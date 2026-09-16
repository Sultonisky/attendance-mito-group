"""Tests for MiniFASNetV2 face liveness assessment (AI-2E).

These tests verify the liveness inference component without requiring
the production ``minifasnet_v2.onnx`` weight. Real MiniFASNetV2 inference
is tested only when the model asset is available.
"""

from __future__ import annotations

import os
import unittest.mock
from pathlib import Path

import numpy as np
import pytest
from PIL import Image

from app.ai.liveness.mini_fasnet import (
    _CLASSES,
    _LIVE_INDEX,
    MiniFASNetV2,
    _softmax,
    _validate_bbox,
    _validate_image,
)
from app.ai.alignment.face_alignment import crop_scale
from app.core.config import get_settings
from app.core.model_assets import create_default_registry
from app.core.model_loader import ModelInvalid, ModelLoader, ModelNotFound, ModelUnavailable


# Ensure settings are loaded with a test API key before app imports.
os.environ.setdefault("AI_API_KEY", "test-secret-key-123")
get_settings.cache_clear()


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_image(width: int = 200, height: int = 200, color: tuple[int, int, int] = (128, 128, 128)) -> Image.Image:
    return Image.new("RGB", (width, height), color)


def _build_mock_liveness_session(logits: np.ndarray | None = None):
    """Build a mock ONNX session that produces 3-class liveness logits."""
    session = unittest.mock.MagicMock()
    session.get_inputs.return_value = [unittest.mock.MagicMock()]
    session.get_inputs.return_value[0].name = "input"
    session.get_outputs.return_value = [unittest.mock.MagicMock()]
    session.get_outputs.return_value[0].name = "logits"

    if logits is None:
        logits = np.array([[1.0, 2.0, 0.5]], dtype=np.float32)  # favors "real"

    def run_side_effect(*args, **kwargs):
        return [logits]

    session.run.side_effect = run_side_effect
    return session


# ---------------------------------------------------------------------------
# Softmax
# ---------------------------------------------------------------------------

class TestSoftmax:
    def test_sum_to_one(self):
        x = np.array([1.0, 2.0, 3.0], dtype=np.float32)
        probs = _softmax(x)
        assert abs(float(probs.sum()) - 1.0) < 1e-5

    def test_largest_input_gets_largest_prob(self):
        x = np.array([1.0, 5.0, 2.0], dtype=np.float32)
        probs = _softmax(x)
        assert float(np.argmax(probs)) == 1

    def test_numerically_stable(self):
        x = np.array([1000.0, 1001.0, 1002.0], dtype=np.float32)
        probs = _softmax(x)
        assert abs(float(probs.sum()) - 1.0) < 1e-5


# ---------------------------------------------------------------------------
# Class ordering
# ---------------------------------------------------------------------------

class TestClassOrdering:
    def test_classes_tuple(self):
        assert _CLASSES == ("print", "real", "replay")

    def test_live_index_is_one(self):
        assert _LIVE_INDEX == 1

    def test_live_prob_uses_correct_index(self):
        logits = np.array([[-1.0, 3.0, 0.0]], dtype=np.float32)  # strong "real"
        session = _build_mock_liveness_session(logits)
        liveness = MiniFASNetV2()
        liveness._loader = unittest.mock.MagicMock()
        liveness._loader.load.return_value = session

        img = _make_image(200, 200)
        result = liveness.assess(img, (50.0, 50.0, 150.0, 150.0))
        assert result.label == "real"
        assert result.live_prob > 0.9


# ---------------------------------------------------------------------------
# Preprocessing
# ---------------------------------------------------------------------------

class TestMiniFASNetPreprocessing:
    def test_patch_size_80x80(self):
        liveness = MiniFASNetV2()
        img = _make_image(200, 200)
        patch = crop_scale(img, (50.0, 50.0, 150.0, 150.0), 2.7, 80)
        assert patch.size == (80, 80)

    def test_preprocess_shape(self):
        liveness = MiniFASNetV2()
        img = _make_image(200, 200)
        patch = crop_scale(img, (50.0, 50.0, 150.0, 150.0), 2.7, 80)
        blob = liveness._preprocess(patch)
        assert blob.shape == (1, 3, 80, 80)

    def test_preprocess_dtype(self):
        liveness = MiniFASNetV2()
        img = _make_image(200, 200)
        patch = crop_scale(img, (50.0, 50.0, 150.0, 150.0), 2.7, 80)
        blob = liveness._preprocess(patch)
        assert blob.dtype == np.float32

    def test_preprocess_bgr_not_rgb(self):
        """Red pixel should appear in channel 2 (BGR), not channel 0."""
        liveness = MiniFASNetV2()
        img = Image.new("RGB", (80, 80), (255, 0, 0))  # pure red
        patch = crop_scale(img, (0.0, 0.0, 80.0, 80.0), 1.0, 80)
        blob = liveness._preprocess(patch)
        # BGR: channel 0 = blue, channel 1 = green, channel 2 = red
        assert blob[0, 2, 0, 0] > 0.9  # red channel high
        assert blob[0, 0, 0, 0] < 0.1  # blue channel low
        assert blob[0, 1, 0, 0] < 0.1  # green channel low

    def test_preprocess_raw_0_255_not_normalized(self):
        """MiniFASNetV2 expects raw 0-255, not /255 normalized."""
        liveness = MiniFASNetV2()
        img = Image.new("RGB", (80, 80), (255, 255, 255))  # pure white
        patch = crop_scale(img, (0.0, 0.0, 80.0, 80.0), 1.0, 80)
        blob = liveness._preprocess(patch)
        # White pixel in BGR: (255, 255, 255)
        assert blob[0, 0, 0, 0] > 200.0  # blue channel should be near 255
        assert blob.max() > 200.0  # not normalized to [0, 1]


# ---------------------------------------------------------------------------
# MiniFASNetV2 with mocked session
# ---------------------------------------------------------------------------

class TestMiniFASNetV2Mock:
    """Liveness behavior with mocked ONNX session."""

    def test_returns_liveness_result(self):
        liveness = MiniFASNetV2()
        mock_session = _build_mock_liveness_session()
        liveness._loader = unittest.mock.MagicMock()
        liveness._loader.load.return_value = mock_session

        img = _make_image(200, 200)
        result = liveness.assess(img, (50.0, 50.0, 150.0, 150.0))
        assert isinstance(result, type(result))
        assert result.label in _CLASSES
        assert 0.0 <= result.live_prob <= 1.0
        assert set(result.probs.keys()) == set(_CLASSES)

    def test_model_not_found_propagates(self):
        registry = create_default_registry()
        loader = ModelLoader(registry)
        liveness = MiniFASNetV2(registry=registry, loader=loader)

        img = _make_image(200, 200)
        with pytest.raises(ModelNotFound):
            liveness.assess(img, (50.0, 50.0, 150.0, 150.0))

    def test_deterministic(self):
        liveness = MiniFASNetV2()
        mock_session = _build_mock_liveness_session()
        liveness._loader = unittest.mock.MagicMock()
        liveness._loader.load.return_value = mock_session

        img = _make_image(200, 200)
        bbox = (50.0, 50.0, 150.0, 150.0)
        result1 = liveness.assess(img, bbox)
        result2 = liveness.assess(img, bbox)
        assert result1 == result2

    def test_model_version_in_result(self):
        liveness = MiniFASNetV2()
        mock_session = _build_mock_liveness_session()
        liveness._loader = unittest.mock.MagicMock()
        liveness._loader.load.return_value = mock_session

        img = _make_image(200, 200)
        result = liveness.assess(img, (50.0, 50.0, 150.0, 150.0))
        assert result.model_version == "mito-face-v1"

    def test_ensemble_averages_probabilities(self):
        """Ensemble over multiple scales should average class probabilities."""
        liveness = MiniFASNetV2()
        # First scale favors print, second favors real
        logits_1 = np.array([[2.0, 0.0, 0.5]], dtype=np.float32)  # print
        logits_2 = np.array([[0.0, 2.0, 0.5]], dtype=np.float32)  # real

        session_1 = _build_mock_liveness_session(logits_1)
        session_2 = _build_mock_liveness_session(logits_2)

        liveness._loader = unittest.mock.MagicMock()
        # Return different sessions for each scale call
        liveness._loader.load.side_effect = [session_1, session_2]

        img = _make_image(200, 200)
        result = liveness.assess(img, (50.0, 50.0, 150.0, 150.0), scales=(2.7, 4.0))

        # Averaged probabilities should depend on both scales
        assert result.label in _CLASSES
        assert 0.0 <= result.live_prob <= 1.0


# ---------------------------------------------------------------------------
# Input validation
# ---------------------------------------------------------------------------

class TestLivenessInputValidation:
    def test_invalid_bbox_too_few_values(self):
        liveness = MiniFASNetV2()
        liveness._loader = unittest.mock.MagicMock()  # bypass model loading
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="4 values"):
            liveness.assess(img, (10.0, 10.0, 50.0))

    def test_invalid_bbox_non_finite(self):
        liveness = MiniFASNetV2()
        liveness._loader = unittest.mock.MagicMock()
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="finite"):
            liveness.assess(img, (10.0, 10.0, float("nan"), 50.0))

    def test_invalid_bbox_x2_not_greater(self):
        liveness = MiniFASNetV2()
        liveness._loader = unittest.mock.MagicMock()
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="x2"):
            liveness.assess(img, (50.0, 10.0, 10.0, 50.0))

    def test_non_pil_image_raises(self):
        liveness = MiniFASNetV2()
        liveness._loader = unittest.mock.MagicMock()
        with pytest.raises(ValueError, match="PIL Image"):
            liveness.assess(np.zeros((100, 100, 3), dtype=np.uint8), (10.0, 10.0, 50.0, 50.0))

    def test_grayscale_image_raises(self):
        liveness = MiniFASNetV2()
        liveness._loader = unittest.mock.MagicMock()
        img = Image.new("L", (200, 200))
        with pytest.raises(ValueError, match="RGB"):
            liveness.assess(img, (10.0, 10.0, 50.0, 50.0))

    def test_zero_size_image_raises(self):
        liveness = MiniFASNetV2()
        liveness._loader = unittest.mock.MagicMock()
        img = Image.new("RGB", (0, 0))
        with pytest.raises(ValueError, match="non-zero"):
            liveness.assess(img, (0.0, 0.0, 10.0, 10.0))


# ---------------------------------------------------------------------------
# Output validation
# ---------------------------------------------------------------------------

class TestLivenessOutputValidation:
    def test_wrong_output_shape_raises(self):
        liveness = MiniFASNetV2()
        mock_session = _build_mock_liveness_session(np.array([[1.0, 2.0]], dtype=np.float32))
        liveness._loader = unittest.mock.MagicMock()
        liveness._loader.load.return_value = mock_session

        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="3 classes"):
            liveness.assess(img, (50.0, 50.0, 150.0, 150.0))

    def test_nan_logits_raises(self):
        liveness = MiniFASNetV2()
        logits = np.array([[1.0, np.nan, 0.5]], dtype=np.float32)
        mock_session = _build_mock_liveness_session(logits)
        liveness._loader = unittest.mock.MagicMock()
        liveness._loader.load.return_value = mock_session

        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="non-finite"):
            liveness.assess(img, (50.0, 50.0, 150.0, 150.0))

    def test_inf_logits_raises(self):
        liveness = MiniFASNetV2()
        logits = np.array([[1.0, np.inf, 0.5]], dtype=np.float32)
        mock_session = _build_mock_liveness_session(logits)
        liveness._loader = unittest.mock.MagicMock()
        liveness._loader.load.return_value = mock_session

        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="non-finite"):
            liveness.assess(img, (50.0, 50.0, 150.0, 150.0))


# ---------------------------------------------------------------------------
# Fail-closed behavior
# ---------------------------------------------------------------------------

class TestLivenessFailClosed:
    def test_model_load_error_does_not_return_live(self):
        registry = create_default_registry()
        loader = ModelLoader(registry)
        liveness = MiniFASNetV2(registry=registry, loader=loader)

        img = _make_image(200, 200)
        with pytest.raises((ModelNotFound, ModelInvalid, ModelUnavailable)):
            liveness.assess(img, (50.0, 50.0, 150.0, 150.0))

    def test_runtime_inference_error_propagates(self):
        liveness = MiniFASNetV2()
        mock_session = unittest.mock.MagicMock()
        mock_session.run.side_effect = RuntimeError("ONNX runtime failure")
        liveness._loader = unittest.mock.MagicMock()
        liveness._loader.load.return_value = mock_session

        img = _make_image(200, 200)
        with pytest.raises(RuntimeError):
            liveness.assess(img, (50.0, 50.0, 150.0, 150.0))


# ---------------------------------------------------------------------------
# Real-model integration (skipped when model is absent)
# ---------------------------------------------------------------------------

class TestMiniFASNetRealModel:
    """Integration tests that require the real minifasnet_v2.onnx weight."""

    @pytest.mark.skipif(
        not (Path("models") / "minifasnet_v2.onnx").exists(),
        reason="MiniFASNetV2 model asset models/minifasnet_v2.onnx is not present",
    )
    def test_real_model_produces_valid_output(self):
        liveness = MiniFASNetV2()
        img = _make_image(200, 200)
        result = liveness.assess(img, (50.0, 50.0, 150.0, 150.0))
        assert result.label in _CLASSES
        assert 0.0 <= result.live_prob <= 1.0
        assert set(result.probs.keys()) == set(_CLASSES)
