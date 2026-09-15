"""Rule-based face quality gate (size, blur, pose, brightness, contrast)."""
import math

import numpy as np
from PIL import Image


def _laplacian_var(gray):
    """Variance of the 3x3 Laplacian — standard focus/blur metric."""
    lap = (
        -4.0 * gray[1:-1, 1:-1]
        + gray[:-2, 1:-1]
        + gray[2:, 1:-1]
        + gray[1:-1, :-2]
        + gray[1:-1, 2:]
    )
    return float(lap.var())


def assess(
    img,
    bbox,
    kps,
    min_side=60,
    blur_min=60.0,
    yaw_max=0.32,
    roll_max=18.0,
    bright_range=(40.0, 225.0),
    contrast_min=12.0,
):
    """Returns {ok, reasons[], metrics{}} — reasons empty means pass."""
    x1, y1, x2, y2 = [float(v) for v in bbox]
    w, h = x2 - x1, y2 - y1
    crop = img.convert("RGB").crop(
        (max(0, int(x1)), max(0, int(y1)), min(img.width, int(x2)), min(img.height, int(y2)))
    )
    if crop.width < 8 or crop.height < 8:
        return {
            "ok": False,
            "reasons": ["face_too_small"],
            "metrics": {"face_w": round(w, 1), "face_h": round(h, 1)},
        }
    gray = np.asarray(crop.resize((112, 112), Image.BILINEAR).convert("L"), dtype=np.float32)
    blur = _laplacian_var(gray)
    bright = float(gray.mean())
    contrast = float(gray.std())

    left_eye, right_eye, nose, l_mouth, r_mouth = [np.asarray(p, dtype=np.float64) for p in kps]
    roll = math.degrees(math.atan2(right_eye[1] - left_eye[1], right_eye[0] - left_eye[0]))
    d_left = float(np.linalg.norm(nose - left_eye))
    d_right = float(np.linalg.norm(nose - right_eye))
    yaw = (d_left - d_right) / (d_left + d_right + 1e-6)

    reasons = []
    if min(w, h) < min_side:
        reasons.append(f"face_too_small({min(w, h):.0f}px<{min_side:.0f})")
    if blur < blur_min:
        reasons.append(f"blurry({blur:.0f}<{blur_min:.0f})")
    if abs(yaw) > yaw_max:
        reasons.append(f"yaw({yaw:+.2f})")
    if abs(roll) > roll_max:
        reasons.append(f"roll({roll:+.1f}deg)")
    if not (bright_range[0] <= bright <= bright_range[1]):
        reasons.append(f"brightness({bright:.0f})")
    if contrast < contrast_min:
        reasons.append(f"contrast({contrast:.0f})")

    return {
        "ok": not reasons,
        "reasons": reasons,
        "metrics": {
            "face_w": round(w, 1),
            "face_h": round(h, 1),
            "blur": round(blur, 1),
            "brightness": round(bright, 1),
            "contrast": round(contrast, 1),
            "yaw": round(yaw, 3),
            "roll": round(roll, 1),
        },
    }