"""Attribute heads: GenderAge (InsightFace buffalo) + Emotion FER+ (ONNX zoo).

Preprocessing diverifikasi (bukan dari dokumentasi pihak ketiga):
- genderage.onnx: crop 96x96 bbox-center, scale = 96/(max(w,h)*1.5) — port verbatim
  dari insightface/model_zoo/attribute.py + utils/face_align.py (sdist 0.7.3).
  Input RGB RAW 0-255 TANPA mean/std (varian mxnet bn_data). Diverifikasi empiris:
  varian (127.5,128) gak bisa bedain gender, varian (0,1) bisa.
  Decode: gender = argmax(pred[:2]) -> 0=female, 1=male (lena->0, einstein->1).
  age = round(pred[2] * 100).
- emotion-ferplus-8.onnx: crop 64x64 grayscale, softmax 8 kelas. Normalisasi
  divalidasi pake test_data_set resmi ONNX zoo (lihat FER_DIV_255 di bawah).
"""
import numpy as np
import onnxruntime as ort

from .align import center_crop
from .scrfd import _sess_opts

FER_DIV_255 = False  # ground truth ONNX zoo test_data_set: raw 0-255 grayscale (L2 1.17 vs /255 L2 2.97)

EMOTION_ID = {
    0: "netral",
    1: "senang",
    2: "kaget",
    3: "sedih",
    4: "marah",
    5: "jijik",
    6: "takut",
    7: "menghina",
}


class GenderAge:
    def __init__(self, path):
        self.session = ort.InferenceSession(
            str(path), providers=["CPUExecutionProvider"], sess_options=_sess_opts()
        )
        self.input_name = self.session.get_inputs()[0].name
        self.size = int(self.session.get_inputs()[0].shape[2])  # 96

    def predict(self, img, bbox):
        """img PIL RGB, bbox [x1,y1,x2,y2] -> (gender, age)."""
        w, h = bbox[2] - bbox[0], bbox[3] - bbox[1]
        if w < 4 or h < 4:
            raise ValueError("bbox terlalu kecil buat attribute")
        center = ((bbox[2] + bbox[0]) / 2.0, (bbox[3] + bbox[1]) / 2.0)
        scale = self.size / (max(w, h) * 1.5)
        crop = center_crop(img, center, self.size, scale, 0)
        x = np.asarray(crop, dtype=np.float32).transpose(2, 0, 1)[None]  # RGB raw
        pred = self.session.run(None, {self.input_name: x})[0][0]
        gender = "female" if int(np.argmax(pred[:2])) == 0 else "male"
        age = int(round(float(pred[2]) * 100))
        return gender, age


class Emotion:
    def __init__(self, path):
        self.session = ort.InferenceSession(
            str(path), providers=["CPUExecutionProvider"], sess_options=_sess_opts()
        )
        self.input_name = self.session.get_inputs()[0].name

    def predict(self, img, bbox):
        """img PIL RGB, bbox [x1,y1,x2,y2] -> dict emosi (top-3 + label utama)."""
        w, h = bbox[2] - bbox[0], bbox[3] - bbox[1]
        if w < 4 or h < 4:
            raise ValueError("bbox terlalu kecil buat attribute")
        center = ((bbox[2] + bbox[0]) / 2.0, (bbox[3] + bbox[1]) / 2.0)
        scale = 64 / (max(w, h) * 1.3)  # ekspresi butuh area sekitar mulut/alis
        gray = center_crop(img, center, 64, scale, 0).convert("L")
        a = np.asarray(gray, dtype=np.float32)
        if FER_DIV_255:
            a = a / 255.0
        logits = self.session.run(None, {self.input_name: a[None, None]})[0][0]
        e = np.exp(logits - logits.max())
        p = e / e.sum()
        order = np.argsort(p)[::-1][:3]
        return {
            "emotion": EMOTION_ID[int(order[0])],
            "score": round(float(p[order[0]]), 3),
            "top": [[EMOTION_ID[int(i)], round(float(p[i]), 3)] for i in order],
        }
