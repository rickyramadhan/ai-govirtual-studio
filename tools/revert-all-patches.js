// Revert semua patch teaser-fix & guide.js ke versi awal:
// 1. Hapus baris <script src="../teaser-fix.js"></script> dari index.html.
// 2. Revert ttsPickVoice (skor voice) di guide.js ke versi asli.
// 3. Revert history.replaceState kembali ke location.hash di guide.js.
const fs = require('fs');
const path = require('path');
const root = 'C:\\xampp\\htdocs\\semarang';

// ---- 1. index.html: hapus baris teaser-fix.js ----
function walkIndex(dir) {
  let out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out = out.concat(walkIndex(p));
    else if (e.name === 'index.html') out.push(p);
  }
  return out;
}
let idxReverted = 0;
for (const f of walkIndex(root)) {
  let t = fs.readFileSync(f, 'utf8');
  if (!t.includes('teaser-fix.js')) continue;
  const lines = t.split('\n').filter(l => l.indexOf('teaser-fix.js') === -1);
  fs.writeFileSync(f, lines.join('\n'));
  console.log('INDEX revert: ' + f);
  idxReverted++;
}

// ---- 2 & 3. guide.js: revert ttsPickVoice + location.hash ----
function walkGuide(dir) {
  let out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out = out.concat(walkGuide(p));
    else if (e.name === 'guide.js' && /\\govirtual-ai\\frontend\\guide\.js$/.test(p)) out.push(p);
  }
  return out;
}

const VOICE_NEW = [
  "        if (/^id([-_]|$)/i.test(v.lang || '')) s += 70;",
  "        else if (/indonesia/i.test(n)) s += 60;",
  "        if (/natural|neural|online|google|premium|wavenet/i.test(n)) s += 20;",
  "        if (/google/i.test(n) && /^id([-_]|$)/i.test(v.lang || '')) s += 30;",
  "        if (/female|woman|girl|gadis|perempuan|wanita|damayanti|sari|dewi|kartini|ratna|maya|arum|indah|meilani|zira|samantha|aria|jenny|sonia|imelda|keira|meimei|michelle|ava|emma|hazel|libby|susan|karen|natasha/i.test(n)) s += 45;",
  "        if (/\\bmale\\b|\\bman\\b|boy|pria|laki|ardi\\b|david|mark|daniel|budi|andika|harry|michael|george|oliver|james|jacob/i.test(n)) s -= 20;",
].join('\n');
const VOICE_OLD = [
  "        if (/^id([-_]|$)/i.test(v.lang || '')) s += 50;",
  "        else if (/indonesia/i.test(n)) s += 40;",
  "        if (/natural|neural|online|google|premium|wavenet/i.test(n)) s += 20;",
  "        if (/female|woman|girl|gadis|perempuan|zira|samantha|aria|jenny|sonia|imelda|keira|meimei/i.test(n)) s += 30;",
  "        if (/\\bmale\\b|\\bman\\b|boy|pria|laki|ardi\\b|david|mark|daniel|budi/i.test(n)) s -= 40;",
].join('\n');

const HASH_RULES = [
  ["try { history.replaceState(null, '', '#' + scene.id); } catch (e) {}", "location.hash = scene.id;"],
  ["try { history.replaceState(null, '', '#' + id); } catch (e) {}", "location.hash = id;"],
  ["try { history.replaceState(null, '', '#' + targetScene.id); } catch (e) {}", "location.hash = targetScene.id;"],
];

let guideReverted = 0;
for (const f of walkGuide(root)) {
  let t = fs.readFileSync(f, 'utf8');
  let changed = false;
  if (t.includes(VOICE_NEW)) { t = t.replace(VOICE_NEW, VOICE_OLD); changed = true; }
  for (const [nw, old] of HASH_RULES) {
    if (t.includes(nw)) { t = t.split(nw).join(old); changed = true; }
  }
  if (changed) { fs.writeFileSync(f, t); console.log('GUIDE revert: ' + f); guideReverted++; }
  else console.log('GUIDE SKIP (sudah asli): ' + f);
}
console.log(`Done. indexReverted=${idxReverted} guideReverted=${guideReverted}`);