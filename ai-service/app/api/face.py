"""
Face AI/CV endpoints for enrollment and verification.

These endpoints are internal — they are called by Laravel, never directly by
the browser. All requests (except ``/health``) require the shared API key.

Endpoints:

    POST /face/enroll  — enroll an employee face (admin action via Laravel)
    POST /face/verify  — verify a probe face against the enrolled embedding

FastAPI returns AI facts only. Laravel makes the final attendance decision.
"""

import logging
import uuid

import numpy as np

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile, status

from app.core.biometric_storage import PostgreSQLBiometricStorage, create_storage, generate_reference
from app.core.config import Settings, get_settings
from app.core.face import FaceProcessor
from app.core.image_utils import ImageValidationError, validate_and_decode_image
from app.core.security import require_api_key
from app.models.face import EnrollResponse, VerifyResponse

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/face", tags=["face"])

_storage: PostgreSQLBiometricStorage | None = None


def _get_storage() -> PostgreSQLBiometricStorage:
    global _storage
    if _storage is None:
        _storage = create_storage(get_settings().biometric_database_url)
    return _storage


def _get_processor(settings: Settings) -> FaceProcessor:
    return FaceProcessor(settings)


@router.post("/enroll", response_model=EnrollResponse)
async def enroll_face(
    image: UploadFile = File(..., description="Face image (JPEG/PNG/WebP)."),
    employee_id: str = Form(..., description="Employee identifier (e.g. employee_code)."),
    settings: Settings = Depends(require_api_key),
) -> EnrollResponse:
    """Enroll an employee's face.

    - Validates the image server-side (MIME, size, pixel dimensions).
    - Generates a deterministic embedding via the configured model.
    - Stores the embedding internally under ``employee_id``.
    - Returns the model version and an opaque ``embedding_reference``.

    The actual embedding vector is retained by FastAPI and is never returned.
    """
    image_bytes = await image.read()
    mime_type = image.content_type or "application/octet-stream"

    if not image_bytes:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No image data received.",
        )

    try:
        img = validate_and_decode_image(
            image_bytes=image_bytes,
            mime_type=mime_type,
            max_size_bytes=settings.max_image_size_bytes,
            max_dimension=settings.max_image_dimension,
        )
    except ImageValidationError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=str(exc),
        ) from exc

    try:
        processor = _get_processor(settings)
        embedding, quality = processor.enroll(img)
    except Exception:
        logger.exception("Face enrollment failed.")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Face enrollment processing failed.",
        ) from None

    # FastAPI owns the raw embedding and never exposes it to Laravel.
    # Laravel stores only the opaque embedding_reference.
    reference = generate_reference()
    vector_bytes = np.asarray(embedding, dtype=np.float32).tobytes()

    storage = _get_storage()
    storage.store(
        reference=reference,
        vector=vector_bytes,
        dimension=len(embedding),
        model_version=settings.model_version,
        idempotency_key=uuid.uuid4().hex,
    )

    logger.info(
        "Face enrolled — ref=%s model=%s quality=%.4f",
        reference,
        settings.model_version,
        quality,
    )

    return EnrollResponse(
        enrolled=True,
        model_version=settings.model_version,
        embedding_reference=reference,
        face_detected=quality > 0.0,
        quality_score=quality,
    )


@router.post("/verify", response_model=VerifyResponse)
async def verify_face(
    image: UploadFile = File(..., description="Probe face image (JPEG/PNG/WebP)."),
    employee_id: str = Form(..., description="Employee identifier to verify against."),
    embedding_reference: str | None = Form(
        default=None, description="Opaque embedding reference from enrollment."
    ),
    settings: Settings = Depends(require_api_key),
) -> VerifyResponse:
    """Verify a probe face against the enrolled embedding for ``employee_id``.

    Returns AI facts: ``verified``, ``confidence``, ``liveness``,
    ``face_detected``, ``model_version``, ``processing_time_ms``.

    FastAPI does NOT decide attendance authorization — that is Laravel's
    responsibility. ``verified`` means only that the face matched the enrolled
    embedding to within the configured similarity threshold.
    """
    image_bytes = await image.read()
    mime_type = image.content_type or "application/octet-stream"

    if not image_bytes:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No image data received.",
        )

    try:
        img = validate_and_decode_image(
            image_bytes=image_bytes,
            mime_type=mime_type,
            max_size_bytes=settings.max_image_size_bytes,
            max_dimension=settings.max_image_dimension,
        )
    except ImageValidationError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=str(exc),
        ) from exc

    # Retrieve stored embedding by opaque reference.
    # FastAPI does not query the Laravel database.
    stored_embedding = None
    if embedding_reference is not None:
        row = _get_storage().get_by_reference(embedding_reference)
        if row is not None:
            stored_embedding = np.frombuffer(row.vector, dtype=np.float32).tolist()

    try:
        processor = _get_processor(settings)
        result = processor.verify(img, stored_embedding)
    except Exception:
        logger.exception("Face verification failed.")
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="Face verification processing failed.",
        ) from None

    # "verified" is an AI fact: the probe face matched the enrolled embedding
    # to within the model threshold. Liveness is reported separately — Laravel
    # combines both (plus GPS, geofence, policy) for the final decision.
    verified = (
        result.face_detected
        and result.confidence >= settings.similarity_threshold
    )

    return VerifyResponse(
        verified=verified,
        confidence=round(result.confidence, 4),
        liveness=result.liveness,
        liveness_reason=result.liveness_reason,
        face_detected=result.face_detected,
        model_version=result.model_version,
        processing_time_ms=result.processing_time_ms,
        quality_score=round(result.quality_score, 4),
    )
