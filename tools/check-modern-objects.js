const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const root = '../semarang/';
const dbContext = { window: {} };
vm.runInNewContext(fs.readFileSync(root + 'assets/ext/database.js', 'utf8'), dbContext);
const db = dbContext.window.TourDatabase;
const source = fs.readFileSync('../govirtual-ai/govirtual-ai/frontend/guide.js', 'utf8');
assert(source.includes('if (description) {\n        startProgressiveReveal(description, d.rightBodyText);'));
assert(source.includes('if (o.newSystem) focusModernObject(o);'));
assert(source.includes('setPanTiltFov(view.pan, view.tilt, OBJECT_VIEW_FOV)'));
assert(!source.slice(source.indexOf('  function focusModernObject('), source.indexOf('  function hasNewSystemObjects(')).includes('bukaOtomatis'));
assert(source.includes('if (modern[j].infoId === wanted) return modern[j];'));
assert(source.includes('focusModernObject(pf);'));
assert(source.includes('focusModernObject(rs2);'));
assert(source.includes("window.pano.openNext('{' + targetScene.id + '}', rs2.view.pan + '/' + rs2.view.tilt + '/' + OBJECT_VIEW_FOV);"));
assert(source.includes('toggleModernWebElements(state === UIState.WORKSPACE || state === UIState.EXPANDED);'));
assert(source.includes("element.style.setProperty('display', 'none', 'important');"));
assert(source.includes("element.style.display = element.getAttribute('data-gva-web-display');"));
assert(source.includes('function syncAiButtonWithSkinPopup()'));
assert(source.includes("_dom.aiBtn.style.display = blocked || _uiState !== UIState.CLOSED ? 'none' : '';"));
assert(!source.includes('Date.now() - readinessStartedAt >= 5000'));
const begin = source.indexOf('  function newSystemProjectName()');
const end = source.indexOf('  function visibleObjectsForScene(', begin);
assert(begin !== -1 && end !== -1);
for (const [slug, expected] of [
  ['pestakeboenresto', ['1482', '1483', '1486', '1487', '1488']],
  ['goakreo', db.goakreo.map(r => String(r.infoID))]
]) {
  const file = fs.readFileSync(root + slug + '/govirtual-ai/data/tour-data.js', 'utf8');
  const context = { window: { TourDatabase: db, pano: { getVariableValue: () => '' } }, cfg: { tourId: slug }, data: null, sceneById: id => context.data.scenes.find(s => s.id === id), currentSceneId: () => '', console };
  vm.runInNewContext(file, context);
  context.data = context.window.GoVirtualTourData;
  vm.runInNewContext(source.slice(begin, end), context);
  assert.equal(context.newSystemProjectName(), slug);
  const cards = context.data.scenes.flatMap(scene => context.newSystemObjectsForScene(scene.id));
  assert.deepEqual([...new Set(cards.map(card => card.infoId))].sort(), [...new Set(expected)].sort());
  const webview = JSON.parse(fs.readFileSync(root + 'webview/json/data.json'));
  const objects = webview.data.flatMap(group => group.location || []).flatMap(loc => loc.obj_info || []);
  for (const card of cards) {
    const obj = objects.find(o => o.old_par_id === card.infoId);
    assert(obj, card.infoId);
    assert.equal(card.label, obj.oi_name.id);
    assert.equal(card.image, obj.img_thumb);
    assert.equal(context.data.modernObjectInfo[card.infoId].description.id, obj.oi_desc_2.id || obj.oi_desc.id);
    assert(Number.isFinite(context.data.modernObjectInfo[card.infoId].view.pan));
    assert(Number.isFinite(context.data.modernObjectInfo[card.infoId].view.tilt));
    assert(Number.isFinite(context.data.modernObjectInfo[card.infoId].view.fov));
    assert(fs.existsSync(root + 'webview/assets/images/oi/' + card.image), card.image);
    assert(!card.label.startsWith('Klik Untuk Informasi'));
  }
  assert(!cards.some(card => ['1061', '1062', '1063', '1064'].includes(card.infoId)));
  console.log(slug + ': ' + cards.length + ' mapped cards verified');
}
