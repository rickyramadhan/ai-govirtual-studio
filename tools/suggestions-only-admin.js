// Ubah guide.js: per panorama tampilkan SELURUH suggestedQuestions admin (hybrid ai|fix)
// + "Kemana Berikutnya?" di paling bawah. Hapus starter/fallback default
// (Asal nama, Yang menarik, Rute terbaik, GENERAL_KNOWLEDGE, dst).
// Berlaku untuk storage source (ai-guide-hub) + deployed (semarang).
const fs = require('fs');
const path = require('path');

const ROOTS = [
  'C:\\xampp\\htdocs\\ai-guide-hub\\storage\\tours',
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
];

const L = (...arr) => arr.join('\n');

const NEW_BUILD = L(
  '  function buildContextualSuggestions(params) {',
  '    var sceneId = params.sceneId || currentSceneId(), si = getSceneIdentityEntry(sceneId);',
  '    var suggestions = [], used = {};',
  '    // Admin-curated suggested questions (hybrid ai|fix) — SEMUA ditampilkan per panorama.',
  '    if (si && si.suggestedQuestions && si.suggestedQuestions.length) {',
  '      var cq = si.suggestedQuestions;',
  '      for (var ci = 0; ci < cq.length; ci++) {',
  '        var cqq = sqQ(cq[ci]); if (!cqq) continue;',
  '        var k = normalize(cqq); if (used[k]) continue; used[k] = true;',
  '        suggestions.push({ id: sceneId + \'-cur-\' + ci, label: cqq, prompt: cqq, intent: \'SCENE_DESCRIPTION\', evidenceType: \'CURATED\', evidenceIds: [], confidence: 100 });',
  '      }',
  '    }',
  '    // "Kemana Berikutnya?" wajib di paling bawah.',
  '    suggestions.push({ id: sceneId + \'-NAVIGATION-flow\', label: \'Kemana Berikutnya?\', prompt: \'Kemana berikutnya?\', intent: \'NAVIGATION\', evidenceType: \'TRAVERSAL\', evidenceIds: [], confidence: 99 });',
  '    return suggestions;',
  '  }'
);

const NEW_STARTERS = L(
  '  function buildSmartStarters() {',
  '    // Per-tour override via recommended-questions.json (State1) dipakai bila ada.',
  '    if (_recQuestions && _recQuestions.state1 && _recQuestions.state1.starters && _recQuestions.state1.starters.length) {',
  '      var out=[]; for(var i=0;i<_recQuestions.state1.starters.length && out.length<4;i++){ var s=_recQuestions.state1.starters[i]; if(s && s.query) out.push({ icon: \'\\u2728\', title: s.label||s.desc||s.query, desc: s.desc||s.query, query: s.query }); }',
  '      if(out.length) return out.slice(0,4);',
  '    }',
  '    var scene = currentScene();',
  '    var si = scene ? getSceneIdentityEntry(scene.id) : null;',
  '    var starters = [];',
  '    if (si && si.suggestedQuestions && si.suggestedQuestions.length) {',
  '      for (var qi = 0; qi < si.suggestedQuestions.length; qi++) {',
  '        var q = sqQ(si.suggestedQuestions[qi]); if (!q) continue;',
  '        starters.push({ icon: \'\\uD83D\\uDCCD\', title: q, desc: q, query: q });',
  '      }',
  '    }',
  '    starters.push({ icon: \'\\uD83E\\uDDED\', title: \'Kemana Berikutnya\', desc: \'Ke mana setelah dari sini? Saya bisa arahkan.\', query: \'Kemana berikutnya?\' });',
  '    return starters;',
  '  }'
);

const NEW_RENDER_SUGG = L(
  '  function renderSuggestions() {',
  '    var d = _dom; if (!d) return;',
  '    var scene = currentScene();',
  '    var contextual = scene ? buildContextualSuggestions({ sceneId: scene.id }) : [];',
  '    var seen = {}, merged = [];',
  '    function pushIfNew(s) {',
  '      if (!s || !s.prompt) return;',
  '      var key = normalize(s.prompt);',
  '      if (seen[key]) return;',
  '      seen[key] = true;',
  '      merged.push(s);',
  '    }',
  '    // Per panorama: seluruh suggested questions admin + "Kemana Berikutnya?" (paling bawah).',
  '    contextual.forEach(pushIfNew);',
  '    d.leftSugList.innerHTML = \'\';',
  '    merged.forEach(function (s) {',
  '      var btn = el(\'button\', \'tour-chat__left-sug\', s.label); btn.type = \'button\';',
  '      btn.addEventListener(\'click\', function () { submitAI(s.prompt); });',
  '      d.leftSugList.appendChild(btn);',
  '    });',
  '    if (d.sugBox) d.sugBox.style.display = merged.length ? \'\' : \'none\';',
  '  }'
);

function walk(dir, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name === 'guide.js' && /\\govirtual-ai\\frontend\\guide\.js$/.test(p)) out.push(p);
  }
  return out;
}

function replaceBlock(text, startMarker, endMarker, newBlock) {
  const s = text.indexOf(startMarker);
  if (s === -1) return { ok: false, why: 'start marker' };
  const e = text.indexOf(endMarker, s + startMarker.length);
  if (e === -1) return { ok: false, why: 'end marker' };
  return { ok: true, out: text.slice(0, s) + newBlock + '\n' + text.slice(e) };
}

const files = [];
for (const r of ROOTS) walk(r, files);

let done = 0, fail = [];
for (const f of files) {
  let t = fs.readFileSync(f, 'utf8');
  if (t.includes('evidenceType: \'CURATED\'')) { console.log('SKIP (sudah): ' + f); done++; continue; }
  let r1 = replaceBlock(t, '  function buildContextualSuggestions(params) {', '  /* Yield AI overlay', NEW_BUILD);
  if (!r1.ok) { fail.push(f + ' (build: ' + r1.why + ')'); continue; }
  let r2 = replaceBlock(r1.out, '  function buildSmartStarters() {', '  function showDiscStatus', NEW_STARTERS);
  if (!r2.ok) { fail.push(f + ' (starters: ' + r2.why + ')'); continue; }
  let r3 = replaceBlock(r2.out, '  function renderSuggestions() {', '  function renderLeftPanel', NEW_RENDER_SUGG);
  if (!r3.ok) { fail.push(f + ' (render: ' + r3.why + ')'); continue; }
  // discovery: tampilkan semua starter (hapus batas 3)
  let r4 = r3.out.replace('deduped.slice(0, 3).forEach(function (s) {', 'deduped.forEach(function (s) {');
  if (!r4.includes('deduped.forEach(function (s) {')) { fail.push(f + ' (discovery)'); continue; }
  fs.writeFileSync(f, r4);
  console.log('PATCH: ' + f);
  done++;
}
console.log(`Done. patched=${done} fail=${fail.length}`);
fail.forEach(f => console.log('  FAIL ' + f));