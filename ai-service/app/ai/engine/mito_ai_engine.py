"""Pure MITO AI inference engine.

Orchestrates the approved AI-2B through AI-2E components into a single
in-memory, stateless, deterministic pipeline:

    SCRFD detection
        -> face alignment
        -> quality assessment
        -> ArcFace embedding
        -> MiniFASNetV2 liveness

The engine produces pure AI inference facts. It does NOT implement:

* employee lookup
* identity verification
* enrollment
* attendance decisions
* persistence
* database access
* HTTP/API behavior
* Laravel integration

All business decisions belong to later integration layers.
"""

from __future__ import annotations

import time
from dataclasses import dataclass
from typing import Protocol

from PIL import Image

from app.ai.alignment.face_alignment import FaceAligner
from app.ai.detection.scrfd import DetectionResult, FaceDetection, SCRFDDetector
from app.ai.embedding.arcface import ArcFaceEmbedder, EmbeddingResult
from app.ai.liveness.mini_fasnet import LivenessResult, MiniFASNetV2
from app.ai.quality.face_quality import FaceQualityAssessor, QualityInput, QualityResult


# ---------------------------------------------------------------------------
# Inference-level error taxonomy
# ---------------------------------------------------------------------------


class MitoAiError(Exception):
    """Base class for MitoAiEngine inference failures."""


class NoFaceDetectedError(MitoAiError):
    """Raised when SCRFD detects zero faces in the input image."""


class MultipleFacesDetectedError(MitoAiError):
    """Raised when SCRFD detects more than one face in the input image."""


# ---------------------------------------------------------------------------
# Result
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class MitoAiResult:
    """Pure AI inference result from the MITO pipeline.

    Contains only AI facts. No employee identity, attendance decision,
    or business policy is encoded here.
    """

    face_detected: bool
    """True if exactly one face was detected and processed."""

    bbox: tuple[float, float, float, float]
    """Bounding box ``(x1, y1, x2, y2)`` of the processed face in original image coordinates."""

    landmarks: tuple[tuple[float, float], ...]
    """Five facial landmarks of the processed face in original image coordinates."""

    quality: QualityResult
    """Raw quality metrics from AI-2D."""

    liveness: LivenessResult
    """Liveness assessment from AI-2E."""

    embedding_dimension: int
    """Dimensionality of the ArcFace embedding (typically 512)."""

    model_version: str
    """Pipeline model version string (e.g. ``mito-face-v1``)."""

    processing_time_ms: float
    """Total wall-to-wall pipeline execution time in milliseconds."""


# ---------------------------------------------------------------------------
# Engine
# ---------------------------------------------------------------------------


class MitoAiEngine:
    """Pure MITO AI inference pipeline orchestrator.

    Coordinates the existing AI-2B through AI-2E components without
    adding business logic, persistence, or external dependencies.

    The engine is:
        * pure  — same input produces the same AI facts (modulo measured timing)
        * stateless — no mutable state between ``process()`` calls
        * in-memory — no filesystem, database, or network I/O
        * independent — no Laravel, FastAPI, PostgreSQL, or Redis dependency
    """

    def __init__(
        self,
        detector: SCRFDDetector,
        aligner: FaceAligner,
        quality_assessor: FaceQualityAssessor,
        embedder: ArcFaceEmbedder,
        liveness: MiniFASNetV2,
    ) -> None:
        self._detector = detector
        self._aligner = aligner
        self._quality_assessor = quality_assessor
        self._embedder = embedder
        self._liveness = liveness

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def process(self, image: Image.Image) -> MitoAiResult:
        """Run the full MITO AI pipeline on ``image``.

        Args:
            image: PIL RGB image containing exactly one human face.

        Returns:
            ``MitoAiResult`` with pure AI inference facts.

        Raises:
            NoFaceDetectedError: Zero faces detected.
            MultipleFacesDetectedError: More than one face detected.
            ValueError: ``image`` is not a valid PIL RGB image.
            ModelNotFound: A required model asset is missing.
            ModelInvalid: A model asset cannot be loaded.
            ModelUnavailable: ONNX runtime or another dependency is unavailable.
            RuntimeError: A component encounters an unrecoverable inference failure.
        """
        start = time.perf_counter()

        detection_result = self._detector.detect(image)
        faces = detection_result.faces

        if len(faces) == 0:
            raise NoFaceDetectedError(
                "SCRFD detected zero faces in the input image."
            )

        if len(faces) > 1:
            raise MultipleFacesDetectedError(
                f"SCRFD detected {len(faces)} faces in the input image. "
                "The pure AI pipeline requires exactly one face."
            )

        face = faces[0]
        bbox = face.bbox
        landmarks = face.landmarks

        aligned_face = self._aligner.align(image, landmarks)

        quality_input = QualityInput(
            image=image,
            bbox=bbox,
            landmarks=landmarks,
        )
        quality_result = self._quality_assessor.assess(quality_input)

        embedding_result = self._embedder.embed(aligned_face)

        liveness_result = self._liveness.assess(image, bbox)

        end = time.perf_counter()
        processing_time_ms = (end - start) * 1000.0

        return MitoAiResult(
            face_detected=True,
            bbox=bbox,
            landmarks=landmarks,
            quality=quality_result,
            liveness=liveness_result,
            embedding_dimension=embedding_result.dimension,
            model_version=embedding_result.model_version,
            processing_time_ms=processing_time_ms,
        )
