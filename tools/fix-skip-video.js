// fix-skip-video.js — Patch Pano2VR skin.js: SEMUA tombol Skip (desktop
// `_button_skip` DAN mobile `_skip_mobile`) benar-benar menghentikan video
// teaser (desktop + mobile), bukan hanya hide container.
// Idempoten per-handler (marker FIX-SKIP-STOP).
// Pakai: node tools/fix-skip-video.js [root...]
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const MARK = 'FIX-SKIP-STOP';
const BLOCK =
  '/*FIX-SKIP-STOP*/player.stopSound("video - desktop");player.stopSound("video - mobile");' +
  'try{if(me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}}catch(_se){}' +
  'try{if(me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_se2){}';

const HANDLER_RE = /me\.([A-Za-z0-9_]*skip[A-Za-z0-9_]*)\.onclick\s*=\s*function\s*\(e\)\s*\{/gi;
const STOP = 'player.stopSound("_main");';

function walk(dir, out) {
  let entries;
  try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch (e) { return out; }
  for (const e of entries) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name === 'skin.js') out.push(p);
  }
  return out;
}

const files = [];
for (const r of roots) walk(r, files);

let patched = 0, patchedHandlers = 0, skipped = 0, failed = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { console.log('READ FAIL ' + f); failed++; continue; }
  const inserts = [];
  HANDLER_RE.lastIndex = 0;
  let m;
  while ((m = HANDLER_RE.exec(t)) !== null) {
    const si = t.indexOf(STOP, m.index);
    if (si < 0) continue;
    const after = si + STOP.length;
    if (t.slice(after, after + 60).indexOf(MARK) !== -1) continue; // sudah
    inserts.push(after);
  }
  if (!inserts.length) { console.log('SKIP (sudah/ tanpa handler): ' + f); skipped++; continue; }
  const bak = f + '.preskipfix.bak';
  try { if (!fs.existsSync(bak)) fs.copyFileSync(f, bak); } catch (e) {}
  inserts.sort((a, b) => b - a);
  for (const at of inserts) t = t.slice(0, at) + BLOCK + t.slice(at);
  try { fs.writeFileSync(f, t); } catch (e) { console.log('WRITE FAIL ' + f); failed++; continue; }
  console.log('PATCHED (' + inserts.length + ' handler): ' + f);
  patched++; patchedHandlers += inserts.length;
}

console.log(`Done. files=${patched} handlers=${patchedHandlers} skipped=${skipped} failed=${failed}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
