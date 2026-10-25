// Pemeriksaan tampilan AI Guide di browser sungguhan (Edge headless).
// Pemakaian: node tools/ui_check.mjs <url-tur>
// Keluaran: satu baris JSON di akhir output: {"ok":true|false,"reason":"...","ms":123}
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const url = process.argv[2];
const EDGE = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find((p) => fs.existsSync(p));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const t0 = Date.now();
const done = (ok, reason) => { console.log(JSON.stringify({ ok, reason, ms: Date.now() - t0 })); process.exit(ok ? 0 : 1); };

if (!url) done(false, 'url tidak diberikan');
if (!EDGE) done(false, 'Microsoft Edge tidak ditemukan');

const port = 9800 + Math.floor(Math.random() * 150);
const prof = path.join(os.tmpdir(), 'uicheck-' + port);
const edge = spawn(EDGE, ['--headless=new', '--disable-gpu', '--no-first-run', '--mute-audio', `--user-data-dir=${prof}`, `--remote-debugging-port=${port}`, '--window-size=1440,900', 'about:blank'], { stdio: 'ignore' });
const cleanup = () => { try { edge.kill(); } catch {} try { fs.rmSync(prof, { recursive: true, force: true }); } catch {} };
process.on('exit', cleanup);

let ws; let id = 0; const pend = new Map();
async function connect() {
  for (let i = 0; i < 60; i++) {
    try {
      const page = (await (await fetch(`http://127.0.0.1:${port}/json`)).json()).find((t) => t.type === 'page');
      if (page) { ws = new WebSocket(page.webSocketDebuggerUrl); await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; }); ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pend.has(d.id)) { pend.get(d.id)(d); pend.delete(d.id); } }; return true; }
    } catch {}
    await sleep(250);
  }
  return false;
}
const send = (method, params = {}) => new Promise((r) => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); return r.result?.result?.value; };

const probeExpr = `(() => {
  const b = document.querySelector('.tour-chat__ai-btn');
  if (!b) return { found: false };
  const r = b.getBoundingClientRect(), cs = getComputedStyle(b);
  const visible = r.width > 4 && r.height > 4 && cs.visibility !== 'hidden' && parseFloat(cs.opacity) > 0.5 && cs.display !== 'none';
  const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
  const top = document.elementFromPoint(cx, cy);
  const unobstructed = !!top && (top === b || b.contains(top));
  return { found: true, visible, unobstructed, cls: b.className };
})()`;

async function clickByText(text) {
  return ev(`(() => { const t = ${JSON.stringify(text)}.toLowerCase(); let best = null;
    for (const e of document.querySelectorAll('div,span,button,a')) { if ((e.textContent || '').trim().toLowerCase() !== t) continue;
      const c = getComputedStyle(e), r = e.getBoundingClientRect(); if (c.display === 'none' || c.visibility === 'hidden' || r.width < 4 || r.height < 4) continue;
      if (!best || r.width * r.height < best.a) best = { x: r.left + r.width / 2, y: r.top + r.height / 2, a: r.width * r.height }; }
    if (!best) return false;
    return best; })()`);
}
async function realClick(x, y) {
  for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) await send('Input.dispatchMouseEvent', { type, x, y, button: 'left', buttons: type === 'mousePressed' ? 1 : 0, clickCount: 1 });
}

(async () => {
  if (!(await connect())) done(false, 'Edge tidak merespons');
  await send('Page.enable'); await send('Runtime.enable');
  await send('Emulation.setFocusEmulationEnabled', { enabled: true });
  await send('Page.navigate', { url });
  await sleep(4000);

  const deadline = Date.now() + 45000;
  let introHandled = false, last = null;
  while (Date.now() < deadline) {
    last = await ev(probeExpr);
    if (last && last.found && last.visible && last.unobstructed) done(true, 'tombol AI Guide tampil dan tidak tertutup');
    // Intro "Enable Audio?": pilih NO agar intro bisa lanjut seperti pengunjung biasa.
    if (!introHandled) {
      const no = await clickByText('NO');
      if (no && typeof no === 'object') { await realClick(no.x, no.y); introHandled = true; await sleep(1500); continue; }
    }
    // Lewati intro bila masih tertahan: panggil handler tombol "Lewati" milik skin (sama seperti pengunjung yang menekannya).
    await ev(`(() => { try { const so = window.skin || window.pano.skinObj; const b = so && so._button_skip; if (b && typeof b.onclick === 'function') b.onclick({}); const c = so && so._closewelcometext; if (c && typeof c.onclick === 'function') c.onclick({}); } catch (e) {} })()`);
    await sleep(2000);
  }
  if (!last || !last.found) done(false, 'tombol AI Guide (.tour-chat__ai-btn) tidak ditemukan di halaman');
  if (!last.visible) done(false, 'tombol AI Guide ada tetapi tidak terlihat (tersembunyi atau opacity 0)');
  done(false, 'tombol AI Guide terlihat tetapi tertutup elemen lain');
})().catch((e) => done(false, 'error: ' + e.message));
