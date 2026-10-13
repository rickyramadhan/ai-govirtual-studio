// guide.js: navigate & goToSceneId memakai default view node (pan/tilt/fov),
// bukan view kosong — sehingga arah pandang "Kemana Berikutnya" benar.
const fs = require('fs');
const path = require('path');
const ROOTS = [
  'C:\\xampp\\htdocs\\ai-guide-hub\\storage\\tours',
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
];
const OLD =
  "  function navigate(scene) { window.pano.openNext('{' + scene.id + '}', ''); try { history.replaceState(null, '', '#' + scene.id); } catch (e) {} try { renderLeftPanel(scene.id); } catch (e) {} }\n" +
  "  function goToSceneId(id) { if (!id) return; window.pano.openNext('{' + id + '}', ''); try { history.replaceState(null, '', '#' + id); } catch (e) {} try { renderLeftPanel(id); } catch (e) {} }";
const NEW =
  "  function navigate(scene) { var v = ''; try { if (scene && scene.pan !== undefined && scene.tilt !== undefined && scene.fov !== undefined) v = scene.pan + '/' + scene.tilt + '/' + scene.fov; } catch (e) {} window.pano.openNext('{' + scene.id + '}', v); try { history.replaceState(null, '', '#' + scene.id); } catch (e) {} try { renderLeftPanel(scene.id); } catch (e) {} }\n" +
  "  function goToSceneId(id) { if (!id) return; var sc = sceneById(id); var v = ''; try { if (sc && sc.pan !== undefined && sc.tilt !== undefined && sc.fov !== undefined) v = sc.pan + '/' + sc.tilt + '/' + sc.fov; } catch (e) {} window.pano.openNext('{' + id + '}', v); try { history.replaceState(null, '', '#' + id); } catch (e) {} try { renderLeftPanel(id); } catch (e) {} }";

function walk(dir, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name === 'guide.js' && /\\govirtual-ai\\frontend\\guide\.js$/.test(p)) out.push(p);
  }
  return out;
}
const files = [];
for (const r of ROOTS) walk(r, files);
let done = 0; const fails = [];
for (const f of files) {
  let t = fs.readFileSync(f, 'utf8');
  if (t.includes('scene.pan !== undefined && scene.tilt !== undefined')) { console.log('SKIP (sudah): ' + f); done++; continue; }
  if (!t.includes(OLD)) { fails.push(f); continue; }
  fs.writeFileSync(f, t.split(OLD).join(NEW));
  console.log('PATCH: ' + f);
  done++;
}
console.log(`Done. patched=${done} fail=${fails.length}`);
fails.forEach(x => console.log('  FAIL ' + x));