"""Tests for ArcFace embedding and cosine similarity.

These tests verify the AI-2C inference components without requiring
the production ``w600k_mbf.onnx`` weight. Real ArcFace inference
is tested only when the model asset is available.
"""

from __future__ import annotations

import os
import unittest.mock
from pathlib import Path

import numpy as np
import pytest
from PIL import Image

from app.ai.embedding.arcface import (
    ArcFaceEmbedder,
    EmbeddingResult,
    _EXPECTED_EMBEDDING_DIM,
)
from app.ai.embedding.similarity import cosine_similarity
from app.core.config import get_settings
from app.core.model_assets import create_default_registry
from app.core.model_loader import ModelInvalid, ModelLoader, ModelNotFound


# Ensure settings are loaded with a test API key before app imports.
os.environ.setdefault("AI_API_KEY", "test-secret-key-123")
get_settings.cache_clear()


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_aligned_face(size: int = 112, color: tuple[int, int, int] = (128, 128, 128)) -> Image.Image:
    """Create a synthetic 112×112 RGB aligned face."""
    return Image.new("RGB", (size, size), color)


def _build_mock_arcface_session(dim: int = 512):
    """Build a mock ONNX session that produces a normalized embedding."""
    session = unittest.mock.MagicMock()
    session.get_inputs.return_value = [unittest.mock.MagicMock()]
    session.get_inputs.return_value[0].name = "input"
    session.get_outputs.return_value = [unittest.mock.MagicMock()]
    session.get_outputs.return_value[0].name = "output"

    def run_side_effect(*args, **kwargs):
        vec = np.random.randn(dim).astype(np.float32)
        vec = vec / np.linalg.norm(vec)
        return [vec.reshape(1, dim)]

    session.run.side_effect = run_side_effect
    return session


# ---------------------------------------------------------------------------
# Input validation
# ---------------------------------------------------------------------------

class TestArcFaceInputValidation:
    """ArcFaceEmbedder input contract enforcement."""

    def test_valid_input_succeeds(self):
        embedder = ArcFaceEmbedder()
        img = _make_aligned_face()
        # Should not raise if we patch the loader.
        mock_session = _build_mock_arcface_session()
        embedder._loader = unittest.mock.MagicMock()
        embedder._loader.load.return_value = mock_session
        result = embedder.embed(img)
        assert result.embedding.shape == (512,)

    def test_wrong_width_raises(self):
        embedder = ArcFaceEmbedder()
        img = Image.new("RGB", (64, 112))
        with pytest.raises(ValueError, match="112×112"):
            embedder.embed(img)

    def test_wrong_height_raises(self):
        embedder = ArcFaceEmbedder()
        img = Image.new("RGB", (112, 64))
        with pytest.raises(ValueError, match="112×112"):
            embedder.embed(img)

    def test_grayscale_raises(self):
        embedder = ArcFaceEmbedder()
        img = Image.new("L", (112, 112))
        with pytest.raises(ValueError, match="RGB"):
            embedder.embed(img)

    def test_rgba_raises(self):
        embedder = ArcFaceEmbedder()
        img = Image.new("RGBA", (112, 112))
        with pytest.raises(ValueError, match="RGB"):
            embedder.embed(img)

    def test_non_pil_raises(self):
        embedder = ArcFaceEmbedder()
        with pytest.raises(ValueError, match="PIL Image"):
            embedder.embed(np.zeros((112, 112, 3), dtype=np.uint8))


# ---------------------------------------------------------------------------
# Preprocessing
# ---------------------------------------------------------------------------

class TestArcFacePreprocessing:
    """ArcFaceEmbedder preprocessing matches reference contract."""

    def test_blob_shape(self):
        embedder = ArcFaceEmbedder()
        img = _make_aligned_face()
        blob = embedder._preprocess(img)
        assert blob.shape == (1, 3, 112, 112)

    def test_blob_dtype(self):
        embedder = ArcFaceEmbedder()
        img = _make_aligned_face()
        blob = embedder._preprocess(img)
        assert blob.dtype == np.float32

    def test_normalization_range(self):
        embedder = ArcFaceEmbedder()
        # Pure white (255) should normalize to (255 - 127.5) / 128.0
        img = _make_aligned_face(color=(255, 255, 255))
        blob = embedder._preprocess(img)
        expected = (255.0 - 127.5) / 128.0
        np.testing.assert_allclose(blob, expected, atol=1e-5)

    def test_channel_order_rgb(self):
        embedder = ArcFaceEmbedder()
        # Red pixel should have high value in channel 0 after normalization.
        img = Image.new("RGB", (112, 112), (255, 0, 0))
        blob = embedder._preprocess(img)
        # (255 - 127.5) / 128.0 ≈ 0.996
        assert blob[0, 0, 0, 0] > 0.9  # red channel high
        # (0 - 127.5) / 128.0 ≈ -0.996
        assert blob[0, 1, 0, 0] < -0.9  # green channel low
        assert blob[0, 2, 0, 0] < -0.9  # blue channel low


# ---------------------------------------------------------------------------
# Postprocessing / L2 normalization
# ---------------------------------------------------------------------------

class TestArcFacePostprocessing:
    """Embedding validation and L2 normalization."""

    def test_output_shape_512(self):
        embedder = ArcFaceEmbedder()
        vec = np.ones(512, dtype=np.float32)
        result = embedder._postprocess(vec)
        assert result.shape == (512,)

    def test_l2_normalized(self):
        embedder = ArcFaceEmbedder()
        vec = np.random.randn(512).astype(np.float32)
        result = embedder._postprocess(vec)
        norm = float(np.linalg.norm(result))
        assert abs(norm - 1.0) < 1e-5

    def test_wrong_dimension_raises(self):
        embedder = ArcFaceEmbedder()
        with pytest.raises(ValueError, match="512"):
            embedder._postprocess(np.ones(256, dtype=np.float32))

    def test_zero_vector_raises(self):
        embedder = ArcFaceEmbedder()
        with pytest.raises(RuntimeError, match="norm=0"):
            embedder._postprocess(np.zeros(512, dtype=np.float32))

    def test_nan_raises(self):
        embedder = ArcFaceEmbedder()
        vec = np.ones(512, dtype=np.float32)
        vec[0] = np.nan
        with pytest.raises(RuntimeError, match="norm"):
            embedder._postprocess(vec)

    def test_inf_raises(self):
        embedder = ArcFaceEmbedder()
        vec = np.ones(512, dtype=np.float32)
        vec[0] = np.inf
        with pytest.raises(RuntimeError, match="norm"):
            embedder._postprocess(vec)


# ---------------------------------------------------------------------------
# ArcFaceEmbedder with mocked ONNX session
# ---------------------------------------------------------------------------

class TestArcFaceEmbedderMock:
    """Embedder behavior with mocked ONNX session."""

    def test_returns_embedding_result(self):
        embedder = ArcFaceEmbedder()
        mock_session = _build_mock_arcface_session()
        embedder._loader = unittest.mock.MagicMock()
        embedder._loader.load.return_value = mock_session

        img = _make_aligned_face()
        result = embedder.embed(img)
        assert isinstance(result, EmbeddingResult)
        assert result.embedding.shape == (512,)
        assert result.dimension == 512
        assert result.model_version == "mito-face-v1"

    def test_model_not_found_propagates(self):
        registry = create_default_registry()
        loader = ModelLoader(registry)
        embedder = ArcFaceEmbedder(registry=registry, loader=loader)

        img = _make_aligned_face()
        with pytest.raises(ModelNotFound):
            embedder.embed(img)

    def test_session_reused(self):
        embedder = ArcFaceEmbedder()
        mock_session = _build_mock_arcface_session()
        embedder._loader = unittest.mock.MagicMock()
        embedder._loader.load.return_value = mock_session

        img = _make_aligned_face()
        embedder.embed(img)
        embedder.embed(img)
        # load should be called twice (caching is handled by ModelLoader).
        assert embedder._loader.load.call_count == 2

    def test_mock_session_produces_normalized_output(self):
        embedder = ArcFaceEmbedder()
        mock_session = _build_mock_arcface_session()
        embedder._loader = unittest.mock.MagicMock()
        embedder._loader.load.return_value = mock_session

        img = _make_aligned_face()
        result = embedder.embed(img)
        norm = float(np.linalg.norm(result.embedding))
        assert abs(norm - 1.0) < 1e-5


# ---------------------------------------------------------------------------
# Cosine similarity
# ---------------------------------------------------------------------------

class TestCosineSimilarity:
    """Cosine similarity utility."""

    def test_identical_vectors(self):
        a = np.array([1.0, 0.0, 0.0], dtype=np.float32)
        assert abs(cosine_similarity(a, a) - 1.0) < 1e-5

    def test_orthogonal_vectors(self):
        a = np.array([1.0, 0.0], dtype=np.float32)
        b = np.array([0.0, 1.0], dtype=np.float32)
        assert abs(cosine_similarity(a, b)) < 1e-5

    def test_opposite_vectors(self):
        a = np.array([1.0, 0.0], dtype=np.float32)
        b = np.array([-1.0, 0.0], dtype=np.float32)
        assert abs(cosine_similarity(a, b) - (-1.0)) < 1e-5

    def test_arbitrary_vectors(self):
        a = np.array([3.0, 4.0], dtype=np.float32)
        b = np.array([1.0, 0.0], dtype=np.float32)
        expected = 3.0 / 5.0  # dot / (5 * 1)
        assert abs(cosine_similarity(a, b) - expected) < 1e-5

    def test_512d_embeddings(self):
        np.random.seed(42)
        a = np.random.randn(512).astype(np.float32)
        b = np.random.randn(512).astype(np.float32)
        sim = cosine_similarity(a, b)
        assert -1.0 <= sim <= 1.0

    def test_wrong_dimension_raises(self):
        a = np.array([1.0, 0.0], dtype=np.float32)
        b = np.array([1.0, 0.0, 0.0], dtype=np.float32)
        with pytest.raises(ValueError, match="dimension"):
            cosine_similarity(a, b)

    def test_zero_norm_raises(self):
        a = np.zeros(512, dtype=np.float32)
        b = np.ones(512, dtype=np.float32)
        with pytest.raises(ValueError, match="zero-norm"):
            cosine_similarity(a, b)

    def test_nan_raises(self):
        a = np.ones(512, dtype=np.float32)
        a[0] = np.nan
        b = np.ones(512, dtype=np.float32)
        with pytest.raises(ValueError, match="finite"):
            cosine_similarity(a, b)

    def test_inf_raises(self):
        a = np.ones(512, dtype=np.float32)
        a[0] = np.inf
        b = np.ones(512, dtype=np.float32)
        with pytest.raises(ValueError, match="finite"):
            cosine_similarity(a, b)

    def test_clamping_overshoot(self):
        """Tiny floating-point overshoot should be clamped to [-1, 1]."""
        a = np.array([1.0, 0.0], dtype=np.float32)
        b = np.array([1.0, 1e-12], dtype=np.float32)  # near-identical
        sim = cosine_similarity(a, b)
        assert -1.0 <= sim <= 1.0


# ---------------------------------------------------------------------------
# Real-model integration (skipped when model is absent)
# ---------------------------------------------------------------------------

class TestArcFaceRealModel:
    """Integration tests that require the real w600k_mbf.onnx weight."""

    @pytest.mark.skipif(
        not (Path("models") / "w600k_mbf.onnx").exists(),
        reason="ArcFace model asset models/w600k_mbf.onnx is not present",
    )
    def test_real_model_produces_512d_embedding(self):
        embedder = ArcFaceEmbedder()
        img = _make_aligned_face()
        result = embedder.embed(img)
        assert result.embedding.shape == (512,)
        assert np.all(np.isfinite(result.embedding))
        norm = float(np.linalg.norm(result.embedding))
        assert abs(norm - 1.0) < 1e-4
