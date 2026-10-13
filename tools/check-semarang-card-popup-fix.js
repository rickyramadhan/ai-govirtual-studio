'use strict';

const fs = require('fs');
const path = require('path');
const cp = require('child_process');

const root = 'C:/xampp/htdocs/semarang';
const tours = [
  'Balai Kota', 'Bandara Ahmad Yani force zoom', 'gereja-santo-yusuf force zoom',
  'greenfreshfarm force zoom', 'kota-lama force zoom', 'masjid-agung-jateng force zoom',
  'Museum Kota Lama force zoom', 'rscolumbia force zoom', 'rstugu force zoom',
  'sam-poo-kong force zoom', 'sandibuana force zoom', 'tawang force zoom',
  'Universitas Negeri Semarang force zoom', 'ziebatik force zoom'
];

let failures = 0;
for (const tour of tours) {
  const guide = path.join(root, tour, 'govirtual-ai/frontend/guide.js');
  const index = path.join(root, tour, 'index.html');
  const js = fs.readFileSync(guide, 'utf8');
  const html = fs.readFileSync(index, 'utf8');
  let syntax = true;
  try { cp.execFileSync(process.execPath, ['--check', guide], { stdio: 'pipe' }); }
  catch (error) { syntax = false; }
  const checks = {
    syntax,
    onePopupSync: js.split('var _allPopupNames =').length - 1 === 1,
    directImageFallback: js.includes('var triedDirect = false;'),
    panoramaFallback: js.includes("navCardThumbFor(sc.id) || panoThumbnailUrl(sc.id, 'Point01')"),
    cacheVersion: html.includes('guide.js?v=2026092806')
  };
  const failed = Object.keys(checks).filter(function (key) { return !checks[key]; });
  if (failed.length) {
    failures++;
    console.log('FAIL ' + tour + ': ' + failed.join(', '));
  } else {
    console.log('OK ' + tour);
  }
}
console.log('failures=' + failures);
process.exitCode = failures ? 1 : 0;
