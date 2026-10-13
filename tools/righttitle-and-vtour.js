// guide.js: 
//  1) right-title menampilkan salinan pertanyaan yang diklik (_lastQuestion).
//  2) hapus tombol "Virtual Tour" dari right-actions (DESTINATION_DISCOVERY).
// Berlaku untuk storage source (ai-guide-hub) + deployed (semarang).
const fs = require('fs');
const path = require('path');

const ROOTS = [
  'C:\\xampp\\htdocs\\ai-guide-hub\\storage\\tours',
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
];

// A: state _lastQuestion
const A_OLD = '  var _dynamicPrompts = [];';
const A_NEW = '  var _dynamicPrompts = [];\n  var _lastQuestion = \'\';';

// B: set _lastQuestion saat submit
const B_OLD = '    lastSubmitted = { text: query, at: now };';
const B_NEW = '    lastSubmitted = { text: query, at: now };\n    _lastQuestion = query;';

// C: right-title = pertanyaan (fallback presentation.title)
const C_OLD = "    d.rightTitle.textContent = presentation.title || '';";
const C_NEW = "    d.rightTitle.textContent = _lastQuestion || presentation.title || '';";

// D: hapus tombol Virtual Tour
const D_OLD = "actions: dests.filter(function (d) { return d.virtualTourAvailable; }).slice(0, 2).map(function (d) { return { label: 'Virtual Tour', url: d.virtualTourUrl }; })";
const D_NEW = 'actions: []';

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
  if (t.includes('_lastQuestion')) { console.log('SKIP (sudah): ' + f); done++; continue; }
  const rules = [[A_OLD, A_NEW], [B_OLD, B_NEW], [C_OLD, C_NEW], [D_OLD, D_NEW]];
  let ok = true;
  for (const [o, n] of rules) {
    if (!t.includes(o)) { fails.push(f + ' (missing: ' + o.slice(0, 40) + ')'); ok = false; break; }
    t = t.split(o).join(n);
  }
  if (!ok) continue;
  fs.writeFileSync(f, t);
  console.log('PATCH: ' + f);
  done++;
}
console.log(`Done. patched=${done} fail=${fails.length}`);
fails.forEach(x => console.log('  FAIL ' + x));