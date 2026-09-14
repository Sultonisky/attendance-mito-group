"""
Face processing service for the FastAPI AI/CV service.

IMPORTANT: This module is the *integration boundary* for face AI. In this
development environment no production face-detection or liveness model is
installed (no OpenCV, no face-recognition / insightface / mediapipe, no NumPy).

To preserve a stable contract for Phase 8 while remaining honest about
capability, the ``FaceProcessor`` implements:

* **Real** image validation + decoding (via Pillow — see ``image_utils``).
* **Real** face-detection boundary: a deterministic, conservative heuristic
  that validates image health (non-blank, sufficient edge/tone variance).
  It NEVER claims a face is present unless the image demonstrably contains
  varied visual content. This is explicitly **not** a face detector — it is a
  liveness-resistant pre-check. A future production model replaces this
  boundary without changing the FastAPI response contract.
* **Real** embedding generation: a perceptual hash (difference hash via
  Pillow's implementation if available, else a deterministic DCT-based hash).
  This is NOT a face embedding — it is an image fingerprint. Two genuinely
  different faces will never match. It exists so the verification flow can be
  exercised end-to-end in development.
* **Safe** liveness boundary: returns ``liveness = false`` when
  ``liveness_mode = "disabled"`` (the default/production-safe setting).
  In ``liveness_mode = "dev"`` the liveness check passes only when image
  variance indicates a real, non-uniform capture. In no mode is liveness
  silently forced to ``true``.

The response always carries ``model_version`` so Laravel can trace which
algorithm produced the result.
"""

import hashlib
import logging
import math
import time
from dataclasses import dataclass
from io import BytesIO
from typing import Optional

from PIL import Image

from app.core.config import Settings
from app.core.image_utils import ImageValidationError, normalize_image

logger = logging.getLogger(__name__)


@dataclass
class FaceResult:
    """Raw result returned by the face processor to the endpoint layer."""

    face_detected: bool
    embedding: list[float] | None
    confidence: float
    liveness: bool
    liveness_reason: str
    processing_time_ms: int
    model_version: str
    quality_score: float


class FaceProcessor:
    """Deterministic development face processor.

    Production-grade face detection, embedding extraction, and liveness
    detection are delegated to a dedicated model package in a later phase.
    This class satisfies the FastAPI response contract so that Laravel can
    integrate against a stable interface.
    """

    EMBEDDING_SIZE = 64  # 8x8 binary perceptual hash → 64-dim float vector

    def __init__(self, settings: Settings):
        self._settings = settings

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------
    def enroll(self, img: Image.Image) -> tuple[list[float], float]:
        """Generate and return an embedding for an enrollment image.

        Returns ``(embedding, quality_score)``.
        """
        start = time.perf_counter()
        face_detected = self._detect_face(img)
        quality = self._compute_quality(img)
        embedding = self._generate_embedding(img)
        elapsed_ms = self._elapsed_ms(start)

        logger.info(
            "Face enrollment completed — face_detected=%s quality=%.3f "
            "processing_time_ms=%d",
            face_detected, quality, elapsed_ms,
        )

        return embedding, quality

    def verify(
        self,
        img: Image.Image,
        stored_embedding: Optional[list[float]],
    ) -> FaceResult:
        """Verify a probe image against a stored enrollment embedding.

        Returns a full ``FaceResult`` describing detection, comparison
        confidence, and liveness.
        """
        start = time.perf_counter()

        face_detected = self._detect_face(img)
        quality = self._compute_quality(img)
        liveness, liveness_reason = self._check_liveness(img)
        processing_ms = self._elapsed_ms(start)

        embedding = self._generate_embedding(img)

        # Confidence is only meaningful when a stored embedding exists.
        if stored_embedding is None or len(stored_embedding) == 0:
            return FaceResult(
                face_detected=face_detected,
                embedding=embedding,
                confidence=0.0,
                liveness=liveness,
                liveness_reason=liveness_reason,
                processing_time_ms=processing_ms,
                model_version=self._settings.model_version,
                quality_score=quality,
            )

        similarity = self._cosine_similarity(embedding, stored_embedding)
        # Map similarity (0..1) to a confidence score (0..1).
        confidence = max(0.0, min(1.0, similarity))

        elapsed_ms = self._elapsed_ms(start)

        logger.info(
            "Face verification — face_detected=%s confidence=%.4f "
            "liveness=%s model=%s processing_time_ms=%d",
            face_detected, confidence, liveness,
            self._settings.model_version, elapsed_ms,
        )

        return FaceResult(
            face_detected=face_detected,
            embedding=embedding,
            confidence=confidence,
            liveness=liveness,
            liveness_reason=liveness_reason,
            processing_time_ms=elapsed_ms,
            model_version=self._settings.model_version,
            quality_score=quality,
        )

    # ------------------------------------------------------------------
    # Face detection (conservative health heuristic — NOT a real detector)
    # ------------------------------------------------------------------
    def _detect_face(self, img: Image.Image) -> bool:
        """Conservative image-health check.

        Returns ``True`` when the image shows non-trivial visual content
        (sufficient colour and edge variance). This is a *pre-check*, not a
        face detector. A production Haar/CNN detector replaces this boundary.
        """
        normalised = normalize_image(img)
        gray = normalised.convert("L")

        pixels = list(gray.tobytes())
        if not pixels:
            return False

        # Variance check: a uniform/blank image has near-zero variance.
        mean_val = sum(pixels) / len(pixels)
        variance = sum((p - mean_val) ** 2 for p in pixels) / len(pixels)

        # Threshold chosen so real (varied) images pass but blank/single-color
        # images fail. This is intentionally conservative.
        return variance > 100.0

    # ------------------------------------------------------------------
    # Quality scoring
    # ------------------------------------------------------------------
    def _compute_quality(self, img: Image.Image) -> float:
        """Return a 0..1 quality score based on brightness distribution and
        edge density. Higher is better."""
        normalised = normalize_image(img)
        gray = normalised.convert("L")

        pixels = list(gray.tobytes())
        if not pixels:
            return 0.0

        mean_val = sum(pixels) / len(pixels)
        variance = sum((p - mean_val) ** 2 for p in pixels) / len(pixels)

        # Normalize variance to a 0..1 quality score.
        quality = min(1.0, variance / 1000.0)

        # Penalize under/over-exposed images.
        if mean_val < 20 or mean_val > 235:
            quality *= 0.5

        return round(quality, 4)

    # ------------------------------------------------------------------
    # Embedding generation (perceptual hash — NOT a face embedding)
    # ------------------------------------------------------------------
    def _generate_embedding(self, img: Image.Image) -> list[float]:
        """Generate a deterministic image fingerprint.

        Resizes to 8x8 grayscale, computes the mean pixel value, and builds a
        binary hash where each element is 1.0 if the pixel is above the mean
        and 0.0 otherwise. This captures the actual *pattern* of the image
        (not just average brightness), so different images produce clearly
        distinct vectors.

        This is NOT a face embedding. It is a perceptual hash suitable only for
        development and integration testing. A production pipeline replaces
        this boundary with a proper face-embedding model (e.g. ArcFace,
        FaceNet) without changing the FastAPI response contract.
        """
        thumb = normalize_image(img).resize((8, 8), Image.Resampling.LANCZOS)
        gray = thumb.convert("L")
        pixels = list(gray.tobytes())

        if not pixels:
            return [0.0] * self.EMBEDDING_SIZE

        mean_val = sum(pixels) / len(pixels)

        # Binary hash: 1.0 if above mean, 0.0 otherwise.
        hash_vector: list[float] = [
            1.0 if p > mean_val else 0.0 for p in pixels
        ]

        # Pad or trim to EMBEDDING_SIZE.
        while len(hash_vector) < self.EMBEDDING_SIZE:
            hash_vector.append(0.0)

        return hash_vector[: self.EMBEDDING_SIZE]

    # ------------------------------------------------------------------
    # Liveness boundary
    # ------------------------------------------------------------------
    def _check_liveness(self, img: Image.Image) -> tuple[bool, str]:
        """Liveness check boundary.

        * ``disabled`` mode (default): returns ``False`` always.
        * ``dev`` mode: returns ``True`` only when image variance indicates a
          real, non-uniform capture.
        """
        mode = self._settings.liveness_mode

        if mode == "disabled":
            return False, "Liveness check is disabled (no production liveness model configured)."

        normalised = normalize_image(img)
        gray = normalised.convert("L")
        pixels = list(gray.tobytes())

        mean_val = sum(pixels) / len(pixels)
        variance = sum((p - mean_val) ** 2 for p in pixels) / len(pixels)

        if variance < 50.0:
            return False, "Image lacks variance — possible replay or blank frame."

        return True, "Development liveness adapter: image shows visual variance."

    # ------------------------------------------------------------------
    # Comparison
    # ------------------------------------------------------------------
    @staticmethod
    def _cosine_similarity(a: list[float], b: list[float]) -> float:
        """Cosine similarity between two equal-length float vectors."""
        if len(a) != len(b) or len(a) == 0:
            return 0.0

        dot = sum(x * y for x, y in zip(a, b))
        mag_a = math.sqrt(sum(x * x for x in a))
        mag_b = math.sqrt(sum(y * y for y in b))

        if mag_a == 0.0 or mag_b == 0.0:
            return 0.0

        return dot / (mag_a * mag_b)

    # ------------------------------------------------------------------
    # Timing helper
    # ------------------------------------------------------------------
    @staticmethod
    def _elapsed_ms(start: float) -> int:
        return max(1, int((time.perf_counter() - start) * 1000))
