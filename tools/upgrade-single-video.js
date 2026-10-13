// upgrade-single-video.js — Ganti blok FIX-SINGLE-VIDEO dengan versi berbasis
// lebar viewport yang sama seperti Pano2VR (>=768 desktop, <768 mobile):
// pause+mute video device yang tidak aktif, dengan pengecekan berulang.
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const OLD =
  '/*FIX-SINGLE-VIDEO*/try{var _isH=function(el){return !el||el.ggVisible===false||(el.style&&el.style.visibility==="hidden");};' +
  'if(_isH(me._video_desktop)&&me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}' +
  'if(_isH(me._video_mobile)&&me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_sv){}';

const NEW =
  '/*FIX-SINGLE-VIDEO*/try{' +
  'var _enforceOne=function(){' +
  'var _w=(typeof player!=="undefined"&&player.getViewerSize)?player.getViewerSize(true).width:window.innerWidth;' +
  'var _dsk=(_w>=768);' +
  'try{if(!_dsk&&me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}}catch(_x){}' +
  'try{if(_dsk&&me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_y){}' +
  'try{if(!_dsk&&me._video_desktop.ggApiPlayer)me._video_desktop.ggApiPlayer.pause();}catch(_z){}' +
  'try{if(_dsk&&me._video_mobile.ggApiPlayer)me._video_mobile.ggApiPlayer.pause();}catch(_w2){}' +
  '};_enforceOne();try{setTimeout(_enforceOne,150);}catch(_q){}try{setTimeout(_enforceOne,600);}catch(_q2){}' +
  '}catch(_sv){}';

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
  if (!t.includes('FIX-SINGLE-VIDEO')) { console.log('SKIP (tanpa marker): ' + f); none++; continue; }
  if (!t.includes(OLD)) { console.log('SKIP (sudah versi baru): ' + f); already++; continue; }
  t = t.split(OLD).join(NEW);
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
