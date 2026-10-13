// fix-proxy-double.js — Hapus pemanggilan ganda pada listener hsproxyclick:
// blok `if(event.id=='button'){ me._yes.onclick(); }` yang langsung diikuti
// `if(event.id=='button'){ me._no.onclick(); }` -> sisakan yang _yes saja.
// Idempoten. Khusus Semarang.
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

const RE = /(if\s*\(event\.id=='button'\)\s*\{\s*me\._yes\.onclick\(\);\s*\}\s*)if\s*\(event\.id=='button'\)\s*\{\s*me\._no\.onclick\(\);\s*\}/;

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

let changed = 0, already = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  if (!RE.test(t)) { console.log('SKIP (sudah/ tak cocok): ' + f); already++; continue; }
  t = t.replace(RE, '$1');
  fs.writeFileSync(f, t);
  console.log('FIXED: ' + f);
  changed++;
}
console.log(`Done. changed=${changed} skip=${already}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
