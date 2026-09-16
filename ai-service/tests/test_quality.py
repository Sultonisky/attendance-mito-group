"""Tests for face quality assessment (AI-2D).

Verifies the quality metric calculations against the reference
implementation's algorithm. Tests use deterministic synthetic images
and geometry; no production ONNX models are required.
"""

from __future__ import annotations

import math
import os
from pathlib import Path

import numpy as np
import pytest
from PIL import Image

from app.ai.quality.face_quality import (
    _MIN_FACE_SIDE_PX,
    FaceQualityAssessor,
    QualityInput,
    QualityResult,
    _laplacian_variance,
)
from app.core.config import get_settings

# Ensure settings are loaded with a test API key before app imports.
os.environ.setdefault("AI_API_KEY", "test-secret-key-123")
get_settings.cache_clear()


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_image(
    width: int,
    height: int,
    color: tuple[int, int, int] = (128, 128, 128),
) -> Image.Image:
    """Create a solid-color RGB image."""
    return Image.new("RGB", (width, height), color)


def _make_checkerboard(
    width: int,
    height: int,
    tile_size: int = 4,
) -> Image.Image:
    """Create a high-frequency checkerboard pattern."""
    arr = np.zeros((height, width, 3), dtype=np.uint8)
    for y in range(0, height, tile_size):
        for x in range(0, width, tile_size):
            if ((x // tile_size) + (y // tile_size)) % 2 == 0:
                arr[y : y + tile_size, x : x + tile_size] = 255
    return Image.fromarray(arr, mode="RGB")


def _landmarks_centered(
    cx: float,
    cy: float,
    eye_separation: float = 20.0,
    eye_y: float = 0.0,
    nose_y: float = 10.0,
    mouth_y: float = 25.0,
    mouth_separation: float = 15.0,
) -> tuple[tuple[float, float], ...]:
    """Return symmetric 5-point landmarks centered at (cx, cy)."""
    return (
        (cx - eye_separation / 2, cy + eye_y),
        (cx + eye_separation / 2, cy + eye_y),
        (cx, cy + nose_y),
        (cx - mouth_separation / 2, cy + mouth_y),
        (cx + mouth_separation / 2, cy + mouth_y),
    )


def _landmarks_yaw(
    cx: float,
    cy: float,
    nose_offset_x: float,
) -> tuple[tuple[float, float], ...]:
    """Return landmarks with a yaw offset (nose shifted horizontally)."""
    return (
        (cx - 10.0, cy),
        (cx + 10.0, cy),
        (cx + nose_offset_x, cy + 10.0),
        (cx - 8.0, cy + 25.0),
        (cx + 8.0, cy + 25.0),
    )


def _landmarks_roll(
    cx: float,
    cy: float,
    left_eye_y_offset: float = 0.0,
    right_eye_y_offset: float = 0.0,
) -> tuple[tuple[float, float], ...]:
    """Return landmarks with a roll offset (eyes at different heights)."""
    return (
        (cx - 10.0, cy + left_eye_y_offset),
        (cx + 10.0, cy + right_eye_y_offset),
        (cx, cy + 10.0),
        (cx - 8.0, cy + 25.0),
        (cx + 8.0, cy + 25.0),
    )


# ---------------------------------------------------------------------------
# Blur / sharpness
# ---------------------------------------------------------------------------

class TestBlurMetric:
    """Laplacian variance blur metric."""

    def test_uniform_image_has_low_blur(self):
        gray = np.full((112, 112), 128.0, dtype=np.float32)
        blur = _laplacian_variance(gray)
        assert blur == 0.0

    def test_checkerboard_has_high_blur(self):
        img = _make_checkerboard(112, 112, tile_size=4)
        gray = np.asarray(img.convert("L").resize((112, 112), Image.BILINEAR), dtype=np.float32)
        blur = _laplacian_variance(gray)
        assert blur > 100.0

    def test_blur_increases_with_frequency(self):
        gray_fine = np.asarray(
            _make_checkerboard(112, 112, tile_size=2).convert("L").resize((112, 112), Image.BILINEAR),
            dtype=np.float32,
        )
        gray_coarse = np.asarray(
            _make_checkerboard(112, 112, tile_size=16).convert("L").resize((112, 112), Image.BILINEAR),
            dtype=np.float32,
        )
        assert _laplacian_variance(gray_fine) > _laplacian_variance(gray_coarse)


# ---------------------------------------------------------------------------
# Brightness
# ---------------------------------------------------------------------------

class TestBrightnessMetric:
    """Mean luminance metric."""

    def test_dark_image_low_brightness(self):
        img = _make_image(112, 112, color=(10, 10, 10))
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(10.0, 10.0, 50.0, 50.0),
                landmarks=_landmarks_centered(30.0, 30.0),
            )
        )
        assert result.brightness < 50.0

    def test_mid_brightness(self):
        img = _make_image(112, 112, color=(128, 128, 128))
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(10.0, 10.0, 50.0, 50.0),
                landmarks=_landmarks_centered(30.0, 30.0),
            )
        )
        assert 100.0 < result.brightness < 160.0

    def test_bright_image_high_brightness(self):
        img = _make_image(112, 112, color=(240, 240, 240))
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(10.0, 10.0, 50.0, 50.0),
                landmarks=_landmarks_centered(30.0, 30.0),
            )
        )
        assert result.brightness > 200.0


# ---------------------------------------------------------------------------
# Contrast
# ---------------------------------------------------------------------------

class TestContrastMetric:
    """Standard deviation contrast metric."""

    def test_uniform_image_low_contrast(self):
        img = _make_image(112, 112, color=(128, 128, 128))
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(10.0, 10.0, 50.0, 50.0),
                landmarks=_landmarks_centered(30.0, 30.0),
            )
        )
        assert result.contrast < 1.0

    def test_high_contrast_image(self):
        # Black and white checkerboard has high contrast.
        img = _make_checkerboard(112, 112, tile_size=4)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(10.0, 10.0, 100.0, 100.0),
                landmarks=_landmarks_centered(55.0, 55.0),
            )
        )
        assert result.contrast > 40.0


# ---------------------------------------------------------------------------
# Face size
# ---------------------------------------------------------------------------

class TestFaceSizeMetric:
    """Bounding-box dimension metrics."""

    def test_small_face(self):
        img = _make_image(200, 200)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(50.0, 50.0, 70.0, 70.0),
                landmarks=_landmarks_centered(60.0, 60.0),
            )
        )
        assert result.face_width == 20.0
        assert result.face_height == 20.0

    def test_large_face(self):
        img = _make_image(200, 200)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(10.0, 10.0, 190.0, 190.0),
                landmarks=_landmarks_centered(100.0, 100.0),
            )
        )
        assert result.face_width == 180.0
        assert result.face_height == 180.0

    def test_non_square_bbox(self):
        img = _make_image(200, 200)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(20.0, 40.0, 120.0, 80.0),
                landmarks=_landmarks_centered(70.0, 60.0),
            )
        )
        assert result.face_width == 100.0
        assert result.face_height == 40.0


# ---------------------------------------------------------------------------
# Pose
# ---------------------------------------------------------------------------

class TestPoseMetric:
    """Yaw and roll estimation from landmarks."""

    def test_centered_face_neutral_yaw(self):
        img = _make_image(200, 200)
        landmarks = _landmarks_centered(100.0, 100.0)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(50.0, 50.0, 150.0, 150.0),
                landmarks=landmarks,
            )
        )
        assert abs(result.yaw) < 0.05

    def test_right_gaze_positive_yaw(self):
        """Nose closer to right eye → positive yaw."""
        img = _make_image(200, 200)
        landmarks = _landmarks_yaw(100.0, 100.0, nose_offset_x=15.0)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(50.0, 50.0, 150.0, 150.0),
                landmarks=landmarks,
            )
        )
        assert result.yaw > 0.0

    def test_left_gaze_negative_yaw(self):
        """Nose closer to left eye → negative yaw."""
        img = _make_image(200, 200)
        landmarks = _landmarks_yaw(100.0, 100.0, nose_offset_x=-15.0)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(50.0, 50.0, 150.0, 150.0),
                landmarks=landmarks,
            )
        )
        assert result.yaw < 0.0

    def test_no_roll_when_eyes_level(self):
        img = _make_image(200, 200)
        landmarks = _landmarks_roll(100.0, 100.0, left_eye_y_offset=0.0, right_eye_y_offset=0.0)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(50.0, 50.0, 150.0, 150.0),
                landmarks=landmarks,
            )
        )
        assert abs(result.roll) < 1.0

    def test_positive_roll_when_right_eye_lower(self):
        img = _make_image(200, 200)
        landmarks = _landmarks_roll(100.0, 100.0, left_eye_y_offset=0.0, right_eye_y_offset=5.0)
        result = FaceQualityAssessor().assess(
            QualityInput(
                image=img,
                bbox=(50.0, 50.0, 150.0, 150.0),
                landmarks=landmarks,
            )
        )
        assert result.roll > 0.0


# ---------------------------------------------------------------------------
# Input validation
# ---------------------------------------------------------------------------

class TestQualityInputValidation:
    """Invalid inputs must fail explicitly."""

    def test_invalid_bbox_too_few_values(self):
        assessor = FaceQualityAssessor()
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="4 values"):
            assessor.assess(
                QualityInput(
                    image=img,
                    bbox=(10.0, 10.0, 50.0),
                    landmarks=_landmarks_centered(30.0, 30.0),
                )
            )

    def test_invalid_bbox_x2_not_greater_than_x1(self):
        assessor = FaceQualityAssessor()
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="x2"):
            assessor.assess(
                QualityInput(
                    image=img,
                    bbox=(50.0, 10.0, 10.0, 50.0),
                    landmarks=_landmarks_centered(30.0, 30.0),
                )
            )

    def test_invalid_bbox_non_finite(self):
        assessor = FaceQualityAssessor()
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="finite"):
            assessor.assess(
                QualityInput(
                    image=img,
                    bbox=(10.0, 10.0, float("nan"), 50.0),
                    landmarks=_landmarks_centered(30.0, 30.0),
                )
            )

    def test_invalid_landmarks_wrong_count(self):
        assessor = FaceQualityAssessor()
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="5 landmarks"):
            assessor.assess(
                QualityInput(
                    image=img,
                    bbox=(10.0, 10.0, 50.0, 50.0),
                    landmarks=((0.0, 0.0),),
                )
            )

    def test_invalid_landmarks_non_finite(self):
        assessor = FaceQualityAssessor()
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="finite"):
            assessor.assess(
                QualityInput(
                    image=img,
                    bbox=(10.0, 10.0, 50.0, 50.0),
                    landmarks=(
                        (0.0, 0.0),
                        (1.0, 1.0),
                        (2.0, 2.0),
                        (3.0, 3.0),
                        (float("inf"), 5.0),
                    ),
                )
            )

    def test_crop_too_small_raises(self):
        assessor = FaceQualityAssessor()
        img = _make_image(200, 200)
        with pytest.raises(ValueError, match="too small"):
            assessor.assess(
                QualityInput(
                    image=img,
                    bbox=(10.0, 10.0, 15.0, 15.0),
                    landmarks=_landmarks_centered(12.0, 12.0),
                )
            )


# ---------------------------------------------------------------------------
# Result validation
# ---------------------------------------------------------------------------

class TestQualityResult:
    """QualityResult structure and determinism."""

    def test_result_fields(self):
        img = _make_image(200, 200)
        assessor = FaceQualityAssessor()
        result = assessor.assess(
            QualityInput(
                image=img,
                bbox=(20.0, 20.0, 100.0, 100.0),
                landmarks=_landmarks_centered(60.0, 60.0),
            )
        )
        assert isinstance(result, QualityResult)
        assert isinstance(result.blur, float)
        assert isinstance(result.brightness, float)
        assert isinstance(result.contrast, float)
        assert isinstance(result.face_width, float)
        assert isinstance(result.face_height, float)
        assert isinstance(result.yaw, float)
        assert isinstance(result.roll, float)

    def test_result_is_deterministic(self):
        img = _make_image(200, 200)
        assessor = FaceQualityAssessor()
        input_data = QualityInput(
            image=img,
            bbox=(20.0, 20.0, 100.0, 100.0),
            landmarks=_landmarks_centered(60.0, 60.0),
        )
        result1 = assessor.assess(input_data)
        result2 = assessor.assess(input_data)
        assert result1 == result2

    def test_all_values_finite(self):
        img = _make_image(200, 200)
        assessor = FaceQualityAssessor()
        result = assessor.assess(
            QualityInput(
                image=img,
                bbox=(20.0, 20.0, 100.0, 100.0),
                landmarks=_landmarks_centered(60.0, 60.0),
            )
        )
        for field in (
            result.blur,
            result.brightness,
            result.contrast,
            result.face_width,
            result.face_height,
            result.yaw,
            result.roll,
        ):
            assert math.isfinite(field), f"{field} is not finite"
