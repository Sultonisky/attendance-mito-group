"""MITO engine HTTP API — server-side ONNX inference, mounted next to the JS playground.

Image uploads are sent as RAW BYTES (application/octet-stream) instead of multipart
so the endpoint needs no extra dependency. Name goes in the query string.
"""
import json
import time
import uuid
from pathlib import Path

from fastapi import APIRouter, HTTPException, Query, Request
from fastapi.responses import FileResponse

from mito.engine import MitoEngine

BASE = Path(__file__).resolve().parent
router = APIRouter()
_engine: MitoEngine | None = None


def get_engine() -> MitoEngine:
    """Lazy singleton — ORT sessions are built on first request, not at import."""
    global _engine
    if _engine is None:
        _engine = MitoEngine(BASE / "models_pro", BASE / "pro_faces.json")
    return _engine


@router.get("/pro")
def pro_page():
    return FileResponse(BASE / "static" / "pro.html")


@router.get("/api/pro/config")
def pro_config():
    eng = get_engine()
    return {
        "engine": "SCRFD-500M + ArcFace w600k_mbf + MiniFASNetV2 + rule-based quality + genderage + FER+",
        "thresholds": eng.th,
        "enrolled": len(eng.faces()),
        "attributes": {"genderage": eng.attr is not None, "emotion": eng.emo is not None},
    }


@router.post("/api/pro/verify")
async def pro_verify(request: Request):
    data = await request.body()
    if not data:
        raise HTTPException(422, "body kosong - kirim bytes gambar")
    return get_engine().verify(data)


@router.post("/api/pro/track")
async def pro_track(request: Request):
    """Deteksi cepat (SCRFD doang) — buat live tracking halus di UI."""
    data = await request.body()
    if not data:
        raise HTTPException(422, "body kosong - kirim bytes gambar")
    return get_engine().track(data)


@router.post("/api/pro/enroll")
async def pro_enroll(request: Request, name: str = Query(..., min_length=1, max_length=40)):
    data = await request.body()
    if not data:
        raise HTTPException(422, "body kosong - kirim bytes gambar")
    return get_engine().enroll(name, data)


@router.get("/api/pro/faces")
def pro_faces():
    return get_engine().faces()


@router.delete("/api/pro/faces/{fid}")
def pro_delete(fid: str):
    try:
        get_engine().delete(fid)
    except KeyError:
        raise HTTPException(404, "id gak ketemu")
    return {"ok": True, "id": fid}


# ---------- absensi karyawan ----------
ATT = BASE / "attendance.json"
COOLDOWN_S = 60.0  # jeda antar-catat per orang (anti-spam tiap frame live)

import re


def _device_from_ua(ua: str) -> str:
    """Parse tipe device dari User-Agent (Android model, iPhone, dll)."""
    if not ua:
        return "unknown"
    m = re.search(r"Android[^;]*;\s*([^;)]+?)\s*(?:Build/|\))", ua)
    if m:
        return "Android " + m.group(1).strip()
    if "iPhone" in ua:
        return "iPhone"
    if "iPad" in ua:
        return "iPad"
    if "Windows" in ua:
        return "Windows PC"
    if "Macintosh" in ua:
        return "Mac"
    if "Linux" in ua:
        return "Linux PC"
    return ua[:40]


def _att_load():
    return json.loads(ATT.read_text("utf-8")) if ATT.exists() else []


def _att_save(rows):
    ATT.write_text(json.dumps(rows, ensure_ascii=False), encoding="utf-8")


@router.post("/api/pro/attendance")
async def pro_attendance(
    request: Request,
    lat: float = Query(None),
    lon: float = Query(None),
    acc: float = Query(None),
):
    """Verify + auto-catat absen (sekali per COOLDOWN_S per orang) beserta GPS."""
    data = await request.body()
    if not data:
        raise HTTPException(422, "body kosong - kirim bytes gambar")
    out = get_engine().verify(data)
    best = None
    for f in out["faces"]:
        if f["decision"]["status"] == "accept" and (best is None or f["match"]["cosine"] > best["match"]["cosine"]):
            best = f
    now = time.time()
    ua = request.headers.get("user-agent", "")
    device = _device_from_ua(ua)
    key = best["match"]["id"] if best else "__unknown__"
    rows = _att_load()
    last = next((r for r in rows if r.get("key") == key), None)
    cooldown = bool(last and (now - last["ts"]) < COOLDOWN_S)
    rec = None
    if not cooldown:
        ref = best or (out["faces"][0] if out["faces"] else None)
        rec = {
            "id": uuid.uuid4().hex[:10],
            "ts": round(now, 3),
            "key": key,
            "name": best["match"]["name"] if best else "(tidak dikenal)",
            "status": "hadir" if best else ("unknown" if ref else "no_face"),
            "cosine": best["match"]["cosine"] if best else None,
            "liveness": ref["liveness"]["live_prob"] if ref else None,
            "reason": ref["decision"]["reason"] if ref else "no_face",
            "gps": {"lat": lat, "lon": lon, "acc": acc} if lat is not None else None,
            "thumb": ref["aligned"] if ref else None,
            "attrs": ref.get("attributes", {}) if ref else {},
            "device": device,
        }
        rows.append(rec)
        _att_save(rows)
    return {"verify": out, "logged": rec is not None, "record": rec, "cooldown": cooldown}


@router.get("/api/pro/attendance")
def pro_attendance_list(limit: int = Query(30, ge=1, le=200)):
    return _att_load()[-limit:][::-1]


@router.delete("/api/pro/attendance")
def pro_attendance_clear():
    _att_save([])
    return {"ok": True}
