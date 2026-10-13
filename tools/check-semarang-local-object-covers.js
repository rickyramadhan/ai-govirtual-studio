'use strict';

const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const root = 'C:\\xampp\\htdocs\\semarang';
const tours = fs.readdirSync(root, { withFileTypes: true })
  .filter(entry => entry.isDirectory())
  .map(entry => entry.name)
  .filter(name => fs.existsSync(path.join(root, name, 'govirtual-ai', 'frontend', 'guide.js'))
    && fs.existsSync(path.join(root, name, 'govirtual-ai', 'data', 'knowledge.json'))
    && fs.existsSync(path.join(root, name, 'index.html')));
// Verified against WebView SQL and location image folders: no matching source PNG.
const sourceExceptions = new Set([
  'hoteltentrem-260909:452',
  'loempialien:2235', 'loempialien:2237',
  'masjidkauman-260909:1081',
  'rshermina-260910:1195', 'rshermina-260910:1196', 'rshermina-260910:1197',
  'unika-260911:1085', 'unika-260911:1086', 'unika-260911:1093'
]);
let failures = 0;
let files = 0;
let sourceMissing = 0;

for (const tour of tours) {
  const base = path.join(root, tour);
  const guidePath = path.join(base, 'govirtual-ai', 'frontend', 'guide.js');
  const htmlPath = path.join(base, 'index.html');
  const knowledgePath = path.join(base, 'govirtual-ai', 'data', 'knowledge.json');
  const guide = fs.readFileSync(guidePath, 'utf8');
  const html = fs.readFileSync(htmlPath, 'utf8');
  const knowledge = JSON.parse(fs.readFileSync(knowledgePath, 'utf8'));
  const ids = new Set();
  for (const entry of knowledge.entries || []) {
    const match = /objinfo2:(\d+)/.exec(String(entry.id || entry.entityId || ''));
    if (match) ids.add(match[1]);
  }
  const missing = [];
  for (const id of ids) {
    const file = path.join(base, 'govirtual-ai', 'frontend', 'assets', 'object-covers', id + '.webp');
    if (!fs.existsSync(file) || fs.statSync(file).size < 100) missing.push(id);
    else files++;
  }
  const unexpectedMissing = missing.filter(id => !sourceExceptions.has(tour + ':' + id));
  sourceMissing += missing.length;
  const checks = {
    syntax: cp.spawnSync(process.execPath, ['--check', guidePath]).status === 0,
    localPath: guide.includes("frontend/assets/object-covers/"),
    noRuntimeResolver: !guide.includes("fetch(basePath + 'api/objinfo-cover.php?info='"),
    noRuntimeProxy: !guide.includes("img.src = proxyImg(") && !guide.includes("im.src = proxyImg("),
    catalogCompat: guide.includes('data.objects = Array.isArray(data.objectInfo)'),
    objectFocus: guide.includes("var resolved = resolveObjectScene(o.infoId);"),
    noDiscoverInObjectPanels: !guide.includes('// Secondary source: explore-menu entities mapped to this scene.')
      && !guide.includes('// Explore entities as info'),
    cacheVersion: html.includes('guide.js?v=2026100103'),
    assets: unexpectedMissing.length === 0
  };
  const failed = Object.keys(checks).filter(key => !checks[key]);
  if (failed.length) {
    failures++;
    console.error('FAIL ' + tour + ': ' + failed.join(',') + ' missing=' + unexpectedMissing.join(','));
  } else {
    console.log('OK ' + tour + ': ids=' + ids.size + ' local=' + (ids.size - missing.length) + ' source-without-cover=' + missing.length);
  }
}
console.log('files=' + files + ' sourceMissing=' + sourceMissing + ' failures=' + failures);
process.exitCode = failures ? 1 : 0;
