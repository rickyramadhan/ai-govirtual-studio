// fix-single-video.js — Cegah video teaser desktop & mobile berbunyi bersamaan.
// Menyisipkan guard di dalam `_yes.onclick` (mulai teaser): bila salah satu
// elemen video tersembunyi (ggVisible=false / visibility:hidden), pause + mute.
// Idempoten (marker FIX-SINGLE-VIDEO). Hanya untuk Semarang.
// Pakai: node tools/fix-single-video.js [root...]
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const MARK = 'FIX-SINGLE-VIDEO';
const BLOCK =
  '/*FIX-SINGLE-VIDEO*/try{var _isH=function(el){return !el||el.ggVisible===false||(el.style&&el.style.visibility==="hidden");};' +
  'if(_isH(me._video_desktop)&&me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}' +
  'if(_isH(me._video_mobile)&&me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_sv){}';

const YES_RE = /me\._yes\.onclick\s*=\s*function\s*\(e\)\s*\{/;
const MOBILE_PLAY = 'player.playSound("video - mobile","1");';

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

let patched = 0, skipped = 0, failed = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { failed++; continue; }
  if (t.includes(MARK)) { console.log('SKIP (sudah): ' + f); skipped++; continue; }
  const m = YES_RE.exec(t);
  if (!m) { console.log('SKIP (tanpa _yes.onclick): ' + f); skipped++; continue; }
  const pi = t.indexOf(MOBILE_PLAY, m.index);
  if (pi < 0) { console.log('SKIP (tanpa playSound mobile): ' + f); skipped++; continue; }
  const insertAt = pi + MOBILE_PLAY.length;
  const bak = f + '.presingle.bak';
  try { if (!fs.existsSync(bak)) fs.copyFileSync(f, bak); } catch (e) {}
  try { fs.writeFileSync(f, t.slice(0, insertAt) + BLOCK + t.slice(insertAt)); }
  catch (e) { console.log('WRITE FAIL ' + f); failed++; continue; }
  console.log('PATCHED: ' + f);
  patched++;
}

console.log(`Done. patched=${patched} skipped=${skipped} failed=${failed}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
