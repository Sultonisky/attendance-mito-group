"use strict";
const $ = (id) => document.getElementById(id);
const MODEL = "/static/models";
const opts = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
const THRESH = 0.6;

let stream = null;
let useFront = true;
let liveTimer = null;
let enrolled = [];
let busy = false;

function setMsg(t) { $("msg").textContent = t; }
function drawBoxes(dets, matched) {
  const c = $("ov"), ctx = c.getContext("2d");
  const src = $("img").classList.contains("hidden") ? $("vid") : $("img");
  c.width = src.videoWidth || src.naturalWidth || src.clientWidth;
  c.height = src.videoHeight || src.naturalHeight || src.clientHeight;
  ctx.clearRect(0, 0, c.width, c.height);
  ctx.lineWidth = Math.max(2, c.width / 200);
  ctx.font = Math.max(12, c.width / 28) + "px sans-serif";
  dets.forEach((d, i) => {
    const box = d.detection.box;
    const col = matched && matched[i] ? "#4ade80" : "#f87171";
    ctx.strokeStyle = col;
    ctx.strokeRect(box.x, box.y, box.width, box.height);
    const label = matched && matched[i];
    if (label) { ctx.fillStyle = col; ctx.fillText(label, box.x, box.y - 4); }
  });
}

async function loadModels() {
  setMsg("load model wajah…");
  const t0 = performance.now();
  await Promise.all([
    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL),
    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL),
    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL),
    faceapi.nets.ageGenderNet.loadFromUri(MODEL),
    faceapi.nets.faceExpressionNet.loadFromUri(MODEL),
  ]);
  $("statusDot").classList.add("on");
  setMsg("model siap (" + (performance.now() - t0).toFixed(0) + " ms). Enroll di laptop bisa dikenali dari HP.");
}

async function startCam() {
  try {
    if (stream) stream.getTracks().forEach((t) => t.stop());
    const devs = await navigator.mediaDevices.enumerateDevices();
    const cams = devs.filter((d) => d.kind === "videoinput");
    const constraints = cams.length > 1
      ? { audio: false, video: { deviceId: { exact: cams[useFront ? 0 : cams.length - 1].deviceId } } }
      : { audio: false, video: { facingMode: useFront ? "user" : "environment" } };
    stream = await navigator.mediaDevices.getUserMedia(constraints);
    $("vid").srcObject = stream;
    $("vid").classList.remove("hidden");
    $("img").classList.add("hidden");
    $("flipBtn").classList.toggle("hidden", cams.length < 2);
    $("clearImg").classList.add("hidden");
    await new Promise((r) => setTimeout(r, 300));
    setMsg("kamera nyala. pilih mode di atas.");
  } catch (e) {
    setMsg("gagal buka kamera: " + e.message + " (coba HTTPS/localhost)");
  }
}

function currentInput() { return $("img").classList.contains("hidden") ? $("vid") : $("img"); }

async function detectAll() {
  const input = currentInput();
  const meta = $("doMeta").checked;
  let det = faceapi.detectAllFaces(input, opts).withFaceLandmarks().withFaceDescriptors();
  if (meta) det = det.withAgeAndGender().withFaceExpressions();
  return det.run();
}

function matchOne(desc) {
  let best = null, bestD = Infinity;
  for (const f of enrolled) {
    const d = faceapi.euclideanDistance(desc, f.descriptor);
    if (d < bestD) { bestD = d; best = f; }
  }
  if (best && bestD < THRESH) {
    return { name: best.name, dist: bestD, conf: Math.max(0, Math.round((1 - bestD / THRESH) * 100)) };
  }
  return { name: null, dist: bestD === Infinity ? null : bestD, conf: 0 };
}

async function recognize() {
  if (busy) return;
  busy = true;
  try {
    const dets = await detectAll();
    const matched = dets.map((d) => {
      const m = matchOne(d.descriptor);
      return m.name ? m.name + " " + m.conf + "%" : null;
    });
    drawBoxes(dets, matched);
    if (!dets.length) { $("result").textContent = "😶 gak ada wajah"; $("detail").textContent = " "; return; }
    const m = matchOne(dets[0].descriptor);
    if (m.name) {
      $("result").textContent = "✅ " + m.name;
      $("detail").textContent = "confidence " + m.conf + "% · dist " + m.dist.toFixed(3);
    } else {
      $("result").textContent = "❓ wajah gak dikenal";
      $("detail").textContent = m.dist == null ? "belum ada yang didaftar" : "dist " + m.dist.toFixed(3);
    }
    if ($("doMeta").checked && dets[0].age) {
      const expr = dets[0].expressions.asSortedArray()[0][0];
      $("detail").textContent += " · " + Math.round(dets[0].age) + "th " + dets[0].gender + " · " + expr;
    }
  } catch (e) { setMsg("error: " + e.message); }
  finally { busy = false; }
}

async function enroll() {
  if (busy) return;
  busy = true;
  try {
    const name = $("name").value.trim();
    if (!name) { setMsg("isi nama dulu bro"); return; }
    const dets = await detectAll();
    const f = dets.find((d) => d.descriptor);
    if (!f) { setMsg("gak ada wajah ke-detect. pastikan muka kelihatan jelas."); return; }
    const res = await fetch("/api/faces", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name, descriptor: Array.from(f.descriptor) }),
    });
    const out = await res.json();
    setMsg('✅ "' + out.name + '" terdaftar (sync ke semua device).');
    $("name").value = "";
    renderList();
  } catch (e) { setMsg("error enroll: " + e.message); }
  finally { busy = false; }
}

async function renderList() {
  const r = await fetch("/api/faces");
  enrolled = await r.json();
  $("list").innerHTML = enrolled.length ? "" : '<div class="sub">belum ada. daftar di sini, lalu kenali di device lain.</div>';
  enrolled.forEach((f) => {
    const row = document.createElement("div");
    row.className = "face";
    row.innerHTML = "<span>" + f.name + "</span>";
    const b = document.createElement("button");
    b.className = "del"; b.textContent = "✕";
    b.onclick = async () => { await fetch("/api/faces/" + f.id, { method: "DELETE" }); renderList(); };
    row.appendChild(b);
    $("list").appendChild(row);
  });
}

function toggleLive() {
  if (liveTimer) { clearInterval(liveTimer); liveTimer = null; }
  if ($("live").checked) { recognize(); liveTimer = setInterval(recognize, 900); }
}

function showTab(which) {
  const rec = which === "rec";
  $("tabRec").classList.toggle("hidden", !rec);
  $("tabReg").classList.toggle("hidden", rec);
  $("tabRecBtn").classList.toggle("active", rec);
  $("tabRegBtn").classList.toggle("active", !rec);
  if (!rec) renderList();
  if (rec && $("live").checked) toggleLive();
}

function wire() {
  $("camBtn").onclick = startCam;
  $("flipBtn").onclick = () => { useFront = !useFront; startCam(); };
  $("recBtn").onclick = recognize;
  $("saveBtn").onclick = enroll;
  $("live").onchange = toggleLive;
  $("upBtn").onclick = () => $("file").click();
  $("file").onchange = (e) => {
    const f = e.target.files[0];
    if (!f) return;
    if (stream) stream.getTracks().forEach((t) => t.stop());
    $("img").src = URL.createObjectURL(f);
    $("img").classList.remove("hidden");
    $("vid").classList.add("hidden");
    $("flipBtn").classList.add("hidden");
    $("clearImg").classList.remove("hidden");
    setMsg("foto siap. klik Kenali / Daftar.");
  };
  $("clearImg").onclick = () => {
    $("img").classList.add("hidden");
    $("ov").getContext("2d").clearRect(0, 0, $("ov").width, $("ov").height);
    startCam();
  };
  $("tabRecBtn").onclick = () => showTab("rec");
  $("tabRegBtn").onclick = () => showTab("reg");
}

(async function init() {
  wire();
  await loadModels();
  await renderList().catch(() => {});
})();