import json
import urllib.request

B = "http://127.0.0.1:8090"


def req(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(
        B + path, data=data, method=method, headers={"Content-Type": "application/json"}
    )
    with urllib.request.urlopen(r) as resp:
        return resp.status, json.loads(resp.read() or b"null")


print("healthz   ", req("GET", "/healthz"))
print("index     ", urllib.request.urlopen(B + "/").status)
print("app.js    ", urllib.request.urlopen(B + "/static/app.js").status)
print("faceapi   ", urllib.request.urlopen(B + "/static/face-api.min.js").status)
print(
    "model     ",
    urllib.request.urlopen(B + "/static/models/face_recognition_model-shard1").status,
)

s, out = req("POST", "/api/faces", {"name": "tes", "descriptor": [0.1] * 128})
print("POST      ", s, out)
s, lst = req("GET", "/api/faces")
print("LIST      ", s, len(lst))
s, d = req("DELETE", "/api/faces/" + out["id"])
print("DELETE    ", s, d)
s, lst = req("GET", "/api/faces")
print("LIST2     ", s, len(lst))

try:
    req("POST", "/api/faces", {"name": "bad", "descriptor": [0.1] * 12})
    print("BADLEN     FAIL (no error)")
except urllib.error.HTTPError as e:
    print("BADLEN    ", e.code)