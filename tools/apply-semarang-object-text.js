'use strict';

const fs = require('fs');
const path = require('path');
const cp = require('child_process');

const root = 'C:/xampp/htdocs/semarang';
const iframeFallback = /      \} else if \(objectInfoPage\) \{\r?\n        var langIso = langMap\[lang\] \|\| lang;\r?\n        var src = objectInfoPage \+ '\?' \+ objectInfoDetailParam \+ '=' \+ encodeURIComponent\(infoId\) \+ '&lang=' \+ langIso \+ objectInfoExtra;\r?\n        var ifr = el\('iframe', 'tour-chat__right-iframe'\);\r?\n        ifr\.setAttribute\('frameborder', '0'\);\r?\n        ifr\.setAttribute\('allowfullscreen', 'true'\);\r?\n        ifr\.setAttribute\('src', src\);\r?\n        d\.rightBodyText\.appendChild\(ifr\);\r?\n      \}/;
const textFallback = `      } else {
        d.rightBodyText.textContent = label ? 'Informasi ' + label + ' belum tersedia.' : 'Deskripsi objek belum tersedia.';
      }`;

let changed = 0;
const syntaxFail = [];
const iframeRemaining = [];

for (const entry of fs.readdirSync(root, { withFileTypes: true })) {
  if (!entry.isDirectory()) continue;
  const guide = path.join(root, entry.name, 'govirtual-ai/frontend/guide.js');
  const index = path.join(root, entry.name, 'index.html');
  if (!fs.existsSync(guide)) continue;

  let source = fs.readFileSync(guide, 'utf8');
  if (iframeFallback.test(source)) {
    source = source.replace(iframeFallback, textFallback);
    fs.writeFileSync(guide, source);
    changed++;
  }
  if (source.includes("var ifr = el('iframe', 'tour-chat__right-iframe')")) iframeRemaining.push(entry.name);

  try {
    cp.execFileSync(process.execPath, ['--check', guide], { stdio: 'ignore' });
  } catch (error) {
    syntaxFail.push(entry.name);
  }

  if (fs.existsSync(index)) {
    const html = fs.readFileSync(index, 'utf8');
    const updated = html.replace(/(govirtual-ai\/frontend\/guide\.js)\?v=[^"']+/g, '$1?v=2026100201');
    if (updated !== html) fs.writeFileSync(index, updated);
  }
}

console.log(JSON.stringify({ changed, iframeRemaining, syntaxFail }));
process.exitCode = iframeRemaining.length || syntaxFail.length ? 1 : 0;
