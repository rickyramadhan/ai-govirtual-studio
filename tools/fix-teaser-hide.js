// fix-teaser-hide.js — Setiap handler yang memulai tour (VB=skip/show) harus
// menyembunyikan overlay teaser + menghentikan kedua video device. Pano2VR bisa
// menampilkan ulang teaser sesaat setelah skip, jadi penyembunyian diulang.
// Idempoten (marker GVA-TEASER-HIDE) — blok lama akan diganti versi baru.
// Pakai: node tools/fix-teaser-hide.js [root...]
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const MARK = 'GVA-TEASER-HIDE';
const BLOCK =
  '/*' + MARK + '*/' +
  'var _hideTeaser=function(){try{me.__2video_teaser.style.transition=\'none\';me.__2video_teaser.style.visibility=\'hidden\';me.__2video_teaser.ggVisible=false;}catch(_th){}};' +
  'try{_hideTeaser();}catch(_th0){}try{setTimeout(_hideTeaser,120);}catch(_th1){}try{setTimeout(_hideTeaser,600);}catch(_th2){}try{setTimeout(_hideTeaser,1500);}catch(_th3){}' +
  'try{var _thC=0;var _thT=setInterval(function(){_hideTeaser();if(++_thC>30){clearInterval(_thT);}},150);}catch(_th4){}' +
  'try{player.stopSound("video - desktop");player.stopSound("video - mobile");}catch(_ts){}' +
  'try{if(me._video_desktop.ggApiPlayer)me._video_desktop.ggApiPlayer.pause();}catch(_t1){}' +
  'try{if(me._video_mobile.ggApiPlayer)me._video_mobile.ggApiPlayer.pause();}catch(_t2){}' +
  'var _killVid2=function(){try{if(me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}}catch(_a2){}' +
  'try{if(me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_b2){}};' +
  'try{_killVid2();}catch(_c2){}try{setTimeout(_killVid2,200);}catch(_d2){}try{setTimeout(_killVid2,800);}catch(_e2){}';

const BLOCK_RE = /\/\*GVA-TEASER-HIDE\*\/[\s\S]*?catch\(_e2\)\{\}/g;

function walk(dir, out) {
  let entries; try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch (e) { return out; }
  for (const e of entries) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out); else if (e.name === 'skin.js') out.push(p);
  }
  return out;
}
function matchBrace(t, openIdx) {
  let depth = 0;
  for (let i = openIdx; i < t.length; i++) {
    const c = t[i];
    if (c === '{') depth++;
    else if (c === '}') { depth--; if (depth === 0) return i; }
  }
  return -1;
}

const files = [];
for (const r of roots) walk(r, files);

let changed = 0;
for (const f of files) {
  let t; try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  const before = t;
  // 1) Ganti blok lama (jika ada)
  t = t.replace(BLOCK_RE, BLOCK);
  // 2) Sisipkan ke handler yang belum punya marker
  const re = /me\.([A-Za-z0-9_]+)\.onclick\s*=\s*function\s*\(e[^)]*\)\s*\{/g;
  const inserts = [];
  let m;
  while ((m = re.exec(t)) !== null) {
    const bodyStart = m.index + m[0].length;
    const bodyEnd = matchBrace(t, bodyStart - 1);
    if (bodyEnd < 0) continue;
    const body = t.slice(bodyStart, bodyEnd);
    if (!/setVariableValue\(\s*['"]VB['"]\s*,\s*['"](?:skip|show)['"]\s*\)/.test(body)) continue;
    if (body.indexOf(MARK) !== -1) continue;
    let at = -1;
    const si = body.indexOf('player.stopSound("_main");');
    if (si >= 0) at = bodyStart + si + 'player.stopSound("_main");'.length;
    else { const vi = body.match(/setVariableValue\(\s*['"]VB['"]\s*,\s*['"](?:skip|show)['"]\s*\);/); if (vi) at = bodyStart + vi.index + vi[0].length; }
    if (at >= 0) inserts.push(at);
  }
  inserts.sort((a, b) => b - a);
  for (const at of inserts) t = t.slice(0, at) + BLOCK + t.slice(at);
  if (t === before) { continue; }
  const bak = f + '.preteaserhide.bak';
  try { if (!fs.existsSync(bak)) fs.copyFileSync(f, bak); } catch (e) {}
  fs.writeFileSync(f, t);
  console.log('PATCHED: ' + f);
  changed++;
}
console.log('Done. changed=' + changed);
let synFail = 0;
for (const f of files) { try { execFileSync('node', ['--check', f], { stdio: 'ignore' }); } catch (e) { console.log('SYNTAX FAIL ' + f); synFail++; } }
console.log('Syntax check: ' + synFail + ' fail.');
