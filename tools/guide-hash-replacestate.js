// Ganti location.hash = ... menjadi history.replaceState (tanpa reload/hashchange)
// di guide.js semua lokasi tour semarang. Menghindari reload skin saat navigate di Firefox.
const fs = require('fs');
const path = require('path');

const ROOTS = [
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
  'C:\\xampp\\htdocs\\ai-guide-hub\\storage\\tours',
];

const RULES = [
  ['location.hash = scene.id;', "try { history.replaceState(null, '', '#' + scene.id); } catch (e) {}"],
  ['location.hash = id;', "try { history.replaceState(null, '', '#' + id); } catch (e) {}"],
  ['location.hash = targetScene.id;', "try { history.replaceState(null, '', '#' + targetScene.id); } catch (e) {}"],
];

function walk(dir) {
  let out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out = out.concat(walk(p));
    else if (e.name === 'guide.js' && /\\govirtual-ai\\frontend\\guide\.js$/.test(p)) out.push(p);
  }
  return out;
}

let changed = 0, skipped = 0;
const all = [];
for (const r of ROOTS) all.push(...walk(r));
for (const f of all) {
  let t = fs.readFileSync(f, 'utf8');
  let did = false;
  for (const [old, nw] of RULES) {
    if (t.includes(old)) {
      const cnt = t.split(old).length - 1;
      t = t.split(old).join(nw);
      console.log(`  ${f}: ganti "${old.slice(0,22)}..." x${cnt}`);
      did = true;
    }
  }
  if (!did) { console.log('SKIP (tidak ada target): ' + f); skipped++; continue; }
  fs.writeFileSync(f, t);
  changed++;
}
console.log(`Done. changed=${changed} skipped=${skipped}`);