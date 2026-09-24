"""
Face AI/CV endpoints for enrollment and verification.

These endpoints are internal — they are called by Laravel, never directly by
the browser. All requests (except ``/health``) require the shared API key.

Endpoints:

    POST /face/enroll  — enroll a face embedding (admin action via Laravel)
    POST /face/verify  — verify a probe face against the enrolled embedding
    DELETE /face/embeddings/{embedding_reference} — delete an embedding by opaque reference
"""

import hashlib
import json
import logging
import threading
import uuid
from collections import defaultdict

import numpy as np

from fastapi import APIRouter, Depends, File, Form, HTTPException, UploadFile, status

from app.ai.engine.mito_ai_engine import (
    MitoAiEngine,
    MitoAiError,
    MultipleFacesDetectedError,
    NoFaceDetectedError,
)
from app.core.biometric_storage import PostgreSQLBiometricStorage, create_storage, generate_reference
from app.core.config import Settings, get_settings
from app.core.face import FaceProcessor
from app.core.image_utils import ImageValidationError, validate_and_decode_image
from app.core.security import require_api_key
from app.models.face import EnrollResponse, LivenessResponse, QualityResponse, VerifyResponse

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/face", tags=["face"])

_storage: PostgreSQLBiometricStorage | None = None
_storage_init_lock = threading.Lock()

# Per-idempotency-key locks serialize concurrent enrollments that share a key
# so only one writer inserts; losers re-read and return the winner's reference.
_enroll_key_locks: dict[str, threading.Lock] = defaultdict(threading.Lock)
_enroll_key_locks_guard = threading.Lock()


def _get_storage() -> PostgreSQLBiometricStorage:
    """Return the process-wide biometric store (thread-safe lazy init).

    Concurrent first callers must not each call ``create_storage()`` — that
    would create separate in-memory backends and break same-key idempotency.
    """
    global _storage
    if _storage is None:
        with _storage_init_lock:
            if _storage is None:
                _storage = create_storage(get_settings().biometric_database_url)
    return _storage

def _enroll_lock_for(idempotency_key: str) -> threading.Lock:
    with _enroll_key_locks_guard:
        return _enroll_key_locks[idempotency_key]


def _get_processor(settings: Settings) -> FaceProcessor:
    return FaceProcessor(settings)


def _get_engine(settings: Settings) -> MitoAiEngine:
    from app.ai.alignment.face_alignment import FaceAligner
    from app.ai.detection.scrfd import SCRFDDetector
    from app.ai.embedding.arcface import ArcFaceEmbedder
    from app.ai.liveness.mini_fasnet import MiniFASNetV2
    from app.ai.quality.face_quality import FaceQualityAssessor

    return MitoAiEngine(
        detector=SCRFDDetector(),
        aligner=FaceAligner(),
        quality_assessor=FaceQualityAssessor(),
        embedder=ArcFaceEmbedder(),
        liveness=MiniFASNetV2(),
    )


def _build_enroll_response(
    reference: str,
    model_version: str,
    face_detected: bool,
    quality: object,
    liveness: object,
    processing_time_ms: float,
) -> EnrollResponse:
    return EnrollResponse(
        enrolled=True,
        model_version=model_version,
        embedding_reference=reference,
        face_detected=face_detected,
        quality=QualityResponse(
            blur=quality.blur,
            brightness=quality.brightness,
            contrast=quality.contrast,
            face_width=quality.face_width,
            face_height=quality.face_height,
            yaw=quality.yaw,
            roll=quality.roll,
        ),
        liveness=LivenessResponse(
            label=liveness.label,
            live_prob=liveness.live_prob,
            probs=liveness.probs,
        ),
        processing_time_ms=processing_time_ms,
    )


def _build_enroll_response_from_storage(row, settings: Settings) -> EnrollResponse:
    ai_facts = json.loads(row.ai_facts)
    quality_data = ai_facts["quality"]
    liveness_data = ai_facts["liveness"]
    return EnrollResponse(
        enrolled=True,
        model_version=row.model_version,
        embedding_reference=row.reference,
        face_detected=ai_facts["face_detected"],
        quality=QualityResponse(**quality_data),
        liveness=LivenessResponse(**liveness_data),
        processing_time_ms=ai_facts["processing_time_ms"],
    )


@router.post("/enroll", response_model=EnrollResponse)
async def enroll_face(
    image: UploadFile = File(..., description="Face image (JPEG/PNG/WebP)."),
    idempotency_key: str = Form(..., description="Opaque idempotency key for deduplication."),
    settings: Settings = Depends(require_api_key),
) -> EnrollResponse:
    """Enroll a face embedding.

    - Validates the image server-side (MIME, size, pixel dimensions).
    - Runs the MITO AI pipeline (SCRFD → alignment → quality → ArcFace → MiniFASNetV2).
    - Stores the raw embedding internally under an opaque ``embedding_reference``.
    - Returns the model version, quality metrics, liveness facts, and the reference.

    The actual embedding vector is retained by FastAPI and is never returned.
    FastAPI does not know which employee the embedding belongs to.
    """
    image_bytes = await image.read()
    mime_type = image.content_type or "application/octet-stream"

    if not image_bytes:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="No image data received.",
        )

    if not idempotency_key or not idempotency_key.strip():
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail="Invalid idempotency key.",
        )

    fingerprint = hashlib.sha256(image_bytes).hexdigest()

    try:
        img = validate_and_decode_image(
            image_bytes=image_bytes,
            mime_type=mime_type,
            max_size_bytes=settings.max_image_size_bytes,
            max_dimension=settings.max_image_dimension,
        )
    except ImageValidationError as exc:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=str(exc),
        ) from exc

    storage = _get_storage()

    existing = storage.get_by_idempotency_key(idempotency_key)
    if existing is not None:
        if existing.request_fingerprint == fingerprint:
            return _build_enroll_response_from_storage(existing, settings)
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="Idempotency key reused with a different request.",
        )

    try:
        engine = _get_engine(settings)
        result = engine.process(img)
    except NoFaceDetectedError:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="No face detected in image.",
        )
    except MultipleFacesDetectedError:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail="Multiple faces detected. Only one face is allowed.",
        )
    except MitoAiError:
        logger.exception("AI inference failed during enrollment.")
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="AI model unavailable.",
        )
    except Exception:
        logger.exception("Unexpected failure during AI inference.")
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="AI processing failed.",
        )

    vector_bytes = np.asarray(result.embedding, dtype=np.float32).tobytes()

    ai_facts = {
        "face_detected": result.face_detected,
        "quality": {
            "blur": result.quality.blur,
            "brightness": result.quality.brightness,
            "contrast": result.quality.contrast,
            "face_width": result.quality.face_width,
            "face_height": result.quality.face_height,
            "yaw": result.quality.yaw,
            "roll": result.quality.roll,
        },
        "liveness": {
            "label": result.liveness.label,
            "live_prob": result.liveness.live_prob,
            "probs": result.liveness.probs,
        },
        "processing_time_ms": result.processing_time_ms,
    }
    ai_facts_json = json.dumps(ai_facts)

    # Serialize store for this idempotency key: re-check after AI work so a
    # concurrent winner is returned instead of inserting a second reference.
    with _enroll_lock_for(idempotency_key):
        existing = storage.get_by_idempotency_key(idempotency_key)
        if existing is not None:
            if existing.request_fingerprint == fingerprint:
                return _build_enroll_response_from_storage(existing, settings)
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail="Idempotency key reused with a different request.",
            )

        reference = generate_reference()
        try:
            storage.store(
                reference=reference,
                vector=vector_bytes,
                dimension=result.embedding_dimension,
                model_version=result.model_version,
                idempotency_key=idempotency_key,
                request_fingerprint=fingerprint,
                ai_facts=ai_facts_json,
            )
        except Exception as exc:
            existing = None
            try:
                existing = storage.get_by_idempotency_key(idempotency_key)
            except Exception:
                pass

            if existing is not None:
                if existing.request_fingerprint == fingerprint:
                    return _build_enroll_response_from_storage(existing, settings)
                raise HTTPException(
                    status_code=status.HTTP_409_CONFLICT,
                    detail="Idempotency key reused with a different request.",
                )

            logger.exception("Embedding storage failed after successful AI inference.")
            raise HTTPException(
                status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
                detail="Embedding storage failed.",
            ) from exc

    logger.info(
        "Face enrolled — ref=%s model=%s processing_time_ms=%.1f",
        reference,
        result.model_version,
        result.processing_time_ms,
    )

    return _build_enroll_response(
        reference=reference,
        model_version=result.model_version,
        face_detected=result.face_detected,
        quality=result.quality,
        liveness=result.liveness,
        processing_time_ms=result.processing_time_ms,
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


@router.delete("/embeddings/{embedding_reference}", status_code=status.HTTP_204_NO_CONTENT)
async def delete_embedding(
    embedding_reference: str,
    settings: Settings = Depends(require_api_key),
) -> None:
    """Delete a stored embedding by its opaque reference.

    This endpoint is used by Laravel for compensation when an enrollment
    operation could not be persisted after FastAPI successfully stored the
    embedding. No employee identity is accepted or stored.
    """
    storage = _get_storage()
    deleted = storage.delete_by_reference(embedding_reference)

    if not deleted:
        raise HTTPException(
            status_code=status.HTTP_404_NOT_FOUND,
            detail="Embedding reference not found.",
        )
