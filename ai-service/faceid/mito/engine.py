"""MITO decision engine: SCRFD -> 5pt landmarks -> ArcFace -> cosine -> MiniFASNetV2 -> decision."""
import base64
import io
import json
import time
import uuid
from pathlib import Path

import numpy as np
from PIL import Image, ImageOps

from .arcface import ArcFace
from .attributes import Emotion, GenderAge
from .liveness import Liveness
from .quality import assess
from .scrfd import SCRFD

DEFAULTS = {
    "det_score": 0.5,
    "nms": 0.4,
    "match_cosine": 0.35,
    "liveness_live": 0.5,
    "liveness_mode": "warn",   # off | warn | enforce
    "quality_mode": "warn",    # off | warn | enforce
    "attrs": True,             # gender + umur + emosi (genderage + FER+)
}


def load_image(data: bytes) -> Image.Image:
    """Decode upload bytes and apply EXIF orientation (phone cameras need this)."""
    img = Image.open(io.BytesIO(data))
    return ImageOps.exif_transpose(img).convert("RGB")


def _png_b64(img) -> str:
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return base64.b64encode(buf.getvalue()).decode("ascii")


def _thumb_b64(img, bbox, size=96) -> str:
    x1, y1, x2, y2 = [int(v) for v in bbox]
    crop = img.crop((max(0, x1), max(0, y1), min(img.width, x2), min(img.height, y2)))
    buf = io.BytesIO()
    crop.resize((size, size), Image.BILINEAR).save(buf, format="JPEG", quality=70)
    return base64.b64encode(buf.getvalue()).decode("ascii")


def _brighten_if_dark(img):
    """Gamma-boost frame gelap sebelum deteksi. Ukuran gak berubah -> koordinat bbox tetap 1:1."""
    m = np.asarray(img.convert("L"), dtype=np.float32).mean()
    if m >= 55.0:
        return img, False
    lut = [int(round(255.0 * ((i / 255.0) ** 0.45))) for i in range(256)] * 3
    return img.point(lut), True


class MitoEngine:
    def __init__(self, models_dir, db_path, thresholds=None):
        models_dir = Path(models_dir)
        self.det = SCRFD(models_dir / "det_500m.onnx")
        self.rec = ArcFace(models_dir / "w600k_mbf.onnx")
        self.liv = Liveness(models_dir / "minifasnet_v2.onnx")
        self.attr = None
        self.emo = None
        try:
            self.attr = GenderAge(models_dir / "genderage.onnx")
        except Exception:
            pass
        try:
            self.emo = Emotion(models_dir / "emotion_ferplus.onnx")
        except Exception:
            pass
        self.db_path = Path(db_path)
        self.th = {**DEFAULTS, **(thresholds or {})}

    # ---------- storage ----------
    def _load(self):
        return json.loads(self.db_path.read_text("utf-8")) if self.db_path.exists() else []

    def _save(self, rows):
        self.db_path.write_text(json.dumps(rows, ensure_ascii=False), encoding="utf-8")

    def faces(self):
        return [
            {
                "id": r["id"],
                "name": r["name"],
                "samples": len(r.get("embeddings") or [r.get("embedding")]),
                "created_at": r["created_at"],
                "thumb": r.get("thumb"),
                "quality": r.get("quality"),
                "liveness": r.get("liveness"),
                "attributes": r.get("attributes", {}),
            }
            for r in self._load()
        ]

    def delete(self, fid):
        rows = self._load()
        kept = [r for r in rows if r["id"] != fid]
        if len(kept) == len(rows):
            raise KeyError(fid)
        self._save(kept)
        return True

    # ---------- pipeline ----------
    def _attrs(self, img, bbox):
        """Gender/umur/emosi — tiap model optional, error jadi {} biar gak ngerusak pipeline."""
        out = {}
        if not self.th.get("attrs", True):
            return out
        if self.attr is not None:
            try:
                g, a = self.attr.predict(img, bbox)
                out["gender"], out["age"] = g, a
            except Exception:
                pass
        if self.emo is not None:
            try:
                out.update(self.emo.predict(img, bbox))
            except Exception:
                pass
        return out

    def _analyze(self, img, face, with_thumb=False):
        q = assess(img, face["bbox"], face["kps"])
        aligned, vec = self.rec.embed(img, face["kps"])
        lv = self.liv.predict_ensemble(img, face["bbox"])
        res = {
            "bbox": face["bbox"],
            "score": face["score"],
            "kps": face["kps"],
            "landmarks_valid": _landmarks_valid(face["kps"], face["bbox"]),
            "quality": q,
            "liveness": lv,
            "attributes": self._attrs(img, face["bbox"]),
            "aligned": _png_b64(aligned),
        }
        if with_thumb:
            res["thumb"] = _thumb_b64(img, face["bbox"])
        return res, vec

    def verify(self, data: bytes):
        t0 = time.perf_counter()
        img, brightened = _brighten_if_dark(load_image(data))
        faces = self.det.detect(img, self.th["det_score"], self.th["nms"])
        db = self._load()
        results = []
        for face in faces:
            res, vec = self._analyze(img, face)
            best, best_cos = None, -2.0
            for row in db:
                embs = row.get("embeddings") or [row["embedding"]]
                cos = max(
                    ArcFace.cosine(vec, np.asarray(e, dtype=np.float32)) for e in embs
                )
                if cos > best_cos:
                    best, best_cos = row, cos
            res["match"] = (
                {
                    "id": best["id"],
                    "name": best["name"],
                    "cosine": round(best_cos, 4),
                    "threshold": self.th["match_cosine"],
                    "ok": best_cos >= self.th["match_cosine"],
                }
                if best
                else None
            )
            res["decision"] = self._decide(res)
            results.append(res)
        return {
            "engine": "MITO (SCRFD-500M + ArcFace w600k_mbf + MiniFASNetV2 + rule-based quality + genderage + FER+)",
            "image": {"w": img.width, "h": img.height, "auto_brighten": brightened},
            "faces": results,
            "enrolled": len(db),
            "thresholds": self.th,
            "timings_ms": {"total": round((time.perf_counter() - t0) * 1000, 1)},
        }

    def track(self, data: bytes):
        """Deteksi cepat (SCRFD + auto-brighten) buat live tracking.

        Tanpa ArcFace/liveness/atribut/quality — ~10x lebih cepat dari verify(),
        buat ngikutin posisi wajah tiap frame. Identitas di-merge di client
        dari hasil verify lengkap terakhir.
        """
        t0 = time.perf_counter()
        img = load_image(data)
        img, boosted = _brighten_if_dark(img)
        faces = self.det.detect(img, self.th["det_score"], self.th["nms"])
        return {
            "image": {"w": img.width, "h": img.height, "auto_brighten": boosted},
            "faces": [{"bbox": f["bbox"], "score": f["score"], "kps": f["kps"]} for f in faces],
            "timings_ms": {"track": round((time.perf_counter() - t0) * 1000, 1)},
        }

    def _decide(self, res):
        q, lv, m = res["quality"], res["liveness"], res["match"]
        warnings, errors = [], []
        if not res.get("landmarks_valid", True):
            warnings.append("landmarks_unreliable")
        if not q["ok"]:
            msg = "quality:" + ",".join(q["reasons"])
            (errors if self.th["quality_mode"] == "enforce" else warnings).append(msg)
        if lv["live_prob"] < self.th["liveness_live"]:
            msg = "liveness:%s(%s)" % (lv["label"], lv["live_prob"])
            (errors if self.th["liveness_mode"] == "enforce" else warnings).append(msg)
        if m and m["ok"]:
            status, reason = "accept", "match:%s cos=%s" % (m["name"], m["cosine"])
        else:
            status = "reject"
            reason = "unknown_face" if not m else "below_threshold cos=%s" % m["cosine"]
            if errors:
                reason = errors[0]
        return {"status": status, "reason": reason, "warnings": warnings, "errors": errors}

    # ---------- enroll ----------
    def enroll(self, name, data: bytes):
        img = load_image(data)
        faces = self.det.detect(img, self.th["det_score"], self.th["nms"])
        if not faces:
            return {"ok": False, "error": "no_face_detected", "enrolled": len(self._load())}
        res, vec = self._analyze(img, faces[0], with_thumb=True)
        check = {"quality": res["quality"], "liveness": res["liveness"], "bbox": res["bbox"]}
        if self.th["quality_mode"] == "enforce" and not res["quality"]["ok"]:
            return {"ok": False, "error": "quality:" + ",".join(res["quality"]["reasons"]), "check": check}
        if self.th["liveness_mode"] == "enforce" and res["liveness"]["live_prob"] < self.th["liveness_live"]:
            return {"ok": False, "error": "liveness:" + res["liveness"]["label"], "check": check}
        name_c = name.strip().lower()
        db = self._load()
        existing = next((r for r in db if r["name"].lower() == name_c), None)
        now = round(time.time(), 3)
        if existing is not None:
            # multi-sampel: enroll nama sama nambah embedding (maks 5) ke entri yang sama
            embs = existing.setdefault("embeddings", [existing["embedding"]])
            existing.setdefault("embedding", embs[0])
            if len(embs) < 5:
                embs.append([round(float(v), 5) for v in vec])
            existing.update({
                "quality": res["quality"],
                "liveness": res["liveness"],
                "attributes": res.get("attributes", {}),
                "thumb": res["thumb"],
                "updated_at": now,
            })
            self._save(db)
            return {
                "ok": True,
                "id": existing["id"],
                "name": existing["name"],
                "merged": True,
                "samples": len(embs),
                "check": check,
                "enrolled": len(db),
                "faces_detected": len(faces),
            }
        row = {
            "id": uuid.uuid4().hex[:12],
            "name": name.strip(),
            "embedding": [round(float(v), 5) for v in vec],
            "embeddings": [[round(float(v), 5) for v in vec]],
            "quality": res["quality"],
            "liveness": res["liveness"],
            "attributes": res.get("attributes", {}),
            "thumb": res["thumb"],
            "created_at": now,
        }
        db.append(row)
        self._save(db)
        return {
            "ok": True,
            "id": row["id"],
            "name": row["name"],
            "samples": 1,
            "check": check,
            "enrolled": len(db),
            "faces_detected": len(faces),
        }


def _landmarks_valid(kps, bbox):
    """Cheap sanity check on the 5 landmarks (catches garbled detections).

    Returns a plain Python bool — numpy.bool_ breaks FastAPI's jsonable_encoder.
    """
    le, re, nose, l_mouth, r_mouth = [np.asarray(p, dtype=np.float64) for p in kps]
    if le[1] > nose[1] or re[1] > nose[1]:
        return False
    if l_mouth[1] < nose[1] or r_mouth[1] < nose[1]:
        return False
    w = float(bbox[2]) - float(bbox[0])
    return bool(abs(float(le[0]) - float(re[0])) > 0.15 * w)
