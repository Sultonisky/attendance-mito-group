"""ArcFace 512-D face embedding inference.

Consumes a 112×112 aligned PIL RGB face from AI-2B and returns an
L2-normalized 512-D embedding.

Reference: ``PRESENSI-main/mito/arcface.py`` (InsightFace w600k_mbf).
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Sequence

import numpy as np
from PIL import Image

from app.core.model_assets import ModelAssetRegistry, create_default_registry
from app.core.model_loader import (
    ModelInvalid,
    ModelLoadError,
    ModelLoader,
    ModelNotFound,
    ModelUnavailable,
)

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# ArcFace preprocessing constants (InsightFace standard)
# ---------------------------------------------------------------------------
_INPUT_SIZE = 112
_NORMALIZATION_OFFSET = 127.5
_NORMALIZATION_SCALE = 128.0
_EXPECTED_EMBEDDING_DIM = 512


@dataclass(frozen=True)
class EmbeddingResult:
    """L2-normalized ArcFace embedding result."""

    embedding: np.ndarray
    """Float32 NumPy array of shape ``(512,)`` with L2 norm ≈ 1.0."""

    model_version: str
    """Pipeline version that produced this embedding."""

    dimension: int = _EXPECTED_EMBEDDING_DIM
    """Embedding dimensionality."""


class ArcFaceEmbedder:
    """ArcFace 512-D embedding inference.

    Uses AI-2A's ``ModelLoader`` and ``ModelAssetRegistry`` so the
    embedder does not own model-path resolution or session caching.

    The canonical input is the 112×112 aligned PIL RGB face produced
    by AI-2B's ``FaceAligner``.
    """

    def __init__(
        self,
        registry: ModelAssetRegistry | None = None,
        loader: ModelLoader | None = None,
    ) -> None:
        self._registry = registry or create_default_registry()
        self._loader = loader or ModelLoader(self._registry)

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def embed(self, aligned_face: Image.Image) -> EmbeddingResult:
        """Generate an embedding from an aligned face.

        Args:
            aligned_face: PIL RGB image of exactly 112×112 pixels.
                This must be the output of the AI-2B alignment stage.

        Returns:
            ``EmbeddingResult`` with an L2-normalized 512-D embedding.

        Raises:
            ModelNotFound: ArcFace model file is missing.
            ModelInvalid: ArcFace model cannot be loaded.
            ModelUnavailable: ONNX runtime is unavailable.
            ValueError: ``aligned_face`` does not match the expected
                112×112 RGB contract.
        """
        self._validate_input(aligned_face)
        session = self._loader.load("arcface")

        blob = self._preprocess(aligned_face)
        input_name = session.get_inputs()[0].name
        output_name = session.get_outputs()[0].name

        raw_output = session.run([output_name], {input_name: blob})[0]
        embedding = self._postprocess(raw_output)

        return EmbeddingResult(
            embedding=embedding,
            model_version=self._registry.pipeline_version,
        )

    # ------------------------------------------------------------------
    # Preprocessing / postprocessing
    # ------------------------------------------------------------------

    @staticmethod
    def _preprocess(aligned_face: Image.Image) -> np.ndarray:
        """Convert 112×112 PIL RGB to NCHW float32 normalized blob.

        Matches the InsightFace preprocessing contract:
            RGB order, (x - 127.5) / 128.0, NCHW layout.
        """
        arr = np.asarray(aligned_face.convert("RGB"), dtype=np.float32)
        blob = (arr - _NORMALIZATION_OFFSET) / _NORMALIZATION_SCALE
        return blob.transpose(2, 0, 1)[None]

    @staticmethod
    def _postprocess(raw_output: np.ndarray) -> np.ndarray:
        """Validate, reshape, and L2-normalize the raw model output.

        Args:
            raw_output: Raw ONNX output array.

        Returns:
            Float32 NumPy array of shape ``(512,)`` with L2 norm ≈ 1.0.

        Raises:
            ValueError: If the output shape is not compatible with a
                single 512-D embedding.
            RuntimeError: If the embedding has zero norm after squeeze.
        """
        embedding = np.asarray(raw_output, dtype=np.float32).reshape(-1)

        if embedding.shape[0] != _EXPECTED_EMBEDDING_DIM:
            raise ValueError(
                f"ArcFace output dimension mismatch: expected "
                f"{_EXPECTED_EMBEDDING_DIM}, got {embedding.shape[0]}."
            )

        norm = float(np.linalg.norm(embedding))
        if norm == 0.0 or not np.isfinite(norm):
            raise RuntimeError(
                f"ArcFace produced an invalid embedding (norm={norm})."
            )

        normalized = embedding / norm
        return normalized.astype(np.float32)

    # ------------------------------------------------------------------
    # Input validation
    # ------------------------------------------------------------------

    @staticmethod
    def _validate_input(aligned_face: Image.Image) -> None:
        """Validate that the input matches the 112×112 RGB contract."""
        if not isinstance(aligned_face, Image.Image):
            raise ValueError(
                f"Expected PIL Image, got {type(aligned_face).__name__}."
            )

        mode = aligned_face.mode
        if mode != "RGB":
            raise ValueError(
                f"Expected RGB image, got '{mode}'. "
                f"Convert with image.convert('RGB') before calling embed()."
            )

        width, height = aligned_face.size
        if width != _INPUT_SIZE or height != _INPUT_SIZE:
            raise ValueError(
                f"Expected {_INPUT_SIZE}×{_INPUT_SIZE} aligned face, "
                f"got {width}×{height}."
            )
