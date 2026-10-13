// guide.js: hapus bagian "Yang menonjol: ..." (dari field highlight AI Studio)
// yang ditambahkan destinationContext ke body jawaban "Kemana Berikutnya" (panel kanan).
// Berlaku storage source (ai-guide-hub) + deployed (semarang) + master (govirtual-ai).
const fs = require('fs');
const path = require('path');
const ROOTS = [
  'C:\\xampp\\htdocs\\ai-guide-hub\\storage\\tours',
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
];
const OLD = "    if (si && si.highlights && si.highlights.length) parts.push('Yang menonjol: ' + si.highlights.slice(0, 2).join('; ') + '.');";
const NEW = "    // highlight sengaja tidak ditampilkan di panel kanan (permintaan: hapus \"Yang menonjol\")";
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
  if (t.includes('highlight sengaja tidak ditampilkan')) { console.log('SKIP (sudah): ' + f); done++; continue; }
  if (!t.includes(OLD)) { fails.push(f); continue; }
  fs.writeFileSync(f, t.split(OLD).join(NEW));
  console.log('PATCH: ' + f);
  done++;
}
console.log(`Done. patched=${done} fail=${fails.length}`);
fails.forEach(x => console.log('  FAIL ' + x));