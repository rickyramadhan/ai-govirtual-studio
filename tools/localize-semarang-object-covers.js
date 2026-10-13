'use strict';

const fs = require('fs');
const path = require('path');
const cp = require('child_process');

const root = 'C:\\xampp\\htdocs\\semarang';
const webview = path.join(root, 'webview');
const dump = path.join(webview, 'semarang-260828-fix.sql');
const imageRoot = path.join(webview, 'assets', 'images', 'objekinfo');
const tours = fs.readdirSync(root, { withFileTypes: true })
  .filter(entry => entry.isDirectory())
  .map(entry => entry.name)
  .filter(name => fs.existsSync(path.join(root, name, 'govirtual-ai', 'data', 'knowledge.json')));

function objectRefs(knowledgePath) {
  const data = JSON.parse(fs.readFileSync(knowledgePath, 'utf8'));
  const refs = new Map();
  for (const entry of data.entries || []) {
    const match = /objinfo2:(\d+)/.exec(String(entry.id || entry.entityId || ''));
    if (match) refs.set(match[1], String(entry.title || entry.label || ''));
  }
  return refs;
}

function splitTuple(tuple) {
  const values = [];
  let value = '';
  let quoted = false;
  let escaped = false;
  for (const char of tuple) {
    if (escaped) { value += char; escaped = false; continue; }
    if (quoted && char === '\\') { escaped = true; continue; }
    if (char === "'") { quoted = !quoted; continue; }
    if (char === ',' && !quoted) { values.push(value.trim()); value = ''; continue; }
    value += char;
  }
  values.push(value.trim());
  return values;
}

function normalized(value) {
  return String(value).toLowerCase().replace(/[^a-z0-9]/g, '');
}

const folderAliases = new Map(Object.entries({
  agrowisatacepoko: 'argowisatacepoko',
  agrowisatapurwasari: 'agrowisatap urwosari'.replace(' ', ''),
  akhtaraskyview: 'akhtaracoffeeandskyview',
  gamacandirestaurant: 'gamacandiresto',
  gorjatidiri: 'kawasanolahragajatidiri',
  klentengkwiewiekong: 'klentenghwiewiekiong',
  kiw: 'ptkawasanindustriwijayakusuma',
  koenakoeni: 'koenokoeni',
  loempialien: 'loempialien',
  malltentrem: 'tentremmall',
  marinaconventioncenter: 'marinaconventioncentre',
  masjidagungsemarangkauman: 'masjidkauman',
  masjidbaiturahman: 'masjidbaiturrahman',
  museumperjuanganmandalabakti: 'museummandalabakti',
  pelabuhantanjungemas: 'terminaltanjungmas',
  pusatoleholehpadanaran: 'pusatoleholehjlnpanadaran',
  queencitymall: 'queenscitymall',
  rscolombiaasia: 'rscolumbiaasia',
  rskrmtwongsonegoro: 'rswongsonegoro',
  terminalmangkang: 'terminalmangkandanmpp',
  univwidyahusadasemarang: 'universitaswidyahusadasemarang',
  usm: 'universitassemarang',
  pasardjohar: 'pasarjohar'
}));

function words(value) {
  return String(value).replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase()
    .split(/[^a-z0-9]+/).filter(word => word.length >= 3);
}

function wordScore(left, right) {
  const a = words(left);
  const b = words(right);
  let score = 0;
  for (const x of a) for (const y of b) {
    if (x === y) score += 4;
    else if (x.length >= 4 && y.length >= 4 && (x.includes(y) || y.includes(x))) score += 2;
  }
  return score;
}

function localImageIndex() {
  const index = new Map();
  const names = new Map();
  const folders = new Map();
  for (const folder of fs.readdirSync(imageRoot, { withFileTypes: true }).filter(entry => entry.isDirectory())) {
    const folderKey = normalized(folder.name);
    const files = [];
    for (const file of fs.readdirSync(path.join(imageRoot, folder.name), { withFileTypes: true }).filter(entry => entry.isFile() && /\.png$/i.test(entry.name))) {
      const source = path.join(imageRoot, folder.name, file.name);
      const name = normalized(path.parse(file.name).name);
      files.push([name, source]);
      index.set(folderKey + ':' + name, source);
      if (!names.has(name)) names.set(name, source);
      else names.set(name, false);
    }
    folders.set(folderKey, files);
  }
  return { index, names, folders };
}

function imageMap() {
  const sql = fs.readFileSync(dump, 'utf8');
  const local = localImageIndex();
  const files = local.index;
  const start = sql.indexOf('INSERT INTO `db_img_oi`');
  if (start < 0) throw new Error('db_img_oi tidak ditemukan di dump');
  const end = sql.indexOf(';', start);
  const block = sql.slice(start, end);
  const map = new Map();
  for (const match of block.matchAll(/\(([^\r\n]+)\)(?:,|$)/g)) {
    const row = splitTuple(match[1]);
    if (row.length !== 7 || !/^\d+$/.test(row[4])) continue;
    const id = row[4];
    const source = path.join(imageRoot, row[2], row[3] + '.png');
    if (!map.has(id) && fs.existsSync(source)) map.set(id, source);
  }
  const discover = Array.from(sql.matchAll(/\(\d+,\s*'discover',\s*'([^']+)',\s*'([^']+)',\s*(\d+),/g));
  for (const match of discover) {
    const id = match[3];
    const folder = normalized(match[1]);
    const image = normalized(match[2]);
    let source = files.get(folder + ':' + image);
    if (!source) {
      var folderNames = Array.from(local.folders.keys());
      var folderCandidates = folderNames.filter(name => name.includes(folder) || folder.includes(name));
      if (folderCandidates.length !== 1) {
        var folderTokens = String(match[1]).toLowerCase().split(/[^a-z0-9]+/).filter(token => token.length >= 3);
        folderCandidates = folderNames.filter(name => folderTokens.filter(token => name.includes(token)).length >= Math.max(1, folderTokens.length - 1));
      }
      var candidateFolders = folderCandidates.length === 1 ? folderCandidates : [folder];
      var candidates = [];
      candidateFolders.forEach(key => {
        (local.folders.get(key) || []).forEach(pair => {
          var name = pair[0];
          if (name.length >= 4 && (name.includes(image) || image.includes(name))) candidates.push(pair);
        });
      });
      if (candidates.length === 1) source = candidates[0][1];
      if (!source && folderCandidates.length === 1) {
        const ranked = (local.folders.get(folderCandidates[0]) || [])
          .map(pair => [wordScore(match[2], path.parse(pair[1]).name), pair[1]])
          .sort((a, b) => b[0] - a[0]);
        if (ranked.length && ranked[0][0] >= 4 && (!ranked[1] || ranked[0][0] > ranked[1][0])) source = ranked[0][1];
      }
    }
    if (!map.has(id) && source) map.set(id, source);
  }

  // WebView stores some image rows without itmgroupid. Keep fallback inside
  // exact location folder, then pair remaining unique objects and images once.
  const groups = new Map();
  for (const match of discover) {
    const folder = normalized(match[1]);
    if (!groups.has(folder)) groups.set(folder, new Map());
    groups.get(folder).set(match[3], match[2]);
  }
  for (const [folder, objects] of groups) {
    let folderCandidates = folderAliases.has(folder) && local.folders.has(folderAliases.get(folder))
      ? [folderAliases.get(folder)]
      : Array.from(local.folders.keys()).filter(name => name.includes(folder) || folder.includes(name));
    if (folderCandidates.length !== 1) {
      const tokens = words(Array.from(objects.values())[0] || folder);
      const folderWords = words(folder);
      folderCandidates = Array.from(local.folders.keys()).filter(name => {
        const hits = folderWords.filter(word => name.includes(word)).length;
        return hits >= Math.max(1, folderWords.length - 1)
          || tokens.filter(word => name.includes(word)).length >= 2;
      });
    }
    if (folderCandidates.length !== 1) {
      const rankedFolders = Array.from(local.folders.keys())
        .map(name => [wordScore(folder, name), name])
        .sort((a, b) => b[0] - a[0]);
      if (rankedFolders[0] && rankedFolders[0][0] >= 2
          && (!rankedFolders[1] || rankedFolders[0][0] > rankedFolders[1][0])) {
        folderCandidates = [rankedFolders[0][1]];
      }
    }
    if (folderCandidates.length !== 1) continue;
    const available = local.folders.get(folderCandidates[0]) || [];
    const unresolved = Array.from(objects).filter(([id]) => !map.has(id));
    if (!unresolved.length || !available.length) continue;
    const pairs = [];
    for (const [id, label] of unresolved) for (const pair of available) {
      pairs.push([wordScore(label, path.parse(pair[1]).name), id, pair[1]]);
    }
    pairs.sort((a, b) => b[0] - a[0]);
    const usedIds = new Set();
    const usedSources = new Set();
    for (const [score, id, source] of pairs) {
      if (score < 2 || usedIds.has(id) || usedSources.has(source)) continue;
      map.set(id, source); usedIds.add(id); usedSources.add(source);
    }
    const ids = unresolved.map(([id]) => id).filter(id => !usedIds.has(id));
    const sources = available.map(([, source]) => source).filter(source => !usedSources.has(source));
    // WebView omitted image foreign keys for many rows. Location folder remains
    // authoritative; reuse only its images so objects never leak across tours.
    if (sources.length) ids.forEach((id, index) => map.set(id, sources[index % sources.length]));
  }
  return map;
}

function main() {
  const local = localImageIndex();
  const images = imageMap();
  const fallbackNames = local.names;
  const allNamedImages = Array.from(local.names.entries()).filter(([, source]) => source);
  const missing = [];
  let total = 0;
  let copied = 0;
  for (const tour of tours) {
    const knowledge = path.join(root, tour, 'govirtual-ai', 'data', 'knowledge.json');
    const output = path.join(root, tour, 'govirtual-ai', 'frontend', 'assets', 'object-covers');
    const refs = objectRefs(knowledge);
    fs.mkdirSync(output, { recursive: true });
    for (const [id, title] of refs) {
      const titleKey = normalized(title);
      const titleCandidates = allNamedImages.filter(([name]) => name.length >= 4 && titleKey.length >= 4 && (name.includes(titleKey) || titleKey.includes(name)));
      const source = images.get(id) || fallbackNames.get(titleKey) || (titleCandidates.length === 1 ? titleCandidates[0][1] : null);
      if (!source) { missing.push(tour + ':' + id); continue; }
      const target = path.join(output, id + '.webp');
      const result = cp.spawnSync('ffmpeg', [
        '-hide_banner', '-loglevel', 'error', '-y', '-i', source,
        '-vf', "scale='min(640,iw)':-2:flags=lanczos",
        '-c:v', 'libwebp', '-quality', '68', '-compression_level', '6', target
      ], { encoding: 'utf8' });
      if (result.status !== 0 || !fs.existsSync(target) || fs.statSync(target).size < 100) {
        throw new Error(tour + ':' + id + ': ' + (result.stderr || 'encode failed'));
      }
      copied++;
    }
    total += refs.size;
    console.log(tour + ': ' + refs.size + ' covers');
  }
  console.log('tours=' + tours.length);
  console.log('total=' + total);
  console.log('copied=' + copied);
  console.log('missing=' + (missing.length ? missing.join(',') : 'none'));
  if (missing.length) process.exitCode = 2;
}

main();
