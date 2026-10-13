// add-video-volume-guard.js — Tambahkan player.changeVolume(...,0) ke guard
// untuk device yang salah (desktop<->mobile) di dalam FIX-VIDEO-GUARD. Idempoten.
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const FROM_M = 'try{player.stopSound("video - mobile");}catch(_s1){}';
const TO_M = 'try{player.stopSound("video - mobile");player.changeVolume("video - mobile",0.0);}catch(_s1){}';
const FROM_D = 'try{player.stopSound("video - desktop");}catch(_s4){}';
const TO_D = 'try{player.stopSound("video - desktop");player.changeVolume("video - desktop",0.0);}catch(_s4){}';

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

let changed = 0, skip = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  if (!t.includes('FIX-VIDEO-GUARD')) { console.log('SKIP (tanpa guard): ' + f); skip++; continue; }
  if (t.includes('player.changeVolume("video - mobile",0.0)')) { console.log('SKIP (sudah): ' + f); skip++; continue; }
  t = t.split(FROM_M).join(TO_M).split(FROM_D).join(TO_D);
  fs.writeFileSync(f, t);
  console.log('UPDATED: ' + f);
  changed++;
}
console.log(`Done. changed=${changed} skip=${skip}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
