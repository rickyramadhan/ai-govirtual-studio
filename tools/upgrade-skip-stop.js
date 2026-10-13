// upgrade-skip-stop.js — Perkuat blok FIX-SKIP-STOP di semua skin.js:
// stop sound desktop+mobile, pause ggApiPlayer (jika ada), pause+mute+reset
// elemen video, lalu ulangi pengecekan (200ms & 800ms) untuk jaga-jaga bila
// player mengulang. Idempoten (replace blok lama -> baru). Semarang saja.
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const OLD =
  '/*FIX-SKIP-STOP*/player.stopSound("video - desktop");player.stopSound("video - mobile");' +
  'try{if(me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}}catch(_se){}' +
  'try{if(me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_se2){}';

const NEW =
  '/*FIX-SKIP-STOP*/player.stopSound("video - desktop");player.stopSound("video - mobile");' +
  'try{if(me._video_desktop.ggApiPlayer)me._video_desktop.ggApiPlayer.pause();}catch(_g1){}' +
  'try{if(me._video_mobile.ggApiPlayer)me._video_mobile.ggApiPlayer.pause();}catch(_g2){}' +
  'var _killVid=function(){try{if(me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;me._video_desktop__vid.currentTime=0;}}catch(_a){}' +
  'try{if(me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;me._video_mobile__vid.currentTime=0;}}catch(_b){}};' +
  'try{_killVid();}catch(_c){}' +
  'try{setTimeout(_killVid,200);}catch(_d){}' +
  'try{setTimeout(_killVid,800);}catch(_e){}';

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
  if (!t.includes('FIX-SKIP-STOP')) { console.log('SKIP (tanpa marker): ' + f); none++; continue; }
  if (!t.includes(OLD)) { console.log('SKIP (sudah versi baru): ' + f); already++; continue; }
  const n = t.split(OLD).length - 1;
  t = t.split(OLD).join(NEW);
  fs.writeFileSync(f, t);
  console.log('UPGRADED (' + n + ' blok): ' + f);
  changed++;
}
console.log(`Done. changed=${changed} already=${already} none=${none}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
