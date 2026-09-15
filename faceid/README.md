# Face ID Lab

Eksperimen AI face recognition dari browser (HP / laptop). **Folder sendiri, port sendiri** — buat eksperimen & prototyping absensi.

- ML: `face-api.js@0.22.2` (TensorFlow.js) — semua deteksi + matching jalan **di browser**, server cuma nyimpen data.
- Backend: FastAPI mini (`app.py`) — simpan/daftar/hapus wajah ke `faces.json`.
- Enroll di laptop → otomatis kebaca di HP (satu database, sync via API).

## Jalanin

```powershell
cd C:\Users\Mbul\Desktop\attendance-mito-group\faceid
python -m uvicorn app:app --host 0.0.0.0 --port 8090
```

Atau klik `run.bat` (port **8090**).

## Akses

| Dari | URL | Kamera live |
|---|---|---|
| Laptop (localhost) | http://localhost:8090 | ✅ |
| HP via Tailscale | https://win-ro2uqq0r3fn.tail180d46.ts.net/ | ✅ (HTTPS = secure context) |
| HP same wifi | http://192.168.3.127:8090 | ❌ http gak aman → pakai upload foto |

Tailscale serve-nya udah dinyalain (tailnet only, gak publik):

```powershell
tailscale serve --bg 8090            # nyalain
tailscale serve status               # cek
tailscale serve --https=443 off      # matiin
```

Browser cuma kasih `getUserMedia` di secure context (HTTPS / localhost). Kalau dari HP lewat http biasa, tombol `🖼 Upload Foto` tetap jalan (kamera HP via file input).

## Pakai

1. Tab **➕ Daftar Wajah** → nyalakan kamera / upload foto → isi nama → **Simpan**.
2. Tab **📷 Kenali** → **Kenali ▶** atau centang **live scan**.
3. Centang **umur/gender/ekspresi** buat deteksi bonus (age-gender + expression net).

Matching pakai jarak euclidean 128-D descriptor. Ambang **0.6** (`THRESH` di `static/app.js`) — makin kecil makin ketat. `confidence` = `(1 - dist/0.6) * 100`.

## API

| Method | Path | Fungsi |
|---|---|---|
| GET | `/api/faces` | daftar wajah + descriptor |
| POST | `/api/faces` | `{name, descriptor:[128 float]}` |
| DELETE | `/api/faces/{id}` | hapus |
| GET | `/healthz` | health check |

Reset semua data: hapus `faces.json` (server auto-bikin lagi).

Cek cepat: `python smoke.py` (server harus jalan).

## Isi folder

```
app.py                 backend FastAPI playground face-api.js (/api/faces)
pro_api.py             API engine MITO server-side (/api/pro/*, halaman /pro)
restart.py             restart server (kill port 8090 + start ulang, log ke logs/)
run.bat                launcher windows
smoke.py               smoke test playground face-api.js
smoke_pro.py           smoke test MITO engine (/api/pro)
README.md              dokumen ini
faces.json             database playground (descriptor 128-D, auto dibuat)
pro_faces.json         database MITO (embedding 512-D + thumbnail, auto dibuat)
logs/                  log uvicorn dari restart.py
samples/               3 foto publik buat uji (lena, tom, t1 group photo)
mito/                  engine MITO (server-side ONNX)
  scrfd.py             detektor SCRFD + 5 landmark (decode insightface-compatible)
  align.py             umeyama similarity transform -> aligned 112x112
  arcface.py           ArcFace embedding 512-D + cosine similarity
  liveness.py          MiniFASNetV2 anti-spoofing (ensemble 2 scale)
  quality.py           quality gate rule-based (blur/pose/brightness/contrast)
  attributes.py        GenderAge (gender+umur) + Emotion FER+ (8 emosi)
  engine.py            pipeline + decision engine + storage
static/index.html      UI playground face-api.js
static/app.js          logic playground (client-side)
static/face-api.min.js library face-api.js (lokal, no CDN)
static/models/         model face-api.js (detector, landmark, recognition, age-gender, expression)
static/pro.html        UI MITO engine
static/pro.js          logic UI MITO engine
models_pro/            model ONNX server-side (det_500m, w600k_mbf, minifasnet_v2)
```

## Nanti kalau mau naik ke `faceid.guepunya.my.id`

Tinggal reverse proxy (nginx/Caddy) ke `127.0.0.1:8090` + cert HTTPS. HTTPS wajib, kalau nggak kamera browser gak jalan. Kalau dibuka publik, tambahin basic auth dulu — endpoint ini nerima upload tanpa batas.

> Repo intern (`..\attendance-mito-group`) ada di sebelah — jangan pernah naro folder `faceid/` ke dalam working tree repo itu, nanti keikut commit.
---

# MITO AI ENGINE (server-side ONNX) — halaman `/pro`

Implementasi jalur ⭐ dari arsitektur MITO, jalan di server (bukan browser):

```
image → SCRFD + 5pt landmarks → quality gate → align 112×112 (umeyama)
      → ArcFace 512-D → cosine similarity → MiniFASNetV2 liveness
      → GenderAge + FER+ (gender/umur/emosi) → DECISION
```

| Tahap | Model / metode | Sumber | Status |
|---|---|---|---|
| Detection | SCRFD-500M + 5pt kps (`det_500m.onnx`, 2.5 MB) | InsightFace `buffalo_sc` release resmi | ✅ |
| Recognition | ArcFace `w600k_mbf` MobileFaceNet (`w600k_mbf.onnx`, 13.6 MB) | InsightFace `buffalo_sc` release resmi | ✅ |
| Landmark | SCRFD 5-point | dari detektor yang sama | ✅ |
| Liveness | MiniFASNetV2 2.7_80x80 (`minifasnet_v2.onnx`, 1.7 MB) | minivision via HF `garciafido/minifasnet-v2-anti-spoofing-onnx` | ✅ (ensemble 2 scale) |
| Quality | rule-based (blur, pose, brightness, contrast, ukuran) | — | ✅ |
| Gender+Umur | `genderage.onnx` (1.3 MB, input 96×96) | InsightFace `buffalo_s` release resmi | ✅ |
| Emosi | `emotion_ferplus-8.onnx` (34 MB, input 64×64, 8 kelas) | ONNX Model Zoo resmi | ✅ |

Runtime: `onnxruntime` 1.29 + `numpy` + `Pillow` — **tanpa torch, tanpa cv2, tanpa insightface package**.

## API

Upload = **raw bytes** (`Content-Type: application/octet-stream`), jadi gak butuh python-multipart.

| Method | Path | Fungsi |
|---|---|---|
| GET | `/pro` | halaman UI MITO |
| GET | `/api/pro/config` | ambang batas + jumlah terdaftar + status model atribut |
| POST | `/api/pro/verify` | body = bytes gambar → analisis + keputusan + atribut semua wajah |
| POST | `/api/pro/enroll?name=X` | body = bytes gambar → daftar wajah (embedding 512-D + thumbnail + atribut) |
| GET | `/api/pro/faces` | daftar terdaftar (termasuk thumbnail + atribut) |
| DELETE | `/api/pro/faces/{id}` | hapus |
| POST | `/api/pro/attendance?lat=&lon=&acc=` | verify + auto-catat absen (cooldown 60 dtk/orang) + GPS |
| GET | `/api/pro/attendance?limit=30` | riwayat absensi (terbaru dulu) |
| DELETE | `/api/pro/attendance` | kosongkan riwayat |

Atribut per wajah di `verify`: `{"gender": "female", "age": 32, "emotion": "netral", "score": 0.864, "top": [["netral",0.864],["sedih",0.126],["menghina",0.005]]}` — bisa dimatikan lewat `attrs: false` di `DEFAULTS`.

Contoh:
```powershell
curl.exe -X POST --data-binary "@samples/lena.jpg" http://127.0.0.1:8090/api/pro/verify
curl.exe -X POST --data-binary "@samples/lena.jpg" "http://127.0.0.1:8090/api/pro/enroll?name=Lena"
```

## Ambang batas (default)

| Parameter | Default | Mode | Catatan |
|---|---|---|---|
| `det_score` | 0.5 | — | skor deteksi SCRFD |
| `nms` | 0.4 | — | IoU NMS |
| `match_cosine` | 0.35 | — | terukur: orang sama ≥ 0.94, orang beda ≤ 0.14 → margin lebar |
| `liveness_live` | 0.5 | `warn` | `off`/`warn`/`enforce` |
| `quality_mode` | — | `warn` | `off`/`warn`/`enforce` |

Mode default `warn` supaya eksperimen gak langsung nge-block; ubah di `mito/engine.py` → `DEFAULTS`.

## Hasil verifikasi (terukur, bukan klaim)

Detektor + recognizer, pakai 3 foto publik di `samples/`:

| Uji | Hasil |
|---|---|
| lena → 1 wajah, skor 0.811, landmark urutan benar | ✅ |
| t1.jpg (group) → **6 wajah**, skor 0.82–0.88, semua landmark valid | ✅ |
| cosine lena vs lena | 1.0000 |
| cosine lena vs lena diputar 8° + scale 80% + jpeg 55 | **0.9504** (tahan degradasi) |
| cosine lena vs 6 orang lain di t1 | **0.027–0.137** (diskriminasi jelas) |
| engine: enroll lena → verify lena | **accept**, cos 1.0, ~77 ms |
| engine: verify t1 (6 orang) | **6/6 reject** `below_threshold` |
| engine: verify lena degradasi | **accept**, cos 0.944 |
| atribut lena | female · umur 32 · emosi netral 0.864 (top-3: netral/sedih/menghina) ✅ |
| atribut einstein (croom bio) | male · umur 54 ✅ |
| absensi + atribut + GPS | record: name, cos, liveness, gender/age/emosi, lat/lon ±akurasi ✅ |
| kecepatan | ~87 ms/wajah (CPU, det 640×640); +atribut ~360–510 ms/wajah |

## Temuan penting (dokumentasi model pihak ketiga SALAH — sudah diverifikasi silang)

1. **Urutan class MiniFASNetV2**: HF README klaim `[live, print, replay]`. **Salah.** Source upstream `test.py`:
   `if label == 1: Real Face` → urutan sebenarnya **(print, real, replay)**, index **1 = real**.
2. **Preprocessing**: HF README klaim `pixel/255` range `[0,1]`. **Salah.** Upstream `src/data_io/functional.py:to_tensor`
   mengembalikan `img.float()` dengan `/255` **dikomentari** (`modified by zkx`) → model makan **BGR raw 0–255**.
   Dicek juga dari bytes ONNX: **gak ada node `Div`, gak ada konstanta 255.0**. Kalau dikasih `/255`, output
   saturasi konstan di semua gambar (terbukti di grid experiment).
3. **Crop**: upstream `_get_new_box` menggeser box biar tetap di dalam gambar (**clamp**), bukan padding hitam.
   Padding hitam bikin model off-distribution — sudah diganti ke clamp.
4. **Detector butuh margin**: crop wajah super tight (contoh `tom.png` 112×112) **gagal dideteksi** (0 wajah).
   Fix: `auto_pad` — kalau 0 wajah, coba ulang dengan border hitam 50%. Terukur: 0 wajah → skor 0.828.
   **Padding mirror jangan dipakai** (bikin 3 false positive).
5. **Liveness sensitif ke skala crop**: wajah yang sama di scale 2.7 bisa `replay (0.416)` tapi di scale 4.0
   `real (0.939)`. Upstream nge-ensemble 2 bobot (2.7 + 4.0); kita cuma punya 2.7, jadi di-ensemble di 2 skala crop.
6. **GenderAge preprocessing**: preprocessing resminya tersebar di 2 file berbeda (`attribute.py` + `face_align.py`
   di sdist 0.7.3, gak ada di README). Crop 96×96 bbox-center dengan scale `96/(max(w,h)*1.5)`, input **RGB raw
   0–255 tanpa mean/std** (varian mxnet bn_data). Diverifikasi empiris: varian mxnet klasik `(x-127.5)/128`
   menghasilkan prediksi gender **konstan/lemah** (lena & einstein sama-sama argmax 0), varian raw 0–255 bisa
   bedain F/M dengan benar. Decode: `argmax(pred[:2])` → 0=female, 1=male (lena→F ✅, einstein→M ✅),
   `age = round(pred[2]*100)`.
7. **FER+ normalisasi**: demo ONNX.js bilang grayscale aja, repo ONNX zoo juga gak eksplisit. Diverifikasi pake
   `test_data_set_0/input_0.pb` resmi: ternyata input zoo **bukan 0–255 juga** (range −32.37…+36.04 ≈
   `(p/255−0.4732)×68.4`), tapi model+parser gue terbukti bener — jalanin input zoo asli → L2 vs output
   ground truth = **0.0000**. Dari varian sederhana, **raw 0–255 paling deket** (L2 1.17 vs `/255` L2 2.97,
   confidence 0.93 vs expected 0.97). Fix: `FER_DIV_255 = False` di `mito/attributes.py`.

## Batasan yang harus diketahui (jujur)

- **Belum ada sampel serangan asli.** Simulasi print/replay yang gue bikin (blur + downscale + grayscale) **gak**
  memicu class fake (tetap diprediksi `real`) — jadi **akurasi anti-spoof belum tervalidasi**. Butuh foto asli
  (foto layar HP, foto kertas hasil print) buat kalibrasi ambang `liveness_live`.
- **Wajah resolusi sangat kecil gagal diklasifikasi liveness** (`tom.png` 112×112 → `replay`, P(real) 0.0001).
  Ini false-reject yang diketahui; deteksi tetap jalan.
- **Cuma model 2.7**, model 4.0 belum ada versi ONNX publik. Konversi dari `.pth` butuh torch (install besar).
  Python 3.13 juga belum disupport wheel `insightface` (yang tersedia cuma cp39–cp312).
- **Cuma jalur ⭐** yang diimplementasi. Belum ada: RetinaFace/YuNet, AdaFace/MagFace/MobileFaceNet alternatif,
  CDCN/DeepPixBiS, dan quality berbasis model (SER-FIQ, FaceQNet, MagFace).
- **Statis (single image)**, belum ada multi-frame voting / challenge-response (kedip, toleh) buat liveness.
- **Belum di-deploy** ke `faceid.guepunya.my.id` — masih lokal port 8090.
- Skala: `pro_faces.json` load seluruh DB tiap request; buat ratusan/ribuan wajah perlu index vektor (FAISS).