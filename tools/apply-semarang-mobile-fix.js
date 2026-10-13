'use strict';

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..', 'semarang');
const version = '2026092804';
const changed = [];

function updateAsset(text, file) {
  const escaped = file.replace(/[.*+?^${}()|[\\]\\]/g, '\\$&');
  const pattern = new RegExp('(["\\x27])' + escaped + '(?:\\?[^"\\x27\\s>]*)?', 'g');
  return text.replace(pattern, '$1' + file + '?v=' + version);
}

function updateGuide(text) {
  const menuStart = text.indexOf('    // Mobile: hilangkan animasi');
  if (menuStart >= 0) {
    const menuEnd = text.indexOf('\n  }\n\n  if (document.readyState', menuStart);
    if (menuEnd >= 0) text = text.slice(0, menuStart) + text.slice(menuEnd);
  }
  const resizeStart = text.indexOf('    // Jangan panggil setViewerSize saat mode AI Guide split aktif.');
  if (resizeStart >= 0) {
    const resizeEnd = text.indexOf('    applyDevice(false);', resizeStart);
    if (resizeEnd >= 0) text = text.slice(0, resizeStart) + text.slice(resizeEnd);
  }
  return text;
}

for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
  if (!entry.isDirectory() || entry.name === 'lawang-sewu') continue;
  const dir = path.join(root, entry.name);
  const index = path.join(dir, 'index.html');
  const guide = path.join(dir, 'govirtual-ai', 'frontend', 'guide.js');
  if (!fs.existsSync(index) || !fs.existsSync(guide)) continue;

  let html = fs.readFileSync(index, 'utf8');
  const oldHtml = html;
  html = html.replace(/(html\s*\{\s*height:100%;)(\s*\})/, '$1\n\t\t\t\theight:100svh;$2');
  html = html.replace(/(body\s*\{\s*height:100%;)(\s*)/, '$1\n\t\t\t\theight:100svh;$2');
  html = html.replace('style="width:100%;height:100%;overflow:hidden;"', 'style="width:100%;height:100%;height:100svh;overflow:hidden;"');
  for (const file of ['pano2vr_player.js', 'skin.js', 'guide.css', 'guide.js']) html = updateAsset(html, file);
  if (html !== oldHtml) {
    fs.writeFileSync(index, html, 'utf8');
    changed.push(path.relative(root, index));
  }

  let guideText = fs.readFileSync(guide, 'utf8');
  const oldGuide = guideText;
  guideText = updateGuide(guideText);
  if (guideText !== oldGuide) {
    fs.writeFileSync(guide, guideText, 'utf8');
    changed.push(path.relative(root, guide));
  }
}

console.log(changed.map(file => 'CHANGED ' + file).join('\n'));
console.log('TOTAL ' + changed.length);
