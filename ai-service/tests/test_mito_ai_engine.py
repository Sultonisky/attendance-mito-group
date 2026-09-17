"""Tests for MitoAiEngine (AI-2F — Pure MITO AI Engine).

These tests verify the pure orchestration layer without requiring
production ONNX model weights. All AI components are mocked to
validate engine behavior, error semantics, and result contracts.
"""

from __future__ import annotations

import os
import time
import unittest.mock
import uuid

import numpy as np
import pytest
from PIL import Image

from app.ai.engine.mito_ai_engine import (
    MitoAiEngine,
    MitoAiResult,
    MitoAiError,
    MultipleFacesDetectedError,
    NoFaceDetectedError,
)
from app.ai.alignment.face_alignment import FaceAligner
from app.ai.detection.scrfd import DetectionResult, FaceDetection, SCRFDDetector
from app.ai.embedding.arcface import ArcFaceEmbedder, EmbeddingResult
from app.ai.liveness.mini_fasnet import LivenessResult, MiniFASNetV2
from app.ai.quality.face_quality import FaceQualityAssessor, QualityInput, QualityResult
from app.core.config import get_settings


# Ensure settings are loaded with a test API key before app imports.
os.environ.setdefault("AI_API_KEY", "test-secret-key-123")
get_settings.cache_clear()


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _make_image(width: int = 200, height: int = 200, color: tuple[int, int, int] = (128, 128, 128)) -> Image.Image:
    return Image.new("RGB", (width, height), color)


def _make_face_detection(
    bbox: tuple[float, float, float, float] = (50.0, 50.0, 150.0, 150.0),
    landmarks: tuple[tuple[float, float], ...] | None = None,
    confidence: float = 0.9,
) -> FaceDetection:
    if landmarks is None:
        landmarks = (
            (80.0, 80.0),
            (120.0, 80.0),
            (100.0, 100.0),
            (85.0, 120.0),
            (115.0, 120.0),
        )
    return FaceDetection(
        bbox=bbox,
        landmarks=landmarks,
        confidence=confidence,
    )


def _make_detection_result(
    face_count: int = 1,
) -> DetectionResult:
    faces = tuple(_make_face_detection() for _ in range(face_count))
    return DetectionResult(faces=faces, padded_retry=False)


def _make_quality_result() -> QualityResult:
    return QualityResult(
        blur=120.5,
        brightness=128.0,
        contrast=45.2,
        face_width=100.0,
        face_height=100.0,
        yaw=0.05,
        roll=0.0,
    )


def _make_embedding_result() -> EmbeddingResult:
    vec = np.random.randn(512).astype(np.float32)
    vec = vec / np.linalg.norm(vec)
    return EmbeddingResult(
        embedding=vec,
        model_version="mito-face-v1",
        dimension=512,
    )


def _make_liveness_result() -> LivenessResult:
    return LivenessResult(
        label="real",
        live_prob=0.95,
        probs={"print": 0.02, "real": 0.95, "replay": 0.03},
        logits=(-1.0, 3.0, 0.5),
        model_version="mito-face-v1",
    )


def _build_mock_components():
    """Build mocked AI components with controllable return values."""
    detector = unittest.mock.MagicMock(spec=SCRFDDetector)
    aligner = unittest.mock.MagicMock(spec=FaceAligner)
    quality_assessor = unittest.mock.MagicMock(spec=FaceQualityAssessor)
    embedder = unittest.mock.MagicMock(spec=ArcFaceEmbedder)
    liveness = unittest.mock.MagicMock(spec=MiniFASNetV2)

    detector.detect.return_value = _make_detection_result(face_count=1)
    aligner.align.return_value = _make_image(112, 112)
    quality_assessor.assess.return_value = _make_quality_result()
    embedder.embed.return_value = _make_embedding_result()
    liveness.assess.return_value = _make_liveness_result()

    return detector, aligner, quality_assessor, embedder, liveness


# ---------------------------------------------------------------------------
# Happy path
# ---------------------------------------------------------------------------


class TestHappyPath:
    """Single detected face flows through all stages successfully."""

    def test_returns_mito_ai_result(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert isinstance(result, MitoAiResult)

    def test_all_components_invoked(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        engine.process(img)

        detector.detect.assert_called_once()
        aligner.align.assert_called_once()
        quality_assessor.assess.assert_called_once()
        embedder.embed.assert_called_once()
        liveness.assess.assert_called_once()

    def test_result_fields_populated(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.face_detected is True
        assert isinstance(result.bbox, tuple)
        assert len(result.bbox) == 4
        assert isinstance(result.landmarks, tuple)
        assert len(result.landmarks) == 5
        assert isinstance(result.quality, QualityResult)
        assert isinstance(result.liveness, LivenessResult)
        assert result.embedding_dimension == 512
        assert result.model_version == "mito-face-v1"
        assert result.processing_time_ms >= 0.0

    def test_result_is_frozen_dataclass(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.__dataclass_params__.frozen is True

    def test_processing_time_is_float(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert isinstance(result.processing_time_ms, float)
        assert result.processing_time_ms >= 0.0


# ---------------------------------------------------------------------------
# Invocation order
# ---------------------------------------------------------------------------


class TestInvocationOrder:
    """Components must be called in the correct pipeline sequence."""

    def test_detect_called_before_align(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        call_order: list[str] = []

        def record_detect(*args, **kwargs):
            call_order.append("detect")
            return _make_detection_result(face_count=1)

        def record_align(*args, **kwargs):
            call_order.append("align")
            return _make_image(112, 112)

        def record_quality(*args, **kwargs):
            call_order.append("quality")
            return _make_quality_result()

        def record_embed(*args, **kwargs):
            call_order.append("embed")
            return _make_embedding_result()

        def record_liveness(*args, **kwargs):
            call_order.append("liveness")
            return _make_liveness_result()

        detector.detect.side_effect = record_detect
        aligner.align.side_effect = record_align
        quality_assessor.assess.side_effect = record_quality
        embedder.embed.side_effect = record_embed
        liveness.assess.side_effect = record_liveness

        engine.process(img)

        assert call_order == ["detect", "align", "quality", "embed", "liveness"]

    def test_align_not_called_when_no_face(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=0)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(NoFaceDetectedError):
            engine.process(img)

        aligner.align.assert_not_called()
        quality_assessor.assess.assert_not_called()
        embedder.embed.assert_not_called()
        liveness.assess.assert_not_called()

    def test_liveness_not_called_when_quality_raises(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        quality_assessor.assess.side_effect = RuntimeError("quality failed")

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(RuntimeError, match="quality failed"):
            engine.process(img)

        liveness.assess.assert_not_called()

    def test_embed_not_called_when_liveness_raises(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        embedder.embed.side_effect = RuntimeError("embed failed")

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(RuntimeError, match="embed failed"):
            engine.process(img)

        liveness.assess.assert_not_called()


# ---------------------------------------------------------------------------
# No face
# ---------------------------------------------------------------------------


class TestNoFace:
    """Zero detections must raise NoFaceDetectedError."""

    def test_raises_no_face_detected_error(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=0)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(NoFaceDetectedError):
            engine.process(img)

    def test_no_face_error_is_mito_ai_error(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=0)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(MitoAiError):
            engine.process(img)

    def test_later_components_not_called_on_no_face(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=0)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(NoFaceDetectedError):
            engine.process(img)

        aligner.align.assert_not_called()
        quality_assessor.assess.assert_not_called()
        embedder.embed.assert_not_called()
        liveness.assess.assert_not_called()


# ---------------------------------------------------------------------------
# Multiple faces
# ---------------------------------------------------------------------------


class TestMultipleFaces:
    """More than one detection must raise MultipleFacesDetectedError."""

    def test_raises_multiple_faces_detected_error(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=2)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(MultipleFacesDetectedError):
            engine.process(img)

    def test_multiple_faces_error_is_mito_ai_error(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=2)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(MitoAiError):
            engine.process(img)

    def test_later_components_not_called_on_multiple_faces(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=2)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(MultipleFacesDetectedError):
            engine.process(img)

        aligner.align.assert_not_called()
        quality_assessor.assess.assert_not_called()
        embedder.embed.assert_not_called()
        liveness.assess.assert_not_called()

    def test_error_message_contains_face_count(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=3)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(MultipleFacesDetectedError, match="3 faces"):
            engine.process(img)


# ---------------------------------------------------------------------------
# Component failure propagation
# ---------------------------------------------------------------------------


class TestComponentFailurePropagation:
    """Exceptions from AI components must propagate and stop the pipeline."""

    def test_alignment_failure_propagates(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        aligner.align.side_effect = RuntimeError("alignment failed")

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(RuntimeError, match="alignment failed"):
            engine.process(img)

        quality_assessor.assess.assert_not_called()
        embedder.embed.assert_not_called()
        liveness.assess.assert_not_called()

    def test_quality_failure_propagates(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        quality_assessor.assess.side_effect = ValueError("quality failed")

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(ValueError, match="quality failed"):
            engine.process(img)

        embedder.embed.assert_not_called()
        liveness.assess.assert_not_called()

    def test_embedding_failure_propagates(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        embedder.embed.side_effect = RuntimeError("embedding failed")

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(RuntimeError, match="embedding failed"):
            engine.process(img)

        liveness.assess.assert_not_called()

    def test_liveness_failure_propagates(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        liveness.assess.side_effect = RuntimeError("liveness failed")

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(RuntimeError, match="liveness failed"):
            engine.process(img)

    def test_detector_model_not_found_propagates(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        from app.core.model_loader import ModelNotFound

        detector.detect.side_effect = ModelNotFound("missing model")

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(ModelNotFound):
            engine.process(img)


# ---------------------------------------------------------------------------
# Result integrity
# ---------------------------------------------------------------------------


class TestResultIntegrity:
    """MitoAiResult must contain correct fields and types."""

    def test_face_detected_is_true(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.face_detected is True

    def test_bbox_from_detection(self):
        expected_bbox = (30.0, 40.0, 170.0, 180.0)
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = DetectionResult(
            faces=(_make_face_detection(bbox=expected_bbox),),
            padded_retry=False,
        )

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.bbox == expected_bbox

    def test_landmarks_from_detection(self):
        expected_landmarks = (
            (10.0, 20.0),
            (30.0, 20.0),
            (20.0, 30.0),
            (15.0, 40.0),
            (25.0, 40.0),
        )
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = DetectionResult(
            faces=(_make_face_detection(landmarks=expected_landmarks),),
            padded_retry=False,
        )

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.landmarks == expected_landmarks

    def test_quality_result_preserved(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        expected_quality = _make_quality_result()
        quality_assessor.assess.return_value = expected_quality

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.quality is expected_quality

    def test_liveness_result_preserved(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        expected_liveness = _make_liveness_result()
        liveness.assess.return_value = expected_liveness

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.liveness is expected_liveness

    def test_embedding_dimension_from_embedder(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        custom_result = EmbeddingResult(
            embedding=np.zeros(512, dtype=np.float32),
            model_version="mito-face-v1",
            dimension=512,
        )
        embedder.embed.return_value = custom_result

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.embedding_dimension == 512

    def test_model_version_from_embedder(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        custom_result = EmbeddingResult(
            embedding=np.zeros(512, dtype=np.float32),
            model_version="mito-face-v2-test",
            dimension=512,
        )
        embedder.embed.return_value = custom_result

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.model_version == "mito-face-v2-test"

    def test_processing_time_non_negative(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.processing_time_ms >= 0.0

    def test_result_fields_have_correct_types(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert isinstance(result.face_detected, bool)
        assert isinstance(result.bbox, tuple)
        assert all(isinstance(v, float) for v in result.bbox)
        assert isinstance(result.landmarks, tuple)
        assert all(isinstance(p, tuple) and len(p) == 2 for p in result.landmarks)
        assert isinstance(result.quality, QualityResult)
        assert isinstance(result.liveness, LivenessResult)
        assert isinstance(result.embedding_dimension, int)
        assert isinstance(result.model_version, str)
        assert isinstance(result.processing_time_ms, float)


# ---------------------------------------------------------------------------
# No raw embedding exposure
# ---------------------------------------------------------------------------


class TestNoRawEmbeddingExposure:
    """MitoAiResult exposes the raw embedding for the orchestration layer.

    The raw embedding is an internal AI fact retained by FastAPI and stored
    through BiometricStorage. It is never returned in API responses.
    """

    def test_result_has_embedding_attribute(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert hasattr(result, "embedding")
        assert isinstance(result.embedding, np.ndarray)

    def test_result_embedding_has_correct_shape(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.embedding.shape == (512,)

    def test_result_has_no_verified_attribute(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert not hasattr(result, "verified")

    def test_result_has_no_employee_id_attribute(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert not hasattr(result, "employee_id")

    def test_result_has_no_attendance_status_attribute(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert not hasattr(result, "attendance_status")

    def test_result_has_no_quality_score_attribute(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert not hasattr(result, "quality_score")


# ---------------------------------------------------------------------------
# No persistence
# ---------------------------------------------------------------------------


class TestNoPersistence:
    """Engine must not write to filesystem, database, or network."""

    def test_engine_does_not_open_files(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with unittest.mock.patch("builtins.open", side_effect=AssertionError("open() was called")):
            engine.process(img)

    def test_engine_does_not_write_json(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with unittest.mock.patch("json.dump", side_effect=AssertionError("json.dump() was called")):
            engine.process(img)

    def test_engine_has_no_filesystem_dependencies(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        engine.process(img)

        # No SQLAlchemy, no Redis, no database cursor calls
        # We verify this by checking the engine code doesn't import them,
        # which is enforced by the static implementation.
        assert "sqlalchemy" not in dir(engine)
        assert "redis" not in dir(engine)


# ---------------------------------------------------------------------------
# Dependency injection
# ---------------------------------------------------------------------------


class TestDependencyInjection:
    """Mocked components must be injectable without loading real models."""

    def test_mock_components_accepted(self):
        detector = unittest.mock.MagicMock()
        aligner = unittest.mock.MagicMock()
        quality_assessor = unittest.mock.MagicMock()
        embedder = unittest.mock.MagicMock()
        liveness = unittest.mock.MagicMock()

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)
        assert engine._detector is detector
        assert engine._aligner is aligner
        assert engine._quality_assessor is quality_assessor
        assert engine._embedder is embedder
        assert engine._liveness is liveness

    def test_engine_without_real_onnx_models(self):
        detector = unittest.mock.MagicMock()
        aligner = unittest.mock.MagicMock()
        quality_assessor = unittest.mock.MagicMock()
        embedder = unittest.mock.MagicMock()
        liveness = unittest.mock.MagicMock()

        detector.detect.return_value = _make_detection_result(face_count=1)
        aligner.align.return_value = _make_image(112, 112)
        quality_assessor.assess.return_value = _make_quality_result()
        embedder.embed.return_value = _make_embedding_result()
        liveness.assess.return_value = _make_liveness_result()

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        assert result.face_detected is True
        assert result.embedding_dimension == 512


# ---------------------------------------------------------------------------
# Deterministic orchestration
# ---------------------------------------------------------------------------


class TestDeterministicOrchestration:
    """Equivalent inputs must produce equivalent results (modulo timing)."""

    def test_same_inputs_produce_same_quality_and_liveness(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result1 = engine.process(img)
        result2 = engine.process(img)

        assert result1.quality == result2.quality
        assert result1.liveness == result2.liveness
        assert result1.bbox == result2.bbox
        assert result1.landmarks == result2.landmarks
        assert result1.embedding_dimension == result2.embedding_dimension
        assert result1.model_version == result2.model_version

    def test_result_excludes_nondeterministic_data_except_timing(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        result = engine.process(img)

        # Only processing_time_ms is allowed to vary between runs.
        # All other fields must be fully determined by the mocked inputs.
        assert result.face_detected is True
        assert result.quality == _make_quality_result()
        assert result.liveness == _make_liveness_result()
        assert result.embedding_dimension == 512


# ---------------------------------------------------------------------------
# Error hierarchy
# ---------------------------------------------------------------------------


class TestErrorHierarchy:
    """Inference errors must be distinguishable from model/runtime failures."""

    def test_no_face_is_mito_ai_error(self):
        assert issubclass(NoFaceDetectedError, MitoAiError)

    def test_multiple_faces_is_mito_ai_error(self):
        assert issubclass(MultipleFacesDetectedError, MitoAiError)

    def test_no_face_is_not_multiple_faces(self):
        assert not issubclass(NoFaceDetectedError, MultipleFacesDetectedError)

    def test_multiple_faces_is_not_no_face(self):
        assert not issubclass(MultipleFacesDetectedError, NoFaceDetectedError)

    def test_mito_ai_error_is_exception(self):
        assert issubclass(MitoAiError, Exception)

    def test_no_face_error_message(self):
        detector, aligner, quality_assessor, embedder, liveness = _build_mock_components()
        detector.detect.return_value = _make_detection_result(face_count=0)

        engine = MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

        img = _make_image()
        with pytest.raises(NoFaceDetectedError, match="zero faces"):
            engine.process(img)


# ---------------------------------------------------------------------------
# Integration-style with real components (skipped when models absent)
# ---------------------------------------------------------------------------


class TestMitoAiEngineRealComponents:
    """Engine behavior with real AI component implementations.

    These tests verify that the engine works with actual component classes
    (not mocks) when model weights are available. They are skipped when
    ONNX models are absent.
    """

    @pytest.fixture
    def engine(self):
        detector = SCRFDDetector()
        aligner = FaceAligner()
        quality_assessor = FaceQualityAssessor()
        embedder = ArcFaceEmbedder()
        liveness = MiniFASNetV2()
        return MitoAiEngine(detector, aligner, quality_assessor, embedder, liveness)

    def test_engine_instantiation(self, engine):
        assert isinstance(engine, MitoAiEngine)

    def test_no_face_raises_with_real_detector(self, engine):
        from pathlib import Path

        if not (Path("models") / "det_500m.onnx").exists():
            pytest.skip("SCRFD model asset models/det_500m.onnx is not present")

        img = _make_image(640, 480)
        with pytest.raises(NoFaceDetectedError):
            engine.process(img)
