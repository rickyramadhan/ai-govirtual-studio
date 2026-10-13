// fix-menu-anim.js — Hilangkan animasi "turun/fade" pada menu bawah/atas skin
// mobile Pano2VR. Menu tetap tampil (mode Pano2VR normal) tetapi muncul langsung
// tanpa transisi, sehingga tidak terlihat "turun" saat intro/Explore.
// Idempoten. Pakai: node tools/fix-menu-anim.js [root...]
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const roots = process.argv.slice(2);
if (!roots.length) roots.push('C:\\xampp\\htdocs\\semarang');

function walk(dir, out) {
  let entries; try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch (e) { return out; }
  for (const e of entries) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out); else if (e.name === 'skin.js') out.push(p);
  }
  return out;
}

const ELS = ['_bottom_menu_mobile', '_top_menu_mobile', '_shadow_bottom', '_bottom_menu', '_top_menu'];
const files = [];
for (const r of roots) walk(r, files);
let changed = 0;
for (const f of files) {
  let t; try { t = fs.readFileSync(f, 'utf8'); } catch (e) { continue; }
  const before = t;
  for (const el of ELS) {
    // Ganti semua assignment transition pada elemen menu -> 'none'
    const re = new RegExp('me\\.' + el + "\\.style\\.transition='[^']*';", 'g');
    t = t.replace(re, "me." + el + ".style.transition='none';");
  }
  if (t === before) continue;
  const bak = f + '.premenuanim.bak';
  try { if (!fs.existsSync(bak)) fs.copyFileSync(f, bak); } catch (e) {}
  fs.writeFileSync(f, t);
  console.log('PATCHED: ' + f);
  changed++;
}
console.log('Done. changed=' + changed);
let synFail = 0;
for (const f of files) { try { execFileSync('node', ['--check', f], { stdio: 'ignore' }); } catch (e) { console.log('SYNTAX FAIL ' + f); synFail++; } }
console.log('Syntax check: ' + synFail + ' fail.');
