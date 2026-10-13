// Pindahkan referensi teaser-fix.js ke posisi AWAL (sebelum skin instance dibuat).
// Proses: hapus semua baris yang mengandung teaser-fix.js, lalu sisipkan
// <script src="../teaser-fix.js"></script> tepat setelah penutup skin.js.
// Idempoten.
const fs = require('fs');
const path = require('path');

const root = 'C:\\xampp\\htdocs\\semarang';
const MARKER = 'teaser-fix.js';
const SKIN_CLOSE = '</script>'; // penutup skin.js

function walk(dir) {
  let out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out = out.concat(walk(p));
    else if (e.name === 'index.html') out.push(p);
  }
  return out;
}

let updated = 0, skipped = 0;
for (const f of walk(root)) {
  let t = fs.readFileSync(f, 'utf8');
  if (!t.includes(MARKER)) { console.log('SKIP (no marker): ' + f); skipped++; continue; }
  const skinPos = t.search(/src=["']skin\.js/);
  if (skinPos === -1) { console.log('SKIP (skin.js tidak ada): ' + f); skipped++; continue; }
  if (t.indexOf(MARKER) < skinPos) { console.log('SKIP (sudah di posisi awal): ' + f); skipped++; continue; }

  // 1. hapus semua baris berisi teaser-fix.js
  const lines = t.split('\n').filter(l => l.indexOf(MARKER) === -1);

  // 2. sisipkan setelah penutup skin.js (baris </script> yang mengikuti skin.js)
  let skinIdx = -1;
  for (let i = 0; i < lines.length; i++) {
    if (/src=["']skin\.js/.test(lines[i])) { skinIdx = i; break; }
  }
  if (skinIdx === -1) { console.log('SKIP (skin.js tidak ditemukan): ' + f); skipped++; continue; }
  // cari baris </script> setelah skinIdx
  let closeIdx = -1;
  for (let i = skinIdx; i < lines.length; i++) {
    if (lines[i].indexOf('</script>') !== -1) { closeIdx = i; break; }
  }
  if (closeIdx === -1) { console.log('SKIP (penutup skin.js tidak ketemu): ' + f); skipped++; continue; }

  const insert = '\t\t<script src="../teaser-fix.js"></script>';
  lines.splice(closeIdx + 1, 0, insert);
  fs.writeFileSync(f, lines.join('\n'));
  console.log('MOVED: ' + f);
  updated++;
}
console.log(`Done. moved=${updated} skipped=${skipped}`);