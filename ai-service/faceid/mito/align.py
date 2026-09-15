"""ArcFace alignment: 5-point landmarks -> 112x112 similarity transform.

Pillow + numpy only (no cv2 / skimage needed).
"""
import numpy as np
from PIL import Image

# Standard ArcFace 112x112 reference template (insightface order:
# left eye, right eye, nose, left mouth, right mouth).
REF = np.array(
    [
        [38.2946, 51.6963],
        [73.5318, 51.5014],
        [56.0252, 71.7366],
        [41.5493, 92.3655],
        [70.7299, 92.2041],
    ],
    dtype=np.float64,
)


def umeyama(src, dst, estimate_scale=True):
    """Least-squares similarity transform, insightface-compatible (3x3)."""
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
    return M


def estimate_norm(landmarks5, image_size=112):
    """Return 2x3 matrix mapping landmark coords -> aligned 112x112 (forward map)."""
    if image_size % 112 == 0:
        ratio = image_size / 112.0
        diff = (image_size - 112) // 2
        ref = REF * ratio + diff
    else:
        ratio = image_size / 128.0
        diff = (image_size - 128) // 2
        ref = REF * ratio + diff
    M = umeyama(np.asarray(landmarks5, dtype=np.float64), ref, estimate_scale=True)
    return M[:2, :]


def align_face(img, landmarks5, image_size=112):
    """Warp `img` (PIL Image, RGB) so the 5 landmarks land on the ArcFace template."""
    M = estimate_norm(landmarks5, image_size)
    # PIL wants the inverse map (output -> input)
    Minv = np.linalg.inv(np.vstack([M, [0, 0, 1]]))[:2, :]
    coeffs = (Minv[0, 0], Minv[0, 1], Minv[0, 2], Minv[1, 0], Minv[1, 1], Minv[1, 2])
    return img.convert("RGB").transform(
        (image_size, image_size),
        Image.AFFINE,
        coeffs,
        resample=Image.BILINEAR,
        fillcolor=(0, 0, 0),
    )


def center_crop(img, center, out_size, scale, rotation=0):
    """InsightFace face_align.transform port (bbox-center based, used by genderage).

    Forward map: p -> s*R*p - s*R*center + (out_size/2, out_size/2).
    PIL wants the inverse map, same as align_face().
    """
    img = img.convert("RGB")
    r = float(rotation) * np.pi / 180.0
    R = np.array([[np.cos(r), -np.sin(r)], [np.sin(r), np.cos(r)]], dtype=np.float64)
    c = np.asarray(center, dtype=np.float64)
    lin = scale * R
    t = np.array([out_size / 2.0, out_size / 2.0]) - lin @ c
    M = np.vstack([np.hstack([lin, t.reshape(2, 1)]), [0, 0, 1]])
    Minv = np.linalg.inv(M)[:2, :]
    coeffs = (Minv[0, 0], Minv[0, 1], Minv[0, 2], Minv[1, 0], Minv[1, 1], Minv[1, 2])
    return img.transform(
        (out_size, out_size),
        Image.AFFINE,
        coeffs,
        resample=Image.BILINEAR,
        fillcolor=(0, 0, 0),
    )


def crop_scale(img, bbox, scale, out_size=80):
    """Crop bbox expanded by `scale` (upstream minivision _get_new_box semantics).

    The box is shifted/trimmed to stay inside the image — upstream never pads with
    black, and black borders push MiniFASNet off-distribution.
    bbox is [x1, y1, x2, y2]; upstream works with [x, y, w, h] so it is converted here.
    """
    img = img.convert("RGB")
    src_w, src_h = img.size
    x, y = float(bbox[0]), float(bbox[1])
    box_w, box_h = float(bbox[2]) - x, float(bbox[3]) - y
    if box_w <= 1 or box_h <= 1:
        raise ValueError("bbox too small")
    scale = min((src_h - 1) / box_h, min((src_w - 1) / box_w, scale))
    new_w, new_h = box_w * scale, box_h * scale
    cx, cy = box_w / 2 + x, box_h / 2 + y
    l, t = cx - new_w / 2, cy - new_h / 2
    r, b = cx + new_w / 2, cy + new_h / 2
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
        (out_size, out_size), Image.BILINEAR, box=(int(l), int(t), int(r), int(b))
    )
