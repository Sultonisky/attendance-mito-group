"""SCRFD detector (InsightFace det_500m / det_10g) with 5-point keypoints.

Decoding is insightface-compatible: anchor grid + distance2bbox/distance2kps + NMS.
"""
from pathlib import Path

import numpy as np
import onnxruntime as ort
from PIL import Image

STRIDES = (8, 16, 32)
NUM_ANCHORS = 2


def _distance2bbox(points, distance):
    x1 = points[:, 0] - distance[:, 0]
    y1 = points[:, 1] - distance[:, 1]
    x2 = points[:, 0] + distance[:, 2]
    y2 = points[:, 1] + distance[:, 3]
    return np.stack([x1, y1, x2, y2], axis=-1)


def _distance2kps(points, distance):
    out = []
    for i in range(0, distance.shape[1], 2):
        out.append(points[:, 0] + distance[:, i])
        out.append(points[:, 1] + distance[:, i + 1])
    return np.stack(out, axis=-1)


def _nms(dets, thresh=0.4):
    x1, y1, x2, y2, scores = dets[:, 0], dets[:, 1], dets[:, 2], dets[:, 3], dets[:, 4]
    areas = (x2 - x1) * (y2 - y1)
    order = scores.argsort()[::-1]
    keep = []
    while order.size > 0:
        i = order[0]
        keep.append(int(i))
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


class SCRFD:
    def __init__(self, model_path, input_size=(640, 640), providers=None):
        self.session = ort.InferenceSession(
            str(model_path),
            providers=providers or ["CPUExecutionProvider"],
            sess_options=_sess_opts(),
        )
        self.input_name = self.session.get_inputs()[0].name
        self.input_size = input_size
        # classify outputs by anchor count + last dim (robust to output ordering)
        h, w = input_size[1], input_size[0]
        self._slots = {}
        outs = self.session.get_outputs()
        for stride in STRIDES:
            n = (h // stride) * (w // stride) * NUM_ANCHORS
            slot = {}
            for o in outs:
                shape = o.shape
                if len(shape) == 2 and shape[0] == n and shape[1] in (1, 4, 10):
                    slot[int(shape[1])] = o.name
            self._slots[stride] = slot

    def _blob(self, img):
        """img: PIL RGB -> letterboxed NCHW float32, RGB, (x-127.5)/128."""
        iw, ih = self.input_size
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

    def detect(self, img, threshold=0.5, nms_thresh=0.4, auto_pad=True):
        """img: PIL RGB -> list of {bbox:[x1,y1,x2,y2], kps:5x2, score}.

        auto_pad: kalau 0 wajah ketemu, coba sekali lagi pakai border hitam 50%.
        Detector butuh margin di sekeliling wajah; selfie crop tight (muka ngisi
        seluruh frame) bakal kelewat kalau nggak dipad. Terbukti empiris:
        tom.png 112x112 -> 0 wajah as-is, score 0.828 setelah pad 50%.
        """
        faces = self._detect_once(img, threshold, nms_thresh)
        if faces or not auto_pad:
            return faces
        pad = int(max(img.size) * 0.5)
        canvas = Image.new("RGB", (img.width + 2 * pad, img.height + 2 * pad), (0, 0, 0))
        canvas.paste(img.convert("RGB"), (pad, pad))
        faces = self._detect_once(canvas, threshold, nms_thresh)
        for f in faces:
            f["bbox"] = [round(v - pad, 2) for v in f["bbox"]]
            f["kps"] = [[round(x - pad, 2), round(y - pad, 2)] for x, y in f["kps"]]
            f["padded_retry"] = True
        return faces

    def _detect_once(self, img, threshold=0.5, nms_thresh=0.4):
        blob, scale = self._blob(img)
        outs = self.session.run(None, {self.input_name: blob})
        names = [o.name for o in self.session.get_outputs()]
        raw = dict(zip(names, outs))

        ih, iw = self.input_size[1], self.input_size[0]
        all_boxes, all_kps, all_scores = [], [], []
        for stride in STRIDES:
            slot = self._slots[stride]
            if not all(k in slot for k in (1, 4, 10)):
                continue
            scores = raw[slot[1]].reshape(-1)
            bbox_preds = raw[slot[4]].reshape(-1, 4) * stride
            kps_preds = raw[slot[10]].reshape(-1, 10) * stride
            hh, ww = ih // stride, iw // stride
            centers = np.stack(np.mgrid[:hh, :ww][::-1], axis=-1).astype(np.float32)
            centers = (centers * stride).reshape((-1, 2))
            centers = np.stack([centers] * NUM_ANCHORS, axis=1).reshape((-1, 2))
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
        boxes /= scale
        kpss /= scale
        out = []
        for b, k, s in zip(boxes, kpss, scores):
            out.append(
                {
                    "bbox": [round(float(v), 2) for v in b],
                    "kps": [[round(float(x), 2), round(float(y), 2)] for x, y in k],
                    "score": round(float(s), 4),
                }
            )
        out.sort(key=lambda d: -(d["bbox"][2] - d["bbox"][0]) * (d["bbox"][3] - d["bbox"][1]))
        return out


def _sess_opts():
    so = ort.SessionOptions()
    so.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
    return so
