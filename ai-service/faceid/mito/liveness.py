"""MiniFASNetV2 (2.7_80x80) liveness / anti-spoofing.

Verified against the ONNX export and the upstream minivision source:
  * input must be **BGR, raw 0-255**. Upstream `src/data_io/functional.py:to_tensor`
    returns `img.float()` with the `/255` division commented out
    ("# return img.float().div(255)  #modified by zkx"), and the ONNX graph
    contains no Div node nor any 255.0 constant (byte-scan verified). Feeding
    /255 instead saturates the logits on every image. The HF README's claim
    "range [0.0, 1.0] (i.e. pixel / 255)" is wrong for this file.
  * the model emits 3 raw logits in the order (print-attack, real, replay-attack)
    — upstream test.py: `if label == 1: Real Face`. The HF README's
    "[live, print, replay]" ordering is wrong.
  * crop uses the upstream _get_new_box semantics (shift the box to stay inside
    the image, never pad with black).
"""
import numpy as np
import onnxruntime as ort

from .align import crop_scale

CLASSES = ("print", "real", "replay")
LIVE_INDEX = CLASSES.index("real")


def _softmax(x):
    e = np.exp(x - np.max(x))
    return e / e.sum()


class Liveness:
    def __init__(self, model_path, providers=None):
        self.session = ort.InferenceSession(
            str(model_path), providers=providers or ["CPUExecutionProvider"]
        )
        self.input_name = self.session.get_inputs()[0].name
        self.output_name = self.session.get_outputs()[0].name
        self.live_idx = LIVE_INDEX

    def predict(self, img, bbox, scale=2.7):
        patch = crop_scale(img, bbox, scale, 80)
        arr = np.asarray(patch.convert("RGB"), dtype=np.float32)
        bgr = np.ascontiguousarray(arr[:, :, ::-1])  # raw 0-255 BGR
        blob = bgr.transpose(2, 0, 1)[None]
        logits = np.asarray(
            self.session.run([self.output_name], {self.input_name: blob})[0], dtype=np.float32
        ).reshape(-1)
        probs = _softmax(logits)
        idx = int(np.argmax(probs))
        return {
            "label": CLASSES[idx],
            "live_prob": round(float(probs[self.live_idx]), 4),
            "probs": {c: round(float(p), 4) for c, p in zip(CLASSES, probs)},
            "logits": [round(float(v), 3) for v in logits],
        }
    def predict_ensemble(self, img, bbox, scales=(2.7, 4.0)):
        """Average class probabilities over several crop scales.

        Upstream ensembles two separate weights (2.7 + 4.0) and sums their outputs.
        Only the 2.7 ONNX is available here, so the same weights are run at both
        crop scales and averaged — measured improvement on t1.jpg face #0
        (P(real) 0.416 -> 0.939) with no regression on the other samples.
        """
        per = [self.predict(img, bbox, s) for s in scales]
        stack = np.array([[p["probs"][c] for c in CLASSES] for p in per], dtype=np.float64)
        probs = stack.mean(axis=0)
        idx = int(np.argmax(probs))
        return {
            "label": CLASSES[idx],
            "live_prob": round(float(probs[self.live_idx]), 4),
            "probs": {c: round(float(p), 4) for c, p in zip(CLASSES, probs)},
            "per_scale": [
                {"scale": s, "label": p["label"], "live_prob": p["live_prob"]}
                for s, p in zip(scales, per)
            ],
        }
