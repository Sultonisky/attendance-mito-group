"""SCRFD face detector with 5-point landmarks.

Uses AI-2A's ``ModelLoader`` and ``ModelAssetRegistry`` so the detector
does not own model-path resolution or session caching.

Detection results are returned in the **original image coordinate system**.
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
# SCRFD configuration
# ---------------------------------------------------------------------------
# These values are taken from the reference implementation for det_500m.
# They are documented here as initial/reference-derived values.
# Production threshold calibration is deferred.
_STRIDES = (8, 16, 32)
_NUM_ANCHORS = 2
_INPUT_SIZE = (640, 640)  # (width, height)
_CONFIDENCE_THRESHOLD = 0.5
_NMS_THRESHOLD = 0.4


@dataclass(frozen=True)
class FaceDetection:
    """A single face detection result in original-image coordinates."""

    bbox: tuple[float, float, float, float]
    """(x1, y1, x2, y2) in original image pixels."""

    landmarks: tuple[tuple[float, float], ...]
    """Five (x, y) points: left_eye, right_eye, nose, left_mouth, right_mouth."""

    confidence: float
    """Detection confidence in [0.0, 1.0]."""


@dataclass(frozen=True)
class DetectionResult:
    """Aggregated detection output."""

    faces: tuple[FaceDetection, ...]
    """Detected faces, sorted by descending bounding-box area."""

    padded_retry: bool = False
    """True if detection succeeded only after auto-padding the input."""


class SCRFDDetector:
    """Thin SCRFD-500M detector wrapper.

    Uses ``ModelLoader`` from AI-2A for ONNX session caching.
    Does **not** perform employee identification, embedding comparison,
    liveness, quality checks, or attendance decisions.
    """

    def __init__(
        self,
        registry: ModelAssetRegistry | None = None,
        loader: ModelLoader | None = None,
    ) -> None:
        self._registry = registry or create_default_registry()
        self._loader = loader or ModelLoader(self._registry)
        self._input_width, self._input_height = _INPUT_SIZE

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def detect(
        self,
        image: Image.Image,
        threshold: float = _CONFIDENCE_THRESHOLD,
        nms_thresh: float = _NMS_THRESHOLD,
        auto_pad: bool = True,
    ) -> DetectionResult:
        """Detect faces in ``image``.

        Args:
            image: PIL Image in RGB mode.
            threshold: Minimum detection confidence.
            nms_thresh: NMS IoU threshold.
            auto_pad: If True and no face is detected, retry with a 50 %
                black border around the image. Tight selfie crops often
                need this margin.

        Returns:
            ``DetectionResult`` with faces in original-image coordinates.

        Raises:
            ModelNotFound: SCRFD model file is missing.
            ModelInvalid: SCRFD model cannot be loaded.
            ModelUnavailable: ONNX runtime is unavailable.
            ValueError: ``image`` has zero width or height.
        """
        if image.width == 0 or image.height == 0:
            raise ValueError("Image must have non-zero width and height.")

        session = self._loader.load("scrfd")

        faces = self._detect_once(image, session, threshold, nms_thresh)
        padded_retry = False

        if not faces and auto_pad:
            padded_retry = True
            padded = self._auto_pad(image)
            faces = self._detect_once(padded, session, threshold, nms_thresh)
            for face in faces:
                face.bbox = tuple(round(v - padded._pad, 2) for v in face.bbox)  # type: ignore[attr-defined]
                face.landmarks = tuple(  # type: ignore[attr-defined]
                    (round(x - padded._pad, 2), round(y - padded._pad, 2))
                    for x, y in face.landmarks
                )

        return DetectionResult(
            faces=tuple(sorted(faces, key=self._area, reverse=True)),
            padded_retry=padded_retry,
        )

    # ------------------------------------------------------------------
    # Preprocessing
    # ------------------------------------------------------------------

    def _preprocess(self, img: Image.Image) -> tuple[np.ndarray, float]:
        """Letterbox-resize PIL RGB image to model input size.

        Returns:
            (blob, scale) where ``blob`` is NCHW float32 in RGB
            normalized to ``(x - 127.5) / 128.0``, and ``scale`` is the
            resize factor used to map model coordinates back to the
            original image.
        """
        iw, ih = self._input_width, self._input_height
        w, h = img.size
        im_ratio = h / w
        model_ratio = ih / iw

        if im_ratio > model_ratio:
            new_h = ih
            new_w = int(new_h / im_ratio)
        else:
            new_w = iw
            new_h = int(new_w * im_ratio)

        scale = new_h / h
        resized = img.resize((new_w, new_h), Image.BILINEAR)
        canvas = np.zeros((ih, iw, 3), dtype=np.uint8)
        canvas[:new_h, :new_w, :] = np.asarray(resized.convert("RGB"))

        blob = (canvas.astype(np.float32) - 127.5) / 128.0
        return blob.transpose(2, 0, 1)[None], scale

    @staticmethod
    def _auto_pad(img: Image.Image) -> "_PaddedImage":
        pad = int(max(img.size) * 0.5)
        canvas = Image.new("RGB", (img.width + 2 * pad, img.height + 2 * pad), (0, 0, 0))
        canvas.paste(img.convert("RGB"), (pad, pad))
        padded = _PaddedImage._from_canvas(canvas, pad)
        return padded

    # ------------------------------------------------------------------
    # Inference + decoding
    # ------------------------------------------------------------------

    def _detect_once(
        self,
        img: Image.Image,
        session,
        threshold: float,
        nms_thresh: float,
    ) -> list[FaceDetection]:
        """Run one inference pass and decode outputs."""
        blob, scale = self._preprocess(img)
        input_name = session.get_inputs()[0].name
        outputs = session.run(None, {input_name: blob})
        raw = {o.name: v for o, v in zip(session.get_outputs(), outputs)}

        ih, iw = self._input_height, self._input_width
        all_boxes: list[np.ndarray] = []
        all_kps: list[np.ndarray] = []
        all_scores: list[np.ndarray] = []

        for stride in _STRIDES:
            slot = self._build_slot_map(stride, raw, ih, iw)
            if not slot:
                continue

            scores = raw[slot[1]].reshape(-1)
            bbox_preds = raw[slot[4]].reshape(-1, 4) * stride
            kps_preds = raw[slot[10]].reshape(-1, 10) * stride

            hh, ww = ih // stride, iw // stride
            centers = np.stack(np.mgrid[:hh, :ww][::-1], axis=-1).astype(np.float32)
            centers = (centers * stride).reshape((-1, 2))
            centers = np.stack([centers] * _NUM_ANCHORS, axis=1).reshape((-1, 2))

            pos = np.where(scores >= threshold)[0]
            if pos.size == 0:
                continue

            boxes = _distance2bbox(centers, bbox_preds)[pos]
            kpss = _distance2kps(centers, kps_preds)[pos].reshape((-1, 5, 2))
            all_boxes.append(boxes)
            all_kps.append(kpss)
            all_scores.append(scores[pos])

        if not all_boxes:
            return []

        boxes = np.vstack(all_boxes)
        kpss = np.vstack(all_kps)
        scores = np.concatenate(all_scores)

        keep = _nms(np.hstack([boxes, scores[:, None]]), nms_thresh)
        boxes, kpss, scores = boxes[keep], kpss[keep], scores[keep]

        # Map back to original image coordinates.
        boxes = boxes / scale
        kpss = kpss / scale

        faces: list[FaceDetection] = []
        for b, k, s in zip(boxes, kpss, scores):
            landmarks = tuple(
                (round(float(x), 2), round(float(y), 2)) for x, y in k
            )
            faces.append(
                FaceDetection(
                    bbox=tuple(round(float(v), 2) for v in b),
                    landmarks=landmarks,
                    confidence=round(float(s), 4),
                )
            )
        return faces

    # ------------------------------------------------------------------
    # Helpers
    # ------------------------------------------------------------------

    @staticmethod
    def _build_slot_map(
        stride: int, raw: dict[str, np.ndarray], ih: int, iw: int
    ) -> dict[int, str] | None:
        """Map output channel counts to output names for a given stride."""
        expected_count = (ih // stride) * (iw // stride) * _NUM_ANCHORS
        slot: dict[int, str] = {}
        for name, tensor in raw.items():
            shape = tensor.shape
            if len(shape) == 2 and shape[0] == expected_count and shape[1] in (1, 4, 10):
                slot[int(shape[1])] = name
        if not all(k in slot for k in (1, 4, 10)):
            return None
        return slot

    @staticmethod
    def _area(face: FaceDetection) -> float:
        x1, y1, x2, y2 = face.bbox
        return max(0.0, x2 - x1) * max(0.0, y2 - y1)


class _PaddedImage:
    """Wrapper that tracks the padding offset applied to an image."""

    def __init__(self, canvas: Image.Image, pad: int) -> None:
        self._canvas = canvas
        self._pad = pad

    @property
    def width(self) -> int:
        return self._canvas.width

    @property
    def height(self) -> int:
        return self._canvas.height

    @property
    def size(self) -> tuple[int, int]:
        return self._canvas.size

    def convert(self, mode: str) -> Image.Image:
        return self._canvas.convert(mode)

    @classmethod
    def _from_canvas(cls, canvas: Image.Image, pad: int) -> "_PaddedImage":
        inst = cls.__new__(cls)
        inst._canvas = canvas
        inst._pad = pad
        return inst


# ---------------------------------------------------------------------------
# Low-level SCRFD helpers (kept module-private)
# ---------------------------------------------------------------------------

def _distance2bbox(points: np.ndarray, distance: np.ndarray) -> np.ndarray:
    x1 = points[:, 0] - distance[:, 0]
    y1 = points[:, 1] - distance[:, 1]
    x2 = points[:, 0] + distance[:, 2]
    y2 = points[:, 1] + distance[:, 3]
    return np.stack([x1, y1, x2, y2], axis=-1)


def _distance2kps(points: np.ndarray, distance: np.ndarray) -> np.ndarray:
    out: list[np.ndarray] = []
    for i in range(0, distance.shape[1], 2):
        out.append(points[:, 0] + distance[:, i])
        out.append(points[:, 1] + distance[:, i + 1])
    return np.stack(out, axis=-1)


def _nms(dets: np.ndarray, thresh: float = 0.4) -> list[int]:
    x1, y1, x2, y2, scores = (
        dets[:, 0],
        dets[:, 1],
        dets[:, 2],
        dets[:, 3],
        dets[:, 4],
    )
    areas = (x2 - x1) * (y2 - y1)
    order = scores.argsort()[::-1]
    keep: list[int] = []
    while order.size > 0:
        i = int(order[0])
        keep.append(i)
        xx1 = np.maximum(x1[i], x1[order[1:]])
        yy1 = np.maximum(y1[i], y1[order[1:]])
        xx2 = np.minimum(x2[i], x2[order[1:]])
        yy2 = np.minimum(y2[i], y2[order[1:]])
        w = np.maximum(0.0, xx2 - xx1)
        h = np.maximum(0.0, yy2 - yy1)
        inter = w * h
        iou = inter / (areas[i] + areas[order[1:]] - inter + 1e-9)
        order = order[1:][iou <= thresh]
    return keep
