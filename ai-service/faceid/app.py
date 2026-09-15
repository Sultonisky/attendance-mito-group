import json
import time
import uuid
from pathlib import Path

from fastapi import FastAPI, HTTPException
from fastapi.responses import FileResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field

from pro_api import router as pro_router

BASE = Path(__file__).resolve().parent
DB = BASE / "faces.json"

app = FastAPI(title="FaceID Lab")


@app.middleware("http")
async def no_store(request, call_next):
    resp = await call_next(request)
    resp.headers.setdefault("Cache-Control", "no-store")
    return resp


def _load() -> list[dict]:
    return json.loads(DB.read_text(encoding="utf-8")) if DB.exists() else []


def _save(db: list[dict]) -> None:
    DB.write_text(json.dumps(db, ensure_ascii=False, indent=1), encoding="utf-8")


class FaceIn(BaseModel):
    name: str = Field(min_length=1, max_length=40)
    descriptor: list[float] = Field(min_length=128, max_length=128)


@app.post("/api/faces", status_code=201)
def add_face(f: FaceIn):
    rec = {
        "id": uuid.uuid4().hex[:12],
        "name": f.name.strip(),
        "descriptor": f.descriptor,
        "created_at": round(time.time(), 3),
    }
    db = _load()
    db.append(rec)
    _save(db)
    return {"id": rec["id"], "name": rec["name"], "created_at": rec["created_at"]}


@app.get("/api/faces")
def list_faces():
    return _load()


@app.delete("/api/faces/{fid}")
def del_face(fid: str):
    db = _load()
    kept = [f for f in db if f["id"] != fid]
    if len(kept) == len(db):
        raise HTTPException(404, "id gak ketemu")
    _save(kept)
    return {"ok": True, "id": fid}


@app.get("/healthz")
def healthz():
    return {"ok": True}


@app.get("/")
def index():
    return FileResponse(BASE / "static" / "index.html")


app.include_router(pro_router)

app.mount("/static", StaticFiles(directory=BASE / "static"), name="static")
