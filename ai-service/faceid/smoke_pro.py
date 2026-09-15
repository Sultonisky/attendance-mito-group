import json
import urllib.error
import urllib.request
from pathlib import Path

B = "http://127.0.0.1:8090"
ROOT = Path(__file__).resolve().parent


def get(path):
    with urllib.request.urlopen(B + path, timeout=120) as r:
        raw = r.read()
    try:
        return r.status, json.loads(raw)
    except Exception:
        return r.status, raw[:120]


def post(path, data, ctype="application/octet-stream"):
    req = urllib.request.Request(
        B + path, data=data, method="POST", headers={"Content-Type": ctype}
    )
    try:
        with urllib.request.urlopen(req, timeout=180) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, e.read()[:150]


def delete(path):
    req = urllib.request.Request(B + path, method="DELETE")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, e.read()[:120]


print("GET /pro              ", get("/pro")[0], flush=True)
s, cfg = get("/api/pro/config")
print("GET /api/pro/config   ", s, cfg.get("thresholds"), "| enrolled", cfg.get("enrolled"), flush=True)

lena = (ROOT / "samples" / "lena.jpg").read_bytes()
t1_path = ROOT / "samples" / "t1.jpg"  # opsional: foto grup multi-wajah (gak di-commit, berisi orang asli)
t1 = t1_path.read_bytes() if t1_path.exists() else None

s, out = post("/api/pro/enroll?name=ApiTest", lena)
print("POST enroll           ", s, {k: out.get(k) for k in ("ok", "name", "enrolled", "faces_detected")}, flush=True)
new_id = out.get("id")

s, out = post("/api/pro/verify", lena)
if isinstance(out, bytes):
    print("POST verify (lena)     FAILED", s, out[:200], flush=True)
else:
    print("POST verify (lena)    ", s, out["timings_ms"], flush=True)
    for f in out["faces"]:
        print("   ", f["decision"]["status"], "|", f["decision"]["reason"], "| live", f["liveness"]["label"], f["liveness"]["live_prob"], "| q_ok", f["quality"]["ok"], flush=True)

if t1 is not None:
    s, out = post("/api/pro/verify", t1)
    if isinstance(out, bytes):
        print("POST verify (t1)       FAILED", s, out[:200], flush=True)
    else:
        print("POST verify (t1, multi):", s, "faces", len(out["faces"]), [f["decision"]["status"] for f in out["faces"]], flush=True)
else:
    print("POST verify (t1)       SKIP (samples/t1.jpg gak ada — taruh foto grup sendiri buat test multi-wajah)", flush=True)

s, faces = get("/api/pro/faces")
print("GET faces             ", s, [f["name"] for f in faces], "| thumb?", bool(faces and faces[0].get("thumb")), flush=True)

s, out = post("/api/pro/verify", b"")
print("POST verify (kosong)  ", s, "(expect 422)", flush=True)

print("DELETE bad id         ", delete("/api/pro/faces/nonexistent")[0], "(expect 404)", flush=True)
print("DELETE", new_id, "     ", delete("/api/pro/faces/" + new_id)[0], flush=True)
s, faces = get("/api/pro/faces")
print("GET faces after       ", s, len(faces), flush=True)