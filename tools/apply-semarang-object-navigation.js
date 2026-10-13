'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const cp = require('child_process');

const root = 'C:/xampp/htdocs/semarang';
const dbFile = path.join(root, 'assets/ext/database.js');
const sandbox = { window: {} };
vm.runInNewContext(fs.readFileSync(dbFile, 'utf8'), sandbox, { filename: dbFile });
const database = sandbox.window.TourDatabase || {};

function parseTourData(file) {
  const box = { window: {} };
  vm.runInNewContext(fs.readFileSync(file, 'utf8'), box, { filename: file });
  return box.window.GoVirtualTourData;
}

function panoramaViews(xml) {
  const result = {};
  const panoramaRe = /<panorama\b[^>]*\bid="([^"]+)"[^>]*>([\s\S]*?)<\/panorama>/g;
  let match;
  while ((match = panoramaRe.exec(xml))) {
    const body = match[2];
    const custom = /<userdata\b[^>]*\bcustomnodeid="([^"]*)"/i.exec(body);
    const node = (custom && custom[1] || match[1]).replace(/[{}]/g, '').trim();
    const targets = {};
    const elementRe = /<webelement\b((?:"[^"]*"|[^>])*)\/?\s*>/gi;
    let element;
    while ((element = elementRe.exec(body))) {
      const attrs = element[1];
      function value(name) {
        const found = new RegExp('\\b' + name + '="([^"]+)"', 'i').exec(attrs);
        return found && found[1];
      }
      const id = value('id');
      const pan = Number(value('pan'));
      const tilt = Number(value('tilt'));
      const fov = Number(value('fov'));
      if (id && Number.isFinite(pan) && Number.isFinite(tilt)) {
        targets[id] = { pan, tilt, fov: Number.isFinite(fov) ? fov : 15 };
      }
    }
    result[node] = targets;
  }
  return result;
}

function patchTourData(file, rows, views) {
  const data = parseTourData(file);
  const modern = data.modernObjectInfo || {};
  let mapped = 0;
  for (const row of rows) {
    const id = String(row.infoID == null ? '' : row.infoID);
    const node = String(row.nodeID || '').replace(/[{}]/g, '').trim();
    const view = views[node] && views[node][String(row.targetID || '')];
    if (!modern[id] || !view) continue;
    modern[id].view = view;
    mapped++;
  }
  const source = fs.readFileSync(file, 'utf8');
  const start = source.indexOf('"modernObjectInfo"');
  if (start < 0) return { mapped, changed: false };
  const brace = source.indexOf('{', start);
  let depth = 0, end = -1, quoted = false, escaped = false;
  for (let i = brace; i < source.length; i++) {
    const ch = source[i];
    if (quoted) {
      if (escaped) escaped = false;
      else if (ch === '\\') escaped = true;
      else if (ch === '"') quoted = false;
      continue;
    }
    if (ch === '"') quoted = true;
    else if (ch === '{') depth++;
    else if (ch === '}' && --depth === 0) { end = i + 1; break; }
  }
  if (end < 0) throw new Error('Cannot parse modernObjectInfo in ' + file);
  const indent = '    ';
  const json = JSON.stringify(modern, null, 4).split('\n').map((line, i) => i ? indent + line : line).join('\n');
  const next = source.slice(0, brace) + json + source.slice(end);
  if (next !== source) fs.writeFileSync(file, next);
  return { mapped, changed: next !== source };
}

function patchGuide(file) {
  let source = fs.readFileSync(file, 'utf8');
  const before = source;

  source = source.replace(
    "return { label: labels[lang] || labels.id || ('Objek #' + id), image: meta.image, kind: 'info', infoId: id, targetID: String(r.targetID), sceneId: sceneId, oiIndex: i, newSystem: true };",
    "return { label: labels[lang] || labels.id || ('Objek #' + id), image: meta.image, view: meta.view, kind: 'info', infoId: id, targetID: String(r.targetID), sceneId: sceneId, oiIndex: i, newSystem: true };"
  );

  source = source.replace(
    "return db[project].filter(function (r) {\n      return r && r.targetID && r.infoID != null && String(r.nodeID || '').replace(/[{}]/g, '').trim() === node;\n    }).map(function (r, i) {",
    "return db[project].filter(function (r) {\n      var meta = r && (data.modernObjectInfo || {})[String(r.infoID)];\n      return r && r.targetID && r.infoID != null && meta && meta.view && String(r.nodeID || '').replace(/[{}]/g, '').trim() === node;\n    }).map(function (r, i) {"
  );

  if (!source.includes('function focusModernObject(object)')) {
    source = source.replace(
      /\n  function hasNewSystemObjects\(\) \{/,
      "\n  function focusModernObject(object) {\n    var view = object && object.view;\n    if (!view || !window.pano) return;\n    if (typeof window.pano.setPanTiltFov === 'function') window.pano.setPanTiltFov(view.pan, view.tilt, OBJECT_VIEW_FOV);\n    else if (typeof window.pano.moveTo === 'function') window.pano.moveTo(view.pan, view.tilt, OBJECT_VIEW_FOV, 0);\n  }\n\n  function hasNewSystemObjects() {"
    );
  } else {
    source = source.replace(
      /function focusModernObject\(object\) \{[\s\S]*?\n  \}/,
      "function focusModernObject(object) {\n    var view = object && object.view;\n    if (!view || !window.pano) return;\n    if (typeof window.pano.setPanTiltFov === 'function') window.pano.setPanTiltFov(view.pan, view.tilt, OBJECT_VIEW_FOV);\n    else if (typeof window.pano.moveTo === 'function') window.pano.moveTo(view.pan, view.tilt, OBJECT_VIEW_FOV, 0);\n  }"
    );
  }

  source = source.replace(
    "btn.addEventListener('click', function () { openInfoFrame(o.label, o.infoId, o.oiIndex); });",
    "btn.addEventListener('click', function () { openInfoFrame(o.label, o.infoId, o.oiIndex); if (o.newSystem) focusModernObject(o); });"
  );

  source = source.replace(
    "_pendingObjectFocus = { sceneId: targetScene.id, label: o.label, infoId: o.infoId, oiIndex: _oi, targetID: rs2 && rs2.targetID, newSystem: rs2 && rs2.newSystem };",
    "_pendingObjectFocus = { sceneId: targetScene.id, label: o.label, infoId: o.infoId, oiIndex: _oi, targetID: rs2 && rs2.targetID, view: o.view, newSystem: rs2 && rs2.newSystem };"
  );

  source = source.replace(
    /openInfoFrame\(pf\.label, pf\.infoId, 0\);\n\s*if \(window\.TourEngine && pf\.targetID && typeof window\.TourEngine\.bukaOtomatis === 'function'\) window\.TourEngine\.bukaOtomatis\(pf\.targetID\);/g,
    "openInfoFrame(pf.label, pf.infoId, 0);\n              focusModernObject(pf);"
  );

  source = source.replace(
    /openInfoFrame\(o\.label, o\.infoId, 0\);\n\s*if \(window\.TourEngine && rs2\.targetID && typeof window\.TourEngine\.bukaOtomatis === 'function'\) window\.TourEngine\.bukaOtomatis\(rs2\.targetID\);/g,
    "openInfoFrame(o.label, o.infoId, 0);\n              focusModernObject(o);"
  );

  source = source.replace(
    "var description = translated && translated.content || (entry && entry.content) || '';",
    "var modernDescription = (((data.modernObjectInfo || {})[String(infoId)] || {}).description || {});\n      var description = modernDescription[lang] || modernDescription.id || translated && translated.content || (entry && entry.content) || '';"
  );
  source = source.replace('if (description && !hasNewSystemObjects()) {', 'if (description) {');

  if (source !== before) fs.writeFileSync(file, source);
  return source !== before;
}

const report = { tours: 0, objects: 0, mapped: 0, skippedStaleMappings: [], changedData: 0, changedGuide: 0, changedIndex: 0, syntaxFail: [] };
for (const [project, rows] of Object.entries(database)) {
  const dir = path.join(root, project);
  const panoFile = path.join(dir, 'pano.xml');
  const dataFile = path.join(dir, 'govirtual-ai/data/tour-data.js');
  const guideFile = path.join(dir, 'govirtual-ai/frontend/guide.js');
  const indexFile = path.join(dir, 'index.html');
  if (!fs.existsSync(panoFile) || !fs.existsSync(dataFile) || !fs.existsSync(guideFile)) continue;
  report.tours++;
  report.objects += rows.length;
  const views = panoramaViews(fs.readFileSync(panoFile, 'utf8'));
  const result = patchTourData(dataFile, rows, views);
  report.mapped += result.mapped;
  if (result.changed) report.changedData++;
  if (patchGuide(guideFile)) report.changedGuide++;
  if (fs.existsSync(indexFile)) {
    const html = fs.readFileSync(indexFile, 'utf8');
    const nextHtml = html.replace(/(govirtual-ai\/frontend\/guide\.js)(?:\?v=\d+)?/, '$1?v=2026100203');
    if (nextHtml !== html) { fs.writeFileSync(indexFile, nextHtml); report.changedIndex++; }
  }
  const data = parseTourData(dataFile);
  for (const row of rows) {
    const meta = (data.modernObjectInfo || {})[String(row.infoID)];
    if (!meta || !meta.view) report.skippedStaleMappings.push({ project, infoID: String(row.infoID), nodeID: row.nodeID, targetID: row.targetID });
  }
  const syntax = cp.spawnSync(process.execPath, ['--check', guideFile], { encoding: 'utf8' });
  if (syntax.status !== 0) report.syntaxFail.push({ project, error: syntax.stderr.trim() });
}

fs.writeFileSync(path.join(__dirname, '../storage/semarang-object-navigation-audit.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report));
if (report.syntaxFail.length) process.exitCode = 1;
