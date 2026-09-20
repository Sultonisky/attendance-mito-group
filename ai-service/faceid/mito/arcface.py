"""ArcFace recognizer (InsightFace w600k_mbf / w600k_r50), 512-D embeddings + cosine."""
import numpy as np
import onnxruntime as ort

from .align import align_face


class ArcFace:
    def __init__(self, model_path, providers=None):
        self.session = ort.InferenceSession(
            str(model_path), providers=providers or ["CPUExecutionProvider"]
        )
        self.input_name = self.session.get_inputs()[0].name
        self.output_name = self.session.get_outputs()[0].name

    @staticmethod
    def _blob(aligned_rgb):
        """aligned PIL RGB 112x112 -> NCHW float32, RGB, (x-127.5)/128 (insightface)."""
        arr = np.asarray(aligned_rgb.convert("RGB"), dtype=np.float32)
        blob = (arr - 127.5) / 128.0
        return blob.transpose(2, 0, 1)[None]

    def embed(self, img, kps):
        aligned = align_face(img, kps, 112)
        out = self.session.run([self.output_name], {self.input_name: self._blob(aligned)})[0]
        vec = np.asarray(out, dtype=np.float32).reshape(-1)
        return aligned, vec

    @staticmethod
    def cosine(a, b):
        a = np.asarray(a, dtype=np.float32).reshape(-1)
        b = np.asarray(b, dtype=np.float32).reshape(-1)
        na, nb = np.linalg.norm(a), np.linalg.norm(b)
        if na == 0 or nb == 0:
            return 0.0
        return float(np.dot(a, b) / (na * nb))
