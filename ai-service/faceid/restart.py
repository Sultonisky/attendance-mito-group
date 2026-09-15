"""Restart server FaceID: 8090 (http) + 8443 (https self-signed)."""
import ssl
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
LOGS = HERE / "logs"
LOGS.mkdir(exist_ok=True)
CERT = HERE / "certs" / "server.pem"
KEY = HERE / "certs" / "key.pem"

SERVERS = [(8090, [])]
if CERT.exists() and KEY.exists():
    SERVERS.append((8443, ["--ssl-certfile", str(CERT), "--ssl-keyfile", str(KEY)]))

listed = subprocess.run(
    [
        "powershell", "-NoProfile", "-Command",
        ";".join(
            f"(Get-NetTCPConnection -LocalPort {p} -State Listen -ErrorAction SilentlyContinue).OwningProcess"
            for p, _ in SERVERS
        ),
    ],
    capture_output=True, text=True,
)
killed = []
for pid in set(listed.stdout.split()):
    if pid.isdigit():
        subprocess.run(["taskkill", "/F", "/PID", pid], capture_output=True)
        killed.append(pid)
print("killed:", killed or "none", flush=True)

time.sleep(1.2)
for port, extra in SERVERS:
    p = subprocess.Popen(
        [sys.executable, "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", str(port), *extra],
        cwd=HERE,
        stdout=open(LOGS / f"uv{port}_out.txt", "wb"),
        stderr=open(LOGS / f"uv{port}_err.txt", "wb"),
    )
    print("started uvicorn pid", p.pid, f"port {port}{' (https)' if extra else ''}", flush=True)

time.sleep(6)
ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE
for port, _ in SERVERS:
    scheme = "https" if port == 8443 else "http"
    try:
        with urllib.request.urlopen(f"{scheme}://127.0.0.1:{port}/healthz", context=ctx, timeout=5) as r:
            print(f"port {port}: healthz {r.status}", flush=True)
    except Exception as e:
        print(f"port {port}: GAGAL {e}", flush=True)