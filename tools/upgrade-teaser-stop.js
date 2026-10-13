// Upgrade FIX-TEASER-STOP blok di semua skin.js semarang.
// Mengganti blok marker lama dengan versi yang lebih kuat:
// pause + mute + reset SEMUA <video> di seluruh document (bukan hanya dalam __2video_teaser).
// Idempoten: file tanpa marker lama akan di-skip.
const fs = require('fs');
const path = require('path');

const root = 'C:\\xampp\\htdocs\\semarang';

const NEW_BLOCK =
  '/*FIX-TEASER-STOP*/player.stopSound("video - desktop");player.stopSound("video - mobile");' +
  'try{if(me._video_desktop__vid){me._video_desktop__vid.pause();me._video_desktop__vid.muted=true;}}catch(_se){}' +
  'try{if(me._video_mobile__vid){me._video_mobile__vid.pause();me._video_mobile__vid.muted=true;}}catch(_se2){}' +
  'try{var _allv=document.querySelectorAll("video");for(var _vi=0;_vi<_allv.length;_vi++){try{_allv[_vi].pause();_allv[_vi].muted=true;_allv[_vi].currentTime=0;}catch(_e){}}}catch(_se3){}';

// regex untuk blok marker lama: dimulai /*FIX-TEASER-STOP*/ dan berakhir sampai catch(_se3){}
const OLD_RE = /\/\*FIX-TEASER-STOP\*\/.*?catch\(_se3\)\{\}/s;

function walk(dir) {
  let out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out = out.concat(walk(p));
    else if (e.name === 'skin.js') out.push(p);
  }
  return out;
}

const files = walk(root);
let upgraded = 0, skipped = 0, fail = 0;
for (const f of files) {
  let t;
  try { t = fs.readFileSync(f, 'utf8'); } catch (e) { console.log('READ FAIL', f, e.message); fail++; continue; }
  if (!t.includes('FIX-TEASER-STOP')) { console.log('SKIP (no marker): ' + f); skipped++; continue; }
  const m = t.match(OLD_RE);
  if (!m) { console.log('SKIP (no old block match): ' + f); skipped++; continue; }
  const bak = f + '.fix2.bak';
  if (!fs.existsSync(bak)) fs.copyFileSync(f, bak);
  const t2 = t.replace(OLD_RE, NEW_BLOCK);
  fs.writeFileSync(f, t2);
  console.log('UPGRADED: ' + f);
  upgraded++;
}
console.log(`Done. upgraded=${upgraded} skipped=${skipped} fail=${fail}`);

// syntax check via node
const { execSync } = require('child_process');
let synFail = 0;
for (const f of files) {
  try { execSync(`node --check "${f}"`, { stdio: 'ignore' }); }
  catch (e) { console.log('SYNTAX FAIL: ' + f); synFail++; }
}
console.log(`Syntax check: ${synFail} fail.`);
