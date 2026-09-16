"""MiniFASNetV2 face liveness / anti-spoofing inference.

Consumes an original RGB image and a bounding box from AI-2B SCRFD detection,
and returns liveness probabilities produced by the MiniFASNetV2 ONNX model.

Reference: ``PRESENSI-main/mito/liveness.py``

Model input contract (verified against ONNX export and upstream minivision source):
    * BGR, raw 0-255 (no /255 normalization)
    * Shape: (1, 3, 80, 80)
    * dtype: float32

Model output:
    * 3 logits in order: (print-attack, real, replay-attack)
    * Class index 1 = ``real``
"""

from __future__ import annotations

import logging
import math
from dataclasses import dataclass
from typing import Sequence

import numpy as np
from PIL import Image

from app.ai.alignment.face_alignment import crop_scale
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
# Model constants (from reference implementation)
# ---------------------------------------------------------------------------
_INPUT_SIZE = 80
_CLASSES = ("print", "real", "replay")
_LIVE_INDEX = _CLASSES.index("real")
_DEFAULT_SCALES = (2.7, 4.0)


@dataclass(frozen=True)
class LivenessResult:
    """MiniFASNetV2 liveness assessment result.

    All scores are AI facts. No biometric acceptance decision is made here.
    """

    label: str
    """Predicted class: ``print``, ``real``, or ``replay``."""

    live_prob: float
    """Probability that the face is a real live person (class ``real``).

    Range: ``[0.0, 1.0]`` after softmax.
    """

    probs: dict[str, float]
    """Per-class probabilities keyed by class name."""

    logits: tuple[float, ...]
    """Raw model logits before softmax."""

    model_version: str
    """Pipeline version that produced this result."""


class MiniFASNetV2:
    """MiniFASNetV2 liveness / anti-spoofing inference.

    Uses AI-2A's ``ModelLoader`` and ``ModelAssetRegistry`` so the
    liveness component does not own model-path resolution or session caching.

    The canonical input is an original PIL RGB image plus a bounding box
    from AI-2B SCRFD detection. The component does **not** perform face
    detection, alignment, or embedding.
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

    def assess(
        self,
        image: Image.Image,
        bbox: tuple[float, float, float, float],
        scales: Sequence[float] = _DEFAULT_SCALES,
    ) -> LivenessResult:
        """Run MiniFASNetV2 liveness assessment.

        Args:
            image: Original PIL RGB image from which the face was detected.
            bbox: Bounding box in original-image coordinates as
                ``(x1, y1, x2, y2)``.
            scales: Crop expansion scales for ensemble. Defaults to
                ``(2.7, 4.0)`` matching the reference implementation.

        Returns:
            ``LivenessResult`` with liveness probabilities.

        Raises:
            ModelNotFound: MiniFASNetV2 model file is missing.
            ModelInvalid: MiniFASNetV2 model cannot be loaded.
            ModelUnavailable: ONNX runtime is unavailable.
            ValueError: If ``bbox`` or ``image`` is invalid.
        """
        session = self._loader.load("liveness")
        x1, y1, x2, y2 = _validate_bbox(bbox)
        _validate_image(image)

        if len(scales) == 1:
            return self._predict(image, bbox, float(scales[0]), session)

        per_scale = [
            self._predict(image, bbox, float(s), session) for s in scales
        ]
        stack = np.array(
            [[p.probs[c] for c in _CLASSES] for p in per_scale], dtype=np.float64
        )
        probs = stack.mean(axis=0)
        idx = int(np.argmax(probs))

        return LivenessResult(
            label=_CLASSES[idx],
            live_prob=round(float(probs[_LIVE_INDEX]), 4),
            probs={c: round(float(p), 4) for c, p in zip(_CLASSES, probs)},
            logits=per_scale[0].logits,  # logits from first scale
            model_version=self._registry.pipeline_version,
        )

    # ------------------------------------------------------------------
    # Single-scale inference
    # ------------------------------------------------------------------

    def _predict(
        self,
        image: Image.Image,
        bbox: tuple[float, float, float, float],
        scale: float,
        session,
    ) -> LivenessResult:
        """Run one scale of MiniFASNetV2 inference."""
        patch = crop_scale(image, bbox, scale, _INPUT_SIZE)
        blob = self._preprocess(patch)

        input_name = session.get_inputs()[0].name
        output_name = session.get_outputs()[0].name
        raw_output = session.run([output_name], {input_name: blob})[0]

        logits = self._postprocess_logits(raw_output)
        probs = _softmax(logits)
        idx = int(np.argmax(probs))

        return LivenessResult(
            label=_CLASSES[idx],
            live_prob=round(float(probs[_LIVE_INDEX]), 4),
            probs={c: round(float(p), 4) for c, p in zip(_CLASSES, probs)},
            logits=tuple(round(float(v), 3) for v in logits),
            model_version=self._registry.pipeline_version,
        )

    # ------------------------------------------------------------------
    # Preprocessing / postprocessing
    # ------------------------------------------------------------------

    @staticmethod
    def _preprocess(patch: Image.Image) -> np.ndarray:
        """Convert 80×80 PIL RGB patch to NCHW float32 BGR blob.

        MiniFASNetV2 expects:
            * BGR channel order (not RGB)
            * Raw 0-255 (no /255 normalization)
            * Shape: (1, 3, 80, 80)
            * dtype: float32
        """
        arr = np.asarray(patch.convert("RGB"), dtype=np.float32)
        bgr = np.ascontiguousarray(arr[:, :, ::-1])
        return bgr.transpose(2, 0, 1)[None]

    @staticmethod
    def _postprocess_logits(raw_output: np.ndarray) -> np.ndarray:
        """Validate and return raw logits as 1-D float32 array."""
        logits = np.asarray(raw_output, dtype=np.float32).reshape(-1)
        if logits.shape[0] != 3:
            raise ValueError(
                f"MiniFASNetV2 output must have 3 classes, got {logits.shape[0]}."
            )
        if not np.all(np.isfinite(logits)):
            raise ValueError("MiniFASNetV2 logits contain non-finite values.")
        return logits


# ---------------------------------------------------------------------------
# Private helpers
# ---------------------------------------------------------------------------

def _softmax(x: np.ndarray) -> np.ndarray:
    """Numerically stable softmax."""
    e = np.exp(x - np.max(x))
    return e / e.sum()


def _validate_bbox(
    bbox: tuple[float, float, float, float],
) -> tuple[float, float, float, float]:
    """Validate and return bounding box as (x1, y1, x2, y2)."""
    if len(bbox) != 4:
        raise ValueError(
            f"bbox must contain 4 values (x1, y1, x2, y2), got {len(bbox)}."
        )
    x1, y1, x2, y2 = (float(v) for v in bbox)
    if not all(math.isfinite(v) for v in (x1, y1, x2, y2)):
        raise ValueError("bbox coordinates must be finite numbers.")
    if x2 <= x1 or y2 <= y1:
        raise ValueError(
            f"Invalid bbox: x2 ({x2}) must be > x1 ({x1}) and "
            f"y2 ({y2}) must be > y1 ({y1})."
        )
    return x1, y1, x2, y2


def _validate_image(image: Image.Image) -> None:
    """Validate that the image is a PIL RGB image."""
    if not isinstance(image, Image.Image):
        raise ValueError(
            f"Expected PIL Image, got {type(image).__name__}."
        )
    if image.mode != "RGB":
        raise ValueError(
            f"Expected RGB image, got '{image.mode}'. "
            f"Convert with image.convert('RGB') before calling assess()."
        )
    if image.width == 0 or image.height == 0:
        raise ValueError("Image must have non-zero width and height.")
