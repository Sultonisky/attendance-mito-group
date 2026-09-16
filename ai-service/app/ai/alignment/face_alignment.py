"""Five-point face alignment for ArcFace input.

Converts detected 5-point landmarks to a 112×112 aligned face image
using the Umeyama similarity transform.

Landmark order (InsightFace / ArcFace standard):
    0. left eye
    1. right eye
    2. nose
    3. left mouth
    4. right mouth
"""

from __future__ import annotations

import logging
from typing import Sequence

import numpy as np
from PIL import Image

logger = logging.getLogger(__name__)

# Standard ArcFace 112×112 reference template (InsightFace order).
_REF = np.array(
    [
        [38.2946, 51.6963],
        [73.5318, 51.5014],
        [56.0252, 71.7366],
        [41.5493, 92.3655],
        [70.7299, 92.2041],
    ],
    dtype=np.float64,
)

_OUTPUT_SIZE = 112


class FaceAligner:
    """Align a face image to the ArcFace 112×112 template.

    Accepts a PIL RGB image and five facial landmarks, and returns a
    PIL RGB image warped so that the landmarks match the reference
    template used by ArcFace.

    The transformation is deterministic for identical inputs.
    """

    def __init__(self, image_size: int = _OUTPUT_SIZE) -> None:
        if image_size != _OUTPUT_SIZE:
            raise ValueError(
                f"FaceAligner currently supports only image_size={_OUTPUT_SIZE}."
            )
        self._image_size = image_size

    def align(
        self,
        image: Image.Image,
        landmarks: Sequence[tuple[float, float]] | Sequence[Sequence[float]],
    ) -> Image.Image:
        """Align ``image`` using five facial landmarks.

        Args:
            image: PIL Image in RGB mode.
            landmarks: Five (x, y) landmark points in the order:
                left_eye, right_eye, nose, left_mouth, right_mouth.
                Coordinates must be in the original image pixel space.

        Returns:
            PIL RGB image of size ``(image_size, image_size)``.

        Raises:
            ValueError: If ``landmarks`` does not contain exactly 5
                points, or if any coordinate is non-finite.
        """
        landmarks_array = self._validate_landmarks(landmarks)
        M = _umeyama(landmarks_array, _REF, estimate_scale=True)
        # PIL transform expects the inverse mapping (output -> input).
        Minv = np.linalg.inv(np.vstack([M, [0, 0, 1]]))[:2, :]
        coeffs = (
            float(Minv[0, 0]),
            float(Minv[0, 1]),
            float(Minv[0, 2]),
            float(Minv[1, 0]),
            float(Minv[1, 1]),
            float(Minv[1, 2]),
        )
        return image.convert("RGB").transform(
            (self._image_size, self._image_size),
            Image.AFFINE,
            coeffs,
            resample=Image.BILINEAR,
            fillcolor=(0, 0, 0),
        )

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    @staticmethod
    def _validate_landmarks(
        landmarks: Sequence[tuple[float, float]] | Sequence[Sequence[float]],
    ) -> np.ndarray:
        """Validate and convert landmarks to a float64 NumPy array."""
        if len(landmarks) != 5:
            raise ValueError(
                f"Expected exactly 5 landmarks, got {len(landmarks)}."
            )

        arr = np.asarray(landmarks, dtype=np.float64)
        if arr.shape != (5, 2):
            raise ValueError(
                f"Landmarks must have shape (5, 2), got {arr.shape}."
            )
        if not np.all(np.isfinite(arr)):
            raise ValueError("Landmark coordinates must be finite numbers.")

        return arr


def _umeyama(
    src: np.ndarray,
    dst: np.ndarray,
    estimate_scale: bool = True,
) -> np.ndarray:
    """Least-squares similarity transform (Umeyama), InsightFace-compatible.

    Returns a 2×3 affine matrix.
    """
    src = np.asarray(src, dtype=np.float64)
    dst = np.asarray(dst, dtype=np.float64)
    n = src.shape[0]

    mu_s = src.mean(axis=0)
    mu_d = dst.mean(axis=0)
    src_c = src - mu_s
    dst_c = dst - mu_d
    cov = dst_c.T @ src_c / n

    U, S, Vt = np.linalg.svd(cov)
    d = np.ones(2)
    if np.linalg.det(U) * np.linalg.det(Vt) < 0:
        d[1] = -1

    R = U @ np.diag(d) @ Vt

    if estimate_scale:
        var_s = (src_c**2).sum() / n
        scale = float((S * d).sum() / var_s)
    else:
        scale = 1.0

    t = mu_d - scale * (R @ mu_s)
    M = np.eye(3)
    M[:2, :2] = scale * R
    M[:2, 2] = t
    return M[:2, :]


def crop_scale(
    img: Image.Image,
    bbox: tuple[float, float, float, float],
    scale: float,
    out_size: int = 80,
) -> Image.Image:
    """Crop ``bbox`` expanded by ``scale`` and resize to ``out_size``.

    Uses the upstream minivision ``_get_new_box`` semantics: the box is
    shifted/trimmed to stay inside the image. Black padding is NOT added
    because it pushes MiniFASNet off-distribution.

    Args:
        img: PIL RGB image.
        bbox: Bounding box as ``(x1, y1, x2, y2)`` in image coordinates.
        scale: Expansion factor applied to the bbox before cropping.
        out_size: Output size in pixels (width == height).

    Returns:
        Resized PIL RGB image of shape ``(out_size, out_size)``.

    Raises:
        ValueError: If the bbox is too small.
    """
    x1, y1, x2, y2 = (float(v) for v in bbox)
    box_w = x2 - x1
    box_h = y2 - y1
    if box_w <= 1 or box_h <= 1:
        raise ValueError("bbox too small for crop_scale")

    src_w, src_h = img.size
    scale = min((src_h - 1) / box_h, min((src_w - 1) / box_w, scale))
    new_w = box_w * scale
    new_h = box_h * scale
    cx = box_w / 2 + x1
    cy = box_h / 2 + y1
    l = cx - new_w / 2
    t = cy - new_h / 2
    r = cx + new_w / 2
    b = cy + new_h / 2

    if l < 0:
        r -= l
        l = 0
    if t < 0:
        b -= t
        t = 0
    if r > src_w - 1:
        l -= r - src_w + 1
        r = src_w - 1
    if b > src_h - 1:
        t -= b - src_h + 1
        b = src_h - 1

    return img.resize(
        (int(out_size), int(out_size)),
        Image.BILINEAR,
        box=(int(l), int(t), int(r), int(b)),
    )
