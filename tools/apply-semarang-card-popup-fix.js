'use strict';

const fs = require('fs');
const path = require('path');

const root = 'C:\\xampp\\htdocs\\semarang';
const tours = [
  'Balai Kota',
  'Bandara Ahmad Yani force zoom',
  'gereja-santo-yusuf force zoom',
  'greenfreshfarm force zoom',
  'kota-lama force zoom',
  'masjid-agung-jateng force zoom',
  'Museum Kota Lama force zoom',
  'rscolumbia force zoom',
  'rstugu force zoom',
  'sam-poo-kong force zoom',
  'sandibuana force zoom',
  'tawang force zoom',
  'Universitas Negeri Semarang force zoom',
  'ziebatik force zoom'
];

const oldImage = `function setObjCardImage(img, infoId) {
    img.addEventListener('error', function () { img.removeAttribute('src'); img.classList.add('tour-chat__objects-card-img--ph'); });
    var key = String(infoId);
    if (Object.prototype.hasOwnProperty.call(_objCoverCache, key)) {
      var cached = _objCoverCache[key];
      if (cached) img.src = proxyImg(cached);
      else img.classList.add('tour-chat__objects-card-img--ph');
      return;
    }
    fetch(basePath + 'api/objinfo-cover.php?info=' + encodeURIComponent(key) + '&lang=id')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.image) { _objCoverCache[key] = d.image; img.src = proxyImg(d.image); }
        else { _objCoverCache[key] = false; img.classList.add('tour-chat__objects-card-img--ph'); }
      })
      .catch(function () { img.classList.add('tour-chat__objects-card-img--ph'); });
  }`;

const newImage = `function setObjCardImage(img, infoId) {
    var sourceUrl = '';
    var triedDirect = false;
    img.addEventListener('error', function () {
      // CDN host can block server-side proxy fetches. Browser can still load source image directly.
      if (!triedDirect && sourceUrl && proxyImg(sourceUrl) !== sourceUrl) {
        triedDirect = true;
        img.src = sourceUrl;
        return;
      }
      img.removeAttribute('src');
      img.classList.add('tour-chat__objects-card-img--ph');
    });
    function show(url) {
      sourceUrl = url || '';
      if (sourceUrl) img.src = proxyImg(sourceUrl);
      else img.classList.add('tour-chat__objects-card-img--ph');
    }
    var key = String(infoId);
    if (Object.prototype.hasOwnProperty.call(_objCoverCache, key)) {
      show(_objCoverCache[key]);
      return;
    }
    fetch(basePath + 'api/objinfo-cover.php?info=' + encodeURIComponent(key) + '&lang=id')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        _objCoverCache[key] = (d && d.image) || false;
        show(_objCoverCache[key]);
      })
      .catch(function () { img.classList.add('tour-chat__objects-card-img--ph'); });
  }`;

const popupBlock = `

    // Hide AI while any generated Pano2VR popup is open, on every viewport.
    try {
      var _allPopupNames = ['_url_popupkategori', '_url_popupdiscover', '_url_popupmenu', '_url_popupinformasi', '_url_popuppublic'];
      var _allPopupVars = ['vis_url_popup', 'vis_image_popup', 'vis_pdf_popup',
        'vis_video_youtube_popup', 'vis_video_vimeo_popup', 'vis_video_file_popup',
        'vis_video_url_popup', 'vis_phone_info', 'vis_phone_floorplan', 'vis_phone_map',
        'vis_phone_image', 'vis_phone_pdf', 'vis_phone_youtube', 'vis_phone_vimeo',
        'vis_phone_video_file', 'vis_phone_video_url'];
      var _allPopupIsTrue = function (value) {
        return value === true || value === 1 || value === 'true' || value === '1';
      };
      var _allPopupSync = function () {
        var sk = window.skin || (window.pano && window.pano.skinObj) || null;
        var p = window.pano || (sk && sk.player) || null;
        var open = false;
        if (p && typeof p.getVariableValue === 'function') {
          for (var avi = 0; avi < _allPopupVars.length; avi++) {
            try {
              if (_allPopupIsTrue(p.getVariableValue(_allPopupVars[avi]))) { open = true; break; }
            } catch (eAV) {}
          }
        }
        if (!open && sk) for (var api = 0; api < _allPopupNames.length; api++) {
          var ae = sk[_allPopupNames[api]];
          if (ae && ae.style) {
            var acs = getComputedStyle(ae);
            if (acs.display !== 'none' && acs.visibility !== 'hidden' && parseFloat(acs.opacity || '0') > 0.02) {
              open = true; break;
            }
          }
        }
        if (_dom && _dom.root) _dom.root.classList.toggle('tour-chat--yield', open);
      };
      var _allPopupPlayer = window.pano || null;
      if (_allPopupPlayer && typeof _allPopupPlayer.addListener === 'function') {
        for (var alvi = 0; alvi < _allPopupVars.length; alvi++) {
          (function (name) {
            try { _allPopupPlayer.addListener('varchanged_' + name, _allPopupSync); } catch (eAL) {}
          })(_allPopupVars[alvi]);
        }
      }
      _allPopupSync();
      setInterval(_allPopupSync, 100);
    } catch (eAllPopup) {}`;

for (const tour of tours) {
  const guide = path.join(root, tour, 'govirtual-ai', 'frontend', 'guide.js');
  const index = path.join(root, tour, 'index.html');
  let js = fs.readFileSync(guide, 'utf8');

  if (!js.includes('var triedDirect = false;')) {
    if (!js.includes(oldImage)) throw new Error(tour + ': setObjCardImage marker missing');
    js = js.replace(oldImage, newImage);
  }

  js = js.replace("var t = navCardThumbFor(sc.id);", "var t = navCardThumbFor(sc.id) || panoThumbnailUrl(sc.id, 'Point01');");

  if (!js.includes('var _allPopupNames =')) {
    const marker = '    setTimeout(showWhenReady, 1500);';
    if (!js.includes(marker)) throw new Error(tour + ': popup insertion marker missing');
    js = js.replace(marker, marker + popupBlock);
  }

  fs.writeFileSync(guide, js);

  let html = fs.readFileSync(index, 'utf8');
  html = html.replace(/(govirtual-ai\/frontend\/guide\.js)\?v=[^"']+/g, '$1?v=2026092806');
  fs.writeFileSync(index, html);
  console.log('updated ' + tour);
}
