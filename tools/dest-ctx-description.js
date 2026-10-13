// guide.js (destinationContext) — jawaban "Kemana Berikutnya" di panel kanan:
//  1) hapus baris "Bisa ditanyakan: ..."
//  2) pakai Deskripsi lengkap (description), bukan shortDescription.
// Berlaku storage source + semarang + master.
const fs = require('fs');
const path = require('path');
const ROOTS = [
  'C:\\xampp\\htdocs\\ai-guide-hub\\storage\\tours',
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
];
const OLD =
  "    var desc = (si && (si.shortDescription || si.description)) || sc.description || '';\n" +
  "    if (desc) parts.push(desc);\n" +
  "    // highlight sengaja tidak ditampilkan di panel kanan (permintaan: hapus \"Yang menonjol\")\n" +
  "    if (si && si.suggestedQuestions && si.suggestedQuestions.length) parts.push('Bisa ditanyakan: ' + sqQ(si.suggestedQuestions[0]));";
const NEW =
  "    // Deskripsi lengkap (untuk guide), bukan short description\n" +
  "    var desc = (si && si.description) || sc.description || '';\n" +
  "    if (desc) parts.push(desc);\n" +
  "    // highlight & \"Bisa ditanyakan\" sengaja tidak ditampilkan di panel kanan";

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
  if (t.includes('Deskripsi lengkap (untuk guide), bukan short description')) { console.log('SKIP (sudah): ' + f); done++; continue; }
  if (!t.includes(OLD)) { fails.push(f); continue; }
  fs.writeFileSync(f, t.split(OLD).join(NEW));
  console.log('PATCH: ' + f);
  done++;
}
console.log(`Done. patched=${done} fail=${fails.length}`);
fails.forEach(x => console.log('  FAIL ' + x));