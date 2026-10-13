'use strict';

const fs = require('fs');
const path = require('path');
const cp = require('child_process');

const root = 'C:\\xampp\\htdocs\\semarang';
const files = [];
function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full);
    else if (entry.name.endsWith('.webp') && full.includes(path.join('assets', 'object-covers'))) files.push(full);
  }
}
walk(root);

let before = 0;
let after = 0;
for (const file of files) {
  before += fs.statSync(file).size;
  const temp = file + '.tmp.webp';
  const result = cp.spawnSync('ffmpeg', [
    '-hide_banner', '-loglevel', 'error', '-y', '-i', file,
    '-vf', "scale='min(640,iw)':-2:flags=lanczos",
    '-c:v', 'libwebp', '-quality', '68', '-compression_level', '6', '-preset', 'picture',
    temp
  ], { encoding: 'utf8' });
  if (result.status !== 0 || !fs.existsSync(temp) || fs.statSync(temp).size < 100) {
    try { fs.unlinkSync(temp); } catch (error) { /* noop */ }
    throw new Error(file + ': ' + (result.stderr || 'encode failed'));
  }
  fs.renameSync(temp, file);
  after += fs.statSync(file).size;
}
console.log('files=' + files.length);
console.log('before=' + before + ' (' + (before / 1048576).toFixed(2) + ' MB)');
console.log('after=' + after + ' (' + (after / 1048576).toFixed(2) + ' MB)');
console.log('saved=' + ((1 - after / before) * 100).toFixed(1) + '%');
