// guide.js: config webview generik — objectInfoDetailParam, objectInfoExtra, langMap.
// default = perilaku Semarang sekarang ('info', '&vd=1&tp=m', id/en/sa/zh).
const fs = require('fs');
const path = require('path');
const ROOTS = [
  'C:\\xampp\\htdocs\\ai-guide-hub\\storage\\tours',
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
];

// 1. config vars
const A_OLD =
  "  var objectInfoPage = cfg.objectInfoPage || cfgFrontend.objectInfoPage || '';\n" +
  "  var data = window.GoVirtualTourData;";
const A_NEW =
  "  var objectInfoPage = cfg.objectInfoPage || cfgFrontend.objectInfoPage || '';\n" +
  "  var objectInfoDetailParam = cfg.objectInfoDetailParam || cfgFrontend.objectInfoDetailParam || 'info';\n" +
  "  var objectInfoExtra = cfg.objectInfoExtra || cfgFrontend.objectInfoExtra || '&vd=1&tp=m';\n" +
  "  var langMap = cfg.langMap || cfgFrontend.langMap || { id: 'id', en: 'en', sa: 'sa', zh: 'zh' };\n" +
  "  var data = window.GoVirtualTourData;";

// 2. deteksi bahasa nl
const B_OLD = "        if (l.indexOf('en') !== -1) lang = 'en';\n        else if (l.indexOf('ar') !== -1) lang = 'sa';";
const B_NEW = "        if (l.indexOf('en') !== -1) lang = 'en';\n        else if (l.indexOf('nl') !== -1) lang = 'nl';\n        else if (l.indexOf('ar') !== -1) lang = 'sa';";

// 3. URL pakai param/extra/langMap
const C_OLD = "        var src = objectInfoPage + '?info=' + encodeURIComponent(infoId) + '&lang=' + lang + '&vd=1&tp=m';";
const C_NEW = "        var langIso = langMap[lang] || lang;\n        var src = objectInfoPage + '?' + objectInfoDetailParam + '=' + encodeURIComponent(infoId) + '&lang=' + langIso + objectInfoExtra;";

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
  if (t.includes('objectInfoDetailParam = cfg.objectInfoDetailParam')) { console.log('SKIP (sudah): ' + f); done++; continue; }
  const rules = [[A_OLD, A_NEW], [B_OLD, B_NEW], [C_OLD, C_NEW]];
  let ok = true;
  for (const [o, n] of rules) {
    if (!t.includes(o)) { fails.push(f + ' (missing: ' + o.slice(0, 50) + ')'); ok = false; break; }
    t = t.split(o).join(n);
  }
  if (!ok) continue;
  fs.writeFileSync(f, t);
  console.log('PATCH: ' + f);
  done++;
}
console.log(`Done. patched=${done} fail=${fails.length}`);
fails.forEach(x => console.log('  FAIL ' + x));