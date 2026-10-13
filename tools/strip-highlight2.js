// guide.js: hapus sisa "Yang menonjol ..." dari sceneSummary & reply lokasi
// supaya highlight tidak tampil di panel kanan. Storage + semarang + master.
const fs = require('fs');
const path = require('path');
const ROOTS = [
  'C:\\xampp\\htdocs\\ai-guide-hub\\storage\\tours',
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
];
// 1. sceneSummary: hapus "Yang menonjol: ..."
const OLD1 = "if (h) p.push('Yang menonjol: ' + h + '.');";
const NEW1 = "if (false) {}";
// 2. reply lokasi: hapus "Yang menonjol di sini: ..."
const OLD2 = "if (lsi && lsi.highlights && lsi.highlights.length) lr += '\\n\\nYang menonjol di sini: ' + lsi.highlights.slice(0, 3).join('; ') + '.';";
const NEW2 = "// highlight tidak ditampilkan di panel kanan";
function walk(dir, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name === 'guide.js' && /\\govirtual-ai\\frontend\\guide\.js$/.test(p)) out.push(p);
  }
  return out;
}
const files = [];
for (const r of ROOTS) walk(r, files);
let done = 0; const fails = [];
for (const f of files) {
  let t = fs.readFileSync(f, 'utf8');
  let changed = false;
  if (t.includes(OLD1)) { t = t.split(OLD1).join(NEW1); changed = true; }
  if (t.includes(OLD2)) { t = t.split(OLD2).join(NEW2); changed = true; }
  if (!changed) { console.log('SKIP: ' + f); done++; continue; }
  fs.writeFileSync(f, t);
  console.log('PATCH: ' + f);
  done++;
}
console.log(`Done. patched=${done} fail=${fails.length}`);
fails.forEach(x => console.log('  FAIL ' + x));