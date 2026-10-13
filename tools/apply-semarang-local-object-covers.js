'use strict';

const fs = require('fs');
const path = require('path');

const root = 'C:\\xampp\\htdocs\\semarang';
const tours = fs.readdirSync(root, { withFileTypes: true })
  .filter(entry => entry.isDirectory())
  .map(entry => entry.name)
  .filter(name => fs.existsSync(path.join(root, name, 'govirtual-ai', 'frontend', 'guide.js'))
    && fs.existsSync(path.join(root, name, 'govirtual-ai', 'data', 'knowledge.json'))
    && fs.existsSync(path.join(root, name, 'index.html')));

const localFunctions = `function setObjCardImage(img, infoId) {
    var url = basePath + 'frontend/assets/object-covers/' + encodeURIComponent(String(infoId)) + '.webp';
    img.addEventListener('error', function () {
      img.removeAttribute('src');
      img.classList.add('tour-chat__objects-card-img--ph');
    }, { once: true });
    img.src = url;
  }

  function preloadObjCovers() {
    if (_objCoverWarmed) return; _objCoverWarmed = true;
    loadKnowledge().then(function (kd) {
      var seen = {};
      if (kd && kd.entries) kd.entries.forEach(function (e) {
        var m = /objinfo2:(\\d+)/.exec(e.id || e.entityId || '');
        if (!m || seen[m[1]]) return;
        seen[m[1]] = 1;
        try {
          var im = new Image();
          im.src = basePath + 'frontend/assets/object-covers/' + encodeURIComponent(m[1]) + '.webp';
        } catch (error) { /* noop */ }
      });
    }).catch(function () { /* noop */ });
  }`;

const visibleObjectClick = "btn.addEventListener('click', function () { openInfoFrame(o.label, o.infoId, o.oiIndex); });";
const focusedVisibleObjectClick = `btn.addEventListener('click', function () {
          var resolved = resolveObjectScene(o.infoId);
          var sceneId = resolved ? resolved.sceneId : currentSceneId();
          var oiIndex = resolved ? resolved.oiIndex : (o.oiIndex || 0);
          var target = sceneById(sceneId);
          if (target && target.id !== currentSceneId()) {
            _pendingObjectFocus = { sceneId: target.id, label: o.label, infoId: o.infoId, oiIndex: oiIndex };
            loadOI().then(function (map) {
              var positions = (map && map[target.id]) || [];
              var position = positions[oiIndex] || positions[0] || null;
              if (position && window.pano && typeof window.pano.openNext === 'function') {
                window.pano.openNext('{' + target.id + '}', position.pan + '/' + position.tilt + '/' + OBJECT_VIEW_FOV);
              } else {
                navigate(target);
              }
              try { history.replaceState(null, '', '#' + target.id); } catch (error) { /* noop */ }
            });
            return;
          }
          objectPanTo(sceneId, oiIndex);
          openInfoFrame(o.label, o.infoId, oiIndex);
        });`;

for (const tour of tours) {
  const guidePath = path.join(root, tour, 'govirtual-ai', 'frontend', 'guide.js');
  const indexPath = path.join(root, tour, 'index.html');
  let guide = fs.readFileSync(guidePath, 'utf8');
  const dataMarker = '  if (!data) return;';
  const dataCompat = "  if (!data) return;\n  if (!Array.isArray(data.objects)) data.objects = Array.isArray(data.objectInfo) ? data.objectInfo : [];";
  if (guide.includes(dataMarker) && !guide.includes('data.objects = Array.isArray(data.objectInfo)')) {
    guide = guide.replace(dataMarker, dataCompat);
  }
  if (guide.includes(visibleObjectClick)) guide = guide.replace(visibleObjectClick, focusedVisibleObjectClick);
  // Object panels contain objinfo2 records only. Explore/discover entries belong to discover.php, not object info.
  guide = guide.replace(/    \/\/ Secondary source: explore-menu entities mapped to this scene\.[\s\S]*?    return items\.slice\(0, 8\);/, '    return items.slice(0, 8);');
  guide = guide.replace(/    \/\/ Explore entities as info[\s\S]*?    all\.forEach\(function \(o\) \{/, '    all.forEach(function (o) {');
  const start = guide.indexOf('function setObjCardImage(img, infoId) {');
  const endMarker = '\n\n  /* ================================================================';
  const end = guide.indexOf(endMarker, start);
  if (start < 0 || end < 0) throw new Error(tour + ': object image block missing');
  guide = guide.slice(0, start) + localFunctions + guide.slice(end);
  const coverStart = guide.indexOf('  function loadObjCover(infoId, lang, label) {');
  if (coverStart >= 0) {
    const coverEnd = guide.indexOf('\n\n  function findHotspotInScene', coverStart);
    if (coverEnd < 0) throw new Error(tour + ': loadObjCover block end missing');
    const localCover = `  function loadObjCover(infoId) {
      var id = String(infoId || '').replace(/[^0-9]/g, '');
      return Promise.resolve(id ? basePath + 'frontend/assets/object-covers/' + encodeURIComponent(id) + '.webp' : null);
    }`;
    guide = guide.slice(0, coverStart) + localCover + guide.slice(coverEnd);
  }
  fs.writeFileSync(guidePath, guide);

  let html = fs.readFileSync(indexPath, 'utf8');
  html = html.replace(/(govirtual-ai\/frontend\/guide\.js)(?:\?v=[^"']+)?/g, '$1?v=2026100103');
  fs.writeFileSync(indexPath, html);
  console.log('updated ' + tour);
}
