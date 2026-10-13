// fix-hidden-video.js — Samakan dengan Lawang Sewu: panggil ggInitMedia('')
// pada cabang HIDDEN di _video_desktop/_video_mobile.logicBlock_visible,
// supaya video device yang tidak aktif benar-benar di-unload (berhenti).
// Idempoten. Pakai: node tools/fix-hidden-video.js [root...]
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

function apply(t, dev) {
  // Jika sudah ada ggInitMedia('') untuk dev ini, lewati.
  if (t.includes('me.' + dev + '.ggInitMedia(\'\')')) return { t, done: false };
  const re = new RegExp(
    '(me\\.' + dev + '\\.style\\.visibility\\s*=\\s*"hidden";)(\\s*)(me\\.' + dev + '\\.ggVisible\\s*=\\s*false;)',
    'g'
  );
  if (!re.test(t)) return { t, done: false };
  return { t: t.replace(re, '$1$2me.' + dev + ".ggInitMedia('');$2$3"), done: true };
}

let changed = 0, skip = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  const before = t;
  const rd = apply(t, '_video_desktop'); t = rd.t;
  const rm = apply(t, '_video_mobile'); t = rm.t;
  if (!rd.done && !rm.done) { console.log('SKIP: ' + f); skip++; continue; }
  const bak = f + '.prehiddenvideo.bak';
  try { if (!fs.existsSync(bak)) fs.copyFileSync(f, bak); } catch (e) {}
  fs.writeFileSync(f, t);
  console.log('PATCHED: ' + f + (rd.done ? ' [desktop]' : '') + (rm.done ? ' [mobile]' : ''));
  changed++;
}
console.log(`Done. changed=${changed} skip=${skip}`);
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
