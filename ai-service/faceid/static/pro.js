"use strict";
const $ = (id) => document.getElementById(id);
let stream = null;
let imgMode = false;
let busy = false;
let useFront = true; // default depan — buat daftar muka sendiri
let live = true;
let loopTimer = null;
let lastGps = null;
let light = false;
let tickN = 0;
let lastFull = null; // hasil verify lengkap terakhir (identitas nempel ke track)
let lastFullAt = 0;
let poseSeq = null;

function setMsg(t) { $("msg").textContent = t; }

async function loadConfig() {
  const c = await (await fetch("/api/pro/config")).json();
  const t = c.thresholds;
  $("chain").textContent =
    "SCRFD → 5pt landmarks → ArcFace 512-D → cosine ≥ " + t.match_cosine +
    " → MiniFASNetV2 ≥ " + t.liveness_live + " (" + t.liveness_mode + ")" +
    " → decision · quality: " + t.quality_mode +
    (c.attributes && c.attributes.genderage ? " · gender+umur ✓" : "") +
    (c.attributes && c.attributes.emotion ? " · emosi ✓" : "");
  $("dot").classList.add("on");
}

const CAM_ERR = {
  NotAllowedError: "izin kamera ditolak. Chrome: ikon ⋮/🔒 di address bar → Izin → Kamera → Izinkan, lalu reload",
  NotFoundError: "gak nemu kamera di device ini",
  NotReadableError: "kamera lagi dipakai app lain — tutup app kamera/video call dulu",
  OverconstrainedError: "set kamera gak didukung — coba tombol 🔄",
};

async function startCam(forceFront) {
  if (typeof forceFront === "boolean") useFront = forceFront;
  if (!window.isSecureContext) {
    setMsg("❌ kamera diblokir browser: halaman gak aman. Wajib https://…ts.net (HP) atau http://localhost (laptop). Alternatif sekarang: 📤 Upload → pilih 'Kamera'.");
    return;
  }
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setMsg("❌ browser gak support getUserMedia. Pakai 📤 Upload → pilih 'Kamera'.");
    return;
  }
  try {
    if (stream) stream.getTracks().forEach((t) => t.stop());
    stream = await navigator.mediaDevices.getUserMedia({
      audio: false,
      video: { facingMode: useFront ? "user" : "environment", width: { ideal: 1280 } },
    });
    $("vid").srcObject = stream;
    $("vid").classList.remove("hidden");
    $("img").classList.add("hidden");
    $("clearImg").classList.add("hidden");
    imgMode = false;
    $("flipBtn").textContent = useFront ? "🔄 Kamera Depan" : "🔄 Kamera Belakang";
    await new Promise((r) => setTimeout(r, 300));
    setMsg("kamera nyala (" + (useFront ? "depan" : "belakang") + "). live scan jalan otomatis.");
    startLiveLoop();
  } catch (e) {
    const n = e && e.name ? e.name : "Error";
    setMsg("❌ kamera [" + n + "] " + (CAM_ERR[n] || (e && e.message) || "") + " — alternatif pasti jalan: 📤 Upload → pilih 'Kamera'.");
  }
}

function insecureBanner() {
  if (window.isSecureContext) return;
  const url = "https://" + location.hostname + ":8443" + location.pathname;
  const b = document.createElement("div");
  b.style.cssText = "background:#3a1414;border:1px solid #7f2b2b;border-radius:12px;padding:11px 12px;margin-top:10px;font-size:13.5px;line-height:1.6";
  b.innerHTML = "⚠️ Browser blokir kamera di http. <a style='color:var(--acc);font-weight:700' href='" + url + "'>Buka versi aman → " + url + "</a><br><span style='color:var(--dim)'>muncul peringatan sertifikat → Advanced/Lanjutkan saja (cert lokal, self-signed)</span>";
  document.querySelector(".wrap").insertBefore(b, document.querySelector(".cam"));
}

function currentSource() { return imgMode ? $("img") : $("vid"); }

function frameBlob() {
  const v = $("vid");
  if (!v.videoWidth || !v.videoHeight)
    return Promise.reject(new Error("kamera belum siap (frame 0px) — tunggu 1-2 detik, klik lagi"));
  const c = document.createElement("canvas");
  c.width = v.videoWidth;
  c.height = v.videoHeight;
  c.getContext("2d").drawImage(v, 0, 0);
  return new Promise((res) => c.toBlob(res, "image/jpeg", 0.92));
}

function startLiveLoop() {
  if (loopTimer) return;
  loopTimer = setInterval(liveTick, 400);
}
function stopLiveLoop() {
  if (loopTimer) { clearInterval(loopTimer); loopTimer = null; }
}

function sceneBrightness() {
  const v = $("vid");
  if (!v.videoWidth) return 255;
  const c = document.createElement("canvas");
  c.width = 32;
  c.height = 24;
  const cx = c.getContext("2d");
  cx.drawImage(v, 0, 0, 32, 24);
  const d = cx.getImageData(0, 0, 32, 24).data;
  let s = 0;
  for (let i = 0; i < d.length; i += 4) s += 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
  return s / (d.length / 4);
}

async function toggleLight(on) {
  light = on;
  document.body.classList.toggle("lightmode", light);
  $("lightBtn").textContent = light ? "💡 Terang: ON" : "💡 Mode Terang";
  $("lightBtn").style.borderColor = light ? "#7a5f1c" : "var(--line)";
  if (light && !useFront && stream) {
    try {
      const t = stream.getVideoTracks()[0];
      await t.applyConstraints({ advanced: [{ torch: true }] });
      setMsg("💡 mode terang + lampu flash nyala");
      return;
    } catch (e) { /* torch gak didukung — cukup cahaya layar */ }
  }
  if (light) setMsg("💡 mode terang nyala (layar jadi sumber cahaya)");
}
const POSES = [
  { label: "hadap LURUS ke kamera", test: (y) => Math.abs(y) < 0.14 },
  { label: "toleh perlahan ke KIRI", test: (y) => y < -0.2 },
  { label: "toleh perlahan ke KANAN", test: (y) => y > 0.2 },
  { label: "dagu NAIK, wajah agak ke atas", test: () => true },
  { label: "kondisi beda: pakai kacamata / ruangan lebih gelap", test: () => true },
];

function yawFromKps(kps) {
  const le = kps[0], re = kps[1], nose = kps[2];
  const dl = Math.hypot(nose[0] - le[0], nose[1] - le[1]);
  const dr = Math.hypot(nose[0] - re[0], nose[1] - re[1]);
  return (dl - dr) / (dl + dr + 1e-6);
}

function iou(a, b) {
  const x1 = Math.max(a[0], b[0]), y1 = Math.max(a[1], b[1]);
  const x2 = Math.min(a[2], b[2]), y2 = Math.min(a[3], b[3]);
  const inter = Math.max(0, x2 - x1) * Math.max(0, y2 - y1);
  const ar = (a[2] - a[0]) * (a[3] - a[1]), br = (b[2] - b[0]) * (b[3] - b[1]);
  return inter / (ar + br - inter + 1e-6);
}

// posisi segar dari track + identitas dari verify lengkap terakhir (match by IoU)
function mergedFaces(tracks) {
  if (!lastFull) return tracks.map((t) => ({ ...t, decision: null }));
  return tracks.map((t) => {
    let best = null, bi = 0.3;
    lastFull.faces.forEach((f) => {
      const v = iou(t.bbox, f.bbox);
      if (v > bi) { bi = v; best = f; }
    });
    return best
      ? { ...t, decision: best.decision, match: best.match, attributes: best.attributes }
      : { ...t, decision: null };
  });
}

function attUrl() {
  let u = "/api/pro/attendance";
  if (lastGps) u += "?lat=" + lastGps.lat + "&lon=" + lastGps.lon + "&acc=" + lastGps.acc;
  return u;
}
async function liveTick() {
  if (busy || !live || imgMode || poseSeq) return;
  busy = true;
  tickN++;
  try {
    if (!light && sceneBrightness() < 45) {
      await toggleLight(true);
      setMsg("🌙 tempat gelap terdeteksi — mode terang nyala otomatis");
    }
    const full = tickN % 5 === 1; // 1x verify lengkap tiap ~2s, sisanya track cepat
    if (full) {
      const out = await post(attUrl(), await frameBlob());
      lastFull = out.verify;
      lastFullAt = Date.now();
      render(lastFull, out);
      if (out.record) loadAttendance();
    } else {
      const t = await post("/api/pro/track", await frameBlob());
      drawOverlay(mergedFaces(t.faces));
    }
  } catch (e) {
    // frame belum siap / jaringan — frame berikutnya aja
  } finally {
    busy = false;
  }
}
function refreshGps() {
  if (!navigator.geolocation) { $("gps").textContent = "📍 GPS: gak didukung browser"; return; }
  navigator.geolocation.getCurrentPosition(
    (p) => {
      lastGps = { lat: +p.coords.latitude.toFixed(6), lon: +p.coords.longitude.toFixed(6), acc: Math.round(p.coords.accuracy) };
      $("gps").textContent = "📍 " + lastGps.lat + ", " + lastGps.lon + " ±" + lastGps.acc + "m";
    },
    (e) => { $("gps").textContent = "📍 GPS: " + (e.code === 1 ? "izin ditolak (aktifkan lokasi di izin situs)" : "gagal: " + e.message); },
    { enableHighAccuracy: true, timeout: 8000, maximumAge: 30000 }
  );
}
async function loadAttendance() {
  const rows = await (await fetch("/api/pro/attendance?limit=30")).json();
  const box = $("att");
  box.innerHTML = rows.length ? "" : '<div class="meta" style="color:var(--dim)">belum ada</div>';
  rows.forEach((r) => {
    const t = new Date(r.ts * 1000);
    const jam = t.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" });
    const tgl = t.toLocaleDateString("id-ID", { day: "2-digit", month: "short" });
    const ok = r.status === "hadir";
    const g = r.gps && r.gps.lat != null
      ? " · 📍<a href='https://maps.google.com/?q=" + r.gps.lat + "," + r.gps.lon + "'>" + r.gps.lat + ", " + r.gps.lon + "</a> ±" + (r.gps.acc || "?") + "m"
      : "";
    const at = r.attrs || {};
    const atTxt = (at.gender ? " · " + (at.gender === "female" ? "♀" : "♂") + at.age : "") +
      (at.emotion ? " · " + at.emotion : "");
    const d = document.createElement("div");
    d.className = "face";
    d.innerHTML = (r.thumb ? '<img src="data:image/png;base64,' + r.thumb + '" alt="">' : '<img alt="">') +
      '<div class="grow"><div class="nm">' + r.name + ' <span class="badge ' + (ok ? "accept" : "reject") + '">' + r.status + "</span></div>" +
      '<div class="meta">' + tgl + " " + jam + atTxt + " · cos " + (r.cosine == null ? "-" : r.cosine) + " · live " + (r.liveness == null ? "-" : r.liveness) +
      (r.device ? " · " + r.device : "") + g + "</div></div>";
    box.appendChild(d);
  });
}

function pendingBlob() {
  return imgMode ? Promise.resolve($("file").files[0]) : frameBlob();
}

async function post(url, blob) {
  const r = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/octet-stream" },
    body: blob,
  });
  if (!r.ok) throw new Error((await r.text()).slice(0, 300));
  return r.json();
}

async function verify() {
  if (busy) return;
  busy = true;
  setMsg("proses…");
  try {
    const out = await post("/api/pro/verify", await pendingBlob());
    render(out);
  } catch (e) {
    setMsg("gagal verifikasi: " + e.message);
  } finally {
    busy = false;
  }
}

async function enroll() {
  if (busy) return;
  const name = $("name").value.trim();
  if (!name) { setMsg("isi nama dulu bro"); return; }
  busy = true;
  setMsg("proses enroll…");
  try {
    const out = await post("/api/pro/enroll?name=" + encodeURIComponent(name), await pendingBlob());
    if (!out.ok) {
      setMsg("❌ ditolak engine: " + out.error);
    } else if (out.merged) {
      setMsg('✅ "' + out.name + '" +1 sampel (total ' + out.samples + "/5) — daftarin juga versi kacamata/ruangan beda biar makin akurat");
      $("name").value = "";
    } else {
      setMsg('✅ "' + out.name + '" terdaftar — daftarin 1-2x lagi (kacamata / tempat beda) biar deteksi makin tahan variasi');
      $("name").value = "";
    }
    await loadFaces();
  } catch (e) {
    setMsg("gagal enroll: " + e.message);
  } finally {
    busy = false;
  }
}
// ----- enroll multi-sudut terpandu (5 pose: lurus, kiri, kanan, atas, kondisi beda) -----
async function enrollGuided() {
  const name = $("name").value.trim();
  if (!name) { setMsg("isi nama dulu bro"); return; }
  if (imgMode) return enroll(); // upload foto → enroll biasa
  if (poseSeq) return;
  if (!stream) { setMsg("nyalakan kamera dulu — mode terpandu butuh kamera live"); return; }
  poseSeq = { i: 0, ok: 0, name, total: POSES.length };
  $("poseGuide").style.display = "";
  $("poseSkip").style.display = "";
  guideStep();
}

async function guideStep() {
  const s = poseSeq;
  if (!s) return;
  if (s.i >= s.total) {
    $("poseGuide").style.display = "none";
    $("poseSkip").style.display = "none";
    poseSeq = null;
    setMsg("✅ enroll multi-sudut \"" + s.name + "\" selesai: " + s.ok + "/" + s.total +
      " pose tersimpan — makin tahan variasi sudut, kacamata & cahaya");
    await loadFaces();
    return;
  }
  const p = POSES[s.i];
  $("poseGuide").textContent = "📸 [" + (s.i + 1) + "/" + s.total + "] " + p.label + " …";
  const t0 = Date.now();
  while (Date.now() - t0 < 7000 && poseSeq === s) {
    await new Promise((r) => setTimeout(r, 350));
    try {
      const t = await post("/api/pro/track", await frameBlob());
      if (t.faces.length) {
        const y = yawFromKps(t.faces[0].kps);
        if (p.test(y)) break;
        $("poseGuide").textContent =
          "📸 [" + (s.i + 1) + "/" + s.total + "] " + p.label + " — yaw " + y.toFixed(2);
      }
    } catch (e) { /* frame belum siap — coba lagi */ }
  }
  if (poseSeq !== s) return; // dibatalin
  try {
    const out = await post("/api/pro/enroll?name=" + encodeURIComponent(s.name), await frameBlob());
    if (out.ok) s.ok++;
  } catch (e) { /* frame gagal — lanjut pose berikutnya */ }
  s.i++;
  guideStep();
}

function drawOverlay(faces) {
  const ov = $("ov");
  const src = currentSource();
  ov.width = src.videoWidth || src.naturalWidth;
  ov.height = src.videoHeight || src.naturalHeight;
  const ctx = ov.getContext("2d");
  ctx.clearRect(0, 0, ov.width, ov.height);
  ctx.lineWidth = Math.max(2, ov.width / 200);
  ctx.font = Math.max(12, ov.width / 32) + "px sans-serif";
  faces.forEach((f) => {
    const b = f.bbox;
    const acc = f.decision ? f.decision.status === "accept" : null;
    const col = acc === null ? "#eab308" : acc ? "#4ade80" : "#f87171";
    ctx.strokeStyle = col;
    ctx.strokeRect(b[0], b[1], b[2] - b[0], b[3] - b[1]);
    const at = f.attributes || {};
    const atTxt = (at.gender ? " · " + (at.gender === "female" ? "♀" : "♂") + at.age : "") +
      (at.emotion ? " · " + at.emotion : "");
    const label = acc === null ? "mencari…" :
      (acc ? "✅ " : "❌ ") +
      (f.match && f.match.name ? f.match.name + " (" + f.match.cosine + ")" : f.decision.reason) +
      atTxt;
    ctx.fillStyle = col;
    ctx.fillText(label, b[0], Math.max(14, b[1] - 6));
    ctx.fillStyle = "#fbbf24";
    f.kps.forEach((p) => ctx.fillRect(p[0] - 2, p[1] - 2, 4, 4));
  });
}

function render(out, att) {
  drawOverlay(out.faces);
  const box = $("out");
  box.innerHTML = "";
  if (!out.faces.length) {
    box.innerHTML = '<div class="card"><div class="verdict"> gak ada wajah kedeteksi</div><div class="msg">coba dekatkan / terangin / hadap kamera</div></div>';
    setMsg(out.timings_ms.total + " ms · db " + out.enrolled);
    return;
  }
  out.faces.forEach((f, i) => {
    const acc = f.decision.status === "accept";
    const m = f.match;
    const q = f.quality.metrics;
    const at = f.attributes || {};
    const card = document.createElement("div");
    card.className = "card";
    card.innerHTML =
      '<div class="verdict">' + (acc ? "✅ DITERIMA" : " DITOLAK") +
      ' <span class="badge ' + (acc ? "accept" : "reject") + '">' + f.decision.reason + "</span></div>" +
      (f.decision.warnings.length
        ? '<div style="margin-top:8px"><span class="badge warn">warnings: ' + f.decision.warnings.join(" · ") + "</span></div>"
        : "") +
      "<table>" +
      "<tr><td>1. deteksi SCRFD</td><td>" + f.score + " · " + Math.round(f.bbox[2] - f.bbox[0]) + "×" + Math.round(f.bbox[3] - f.bbox[1]) + " px</td></tr>" +
      "<tr><td>2. landmark 5pt</td><td>" + (f.landmarks_valid ? "valid" : "mencurigakan") + "</td></tr>" +
      "<tr><td>3. quality gate</td><td>" + (f.quality.ok ? "pass" : f.quality.reasons.join(", ")) + "</td></tr>" +
      "<tr><td>blur / bright / contrast</td><td>" + q.blur + " / " + q.brightness + " / " + q.contrast + "</td></tr>" +
      "<tr><td>yaw / roll</td><td>" + q.yaw + " / " + q.roll + "°</td></tr>" +
      "<tr><td>4. ArcFace cosine</td><td>" + (m ? m.name + " = " + m.cosine + (m.ok ? " ≥ " : " < ") + m.threshold : "db kosong") + "</td></tr>" +
      "<tr><td>5. liveness</td><td>" + f.liveness.label + " · P(real) " + f.liveness.live_prob + "</td></tr>" +
      "<tr><td>6. atribut</td><td>" + (at.gender ? (at.gender === "female" ? "♀ female" : "♂ male") + " · " + at.age + " th" : "-") +
      (at.emotion ? " · " + at.emotion + " " + at.score : "") + "</td></tr>" +
      "<tr><td>7. decision</td><td>" + f.decision.status + "</td></tr>" +
      "</table>" +
      '<div class="bar"><i style="width:' + (f.liveness.live_prob * 100).toFixed(1) + '%"></i></div>' +
      '<div class="align"><img src="data:image/png;base64,' + f.aligned + '" alt="aligned">' +
      '<div class="meta" style="color:var(--dim);font-size:11.5px">aligned 112×112 → ArcFace<br>' +
      f.liveness.per_scale.map((p) => p.scale + "x: " + p.label + " (" + p.live_prob + ")").join("<br>") +
      "</div></div>";
    box.appendChild(card);
  });
  if (att && att.record) {
    const r = att.record;
    const g = r.gps && r.gps.lat != null ? " 📍" + r.gps.lat + ", " + r.gps.lon + " ±" + (r.gps.acc || "?") + "m" : "";
    const b = document.createElement("div");
    b.className = "card";
    b.style.borderColor = "#1f6b3a";
    b.innerHTML = '<div class="verdict">🗓 Absen tercatat: ' + r.name +
      ' <span class="badge accept">' + new Date(r.ts * 1000).toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit" }) + "</span>" + g + "</div>";
    box.insertBefore(b, box.firstChild);
  }
  setMsg(out.faces.length + " wajah · " + out.timings_ms.total + " ms · db " + out.enrolled +
    (att && att.cooldown ? " · absen terakhir masih dalam cooldown" : ""));
}

async function loadFaces() {
  const rows = await (await fetch("/api/pro/faces")).json();
  $("count").textContent = rows.length;
  const box = $("list");
  box.innerHTML = rows.length ? "" : '<div class="meta" style="color:var(--dim)">kosong</div>';
  rows.forEach((f) => {
    const d = document.createElement("div");
    d.className = "face";
    const qm = f.quality && f.quality.metrics ? f.quality.metrics : null;
    const at = f.attributes || {};
    d.innerHTML = '<img src="data:image/jpeg;base64,' + (f.thumb || "") + '" alt="">' +
      '<div class="grow"><div class="nm">' + f.name + '</div><div class="meta">' +
      (at.gender ? (at.gender === "female" ? "♀" : "♂") + at.age + " · " : "") +
      (at.emotion ? at.emotion + " · " : "") +
      (f.samples > 1 ? f.samples + " sampel · " : "") +
      (qm ? "blur " + qm.blur + " · yaw " + qm.yaw + " · roll " + qm.roll + "°" : "") +
      (f.liveness ? " · " + f.liveness.label + " " + f.liveness.live_prob : "") +
      "</div></div>";
    const b = document.createElement("button");
    b.className = "del";
    b.textContent = "";
    b.onclick = async () => {
      await fetch("/api/pro/faces/" + f.id, { method: "DELETE" });
      loadFaces();
    };
    d.appendChild(b);
    box.appendChild(d);
  });
}

function wire() {
  $("camBtn").onclick = () => startCam();
  $("flipBtn").onclick = () => startCam(!useFront);
  $("snapBtn").onclick = verify;
  $("enrollBtn").onclick = enrollGuided;
  $("poseSkip").onclick = () => { if (poseSeq) { poseSeq.i++; guideStep(); } };
  $("upBtn").onclick = () => $("file").click();
  $("liveBtn").onclick = () => {
    live = !live;
    $("liveBtn").textContent = live ? "🔴 Live Scan: ON" : "⏸ Live Scan: OFF";
    $("liveBtn").style.borderColor = live ? "#1f6b3a" : "var(--line)";
    if (live) startLiveLoop(); else stopLiveLoop();
    setMsg(live ? "live scan nyala — deteksi otomatis, gak perlu klik" : "live scan mati — pakai ✅ Verifikasi manual");
  };
  $("lightBtn").onclick = () => toggleLight(!light);
  $("clearAtt").onclick = async () => {
    await fetch("/api/pro/attendance", { method: "DELETE" });
    loadAttendance();
  };
  $("file").onchange = (e) => {
    const f = e.target.files[0];
    if (!f) return;
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    $("img").src = URL.createObjectURL(f);
    $("img").classList.remove("hidden");
    $("vid").classList.add("hidden");
    $("clearImg").classList.remove("hidden");
    imgMode = true;
    setMsg("foto siap (" + f.name + "). klik ✅ Verifikasi atau ➕ Daftar.");
    verify();
  };
  $("clearImg").onclick = () => {
    imgMode = false;
    $("img").classList.add("hidden");
    $("out").innerHTML = "";
    startCam();
  };
}

(async function init() {
  wire();
  await loadConfig().catch((e) => setMsg("config gagal: " + e.message));
  await loadFaces().catch(() => {});
  loadAttendance().catch(() => {});
  insecureBanner();
  refreshGps();
  setInterval(refreshGps, 60000);
  deviceInfo();
  startCam();
})();

function deviceInfo() {
  const ua = navigator.userAgent || "";
  let dev = "unknown";
  const m = ua.match(/Android[^;]*;\s*([^;)]+?)(?:\s+Build\/|\))/);
  if (m) dev = "Android " + m[1].trim();
  else if (ua.includes("iPhone")) dev = "iPhone";
  else if (ua.includes("Windows")) dev = "Windows PC";
  else if (ua.includes("Macintosh")) dev = "Mac";
  const ram = navigator.deviceMemory ? navigator.deviceMemory + "GB" : "?";
  $("devinfo").textContent = "\U0001F4F1 " + dev + " \u00b7 layar " + screen.width + "\u00d7" + screen.height +
    " \u00b7 RAM " + ram + " \u00b7 " + (navigator.hardwareConcurrency || "?") + " core" +
    (navigator.userAgentData && navigator.userAgentData.brands
      ? " \u00b7 " + navigator.userAgentData.brands.map((b) => b.brand).filter((b) => !/Chromium/i.test(b)).join("/")
      : "");
}