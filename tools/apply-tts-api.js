// Terapkan TTS API (Google Translate via tts.php) ke guide.js semua lokasi.
const fs = require('fs');
const path = require('path');
const ROOTS = [
  'C:\\xampp\\htdocs\\semarang',
  'C:\\xampp\\htdocs\\govirtual-ai\\govirtual-ai',
];

const L = (...arr) => arr.join('\n');

const OLD1 = L(
  'function ttsSpeak(text) {',
  '    if (!_ttsEnabled) return;',
  '    try {',
  '      if (!ttsSupported()) return;',
  '      var synth = window.speechSynthesis;',
  '      try { if (synth.paused) synth.resume(); } catch (e0) {}',
  '      try { synth.cancel(); } catch (e) {}',
  '      var clean = String(text || \'\')'
);
const NEW1 = L(
  'var _ttsAudio = null;',
  '  var _ttsApiFailed = false;',
  '  function ttsApiSpeak(chunks, mySeq) {',
  '    ttsDuckMusic(true);',
  '    var base = basePath.replace(/\\/?$/, \'/\');',
  '    var idx = 0;',
  '    function next() {',
  '      if (mySeq !== _ttsSeq) { ttsDuckMusic(false); return; }',
  '      if (idx >= chunks.length) { if (mySeq === _ttsSeq) ttsDuckMusic(false); return; }',
  '      var c = chunks[idx++];',
  '      var url = base + \'api/tts.php?text=\' + encodeURIComponent(c.t) + \'&lang=id\';',
  '      var a = _ttsAudio || new Audio();',
  '      _ttsAudio = a;',
  '      a.onended = next;',
  '      a.onerror = function () {',
  '        if (idx <= 1 && !_ttsApiFailed && ttsSupported()) {',
  '          _ttsApiFailed = true;',
  '          try { if (\'speechSynthesis\' in window) window.speechSynthesis.cancel(); } catch (e) {}',
  '          ttsWebSpeak(chunks, mySeq);',
  '          return;',
  '        }',
  '        next();',
  '      };',
  '      a.src = url;',
  '      a.volume = 1;',
  '      a.playbackRate = 1;',
  '      var pr2 = a.play ? a.play() : null;',
  '      if (pr2 && pr2.catch) pr2.catch(function () { try { a.onerror && a.onerror(); } catch (e) {} });',
  '    }',
  '    next();',
  '  }',
  '  function ttsWebSpeak(chunks, mySeq) {',
  '    try {',
  '      if (!ttsSupported()) { ttsDuckMusic(false); return; }',
  '      var synth = window.speechSynthesis;',
  '      try { if (synth.paused) synth.resume(); } catch (e0) {}',
  '      ttsEnsureVoices(function () {',
  '        try {',
  '          if (mySeq !== _ttsSeq) return;',
  '          var voice = ttsPickVoice();',
  '          var lang = (voice && voice.lang) || \'id-ID\';',
  '          try { if (synth.paused) synth.resume(); } catch (e2) {}',
  '          ttsDuckMusic(true);',
  '          chunks.forEach(function (c, ci) {',
  '            var u = new SpeechSynthesisUtterance(c.t);',
  '            if (voice) { try { u.voice = voice; } catch (e3) {} }',
  '            var pr3 = ttsProsody(c.kind, c.emph);',
  '            u.lang = lang; u.rate = pr3.rate; u.pitch = pr3.pitch; u.volume = 1;',
  '            if (ci === chunks.length - 1) {',
  '              u.onend = function () { if (mySeq === _ttsSeq) ttsDuckMusic(false); };',
  '              u.onerror = function () { if (mySeq === _ttsSeq) ttsDuckMusic(false); };',
  '            }',
  '            synth.speak(u);',
  '          });',
  '        } catch (e5) { /* noop */ }',
  '      });',
  '    } catch (e4) { /* noop */ }',
  '  }',
  '  function ttsSpeak(text) {',
  '    if (!_ttsEnabled) return;',
  '    try {',
  '      var clean = String(text || \'\')'
);

const OLD2 = L(
  '      setTimeout(function () {',
  '        try {',
  '          if (mySeq !== _ttsSeq) return;',
  '          ttsEnsureVoices(function () {',
  '            try {',
  '              if (mySeq !== _ttsSeq) return;',
  '              var voice = ttsPickVoice();',
  '              var lang = (voice && voice.lang) || \'id-ID\';',
  '              try { if (synth.paused) synth.resume(); } catch (e2) {}',
  '              ttsDuckMusic(true);',
  '              chunks.forEach(function (c, ci) {',
  '                var u = new SpeechSynthesisUtterance(c.t);',
  '                if (voice) { try { u.voice = voice; } catch (e3) {} }',
  '                var pr = ttsProsody(c.kind, c.emph);',
  '                u.lang = lang; u.rate = pr.rate; u.pitch = pr.pitch; u.volume = 1;',
  '                if (ci === chunks.length - 1) {',
  '                  u.onend = function () { if (mySeq === _ttsSeq) ttsDuckMusic(false); };',
  '                  u.onerror = function () { if (mySeq === _ttsSeq) ttsDuckMusic(false); };',
  '                }',
  '                synth.speak(u);',
  '              });',
  '            } catch (e5) { /* noop */ }',
  '          });',
  '        } catch (e4) { /* noop */ }',
  '      }, 80);'
);
const NEW2 = L(
  '      setTimeout(function () {',
  '        try {',
  '          if (mySeq !== _ttsSeq) return;',
  '          if (!_ttsApiFailed) ttsApiSpeak(chunks, mySeq);',
  '          else ttsWebSpeak(chunks, mySeq);',
  '        } catch (e4) { /* noop */ }',
  '      }, 80);'
);

const OLD3 = '  function ttsStop() { _ttsSeq++; ttsDuckMusic(false); try { if (\'speechSynthesis\' in window) window.speechSynthesis.cancel(); } catch (e) {} }';
const NEW3 = L(
  '  function ttsStop() {',
  '    _ttsSeq++;',
  '    ttsDuckMusic(false);',
  '    if (_ttsAudio) { try { _ttsAudio.onended = null; _ttsAudio.onerror = null; _ttsAudio.pause(); _ttsAudio.src = \'\'; } catch (e) {} }',
  '    try { if (\'speechSynthesis\' in window) window.speechSynthesis.cancel(); } catch (e) {}',
  '  }'
);

function walk(dir) {
  let out = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) out = out.concat(walk(p));
    else if (e.name === 'guide.js' && /\\govirtual-ai\\frontend\\guide\.js$/.test(p)) out.push(p);
  }
  return out;
}

let done = 0, skip = 0;
const all = [];
for (const r of ROOTS) all.push(...walk(r));
for (const f of all) {
  let t = fs.readFileSync(f, 'utf8');
  if (t.includes('ttsApiSpeak')) { console.log('SKIP (sudah): ' + f); skip++; continue; }
  let ok = true;
  if (!t.includes(OLD1)) { console.log('FAIL OLD1: ' + f); ok = false; }
  if (ok && !t.includes(OLD2)) { console.log('FAIL OLD2: ' + f); ok = false; }
  if (ok && !t.includes(OLD3)) { console.log('FAIL OLD3: ' + f); ok = false; }
  if (!ok) { skip++; continue; }
  t = t.replace(OLD1, NEW1).replace(OLD2, NEW2).replace(OLD3, NEW3);
  fs.writeFileSync(f, t);
  console.log('PATCH: ' + f);
  done++;
}
console.log(`Done. patched=${done} skip=${skip}`);