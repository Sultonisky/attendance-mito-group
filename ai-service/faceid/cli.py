"""CLI engine MITO — enroll / verify / list / delete tanpa buka browser.

Contoh:
  python cli.py enroll "Budi" C:\\foto\\budi.jpg
  python cli.py verify C:\\foto\\tes.jpg
  python cli.py list
  python cli.py delete <id>

Exit code verify: 0 = ada wajah diterima, 1 = ditolak / gak ada wajah.
"""
import argparse
import sys
import time
from pathlib import Path

from mito.engine import MitoEngine

BASE = Path(__file__).resolve().parent
eng = MitoEngine(BASE / "models_pro", BASE / "pro_faces.json")


def show_face(i, f):
    d, q, lv, m = f["decision"], f["quality"], f["liveness"], f["match"]
    at = f.get("attributes") or {}
    w = round(f["bbox"][2] - f["bbox"][0])
    h = round(f["bbox"][3] - f["bbox"][1])
    print(f"  wajah #{i}: {d['status'].upper()} — {d['reason']}")
    print(f"    deteksi SCRFD : skor {f['score']} · {w}x{h} px")
    print(f"    landmark 5pt  : {'valid' if f['landmarks_valid'] else 'MENCURIGAKAN'}")
    print(f"    quality gate  : {'pass' if q['ok'] else ', '.join(q['reasons'])}")
    mt = q["metrics"]
    print(
        f"    metrik        : blur {mt['blur']} · bright {mt['brightness']} · "
        f"contrast {mt['contrast']} · yaw {mt['yaw']} · roll {mt['roll']}°"
    )
    if m:
        print(
            f"    ArcFace       : {m['name']} cos={m['cosine']} "
            f"{'>=' if m['ok'] else '<'} ambang {m['threshold']}"
        )
    else:
        print("    ArcFace       : DB kosong (belum ada yang didaftarkan)")
    print(f"    liveness      : {lv['label']} · P(real)={lv['live_prob']}")
    for p in lv["per_scale"]:
        print(f"      scale {p['scale']}x -> {p['label']} ({p['live_prob']})")
    if at:
        emo = at.get("emotion")
        print(
            f"    atribut       : {at.get('gender', '-')} · umur {at.get('age', '-')} · "
            f"emosi {emo or '-'}"
            + (f" ({at.get('score')})" if emo and at.get("score") is not None else "")
        )
    if d["warnings"]:
        print(f"    warnings      : {', '.join(d['warnings'])}")


def cmd_enroll(a):
    data = Path(a.path).read_bytes()
    t0 = time.perf_counter()
    out = eng.enroll(a.name, data)
    ms = (time.perf_counter() - t0) * 1000
    if not out["ok"]:
        print("ENROLL GAGAL:", out["error"])
        if out.get("check"):
            print("  liveness:", out["check"]["liveness"]["label"], out["check"]["liveness"]["live_prob"])
            print("  quality :", out["check"]["quality"]["reasons"])
        return 1
    lv, q = out["check"]["liveness"], out["check"]["quality"]
    print(f'ENROLL OK  id={out["id"]}  nama="{out["name"]}"')
    print(f'  wajah terdeteksi : {out["faces_detected"]} ({out["enrolled"]} total di DB)')
    print(f'  liveness         : {lv["label"]} P(real)={lv["live_prob"]}')
    print(f'  quality          : {"pass" if q["ok"] else ", ".join(q["reasons"])}')
    print(f"  waktu            : {ms:.0f} ms")
    return 0


def cmd_verify(a):
    data = Path(a.path).read_bytes()
    out = eng.verify(data)
    print(f"VERIFY {a.path}  ({out['image']['w']}x{out['image']['h']}, {out['enrolled']} terdaftar)")
    if not out["faces"]:
        print("  gak ada wajah kedeteksi")
        return 1
    for i, f in enumerate(out["faces"], 1):
        show_face(i, f)
    print(f"  total {out['timings_ms']['total']} ms")
    return 0 if any(f["decision"]["status"] == "accept" for f in out["faces"]) else 1


def cmd_list(a):
    rows = eng.faces()
    print(f"{len(rows)} wajah terdaftar")
    for r in rows:
        q = (r.get("quality") or {}).get("metrics") or {}
        at = r.get("attributes") or {}
        print(
            f"  {r['id']}  {r['name']:20s} blur {q.get('blur')} yaw {q.get('yaw')} "
            f"roll {q.get('roll')}"
            + (f"  [{at.get('gender', '-')} {at.get('age', '-')} · {at.get('emotion', '-')}]" if at else "")
        )
    return 0


def cmd_delete(a):
    try:
        eng.delete(a.id)
    except KeyError:
        print("id gak ketemu:", a.id)
        return 1
    print("dihapus:", a.id)
    return 0


p = argparse.ArgumentParser(description="CLI MITO face engine")
sub = p.add_subparsers(dest="cmd", required=True)
e = sub.add_parser("enroll", help="daftarkan wajah dari file gambar")
e.add_argument("name")
e.add_argument("path")
e.set_defaults(func=cmd_enroll)
v = sub.add_parser("verify", help="tes deteksi/kenali wajah dari file gambar")
v.add_argument("path")
v.set_defaults(func=cmd_verify)
sub.add_parser("list", help="lihat daftar wajah").set_defaults(func=cmd_list)
d = sub.add_parser("delete", help="hapus wajah")
d.add_argument("id")
d.set_defaults(func=cmd_delete)

args = p.parse_args()
sys.exit(args.func(args))