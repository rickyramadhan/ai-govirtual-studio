// remove-video-guard.js — Hapus blok /*FIX-VIDEO-GUARD*/ dari skin.js.
// Guard ini kini redundan: guide.js (TEASER VIDEO GUARD) sudah meng-unload
// device salah secara event-driven, dan skin punya ggInitMedia('') di cabang
// hidden + FIX-SINGLE-VIDEO + FIX-SKIP-STOP. Blok ini memutar interval 200ms
// hingga 600x (~2 menit) setiap load -> beban tambahan di mobile.
// Idempoten, dengan backup .prevideoguard.bak
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

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

const RE = /\/\*FIX-VIDEO-GUARD\*\/try\{[\s\S]*?\}catch\(_vg2?\)\{\}/g;

let changed = 0, skip = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  if (!t.includes('FIX-VIDEO-GUARD')) { skip++; continue; }
  const n = (t.match(RE) || []).length;
  if (!n) { console.log('SKIP (pola tak cocok): ' + f); skip++; continue; }
  const bak = f + '.prevideoguard.bak';
  try { if (!fs.existsSync(bak)) fs.copyFileSync(f, bak); } catch (e) {}
  t = t.replace(RE, '');
  fs.writeFileSync(f, t);
  console.log('REMOVED (' + n + '): ' + f);
  changed++;
}
console.log(`Done. changed=${changed} skip=${skip}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
