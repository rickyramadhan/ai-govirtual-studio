// attach-video-guard.js — Pasang guard pada elemen video teaser di skin.js:
// listener 'play' + interval singkat yang mem-pause+mute video device yang
// TIDAK aktif (desktop <-> mobile) berdasarkan lebar viewer (>=768 desktop).
// Menutup semua jalur pemutaran: _yes.onclick, _no.onclick, dan autoplay.
// Idempoten (marker FIX-VIDEO-GUARD). Khusus Semarang.
// Pakai: node tools/attach-video-guard.js [root...]
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const MARK = 'FIX-VIDEO-GUARD';
const ANCHOR = 'me.__2video_teaser.appendChild(me._video_mobile);';
const GUARD =
  '/*FIX-VIDEO-GUARD*/try{' +
  'var _isDsk=function(){try{return player.getViewerSize(true).width>=768;}catch(_e){return window.innerWidth>=768;}};' +
  'var _killWrong=function(){if(_isDsk()){try{if(me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_a){}}' +
  'else{try{if(me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}}catch(_b){}}};' +
  'try{me._video_desktop__vid.addEventListener("play",function(){if(!_isDsk()){try{this.pause();this.muted=true;}catch(_c){}}});}catch(_d){}' +
  'try{me._video_mobile__vid.addEventListener("play",function(){if(_isDsk()){try{this.pause();this.muted=true;}catch(_e2){}}});}catch(_f){}' +
  'var _gc=0;try{var _gt=setInterval(function(){_killWrong();if(++_gc>80){clearInterval(_gt);}},400);}catch(_g){}' +
  '}catch(_vg){}';

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

let patched = 0, already = 0, noAnchor = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  if (t.includes(MARK)) { console.log('SKIP (sudah): ' + f); already++; continue; }
  const ai = t.indexOf(ANCHOR);
  if (ai < 0) { console.log('SKIP (tanpa anchor): ' + f); noAnchor++; continue; }
  const at = ai + ANCHOR.length;
  const bak = f + '.presingleguard.bak';
  try { if (!fs.existsSync(bak)) fs.copyFileSync(f, bak); } catch (e) {}
  t = t.slice(0, at) + GUARD + t.slice(at);
  fs.writeFileSync(f, t);
  console.log('PATCHED: ' + f);
  patched++;
}
console.log(`Done. patched=${patched} already=${already} noAnchor=${noAnchor}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
