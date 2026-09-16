"""Face quality assessment based on reference implementation.

Computes image-quality metrics for an already-detected face:

* Blur / sharpness (Laplacian variance)
* Brightness (mean luminance)
* Contrast (standard deviation)
* Face size (bounding-box dimensions)
* Pose (yaw / roll from 5-point landmarks)

This module returns **quality facts only**. It does not make biometric
acceptance decisions. Threshold-based policy belongs to the future
pipeline layer.

Reference: ``PRESENSI-main/mito/quality.py``
"""

from __future__ import annotations

import logging
import math
from dataclasses import dataclass
from typing import Sequence

import numpy as np
from PIL import Image

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Reference-derived initial thresholds.
#
# These come from the reference implementation and are documented here as
# starting configuration only. They are NOT production-calibrated.
# ---------------------------------------------------------------------------
_MIN_FACE_SIDE_PX = 60.0
_BLUR_MIN = 60.0
_YAW_MAX = 0.32
_ROLL_MAX = 18.0
_BRIGHT_RANGE = (40.0, 225.0)
_CONTRAST_MIN = 12.0

# Internal crop size used for blur/brightness/contrast measurement.
_QUALITY_CROP_SIZE = 112


@dataclass(frozen=True)
class QualityInput:
    """Input for face quality assessment.

    Attributes:
        image: Original PIL RGB image from which the face was detected.
        bbox: Bounding box in original-image coordinates as
            ``(x1, y1, x2, y2)``.
        landmarks: Five facial landmarks in original-image coordinates.
            Order: left_eye, right_eye, nose, left_mouth, right_mouth.
    """

    image: Image.Image
    bbox: tuple[float, float, float, float]
    landmarks: tuple[tuple[float, float], ...]


@dataclass(frozen=True)
class QualityResult:
    """Face quality measurement results.

    All metrics are raw facts. No threshold-based pass/fail decision
    is included here.
    """

    blur: float
    """Laplacian variance of the face crop. Higher = sharper."""

    brightness: float
    """Mean pixel value of the grayscale face crop."""

    contrast: float
    """Standard deviation of the grayscale face crop."""

    face_width: float
    """Face bounding-box width in original image pixels."""

    face_height: float
    """Face bounding-box height in original image pixels."""

    yaw: float
    """Approximate yaw angle in radians, derived from landmarks."""

    roll: float
    """Approximate roll angle in degrees, derived from eye landmarks."""


class FaceQualityAssessor:
    """Assess face image quality from detection results.

    Does **not** run face detection. Consumes detection/alignment
    outputs from AI-2B and returns measurable quality facts.
    """

    def assess(self, quality_input: QualityInput) -> QualityResult:
        """Compute quality metrics for a detected face.

        Args:
            quality_input: Detection result containing the original
                image, bounding box, and five-point landmarks.

        Returns:
            ``QualityResult`` with measured quality metrics.

        Raises:
            ValueError: If ``bbox`` or ``landmarks`` are invalid, or
                if the cropped face is too small for measurement.
        """
        image = quality_input.image
        x1, y1, x2, y2 = _validate_bbox(quality_input.bbox)
        landmarks = _validate_landmarks(quality_input.landmarks)

        # Crop the face region from the original image.
        crop = image.convert("RGB").crop(
            (
                max(0, int(x1)),
                max(0, int(y1)),
                min(image.width, int(x2)),
                min(image.height, int(y2)),
            )
        )

        if crop.width < 8 or crop.height < 8:
            raise ValueError(
                f"Face crop is too small for quality measurement: "
                f"{crop.width}×{crop.height} px (minimum 8×8)."
            )

        # Resize to fixed size and convert to grayscale for blur/brightness/contrast.
        gray = np.asarray(
            crop.resize(
                (_QUALITY_CROP_SIZE, _QUALITY_CROP_SIZE),
                Image.BILINEAR,
            ).convert("L"),
            dtype=np.float32,
        )

        blur = _laplacian_variance(gray)
        brightness = float(gray.mean())
        contrast = float(gray.std())

        # Pose from five-point landmarks.
        left_eye, right_eye, nose, l_mouth, r_mouth = [
            np.asarray(p, dtype=np.float64) for p in landmarks
        ]
        roll = math.degrees(
            math.atan2(
                right_eye[1] - left_eye[1],
                right_eye[0] - left_eye[0],
            )
        )
        d_left = float(np.linalg.norm(nose - left_eye))
        d_right = float(np.linalg.norm(nose - right_eye))
        yaw = (d_left - d_right) / (d_left + d_right + 1e-6)

        return QualityResult(
            blur=round(blur, 1),
            brightness=round(brightness, 1),
            contrast=round(contrast, 1),
            face_width=round(float(x2 - x1), 1),
            face_height=round(float(y2 - y1), 1),
            yaw=round(yaw, 3),
            roll=round(roll, 1),
        )


# ---------------------------------------------------------------------------
# Private helpers
# ---------------------------------------------------------------------------

def _laplacian_variance(gray: np.ndarray) -> float:
    """Variance of the 4-neighbor Laplacian — standard sharpness/blur metric.

    Matches the reference implementation exactly:
        -4 * center + top + bottom + left + right
    """
    lap = (
        -4.0 * gray[1:-1, 1:-1]
        + gray[:-2, 1:-1]
        + gray[2:, 1:-1]
        + gray[1:-1, :-2]
        + gray[1:-1, 2:]
    )
    return float(lap.var())


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


def _validate_landmarks(
    landmarks: Sequence[tuple[float, float]] | Sequence[Sequence[float]],
) -> tuple[tuple[float, float], ...]:
    """Validate and return five facial landmarks."""
    if len(landmarks) != 5:
        raise ValueError(
            f"Expected exactly 5 landmarks, got {len(landmarks)}."
        )
    validated: list[tuple[float, float]] = []
    for i, point in enumerate(landmarks):
        if len(point) != 2:
            raise ValueError(
                f"Landmark {i} must have 2 coordinates (x, y), got {len(point)}."
            )
        x, y = float(point[0]), float(point[1])
        if not math.isfinite(x) or not math.isfinite(y):
            raise ValueError(
                f"Landmark {i} coordinates must be finite, got ({point[0]}, {point[1]})."
            )
        validated.append((x, y))
    return tuple(validated)
