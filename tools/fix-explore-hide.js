// fix-explore-hide.js — Khusus tombol yang MEMULAI tour mandiri/skip, sembunyikan
// overlay teaser (yang pada skin ini tetap jalan) + matikan video, agar menu
// bawah skin tidak tampak "turun" di belakang teaser.
// Handler target: _no_mobile, _no0 (Explore Virtual Tour), _skip_mobile (Lewati).
// Tidak menyentuh handler "Start Virtual Buddy"/Yes yang memang menampilkan teaser.
// Idempoten (marker GVA-TEASER-HIDE). Pakai: node tools/fix-explore-hide.js [root...]
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');
const TARGETS = ['_no_mobile', '_no0', '_skip_mobile'];
const MARK = 'GVA-TEASER-HIDE';
const BLOCK =
  '/*' + MARK + '*/' +
  'var _gvaHideTeaser=function(){try{me.__2video_teaser.style.transition=\'none\';}catch(_h0){}' +
  'try{me.__2video_teaser.style.display=\'none\';}catch(_h1){}' +
  'try{me.__2video_teaser.style.visibility=\'hidden\';me.__2video_teaser.ggVisible=false;}catch(_h2){}};' +
  'try{_gvaHideTeaser();}catch(_h3){}try{setTimeout(_gvaHideTeaser,150);}catch(_h4){}try{setTimeout(_gvaHideTeaser,700);}catch(_h5){}' +
  'try{var _gvaHC=0;var _gvaHT=setInterval(function(){_gvaHideTeaser();if(++_gvaHC>40){clearInterval(_gvaHT);}},150);}catch(_h6){}' +
  'try{player.stopSound("video - desktop");player.stopSound("video - mobile");}catch(_h7){}' +
  'try{if(me._video_desktop.ggApiPlayer)me._video_desktop.ggApiPlayer.pause();}catch(_h8){}' +
  'try{if(me._video_mobile.ggApiPlayer)me._video_mobile.ggApiPlayer.pause();}catch(_h9){}' +
  'var _gvaKillVid=function(){try{if(me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}}catch(_k0){}' +
  'try{if(me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_k1){}};' +
  'try{_gvaKillVid();}catch(_k2){}try{setTimeout(_gvaKillVid,200);}catch(_k3){}try{setTimeout(_gvaKillVid,800);}catch(_k4){}';
const BLOCK_RE = /\/\*GVA-TEASER-HIDE\*\/[\s\S]*?catch\(_k4\)\{\}/g;

function walk(dir, out) {
  let entries; try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch (e) { return out; }
  for (const e of entries) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out); else if (e.name === 'skin.js') out.push(p);
  }
  return out;
}
function matchBrace(t, openIdx) { let d = 0; for (let i = openIdx; i < t.length; i++) { const c = t[i]; if (c === '{') d++; else if (c === '}') { d--; if (d === 0) return i; } } return -1; }

const files = [];
for (const r of roots) walk(r, files);
let changed = 0;
for (const f of files) {
  let t; try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  const before = t;
  t = t.replace(BLOCK_RE, BLOCK); // update versi lama bila ada
  for (const name of TARGETS) {
    const re = new RegExp('me\\.' + name + '\\.onclick\\s*=\\s*function\\s*\\(e[^)]*\\)\\s*\\{', 'g');
    const m = re.exec(t);
    if (!m) continue;
    const bodyStart = m.index + m[0].length;
    const bodyEnd = matchBrace(t, bodyStart - 1);
    if (bodyEnd < 0) continue;
    const body = t.slice(bodyStart, bodyEnd);
    if (body.indexOf(MARK) !== -1) continue;
    let at = -1;
    const si = body.indexOf('player.stopSound("_main");');
    if (si >= 0) at = bodyStart + si + 'player.stopSound("_main");'.length;
    else { const vb = body.match(/setVariableValue\(\s*['"]VB['"]\s*,\s*['"][^'"]+['"]\s*\);/); if (vb) at = bodyStart + vb.index + vb[0].length; }
    if (at < 0) at = bodyStart;
    t = t.slice(0, at) + BLOCK + t.slice(at);
  }
  if (t === before) continue;
  const bak = f + '.preexplorehide.bak';
  try { if (!fs.existsSync(bak)) fs.copyFileSync(f, bak); } catch (e) {}
  fs.writeFileSync(f, t);
  console.log('PATCHED: ' + f);
  changed++;
}
console.log('Done. changed=' + changed);
let synFail = 0;
for (const f of files) { try { execFileSync('node', ['--check', f], { stdio: 'ignore' }); } catch (e) { console.log('SYNTAX FAIL ' + f); synFail++; } }
console.log('Syntax check: ' + synFail + ' fail.');
