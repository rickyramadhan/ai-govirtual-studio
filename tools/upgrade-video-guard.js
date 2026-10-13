// upgrade-video-guard.js — Perkuat FIX-VIDEO-GUARD di skin.js:
// - pakai player.stopSound("video - desktop"/"video - mobile") untuk mematikan
//   audio (bukan hanya pause elemen), plus pause+mute elemen & ggApiPlayer
// - listener 'play' pada kedua elemen + interval 200ms (sampai ~2 menit)
// Idempoten (ubah blok lama -> baru). Khusus Semarang.
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const OLD_RE = /\/\*FIX-VIDEO-GUARD\*\/try\{[\s\S]*?\}catch\(_vg\)\{\}/;
const NEW =
  '/*FIX-VIDEO-GUARD*/try{' +
  'var _isDsk=function(){try{return player.getViewerSize(true).width>=768;}catch(_e){return (window.innerWidth||9999)>=768;}};' +
  'var _killWrong=function(){if(_isDsk()){' +
  'try{player.stopSound("video - mobile");}catch(_s1){}' +
  'try{if(me._video_mobile.ggApiPlayer)me._video_mobile.ggApiPlayer.pause();}catch(_s2){}' +
  'try{if(me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_s3){}' +
  '}else{' +
  'try{player.stopSound("video - desktop");}catch(_s4){}' +
  'try{if(me._video_desktop.ggApiPlayer)me._video_desktop.ggApiPlayer.pause();}catch(_s5){}' +
  'try{if(me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}}catch(_s6){}' +
  '}};' +
  'try{me._video_mobile__vid.addEventListener("play",_killWrong);}catch(_p1){}' +
  'try{me._video_desktop__vid.addEventListener("play",_killWrong);}catch(_p2){}' +
  'try{_killWrong();}catch(_p3){}' +
  'var _gc=0;try{var _gt=setInterval(function(){_killWrong();if(++_gc>600){clearInterval(_gt);}},200);}catch(_g){}' +
  '}catch(_vg2){}';

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

let changed = 0, already = 0, none = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  if (t.indexOf('_vg2') !== -1) { console.log('SKIP (sudah baru): ' + f); already++; continue; }
  if (!OLD_RE.test(t)) { console.log('SKIP (tak ada blok): ' + f); none++; continue; }
  t = t.replace(OLD_RE, NEW);
  fs.writeFileSync(f, t);
  console.log('UPGRADED: ' + f);
  changed++;
}
console.log(`Done. changed=${changed} already=${already} none=${none}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
