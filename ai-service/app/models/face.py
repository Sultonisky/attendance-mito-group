"""
Pydantic schemas for the FastAPI face AI/CV endpoints.

These schemas define the request/response contract between Laravel and FastAPI.
FastAPI returns *facts* only — never attendance decisions, never employee
authorization, never business policy outcomes.
"""

from typing import Optional

from pydantic import BaseModel, Field


class EnrollResponse(BaseModel):
    """Response returned to Laravel after a successful face enrollment.

    FastAPI returns the model version and an opaque reference that Laravel
    stores in ``employee_face_embeddings.embedding_reference``. The actual
    embedding vector is retained internally by FastAPI and is never sent to
    Laravel or returned to the browser.
    """

    enrolled: bool = Field(default=True, description="Whether enrollment succeeded.")
    model_version: str = Field(..., description="Model version used for this enrollment.")
    embedding_reference: str = Field(
        ..., description="Opaque reference Laravel stores to look up this embedding."
    )
    face_detected: bool = Field(..., description="Whether a face was detected during enrollment.")
    quality_score: float = Field(..., ge=0.0, le=1.0, description="Enrollment image quality (0..1).")


class VerifyResponse(BaseModel):
    """AI facts returned by the verification endpoint.

    Laravel combines these facts with attendance context, geofence, schedule,
    and policy to make the final business decision.
    """

    verified: bool = Field(..., description="Whether the face matches the enrolled profile.")
    confidence: float = Field(..., ge=0.0, le=1.0, description="Match confidence score (0..1).")
    liveness: bool = Field(..., description="Whether the liveness check passed.")
    liveness_reason: Optional[str] = Field(default=None, description="Human-readable liveness detail.")
    face_detected: bool = Field(..., description="Whether a face was detected in the probe image.")
    model_version: str = Field(..., description="Model version used for this verification.")
    processing_time_ms: int = Field(..., ge=1, description="Server-side processing duration in ms.")
    quality_score: float = Field(..., ge=0.0, le=1.0, description="Probe image quality (0..1).")


class ErrorDetail(BaseModel):
    """Standard error response body."""

    status: str = Field(default="error", description="Always 'error'.")
    message: str = Field(..., description="Safe, human-readable error message.")
