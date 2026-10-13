(function () {
  'use strict';

  var cfg = window.GOVIRTUAL_AI_CONFIG || {};
  var cfgFrontend = cfg.frontend || {};
  var basePath = cfg.basePath || './govirtual-ai/';
  var tourName = cfg.tourName || (window.GoVirtualTourData && window.GoVirtualTourData.name) || 'Tour';
  var tourId = cfg.tourId || (window.GoVirtualTourData && window.GoVirtualTourData.id) || 'tour';
  var catalogPath = cfg.catalogPath || cfgFrontend.catalogPath || '';
  var catalogLabel = cfg.catalogLabel || cfg.tourLocation || cfg.location || tourName;
  var objectInfoBase = cfg.objectInfoBase || cfgFrontend.objectInfoBase || '';
  var objectInfoPage = cfg.objectInfoPage || cfgFrontend.objectInfoPage || '';
  var objectInfoDetailParam = cfg.objectInfoDetailParam || cfgFrontend.objectInfoDetailParam || 'info';
  var objectInfoExtra = cfg.objectInfoExtra || cfgFrontend.objectInfoExtra || '&vd=1&tp=m';
  var langMap = cfg.langMap || cfgFrontend.langMap || { id: 'id', en: 'en', sa: 'sa', zh: 'zh' };
  var data = window.GoVirtualTourData;
  if (!data) return;
  var apiUrl = basePath + 'api/tour-chat.php';
  var history = [];
  var _hasConversed = false;
  var pendingClarification = null;
  var submitQuery = null;
  var activeRequest = null;
  var lastSubmitted = { text: '', at: 0 };
  var conversationState = {
    lastIntent: null, lastSceneId: null, lastRoute: null, lastEntity: null,
    lastReferencedEntity: null, lastReferencedDestination: null,
    lastNavigableSceneId: null, lastRecommendedSceneId: null,
    lastRecommendedDestination: null, lastMentionedSceneIds: [],
    lastDiscussedEntity: null, lastDiscussedEntityType: null,
    lastNavigableEntity: null, lastPublicServiceEntity: null,
    recommendedRouteId: null, activeRouteId: null, activeRouteSceneIds: [],
    activeRouteStep: null, lastReply: '',
    visitorProfile: { transport: null, travelingWithChildren: null, accessibilityNeeds: [], servicePreference: null, interests: [], destinationPreference: null },
    activeRoute: null, routeProgress: null, conversationSummary: null, visitedSceneIds: []
  };

  var _catalog = null;
  var _pendingDestination = null;
  var _lastDestinationResults = [];
  var _sceneIdentityData = null;
  var _sceneIdentityLoading = null;

  /* ================================================================
     NAVIGATION STATE — guarded executor (shared by text & voice demo)
     ================================================================
     - scene_revision: naik setiap scene aktual berubah (manual/terprogram).
     - Satu navigasi aktif pada satu waktu; koreksi terbaru menggantikan
       aksi pending; aksi yang sudah berjalan menunggu hasil aktual.
     - action_id/turn_id/revision membedakan aksi aktif vs usang.
     - Ledger callId→hashArg mencegah aksi duplikat dari retry.
     - Timeout tidak pernah menyatakan "sudah sampai" — hanya gagal/lanjut.
     ================================================================ */
  var _sceneRevision = 0;
  var _navTurnSeq = 0;
  var _activeNavigation = null; // { actionId, turnId, sceneId, revision, startedAt, status: 'pending'|'executing' }
  var _navLedger = {};          // callId -> { argHash, status, result? }
  var _navResultListeners = []; // optional callbacks on navigate done/failed

  function navNextTurnId() { return 'turn-' + (++_navTurnSeq) + '-' + Date.now().toString(36); }
  function navHashArg(arg) {
    var s = '';
    try { s = JSON.stringify(arg || {}); } catch (e) { s = String(arg || ''); }
    var h = 0;
    for (var i = 0; i < s.length; i++) { h = ((h << 5) - h + s.charCodeAt(i)) | 0; }
    return 'h' + (h >>> 0).toString(36) + '-' + s.length;
  }
  function navCurrentTurnId() { return _activeNavigation ? _activeNavigation.turnId : null; }

  /**
   * Register a navigation action for execution. Returns an execution plan or a
   * rejection reason. Rejects when: a different action is executing, revision is
   * stale, or the same callId+argHash already ran. When a pending (not yet
   * executed) action exists it is replaced by the newest intent.
   */
  function navPrepareNavigation(opts) {
    var callId = opts.callId || null;
    var actionId = opts.actionId || null;
    var turnId = opts.turnId || navNextTurnId();
    var sceneId = opts.sceneId;
    var revision = opts.revision !== undefined ? opts.revision : _sceneRevision;
    var argHash = navHashArg({ callId: callId, actionId: actionId, sceneId: sceneId });

    if (callId) {
      var prior = _navLedger[callId];
      if (prior && prior.argHash === argHash && prior.status === 'done') {
        return { ok: false, reason: 'duplicate_action', prior: prior };
      }
      if (prior && prior.argHash !== argHash) {
        return { ok: false, reason: 'call_conflict', prior: prior };
      }
    }
    if (revision !== _sceneRevision) {
      return { ok: false, reason: 'stale_context', revision: revision, current: _sceneRevision };
    }
    if (!sceneById(sceneId)) {
      return { ok: false, reason: 'unknown_scene', sceneId: sceneId };
    }
    if (sceneId === currentSceneId()) {
      return { ok: false, reason: 'already_there', sceneId: sceneId };
    }
    // Replace a pending (not-yet-executed) action; wait on an executing one.
    if (_activeNavigation && _activeNavigation.status === 'executing') {
      return { ok: false, reason: 'navigation_busy', active: _activeNavigation };
    }
    if (_activeNavigation) navAbortPending();
    _activeNavigation = { actionId: actionId, callId: callId, turnId: turnId, sceneId: sceneId, revision: revision, startedAt: Date.now(), status: 'pending' };
    if (callId) _navLedger[callId] = { argHash: argHash, status: 'pending' };
    return { ok: true, plan: _activeNavigation };
  }

  function navAbortPending() {
    if (_activeNavigation && _activeNavigation.status === 'pending') {
      _activeNavigation.status = 'cancelled';
      if (_activeNavigation.callId && _navLedger[_activeNavigation.callId]) _navLedger[_activeNavigation.callId].status = 'cancelled';
      _activeNavigation = null;
    }
  }

  /** Mark the current action as actually handed to the viewer. */
  function navMarkExecuting() {
    if (_activeNavigation) _activeNavigation.status = 'executing';
  }

  function navIsCurrent(plan) { return _activeNavigation && _activeNavigation.turnId === plan.turnId; }

  /** Called when scene actually changed (getCurrentNode). Success = node changed to target. */
  function navResolveArrival(sceneId) {
    var n = _activeNavigation;
    if (!n) return;
    if (sceneId === n.sceneId) {
      _activeNavigation = null;
      if (n.callId && _navLedger[n.callId]) _navLedger[n.callId].status = 'done';
      return;
    }
    // User navigated somewhere else manually while we were navigating → our action is moot.
    if (sceneId !== n.sceneId) {
      _activeNavigation = null;
      if (n.callId && _navLedger[n.callId]) _navLedger[n.callId].status = 'cancelled';
    }
  }

  var _navTimeoutTimer = null;
  function navArmTimeout(done) {
    navClearTimeout();
    _navTimeoutTimer = setTimeout(function () {
      _navTimeoutTimer = null;
      var n = _activeNavigation;
      if (!n) return;
      // Timeout ≠ arrival. Report failure/uncertainty honestly.
      _activeNavigation = null;
      if (n.callId && _navLedger[n.callId]) _navLedger[n.callId].status = 'timeout';
      if (typeof done === 'function') done({ ok: false, reason: 'navigation_timeout', actionId: n.actionId, sceneId: n.sceneId });
    }, 10000);
  }
  function navClearTimeout() { if (_navTimeoutTimer) { clearTimeout(_navTimeoutTimer); _navTimeoutTimer = null; } }

  function navResetAll() {
    navClearTimeout();
    _activeNavigation = null;
  }
  /* Kartu navigasi AI: teks "Saya akan mengarahkan Anda ke X" (efek ketik) +
     kartu border berisi thumbnail, nama panorama, dan status "Memuat panorama"
     dengan spinner — selesai saat scene tiba. Dipakai mode teks & suara. */
  var _navCardsPending = [];
  function navCardThumbFor(sceneId) {
    try {
      var exits = (typeof data !== 'undefined' && data.panoExits) || {};
      for (var from in exits) {
        if (!Object.prototype.hasOwnProperty.call(exits, from)) continue;
        var list = exits[from] || [];
        for (var i = 0; i < list.length; i++) {
          if (list[i] && list[i].target === sceneId) return panoThumbnailUrl(from, list[i].suffix);
        }
      }
      var out = panoExits(sceneId) || [];
      if (out.length) return panoThumbnailUrl(sceneId, out[0].suffix);
    } catch (e) {}
    return null;
  }
  function navCardSettle(api) {
    if (api.timer) { clearTimeout(api.timer); api.timer = 0; }
    var ix = _navCardsPending.indexOf(api);
    if (ix >= 0) _navCardsPending.splice(ix, 1);
  }
  function navCardOnArrival(ns) {
    for (var i = _navCardsPending.length - 1; i >= 0; i--) {
      var c = _navCardsPending[i];
      if (c.sceneId === ns) c.complete();
      else c.fail('Navigasi dibatalkan.');
    }
  }
  var _navFloat = null;
  var _navFloatTimer = 0;
  function navFloatHost() {
    if (_navFloat && _navFloat.parentNode) return _navFloat;
    var root = document.getElementById('tour-chat');
    _navFloat = el('div', 'tour-chat__nav-float');
    _navFloat.setAttribute('aria-live', 'polite');
    if (root) root.appendChild(_navFloat);
    else document.body.appendChild(_navFloat);
    return _navFloat;
  }
  function navCardHideFloat() {
    if (_navFloatTimer) { clearTimeout(_navFloatTimer); _navFloatTimer = 0; }
    if (_navFloat && _navFloat.parentNode) { try { _navFloat.parentNode.removeChild(_navFloat); } catch (e) {} }
    _navFloat = null;
  }
  function showNavCard(sceneId) {
    var sc = (typeof sceneById === 'function') ? sceneById(sceneId) : null;
    if (!sc) return null;
    var host = navFloatHost();
    if (!host) return null;
    for (var p = _navCardsPending.length - 1; p >= 0; p--) _navCardsPending[p].fail('Navigasi digantikan.');
    host.innerHTML = '';
    var name = sceneDisplayName(sc);
    var wrap = el('div', 'tour-chat__nav-card');
    var note = el('div', 'tour-chat__nav-note');
    var noteText = el('span', 'tour-chat__nav-note-text', '');
    note.appendChild(noteText);
    note.classList.add('tour-chat__nav-note--typing');
    wrap.appendChild(note);
    var body = el('div', 'tour-chat__nav-body');
    var media = el('div', 'tour-chat__nav-media');
    var pin = el('span', 'tour-chat__nav-pin'); pin.innerHTML = SVG_VOICE_PIN;
    media.appendChild(pin);
    var thumb = navCardThumbFor(sceneId);
    if (thumb) {
      var img = el('img', 'tour-chat__nav-thumb');
      img.src = thumb; img.alt = name; img.loading = 'lazy';
      img.addEventListener('error', function () { try { img.remove(); } catch (e) {} });
      media.appendChild(img);
    }
    body.appendChild(media);
    var info = el('div', 'tour-chat__nav-info');
    info.appendChild(el('div', 'tour-chat__nav-title', name));
    var status = el('div', 'tour-chat__nav-status');
    var spin = el('span', 'tour-chat__nav-spinner');
    var statusText = el('span', 'tour-chat__nav-status-text', 'Memuat panorama…');
    status.appendChild(spin); status.appendChild(statusText);
    info.appendChild(status);
    body.appendChild(info);
    wrap.appendChild(body);
    host.appendChild(wrap);
    requestAnimationFrame(function () { requestAnimationFrame(function () { wrap.classList.add('tour-chat__nav-card--in'); }); });
    var settled = false;
    var shownAt = Date.now();
    function arm() {
      if (api.timer) clearTimeout(api.timer);
      api.timer = setTimeout(function () {
        api.timer = 0;
        if (settled) return;
        try { if (currentSceneId() === sceneId) { api.complete(); return; } } catch (e) {}
        api.fail();
      }, 20000);
    }
    var api = {
      sceneId: sceneId, timer: 0,
      complete: function () {
        if (settled) return;
        // Spinner minimum ~2,2 dtk agar prosesnya terbaca, tidak kedip instan.
        var wait = 2200 - (Date.now() - shownAt);
        if (wait > 0) { setTimeout(function () { api.complete(); }, wait); return; }
        settled = true; navCardSettle(api);
        spin.className = 'tour-chat__nav-check';
        spin.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5.5"/></svg>';
        statusText.textContent = 'Anda tiba di ' + name + '.';
        body.classList.add('tour-chat__nav-body--done');
        // Floating menghilang sendiri beberapa detik setelah tiba.
        if (_navFloatTimer) clearTimeout(_navFloatTimer);
        _navFloatTimer = setTimeout(function () {
          _navFloatTimer = 0;
          wrap.classList.add('tour-chat__nav-card--hide');
          setTimeout(function () { if (wrap.parentNode) { try { wrap.parentNode.removeChild(wrap); } catch (eRm) {} } }, 450);
        }, 4000);
      },
      fail: function (msg) {
        if (settled) return; settled = true; navCardSettle(api);
        if (_navFloatTimer) { clearTimeout(_navFloatTimer); _navFloatTimer = 0; }
        spin.className = 'tour-chat__nav-check tour-chat__nav-check--fail';
        spin.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>';
        statusText.textContent = msg || 'Gagal memuat — ketuk kartu untuk coba lagi.';
        body.classList.add('tour-chat__nav-body--fail');
        wrap.classList.add('tour-chat__nav-card--fail');
      }
    };
    wrap.addEventListener('click', function () {
      if (!settled || !wrap.classList.contains('tour-chat__nav-card--fail')) return;
      settled = false; shownAt = Date.now(); _navCardsPending.push(api);
      spin.className = 'tour-chat__nav-spinner'; spin.innerHTML = '';
      statusText.textContent = 'Memuat panorama…';
      body.classList.remove('tour-chat__nav-body--fail'); wrap.classList.remove('tour-chat__nav-card--fail');
      arm();
      var sc2 = sceneById(sceneId); if (sc2) navigate(sc2);
    });
    // Efek ketik ala respons AI, lalu kartu meluncur masuk.
    var fullPlain = 'Saya akan mengarahkan Anda ke ' + name + '.';
    function finishNote() {
      note.textContent = '';
      note.classList.remove('tour-chat__nav-note--typing');
      note.appendChild(document.createTextNode('Saya akan mengarahkan Anda ke '));
      note.appendChild(el('b', '', name));
      note.appendChild(document.createTextNode('.'));
      body.classList.add('tour-chat__nav-body--in');
    }
    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    if (reduceMotion) { noteText.textContent = fullPlain; finishNote(); }
    else {
      var ci = 0;
      var typeTimer = setInterval(function () {
        ci++;
        noteText.textContent = fullPlain.slice(0, ci);
        if (ci >= fullPlain.length) { clearInterval(typeTimer); finishNote(); }
      }, 14);
    }
    _navCardsPending.push(api);
    arm();
    return api;
  }
  /* Eksekusi navigasi AI yang terasa diorkestrasi: kartu + status "Memuat"
     tampil dulu (~1,5 dtk), baru panorama berpindah. Batal otomatis bila
     pengguna pindah manual / menutup AI / giliran suara berganti. */
  var _aiNavTimer = 0;
  var NAV_SWITCH_DELAY = 1500;
  function scheduleAiNavigation(sceneId, cardApi, opts) {
    opts = opts || {};
    if (_aiNavTimer) { clearTimeout(_aiNavTimer); _aiNavTimer = 0; }
    var fromScene = null;
    try { fromScene = currentSceneId(); } catch (e0) {}
    var voiceTurn = _voice ? _voice.turnSeq : -1;
    _aiNavTimer = setTimeout(function () {
      _aiNavTimer = 0;
      try { if (fromScene !== null && currentSceneId() !== fromScene) { if (cardApi) cardApi.fail('Dibatalkan: posisi berubah.'); return; } } catch (e1) {}
      if (opts.voice && _voice && (_voice.endRequested || _voice.turnSeq !== voiceTurn)) { if (cardApi) cardApi.fail('Dibatalkan: sesi suara berubah.'); return; }
      if (_uiState === UIState.CLOSED && !_voiceMode) { if (cardApi) cardApi.fail('Dibatalkan: AI ditutup.'); return; }
      var sc = sceneById(sceneId);
      if (!sc) { if (cardApi) cardApi.fail('Tujuan tidak dikenal.'); return; }
      var plan = navPrepareNavigation({ sceneId: sceneId, revision: _sceneRevision });
      if (typeof opts.onDone === 'function') { try { opts.onDone(plan); } catch (e2) {} }
      if (!plan.ok) {
        if (cardApi) {
          if (plan.reason === 'already_there') cardApi.complete();
          else cardApi.fail(plan.reason === 'navigation_busy' ? 'Navigasi sebelumnya masih berjalan.' : 'Tidak dapat bernavigasi sekarang.');
        }
        return;
      }
      navMarkExecuting();
      navigate(sc);
      navArmTimeout(function () {});
    }, NAV_SWITCH_DELAY);
  }

  // Test hook (only when enabled) — exposes nav state machine for automated QA.
  if (window.__GVA_NAV_TEST__) {
    window.__gvaNav = {
      reset: navResetAll,
      prepare: navPrepareNavigation,
      markExecuting: navMarkExecuting,
      resolveArrival: navResolveArrival,
      setScene: function (id) { if (typeof window.__gvaSetScene === 'function') window.__gvaSetScene(id); },
      revision: function () { return _sceneRevision; },
      active: function () { return _activeNavigation; }
    };
  }

  /* ================================================================
     VOICE MODE — DEMO BROWSER ADAPTER (no paid provider)
     ================================================================
     UI/state terpisah dari adapter, sehingga adapter realtime (OpenAI
     WebRTC dsb.) bisa ditambahkan nanti tanpa membangun ulang UI, konteks,
     dan tool navigasi.

     KONTAK ADAPTER:
       start(), stop(), setMuted(bool), interrupt(), updateContext(ctx)
       events: onStatusChanged, onUserTurnCommitted, onAssistantResponse,
               onToolRequested, onError
     DEKLARASI KAPABILITAS:
       capabilities() -> { mode, transport, input, output, interrupt,
                           vad, providerConfigured, tools[] }

     Demo ini memakai SpeechRecognition (input) + speechSynthesis (output)
     + resolver/tool deterministik via api/guide-tool.php. Tidak memanggil
     provider berbayar, tidak memakai tts.php (Python Windows), dan TIDAK
     mengklaim interupsi suara saat AI berbicara (baseline bergantian).
     ================================================================ */
  var voiceCapabilities = function () {
    var hasRec = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
    var hasSyn = !!(window.speechSynthesis && window.SpeechSynthesisUtterance);
    var hasAudio = !!((window.AudioContext || window.webkitAudioContext) && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    return {
      supported: hasRec && hasSyn,
      input: hasRec ? 'speech-recognition' : 'none',
      output: hasSyn ? 'speech-synthesis' : 'none',
      interrupt: hasAudio,
      vad: hasAudio ? 'energy-aec' : null,
      providerConfigured: false,
      transport: 'demo-browser',
      tools: ['lookup_tour_information', 'resolve_navigation']
    };
  };
  // Live realtime hanya butuh mic + WebAudio (tanpa SpeechRecognition browser).
  function voiceAudioCapable() {
    try { return !!((window.AudioContext || window.webkitAudioContext) && navigator.mediaDevices && navigator.mediaDevices.getUserMedia); } catch (e) { return false; }
  }
  var voiceStatusTexts = {
    idle: { title: 'Mode suara terhenti', sub: 'Klik tombol Mulai di bawah untuk merekam kembali.' },
    requesting: { title: 'Menghubungkan\u2026', sub: 'Meminta izin mikrofon dan membuka sesi.' },
    connecting: { title: 'Menghubungkan\u2026', sub: 'Menyiapkan sesi percakapan.' },
    listening: { title: 'Saya mendengarkan', sub: 'Silakan bicara, Anda bisa menyela kapan saja.' },
    processing: { title: 'Saya sedang memproses\u2026', sub: 'Mencari fakta atau tujuan untuk Anda.' },
    speaking: { title: 'AI sedang menjawab', sub: 'Ketuk tombol Bicara untuk menyela.' },
    muted: { title: 'Mikrofon dibisukan', sub: 'Buka bisukan untuk melanjutkan percakapan.' },
    ended: { title: 'Sesi suara selesai', sub: 'Klik tombol Mulai di bawah untuk mulai bicara lagi.' },
    not_configured: { title: 'Mode suara belum dikonfigurasi', sub: 'Gunakan mode Teks untuk melanjutkan.' },
    error: { title: 'Mode suara AI terputus', sub: 'Silakan klik tombol Mulai untuk merekam kembali, atau cek izin mikrofon di browser.' }
  };
  var _voice = {
    sessionId: null,
    live: null,
    capabilities: null,
    state: 'idle',
    muted: false,
    turnSeq: 0,
    recognition: null,
    recognitionActive: false,
    finalTranscript: '',
    interimText: '',
    processing: false,
    speakingSeq: 0,
    speakingStartedAt: 0,
    bargeMonitor: null,
    endRequested: false,
    activeAbort: null,
    pendingChoice: null,     // klarifikasi navigasi yang masih berlaku
    lastTranscript: '',
    restartCount: 0,
    lastRestartAt: 0,
    timers: [],
    el: null,
    onStatus: null, onUserTurn: null, onAssistant: null, onTool: null, onError: null
  };
  /* Ikon SVG inline panel suara (tanpa emoji / tanpa dependensi eksternal). */
  function _voiceSvg(inner) { return '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + inner + '</svg>'; }
  var SVG_VOICE_STOP = _voiceSvg('<rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor" stroke="none"/>');
  var SVG_VOICE_PLAY = _voiceSvg('<polygon points="7 4 20 12 7 20" fill="currentColor" stroke="none"/>');
  var SVG_VOICE_CLIPBOARD = _voiceSvg('<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>');
  var SVG_VOICE_PHONE = _voiceSvg('<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>');
  var SVG_VOICE_MIC = _voiceSvg('<path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3z" fill="currentColor" stroke="none"/><path d="M19 10a7 7 0 0 1-14 0"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>');
  var SVG_VOICE_ARROW = _voiceSvg('<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>');
  var SVG_VOICE_USER = _voiceSvg('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>');
  var SVG_VOICE_BULB = _voiceSvg('<path d="M9 18h6M10 22h4"/><path d="M12 2a7 7 0 0 0-4.2 12.6c.6.5 1.2 1.4 1.2 2.4h6c0-1 .6-1.9 1.2-2.4A7 7 0 0 0 12 2z"/>');
  var SVG_VOICE_PIN = _voiceSvg('<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>');
  function voiceStatusMeta(s) { return voiceStatusTexts[s] || { title: s, sub: '' }; }
  function voiceSetStatus(s, detail) {
    _voice.state = s;
    var meta = voiceStatusMeta(s);
    var sub = meta.sub;
    if (detail) sub = sub ? (sub + ' ' + detail) : detail;
    _voice.statusDetail = sub;
    try { voiceSyncPanel(); } catch (e) {}
    if (typeof _voice.onStatus === 'function') _voice.onStatus({ state: s, text: meta.title });
  }
  /* Aksi tombol utama panel — memakai lifecycle voice existing, tanpa mesin baru. */
  var _voicePrimaryAt = 0;
  function voicePrimaryAction() {
    var now = Date.now();
    if (now - _voicePrimaryAt < 400) return; // cegah klik berulang (mute/recognition ganda)
    _voicePrimaryAt = now;
    var st = _voice.state;
    if (st === 'idle' || st === 'ended' || st === 'error' || st === 'not_configured') { voiceStartRecording(); return; }
    if (st === 'listening') { voiceSetMuted(true); return; }
    if (st === 'muted') { voiceSetMuted(false); return; }
    if (st === 'speaking') { voiceStopAnswer(); return; }
    // requesting/connecting/processing: tidak ada aksi aman.
  }
  /* Sinkronkan seluruh UI panel dengan state voice yang sebenarnya. */
  function voiceSyncPanel() {
    var d = _voice.el; if (!d) return;
    var st = _voice.state;
    var meta = voiceStatusMeta(st);

    d.panel.classList.toggle('tour-chat__voice-panel--speaking', st === 'speaking');
    d.panel.classList.toggle('tour-chat__voice-panel--rec', st === 'listening' || st === 'processing' || st === 'speaking');
    d.panel.classList.toggle('tour-chat__voice-panel--stopped', st === 'error' || st === 'ended' || st === 'idle' || st === 'not_configured');
    d.panel.classList.toggle('tour-chat__voice-panel--muted', st === 'muted');

    if (d.status) d.status.textContent = meta.title;
    if (d.miniStatus) d.miniStatus.textContent = meta.title;
    // Sub-status hanya tampil saat ada detail yang perlu ditindak (error/retry/catatan).
    if (d.statusSub) {
      var detail = String(_voice.statusDetail || '');
      var showSub = (st === 'error' || st === 'not_configured') || /mencoba lagi|batas pemakaian|terputus|sesi/i.test(detail);
      d.statusSub.textContent = showSub ? (detail || meta.sub || '') : '';
      d.statusSub.style.display = showSub ? '' : 'none';
    }

    // Cuplikan jawaban AI (teks aman; dipotong 3 baris oleh CSS).
    var lastAssistant = '';
    for (var i = d.transcriptHistory.length - 1; i >= 0; i--) {
      var h = d.transcriptHistory[i];
      if (h && h.role === 'assistant' && h.text) { lastAssistant = h.text; break; }
    }
    if (d.answer) {
      var showAnswer = false, answerText = '';
      if (st === 'speaking' && lastAssistant) { showAnswer = true; answerText = lastAssistant; }
      else if ((st === 'listening' || st === 'muted') && lastAssistant) { showAnswer = true; answerText = lastAssistant; }
      else if (st === 'idle' || st === 'ended') { showAnswer = true; answerText = 'Ketuk "Mulai ngobrol" untuk memulai percakapan suara.'; }
      else if (st === 'error' || st === 'not_configured') { showAnswer = true; answerText = 'Mode suara sedang tidak tersedia. Coba lagi, atau gunakan mode Teks.'; }
      d.answer.textContent = answerText;
      d.answer.style.display = showAnswer ? '' : 'none';
      d.answer.classList.toggle('tour-chat__voice-answer--hint', !lastAssistant || st === 'idle' || st === 'ended' || st === 'error' || st === 'not_configured');
    }

    // Baris "Anda": teks biasa (bukan input), dari state percakapan sebenarnya.
    var userText = cleanVoiceText((_voice.interimText || '') + ' ' + (_voice.finalTranscript || ''));
    if (!userText) {
      for (var j = d.transcriptHistory.length - 1; j >= 0; j--) {
        var u = d.transcriptHistory[j];
        if (u && u.role === 'user' && u.text) { userText = u.text; break; }
      }
    }
    if (d.userLine) {
      var showUser = !!userText && (st === 'listening' || st === 'muted' || st === 'processing' || st === 'speaking');
      d.userLine.style.display = showUser ? '' : 'none';
      if (d.userTx) d.userTx.textContent = userText ? ('Anda: ' + userText) : '';
    }

    // Tombol utama (state-driven).
    var label = 'Mulai ngobrol', icon = SVG_VOICE_PLAY, disabled = false, hint = false;
    if (st === 'idle') { label = 'Mulai ngobrol'; icon = SVG_VOICE_PLAY; }
    else if (st === 'ended') { label = 'Mulai lagi'; icon = SVG_VOICE_PLAY; }
    else if (st === 'error' || st === 'not_configured') { label = 'Coba lagi'; icon = SVG_VOICE_PLAY; }
    else if (st === 'requesting' || st === 'connecting') { label = 'Menyiapkan…'; icon = SVG_VOICE_MIC; disabled = true; }
    else if (st === 'listening') { label = 'Bisukan'; icon = SVG_VOICE_MIC; }
    else if (st === 'muted') { label = 'Lanjut bicara'; icon = SVG_VOICE_MIC; }
    else if (st === 'processing') { label = 'Memproses…'; icon = SVG_VOICE_MIC; disabled = true; }
    else if (st === 'speaking') { label = 'Sela & bicara'; icon = SVG_VOICE_MIC; hint = true; }
    if (d.primaryTx) d.primaryTx.textContent = label;
    if (d.primaryIc) d.primaryIc.innerHTML = icon;
    if (d.primaryBtn) { d.primaryBtn.disabled = disabled; d.primaryBtn.setAttribute('aria-label', label); }
    if (d.primaryHint) d.primaryHint.style.display = hint ? '' : 'none';

    // Hentikan: aktif hanya saat ada jawaban yang sedang dibacakan.
    if (d.stopBtn) d.stopBtn.disabled = (st !== 'speaking');

  }
  function voiceEmit(evt, payload) {
    if (evt === 'status' && typeof _voice.onStatus === 'function') _voice.onStatus(payload);
    if (evt === 'user' && typeof _voice.onUserTurn === 'function') _voice.onUserTurn(payload);
    if (evt === 'assistant' && typeof _voice.onAssistant === 'function') _voice.onAssistant(payload);
    if (evt === 'tool' && typeof _voice.onTool === 'function') _voice.onTool(payload);
    if (evt === 'error' && typeof _voice.onError === 'function') _voice.onError(payload);
  }
  function voiceToolUrl() { return basePath.replace(/\/?$/, '/') + 'api/guide-tool.php'; }
  function voiceTourContext() {
    // Kirim nama panorama terkurasi (AI Studio) ke server, bukan nama mentah
    // seperti "default". Nama mentah tetap disertakan sebagai alias agar
    // pencarian lama tetap cocok.
    var scenes = (data.scenes || []).map(function (s) {
      var curated = '';
      try { curated = sceneDisplayName(s); } catch (e) { curated = ''; }
      var aliases = (s.aliases || []).slice();
      [s.displayName, s.name, s.rawName].forEach(function (n) {
        if (n && String(n) !== curated && aliases.indexOf(n) < 0) aliases.push(n);
      });
      return {
        id: s.id, name: s.name, rawName: s.rawName,
        displayName: curated || s.displayName || s.name || s.id,
        aliases: aliases, description: s.description, highlights: s.highlights,
        facilities: s.facilities, whoFor: s.whoFor
      };
    });
    return { scenes: scenes, hotspots: data.hotspots, routes: data.routes, video360: data.video360 };
  }
  function voiceApiCall(payload) {
    if (_voice.activeAbort) _voice.activeAbort.abort();
    _voice.activeAbort = new AbortController();
    return fetch(voiceToolUrl(), {
      method: 'POST',
      signal: _voice.activeAbort.signal,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: { code: 'BAD_RESPONSE', message: 'Respons tidak valid', retryable: false } }; }); });
  }
  function voiceStopBargeMonitor() {
    var monitor = _voice.bargeMonitor;
    _voice.bargeMonitor = null;
    voiceEqStop();
    if (!monitor) return;
    if (monitor.raf) cancelAnimationFrame(monitor.raf);
    if (monitor.stream) monitor.stream.getTracks().forEach(function (track) { try { track.stop(); } catch (e) {} });
    if (monitor.ctx && monitor.ctx.state !== 'closed') { try { monitor.ctx.close(); } catch (e) {} }
  }
  /* ---- Equalizer real-time untuk voice wave: mengikuti frekuensi mic yang
     masuk (seperti equalizer musik). Menumpang stream mic sesi suara — tanpa
     getUserMedia tambahan. Bar CSS palsu jadi fallback saat EQ nonaktif. ---- */
  var _voiceEq = null;
  function voiceEqStop() {
    if (_voiceEq && _voiceEq.raf) { try { cancelAnimationFrame(_voiceEq.raf); } catch (e) {} }
    if (_voiceEq && _voiceEq.ctx && _voiceEq.ctx.state !== 'closed') { try { _voiceEq.ctx.close(); } catch (e2) {} }
    _voiceEq = null;
    try {
      var _w = _voice.el && _voice.el.wave;
      if (_w) {
        _w.classList.remove('tour-chat__voice-wave--live');
        var _b = _w.querySelector('.tour-chat__voice-wave-bars');
        if (_b) _b.innerHTML = '';
      }
    } catch (e3) {}
  }
  function voiceEqStart(stream) {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC || !stream) return;
    if (!_voice.el || !_voice.el.wave) return;
    voiceEqStop();
    var wave = _voice.el.wave;
    var barsBox = wave.querySelector('.tour-chat__voice-wave-bars');
    if (!barsBox) return;
    var ctx;
    try { ctx = new AC(); } catch (e) { return; }
    try { if (ctx.state === 'suspended' && ctx.resume) ctx.resume(); } catch (e0) {}
    var analyser = ctx.createAnalyser();
    analyser.fftSize = 512;
    analyser.smoothingTimeConstant = 0.72;
    try { ctx.createMediaStreamSource(stream).connect(analyser); }
    catch (e2) { try { ctx.close(); } catch (e3) {} return; }
    var N = 7;
    barsBox.innerHTML = '';
    var bars = [];
    for (var i = 0; i < N; i++) {
      var b = document.createElement('span');
      b.className = 'tour-chat__voice-eq-bar';
      barsBox.appendChild(b);
      bars.push(b);
    }
    wave.classList.add('tour-chat__voice-wave--live');
    var freq = new Uint8Array(analyser.frequencyBinCount);
    var outFreq = new Uint8Array(256);
    var smooth = [];
    var floor = [];
    for (var s0 = 0; s0 < N; s0++) { smooth.push(0); floor.push(0.05); }
    var eq = _voiceEq = { ctx: ctx, analyser: analyser, raf: 0 };
    function frame() {
      if (_voiceEq !== eq) return;
      eq.raf = requestAnimationFrame(frame);
      // Saat AI berbicara, indikator mengikuti audio keluaran yang benar-benar
      // diputar (jika tersedia). Selain itu, ikuti sinyal mikrofon asli.
      var useOut = _voice.speaking && _voiceOutMon && _voiceOutMon.analyser;
      var src = useOut ? _voiceOutMon.analyser : analyser;
      var buf = useOut ? outFreq : freq;
      src.getByteFrequencyData(buf);
      var maxBin = buf.length - 1;
      for (var k = 0; k < N; k++) {
        // Pemetaan logaritmik di pita suara (mulai bin 4: potong dengung/rumble bawah).
        var t = N === 1 ? 0 : k / (N - 1);
        var bin = Math.floor(4 + Math.pow(t, 1.6) * 108);
        if (bin > maxBin) bin = maxBin;
        var mag = buf[bin] / 255;
        var target;
        if (useOut) {
          target = Math.min(1, mag * 1.4);
        } else {
          // Noise gate adaptif: lantai noise diikuti perlahan, sinyal di bawahnya dibuang.
          var fl = floor[k] + (mag - floor[k]) * 0.03;
          floor[k] = fl;
          var gated = mag - fl * 2;
          if (gated < 0) gated = 0;
          target = Math.pow(Math.min(1, gated * 1.6), 1.5);
        }
        var prev = smooth[k];
        smooth[k] = prev + (target - prev) * (target > prev ? 0.5 : 0.14);
        bars[k].style.height = (8 + smooth[k] * 92).toFixed(1) + '%';
      }
    }
    frame();
  }
  /* Monitor energi audio yang sedang DIPUTAR (elemen Audio server). Barge
     sadar-gema: mic hanya dianggap menyela bila energinya jauh di atas
     bleed yang wajar dari playback (rasio, bukan ambang absolut). */
  var _voiceOutMon = null;
  function voiceOutEnsure() {
    try {
      if (_voiceOutMon && _voiceOutMon.ctx && _voiceOutMon.ctx.state !== 'closed') {
        if (_voiceOutMon.ctx.state === 'suspended' && _voiceOutMon.ctx.resume) { try { _voiceOutMon.ctx.resume(); } catch (eR) {} }
        return _voiceOutMon;
      }
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      var ctx = new AC();
      var analyser = ctx.createAnalyser();
      analyser.fftSize = 512;
      analyser.connect(ctx.destination);
      _voiceOutMon = { ctx: ctx, analyser: analyser, buf: new Uint8Array(analyser.fftSize) };
      if (ctx.state === 'suspended' && ctx.resume) { try { ctx.resume(); } catch (eR2) {} }
      return _voiceOutMon;
    } catch (e) { return null; }
  }
  function voiceOutTap(audio) {
    try {
      var m = voiceOutEnsure();
      if (!m || !audio || audio._outTapped) return;
      audio._outTapped = true;
      var src = m.ctx.createMediaElementSource(audio);
      src.connect(m.analyser);
    } catch (e2) {}
  }
  function voiceOutRms() {
    try {
      var m = _voiceOutMon;
      if (!m || !m.analyser) return 0;
      m.analyser.getByteTimeDomainData(m.buf);
      var sum = 0;
      for (var i = 0; i < m.buf.length; i++) { var v = (m.buf[i] - 128) / 128; sum += v * v; }
      return Math.sqrt(sum / m.buf.length);
    } catch (e3) { return 0; }
  }
  function voiceStartBargeMonitor(stream) {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC || !stream) { if (stream) stream.getTracks().forEach(function (track) { track.stop(); }); return; }
    voiceStopBargeMonitor();
    var ctx = new AC();
    var analyser = ctx.createAnalyser();
    analyser.fftSize = 512;
    ctx.createMediaStreamSource(stream).connect(analyser);
    var monitor = { ctx: ctx, analyser: analyser, stream: stream, buf: new Uint8Array(analyser.fftSize), raf: 0, floor: 0.008, hotFrames: 0 };
    _voice.bargeMonitor = monitor;
    voiceEqStart(stream);
    function sample() {
      if (_voice.bargeMonitor !== monitor) return;
      monitor.raf = requestAnimationFrame(sample);
      analyser.getByteTimeDomainData(monitor.buf);
      var sum = 0;
      for (var i = 0; i < monitor.buf.length; i++) {
        var value = (monitor.buf[i] - 128) / 128;
        sum += value * value;
      }
      var rms = Math.sqrt(sum / monitor.buf.length);
      if (!_voice.speaking) {
        monitor.floor = monitor.floor * 0.96 + Math.min(rms, 0.08) * 0.04;
        monitor.hotFrames = 0;
        return;
      }
      // Sela suara OTOMATIS nonaktif by design: interupsi hanya manual
      // (tombol Bicara / ketuk wave / tombol Hentikan) agar tak ada lagi
      // self-trigger dari bleed speaker. Monitor tetap jalan untuk adat lantai.
      monitor.hotFrames = 0;
      return;
    }
    sample();
  }
  var _voiceGreetStart = 0;
  // Riwayat terakhir (maks 6 giliran) untuk menyemai sesi baru — percakapan
  // lanjutan setelah "Akhiri" tetap punya konteks.
  function voiceRecentHistory() {
    try {
      var h = (_voice.el && _voice.el.transcriptHistory) || [];
      return h.slice(-6).map(function (m) { return { role: m.role, text: String(m.text || '') }; })
        .filter(function (m) { return m.text && (m.role === 'user' || m.role === 'assistant'); });
    } catch (e) { return []; }
  }
  // Teks sapaan (dipakai bicara + prefetch audio agar tanpa jeda).
  function voiceGreetingText() {
    var sc = (typeof currentScene === 'function') ? currentScene() : null;
    var loc = sc ? sceneDisplayName(sc) : '';
    var tour = tourName || 'tempat ini';
    var hasHistory = false;
    try { hasHistory = !!(_voice.el && _voice.el.transcriptHistory && _voice.el.transcriptHistory.length); } catch (eH) { hasHistory = false; }
    if (hasHistory) {
      return 'Selamat datang kembali! Senang bisa lanjut mengobrol. Silakan lanjutkan!';
    }
    return 'Halo, selamat datang di ' + tour + '! Saya pemandu kecerdasan buatan untuk Anda di ' + (loc ? loc : 'sini') + '. Silakan bicara saja!';
  }
  // Sapaan langsung saat mic diberikan — tanpa menunggu sesi server — sekali per voiceStart.
  function voiceGreetEarly() {
    try {
      if (_voiceGreetStart === _voice.startSeq) return;
      _voiceGreetStart = _voice.startSeq;
      var prevLive = _voice.capabilities && _voice.capabilities.transport === 'gemini-live-websocket';
      if (prevLive) return;
      var msg = voiceGreetingText();
      if (msg) voiceSpeak(msg);
    } catch (e) {}
  }
  function voiceStart() {
    if (_voice.state !== 'ended' && _voice.state !== 'idle') {
      // Pulihkan dari status buntu (error/terputus) agar entri mana pun
      // (tab Suara / Mulai ngobrol / Mulai) selalu bisa memulai ulang sesi.
      try { voiceStop(false); } catch (eVS) {}
      _voice.state = 'idle';
      try { voiceOpenPanel(); } catch (eOP) {}
    }
    {
      if (!voiceCapabilities().supported && !voiceAudioCapable()) { voiceSetStatus('error', '(browser tidak mendukung pengenalan/ucapan)'); voiceEmit('error', { code: 'UNSUPPORTED' }); return; }
      _voice.endRequested = false;
      _voice.speakingSeq++;
      _voice.startSeq = (_voice.startSeq || 0) + 1;
      _voice.restartCount = 0;
      _voice.sessionId = null;
      _voice.pendingChoice = null;
      _voice.demoFallback = false;
      voiceSetStatus('requesting');
      // Jalankan sesi server dan izin mikrofon secara paralel agar
      // recognition.start() tetap di dalam window user-gesture
      // (Chrome memblokir start() bila dipanggil setelah rantai async panjang).
      var sessionReady = voiceApiCall({ op: 'start_session', tourContext: voiceTourContext(), currentScene: currentSceneId(), sceneRevision: _sceneRevision, history: voiceRecentHistory() })
        .then(function (res) {
          if (!res || !res.ok) {
            var code = (res && res.error && res.error.code) || 'START_FAILED';
            if (code === 'VOICE_DISABLED') { voiceSetStatus('error', '(voice nonaktif — gunakan teks)'); }
            else if (code === 'NOT_CONFIGURED') { voiceSetStatus('not_configured'); }
            else voiceSetStatus('error', '(server)');
            voiceEmit('error', { code: code });
            return null;
          }
          _voice.sessionId = res.session_id;
          _voice.capabilities = res.capabilities || null;
          return res.session_id;
        })
        .catch(function () { voiceSetStatus('error', '(jaringan)'); voiceEmit('error', { code: 'NETWORK' }); return null; });

      function beginAfterPermission(stream) {
        sessionReady.then(function (sid) {
          if (!sid) {
            // Sesi gagal: hentikan sapaan yang mungkin sudah berbunyi.
            _voice.speakingSeq++;
            voiceStopVoiceAudio();
            try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (eCancel) {}
            _voice.speaking = false;
            return;
          }
          var isLive = _voice.capabilities && _voice.capabilities.transport === 'gemini-live-websocket';
          if (isLive) {
            // Potong sapaan TTS agar tak terpantul ke mic live.
            _voice.speakingSeq++;
            voiceStopVoiceAudio();
            try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (eC) {}
            _voice.speaking = false;
            voiceStartGeminiLive(stream);
          }
          else if (!_voice.speaking) voiceBeginListening();
        });
      }

      if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ audio: {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true
        } }).then(function (stream) {
          if (!(_voice.capabilities && _voice.capabilities.transport === 'gemini-live-websocket')) voiceStartBargeMonitor(stream);
          voiceGreetEarly();
          beginAfterPermission(stream);
        }).catch(function () {
          voiceSetStatus('error', '(izin mikrofon ditolak)');
          voiceEmit('error', { code: 'PERMISSION_DENIED' });
        });
      } else {
        beginAfterPermission(null);
      }
    }
  }
  function voiceBytesToBase64(bytes) {
    var binary = '', step = 0x8000;
    for (var i = 0; i < bytes.length; i += step) binary += String.fromCharCode.apply(null, bytes.subarray(i, Math.min(i + step, bytes.length)));
    return btoa(binary);
  }
  function voiceBase64ToBytes(value) {
    var binary = atob(value), out = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) out[i] = binary.charCodeAt(i);
    return out;
  }
  function voiceLiveSend(message) {
    if (_voice.live && _voice.live.ws && _voice.live.ws.readyState === WebSocket.OPEN) _voice.live.ws.send(JSON.stringify(message));
  }
  // Kirim pertanyaan teks lewat kanal live (dipakai tombol saran/pilihan saat live).
  function voiceLiveAsk(q) {
    if (!_voice.live) return false;
    try {
      voiceLiveSend({ clientContent: { turns: [{ role: 'user', parts: [{ text: String(q || '') }] }], turnComplete: true } });
      return true;
    } catch (e) { return false; }
  }
  function voiceLivePlay(base64) {
    var live = _voice.live;
    if (!live || !base64) return;
    var bytes = voiceBase64ToBytes(base64);
    var samples = new Int16Array(bytes.buffer, bytes.byteOffset, Math.floor(bytes.byteLength / 2));
    var buffer = live.outputCtx.createBuffer(1, samples.length, 24000);
    var channel = buffer.getChannelData(0);
    for (var i = 0; i < samples.length; i++) channel[i] = samples[i] / 32768;
    var source = live.outputCtx.createBufferSource();
    source.buffer = buffer;
    source.connect(live.outputCtx.destination);
    source.start(Math.max(live.outputCtx.currentTime, live.nextPlayAt));
    live.nextPlayAt = Math.max(live.outputCtx.currentTime, live.nextPlayAt) + buffer.duration;
    live.sources.push(source);
    source.onended = function () { var n = live.sources.indexOf(source); if (n >= 0) live.sources.splice(n, 1); };
    _voice.speaking = true;
    voiceSetStatus('speaking');
    ttsDuckMusic(true);
  }
  function voiceLiveInterrupt() {
    var live = _voice.live;
    if (!live) return;
    live.sources.forEach(function (source) { try { source.stop(); } catch (e) {} });
    live.sources = [];
    live.nextPlayAt = live.outputCtx ? live.outputCtx.currentTime : 0;
    _voice.speaking = false;
    ttsDuckMusic(false);
    voiceSetStatus('listening');
  }
  function voiceLiveTool(call) {
    var args = call.args || {};
    var isNav = call.name === 'resolve_navigation';
    var payload = {
      op: isNav ? 'resolve_navigation' : 'lookup_tour_information',
      session_id: _voice.sessionId,
      tourContext: voiceTourContext(),
      currentScene: currentSceneId(),
      sceneRevision: _sceneRevision,
      turn_id: call.id || ('live-' + (++_voice.turnSeq))
    };
    if (isNav) payload.destination_query = String(args.destination_query || args.query || '');
    else payload.query = String(args.query || '');
    voiceApiCall(payload).then(function (res) {
      if (res && res.ok) {
        if (isNav) voiceHandleNavResult(res, payload.destination_query);
        else voiceRenderRecs(res.recommendations || []);
      }
      voiceLiveSend({ toolResponse: { functionResponses: [{ id: call.id, name: call.name, response: res || { ok: false } }] } });
    }).catch(function () {
      voiceLiveSend({ toolResponse: { functionResponses: [{ id: call.id, name: call.name, response: { ok: false, error: 'tool_failed' } }] } });
    });
  }
  // Sapaan native transport live (model yang berbicara, bukan TTS).
  function voiceLiveGreet() {
    try {
      var sc = (typeof currentScene === 'function') ? currentScene() : null;
      var loc = sc ? sceneDisplayName(sc) : '';
      var hasH = false;
      try { hasH = !!(_voice.el && _voice.el.transcriptHistory && _voice.el.transcriptHistory.length); } catch (eH) {}
      var prompt = hasH
        ? 'Sapa pengunjung yang kembali mengobrol dengan hangat dalam Bahasa Indonesia. Sebut kamu senang bisa lanjut mengobrol' + (loc ? ' dan mereka masih di ' + loc : '') + '. Maksimal 2 kalimat pendek.'
        : 'Sapa pengunjung dengan hangat dalam Bahasa Indonesia. Strukturnya: selamat datang di ' + (tourName || 'tempat ini') + ', perkenalkan dirimu sebagai AI Guide' + (loc ? ', sebut mereka sedang di ' + loc : '') + ', dan ajak mereka bertanya atau minta diantar berkeliling. Maksimal 3 kalimat pendek.';
      voiceLiveSend({ clientContent: { turns: [{ role: 'user', parts: [{ text: prompt }] }], turnComplete: true } });
    } catch (e2) {}
  }
  // Bila Live tidak tersedia / kuota free tier habis → lanjutkan percakapan
  // lewat mode cadangan (STT browser + LLM teks + web search + TTS) tanpa
  // menghentikan sesi. Sesi server tetap sama sehingga konteks lanjut.
  function voiceFallbackToDemo(reason) {
    if (_voice.endRequested) return;
    var wasLive = !!_voice.live || (_voice.capabilities && _voice.capabilities.transport === 'gemini-live-websocket');
    if (!wasLive || _voice.demoFallback) return;
    _voice.demoFallback = true;
    if (_voice.live) {
      var live = _voice.live; _voice.live = null;
      try { live.sources.forEach(function (s) { try { s.stop(); } catch (e) {} }); } catch (e0) {}
      try { if (live.processor) live.processor.disconnect(); } catch (e1) {}
      try { if (live.ws) { live.ws.onmessage = null; live.ws.onerror = null; live.ws.onclose = null; live.ws.close(); } } catch (e2) {}
      try { if (live.inputCtx) live.inputCtx.close(); } catch (e3) {}
      try { if (live.outputCtx) live.outputCtx.close(); } catch (e4) {}
      try { if (live.stream) live.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e5) {} }); } catch (e6) {}
    }
    voiceEqStop();
    voiceStopVoiceAudio();
    try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e7) {}
    _voice.speaking = false;
    if (_voice.capabilities) _voice.capabilities.transport = 'demo-browser';
    voiceEmit('fallback', { to: 'demo', reason: reason || 'live_unavailable' });
    voiceSetStatus('listening', '(mode cadangan aktif)');
    if (!_voice.endRequested && !_voice.processing) voiceBeginListening();
  }
  function voiceStartGeminiLive(stream) {
    if (!stream) { voiceSetStatus('error', '(mikrofon tidak tersedia)'); return; }
    voiceSetStatus('connecting');
    voiceApiCall({ op: 'live_token', session_id: _voice.sessionId }).then(function (auth) {
      if (!auth || !auth.ok || !auth.token) throw new Error('live_token_failed');
      var AC = window.AudioContext || window.webkitAudioContext;
      var inputCtx = new AC({ sampleRate: 16000 });
      var outputCtx = new AC({ sampleRate: 24000 });
      var live = _voice.live = { ws: null, stream: stream, inputCtx: inputCtx, outputCtx: outputCtx, processor: null, sources: [], nextPlayAt: 0 };
      voiceEqStart(stream);
      var ws = live.ws = new WebSocket('wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1alpha.GenerativeService.BidiGenerateContent?access_token=' + encodeURIComponent(auth.token));
      ws.onopen = function () {
        voiceLiveSend({ setup: {
          model: auth.model,
          generationConfig: { responseModalities: ['AUDIO'] },
          systemInstruction: { parts: [{ text: 'Anda pemandu tur virtual berbahasa Indonesia. Jawab alami dan ringkas. Untuk fakta tur atau tempat sekitar, wajib panggil lookup_tour_information. Untuk perpindahan panorama, wajib panggil resolve_navigation. Jangan mengarang fakta atau tujuan.' }] },
          tools: [{ functionDeclarations: [
            { name: 'lookup_tour_information', description: 'Cari fakta tervalidasi tentang tur dan tempat sekitar.', parameters: { type: 'OBJECT', properties: { query: { type: 'STRING' } }, required: ['query'] } },
            { name: 'resolve_navigation', description: 'Validasi dan jalankan tujuan panorama.', parameters: { type: 'OBJECT', properties: { destination_query: { type: 'STRING' } }, required: ['destination_query'] } }
          ] }],
          inputAudioTranscription: {}, outputAudioTranscription: {}, realtimeInputConfig: { automaticActivityDetection: { disabled: false } }
        } });
      };
      ws.onmessage = function (event) {
        var msg; try { msg = JSON.parse(event.data); } catch (e) { return; }
        if (msg.setupComplete) {
          var source = inputCtx.createMediaStreamSource(stream);
          var processor = live.processor = inputCtx.createScriptProcessor(4096, 1, 1);
          source.connect(processor); processor.connect(inputCtx.destination);
          processor.onaudioprocess = function (ev) {
            if (_voice.muted || !_voice.live) return;
            var input = ev.inputBuffer.getChannelData(0), pcm = new Int16Array(input.length);
            for (var i = 0; i < input.length; i++) pcm[i] = Math.max(-32768, Math.min(32767, input[i] * 32768));
            voiceLiveSend({ realtimeInput: { audio: { mimeType: 'audio/pcm;rate=16000', data: voiceBytesToBase64(new Uint8Array(pcm.buffer)) } } });
          };
          voiceSetStatus('listening');
          voiceLiveGreet();
          return;
        }
        var content = msg.serverContent || {};
        if (content.interrupted) voiceLiveInterrupt();
        var transcript = content.inputTranscription && cleanVoiceText(content.inputTranscription.text || '');
        if (transcript) { _voice.liveIn = transcript; _voice.interimText = transcript; if (_voice.el) _voice.renderTranscript && _voice.renderTranscript(); }
        var outTx = content.outputTranscription && content.outputTranscription.text;
        if (typeof outTx === 'string' && cleanVoiceText(outTx)) _voice.liveOut = cleanVoiceText(outTx);
        var parts = content.modelTurn && content.modelTurn.parts || [];
        parts.forEach(function (part) { if (part.inlineData && part.inlineData.data) voiceLivePlay(part.inlineData.data); });
        if (msg.toolCall && msg.toolCall.functionCalls) msg.toolCall.functionCalls.forEach(voiceLiveTool);
        if (content.turnComplete) {
          _voice.speaking = false; ttsDuckMusic(false); voiceSetStatus('listening');
          if (!_voice.endRequested && _voice.el && _voice.el.transcriptHistory) {
            var li = cleanVoiceText(_voice.liveIn || '');
            var lo = cleanVoiceText(_voice.liveOut || '');
            if (li) _voice.el.transcriptHistory.push('Anda: ' + li);
            if (lo) _voice.el.transcriptHistory.push('Pemandu: ' + lo);
            _voice.liveIn = ''; _voice.liveOut = '';
            _voice.interimText = ''; _voice.finalTranscript = '';
            if (_voice.renderTranscript) _voice.renderTranscript();
          }
        }
      };
      ws.onerror = function () { voiceFallbackToDemo('live_error'); };
      ws.onclose = function () { if (!_voice.endRequested) voiceFallbackToDemo('live_closed'); };
    }).catch(function () { voiceFallbackToDemo('live_token_failed'); });
  }
  // Arbiter mic: browser hanya mengizinkan SATU SpeechRecognition aktif.
  // Mic teks (discovery/questionbar) mendaftarkan penghentinya; mode suara
  // mengambil alih saat mulai mendengarkan (panel teks sedang tersembunyi,
  // jadi arah sebaliknya tak mungkin via UI).
  function gvaSrRegisterStopper(fn) {
    try {
      var a = window.__gvaSrStoppers || (window.__gvaSrStoppers = []);
      if (a.indexOf(fn) < 0) a.push(fn);
    } catch (e) {}
  }
  function gvaSrStopOthers() {
    try {
      var a = window.__gvaSrStoppers || [];
      for (var i = 0; i < a.length; i++) { try { a[i](); } catch (e) {} }
    } catch (e2) {}
  }
  function voiceBeginListening() {
    gvaSrStopOthers();
    if (_voice.live) return; // live: input milik stream realtime, bukan recognition browser
    if (_voice.endRequested || _voice.speaking) return;
    voiceSetStatus('listening');
    _voice.muted = false;
    if (_voice.el) { _voice.el.muteBtn && (_voice.el.muteBtn.setAttribute('aria-pressed', 'false')); _voice.el.muteBtn && (_voice.el.muteBtn.classList.remove('tour-chat__voice-mute--on')); }
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { voiceSetStatus('error', '(pengenalan suara tidak didukung)'); return; }
    var rec = new SR();
    _voice.recognition = rec;
    rec.lang = 'id-ID';
    rec.interimResults = true;
    rec.continuous = true;
    rec.maxAlternatives = 1;
    _voice.finalTranscript = '';
    _voice.interimText = '';
    if (_voice.el) _voice.renderTranscript && _voice.renderTranscript();
    var pausedToSpeak = false;
    var _voiceSilenceTimer = null;
    function voiceResetSilence() {
      if (_voiceSilenceTimer) clearTimeout(_voiceSilenceTimer);
      // Jika ada interim/final, proses setelah 1800ms hening (fallback untuk kata pendek seperti "test")
      _voiceSilenceTimer = setTimeout(function () {
        if (_voice.recognition !== rec) return; // sesi basi
        var txt = cleanVoiceText(_voice.finalTranscript || _voice.interimText);
        if (txt && !_voice.processing && !_voice.endRequested) {
          try { rec.stop(); } catch (e2) {}
          _voice.finalTranscript = txt;
          _voice.interimText = '';
          _voice.recognitionActive = false;
          voiceProcessTurn(txt);
        }
      }, 1800);
    }
    rec.onstart = function () { if (_voice.recognition !== rec) return; _voice.recognitionActive = true; if (_voiceSilenceTimer) clearTimeout(_voiceSilenceTimer); };
    rec.onresult = function (e) {
      if (_voice.recognition !== rec) return; // handler basi dari sesi lama
      if (_voice.speaking) return;
      var interim = '';
      for (var i = e.resultIndex; i < e.results.length; i++) {
        var t = cleanVoiceText(e.results[i][0].transcript);
        if (e.results[i].isFinal) _voice.finalTranscript += (t ? t + ' ' : ''); else interim += (t ? t + ' ' : '');
      }
      _voice.interimText = interim;
      // Sinyal sehat: ada suara terdeteksi → jatah restart pulih penuh.
      if (_voice.finalTranscript || interim) { _voice.restartCount = 0; _voice.restartReasons = {}; }
      if (_voice.el) _voice.renderTranscript && _voice.renderTranscript();
      voiceResetSilence();
    };
    rec.onerror = function (ev) {
      if (_voice.recognition !== rec) return; // handler basi
      var errName = ev && ev.error ? ev.error : 'unknown';
      if (_voice.endRequested) return;
      if (errName === 'not-allowed' || errName === 'service-not-allowed') { voiceSetStatus('error', '(izin mikrofon ditolak)'); voiceEmit('error', { code: 'PERMISSION_DENIED' }); return; }
      if (errName === 'network') { voiceLimitedRestart('network'); return; }
      if (errName === 'no-speech') { voiceLimitedRestart('no-speech'); return; }
      voiceLimitedRestart(errName);
    };
    rec.onend = function () {
      if (_voiceSilenceTimer) { clearTimeout(_voiceSilenceTimer); _voiceSilenceTimer = null; }
      if (_voice.recognition !== rec) return; // onend basi: sesi baru sudah menggantikan
      _voice.recognitionActive = false;
      if (_voice.endRequested) return;
      var finalText = cleanVoiceText(_voice.finalTranscript || _voice.interimText);
      if (finalText && !pausedToSpeak) {
        _voice.interimText = '';
        voiceProcessTurn(finalText);
      } else if (!_voice.processing && !_voice.speaking) {
        voiceLimitedRestart('ended-empty');
      }
    };
    try { rec.start(); } catch (e) { voiceSetStatus('error', '(gagal memulai pengenalan)'); }
    function voiceLimitedRestart(reason) {
      var now = Date.now();
      if (now - _voice.lastRestartAt > 60000) { _voice.restartCount = 0; _voice.restartReasons = {}; }
      _voice.lastRestartAt = now;
      // Izin ditolak = gagal permanen, jangan loop.
      if (reason === 'not-allowed' || reason === 'service-not-allowed') { voiceSetStatus('error', '(izin mikrofon ditolak)'); voiceEmit('error', { code: 'PERMISSION_DENIED' }); return; }
      // Berakhir karena hening / dihentikan sendiri itu NORMAL (Chrome menutup
      // recognition saat senyap) — restart diam-diam, tidak menghabiskan jatah.
      var benign = (reason === 'no-speech' || reason === 'ended-empty' || reason === 'aborted');
      if (!benign) {
        _voice.restartReasons = _voice.restartReasons || {};
        _voice.restartReasons[reason] = (_voice.restartReasons[reason] || 0) + 1;
        if (_voice.restartCount >= 10) {
          var top = reason, topN = 0, kk;
          for (kk in _voice.restartReasons) { if (_voice.restartReasons[kk] > topN) { topN = _voice.restartReasons[kk]; top = kk; } }
          voiceSetStatus('error', '(pengenalan terputus: ' + top + ' — coba lagi)'); voiceEmit('error', { code: 'RESTART_LIMIT', reason: reason }); return;
        }
        _voice.restartCount++;
      }
      _voice.statusDetail = voiceStatusMeta('listening').sub + ' (mencoba lagi…)';
      try { voiceSyncPanel(); } catch (eR) {}
      var wait = benign ? 250 : Math.min(2500, Math.round(350 * Math.pow(1.5, Math.min(_voice.restartCount, 5))));
      setTimeout(function () { if (!_voice.endRequested && !_voice.processing && !_voice.speaking && _voice.recognition === rec) voiceBeginListening(); }, wait);
    }
  }
  function voiceProcessTurn(text) {
    if (_voice.processing || _voice.speaking) return;
    _voice.processing = true;
    _voice.turnSeq++;
    voiceSetStatus('processing');
    if (_voice.el && _voice.el.recs) _voice.el.recs.innerHTML = '';
    try { navCardHideFloat(); } catch (eNavFloat) {}
    voiceEmit('user', { text: text, turn: _voice.turnSeq });
    if (_voice.el) { _voice.el.transcriptHistory && (_voice.el.transcriptHistory.push('Anda: ' + text)); _voice.renderTranscript && _voice.renderTranscript(); }
    // Jangan pernah mengeksekusi navigasi dari transkrip parsial — hanya final.
    var intent = voiceClassifyIntent(text);
    if (intent === 'negation') {
      voiceSpeak('Baik, saya tidak melakukannya. Silakan sampaikan yang lain.');
      voiceFinishTurn();
      return;
    }
    // Fast-path deterministik (negation/next/drone/follow-up klarifikasi) memilih
    // tool langsung. Sisanya diserahkan ke Smart Router — satu panggilan LLM yang
    // memilih tool berdasarkan niat + konteks.
    var payload = {
      session_id: _voice.sessionId,
      tourContext: voiceTourContext(),
      currentScene: currentSceneId(),
      sceneRevision: _sceneRevision,
      turn_id: 'voice-' + _voice.turnSeq
    };
    if (intent === 'navigation') {
      payload.op = 'resolve_navigation';
      // Lanjutan klarifikasi: tombol mengirim "ke <label>"; angka ("1"/"yang
      // pertama") dipetakan ke opsi yang tersimpan.
      var dest = text;
      if (_voice.pendingChoice && _voice.pendingChoice.options) {
        var mm = (text || '').trim().toLowerCase().match(/^(?:yang\s+)?(?:nomor\s+)?(\d+)$/);
        if (mm) {
          var opt = _voice.pendingChoice.options[String(parseInt(mm[1], 10) - 1)];
          if (opt && opt.query) dest = opt.query;
        }
      }
      payload.destination_query = dest;
    } else if (intent === 'lookup') {
      payload.op = 'lookup_tour_information';
      payload.query = text;
    } else {
      payload.op = 'smart_route';
      payload.userQuery = text;
    }
    voiceEmit('tool', {
      tool: intent === 'navigation' ? 'resolve_navigation' : (intent === 'lookup' ? 'lookup_tour_information' : 'smart_route'),
      query: text
    });
    var requestStart = Date.now();
    voiceApiCall(payload).then(function (res) {
      if (_voice.endRequested) { voiceFinishTurn(true); return; }
      if (!res || !res.ok) {
        var code = (res && res.error && res.error.code) || 'TOOL_FAILED';
        if (code === 'SESSION_EXPIRED') { voiceSpeak('Sesi suara berakhir. Silakan mulai ulang percakapan suara.'); voiceStop(true); }
        else if (code === 'QUOTA_EXCEEDED') { voiceSpeak('Mode suara sementara tidak tersedia. Silakan gunakan teks.'); voiceStop(true); }
        else voiceSpeak('Maaf, saya tidak dapat memproses permintaan ini sekarang.');
        voiceEmit('error', { code: code });
        voiceFinishTurn();
        return;
      }
      // Dispatch mengikuti tool yang dikembalikan server — Smart Router boleh
      // mengoreksi niat fast-path bila ternyata salah.
      var tool = res.tool || (intent === 'navigation' ? 'resolve_navigation' : 'lookup_tour_information');
      if (tool === 'resolve_navigation') voiceHandleNavResult(res, text);
      else if (tool === 'none') voiceHandleNoneResult(res);
      else voiceHandleLookupResult(res, text);
    }).catch(function () {
      if (_voice.endRequested) { voiceFinishTurn(true); return; }
      voiceSpeak('Maaf, terjadi gangguan koneksi. Silakan coba lagi.');
      voiceEmit('error', { code: 'NETWORK' });
      voiceFinishTurn();
    });
  }
  // Kartu rekomendasi nearby (Google Maps) di panel suara — pola sama dengan mode teks.
  function voiceRenderRecs(list) {
    var d = _voice.el; if (!d || !d.recs) return;
    d.recs.innerHTML = '';
    (list || []).forEach(function (r) {
      if (!r || !r.name) return;
      var item = el('div', 'tour-chat__right-rec');
      var txt = el('div', 'tour-chat__right-rec-text');
      var meta = el('div', 'tour-chat__right-rec-meta');
      txt.appendChild(el('div', 'tour-chat__right-rec-name', r.name));
      if (r.type) meta.appendChild(el('span', 'tour-chat__right-rec-type', r.type));
      if (r.note) meta.appendChild(el('span', 'tour-chat__right-rec-note', r.note));
      txt.appendChild(meta);
      var link = el('a', 'tour-chat__right-rec-map', 'Buka Maps');
      if (r.url) { link.href = r.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; }
      else { link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true'); }
      item.appendChild(txt); item.appendChild(link);
      d.recs.appendChild(item);
    });
  }
  function voiceHandleLookupResult(res, text) {
    var hasRecs = !!(res.recommendations && res.recommendations.length);
    var reply = res.reply || (res.status === 'not_found' && !hasRecs ? 'Saya tidak menemukan informasi itu di data tur ini.' : '');
    if (res.status === 'not_found' && !hasRecs) reply = 'Saya belum memiliki informasi itu di data tur ini. Coba tanyakan tentang tempat lain.';
    voiceRenderRecs(hasRecs ? res.recommendations : []);
    voiceSpeak(reply);
    voiceEmit('assistant', { text: reply, status: res.status, sources: res.source_ids || [] });
    voiceFinishTurn();
  }
  // Balasan obrolan ringan (tool 'none') — server sudah menyusun teksnya.
  function voiceHandleNoneResult(res) {
    var reply = (res && (res.reply || (res.display && res.display.spoken))) || 'Baik.';
    voiceSpeak(reply);
    voiceEmit('assistant', { text: reply, status: (res && res.status) || 'answered', sources: [] });
    voiceFinishTurn();
  }
  function voiceHandleNavResult(res, text) {
    var st = res.status;
    if (st === 'ready' && res.action) {
      var label = (res.display && res.display.destination_label) || '';
      var sc = sceneById(res.action.destination_scene_id);
      var spoken = (res.display && res.display.spoken) || ('Baik, saya arahkan Anda ke ' + (label || (sc ? sceneDisplayName(sc) : res.action.destination_scene_id)) + '.');
      if (sc && sc.id === currentSceneId()) {
        voiceSpeak('Anda sudah berada di ' + (label || sceneDisplayName(sc)) + '.');
        voiceFinishTurn();
        return;
      }
      // Kartu dulu, panorama menyusul (~1,5 dtk) agar terasa diorkestrasi AI.
      var vcard = null;
      try { if (sc) vcard = showNavCard(sc.id); } catch (eNav) {}
      scheduleAiNavigation(res.action.destination_scene_id, vcard, { voice: true, onDone: function (plan) {
        if (!plan.ok) {
          if (plan.reason === 'navigation_busy') voiceSpeak('Sebentar, navigasi sebelumnya masih berjalan. Saya tunggu hasilnya dulu.');
          else voiceSpeak('Saya tidak dapat menjalankan tujuan itu sekarang.');
          return;
        }
        // Ucapkan versi ringkas setelah tiba — kecuali mode live
        // (model berbicara native; TTS ganda dilarang). Teks utuh di kartu/riwayat.
        if (!_voice.live) _voice.pendingNavigationSpeech = {
          sceneId: res.action.destination_scene_id,
          text: ttsLead(spoken, 2, 280)
        };
        // Lapor hasil aktual ke ledger server.
        voiceReportAction(res.action.action_id, 'pending', res.action.destination_scene_id);
      }});
      voiceFinishTurn();
      return;
    }
    if (st === 'already_there') {
      voiceSpeak((res.display && res.display.spoken) || ('Anda sudah berada di ' + (res.display && res.display.destination_label || 'lokasi itu') + '.'));
      voiceFinishTurn();
      return;
    }
    if (st === 'clarify') {
      var opts = res.clarification_options || {};
      var labels = Object.keys(opts).map(function (k) { return opts[k].label; });
      _voice.pendingChoice = { type: 'navigation', options: opts };
      voiceSpeak('Maksud Anda yang mana? ' + labels.join(', ') + '.');
      if (_voice.el) { _voice.el.choices && (_voice.el.choices.innerHTML = ''); Object.keys(opts).forEach(function (k) { var b = document.createElement('button'); b.type = 'button'; b.className = 'tour-chat__voice-choice'; b.textContent = opts[k].label; b.addEventListener('click', function () { _voice.pendingChoice = null; if (voiceLiveAsk('ke ' + opts[k].label)) return; voiceProcessTurn('ke ' + opts[k].label); }); _voice.el.choices.appendChild(b); }); }
      voiceFinishTurn();
      return;
    }
    if (st === 'unavailable') {
      voiceSpeak(res.reason || 'Tujuan tersebut belum ada dalam cakupan tur yang tervalidasi.');
      voiceFinishTurn();
      return;
    }
    voiceSpeak('Saya belum dapat memproses tujuan tersebut.');
    voiceFinishTurn();
  }
  function voiceReportAction(actionId, result, sceneId) {
    if (!_voice.sessionId || !actionId) return;
    return voiceApiCall({ op: 'report_action_result', session_id: _voice.sessionId, action_id: actionId, result: result, scene_actual: sceneId, scene_revision: _sceneRevision }).catch(function () { return null; });
  }
  // Klasifikasi intent cepat (0ms, tanpa LLM). HANYA kasus 100% pasti — sisanya
  // 'smart' → Smart Router (satu panggilan LLM) yang memilih tool.
  function voiceClassifyIntent(text) {
    var t = ' ' + (text || '').toLowerCase() + ' ';
    // 1) Negasi eksplisit — ditangani lokal.
    if (/(tidak mau|nggak mau|gak mau|jangan|batal|bukan|kurangi|stop|berhenti)/.test(t) && /(ke |menuju|arahkan|antar|bawa|pindah|tujuan|ke sana|ke situ)/.test(t)) return 'negation';
    // 2) Lanjutan pilihan klarifikasi yang masih berlaku ("1", "yang pertama", "ke <label>").
    if (_voice.pendingChoice) {
      var bare = (text || '').trim().toLowerCase();
      if (/^(yang\s+)?(nomor\s+)?(\d+|pertama|kedua|ketiga|keempat|kelima)$/.test(bare) || /^ke\s+/.test(bare)) return 'navigation';
    }
    // 3) "ke mana lagi / lanjut" → navigasi berikutnya (deterministik di server).
    if (/\b(ke mana|kemana)(?:\s+(lagi|berikutnya|selanjutnya))?\b|\b(lanjut|berikutnya|selanjutnya|next)\b/.test(t)) return 'navigation';
    // 4) "drone" → navigasi (2 view drone).
    if (/\bdrone\b/.test(t)) return 'navigation';
    // 5) Pertanyaan moda transportasi / arah dari luar kota — pasti info, bukan navigasi.
    if (/(naik (apa|gimana|bagaimana|kendaraan)|pakai (apa|kendaraan|transportasi)|kendaraan apa|transportasi (apa|umum|yang)|(gimana|bagaimana) (caranya |saya |aku )?(pergi|ke )|(cara|jalur) (pergi|dari))/i.test(t)) return 'lookup';
    // 6) Selebihnya: Smart Router memilih tool berdasarkan LLM + konteks.
    return 'smart';
  }
  function cleanVoiceText(s) {
    if (!s) return '';
    return s.replace(/[\u00A0\u1680\u2000-\u200B\u202F\u205F\u3000\uFEFF\s]+/g, ' ').replace(/^\s+|\s+$/g, '');
  }
  var _voiceAudio = null;
  function voiceStopVoiceAudio() {
    if (_voiceAudio) { try { _voiceAudio.onended = null; _voiceAudio.onerror = null; _voiceAudio.pause(); } catch (e) {} _voiceAudio = null; }
  }
  // Versi lisan ringkas: kalimat-kalimat awal saja (teks utuh tetap di panel/riwayat).
  function ttsLead(text, maxSent, maxChars) {
    var t = String(text || '');
    var parts = t.match(/[^.!?]+[.!?]+["')]?|[^.!?]+$/g) || [t];
    var out = [], len = 0, ms = maxSent || 2, mc = maxChars || 280;
    for (var i = 0; i < parts.length && i < ms; i++) {
      var p = String(parts[i] || '').trim();
      if (!p) continue;
      if (len + p.length > mc && out.length) break;
      out.push(p); len += p.length + 1;
    }
    return out.length ? out.join(' ') : t;
  }
  // URL TTS voice: dilembutkan (rate -5, pitch +2) agar tidak tegas.
  function voiceTtsUrl(t) { return basePath.replace(/\/?$/, '/') + 'api/tts.php?text=' + encodeURIComponent(t) + '&lang=id&rate=-5&pitch=%2B2'; }
  function voiceTtsUrl(t) { return basePath.replace(/\/?$/, '/') + 'api/tts.php?text=' + encodeURIComponent(t) + '&lang=id&rate=-5&pitch=%2B2'; }
  // Prefetch audio sapaan saat panel dibuka agar VO langsung bunyi tanpa jeda.
  var _voicePrefetch = {};
  function voicePrefetchUrl(url) {
    if (!url || _voicePrefetch[url] || typeof fetch !== 'function') return;
    fetch(url).then(function (r) { return (r && r.ok) ? r.blob() : null; }).then(function (blob) {
      if (!blob) return;
      try {
        var keys = Object.keys(_voicePrefetch);
        if (keys.length > 5) { try { URL.revokeObjectURL(_voicePrefetch[keys[0]]); } catch (e0) {} delete _voicePrefetch[keys[0]]; }
        _voicePrefetch[url] = URL.createObjectURL(blob);
      } catch (e2) {}
    }).catch(function () {});
  }
  function voicePrefetchGreeting() {
    try {
      var msg = voiceGreetingText();
      if (!msg) return;
      // Hemat kuota: prefetch kalimat PERTAMA saja (penentu delay masuk).
      var first = ttsChunkify(ttsPhonetic(msg), 0)[0];
      if (first) voicePrefetchUrl(voiceTtsUrl(first.t));
    } catch (e3) {}
  }
  /* Putar jawaban per kalimat: server Edge neural dulu (prefetch 1 kalimat ke
     depan agar jeda minim), fallback browser prosody bila server gagal. */
  function voicePlayChunks(chunks, mySeq, finish) {
    var idx = 0;
    var useServer = ttsServerAllowed();
    var srvRetried = false;
    var srvMidFails = 0;
    var lastTtsHttp = 0;
    var cache = {};
    var fetching = {};
    function alive() { return mySeq === _voice.speakingSeq && !_voice.endRequested; }
    // Gagal chunk server: chunk PERTAMA boleh coba browser utuh; di TENGAH
    // jawaban jangan ganti suara — retry sekali, lalu lewati kalimat (lanjut
    // server). Dua gagal beruntun = server down: baru alihkan sisa ke browser.
    function serverChunkFailed(i) {
      if (!alive()) return;
      if (i === 0 && !srvRetried) { srvRetried = true; setTimeout(function () { if (alive()) playServer(0); }, 800); return; }
      if (i === 0) { useServer = false; ttsServerFailed(); quotaNote(); if (alive()) playBrowser(0); return; }
      srvMidFails++;
      if (srvMidFails >= 2) { useServer = false; ttsServerFailed(); quotaNote(); if (alive()) playBrowser(i); return; }
      if (alive()) playServer(i + 1);
    }
    // Info jujur saat jatuh ke suara cadangan karena kuota/batas server.
    function quotaNote() {
      try {
        if (lastTtsHttp === 429) {
          _voice.statusDetail = 'Server suara sibuk (batas pemakaian) — memakai suara cadangan.';
          voiceSyncPanel();
        }
      } catch (eQ) {}
    }
    function alive() { return mySeq === _voice.speakingSeq && !_voice.endRequested; }
    function ensureAudio(i, cb) {
      if (i >= chunks.length) { cb(null); return; }
      if (cache[i]) { cb(cache[i]); return; }
      if (fetching[i]) { fetching[i].push(cb); return; }
      var key = voiceTtsUrl(chunks[i].t);
      if (_voicePrefetch[key]) {
        var pre = _voicePrefetch[key];
        delete _voicePrefetch[key];
        var pa = null;
        try { pa = new Audio(pre); } catch (ePre) { pa = null; }
        cb(pa);
        return;
      }
      if (typeof fetch !== 'function') { cb(null); return; }
      fetching[i] = [cb];
      var actrl = null;
      try { if (typeof AbortController === 'function') actrl = new AbortController(); } catch (eAC) { actrl = null; }
      var atimer = 0;
      if (actrl) { atimer = setTimeout(function () { try { actrl.abort(); } catch (eA) {} }, 10000); }
      fetch(key, actrl ? { signal: actrl.signal } : undefined).then(function (r) {
        if (r) lastTtsHttp = r.status || 0;
        return (r && r.ok) ? r.blob() : null;
      }).then(function (blob) {
        if (atimer) clearTimeout(atimer);
        var cbs = fetching[i]; delete fetching[i];
        var audio = null;
        if (blob) { ttsServerOk(); try { audio = new Audio(URL.createObjectURL(blob)); } catch (e) { audio = null; } }
        if (audio && alive()) cache[i] = audio;
        cbs.forEach(function (fn) { try { fn(audio); } catch (e2) {} });
      }).catch(function () {
        if (atimer) clearTimeout(atimer);
        var cbs = fetching[i]; delete fetching[i];
        cbs.forEach(function (fn) { try { fn(null); } catch (e3) {} });
      });
    }
    function playServer(i) {
      if (!alive()) return;
      if (i >= chunks.length) { finish(); return; }
      ensureAudio(i, function (audio) {
        if (!alive()) return;
        if (!audio) { serverChunkFailed(i); return; }
        if (i + 3 < chunks.length) { ensureAudio(i + 1, function () {}); ensureAudio(i + 2, function () {}); ensureAudio(i + 3, function () {}); }
        else { for (var pa = i + 1; pa < chunks.length; pa++) ensureAudio(pa, function () {}); }
        _voiceAudio = audio;
        voiceOutTap(audio);
        audio.onended = function () {
          try { URL.revokeObjectURL(audio.src); } catch (e) {}
          if (cache[i] === audio) delete cache[i];
          _voiceAudio = null;
          srvMidFails = 0;
          if (alive()) playServer(i + 1);
        };
        audio.onerror = function () {
          _voiceAudio = null;
          serverChunkFailed(i);
        };
        audio.volume = 1;
        _voice.audioStartedAt = Date.now();
        try {
          var pr = audio.play();
          if (pr && pr.catch) pr.catch(function () { if (alive()) playBrowser(i); });
        } catch (e2) { if (alive()) playBrowser(i); }
      });
    }
    function playBrowser(i) {
      if (!alive()) return;
      if (i >= chunks.length || !ttsSupported()) { finish(); return; }
      ttsEnsureVoices(function () {
        if (!alive()) return;
        try {
          var synth = window.speechSynthesis;
          try { if (synth.paused) synth.resume(); } catch (e0) {}
          var voice = ttsPickVoice();
          var c = chunks[i];
          var u = new SpeechSynthesisUtterance(c.t);
          if (voice) { try { u.voice = voice; } catch (e3) {} }
          var pr3 = ttsProsody(c.kind, c.emph);
          u.lang = (voice && voice.lang) || 'id-ID'; u.rate = pr3.rate; u.pitch = pr3.pitch; u.volume = 1;
          var stepped = false;
          function step() { if (stepped) return; stepped = true; if (alive()) playBrowser(i + 1); }
          u.onend = step; u.onerror = step;
          _voice.audioStartedAt = Date.now();
          synth.speak(u);
        } catch (e5) { if (alive()) playBrowser(i + 1); }
      });
    }
    if (useServer) playServer(0);
    else playBrowser(0);
  }
  function voiceSpeak(text) {
    // Mode live berbicara via audio native model — TTS browser/server dilarang
    // agar tidak menimpa percakapan realtime.
    if (_voice.live) return;
    var mySeq = ++_voice.speakingSeq;
    voiceSetStatus('speaking');
    voiceStopVoiceAudio();
    try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) {}
    var clean = String(text || '').replace(/https?:\/\/\S+/g, ' ').replace(/[*_`#>|~]/g, ' ').replace(/\s+/g, ' ').trim();
    if (!clean) { voiceSetStatus('listening'); return; }
    // SpeechRecognition tetap berhenti agar gema TTS tidak pernah masuk transkrip.
    // Stream AEC terpisah hanya mengukur energi untuk menyela, tanpa membuat teks.
    _voice.speaking = true;
    _voice.speakingStartedAt = Date.now();
    if (_voice.recognitionActive && _voice.recognition) { try { _voice.recognition.stop(); } catch (e) {} }
    _voice.finalTranscript = ''; _voice.interimText = '';
    if (_voice.el) _voice.renderTranscript && _voice.renderTranscript();
    ttsDuckMusic(true);
    if (_voice.el) { _voice.el.transcriptHistory && (_voice.el.transcriptHistory.push('Pemandu: ' + clean)); _voice.renderTranscript && _voice.renderTranscript(); }
    voiceEmit('assistant', { text: clean });
    var chunks = ttsChunkify(ttsPhonetic(clean), 1500);
    if (!chunks.length) { voiceSetStatus('listening'); return; }
    var done = false;
    function finish() {
      if (done) return; done = true;
      if (mySeq !== _voice.speakingSeq) return;
      _voice.speaking = false;
      ttsDuckMusic(false);
      if (!_voice.endRequested) voiceSetStatus('listening');
      // Jeda kecil agar audio speaker benar-benar selesai sebelum mic aktif lagi.
      setTimeout(function () { if (mySeq === _voice.speakingSeq && !_voice.endRequested) voiceResumeListening(); }, 350);
    }
    voicePlayChunks(chunks, mySeq, finish);
  }
  function voiceResumeListening() {
    if (_voice.live) return; // live: VAD + giliran milik provider, bukan recognition browser
    if (_voice.endRequested || _voice.processing || _voice.speaking) return;
    _voice.processing = false;
    _voice.finalTranscript = '';
    setTimeout(function () { if (!_voice.endRequested && !_voice.processing && !_voice.recognitionActive) voiceBeginListening(); }, 250);
  }
  function voiceFinishTurn(force) {
    _voice.processing = false;
    if (force) return;
    if (!_voice.endRequested) voiceResumeListening();
  }
  function voiceStopAnswer() {
    if (_voice.live) { voiceLiveInterrupt(); return; }
    // Hentikan jawaban saat ini tanpa mengakhiri sesi.
    _voice.speakingSeq++;
    _voice.speaking = false;
    voiceStopVoiceAudio();
    try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) {}
    ttsDuckMusic(false);
    if (!_voice.endRequested) { voiceSetStatus('listening'); voiceResumeListening(); }
  }
  // Hentikan rekaman tapi panel tetap terbuka — pengguna bisa mulai lagi via tombol yang sama.
  function voiceStopRecording() {
    voiceStop(false);
    _voice.state = 'idle';
    voiceOpenPanel();
    voiceSetStatus('idle', '(rekaman dihentikan — ketuk Mulai untuk bicara lagi.)');
  }
  // Mulai (ulang) rekaman setelah terputus/selesai.
  function voiceStartRecording() {
    voiceStop(false);
    _voice.state = 'idle';
    voiceOpenPanel();
    voiceStart();
  }
  function voiceSetMuted(m) {
    _voice.muted = !!m;
    if (_voice.el) { _voice.el.muteBtn && (_voice.el.muteBtn.setAttribute('aria-pressed', _voice.muted ? 'true' : 'false')); _voice.el.muteBtn && (_voice.el.muteBtn.classList.toggle('tour-chat__voice-mute--on', _voice.muted)); }
    if (_voice.muted) {
      voiceSetStatus('muted');
      if (_voice.recognitionActive && _voice.recognition) { try { _voice.recognition.stop(); } catch (e) {} }
    } else if (!_voice.endRequested) {
      voiceSetStatus('listening');
      if (!_voice.recognitionActive && !_voice.processing) voiceBeginListening();
    }
  }
  function voiceInterrupt() { voiceStopAnswer(); }
  function voiceStop(hard) {
    _voice.endRequested = true;
    _voice.speakingSeq++;
    voiceStopVoiceAudio();
    voiceStopSuggest();
    voiceEqStop();
    voiceStopBargeMonitor();
    if (_voice.live) {
      var live = _voice.live; _voice.live = null;
      live.sources.forEach(function (source) { try { source.stop(); } catch (e) {} });
      try { if (live.processor) live.processor.disconnect(); } catch (e) {}
      try { if (live.ws) live.ws.close(); } catch (e) {}
      try { if (live.stream) live.stream.getTracks().forEach(function (track) { track.stop(); }); } catch (e) {}
      try { if (live.inputCtx) live.inputCtx.close(); } catch (e) {}
      try { if (live.outputCtx) live.outputCtx.close(); } catch (e) {}
    }
    _voice.pendingChoice = null;
    _voice.speaking = false;
    _voice.speakingSeq++;
    if (_voice.activeAbort) { try { _voice.activeAbort.abort(); } catch (e) {} _voice.activeAbort = null; }
    if (_voice.recognition) { try { _voice.recognition.stop(); } catch (e) {} _voice.recognition = null; }
    _voice.recognitionActive = false;
    try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) {}
    ttsDuckMusic(false);
    _voice.timers.forEach(function (t) { clearTimeout(t); }); _voice.timers = [];
    if (_voice.sessionId) { voiceApiCall({ op: 'end_session', session_id: _voice.sessionId }).catch(function () {}); _voice.sessionId = null; }
    voiceSetStatus('ended');
    if (_voice.el && _voice.el.panel) voiceHidePanel();
    voiceSyncLoc();
    // Riwayat transkrip dipertahankan — "Akhiri" tidak menghapus percakapan.
    // Sesi berikutnya lanjut dari riwayat (sapaan "Selamat datang kembali").
    if (hard) { _voice.state = 'idle'; }
  }
  function voiceShowHistory() {
    // Riwayat percakapan suara tampil di sidebar (sama seperti mode teks),
    // bukan di dalam panel suara.
    _historySource = 'voice';
    try { setUIState(UIState.EXPANDED); } catch (e) {}
  }

  /* ================================================================
     VOICE UI v2 — panel percakapan ringkas: header (identitas + aksi),
     isi (indikator audio + status + cuplikan jawaban + baris "Anda"),
     footer (Hentikan · Sela & bicara · Akhiri). Memakai controller/state
     voice yang sama — tidak ada mesin kedua.
     ================================================================ */
  function voiceBuildPanel() {
    if (_voice.el) return _voice.el;
    var panel = el('div', 'tour-chat__voice-panel');
    panel.setAttribute('aria-hidden', 'true');
    panel.setAttribute('role', 'region');
    panel.setAttribute('aria-label', 'Mode Suara AI Guide');

    // Header: identitas AI Guide + aksi Riwayat & ciutkan.
    var header = el('div', 'tour-chat__voice-header');
    var brandWrap = el('div', 'tour-chat__voice-brandwrap');
    var brand = el('div', 'tour-chat__voice-brand');
    brand.appendChild(el('span', 'tour-chat__voice-brand-icon', '\u2726'));
    brand.appendChild(el('span', 'tour-chat__voice-brand-text', 'AI GUIDE'));
    brandWrap.appendChild(brand);
    var miniStatus = el('span', 'tour-chat__voice-mini-status', '');
    brandWrap.appendChild(miniStatus);
    var headerActions = el('div', 'tour-chat__voice-header-actions');
    var histBtn = el('button', 'tour-chat__voice-histbtn'); histBtn.type = 'button'; histBtn.setAttribute('aria-label', 'Lihat riwayat percakapan');
    var _hIc = el('span', 'tour-chat__voice-histbtn-ic'); _hIc.innerHTML = SVG_VOICE_CLIPBOARD;
    histBtn.appendChild(_hIc);
    histBtn.appendChild(el('span', 'tour-chat__voice-histbtn-tx', 'Riwayat'));
    histBtn.addEventListener('click', function () { voiceShowHistory(); });
    var collapseBtn = el('button', 'tour-chat__voice-collapse'); collapseBtn.type = 'button';
    collapseBtn.setAttribute('aria-expanded', 'true'); collapseBtn.setAttribute('aria-label', 'Ciutkan panel suara');
    collapseBtn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    collapseBtn.addEventListener('click', function () {
      var col = panel.classList.toggle('tour-chat__voice-panel--collapsed');
      collapseBtn.setAttribute('aria-expanded', col ? 'false' : 'true');
      collapseBtn.setAttribute('aria-label', col ? 'Perluas panel suara' : 'Ciutkan panel suara');
    });
    headerActions.appendChild(histBtn); headerActions.appendChild(collapseBtn);
    header.appendChild(brandWrap); header.appendChild(headerActions);

    // Isi percakapan: indikator audio kecil + status + cuplikan jawaban + baris "Anda".
    var body = el('div', 'tour-chat__voice-body');
    var indicator = el('div', 'tour-chat__voice-indicator');
    indicator.setAttribute('aria-hidden', 'true');
    var wave = el('div', 'tour-chat__voice-wave');
    wave.appendChild(el('div', 'tour-chat__voice-wave-bars'));
    for (var wb = 0; wb < 7; wb++) wave.appendChild(el('span', 'tour-chat__voice-wave-bar'));
    indicator.appendChild(wave);
    var conv = el('div', 'tour-chat__voice-conv');
    var status = el('div', 'tour-chat__voice-status');
    var statusSub = el('div', 'tour-chat__voice-status-sub');
    var answer = el('div', 'tour-chat__voice-answer');
    answer.setAttribute('aria-live', 'polite');
    var userLine = el('div', 'tour-chat__voice-userline');
    var userIc = el('span', 'tour-chat__voice-userline-ic'); userIc.innerHTML = SVG_VOICE_USER;
    var userTx = el('span', 'tour-chat__voice-userline-tx');
    userLine.appendChild(userIc); userLine.appendChild(userTx);
    conv.appendChild(status); conv.appendChild(answer); conv.appendChild(userLine); conv.appendChild(statusSub);
    body.appendChild(indicator); body.appendChild(conv);

    var transcriptHistory = [];

    // Pilihan klarifikasi
    var choices = el('div', 'tour-chat__voice-choices');

    // Kartu rekomendasi (Google Maps) — jawaban nearby, pola sama dengan mode teks
    var recs = el('div', 'tour-chat__voice-recs');

    // Footer: Hentikan (sekunder) · Sela & bicara (utama) · Akhiri (merah).
    var footer = el('div', 'tour-chat__voice-footer');
    var stopBtn = el('button', 'tour-chat__voice-btn tour-chat__voice-btn--stop'); stopBtn.type = 'button'; stopBtn.setAttribute('aria-label', 'Hentikan jawaban');
    var _stIc = el('span', 'tour-chat__voice-btn-ic'); _stIc.innerHTML = SVG_VOICE_STOP;
    stopBtn.appendChild(_stIc);
    stopBtn.appendChild(el('span', 'tour-chat__voice-btn-tx', 'Hentikan'));
    stopBtn.addEventListener('click', function () {
      if (stopBtn.disabled) return;
      if (_voice.state === 'speaking') voiceStopAnswer();
    });
    var primaryWrap = el('div', 'tour-chat__voice-primary-wrap');
    var primaryBtn = el('button', 'tour-chat__voice-btn tour-chat__voice-btn--primary'); primaryBtn.type = 'button';
    var _pIc = el('span', 'tour-chat__voice-btn-ic'); _pIc.innerHTML = SVG_VOICE_MIC;
    var _pTx = el('span', 'tour-chat__voice-btn-tx', 'Mulai ngobrol');
    primaryBtn.appendChild(_pIc); primaryBtn.appendChild(_pTx);
    primaryBtn.addEventListener('click', function () { if (primaryBtn.disabled) return; voicePrimaryAction(); });
    var primaryHint = el('div', 'tour-chat__voice-primary-hint', 'Ketuk untuk menyela jawaban');
    primaryWrap.appendChild(primaryBtn); primaryWrap.appendChild(primaryHint);
    var endBtn = el('button', 'tour-chat__voice-btn tour-chat__voice-btn--end'); endBtn.type = 'button'; endBtn.setAttribute('aria-label', 'Akhiri percakapan');
    var _eIc = el('span', 'tour-chat__voice-btn-ic'); _eIc.innerHTML = SVG_VOICE_PHONE;
    endBtn.appendChild(_eIc);
    endBtn.appendChild(el('span', 'tour-chat__voice-btn-tx', 'Akhiri'));
    endBtn.addEventListener('click', function () {
      // Akhiri = tutup mode AI sepenuhnya, kembali ke panorama (Pano2VR fullscreen).
      voiceStop(true);
      _voiceMode = false;
      var _vr = document.getElementById('tour-chat');
      if (_vr) _vr.classList.remove('tour-chat--voice-mode');
      voiceSyncTab();
      voiceRestoreTextPanels();
      voicePauseBgMusic(false);
      setUIState(UIState.CLOSED);
    });
    footer.appendChild(stopBtn); footer.appendChild(primaryWrap); footer.appendChild(endBtn);

    // Riwayat kini memakai sidebar existing (voiceShowHistory). Di sini hanya
    // normalisasi entri transkrip agar sidebar & UI panel bisa membacanya.
    var _origPush = transcriptHistory.push.bind(transcriptHistory);
    transcriptHistory.push = function (line) {
      var role = 'assistant', text = String(line == null ? '' : line);
      if (text.indexOf('Anda: ') === 0) { role = 'user'; text = text.slice(6); }
      else if (text.indexOf('Pemandu: ') === 0) { text = text.slice(8); }
      _origPush({ role: role, text: text, at: Date.now() });
      try { voiceSyncPanel(); } catch (e) {}
    };

    panel.appendChild(header);
    panel.appendChild(body);
    panel.appendChild(choices);
    panel.appendChild(recs);
    panel.appendChild(footer);

    // Pil lokasi mode suara (kiri atas, di luar panel bawah).
    var loc = el('div', 'tour-chat__voice-loc');
    loc.setAttribute('aria-hidden', 'true');
    loc.setAttribute('aria-label', 'Posisi panorama saat ini');
    var locIcon = el('span', 'tour-chat__voice-loc-icon'); locIcon.innerHTML = SVG_VOICE_PIN;
    loc.appendChild(locIcon);
    loc.appendChild(el('span', 'tour-chat__voice-loc-label', 'Anda di'));
    var locName = el('span', 'tour-chat__voice-loc-name', '');
    loc.appendChild(locName);

    var d = { panel: panel, header: header, body: body, indicator: indicator, wave: wave, status: status, statusSub: statusSub, answer: answer, userLine: userLine, userTx: userTx, choices: choices, recs: recs, stopBtn: stopBtn, primaryBtn: primaryBtn, primaryIc: _pIc, primaryTx: _pTx, primaryHint: primaryHint, endBtn: endBtn, histBtn: histBtn, collapseBtn: collapseBtn, miniStatus: miniStatus, transcriptHistory: transcriptHistory, loc: loc, locName: locName, renderTranscript: voiceSyncPanel, renderHistory: voiceSyncPanel };
    _voice.el = d;
    _voice.renderTranscript = voiceSyncPanel;
    return d;
  }
  var _voicePanelGen = 0;
  function voiceStartSuggest() {}
  function voiceStopSuggest() {}
  function voiceOpenPanel() {
    var d = voiceBuildPanel();
    if (!d.panel.parentNode) {
      var root = document.getElementById('tour-chat');
      if (root) root.appendChild(d.panel);
      else document.body.appendChild(d.panel);
    }
    var gen = ++_voicePanelGen;
    d.panel.classList.add('tour-chat__voice-panel--active');
    d.panel.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        if (gen !== _voicePanelGen) return;
        d.panel.classList.add('tour-chat__voice-panel--in');
      });
    });
    voicePrefetchGreeting();
    voiceStartSuggest();
    voiceSyncLoc();
    try { voiceSyncPanel(); } catch (e) {}
  }
  function voiceHidePanel(immediate) {
    var d = _voice.el;
    if (!d || !d.panel) return;
    var gen = ++_voicePanelGen;
    d.panel.classList.remove('tour-chat__voice-panel--in');
    d.panel.setAttribute('aria-hidden', 'true');
    if (immediate) { d.panel.classList.remove('tour-chat__voice-panel--active'); return; }
    setTimeout(function () {
      if (gen !== _voicePanelGen) return;
      d.panel.classList.remove('tour-chat__voice-panel--active');
    }, 320);
  }
  function voiceUpdateLoc() {
    try {
      var name = '';
      if (typeof currentScene === 'function' && typeof sceneDisplayName === 'function') {
        var sc = currentScene();
        if (sc) name = sceneDisplayName(sc);
      }
      if (_voice.el && _voice.el.locName && _voice.el._locName !== name) {
        _voice.el._locName = name;
        _voice.el.locName.textContent = name || '';
      }
    } catch (e) {}
  }
  function voiceSyncLoc() {
    try {
      if (!_voice.el || !_voice.el.loc) return;
      var show = !!(_voice.el.panel && _voice.el.panel.classList.contains('tour-chat__voice-panel--active'));
      if (show && !_voice.el.loc.parentNode) {
        var root = document.getElementById('tour-chat');
        if (root) root.appendChild(_voice.el.loc);
        else document.body.appendChild(_voice.el.loc);
      }
      _voice.el.loc.classList.toggle('tour-chat__voice-loc--active', show);
      _voice.el.loc.setAttribute('aria-hidden', show ? 'false' : 'true');
      if (show) voiceUpdateLoc();
    } catch (e2) {}
  }

  /* ================================================================
     MODE TAB — Teks / Suara (sesuai konsep: tab di kanan atas desktop,
     top bar di mobile). Mode Suara menyembunyikan panel kiri/kanan/bawah
     dan menampilkan hanya kartu suara di atas panorama.
     ================================================================ */
  var _voiceMode = false;
  var _modeTab = null; // { tab, textBtn, voiceBtn }
  function voiceBuildTab() {
    if (_modeTab) return _modeTab;
    var tab = el('div', 'tour-chat__mode-tab');
    tab.setAttribute('role', 'tablist');
    tab.setAttribute('aria-label', 'Mode percakapan');
    var textBtn = el('button', 'tour-chat__mode-tab-btn tour-chat__mode-tab-btn--text'); textBtn.type = 'button'; textBtn.setAttribute('role', 'tab'); textBtn.setAttribute('aria-selected', 'true');
    var ti = el('span', 'tour-chat__mode-tab-ic'); ti.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>';
    textBtn.appendChild(ti);
    textBtn.appendChild(el('span', 'tour-chat__mode-tab-label', 'Teks'));
    var voiceBtn = el('button', 'tour-chat__mode-tab-btn tour-chat__mode-tab-btn--voice'); voiceBtn.type = 'button'; voiceBtn.setAttribute('role', 'tab'); voiceBtn.setAttribute('aria-selected', 'false');
    // Icon "voice AI": gelombang suara + spark AI (bukan mic).
    var vi = el('span', 'tour-chat__mode-tab-ic'); vi.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="10" x2="4" y2="14"></line><line x1="8" y1="7" x2="8" y2="17"></line><line x1="12" y1="4" x2="12" y2="20"></line><line x1="16" y1="7" x2="16" y2="17"></line><line x1="20" y1="10" x2="20" y2="14"></line></svg>';
    voiceBtn.appendChild(vi);
    voiceBtn.appendChild(el('span', 'tour-chat__mode-tab-label', 'Suara'));
    tab.appendChild(textBtn); tab.appendChild(voiceBtn);
    var caps = voiceCapabilities();
    if (!caps.supported && !voiceAudioCapable()) { voiceBtn.disabled = true; voiceBtn.title = 'Browser tidak mendukung pengenalan suara / ucapan'; voiceBtn.classList.add('tour-chat__mode-tab-btn--disabled'); }
    textBtn.addEventListener('click', function () { voiceSetMode('text'); });
    voiceBtn.addEventListener('click', function () { if (!voiceBtn.disabled) voiceSetMode('voice'); });
    var root = document.getElementById('tour-chat');
    if (root) root.appendChild(tab); else document.body.appendChild(tab);
    _modeTab = { tab: tab, textBtn: textBtn, voiceBtn: voiceBtn };
    return _modeTab;
  }
  function voiceSyncTab() {
    var t = _modeTab || voiceBuildTab();
    t.textBtn.classList.toggle('tour-chat__mode-tab-btn--active', !_voiceMode);
    t.voiceBtn.classList.toggle('tour-chat__mode-tab-btn--active', _voiceMode);
    t.textBtn.setAttribute('aria-selected', _voiceMode ? 'false' : 'true');
    t.voiceBtn.setAttribute('aria-selected', _voiceMode ? 'true' : 'false');
  }
  /** Sembunyikan panel teks (kiri/kanan/bawah/objek/lokasi) saat mode suara. */
  function voiceHideTextPanels() {
    var root = document.getElementById('tour-chat');
    if (!root) return;
    var sel = '.tour-chat__left, .tour-chat__right-stack, .tour-chat__questionbar, .tour-chat__objects-panel, .tour-chat__location, .tour-chat__arrival, .tour-chat__history, .tour-chat__context-sheet, .tour-chat__mobile-sheet';
    var els = root.querySelectorAll(sel);
    for (var i = 0; i < els.length; i++) {
      els[i].setAttribute('data-gva-textpanel', '1');
      els[i].style.display = 'none';
      els[i].classList.remove('tour-chat__left--visible');
      els[i].classList.remove('tour-chat__right-stack--visible');
      els[i].classList.remove('tour-chat__questionbar--visible');
      els[i].classList.remove('tour-chat__location--visible');
      els[i].classList.remove('tour-chat__history--visible');
      els[i].classList.remove('tour-chat__context-sheet--visible');
      els[i].classList.remove('tour-chat__arrival--visible');
    }
  }
  /** Kembalikan tampilan panel teks saat keluar dari mode suara. */
  function voiceRestoreTextPanels() {
    var root = document.getElementById('tour-chat');
    if (!root) return;
    var els = root.querySelectorAll('[data-gva-textpanel]');
    for (var i = 0; i < els.length; i++) {
      els[i].removeAttribute('data-gva-textpanel');
      els[i].style.display = '';
    }
  }
  /* Pause musik latar selama mode suara aktif; resume saat kembali ke teks. */
  var _voiceMusicPaused = false;
  function voicePauseBgMusic(pause) {
    try {
      var p = (typeof window.pano !== 'undefined' && window.pano) ? window.pano : ((window.skin && window.skin.player) ? window.skin.player : null);
      if (!p) return;
      if (pause) {
        if (_voiceMusicPaused) return;
        var playing = false;
        try { playing = (typeof p.isPlaying === 'function') ? !!p.isPlaying('Music Background') : false; } catch (e) {}
        if (!playing) return;
        try { if (typeof p.pauseSound === 'function') { p.pauseSound('Music Background'); _voiceMusicPaused = true; } } catch (e2) {}
      } else {
        if (!_voiceMusicPaused) return;
        _voiceMusicPaused = false;
        try { if (typeof p.playSound === 'function') p.playSound('Music Background', '1'); } catch (e3) {}
      }
    } catch (e) { /* noop */ }
  }
  function voiceSetMode(mode) {
    var toVoice = mode === 'voice';
    var root = document.getElementById('tour-chat');
    if (root) root.classList.toggle('tour-chat--voice-mode', toVoice);
    if (toVoice === _voiceMode && _voice.state !== 'idle' && _voice.state !== 'ended') { voiceSyncTab(); voiceHideTextPanels(); return; }
    _voiceMode = toVoice;
    voiceSyncTab();
    voicePauseBgMusic(toVoice);
    var d = voiceBuildPanel();
    if (toVoice) {
      // Suara: hanya kartu suara + tab di atas panorama.
      voiceHideTextPanels();
      voiceOpenPanel();
      // Mobile: tanpa split — panorama fullscreen seperti mode Pano2VR, panel suara floating.
      try {
        if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
          document.body.classList.remove('ai-guide-split', 'ai-guide-expanded');
          var _vc = document.getElementById('container');
          if (_vc) { _vc.style.height = '100%'; }
          setTimeout(function () { try { var _c = document.getElementById('container'); if (_c && window.pano && window.pano.setViewerSize) window.pano.setViewerSize(_c.offsetWidth, _c.offsetHeight); } catch (e) {} }, 80);
        }
      } catch (e2) {}
      if (_voice.state === 'idle' || _voice.state === 'ended') voiceStart();
      else voiceOpenPanel();
    } else {
      // Teks: tutup media suara, kembalikan panel teks.
      if (_voice.state !== 'idle' && _voice.state !== 'ended') voiceStop(true);
      voiceHidePanel();
      voiceSyncLoc();
      voiceRestoreTextPanels();
      setUIState(UIState.WORKSPACE);
    }
  }

  // Per-panorama navigation exits: each thumbnail (images/ht_node_thumb_1_<node>_<suffix>.webp)
  // corresponds to a navigation hotspot leading to <target> scene, labelled <title>.
  var _panoExits = {
    "node39": [{ suffix: 'Point01', target: 'node73', title: 'Galeri Museum Kereta Api' }, { suffix: 'Point02', target: 'node40', title: 'Galeri Museum Kereta Api' }],
    "node40": [{ suffix: 'Point01', target: 'node39', title: 'Galeri Museum Kereta Api' }, { suffix: 'Point02', target: 'node41', title: 'Galeri Museum Kereta Api' }],
    "node41": [{ suffix: 'Point01', target: 'node40', title: 'Galeri Museum Kereta Api' }, { suffix: 'Point02', target: 'node42', title: 'Galeri Museum Kereta Api' }],
    "node42": [{ suffix: 'Point01', target: 'node41', title: 'Galeri Museum Kereta Api' }],
    "node43": [{ suffix: 'Point01', target: 'node71', title: 'Galeri Museum Kereta Api' }, { suffix: 'Point02', target: 'node68', title: 'Pintu Keluar Utama' }],
    "node44": [{ suffix: 'Point01', target: 'node60', title: 'Pintu Keluar' }],
    "node45": [{ suffix: 'Point01', target: 'node61', title: 'Lawang Sewu' }, { suffix: 'Point02', target: 'node56', title: 'Foodcourt' }, { suffix: 'Point03', target: 'node69', title: 'Koridor UMKM' }],
    "node46": [{ suffix: 'Point01', target: 'node58', title: 'Pintu Keluar' }, { suffix: 'Point02', target: 'node48', title: 'Struktur Atap Arsitektur' }],
    "node47": [{ suffix: 'Point01', target: 'node48', title: 'Pintu Keluar' }],
    "node48": [{ suffix: 'Point01', target: 'node46', title: 'Pintu Keluar' }, { suffix: 'Point02', target: 'node47', title: 'Struktur Atap Arsitektur' }],
    "node49": [{ suffix: 'Point01', target: 'node65', title: 'Lorong Gedung' }, { suffix: 'Point02', target: 'node69', title: 'Koridor UMKM' }],
    "node50": [{ suffix: 'Point01', target: 'node62', title: 'Lawang Sewu' }, { suffix: 'Point02', target: 'node63', title: 'Monumen Kereta Api' }, { suffix: 'Point03', target: 'node52', title: 'Balkon' }],
    "node51": [{ suffix: 'Point01', target: 'node61', title: 'Lawang Sewu' }, { suffix: 'Point02', target: 'node64', title: 'Monumen Kereta Api' }, { suffix: 'Point03', target: 'node53', title: 'Balkon' }],
    "node52": [{ suffix: 'Point01', target: 'node50', title: 'Lawang Sewu' }],
    "node53": [{ suffix: 'Point01', target: 'node51', title: 'Lawang Sewu' }],
    "node55": [{ suffix: 'Point01', target: 'node59', title: 'Pintu Masuk' }],
    "node56": [{ suffix: 'Point01', target: 'node45', title: 'Halaman Belakang' }],
    "node57": [{ suffix: 'Point01', target: 'node61', title: 'Pintu Keluar' }, { suffix: 'Point02', target: 'node59', title: 'Ruang Pameran Sejarah' }, { suffix: 'Point03', target: 'node73', title: 'Galeri Museum Kereta Api' }],
    "node58": [{ suffix: 'Point01', target: 'node59', title: 'Koridor Gedung A' }, { suffix: 'Point02', target: 'node57', title: 'Pintu Keluar' }, { suffix: 'Point03', target: 'node46', title: 'Struktur Atap Arsitektur' }],
    "node59": [{ suffix: 'Point01', target: 'node57', title: 'Pintu Keluar' }, { suffix: 'Point02', target: 'node58', title: 'Koridor Gedung A' }, { suffix: 'Point03', target: 'node55', title: 'Balkon' }],
    "node60": [{ suffix: 'Point01', target: 'node61', title: 'Lawang Sewu' }, { suffix: 'Point02', target: 'node44', title: 'Pintu Masuk' }, { suffix: 'Point03', target: 'node45', title: 'Halaman Belakang' }, { suffix: 'Point05', target: 'node60', title: 'Exit' }],
    "node61": [{ suffix: 'Point01', target: 'node57', title: 'Gedung A Ruang Pameran' }, { suffix: 'Point02', target: 'node60', title: 'Gedung C Ruang Informasi' }, { suffix: 'Point03', target: 'node45', title: 'Halaman Belakang' }, { suffix: 'Point04', target: 'node64', title: 'Monumen Kereta Api' }, { suffix: 'Point05', target: 'node67', title: 'Gedung E' }, { suffix: 'Point06', target: 'node68', title: 'Koridor UMKM' }],
    "node62": [{ suffix: 'masuksiang', target: 'node57', title: 'Gedung A Ruang Pameran' }, { suffix: 'Point01', target: 'node63', title: 'Monumen Kereta Api' }],
    "node63": [{ suffix: 'Point01', target: 'node62', title: 'Pintu Masuk' }],
    "node64": [{ suffix: 'Point01', target: 'node61', title: 'Lawang Sewu' }, { suffix: 'Point02', target: 'node67', title: 'Pintu Masuk' }],
    "node65": [{ suffix: 'Point01', target: 'node66', title: 'Lorong Gedung' }, { suffix: 'Point02', target: 'node49', title: 'UMKM' }],
    "node66": [{ suffix: 'Point01', target: 'node69', title: 'Koridor UMKM' }, { suffix: 'Point02', target: 'node65', title: 'Lorong Gedung' }, { suffix: 'Point03', target: 'node67', title: 'Pintu Keluar' }],
    "node67": [{ suffix: 'Point01', target: 'node61', title: 'Lawang Sewu' }, { suffix: 'Point02', target: 'node66', title: 'Lorong Gedung' }, { suffix: 'Point03', target: 'node64', title: 'Monumen Kereta Api' }],
    "node68": [{ suffix: 'Point01', target: 'node69', title: 'Koridor UMKM' }, { suffix: 'Point02', target: 'node61', title: 'Lawang Sewu' }, { suffix: 'Point03', target: 'node72', title: 'Galeri Museum Kereta Api' }],
    "node69": [{ suffix: 'Point01', target: 'node68', title: 'Koridor UMKM' }, { suffix: 'Point02', target: 'node45', title: 'Halaman Belakang' }, { suffix: 'Point03', target: 'node66', title: 'Lorong Gedung' }],
    "node70": [{ suffix: 'Point01', target: 'node71', title: 'Galeri Museum Kereta Api' }],
    "node71": [{ suffix: 'Point01', target: 'node72', title: 'Galeri Museum Kereta Api' }, { suffix: 'Point02', target: 'node70', title: 'Galeri Museum Kereta Api' }, { suffix: 'Point03', target: 'node43', title: 'Balkon Koridor' }],
    "node72": [{ suffix: 'Point01', target: 'node68', title: 'Pintu Keluar' }, { suffix: 'Point02', target: 'node71', title: 'Galeri Museum Kereta Api' }],
    "node73": [{ suffix: 'Point01', target: 'node39', title: 'Galeri Museum Kereta Api' }, { suffix: 'Point02', target: 'node61', title: 'Lawang Sewu' }, { suffix: 'Point03', target: 'node57', title: 'Gedung A Ruang Pameran' }]
  };
  function panoExits(nodeId) { return (data.panoExits && data.panoExits[nodeId]) || _panoExits[nodeId] || []; }
  function panoThumbnailUrl(nodeId, suffix) { return basePath + '../images/ht_node_thumb_1_' + nodeId + '_' + suffix + '.webp'; }
  var _knowledgeData = null;
  var _knowledgeLoading = null;
  var _objCoverCache = {};
  var _objCoverWarmed = false;
  var _ttsEnabled = false;
  var _ttsWarmedVoices = false;
  var _ttsVoicesReady = false;
  var _ttsSeq = 0;
  var _ttsDuckedMusic = false;
  try { _ttsEnabled = window.localStorage.getItem('gva-tts-enabled') === '1'; } catch (e) { _ttsEnabled = false; }
  var _providerHealth = null;
  var _providerHealthLoading = null;
  var _backendAvailable = null;
  var _streamState = null;
  var _pendingObjectFocus = null;
  var _pendingVideoPlay = null;
  var _oiPositions = null;
  var _oiPositionsLoading = null;

  /* ================================================================
     UI STATE MACHINE: CLOSED | DISCOVERY | WORKSPACE | EXPANDED
     ================================================================ */
  var UIState = { CLOSED: 'closed', DISCOVERY: 'discovery', WORKSPACE: 'workspace', EXPANDED: 'expanded' };
  var _uiState = UIState.CLOSED;
  var _currentPresentation = null;
  var _dom = null;
  var _conversationHistory = [];
  // Sumber data sidebar riwayat: 'text' (mode teks) atau 'voice' (mode suara).
  var _historySource = 'text';
  function historyMessages() {
    if (_historySource === 'voice') {
      try { return (_voice.el && _voice.el.transcriptHistory) || []; } catch (e) { return []; }
    }
    return _conversationHistory;
  }

  /* ================================================================
     AI INTELLIGENCE — UNTOUCHED
     ================================================================ */
  function normalize(value) {
    return value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
  }
  /* Hybrid suggested question: string (AI) or {q,mode:'ai'|'fix',answer} (fixed answer) */
  function sqQ(s){ return typeof s === 'string' ? s : (s && s.q ? s.q : ''); }
  function sqMode(s){ return (typeof s === 'object' && s && s.mode) ? s.mode : 'ai'; }
  function sqAnswer(s){ return (typeof s === 'object' && s && s.answer) ? s.answer : ''; }
  function getCuratedAnswer(query) {
    var scene = currentScene();
    var si = scene ? getSceneIdentityEntry(scene.id) : null;
    if (!si || !si.suggestedQuestions || !si.suggestedQuestions.length) return null;
    var normQ = normalize(query);
    for (var i = 0; i < si.suggestedQuestions.length; i++) {
      var sq = si.suggestedQuestions[i];
      if (sqMode(sq) === 'fix' && sqAnswer(sq) && normalize(sqQ(sq)) === normQ) return sqAnswer(sq);
    }
    return null;
  }

  /* Curated Q&A database (team-editable, per-tour: data/curated-qa.json).
     Answered before AI pipeline; never shown in AI suggestions. */
  var _curatedQA = null, _curatedQALoading = null;
  function loadCuratedQA() { if (_curatedQA) return Promise.resolve(_curatedQA); if (_curatedQALoading) return _curatedQALoading; _curatedQALoading = fetch(basePath + 'data/curated-qa.json').then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (!d || !d.items) return null; _curatedQA = d.items; return _curatedQA; }).catch(function () { return null; }); return _curatedQALoading; }
  function matchCuratedQA(query) {
    if (!_curatedQA || !_curatedQA.length) return null;
    var text = normalize(query); if (!text) return null;
    var best = null, bestScore = 0;
    for (var i = 0; i < _curatedQA.length; i++) {
      var it = _curatedQA[i];
      var score = 0;
      if (normalize(it.question) === text) score = 100;
      else if (normalize(it.question).indexOf(text) !== -1) score = Math.max(score, 80);
      (it.aliases || []).forEach(function (a) { var na = normalize(a); if (na === text) score = Math.max(score, 90); else if (text.indexOf(na) !== -1) score = Math.max(score, na.length * 2); });
      (it.keywords || []).forEach(function (k) { var nk = normalize(k); if (nk && text.indexOf(nk) !== -1) score += 5; });
      if (score > bestScore) { bestScore = score; best = it; }
    }
    // Threshold 10: keyword-only hits need >=2 distinct keywords (tour-name
    // alone, e.g. "lawang sewu", must not match everything).
    return best && bestScore >= 10 ? best : null;
  }

  function loadCatalog() {
    if (!catalogPath) return Promise.resolve(null);
    if (_catalog) return Promise.resolve(_catalog);
    return fetch(basePath + catalogPath).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (!d || !d.destinations) return null; _catalog = d; return d; }).catch(function () { return null; });
  }
  var _catalogTermMap = null;
  function buildCatalogTermMap(catalog) {
    if (_catalogTermMap) return _catalogTermMap;
    _catalogTermMap = {};
    catalog.categories.forEach(function (cat) {
      var terms = [cat.name.toLowerCase()].concat((cat.aliases || []).map(function (a) { return a.toLowerCase(); }));
      terms.forEach(function (t) { if (t) _catalogTermMap[t] = cat.id; });
    });
    return _catalogTermMap;
  }
  function detectDiscoveryCategory(query) {
    if (!_catalog) return null;
    var text = normalize(query);
    var termMap = buildCatalogTermMap(_catalog);
    var bestCat = null, bestScore = 0;
    Object.keys(termMap).forEach(function (term) { if (text.indexOf(term) !== -1 && term.length > bestScore) { bestScore = term.length; bestCat = termMap[term]; } });
    if (bestCat) return bestCat;
    for (var i = 0; i < _catalog.categories.length; i++) {
      var cat = _catalog.categories[i];
      if (cat.aliases) { for (var j = 0; j < cat.aliases.length; j++) { if (text.indexOf(cat.aliases[j].toLowerCase()) !== -1) return cat.id; } }
    }
    return null;
  }
  function searchDestinations(query, categoryId) {
    if (!_catalog) return [];
    var text = normalize(query);
    var dests = _catalog.destinations;
    if (categoryId) dests = dests.filter(function (d) { return d.categoryId === categoryId; });
    return dests.map(function (d) {
      var score = 0; var nameNorm = normalize(d.name);
      if (text === nameNorm) score = 100;
      else if (text.indexOf(nameNorm) !== -1) score = Math.max(score, nameNorm.length * 2);
      else if (nameNorm.indexOf(text) !== -1) score = Math.max(score, text.length * 2);
      (d.aliases || []).forEach(function (alias) { var a = normalize(alias); if (text.indexOf(a) !== -1) score = Math.max(score, a.length + 5); if (a.indexOf(text) !== -1) score = Math.max(score, text.length + 5); });
      if (categoryId && d.categoryId === categoryId) score += 3;
      if (d.virtualTourAvailable) score += 0.5;
      return { destination: d, score: score };
    }).filter(function (s) { return s.score > 0; }).sort(function (a, b) { return b.score - a.score; });
  }
  function isDestinationDiscoveryQuery(query) {
    var text = normalize(query);
    if (/(sekitar|terdekat|dekat|nearby|around|di sini|sini)\b/.test(text) && /\b(masjid|gereja|rumah sakit|apotek|klinik|hotel|restoran|cafe|bank|atm|spbu|parkir|terminal|stasiun)\b/.test(text)) return false;
    return [/\b(ada|apakah ada|tersedia|bisakah|bisa)\b.*\b(masjid|gereja|vihara|klenteng|museum|universitas|kampus|rumah sakit|rs|mall|restoran|kuliner|hotel|stasiun|bandara|pantai|taman|pasar|kampung|wisata|tempat)\b/, /\b(masjid|gereja|vihara|klenteng|museum|universitas|kampus|rumah sakit|rs|mall|restoran|kuliner|hotel|stasiun|bandara)\b.*\b(di semarang|sekitar|lain|ada|bisa)\b/, /\b(ada|apakah)\b.*\b(tempat ibadah|destinasi wisata|tempat wisata)\b/, /\b(mau cari|cari|mau lihat|lihat|mau kunjungi|kunjungi)\b.*\b(masjid|gereja|museum|universitas|rumah sakit|restoran|mall|hotel|kuliner)\b/, /\b(tempat makan|tempat ibadah|pusat belanja|oleh-oleh)\b/, /\b(wisata|rekreasi)\b.*\b(lain|lainnya|selain)\b/].some(function (p) { return p.test(text); });
  }
  function isDestinationNameQuery(query) {
    if (!_catalog) return false;
    var text = normalize(query);
    var stripped = text.replace(/\b(buka|open|jalankan|mulai|start|masuk|kunjungi|visit|lihat|cari|mau ke|mau buka|ingin ke)\b/g, '').trim();
    for (var i = 0; i < _catalog.destinations.length; i++) {
      var d = _catalog.destinations[i]; var dName = normalize(d.name);
      if (dName === text || dName === stripped) return true;
      if (dName.length >= 5 && stripped.indexOf(dName) !== -1) return true;
      if (d.aliases) { for (var j = 0; j < d.aliases.length; j++) { var a = normalize(d.aliases[j]); if (a === text || a === stripped) return true; if (a.length >= 5 && stripped.indexOf(a) !== -1) return true; } }
    }
    return false;
  }
  function isVirtualTourOpenQuery(query) { var t = normalize(query); return /\b(buka|open|jalankan|run|mulai|start|masuk|enter)\b/.test(t) && /\b(tur|tour|virtual|360)\b/.test(t); }
  function isConfirmationResponse(query) { var t = normalize(query); if (t.split(/\s+/).length > 3) return false; return /^(ya|yes|oke|ok|baik|boleh|buka|ayo|yuk|siap|gas|mari|saya mau|mau|tentu|persis|benar|betul)$/.test(t) || /\b(buka|ya|oke|boleh|ayo|yuk|masuk)\b/.test(t); }
  function isNumberSelection(query) { var t = normalize(query); var m = t.match(/^(yang\s+)?(nomor\s+)?(\d+|pertama|kedua|ketiga|keempat|kelima)$/); if (!m) return null; var w = { pertama: 1, kedua: 2, ketiga: 3, keempat: 4, kelima: 5 }; if (m[3] && w[m[3]]) return w[m[3]] - 1; if (m[3]) { var n = parseInt(m[3], 10); return (n >= 1 && n <= 10) ? n - 1 : null; } return null; }
  function isBackToLawangSewu(query) { var t = normalize(query), tn = normalize(tourName); return (t.indexOf(tn) !== -1 || /\b(kembali|balik|tempat ini|area ini|cerita.*tempat ini|balik cerita)\b/.test(t)) && !/\b(masjid|museum|gereja|universitas|rumah sakit|restoran|mall|hotel)\b/.test(t); }
  function validateTourUrl(url) { if (!url || typeof url !== 'string') return false; if (/^(javascript:|data:|file:)/.test(url)) return false; if (!/^https?:\/\//.test(url)) return false; return true; }

  function resolveDestinationDiscovery(query) {
    if (!_catalog) return null;
    var text = normalize(query);
    var numSel = isNumberSelection(query);
    if (numSel !== null && _lastDestinationResults.length > 0 && numSel < _lastDestinationResults.length) {
      var sel = _lastDestinationResults[numSel];
      _pendingDestination = { destinationId: sel.id, name: sel.name, virtualTourUrl: sel.virtualTourUrl, virtualTourAvailable: sel.virtualTourAvailable };
      var ns = findNavigableScene(sel.name);
      if (ns) { return { reply: 'Saya bawa Anda ke ' + sel.name + '.', intent: 'navigation', confidence: 0.95, action: 'go_to_scene', scene: ns.id }; }
      var r = sel.name + ' adalah salah satu destinasi di katalog ' + catalogLabel + '.'; if (sel.description) r += ' ' + sel.description; if (sel.virtualTourAvailable) r += ' Tur virtualnya tersedia. Mau saya buka?';
      return { reply: r, intent: 'DESTINATION_DISCOVERY', confidence: 0.92, action: 'none', destination: sel };
    }
    if (isConfirmationResponse(query) && _pendingDestination && _pendingDestination.virtualTourAvailable) { var dest = _pendingDestination; _pendingDestination = null; if (validateTourUrl(dest.virtualTourUrl)) return { reply: 'Baik, saya buka tur virtual ' + dest.name + '.', intent: 'DESTINATION_DISCOVERY', confidence: 0.95, action: 'open_virtual_tour', destinationUrl: dest.virtualTourUrl }; }
    if (isVirtualTourOpenQuery(query) && _pendingDestination && _pendingDestination.virtualTourAvailable) { var dest2 = _pendingDestination; _pendingDestination = null; if (validateTourUrl(dest2.virtualTourUrl)) return { reply: 'Baik, saya buka tur virtual ' + dest2.name + '.', intent: 'DESTINATION_DISCOVERY', confidence: 0.95, action: 'open_virtual_tour', destinationUrl: dest2.virtualTourUrl }; }
    if (isDestinationNameQuery(query)) { var matches = searchDestinations(query, null); if (matches.length > 0) { var best = matches[0].destination; var ns2 = findNavigableScene(best.name); if (ns2) { return { reply: 'Saya bawa Anda ke ' + best.name + '.', intent: 'navigation', confidence: 0.95, action: 'go_to_scene', scene: ns2.id }; } _pendingDestination = { destinationId: best.id, name: best.name, virtualTourUrl: best.virtualTourUrl, virtualTourAvailable: best.virtualTourAvailable }; _lastDestinationResults = matches.slice(0, 4).map(function (m) { return m.destination; }); var r2 = best.name + ' merupakan salah satu destinasi dalam katalog ' + catalogLabel + '.'; if (best.description) r2 += ' ' + best.description; if (best.virtualTourAvailable) r2 += ' Tur virtualnya juga tersedia. Mau saya buka sekarang?'; return { reply: r2, intent: 'DESTINATION_DISCOVERY', confidence: 0.93, action: 'none', destination: best }; } }
    if (isDestinationDiscoveryQuery(query)) { var catId = detectDiscoveryCategory(query); var results = searchDestinations(query, catId); if (results.length > 0) { _lastDestinationResults = results.slice(0, 4).map(function (m) { return m.destination; }); var catName = ''; if (catId) { for (var ci = 0; ci < _catalog.categories.length; ci++) { if (_catalog.categories[ci].id === catId) { catName = _catalog.categories[ci].name; break; } } } var r3 = catName ? ('Untuk destinasi bertema ' + catName.toLowerCase() + ' di ' + catalogLabel + ':\n') : 'Beberapa destinasi di ' + catalogLabel + ':\n'; var vtHint = false; _lastDestinationResults.forEach(function (d, idx) { r3 += (idx + 1) + '. ' + d.name; if (d.virtualTourAvailable) vtHint = true; if (idx < _lastDestinationResults.length - 1) r3 += '\n'; }); if (vtHint) r3 += '\n\nBeberapa memiliki tur virtual. Mau saya buka salah satu?'; return { reply: r3, intent: 'DESTINATION_DISCOVERY', confidence: 0.90, action: 'none' }; } }
    return null;
  }

  function loadSceneIdentity() { if (_sceneIdentityData) return Promise.resolve(_sceneIdentityData); if (_sceneIdentityLoading) return _sceneIdentityLoading; _sceneIdentityLoading = fetch(basePath + 'data/scene-identity.json').then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (!d || !d.scenes) return null; _sceneIdentityData = d; return d; }).catch(function () { return null; }); return _sceneIdentityLoading; }
  function getSceneIdentityEntry(sceneId) { if (!_sceneIdentityData || !_sceneIdentityData.scenes) return null; return _sceneIdentityData.scenes[sceneId] || null; }
  function loadKnowledge() { if (_knowledgeData) return Promise.resolve(_knowledgeData); if (_knowledgeLoading) return _knowledgeLoading; _knowledgeLoading = fetch(basePath + 'data/knowledge.json').then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (!d || !d.entries) return null; _knowledgeData = d; return d; }).catch(function () { return null; }); return _knowledgeLoading; }
  var _exploreMenuData = null, _exploreMenuLoading = null;
  function loadExploreMenu() { if (_exploreMenuData) return Promise.resolve(_exploreMenuData); if (_exploreMenuLoading) return _exploreMenuLoading; _exploreMenuLoading = fetch(basePath + 'data/explore-menu-knowledge.json').then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (!d || !d.entries) return null; _exploreMenuData = d; return d; }).catch(function () { return null; }); return _exploreMenuLoading; }
  var _recQuestions = null, _recLoading = null;
  function loadRecommended() { if (_recQuestions) return Promise.resolve(_recQuestions); if (_recLoading) return _recLoading; _recLoading = fetch(basePath + 'data/recommended-questions.json').then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (!d || !d.state1) return null; _recQuestions = d; return d; }).catch(function () { return null; }); return _recLoading; }
  var _flowData = null, _flowLoading = null;
  function loadFlow() { if (_flowData) return Promise.resolve(_flowData); if (_flowLoading) return _flowLoading; _flowLoading = fetch(basePath + 'data/flow.json').then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (!d || !d.order || !d.order.length) return null; _flowData = d; return d; }).catch(function () { return null; }); return _flowLoading; }
  function flowNextSceneId(currentId) { if (!_flowData || !_flowData.order || !_flowData.order.length) return null; var order = _flowData.order, nodes = _flowData.nodes || {}, visited = conversationState.visitedSceneIds || []; var pos = order.indexOf(currentId); if (pos === -1) pos = -1; var n = order.length; for (var k = 1; k <= n; k++) { var idx = (pos + k) % n; var sid = order[idx]; if (sid === currentId) continue; if (visited.indexOf(sid) !== -1) continue; var s = sceneById(sid); return { sceneId: sid, label: (nodes[sid] && nodes[sid].displayName) || (s ? sceneDisplayName(s) : sid) }; } return null; }
  function resolveExploreEntity(query) {
    if (!_exploreMenuData || !_exploreMenuData.entries) return null;
    var text = normalize(query); if (!text) return null;
    var entries = _exploreMenuData.entries;
    for (var i = 0; i < entries.length; i++) { var e = entries[i]; var title = normalize(e.title); var all = [title].concat((e.aliases || []).map(normalize)); for (var j = 0; j < all.length; j++) { if (all[j] && all[j].length >= 3 && text.indexOf(all[j]) !== -1) return e; } }
    var tourTokens = normalize(tourName).split(/\s+/);
    var qTokens = text.split(/\s+/).filter(function (w) { return w.length > 2 && tourTokens.indexOf(w) === -1; }); if (!qTokens.length) return null;
    var best = null, bestScore = 0;
    for (var k = 0; k < entries.length; k++) { var e2 = entries[k]; var needle = normalize(e2.title + ' ' + (e2.aliases || []).join(' ')); var hits = 0; for (var t = 0; t < qTokens.length; t++) { if (needle.indexOf(qTokens[t]) !== -1) hits++; } var score = hits / qTokens.length; if (score > bestScore) { bestScore = score; best = e2; } }
    return bestScore >= 0.6 ? best : null;
  }
  function loadProviderHealth() { if (_providerHealth) return Promise.resolve(_providerHealth); if (_providerHealthLoading) return _providerHealthLoading; _providerHealthLoading = fetch(basePath + 'api/ai-status.php').then(function (r) { return r.ok ? r.json() : null; }).then(function (d) { if (!d) return null; _providerHealth = d; return d; }).catch(function () { return null; }); return _providerHealthLoading; }
  function backendAvailable() { if (_backendAvailable !== null) return Promise.resolve(_backendAvailable); return loadProviderHealth().then(function (h) { _backendAvailable = !!h; return _backendAvailable; }); }
  function isAnyProviderAvailable() { if (window.aiClientRequest) return true; if (!_providerHealth || !_providerHealth.providers) return false; for (var k in _providerHealth.providers) { var p = _providerHealth.providers[k]; if (p && p.configured && !p.cooldown) return true; } return false; }

  /* ANSWERABILITY */
  var EVIDENCE_TYPES = { DIRECT_KNOWLEDGE: 'DIRECT_KNOWLEDGE', PLACE_KNOWLEDGE: 'PLACE_KNOWLEDGE', RELATED_KNOWLEDGE: 'RELATED_KNOWLEDGE', TOUR_KNOWLEDGE: 'TOUR_KNOWLEDGE', GRAPH: 'GRAPH', GENERAL_KNOWLEDGE: 'GENERAL_KNOWLEDGE', CURRENT_LOCATION: 'CURRENT_LOCATION', DESTINATION_CATALOG: 'DESTINATION_CATALOG', EXPLORE_MENU: 'EXPLORE_MENU', NONE: 'NONE' };
  function evaluateSuggestionAnswerability(params) {
    var q = params.question || '', intent = params.intent || '', sceneId = params.sceneId || '', si = params.sceneIdentity || null, ge = params.graphExits || [], sCat = params.catalogLoaded || false, prov = params.providerAvailable || false;
    var r = { answerable: false, confidence: 0, evidenceType: EVIDENCE_TYPES.NONE, evidenceIds: [] };
    if (!si) return r;
    var cg = si.contentGrounding || '', rki = si.relatedKnowledgeIds || [], hasD = !!(si.description && si.description.trim()), hasH = !!(si.highlights && si.highlights.length > 0);
    // Admin-curated suggested questions (scene editor hybrid: mode=ai|fix) always answerable — regardless of contentGrounding.
    var cur = si.suggestedQuestions || [];
    for (var cix = 0; cix < cur.length; cix++) { if (cur[cix] && normalize(sqQ(cur[cix])) && normalize(sqQ(cur[cix])) === normalize(q)) { if (sqMode(cur[cix]) === 'fix' && sqAnswer(cur[cix])) { r.answerable = true; r.confidence = 99; r.evidenceType = EVIDENCE_TYPES.DIRECT_KNOWLEDGE; r.evidenceIds = [sceneId]; return r; } r.answerable = true; r.confidence = 85; r.evidenceType = EVIDENCE_TYPES.DIRECT_KNOWLEDGE; r.evidenceIds = [sceneId]; return r; } }
    if ((intent === 'CURRENT_LOCATION' || /di mana saya/i.test(q)) && si.placeName) { r.answerable = true; r.confidence = 95; r.evidenceType = EVIDENCE_TYPES.CURRENT_LOCATION; r.evidenceIds = [sceneId]; return r; }
    if ((intent === 'NAVIGATION' || /ke mana|rekomendasi berikutnya|lanjut ke/i.test(q)) && ge.length > 0) { r.answerable = true; r.confidence = 90; r.evidenceType = EVIDENCE_TYPES.GRAPH; r.evidenceIds = ge.map(function (e) { return e.id; }); return r; }
    if ((intent === 'DESTINATION_DISCOVERY' || /destinasi|wisata/i.test(q)) && sCat) { r.answerable = true; r.confidence = 85; r.evidenceType = EVIDENCE_TYPES.DESTINATION_CATALOG; return r; }
    if (intent === 'ENTITY_DESCRIPTION' || /apa itu|ceritakan|fungsi|menarik/i.test(q)) { var em = resolveExploreEntity(q); if (em && em.description) { r.answerable = true; r.confidence = 92; r.evidenceType = EVIDENCE_TYPES.EXPLORE_MENU; r.evidenceIds = [em.id]; return r; } }
    if (intent === 'TOUR_DESCRIPTION' || /lawang sewu/i.test(q)) { if (cg === 'shared-tour' || cg === 'direct') { r.answerable = true; r.confidence = 80; r.evidenceType = EVIDENCE_TYPES.TOUR_KNOWLEDGE; r.evidenceIds = rki; return r; } if (rki.length > 0 || hasD) { r.answerable = true; r.confidence = 70; r.evidenceType = EVIDENCE_TYPES.TOUR_KNOWLEDGE; r.evidenceIds = rki; return r; } }
    if ((intent === 'GENERAL_KNOWLEDGE' || /arsitektur kolonial|kaca patri|NIS/i.test(q)) && prov) { r.answerable = true; r.confidence = 65; r.evidenceType = EVIDENCE_TYPES.GENERAL_KNOWLEDGE; return r; }
    if ((cg === 'direct' || cg === 'direct-likely') && (hasD || rki.length > 0)) { r.answerable = true; r.confidence = 85; r.evidenceType = EVIDENCE_TYPES.DIRECT_KNOWLEDGE; r.evidenceIds = rki; return r; }
    if (cg === 'shared-tour' && hasD && (hasH || rki.length > 0)) { r.answerable = true; r.confidence = 75; r.evidenceType = EVIDENCE_TYPES.PLACE_KNOWLEDGE; r.evidenceIds = rki; return r; }
    if (cg === 'related-tour-knowledge' && rki.length > 0) { r.answerable = true; r.confidence = 70; r.evidenceType = EVIDENCE_TYPES.RELATED_KNOWLEDGE; r.evidenceIds = rki; return r; }
    if (cg === 'identity-only') { r.answerable = false; return r; }
    return r;
  }
  function buildContextualSuggestions(params) {
    var sceneId = params.sceneId || currentSceneId(), si = getSceneIdentityEntry(sceneId);
    var suggestions = [], used = {};
    // Admin-curated suggested questions (hybrid ai|fix) — SEMUA ditampilkan per panorama.
    if (si && si.suggestedQuestions && si.suggestedQuestions.length) {
      var cq = si.suggestedQuestions;
      for (var ci = 0; ci < cq.length; ci++) {
        var cqq = sqQ(cq[ci]); if (!cqq) continue;
        var k = normalize(cqq); if (used[k]) continue; used[k] = true;
        suggestions.push({ id: sceneId + '-cur-' + ci, label: cqq, prompt: cqq, intent: 'SCENE_DESCRIPTION', evidenceType: 'CURATED', evidenceIds: [], confidence: 100 });
      }
    }
    // "Kemana Berikutnya?" wajib di paling bawah.
    suggestions.push({ id: sceneId + '-NAVIGATION-flow', label: 'Kemana Berikutnya?', prompt: 'Kemana berikutnya?', intent: 'NAVIGATION', evidenceType: 'TRAVERSAL', evidenceIds: [], confidence: 99 });
    return suggestions;
  }
  /* Yield AI overlay while a Pano2VR skin popup (modal fullscreen) is open.
     Hides #tour-chat until the popup closes (polled), so skin modals never
     render underneath the AI panels. Tour-agnostic and robust:
     - tracks the explicitly opened element + any newly shown popup-like node
     - uses COMPUTED visibility (catches hide-via-parent, not just inline)
     - ignores chrome that was already visible before the trigger
     - restores pointer-events so the skin close button stays clickable */
  var _skinYieldTimer = null;
  var _skinYieldUntil = 0;
  function skinInlineVisible(e) {
    try {
      if (!e || e.nodeType !== 1 || !e.style) return false;
      if (e.style.display === 'none') return false;
      var v = e.style.visibility || '';
      if (v === 'hidden' || v === 'collapse') return false;
      var o = parseFloat(e.style.opacity);
      if (!isNaN(o) && o <= 0.02) return false;
      if (v === 'visible' || v === 'inherit') return true;
      if (!isNaN(o) && o > 0.02 && v !== 'hidden') return true;
      return false;
    } catch (ex) { return false; }
  }
  function skinComputedVisible(e) {
    try {
      if (!e || e.nodeType !== 1 || !e.parentNode) return false;
      var cs = null;
      try { cs = window.getComputedStyle(e); } catch (ex) { return skinInlineVisible(e); }
      if (!cs) return skinInlineVisible(e);
      if (cs.display === 'none') return false;
      if (cs.visibility === 'hidden' || cs.visibility === 'collapse') return false;
      var o = parseFloat(cs.opacity);
      if (!isNaN(o) && o <= 0.02) return false;
      var r = null;
      try { r = e.getBoundingClientRect(); } catch (ex2) {}
      if (r && (r.width <= 1 || r.height <= 1)) return false;
      return true;
    } catch (ex3) { return false; }
  }
  function skinLooksLikePopup(key, el) {
    try {
      if (/popup|overlay|modal|dialog/i.test(key || '')) return true;
      if (el && typeof el.querySelector === 'function' && el.querySelector('iframe')) return true;
    } catch (ex) {}
    return false;
  }
  function snapshotSkinPopupKeys() {
    var m = {};
    try {
      var so = window.skin || (window.pano && window.pano.skinObj) || null;
      if (!so) return m;
      for (var k in so) {
        try { var e = so[k]; if (e && e.nodeType === 1 && skinInlineVisible(e)) m[k] = true; } catch (ex) {}
      }
    } catch (ex2) {}
    return m;
  }
  function aiYieldToSkinPopup(explicitEl, before) {
    try {
      if (_skinYieldTimer) { clearInterval(_skinYieldTimer); _skinYieldTimer = null; }
      if (!before) before = {};
      if (explicitEl) { try { setSkinPointerEvents(explicitEl, true); } catch (e0b) {} }
      setTimeout(function () {
        try {
          var so = null;
          try { so = window.skin || (window.pano && window.pano.skinObj) || null; } catch (e3) {}
          if (!so) return;
          var tracked = [];
          var seenEls = [];
          function track(e) { try { if (e && e.nodeType === 1 && seenEls.indexOf(e) === -1) { seenEls.push(e); tracked.push(e); } } catch (ex) {} }
          if (explicitEl) track(explicitEl);
          for (var k in so) {
            if (before[k]) continue;
            var e = null;
            try { e = so[k]; } catch (e4) { continue; }
            if (!e || e.nodeType !== 1) continue;
            if (!skinInlineVisible(e)) continue;
            if (skinLooksLikePopup(k, e)) track(e);
          }
          for (var r = 0; r < tracked.length; r++) { try { setSkinPointerEvents(tracked[r], true); } catch (e5) {} }
          var confirmed = false;
          for (var c = 0; c < tracked.length; c++) { try { if (skinComputedVisible(tracked[c])) { confirmed = true; break; } } catch (e6) {} }
          if (!confirmed) return;
          var d = _dom;
          if (d && d.root) d.root.classList.add('tour-chat--yield');
          _skinYieldUntil = Date.now() + 600000;
          _skinYieldTimer = setInterval(function () {
            try {
              var so2 = null;
              try { so2 = window.skin || (window.pano && window.pano.skinObj) || null; } catch (e7) {}
              var anyVisible = false;
              if (so2) for (var k2 in so2) {
                if (before[k2]) continue;
                var e2 = null;
                try { e2 = so2[k2]; } catch (e8) { continue; }
                if (!e2 || e2.nodeType !== 1) continue;
                if (!skinInlineVisible(e2)) continue;
                if (!skinLooksLikePopup(k2, e2)) continue;
                try { if (skinComputedVisible(e2)) { anyVisible = true; break; } } catch (e9) {}
              }
              if (!anyVisible || Date.now() > _skinYieldUntil) {
                clearInterval(_skinYieldTimer); _skinYieldTimer = null;
                var dd = _dom;
                if (dd && dd.root) dd.root.classList.remove('tour-chat--yield');
              }
            } catch (ex) {
              try { clearInterval(_skinYieldTimer); } catch (ex2) {}
              _skinYieldTimer = null;
              try { if (_dom && _dom.root) _dom.root.classList.remove('tour-chat--yield'); } catch (ex3) {}
            }
          }, 500);
        } catch (ex4) {}
      }, 600);
    } catch (ex5) {}
  }

  /* PANO2VR BRIDGE */
  function getSkinPlayer() { if (window.pano && typeof window.pano.setLanguage === 'function') return window.pano; if (window.skin && window.skin.player && typeof window.skin.player.setLanguage === 'function') return window.skin.player; return null; }
  function runSkinAction(action) {
    var player = getSkinPlayer(); var skinObj = window.skin || (window.pano && window.pano.skinObj) || null;
    var _yieldBefore = null; try { _yieldBefore = snapshotSkinPopupKeys(); } catch (eYB) {}
    function openPopup(bk, pk) { var b = skinObj && skinObj[bk]; var p = skinObj && skinObj[pk]; if (b && typeof b.onclick === 'function') b.onclick(); if (!p) return false; p.ggVisible = true; p.style.opacity = '1'; p.style.visibility = 'inherit'; p.style.pointerEvents = 'auto'; try { aiYieldToSkinPopup(p, _yieldBefore); } catch (e) {} return true; }
    switch (action) {
      case 'set_language_en': if (player) player.setLanguage('en'); return true;
      case 'set_language_id': if (player) player.setLanguage('id'); return true;
      case 'set_language_nl': if (player) player.setLanguage('nl'); return true;
      case 'set_language_ar': if (player) player.setLanguage('ar'); return true;
      case 'set_language_zh': if (player) player.setLanguage('zh-CN'); return true;
      case 'open_explore': return openPopup('_jelajah', '_url_popupdiscover');
      case 'open_menu': return openPopup('_icon_menu', '_url_popupmenu') || openPopup('_menu', '_url_popupmenu');
      case 'open_information': return openPopup('_icon_info', '_url_popupinformasi');
      case 'open_public_service': if (skinObj && skinObj._icon_public && typeof skinObj._icon_public.onclick === 'function') { skinObj._icon_public.onclick(); try { aiYieldToSkinPopup(null, _yieldBefore); } catch (e) {} } return true;
      case 'play_360': if (skinObj && skinObj._btn360 && typeof skinObj._btn360.onclick === 'function') skinObj._btn360.onclick(); return true;
      case 'open_category': if (skinObj && skinObj._button_kategori && typeof skinObj._button_kategori.onclick === 'function') { skinObj._button_kategori.onclick(); try { aiYieldToSkinPopup(null, _yieldBefore); } catch (e) {} } return true;
      default: return false;
    }
  }
  function skinActionFromQuery(query) {
    var t = normalize(query);
    if (/(ganti|ubah|pakai|switch|change)\s+(bahasa\s+)?(inggris|english)|^english$/.test(t)) return { action: 'set_language_en', reply: 'Saya ubah bahasa ke English.' };
    if (/(ganti|ubah|pakai|switch|change)\s+(bahasa\s+)?indonesia|^bahasa indonesia$/.test(t)) return { action: 'set_language_id', reply: 'Saya ubah bahasa ke Indonesia.' };
    if (/(buka|open|tampilkan|show).*(kategori|category)|\bkategori\b/.test(t)) return { action: 'open_category', reply: 'Saya buka menu kategori.' };
    if (/(buka|open|tampilkan|show).*(jelajah|explore)|^(jelajah|explore|discover)$/.test(t)) return { action: 'open_explore', reply: 'Saya buka Jelajah.' };
    if (/(buka|open|tampilkan|show).*(layanan publik)|^(layanan publik)$/.test(t)) return { action: 'open_public_service', reply: 'Saya buka Layanan Publik.' };
    if (/(buka|open|tampilkan|show).*(informasi|info)|^(informasi|info)$/.test(t)) return { action: 'open_information', reply: 'Saya buka panel Informasi.' };
    if (/(buka|open|tampilkan|show).*(menu utama)|^(menu|main menu)$/.test(t)) return { action: 'open_menu', reply: 'Saya buka Menu utama.' };
    if (/(putar|jalankan|play|mulai|start).*(360|video)|^(360|video 360)$/.test(t)) return { action: 'play_360', reply: 'Saya jalankan tampilan 360\u00b0.' };
    return null;
  }
  function currentSceneId() { if (typeof window.pano.getCurrentNode === 'function') return window.pano.getCurrentNode(); var h = location.hash.split(',')[0].replace('#', ''); return h || (data.scenes.length ? data.scenes[0].id : ''); }
  function findSceneMatches(query) { var t = normalize(query); return data.scenes.map(function (s) { var score = [s.name].concat(s.aliases || []).reduce(function (b, term) { var n = normalize(term); return t.indexOf(n) !== -1 ? Math.max(b, n.length) : b; }, 0); return { scene: s, score: score }; }).filter(function (m) { return m.score > 0; }).sort(function (a, b) { return b.score - a.score; }); }
  function findHotspot(q, sid) { var t = normalize(q); return data.hotspots.filter(function (h) { return h.scene === sid; }).map(function (h) { var term = normalize(h.title); return { hotspot: h, score: t.indexOf(term) !== -1 ? term.length : 0 }; }).sort(function (a, b) { return b.score - a.score; })[0]; }
  function findRouteMatches(query) { var t = normalize(query); return (data.routes || []).map(function (r) { var score = r.aliases.reduce(function (b, a) { var term = normalize(a); return t.indexOf(term) !== -1 ? Math.max(b, term.length) : b; }, 0); return { route: r, score: score }; }).filter(function (m) { return m.score > 0; }).sort(function (a, b) { return b.score - a.score; }); }
  function buildSceneGraph() { var g = {}; data.scenes.forEach(function (s) { g[s.id] = []; }); data.hotspots.forEach(function (h) { if (g[h.scene] && h.target !== h.scene && g[h.scene].indexOf(h.target) === -1) g[h.scene].push(h.target); }); return g; }
  function findScenePath(from, to) { var g = buildSceneGraph(); if (!g[from] || !g[to]) return []; var q = [[from]], v = {}; v[from] = true; while (q.length) { var p = q.shift(); var n = p[p.length - 1]; if (n === to) return p; g[n].forEach(function (next) { if (!v[next]) { v[next] = true; q.push(p.concat(next)); } }); } return []; }
  function routeBetweenScenes(fq, tq) { var fm = findSceneMatches(fq)[0], tm = findSceneMatches(tq)[0]; if (!fm || !tm) return null; var p = findScenePath(fm.scene.id, tm.scene.id); return p.length ? { fromScene: fm.scene, toScene: tm.scene, path: p } : null; }
  function remember(role, text) { history.push({ role: role, text: text, scene: currentSceneId() }); history = history.slice(-8); }
  function updateConversationState(result, query) {
    var rs = result && result.conversation_update ? result.conversation_update.referenced_scene_id : null;
    var re = result && result.conversation_update ? result.conversation_update.referenced_entity_id : null;
    var rr = result && result.conversation_update ? result.conversation_update.recommended_route_id : null;
    if (query) conversationState.lastEntity = result && result.entities && result.entities[0] ? result.entities[0] : query;
    if (re) conversationState.lastReferencedEntity = re;
    if (rs) { conversationState.lastReferencedDestination = rs; conversationState.lastNavigableSceneId = rs; conversationState.lastRecommendedSceneId = rs; }
    if (rr) conversationState.recommendedRouteId = rr;
    if (result && result.recommendation_target) conversationState.lastRecommendedDestination = result.recommendation_target;
    conversationState.lastReply = result && result.reply ? result.reply : conversationState.lastReply;
    conversationState.lastIntent = result && result.intent ? result.intent : conversationState.lastIntent;
    if (result && result.route) conversationState.lastRoute = result.route;
    if (result && result.active_route_id) conversationState.activeRouteId = result.active_route_id;
    if (result && result.active_route_scene_ids) conversationState.activeRouteSceneIds = result.active_route_scene_ids;
    if (result && result.active_route_step !== undefined && result.active_route_step !== null) conversationState.activeRouteStep = result.active_route_step;
    if (result && result.profile_updates) { var u = result.profile_updates; if (u.transport) conversationState.visitorProfile.transport = u.transport; if (typeof u.traveling_with_children === 'boolean') conversationState.visitorProfile.travelingWithChildren = u.traveling_with_children; if (u.service_preference) conversationState.visitorProfile.servicePreference = u.service_preference; if (u.destination_preference) conversationState.visitorProfile.destinationPreference = u.destination_preference; if (u.accessibility_needs) conversationState.visitorProfile.accessibilityNeeds = u.accessibility_needs.slice(0, 10); if (u.interests) conversationState.visitorProfile.interests = u.interests.slice(0, 10); }
    if (result && result.scene) conversationState.lastSceneId = result.scene; else conversationState.lastSceneId = currentSceneId();
    if (result && result.mentioned_scene_ids && result.mentioned_scene_ids.length) conversationState.lastMentionedSceneIds = result.mentioned_scene_ids.slice(0, 10);
    if (conversationState.visitedSceneIds.indexOf(conversationState.lastSceneId) === -1) conversationState.visitedSceneIds.push(conversationState.lastSceneId);
    if (result && result.entities && result.entities.length) { var te = result.entities[0]; conversationState.lastDiscussedEntity = te.name || ''; conversationState.lastDiscussedEntityType = te.type || 'entity'; if (result.action === 'go_to_scene' || te.type === 'destination' || te.type === 'explore-place') conversationState.lastNavigableEntity = te.name || ''; }
    if (result && result.intent === 'PUBLIC_SERVICE' && result.entities && result.entities.length) conversationState.lastPublicServiceEntity = result.entities[0];
    if (result && result.session_update && result.session_update.lastPublicServiceResults) _lastPublicServiceResults = result.session_update.lastPublicServiceResults;
  }
  function contextualSceneMatches(query) {
    var m = findSceneMatches(query); if (m.length) return m;
    var t = normalize(query); if (!t) return [];
    return data.scenes.map(function (s) { var terms = [normalize(s.name)].concat((s.aliases || []).map(normalize)); var best = 0, exact = false; for (var i = 0; i < terms.length; i++) { var term = terms[i]; if (!term || term.length < 3) continue; if (i === 0 && term === t) { best = 200; exact = true; break; } if (term.indexOf(t) !== -1 || t.indexOf(term) !== -1) { var sc = Math.min(term.length, t.length) || 1; if (sc > best) best = sc; } var parts = term.split(' '); for (var j = 0; j < parts.length; j++) { if (parts[j] && parts[j].length >= 3 && (parts[j].indexOf(t) !== -1 || t.indexOf(parts[j]) !== -1)) { if (parts[j].length > best) best = parts[j].length; } } } return { scene: s, score: best, isExactName: exact }; }).filter(function (m) { return m.score > 0; }).sort(function (a, b) { if (a.isExactName && !b.isExactName) return -1; if (!a.isExactName && b.isExactName) return 1; return b.score - a.score; });
  }

  var _pendingDroneChoice = null, _lastPublicServiceResults = [], _pendingPublicService = null;

  function resolveContextualIntent(query, active) {
    var t = normalize(query);
    var pronounFollowUp = /\b(di sana|kesana|ke sana|yang tadi|tempat tadi|area itu|scene itu)\b/.test(t) && !/\b(apa|bagaimana|kenapa|mengapa|siapa|kapan|berapa|dimana|di mana|jelaskan|ceritakan|deskripsikan)\b/.test(t);
    var continueRoute = /\b(lanjut|berikutnya|next|selanjutnya)\b/.test(t);
    var wantsRoute = /\b(rute|jalur|ikut|ikuti|bawa|antar|arahkan)\b/.test(t);
    var wantsFacility = /\b(nunggu|menunggu|tempat nunggu|nunggunya|waiting|rest|family|anak|keluarga)\b/.test(t);

    if (/\bdrone\b/i.test(t)) {
      if (_pendingDroneChoice) { var c = _pendingDroneChoice; _pendingDroneChoice = null; if (/\b(utama|area utama|main|depan|halaman)\b/.test(t)) { var ms = sceneById(c.mainId); if (ms) { return { reply: 'Saya arahkan ke view drone Area Utama.', intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: ms.id }; } } var bs = sceneById(c.balkonId); if (bs) { return { reply: 'Saya arahkan ke view drone Balkon.', intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: bs.id }; } }
      var md = sceneById('node51'), mn = sceneById('node50'), bd = sceneById('node53'), bn = sceneById('node52');
      var ms2 = active && active.variant === 'night' ? mn : md, bs2 = active && active.variant === 'night' ? bn : bd;
      if (!ms2) ms2 = md || mn; if (!bs2) bs2 = bd || bn;
      if (ms2 && bs2) { _pendingDroneChoice = { mainId: ms2.id, balkonId: bs2.id }; pendingClarification = { type: 'drone', options: ['Drone Area Utama', 'Drone Balkon'] }; return { reply: 'Ada 2 view drone:\n1. Drone Area Utama\n2. Drone Balkon\n\nMau yang mana?', intent: 'clarify', confidence: 0.90, needs_clarification: true, clarification_options: ['Drone Area Utama', 'Drone Balkon'], action: 'none' }; }
    }
    if (continueRoute) { if (conversationState.activeRouteSceneIds && conversationState.activeRouteSceneIds.length) { var ci = conversationState.activeRouteSceneIds.indexOf(currentSceneId()); var nsid = ci >= 0 ? conversationState.activeRouteSceneIds[ci + 1] : conversationState.activeRouteSceneIds[0]; var ns = nsid ? sceneById(nsid) : null; if (ns) return { reply: 'Saya lanjutkan ke ' + sceneDisplayName(ns) + '.', intent: 'navigate', confidence: 0.92, action: 'go_to_scene', scene: ns.id }; } var fn = flowNextSceneId(currentSceneId()); if (fn) { var fns = sceneById(fn.sceneId); if (fns) return { reply: 'Saya lanjutkan ke ' + fn.label + '.', intent: 'navigate', confidence: 0.92, action: 'go_to_scene', scene: fns.id }; } }
    if (pronounFollowUp) { var tid = (conversationState.lastRecommendedDestination && conversationState.lastRecommendedDestination.sceneId) || conversationState.lastReferencedDestination || conversationState.lastRecommendedSceneId || conversationState.lastNavigableSceneId || null; if (!tid && conversationState.activeRouteSceneIds && conversationState.activeRouteSceneIds.length) { var ai = conversationState.activeRouteSceneIds.indexOf(currentSceneId()); tid = ai >= 0 ? conversationState.activeRouteSceneIds[ai + 1] : conversationState.activeRouteSceneIds[0]; } if (tid) { var ts = sceneById(tid); if (ts) return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(ts) + '. ' + sceneSummary(ts), intent: 'navigate', confidence: 0.88, action: 'go_to_scene', scene: ts.id }; } return { reply: 'Maksud Anda tujuan yang mana?', intent: 'clarify', confidence: 0.4, needs_clarification: true, clarification_options: [], action: 'none' }; }
    if (wantsFacility) { var fm = contextualSceneMatches(query).filter(function (m) { return /anak|keluarga|tunggu|waiting|service|lobi/.test(normalize(m.scene.name + ' ' + (m.scene.aliases || []).join(' '))); }); if (fm.length > 1 && fm[0].score === fm[1].score) return clarify(fm, 'scene'); if (fm[0]) { var fs = fm[0].scene; conversationState.lastRecommendedSceneId = fs.id; conversationState.lastNavigableSceneId = fs.id; return { reply: 'Untuk menunggu, saya rekomendasikan ' + sceneDisplayName(fs) + '. ' + sceneSummary(fs), intent: 'facility_recommendation', confidence: 0.93, action: 'show_facility', scene: fs.id }; } }
    if (wantsRoute || /\b(mobil|kendaraan|anak|keluarga|eksekutif|vip|lansia|disabilitas)\b/.test(t)) { var rm = findRouteMatches(query); if (rm.length > 1 && rm[0].score === rm[1].score) return clarify(rm, 'route'); if (rm[0]) { var route = rm[0].route; var first = route.scenes[0]; conversationState.recommendedRouteId = route.id; conversationState.lastRecommendedSceneId = first; conversationState.lastNavigableSceneId = first; var fo = sceneById(first); if (fo) { return { reply: 'Saya arahkan ke ' + sceneDisplayName(fo) + '. Urutan: ' + route.scenes.map(function (sid) { var s = sceneById(sid); return s ? sceneDisplayName(s) : sid; }).join(' \u2192 ') + '.', intent: 'navigate', confidence: 0.94, action: 'go_to_scene', scene: first }; } } }
    var isInfoNav = /\b(pukul|jam|berapa|buka|tutup|harga|tiket|biaya|sejarah|kenapa|mengapa|apa itu|apa yang|ceritakan|jelaskan|deskripsikan|arti|makna|fungsi|menarik|unik|bagaimana|dimana|di mana|kapan|siapa|hubungan|kaitan|hubungannya|berkaitan)\b/.test(t);
    if (!isInfoNav && /(?:^|\b)(?:ke|menuju|pergi ke|bawa(?:\s+saya)?\s+ke|antar(?:\s+saya)?\s+ke|arahkan(?:\s+saya)?\s+ke|(?:saya\s+)?mau\s+(?:lihat|liat|ke|kelihat)|(?:saya\s+)?ingin\s+(?:lihat|liat|ke|melihat)|(?:saya\s+)?pengen\s+(?:lihat|liat|ke)|go to|take me to)\b/.test(t)) { var sm = contextualSceneMatches(query); if (sm.length > 0) { conversationState.lastReferencedDestination = sm[0].scene.id; conversationState.lastNavigableSceneId = sm[0].scene.id; return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(sm[0].scene) + '. ' + sceneSummary(sm[0].scene), intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: sm[0].scene.id }; } }
    if (/\b(di sini|area ini|tempat ini)\b/.test(t) && active && !/\b(kenapa|mengapa|apa|siapa|kapan|bagaimana|berapa|dimana|di mana|ceritakan|jelaskan|deskripsikan|tentang)\b/.test(t)) return { reply: sceneSummary(active), intent: 'area_info', confidence: 0.95, scene: active.id };
    return null;
  }
  function clarify(matches, type) { var opts = matches.slice(0, 3).map(function (m) { return type === 'route' ? m.route.name : sceneDisplayName(m.scene); }); pendingClarification = { type: type, options: opts }; return { reply: 'Maksud Anda yang mana?', intent: 'clarify', confidence: 0.4, needs_clarification: true, clarification_options: opts, action: 'none' }; }
  function navigate(scene) { var v = ''; try { if (scene && scene.pan !== undefined && scene.tilt !== undefined && scene.fov !== undefined) v = scene.pan + '/' + scene.tilt + '/' + scene.fov; } catch (e) {} window.pano.openNext('{' + scene.id + '}', v); try { history.replaceState(null, '', '#' + scene.id); } catch (e) {} try { renderLeftPanel(scene.id); } catch (e) {} }
  function goToSceneId(id) { if (!id) return; var sc = sceneById(id); var v = ''; try { if (sc && sc.pan !== undefined && sc.tilt !== undefined && sc.fov !== undefined) v = sc.pan + '/' + sc.tilt + '/' + sc.fov; } catch (e) {} window.pano.openNext('{' + id + '}', v); try { history.replaceState(null, '', '#' + id); } catch (e) {} try { renderLeftPanel(id); } catch (e) {} }
  function focusHotspot(h) { if (typeof window.pano.moveTo === 'function') window.pano.moveTo(h.pan + '/' + h.tilt + '/70', 1); }
  function sceneById(sid) { return data.scenes.find(function (s) { return s.id === sid; }); }
  function findNavigableScene(name) { var t = normalize(name); if (!t) return null; var best = null, bs = 0; for (var i = 0; i < data.scenes.length; i++) { var s = data.scenes[i]; var sc = 0; var sn = normalize(sceneDisplayName(s)); if (t === sn) sc = 100; else if (sn.length >= 3 && t.indexOf(sn) !== -1) sc = sn.length * 2; else if (sn.length >= 3 && sn.indexOf(t) !== -1) sc = t.length * 2; (s.aliases || []).forEach(function (a) { var an = normalize(a); if (an.length >= 3 && t.indexOf(an) !== -1) sc = Math.max(sc, an.length + 5); if (an.length >= 3 && an.indexOf(t) !== -1) sc = Math.max(sc, t.length + 5); }); if (sc > bs) { bs = sc; best = s; } } return bs >= 5 ? best : null; }
  var _sceneRegistry = data._sceneRegistry || {};
  var _dynamicPrompts = [];
  var _lastQuestion = '';
  function sceneDisplayName(s) {
    var n = '';
    // 1) Scene Editor kurasi (scene-identity) — prioritas seperti Lawang Sewu perfect
    var si = s && s.id ? getSceneIdentityEntry(s.id) : null;
    if (si && si.placeName) n = si.placeName;
    else if (si && si.displayName) n = si.displayName;
    else if (s && s.id && _sceneRegistry[s.id] && _sceneRegistry[s.id].placeName) n = _sceneRegistry[s.id].placeName;
    else if (s && s.id && _sceneRegistry[s.id] && _sceneRegistry[s.id].displayName) n = _sceneRegistry[s.id].displayName;
    else n = (s && (s.displayName || s.rawName || s.name)) || 'area ini';
    return normalizeVisitorLabel(n);
  }
  function normalizeVisitorLabel(name) { if (!name) return name; name = name.replace(/\b(Area|Ruang|Gedung|Koridor|Lorong|Galeri|Monumen|Toko|Pintu|Balkon|Struktur)\s+\1\b/gi, '$1'); var words = name.split(/\s+/), c = []; for (var i = 0; i < words.length; i++) { if (i > 0 && normalize(words[i]) === normalize(words[i - 1])) continue; c.push(words[i]); } return c.join(' '); }
  function sceneSummary(s) { if (!s) return ''; var p = []; if (s.description) p.push(s.description); var h = (s.highlights || []).join(', '); var f = (s.facilities || []).join(', '); if (false) {} if (f) p.push('Fasilitas: ' + f + '.'); return p.join(' '); }
  function contentCapabilities(s) { if (!s) return null; return { hasDescription: !!s.description, hasHighlights: !!(s.highlights && s.highlights.length), hasNavigation: graphExitIds(s.id).length > 0 }; }
  function graphExitIds(sid) { var g = buildSceneGraph(), seen = {}; return (g[sid] || []).filter(function (id) { return id !== sid; }).map(function (id) { return sceneById(id); }).filter(Boolean).filter(function (s) { var l = sceneDisplayName(s.id); if (seen[l]) return false; seen[l] = true; return true; }); }
  function recommendDestination(s) { conversationState.lastRecommendedDestination = null; if (!s) return null; var exits = graphExitIds(s.id); if (!exits.length) return null; var t = exits[0]; conversationState.lastRecommendedDestination = { sceneId: t.id, label: sceneDisplayName(t), sourceSceneId: s.id, navigable: true }; conversationState.lastRecommendedSceneId = t.id; conversationState.lastNavigableSceneId = t.id; return t; }

  function localRespond(query) {
    var t = normalize(query), aid = currentSceneId(), active = sceneById(aid);
    var ctx = resolveContextualIntent(query, active); if (ctx) return ctx;
    var sa = skinActionFromQuery(query); if (sa) return { reply: sa.reply, intent: 'skin_action', confidence: 0.95, action: sa.action };
    if (/\b(video|hotspot|konten).{0,20}360|\b360\b.{0,20}(apa saja|apa aja|tersedia|ada|video|hotspot)/.test(t)) { var cr = (data.video360 || []).filter(function (i) { return i.scene === aid; }); var all = (data.video360 || []).slice(); var ord = cr.concat(all.filter(function (i) { return cr.indexOf(i) === -1; })); if (!ord.length) return { reply: 'Belum ada video 360 terdaftar.', intent: 'video360', confidence: 0.8 }; var fv = ord[0]; var fs = sceneById(fv.scene) || { name: fv.scene, id: fv.scene }; return { reply: 'Ada ' + ord.length + ' video 360: ' + ord.map(function (i) { var sc = sceneById(i.scene); return i.title + ' di ' + (sc ? sceneDisplayName(sc) : i.scene); }).join('; ') + '. Klik tombol di bawah untuk langsung memutarnya.', intent: 'video360', confidence: 0.97, action: 'none', video360: fv }; }
    if (/^(halo|hai|hi|hello)\b/.test(t)) return { reply: 'Halo. Saya pemandu virtual ' + data.name + '. Tanyakan lokasi atau fasilitas.' };
    if (/(di mana saya|saya di mana|posisi saya|sedang berada|where am i)/.test(t)) { if (!active) return { reply: 'Area aktif belum dikenali.', intent: 'location', confidence: 0.5 }; var lr = 'Anda sedang berada di ' + sceneDisplayName(active) + '.'; var lsi = getSceneIdentityEntry(active.id); if (lsi && (lsi.shortDescription || lsi.description)) lr += ' ' + ((lsi.shortDescription || lsi.description) + '').replace(/\.\s*$/, '') + '.'; if (false) {} return { reply: lr, intent: 'location', confidence: 0.98, scene: active.id }; }
    if (/(rekomendasi berikutnya|habis ini (ke mana|kemana)|(ke mana|kemana) (berikutnya|selanjutnya|sebaiknya( saya)? lanjut)|setelah ini( saya)? (ke mana|kemana)|selanjutnya (ke mana|kemana)|lanjut (ke mana|kemana)|\bnext\b)/.test(t)) { var rec = recommendDestination(active); return rec ? { reply: 'Saya bawa Anda ke ' + sceneDisplayName(rec) + '.', intent: 'recommend', confidence: 0.95, action: 'go_to_scene', scene: rec.id } : { reply: 'Belum ada rekomendasi.', intent: 'recommend', confidence: 0.5, scene: active ? active.id : null }; }
    if (/(arahkan|antar|bawa|guide|bring|take me|go).*(dari|from).*(ke|menuju|to)|(?:dari|from).*(?:ke|menuju|to)/.test(t)) { var fm = t.match(/(?:dari|from)\s+(.+?)\s+(?:ke|menuju|to)\s+(.+)/); if (fm) { var rel = routeBetweenScenes(fm[1], fm[2]); if (rel) return { reply: 'Rute dari ' + sceneDisplayName(rel.fromScene) + ' ke ' + sceneDisplayName(rel.toScene) + ': ' + rel.path.map(function (sid) { var s = sceneById(sid); return s ? sceneDisplayName(s) : sid; }).join(' \u2192 ') + '.', intent: 'route_relation', confidence: 0.95, action: 'start_route', route: rel.path }; } }
    if (/(apa saja|daftar|lokasi tersedia)/.test(t)) return { reply: 'Lokasi: ' + data.scenes.map(sceneDisplayName).join(', ') + '.' };
    if (pendingClarification) { var chosen = pendingClarification.options.find(function (o) { return normalize(o) === t || t.indexOf(normalize(o)) !== -1; }); var ct = pendingClarification.type; pendingClarification = null; if (chosen) return localRespond(chosen); }
    var rm = findRouteMatches(query); if (rm.length > 1 && rm[0].score === rm[1].score) return clarify(rm, 'route');
    if (/(kembali|awal|utama|mulai)/.test(t)) { var home = data.scenes[0]; return { reply: 'Kembali ke awal tur.', intent: 'navigate', confidence: 0.95, action: home ? 'go_to_scene' : 'none', scene: home ? home.id : null }; }
    var lh = findHotspot(query, aid); if (lh && lh.score > 0 && /\b(tunjukkan|sorot|buka|lihat|tampilkan|show|highlight|fokus|focus)\b/.test(t)) { focusHotspot(lh.hotspot); return { reply: 'Saya menyorot ' + lh.hotspot.title + '.' }; }
    if (/(ke sana|kesana|di sana|ke situ|di situ)/.test(t)) { var did = conversationState.lastNavigableEntity ? data.scenes.find(function (s) { return (s.name || '').toLowerCase().indexOf(conversationState.lastNavigableEntity.toLowerCase()) !== -1 || (s.displayName || '').toLowerCase().indexOf(conversationState.lastNavigableEntity.toLowerCase()) !== -1; }) : null; var did2 = did ? did.id : ((conversationState.lastRecommendedDestination && conversationState.lastRecommendedDestination.sceneId) || conversationState.lastRecommendedSceneId || conversationState.lastNavigableSceneId); var ds = did2 ? sceneById(did2) : null; if (ds && ds.id !== aid) { return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(ds) + '.', intent: 'navigate', confidence: 0.96, action: 'go_to_scene', scene: ds.id }; } if (ds && ds.id === aid) return { reply: 'Anda sudah di ' + sceneDisplayName(ds) + '.', intent: 'location', confidence: 0.96 }; }
    if (_pendingDestination && isBackToLawangSewu(query)) { _pendingDestination = null; _lastDestinationResults = []; if (active) return { reply: 'Kembali ke ' + sceneDisplayName(active) + '.', intent: 'area_info', confidence: 0.95, scene: active.id }; }
    var sf = resolvePublicServiceFollowup(query); if (sf) return sf;
    var isInfoQ = /\b(kenapa|mengapa|apa itu|apa yang|ceritakan|jelaskan|deskripsikan|tentang|sejarah|arti|makna|fungsi|menarik|unik|bagaimana|dimana|di mana|hubungan|kaitan|pukul|jam|berapa|buka|tutup|harga|tiket|siapa|kapan)\b/.test(t);
    if (!isInfoQ) { var dr = resolveDestinationDiscovery(query); if (dr) return dr; }
    var ee = resolveExploreEntity(query); if (ee && ee.description) { var nt = ee.sceneIds && ee.sceneIds.length ? ee.sceneIds[0] : null; return { reply: ee.description, intent: 'entity_description', confidence: 0.92, scene: active ? active.id : null, entity: { name: ee.title, id: ee.id, type: 'explore-place' }, navigationTarget: nt ? { sceneId: nt, label: ee.title } : null }; }
    return null;
  }

  function applyAction(result) {
    if (!result) return;
    var actions = result.actions && result.actions.length ? result.actions : [{ type: result.action, scene_id: result.scene, scene_ids: result.route, hotspot: result.hotspot, execution: 'autoExecute' }];
    var action = actions.find(function (i) { return i.execution === 'autoExecute'; });
    if (!action || !action.type) return;
    var sa = action.type === 'set_language' ? 'set_language_' + action.language : action.type;
    if (/^(set_language_|open_category|open_explore|open_menu|open_information|open_public_service|play_360)/.test(sa)) { runSkinAction(sa); return; }
    if (action.type === 'start_route') { var route = (data.routes || []).find(function (r) { return r.id === action.route_id; }); var sids = action.scene_ids || (route && route.scenes) || []; if (!sids.length) return; conversationState.activeRoute = { type: route ? 'curated' : 'graph', id: action.route_id || null, sceneIds: sids, currentStep: 0 }; var first = sceneById(sids[0]); if (first) navigate(first); return; }
    if (action.type === 'continue_route' && conversationState.activeRoute) { var next = conversationState.activeRoute.sceneIds[conversationState.activeRoute.currentStep + 1]; if (next && sceneById(next)) navigate(sceneById(next)); return; }
    if (action.type === 'go_to_scene' && sceneById(action.scene_id || action.scene)) {
      if (result._navScheduled) return; // sudah dijadwalkan pipeline bersama kartu nav
      var _plan = navPrepareNavigation({
        callId: action.call_id || result.aiRequestId || null,
        actionId: action.action_id || null,
        turnId: action.turn_id || null,
        sceneId: action.scene_id || action.scene,
        revision: action.scene_revision !== undefined ? action.scene_revision : _sceneRevision
      });
      if (_plan.ok) {
        var _sc = sceneById(action.scene_id || action.scene);
        navMarkExecuting();
        navigate(_sc);
        navArmTimeout(function () { /* arrival handled via handleSceneChanged; timeout is honest fallback */ });
      }
      return;
    }
    if (action.type === 'open_virtual_tour' && action.destinationUrl) { if (validateTourUrl(action.destinationUrl)) window.open(action.destinationUrl, '_blank', 'noopener,noreferrer'); return; }
    if (action.type === 'focus_hotspot' || action.type === 'show_hotspot_info') { var h = data.hotspots.find(function (i) { return i.scene === currentSceneId() && normalize(i.title) === normalize(action.hotspot || ''); }); if (h) focusHotspot(h); }
  }
  function resolveClarificationChoice(query) { if (!pendingClarification) return null; var t = normalize(query); var opt = pendingClarification.options.find(function (i) { return normalize(i) === t || t.indexOf(normalize(i)) !== -1; }); var ct = pendingClarification.type; pendingClarification = null; if (!opt) return null; return localRespond(opt); }
  function angularDistance(a, b) { return Math.abs(((a - b + 540) % 360) - 180); }
  function getAIViewContext() { var pan = window.pano && typeof window.pano.getPan === 'function' ? window.pano.getPan() : 0; var tilt = window.pano && typeof window.pano.getTilt === 'function' ? window.pano.getTilt() : 0; var fov = window.pano && typeof window.pano.getFov === 'function' ? window.pano.getFov() : 70; return { pan: pan, tilt: tilt, fov: fov }; }
  function buildAITourContext() { var id = currentSceneId(); return { session: conversationState, scene: sceneById(id), view: getAIViewContext(), route: conversationState.activeRoute, visitor: conversationState.visitorProfile, nearby: data.hotspots.filter(function (h) { return h.scene === id; }) }; }
  function remoteRespond(query) {
    if (activeRequest) activeRequest.abort(); activeRequest = new AbortController();
    var ctx = buildAITourContext();
    function tryPhp() { return fetch(apiUrl, { method: 'POST', signal: activeRequest.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ aiRequestId: 'web-' + Date.now().toString(36), message: query, currentScene: currentSceneId(), history: history, conversationState: conversationState, viewContext: ctx.view, tourContext: voiceTourContext() }) }).then(function (r) { if (!r.ok) throw new Error('PHP ' + r.status); return r.json(); }); }
    function clientGuidePrompt() {
      var sc = currentScene();
      var parts = ['Kamu adalah pemandu virtual resmi ' + tourName + (cfg.tourLocation ? ', ' + cfg.tourLocation : '') + '. Jawab dalam bahasa yang sama dengan pertanyaan (default Indonesia). Jawab singkat dan langsung, tanpa sapaan atau kata pengisi yang tidak perlu.'];
      parts.push('Pengunjung saat ini berada di: ' + (sc ? sceneDisplayName(sc) : tourName) + '.');
      var exits = sc ? graphExitIds(sc.id) : [];
      if (exits.length) parts.push('Tempat yang bisa dikunjungi dari sini: ' + exits.map(function (e) { return sceneDisplayName(e); }).join(', ') + '.');
      var allNames = data.scenes.map(function (s) { return sceneDisplayName(s); }).filter(function (n, i, a) { return a.indexOf(n) === i; });
      parts.push('Daftar seluruh tempat yang tersedia: ' + allNames.join(', ') + '.');
      parts.push('Keluarkan JSON: {"reply":"...","intent":"...","confidence":0.9}. Jika Anda menyarankan pengunjung melanjutkan ke tempat LAIN, sertakan key "nextScene" berisi PERSIS salah satu nama tempat dari daftar di atas (bukan id). Jika tidak menyarankan pindah tempat, jangan sertakan nextScene.');
      return parts.join('\n');
    }
    function resolveSceneFromName(dest) {
      if (!dest) return null;
      var m = findSceneMatches(String(dest));
      if (m.length) return m[0].scene;
      var sc = currentScene();
      var nd = normalize(String(dest));
      if (sc) { var exits = graphExitIds(sc.id); for (var i = 0; i < exits.length; i++) { var ex = exits[i]; var en = normalize(sceneDisplayName(ex)); if (en.indexOf(nd) !== -1 || nd.indexOf(en) !== -1) return ex; } }
      var pe = panoExits(currentSceneId());
      for (var j = 0; j < pe.length; j++) { var en2 = normalize(pe[j].title); if (nd.indexOf(en2) !== -1 || en2.indexOf(nd) !== -1) { var peScene = sceneById(pe[j].target); if (peScene) return peScene; } }
      var cm = contextualSceneMatches(String(dest));
      if (cm.length) return cm[0].scene;
      return null;
    }
    function tryGemini() { return new Promise(function (resolve) { if (!window.aiClientRequest) { resolve(null); return; } window.aiClientRequest(clientGuidePrompt(), query, function (err, text) { if (err || !text) { resolve(null); return; } var data; try { data = JSON.parse(text); } catch (e) { data = { reply: text, intent: 'GENERAL_CONVERSATION', confidence: 0.7, action: 'none' }; } // Resolve a suggested next location into a navigation action, mirroring
      // the server pipeline's ROUTE_RECOMMENDATION behaviour (so the tour
      // actually moves there instead of only describing it).
      var dest = data.nextScene || data.scene || null;
      if (dest && (!data.action || data.action === 'none')) {
        var tgt = resolveSceneFromName(dest);
        if (tgt && tgt.id !== currentSceneId()) {
          data.action = 'go_to_scene';
          data.scene = tgt.id;
        }
      }
      // Heuristic fallback: model forgot nextScene but the reply clearly suggests
      // moving to another part and names it — still navigate there.
      if ((!data.action || data.action === 'none') && !data.nextScene) {
        var cue = /\b(menuju|lanjut|berikutnya|kunjungi|silakan|meneruskan|ke\s+(ruang|lantai|bagian|area|gedung|sayap)|bisa\s+(ke|menuju|mengunjungi))\b/i;
        if (cue.test(data.reply || '')) {
          var mm = findSceneMatches(data.reply || '');
          for (var i = 0; i < mm.length; i++) {
            if (mm[i].scene.id !== currentSceneId() && mm[i].score >= 4) {
              data.action = 'go_to_scene';
              data.scene = mm[i].scene.id;
              break;
            }
          }
        }
      }
      resolve(data); }); }); }
    function _isInvalidReply(rep) {
      if (!rep) return true;
      if (rep === 'Layanan AI tidak terjangkau.' || rep === 'Respons AI tidak valid.') return true;
      if (rep.indexOf('Maaf, saya belum bisa menjawab pertanyaan tersebut') === 0) return true;
      return false;
    }
    return backendAvailable().then(function (backendUp) {
      if (backendUp) return tryPhp().then(function (r) {
        if (r && r.reply && !_isInvalidReply(r.reply)) return r;
        return tryGemini();
      }).catch(function () { return tryGemini(); });
      return tryGemini();
    }).then(function (r) { if (r) { if (!r.reply && r.respons) r.reply = r.respons; if (!r.reply && r.answer) r.reply = r.answer; if (!r.reply && r.text) r.reply = r.text; } return r; });
  }
  function resolvePublicServiceFollowup(query) { if (!_lastPublicServiceResults || !_lastPublicServiceResults.length) return null; var t = normalize(query); var numMap = { 'pertama': 0, '1': 0, 'kedua': 1, '2': 1, 'ketiga': 2, '3': 2 }; var sel = null; for (var w in numMap) { if (new RegExp('\\b' + w + '\\b').test(t)) { if (_lastPublicServiceResults[numMap[w]]) sel = _lastPublicServiceResults[numMap[w]]; break; } } if (!sel && _lastPublicServiceResults.length === 1) sel = _lastPublicServiceResults[0]; if (!sel) return null; var r = sel.name + ':'; if (sel.phone) r += '\nTelepon: ' + sel.phone; if (sel.address) r += '\nAlamat: ' + sel.address; _lastPublicServiceResults = []; return { reply: r, intent: 'public_service', confidence: 0.92, action: 'none' }; }

  /* DOM HELPERS */
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
  var _technicalLabels = {}; (function () { data.scenes.forEach(function (s) { var r = _sceneRegistry[s.id]; if (r && s.rawName !== r.placeName) _technicalLabels[s.rawName] = r.placeName; }); })();
  function sanitizeReply(r) { if (!r) return r; var c = r; Object.keys(_technicalLabels).forEach(function (b) { if (b && c.indexOf(b) !== -1) c = c.split(b).join(_technicalLabels[b]); }); return c; }
  function currentScene() { var id = currentSceneId(); return data.scenes.find(function (s) { return s.id === id; }) || data.scenes[0]; }

  /* PROGRESSIVE REVEAL */
  function cancelProgressiveReveal() {
    if (!_streamState) return;
    clearInterval(_streamState.timerId); clearTimeout(_streamState.timerId);
    if (_streamState.active !== undefined) _streamState.active = false;
    if (_streamState.el) _streamState.el.textContent = _streamState.fullText;
    _streamState = null;
  }
  function startProgressiveReveal(text, element) {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { element.textContent = text; _streamState = null; return; }
    var tokens = text.split(/(\s+)/).filter(function (t) { return t.length > 0; });
    var baseDelay = text.length < 200 ? 18 : (text.length < 600 ? 12 : 7);
    var punc = { ',': 20, '.': 35, '!': 35, '?': 35, '\n': 45 };
    var idx = 0, displayed = '';
    var caret = el('span', 'tour-chat__caret'); element.appendChild(caret);
    var stream = { fullText: text, el: element, timerId: null, chunks: tokens, active: true };
    _streamState = stream;
    function tick() {
      if (!stream.active) return;
      if (idx >= tokens.length) { stream.el.textContent = stream.fullText; if (_streamState === stream) _streamState = null; return; }
      displayed += tokens[idx]; stream.el.textContent = displayed; stream.el.appendChild(el('span', 'tour-chat__caret')); idx++;
      var last = tokens[idx - 1].trim().charAt(tokens[idx - 1].trim().length - 1);
      stream.timerId = setTimeout(tick, punc[last] || baseDelay);
    }
    stream.timerId = setTimeout(tick, 80);
  }

  /* ================================================================
     STATE MACHINE
     CLOSED (State 0) | DISCOVERY/ASK (State 1) | WORKSPACE/EXPLORATION (State 2) | EXPANDED/HISTORY
     ================================================================ */
  var _closingTimer = null;
  function closeWorkspaceSmooth() {
    var d = _dom; if (!d) return;
    if (_closingTimer) clearTimeout(_closingTimer);
    _uiState = UIState.CLOSED;
    d.left.classList.remove('tour-chat__left--visible');
    d.rightStack.classList.remove('tour-chat__right-stack--visible');
    d.questionbar.classList.remove('tour-chat__questionbar--visible');
    d.location.classList.remove('tour-chat__location--visible');
    d.arrival.classList.remove('tour-chat__arrival--visible');
    d.history.classList.remove('tour-chat__history--visible');
    d.contextSheet.classList.remove('tour-chat__context-sheet--visible');
    d.objectsPanel.style.display = 'none';
    d.root.setAttribute('data-state', 'closing');
    _closingTimer = setTimeout(function () {
      _closingTimer = null;
      setUIState(UIState.CLOSED);
    }, 400);
  }

  function setUIState(state) {
    if (_closingTimer) { clearTimeout(_closingTimer); _closingTimer = null; }
    var _prevUIState = _uiState;
    _uiState = state; if (!_dom) return; var d = _dom;
    if (d.root.classList.contains('tour-chat--not-ready')) return;

    // Reset all surfaces
    d.aiBtn.style.display = 'none';
    d.veil.style.display = 'none';
    d.discovery.style.display = 'none';
    d.discTopbar.style.display = 'none';
    d.discoveryClose.style.display = 'none';
    d.left.style.display = 'none';
    d.left.classList.remove('tour-chat__left--visible');
    d.left.classList.remove('tour-chat__left--mobile-hidden');
    d.left.classList.remove('tour-chat__left--mobile-open');
    d.objectsPanel.style.display = 'none';
    d.rightStack.style.display = 'none';
    d.rightStack.classList.remove('tour-chat__right-stack--visible');
    d.questionbar.style.display = '';
    d.questionbar.classList.remove('tour-chat__questionbar--visible');
    d.location.style.display = 'none';
    d.location.classList.remove('tour-chat__location--visible');
    d.arrival.classList.remove('tour-chat__arrival--visible');
    d.history.classList.remove('tour-chat__history--visible');
    d.contextSheet.classList.remove('tour-chat__context-sheet--visible');
    hideDiscStatus();
    // Penanda state discovery di root — dipakai CSS (mis. veil mobile segelap desktop saat discovery).
    d.root.classList.toggle('tour-chat--discovery', state === UIState.DISCOVERY);

    // Hide non-essential Pano2VR skin elements while AI mode is active, using a
    // toggled CLASS on the skin container (not inline style mutation). Removing
    // the class restores the skin exactly, so it can never be left non-interactive.
    toggleSkinHidden(state !== UIState.CLOSED && state !== UIState.DISCOVERY);

    switch (state) {
      case UIState.CLOSED:
        d.aiBtn.style.display = '';
        d.aiBtn.classList.add('tour-chat__ai-btn--visible');
        d.root.setAttribute('data-state', 'closed');
        ttsStop();
        try { navCardHideFloat(); } catch (eNavFloat) {}
        if (_modeTab) _modeTab.tab.style.display = 'none';
        // Hentikan voice hanya saat benar-benar meninggalkan mode aktif
        // (bukan saat setUIState(CLOSED) yang sama diulang oleh boot()).
        if (_voice && _voice.state !== 'idle' && _voice.state !== 'ended' && _prevUIState !== UIState.CLOSED) { voiceStop(true); _voiceMode = false; voiceSyncTab(); voiceRestoreTextPanels(); voicePauseBgMusic(false); }
        break;
      case UIState.DISCOVERY:
        if (_modeTab) _modeTab.tab.style.display = 'none';
        if (_voice && _voice.el) voiceHidePanel();
        d.veil.style.display = '';
        d.discovery.style.display = '';
        d.discTopbar.style.display = '';
        d.discoveryClose.style.display = '';
        // Defer data-state so the browser first paints the hidden (opacity 0)
        // state, letting the veil + discovery fade in smoothly instead of popping.
        requestAnimationFrame(function () {
          requestAnimationFrame(function () {
            d.root.setAttribute('data-state', 'open');
            renderDiscovery();
            setTimeout(function () { if (d.discInput) d.discInput.focus(); }, 350);
          });
        });
        break;
      case UIState.WORKSPACE:
        voiceSyncTab();
        if (_voiceMode) {
          // Mode suara: hanya kartu suara + tab; panorama tetap fokus.
          voiceHideTextPanels();
          voiceOpenPanel();
          if (_modeTab) _modeTab.tab.style.display = '';
          requestAnimationFrame(function () {
            requestAnimationFrame(function () {
              d.root.setAttribute('data-state', 'open');
            });
          });
          break;
        }
        d.left.style.display = '';
        d.rightStack.style.display = '';
        d.questionbar.style.display = '';
        d.location.style.display = '';
        if (_modeTab) _modeTab.tab.style.display = '';
        requestAnimationFrame(function () {
          requestAnimationFrame(function () {
            d.root.setAttribute('data-state', 'open');
          });
        });
        renderLeftPanel();
        refreshLocation();
        setTimeout(function () { d.left.classList.add('tour-chat__left--visible'); }, 50);
        setTimeout(function () { d.rightStack.classList.add('tour-chat__right-stack--visible'); }, 150);
        setTimeout(function () { d.questionbar.classList.add('tour-chat__questionbar--visible'); }, 260);
        setTimeout(function () { d.location.classList.add('tour-chat__location--visible'); }, 320);
        break;
      case UIState.EXPANDED:
        d.history.style.display = '';
        if (_voiceMode) {
          // Mode suara: hanya sidebar riwayat yang tampil (panel teks tetap sembunyi).
          voiceHideTextPanels();
          d.history.style.display = '';
          if (d.root) d.root.setAttribute('data-state', 'open');
          setTimeout(function () { d.history.classList.add('tour-chat__history--visible'); }, 60);
          renderHistory();
          break;
        }
        d.left.style.display = '';
        d.rightStack.style.display = '';
        d.questionbar.style.display = '';
        d.location.style.display = '';
        d.history.style.display = '';
        requestAnimationFrame(function () {
          requestAnimationFrame(function () {
            d.root.setAttribute('data-state', 'open');
          });
        });
        setTimeout(function () { d.history.classList.add('tour-chat__history--visible'); }, 60);
        renderHistory();
        break;
    }
  }

  /* Toggle hiding of non-essential Pano2VR skin elements while AI Exploration is
     active. We preserve each element's original inline style and restore it
     exactly on close, so the skin can never be left non-interactive.
     Hotspot containers (ggId === 'hotspot' or .ggskin_hotspot) are excluded so
     navigation hotspots stay usable for scene navigation. Object-info hotspots
     (ggId prefix 'hotspot_oi_polygon', the INFOOO markers) are treated like
     other skin elements and hidden while AI mode is active.
     NOTE: a hidden container is not enough on its own — Pano2VR sets
     `pointer-events: auto` on inner buttons/images, which would otherwise
     override the parent's `pointer-events: none` and keep blocking drags on the
     panorama behind it. So we disable pointer-events recursively on every
     descendant, while still skipping navigation hotspot subtrees. */
  function setSkinPointerEvents(el, enable) {
    if (!el || !el.classList) return;
    if (isHotspotSkin(el) && !isObjectInfoHotspot(el)) return;
    if (enable) {
      if (el.hasAttribute('data-gva-pe')) { el.style.pointerEvents = el.getAttribute('data-gva-pe'); el.removeAttribute('data-gva-pe'); }
    } else {
      if (!el.hasAttribute('data-gva-pe')) el.setAttribute('data-gva-pe', el.style.pointerEvents || '');
      el.style.pointerEvents = 'none';
    }
    var kids = el.children;
    for (var i = 0; i < kids.length; i++) setSkinPointerEvents(kids[i], enable);
  }

  function isHotspotSkin(e) {
    return !!(e && e.classList && (e.ggId === 'hotspot' || e.classList.contains('ggskin_hotspot')));
  }

  function isObjectInfoHotspot(e) {
    return !!(e && e.ggId && String(e.ggId).indexOf('hotspot_oi_polygon') === 0);
  }

  function applySkinHidden(e, hide) {
    if (hide) {
      if (!e.hasAttribute('data-gva-op')) e.setAttribute('data-gva-op', e.style.opacity || '');
      e.style.opacity = '0';
      setSkinPointerEvents(e, false);
    } else {
      if (e.hasAttribute('data-gva-op')) { e.style.opacity = e.getAttribute('data-gva-op'); e.removeAttribute('data-gva-op'); }
      setSkinPointerEvents(e, true);
    }
  }

  function toggleSkinHidden(hide) {
    var skinObj = window.skin || (window.pano && window.pano.skinObj) || null;
    var skinDiv = skinObj && skinObj.divSkin ? skinObj.divSkin : null;
    if (!skinDiv) return;
    var children = skinDiv.children;
    for (var i = 0; i < children.length; i++) {
      var e = children[i];
      if (!e.classList || !e.classList.contains('ggskin')) continue;
      if (isHotspotSkin(e)) {
        // Object-info hotspots (INFOOO markers) hide like other skin elements;
        // navigation hotspots stay usable for scene navigation.
        if (isObjectInfoHotspot(e)) { applySkinHidden(e, hide); }
        else if (e.ggId === 'hotspot') {
          var hs = e.children;
          for (var j = 0; j < hs.length; j++) {
            if (isObjectInfoHotspot(hs[j])) applySkinHidden(hs[j], hide);
          }
        }
        continue;
      }
      applySkinHidden(e, hide);
    }
  }

  /* ================================================================
     RENDER: DISCOVERY — fullscreen Ask Overlay (State 1)
     ================================================================ */
  function renderDiscovery() {
    var d = _dom; if (!d) return;
    // Update current location context
    var scene = currentScene();
    var placeName = scene ? sceneDisplayName(scene) : '';
    if (d.discLocName) d.discLocName.textContent = placeName;
    // Reset ask input (clear any processing placeholder / disabled state)
    if (d.discInput) { d.discInput.placeholder = 'Tanyakan apa saja tentang ' + (data.name || tourName) + '\u2026'; d.discInput.disabled = false; }
    if (d.discAskbar) d.discAskbar.classList.remove('tour-chat__disc-askbar--loading');
    // Populate "COBA TANYAKAN" starters (max 3, deduplicated)
    d.discStarters.innerHTML = '';
    var starters = buildSmartStarters();
    var seen = {}, deduped = [];
    starters.forEach(function (s) {
      var key = questionKey(s.query);
      if (seen[key]) return; seen[key] = 1; deduped.push(s);
    });
    deduped.forEach(function (s) {
      var item = el('button', 'tour-chat__disc-starter');
      item.type = 'button';
      item.appendChild(el('span', 'tour-chat__disc-starter-arrow', '\u203A'));
      item.appendChild(el('span', 'tour-chat__disc-starter-text', s.desc));
      item.addEventListener('click', function () { submitAI(s.query); });
      d.discStarters.appendChild(item);
    });
  }

  function questionKey(q) {
    var n = (q || '').toLowerCase();
    n = n.replace(/[^a-z0-9\s]/g, '').replace(/\s+/g, ' ').trim();
    n = n.replace(/\bmengapa\b/g, 'kenapa').replace(/\bmengenai\b/g, 'tentang')
         .replace(/\bapa\b/g, '').replace(/\bsaya\b/g, '').replace(/\byang\b/g, '')
         .replace(/\bdisebut\b/g, '').replace(/\bda\b/g, '').replace(/\bdi\b/g, '')
         .replace(/\bini\b/g, '').replace(/\bbisa\b/g, '');
    return n.replace(/\s+/g, ' ').trim();
  }

  function buildSmartStarters() {
    // Per-tour override via recommended-questions.json (State1) dipakai bila ada.
    if (_recQuestions && _recQuestions.state1 && _recQuestions.state1.starters && _recQuestions.state1.starters.length) {
      var out=[]; for(var i=0;i<_recQuestions.state1.starters.length && out.length<4;i++){ var s=_recQuestions.state1.starters[i]; if(s && s.query) out.push({ icon: '\u2728', title: s.label||s.desc||s.query, desc: s.desc||s.query, query: s.query }); }
      if(out.length) return out.slice(0,4);
    }
    var scene = currentScene();
    var si = scene ? getSceneIdentityEntry(scene.id) : null;
    var starters = [];
    if (si && si.suggestedQuestions && si.suggestedQuestions.length) {
      for (var qi = 0; qi < si.suggestedQuestions.length; qi++) {
        var q = sqQ(si.suggestedQuestions[qi]); if (!q) continue;
        starters.push({ icon: '\uD83D\uDCCD', title: q, desc: q, query: q });
      }
    }
    starters.push({ icon: '\uD83E\uDDED', title: 'Kemana Berikutnya', desc: 'Ke mana setelah dari sini? Saya bisa arahkan.', query: 'Kemana berikutnya?' });
    return starters;
  }
  function showDiscStatus(msg, isError) {
    var d = _dom; if (!d || !d.discStatus) return;
    d.discStatus.textContent = msg;
    d.discStatus.className = 'tour-chat__disc-status tour-chat__disc-status--visible' + (isError ? ' tour-chat__disc-status--error' : '');
    if (isError && d.discStatusRetry) { d.discStatusRetry.style.display = ''; }
  }
  function hideDiscStatus() {
    var d = _dom; if (!d || !d.discStatus) return;
    d.discStatus.className = 'tour-chat__disc-status';
  }

  /* ================================================================
     RENDER: SUGGESTIONS — unified, dynamic per panorama
     Merges scene-contextual questions with conversation-driven prompts.
     ================================================================ */
  function renderSuggestions() {
    var d = _dom; if (!d) return;
    var scene = currentScene();
    var contextual = scene ? buildContextualSuggestions({ sceneId: scene.id }) : [];
    var seen = {}, merged = [];
    function pushIfNew(s) {
      if (!s || !s.prompt) return;
      var key = normalize(s.prompt);
      if (seen[key]) return;
      seen[key] = true;
      merged.push(s);
    }
    // Per panorama: seluruh suggested questions admin + "Kemana Berikutnya?" (paling bawah).
    contextual.forEach(pushIfNew);
    d.leftSugList.innerHTML = '';
    merged.forEach(function (s) {
      var btn = el('button', 'tour-chat__left-sug', s.label); btn.type = 'button';
      btn.addEventListener('click', function () { submitAI(s.prompt); });
      d.leftSugList.appendChild(btn);
    });
    if (d.sugBox) d.sugBox.style.display = merged.length ? '' : 'none';
  }
  function renderLeftPanel(forceSceneId) {
    var d = _dom; if (!d) return;
    var scene = forceSceneId ? sceneById(forceSceneId) : currentScene();
    // Dim briefly while rebuilding so the swap cross-fades instead of popping.
    if (d.leftHeader) d.leftHeader.classList.add('tour-chat__switching');
    if (d.leftPano) d.leftPano.classList.add('tour-chat__switching');
    if (d.objectsList) d.objectsList.classList.add('tour-chat__switching');
    var si = scene ? getSceneIdentityEntry(scene.id) : null;
    d.leftContextName.textContent = scene ? sceneDisplayName(scene) : '';
    var desc = '';
    if (si && si.shortDescription) desc = si.shortDescription;
    else if (scene && scene.description) desc = scene.description;
    if (!desc) {
      var sName = scene ? sceneDisplayName(scene) : tourName;
      desc = sName + ' adalah bagian dari kawasan ' + tourName + (si && si.placeName ? ' — ' + si.placeName : '') + '. Jelajahi panorama ini dan temukan objek di sekitar.';
    }
    d.leftContextDesc.textContent = desc;

    // Active panorama — navigation exits (thumbnails + labels, click to travel)
    d.leftPano.innerHTML = '';
    var exits = panoExits(scene ? scene.id : '');
    if (exits.length) {
      d.leftPano.appendChild(el('div', 'tour-chat__left-pano-label', 'Dari sini Anda bisa ke :'));
      var grid = el('div', 'tour-chat__left-pano-grid');
      exits.forEach(function (ex) {
        var card = el('button', 'tour-chat__left-pano-card'); card.type = 'button';
        var media = el('div', 'tour-chat__left-pano-media');
        var img = el('img', 'tour-chat__left-pano-img');
        img.src = panoThumbnailUrl(scene.id, ex.suffix);
        // Nama panorama dari data AI Studio (displayName target), bukan judul hotspot skin.
        var tgtScene = sceneById(ex.target);
        var destName = tgtScene ? sceneDisplayName(tgtScene) : (ex.title || ex.target);
        img.alt = destName; img.loading = 'lazy';
        media.appendChild(img);
        media.appendChild(el('div', 'tour-chat__left-pano-title', destName));
        card.appendChild(media);
        card.addEventListener('click', function () { goToSceneId(ex.target); });
        grid.appendChild(card);
      });
      d.leftPano.appendChild(grid);
      d.leftPano.style.display = '';
    } else {
      d.leftPano.style.display = 'none';
    }

    // DALAM PANDANGAN — visible objects / reachable context (separate bottom panel)
    var objects = visibleObjectsForScene(scene ? scene.id : null);
    d.objectsList.innerHTML = '';
    d.objectsPanel.style.display = objects.length ? '' : 'none';
    // Tombol katalog lengkap selalu tersedia di semua panorama (bukan hanya yang tanpa objek).
    if (d.objectsAllCorner) {
      d.objectsAllCorner.textContent = 'Lihat Semua Informasi';
      d.objectsAllCorner.style.display = '';
    }
    // Left panel now only holds the panorama navigation, so let it fill the height.
    d.leftPano.classList.add('tour-chat__left-pano--grow');
    objects.forEach(function (o) {
      var btn = el('button', 'tour-chat__objects-card');
      btn.type = 'button';
      var img = el('img', 'tour-chat__objects-card-img'); img.alt = o.label; img.loading = 'lazy';
      btn.appendChild(img);
      btn.appendChild(el('span', 'tour-chat__objects-card-name', o.label));
      if (o.kind === 'info') {
        setObjCardImage(img, o.infoId);
        btn.addEventListener('click', function () { openInfoFrame(o.label, o.infoId, o.oiIndex); });
      } else if (o.kind === 'entity') {
        var es0 = (o.entity && o.entity.sceneIds && o.entity.sceneIds.length) ? sceneById(o.entity.sceneIds[0]) : null;
        if (es0) {
          var t = panoThumbnailUrl(es0.id, 'Point01');
          if (t) { img.src = t; img.addEventListener('error', function () { img.classList.add('tour-chat__objects-card-img--ph'); }); }
          else img.classList.add('tour-chat__objects-card-img--ph');
        } else { img.classList.add('tour-chat__objects-card-img--ph'); }
        btn.addEventListener('click', function () { submitAI('Apa itu ' + o.label + '?'); });
      }
      d.objectsList.appendChild(btn);
    });

    // Suggestions — contextual (scene) merged with dynamic (conversation) prompts
    renderSuggestions();
    // Fade the rebuilt content back in. Double rAF guarantees one paint with
    // the dim class first, otherwise the fade never becomes visible.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        if (d.leftHeader) d.leftHeader.classList.remove('tour-chat__switching');
        if (d.leftPano) d.leftPano.classList.remove('tour-chat__switching');
        if (d.objectsList) d.objectsList.classList.remove('tour-chat__switching');
      });
    });
  }

  function loadOI() {
    if (_oiPositions) return Promise.resolve(_oiPositions);
    if (_oiPositionsLoading) return _oiPositionsLoading;
    _oiPositionsLoading = fetch(basePath + 'data/oi-positions.json')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.scenes) { _oiPositions = d.scenes; return _oiPositions; }
        // Fallback for older deployments without oi-positions.json: parse the
        // original pano.xml (larger, slower) once.
        return fetch(basePath + '../pano.xml')
          .then(function (r) { return r.ok ? r.text() : null; })
          .then(function (xml) {
            var map = {};
            if (!xml) { _oiPositions = map; return map; }
            var sceneRe = /<panorama id="([^"]+)"[\s\S]*?<hotspots[\s\S]*?<\/hotspots>/g;
            var m;
            while ((m = sceneRe.exec(xml)) !== null) {
              var sceneId = m[1];
              var hsBlock = m[0];
              var oiRe = /<hotspot[^>]*?skinid="hotspot_oi_polygon[^"]*"[^>]*>/g;
              var om, pos = [];
              while ((om = oiRe.exec(hsBlock)) !== null) {
                var tag = om[0];
                var pan = parseFloat((/pan="(-?[\d.]+)"/.exec(tag) || [])[1]);
                var tilt = parseFloat((/tilt="(-?[\d.]+)"/.exec(tag) || [])[1]);
                var fov = parseFloat((/fov="(-?[\d.]+)"/.exec(tag) || [])[1]);
                if (!isNaN(pan)) pos.push({ pan: pan, tilt: isNaN(tilt) ? 0 : tilt, fov: isNaN(fov) ? 70 : fov });
              }
              if (pos.length) map[sceneId] = pos;
            }
            _oiPositions = map;
            return map;
          });
      })
      .catch(function () { _oiPositions = {}; return _oiPositions; });
    return _oiPositionsLoading;
  }

  // Camera framing when focusing an info object: keep a wide view (only a slight
  // zoom-in) and aim quickly.
  var OBJECT_VIEW_FOV = 90;     // wider FOV = less zoom toward the object
  var OBJECT_VIEW_ANIM = 0.9;   // smooth-but-quick camera glide (seconds)

  function objectPanTo(sceneId, index) {
    loadOI().then(function (map) {
      var arr = (map && map[sceneId]) || [];
      var p = arr[index] || arr[0];
      if (p && window.pano) {
        var fov = OBJECT_VIEW_FOV; // keep wide view, no zoom-in
        // Instant aim: jump straight to the object's pan/tilt.
        if (typeof window.pano.setPanTiltFov === 'function') {
          window.pano.setPanTiltFov(p.pan, p.tilt, fov);
        } else if (typeof window.pano.moveTo === 'function') {
          window.pano.moveTo(p.pan, p.tilt, fov, 0);
        }
      }
    });
  }

  // Play a 360 video: navigate to its scene if needed, aim at the entry hotspot,
  // then start the Pano2VR video playback.
  function playVideo360(v) {
    if (!v || !v.scene) return;
    if (currentSceneId() !== v.scene) {
      _pendingVideoPlay = v;
      var sc = sceneById(v.scene);
      if (sc) { navigate(sc); return; }
    }
    aimAndPlayVideo360(v);
  }

  function aimAndPlayVideo360(v) {
    if (v && v.pan !== undefined && window.pano) {
      if (typeof window.pano.setPanTiltFov === 'function') window.pano.setPanTiltFov(v.pan, v.tilt || 0, OBJECT_VIEW_FOV);
      else if (typeof window.pano.moveTo === 'function') window.pano.moveTo(v.pan, v.tilt || 0, OBJECT_VIEW_FOV, 0);
    }
    var p = window.pano || (window.skin && window.skin.player) || null;
    if (p && typeof p.videoPanoPlay === 'function') p.videoPanoPlay();
  }

  function visibleObjectsForScene(sceneId) {
    var items = [], seen = {}, oiIdx = 0;
    // Primary source: scene-identity relatedKnowledgeIds (webview:objinfo2:{id}).
    var si = sceneId ? getSceneIdentityEntry(sceneId) : null;
    var known = {};
    if (_knowledgeData && _knowledgeData.entries) { _knowledgeData.entries.forEach(function (e) { known[e.id] = e; }); }
    if (si && si.relatedKnowledgeIds) {
      // Hanya tampilkan objek yang benar-benar punya marker (posisi OI) di
      // scene ini. Scene tanpa marker tidak boleh menampilkan objek informasi.
      // Jika data OI belum termuat, tampilkan semua (toleran paint pertama).
      var oiArr = (_oiPositions && sceneId) ? (_oiPositions[sceneId] || null) : null;
      var oiReady = !!(_oiPositions && Object.keys(_oiPositions).length);
      si.relatedKnowledgeIds.forEach(function (rid) {
        var m = /objinfo2:(\d+)/.exec(rid);
        if (!m) return;
        var curIdx = oiIdx;
        oiIdx++;
        var e = known[rid];
        var label = e && e.title ? e.title : '';
        if (!label && m) label = 'Objek #' + m[1];
        if (!label) return;
        var key = normalize(label); if (seen[key]) return;
        seen[key] = true;
        if (oiReady && (!oiArr || !oiArr[curIdx])) return;
        items.push({ label: label, kind: 'info', infoId: m[1], oiIndex: curIdx });
      });
    }
    // Secondary source: explore-menu entities mapped to this scene.
    if (_exploreMenuData && _exploreMenuData.entries) {
      var infoTitles = [];
      items.forEach(function (it) {
        if (it.kind !== 'info') return;
        var ke = known['webview:objinfo2:' + it.infoId];
        infoTitles.push(it.label);
        if (ke && ke.aliases) infoTitles = infoTitles.concat(ke.aliases);
      });
      infoTitles = infoTitles.map(normalize).filter(function (t) { return t && t.length >= 3; });
      _exploreMenuData.entries.forEach(function (e) {
        if (!e.sceneIds || e.sceneIds.indexOf(sceneId) === -1) return;
        var key = normalize(e.title); if (seen[key]) return;
        var entAliases = [e.title].concat(e.aliases || []).map(normalize).filter(function (a) { return a && a.length >= 3; });
        var dup = entAliases.some(function (a) {
          return infoTitles.some(function (b) {
            if (a === b || a.indexOf(b) !== -1 || b.indexOf(a) !== -1) return true;
            var ta = a.split(/\s+/), tb = b.split(/\s+/);
            for (var x = 0; x < ta.length; x++) for (var y = 0; y < tb.length; y++) if (ta[x].length >= 4 && ta[x] === tb[y]) return true;
            return false;
          });
        });
        if (dup) return;
        seen[key] = true;
        items.push({ label: e.title, kind: 'entity', entity: e });
      });
    }
    return items.slice(0, 8);
  }

  /* Resolve which panorama scene an info object (objinfo2:N) lives in and its
     OI polygon index, so we can navigate to that scene and aim the camera.
     scene-identity relatedKnowledgeIds are ordered to match the pano.xml
     hotspot_oi_polygon order used by objectPanTo(). */
  function resolveObjectScene(infoId) {
    if (!_sceneIdentityData || !_sceneIdentityData.scenes) return null;
    var id = String(infoId);
    var scenes = _sceneIdentityData.scenes;
    var candidates = [];
    for (var sid in scenes) {
      if (!scenes.hasOwnProperty(sid)) continue;
      var rki = scenes[sid].relatedKnowledgeIds;
      if (!rki || !rki.length) continue;
      var oiIdx = 0;
      for (var i = 0; i < rki.length; i++) {
        var m = /objinfo2:(\d+)/.exec(rki[i]);
        if (!m) continue;
        if (m[1] === id) { candidates.push({ sceneId: sid, oiIndex: oiIdx }); break; }
        oiIdx++;
      }
    }
    if (!candidates.length) return null;
    // Prefer a scene whose panorama actually has an OI polygon at that index so
    // the camera can aim at the object (e.g. node64, not node63/node71-73 which
    // list the object but expose no hotspot polygon). Then prefer the scene the
    // visitor is currently in, and finally fall back to the first candidate.
    var oi = _oiPositions || {};
    var withPoly = [];
    for (var c = 0; c < candidates.length; c++) {
      var arr = oi[candidates[c].sceneId];
      if (arr && arr[candidates[c].oiIndex]) withPoly.push(candidates[c]);
    }
    if (withPoly.length) {
      var cur = currentSceneId();
      for (var p = 0; p < withPoly.length; p++) if (withPoly[p].sceneId === cur) return withPoly[p];
      return withPoly[0];
    }
    return candidates[0];
  }

  // Resolve exact cover from the objinfo page server-side; never substitute a
  // panorama thumbnail because it represents the location, not the object.
  function proxyImg(url) {
    if (!url) return '';
    if (url.indexOf('webview-govirtual.id') === -1) return url;
    return basePath + 'api/img-proxy.php?url=' + encodeURIComponent(url);
  }

function setObjCardImage(img, infoId) {
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
  }

  /* Preload SEMUA cover objek info di awal (sekali, background) agar thumbnail
     sudah siap saat panel "OBJEK YANG BISA DILIHAT" / "Lihat semua objek" dibuka.
     Dipanggil dari boot() setelah knowledge termuat. */
  function preloadObjCovers() {
    if (_objCoverWarmed) return; _objCoverWarmed = true;
    loadKnowledge().then(function (kd) {
      var ids = [];
      var seen = {};
      if (kd && kd.entries) kd.entries.forEach(function (e) {
        var m = /objinfo2:(\d+)/.exec(e.id || '');
        if (m && !seen[m[1]]) { seen[m[1]] = 1; ids.push(m[1]); }
      });
      if (!ids.length) return;
      fetch(basePath + 'api/objinfo-cover.php?info=' + ids.join(',') + '&lang=id')
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          var map = (d && d.images) || {};
          if (d && d.image && ids.length === 1 && !map[ids[0]]) map[ids[0]] = d.image;
          ids.forEach(function (id) {
            var url = map[id] || null;
            if (url) {
              _objCoverCache[id] = url;
              try { var im = new Image(); im.src = proxyImg(url); } catch (e) { /* noop */ }
            } else {
              _objCoverCache[id] = false;
            }
          });
        })
        .catch(function () { /* permukaan gambar tetap lazy seperti semula */ });
    }).catch(function () { /* noop */ });
  }

  /* ================================================================
     TEXT-TO-SPEECH — baca jawaban dengan suara perempuan natural.
     Hanya aktif bila user menyalakan switch; mati = sunyi seperti semula.
     ================================================================ */
  function ttsSupported() {
    try { return ('speechSynthesis' in window) && ('SpeechSynthesisUtterance' in window); } catch (e) { return false; }
  }
  function ttsWarmVoices() {
    if (_ttsWarmedVoices) return; _ttsWarmedVoices = true;
    try {
      if (!('speechSynthesis' in window)) return;
      window.speechSynthesis.getVoices();
      var refresh = function () { try { window.speechSynthesis.getVoices(); } catch (e) {} };
      if (window.speechSynthesis.addEventListener) window.speechSynthesis.addEventListener('voiceschanged', refresh);
      else window.speechSynthesis.onvoiceschanged = refresh;
    } catch (e) { /* noop */ }
  }
  function ttsPickVoice() {
    try {
      if (!('speechSynthesis' in window)) return null;
      var vs = window.speechSynthesis.getVoices() || [];
      if (!vs.length) return null;
      var best = null, bestScore = -1;
      for (var i = 0; i < vs.length; i++) {
        var v = vs[i] || {};
        var n = String(v.name || '') + ' ' + String(v.lang || '');
        var s = 0;
        if (/^id([-_]|$)/i.test(v.lang || '')) s += 70;
        else if (/indonesia/i.test(n)) s += 60;
        if (/natural|neural|online|google|premium|wavenet/i.test(n)) s += 20;
        if (/google/i.test(n) && /^id([-_]|$)/i.test(v.lang || '')) s += 30;
        if (/female|woman|girl|gadis|perempuan|wanita|damayanti|sari|dewi|kartini|ratna|maya|arum|indah|meilani|zira|samantha|aria|jenny|sonia|imelda|keira|meimei|michelle|ava|emma|hazel|libby|susan|karen|natasha/i.test(n)) s += 45;
        if (/\bmale\b|\bman\b|boy|pria|laki|ardi\b|david|mark|daniel|budi|andika|harry|michael|george|oliver|james|jacob/i.test(n)) s -= 20;
        if (s > bestScore) { bestScore = s; best = v; }
      }
      return best;
    } catch (e) { return null; }
  }
  /* Prosodi per jenis kalimat agar tidak monoton: tanya menanjak, seru
     berenergi, pernyataan netral; bagian bertanda kutip diberi penekanan. */
  function ttsProsody(kind, emph) {
    var r = 1.1, pi = 1.06;
    if (kind === 'question') { r = 1.08; pi = 1.18; }
    else if (kind === 'exclaim') { r = 1.13; pi = 1.14; }
    if (emph) { r = Math.max(0.9, r - 0.06); pi = Math.min(1.4, pi + 0.05); }
    return { rate: r, pitch: pi };
  }
  var _ttsAudio = null;
  var _ttsApiFailed = false;
  var _ttsApiFailAt = 0;
  // Engine TTS konsisten: sekali gagal → browser untuk sisa momen; server
  // dicoba lagi setelah 5 menit (pulih dari flake tanpa flip-flop tiap ucapan).
  function ttsServerAllowed() {
    try {
      if (!_ttsApiFailed) return true;
      if (Date.now() - _ttsApiFailAt > 300000) { _ttsApiFailed = false; _ttsApiFailAt = 0; return true; }
      return false;
    } catch (e) { return !_ttsApiFailed; }
  }
  function ttsServerFailed() {
    _ttsApiFailed = true;
    try { _ttsApiFailAt = Date.now(); } catch (e2) {}
  }
  function ttsServerOk() { _ttsApiFailed = false; _ttsApiFailAt = 0; }
  /* Prefetch audio TTS (maks 6 entri) — dipanggil saat jawaban muncul agar
     sintesis server berjalan paralel dengan reveal teks; saat playback mulai,
     chunk pertama sudah siap → suara bunyi instan. */
  var _ttsPrefetch = {};
  function ttsChunkUrl(t) {
    var base = basePath.replace(/\/?$/, '/');
    return base + 'api/tts.php?text=' + encodeURIComponent(t) + '&lang=id&rate=-5&pitch=' + encodeURIComponent('+2');
  }
  function ttsPrefetchUrl(url) {
    if (!url || _ttsPrefetch[url] || typeof fetch !== 'function') return;
    fetch(url).then(function (r) { return (r && r.ok) ? r.blob() : null; }).then(function (blob) {
      if (!blob) return;
      try {
        var keys = Object.keys(_ttsPrefetch);
        if (keys.length > 5 && _ttsPrefetch[keys[0]] !== (_ttsAudio && _ttsAudio.src)) {
          try { URL.revokeObjectURL(_ttsPrefetch[keys[0]]); } catch (e0) {}
          delete _ttsPrefetch[keys[0]];
        }
        _ttsPrefetch[url] = URL.createObjectURL(blob);
      } catch (e2) {}
    }).catch(function () {});
  }
  function ttsApiSpeak(chunks, mySeq) {
    ttsDuckMusic(true);
    var idx = 0;
    function next() {
      if (mySeq !== _ttsSeq) { ttsDuckMusic(false); return; }
      if (idx >= chunks.length) { if (mySeq === _ttsSeq) ttsDuckMusic(false); return; }
      var c = chunks[idx++];
      var url = ttsChunkUrl(c.t);
      if (chunks[idx]) ttsPrefetchUrl(ttsChunkUrl(chunks[idx].t)); // prefetch chunk berikut selagi chunk ini bunyi
      var a = _ttsAudio || new Audio();
      _ttsAudio = a;
      a.onended = next;
      a.onerror = function () {
        if (idx <= 1 && ttsServerAllowed() && ttsSupported()) {
          ttsServerFailed();
          try { if ('speechSynthesis' in window) window.speechSynthesis.cancel(); } catch (e) {}
          ttsWebSpeak(chunks, mySeq);
          return;
        }
        next();
      };
      var pre = _ttsPrefetch[url];
      a.src = pre || url;
      a.volume = 1;
      a.playbackRate = 1;
      var pr2 = a.play ? a.play() : null;
      if (pr2 && pr2.catch) pr2.catch(function () { try { a.onerror && a.onerror(); } catch (e) {} });
    }
    next();
  }
  function ttsWebSpeak(chunks, mySeq) {
    try {
      if (!ttsSupported()) { ttsDuckMusic(false); return; }
      var synth = window.speechSynthesis;
      try { if (synth.paused) synth.resume(); } catch (e0) {}
      ttsEnsureVoices(function () {
        try {
          if (mySeq !== _ttsSeq) return;
          var voice = ttsPickVoice();
          var lang = (voice && voice.lang) || 'id-ID';
          try { if (synth.paused) synth.resume(); } catch (e2) {}
          ttsDuckMusic(true);
          chunks.forEach(function (c, ci) {
            var u = new SpeechSynthesisUtterance(c.t);
            if (voice) { try { u.voice = voice; } catch (e3) {} }
            var pr3 = ttsProsody(c.kind, c.emph);
            u.lang = lang; u.rate = pr3.rate; u.pitch = pr3.pitch; u.volume = 1;
            if (ci === chunks.length - 1) {
              u.onend = function () { if (mySeq === _ttsSeq) ttsDuckMusic(false); };
              u.onerror = function () { if (mySeq === _ttsSeq) ttsDuckMusic(false); };
            }
            synth.speak(u);
          });
        } catch (e5) { /* noop */ }
      });
    } catch (e4) { /* noop */ }
  }
  /* Substitusi khusus input TTS (teks tampil/riwayat tidak berubah). */
  var TTS_PHONETIC = [['AI Guide', 'pemandu kecerdasan buatan'], ['AI guide', 'pemandu kecerdasan buatan'], ['ai guide', 'pemandu kecerdasan buatan']];
  function ttsPhonetic(text) {
    var s = String(text || '');
    TTS_PHONETIC.forEach(function (pair) {
      s = s.split(pair[0]).join(pair[1]);
    });
    return s;
  }
  /* Pecah teks jadi chunk per kalimat (maks 220 chr) + prosodi per jenis
     kalimat. Dipakai TTS teks & suara agar intonasi hidup. */
  function ttsChunkify(text, maxLen) {   var clean = String(text || '');
    if (maxLen && clean.length > maxLen) clean = clean.slice(0, maxLen).replace(/\s+\S*$/, '') + '.';
    var rawParts = clean.match(/[^.!?…]+[.!?…]+["”)]?|[^.!?…]+$/g) || [clean];
    var chunks = [];
    rawParts.forEach(function (p) {
      p = String(p || '').trim(); if (!p) return;
      var kind = /[?？]\s*["”)]?$/.test(p) ? 'question' : (/[!¡]\s*["”)]?$/.test(p) ? 'exclaim' : 'statement');
      var segs = [];
      var qre = /["“”']([^"“”']{2,})["“”']/g;
      var last = 0, qm;
      while ((qm = qre.exec(p)) !== null) {
        if (qm.index > last) segs.push({ t: p.slice(last, qm.index), kind: kind, emph: false });
        segs.push({ t: qm[1], kind: kind, emph: true });
        last = qm.index + qm[0].length;
      }
      if (last < p.length) segs.push({ t: p.slice(last), kind: kind, emph: false });
      if (!segs.length) segs.push({ t: p, kind: kind, emph: false });
      segs.forEach(function (sg) {
        var s = String(sg.t || '').trim(); if (!s) return;
        while (s.length > 450) {
          var cut = s.slice(0, 450);
          var idx = Math.max(cut.lastIndexOf(','), cut.lastIndexOf(';'), cut.lastIndexOf(':'), cut.lastIndexOf(' '));
          if (idx < 80) idx = 450;
          chunks.push({ t: s.slice(0, idx).trim(), kind: sg.kind, emph: sg.emph }); s = s.slice(idx).trim();
        }
        if (s) chunks.push({ t: s, kind: sg.kind, emph: sg.emph });
      });
    });
    // Gabungkan kalimat sejenis (maks 450 chr) agar request TTS lebih sedikit —
    // jeda antar titik menyusut (boundary antar-request lebih lambat daripada
    // prosodi alami Edge di dalam satu request). Potongan PERTAMA tetap satu
    // kalimat supaya audio mulai berbunyi cepat; sisanya menyusul via prefetch.
    var merged = [];
    chunks.forEach(function (c) {
      var prev = merged[merged.length - 1];
      if (prev && merged.length >= 2 && prev.kind === c.kind && prev.emph === c.emph && (prev.t.length + 1 + c.t.length) <= 450) prev.t += ' ' + c.t;
      else merged.push(c);
    });
    return merged;
  }
  function ttsSpeak(text) {
    if (!_ttsEnabled) return;
    try {
      var clean = String(text || '')
        .replace(/https?:\/\/\S+/g, ' ')
        .replace(/[*_`#>|~]/g, ' ')
        .replace(/\s+/g, ' ').trim();
      clean = ttsPhonetic(clean);
      if (!clean) return;
      var chunks = ttsChunkify(clean, 900);
      if (!chunks.length) return;
      var mySeq = ++_ttsSeq;
      // Prefetch 2 chunk pertama SEKARANG: sintesis TTS berjalan paralel
      // dengan reveal teks jawaban, bukan menunggu giliran playback.
      if (ttsServerAllowed()) {
        ttsPrefetchUrl(ttsChunkUrl(chunks[0].t));
        if (chunks[1]) ttsPrefetchUrl(ttsChunkUrl(chunks[1].t));
      }
      // Jalur server (Audio element) langsung main — tanpa jeda.
      // Jalur browser (speechSynthesis) tetap ditunda 80ms: cancel() yang
      // baru dipanggil kadang menelan utterance berikutnya (quirk Chrome).
      // Token sekuens membatalkan antrean basi bila pertanyaan baru /
      // toggle mati datang duluan.
      if (ttsServerAllowed()) { try { ttsApiSpeak(chunks, mySeq); } catch (e5) { /* noop */ } }
      else setTimeout(function () {
        try {
          if (mySeq !== _ttsSeq) return;
          ttsWebSpeak(chunks, mySeq);
        } catch (e4) { /* noop */ }
      }, 80);
    } catch (e) { /* noop */ }
  }
  function ttsStop() {
    _ttsSeq++;
    ttsDuckMusic(false);
    if (_ttsAudio) { try { _ttsAudio.onended = null; _ttsAudio.onerror = null; _ttsAudio.pause(); _ttsAudio.src = ''; } catch (e) {} }
    try { if ('speechSynthesis' in window) window.speechSynthesis.cancel(); } catch (e) {}
  }
  /* Ducking musik latar Pano2VR ("Music Background") selama TTS berbicara.
     Hanya pause bila musik memang sedang bunyi; restore hanya bila kita yang
     mem-pause, jadi preferensi mute user tidak pernah diubah. */
  function ttsDuckMusic(on) {
    try {
      var p = (typeof window.pano !== 'undefined' && window.pano) ? window.pano : ((window.skin && window.skin.player) ? window.skin.player : null);
      if (!p) return;
      if (on) {
        if (_ttsDuckedMusic) return;
        var playing = false;
        try { playing = (typeof p.isPlaying === 'function') ? !!p.isPlaying('Music Background') : false; } catch (e) { playing = false; }
        if (!playing) return;
        try { if (typeof p.pauseSound === 'function') { p.pauseSound('Music Background'); _ttsDuckedMusic = true; } } catch (e2) {}
      } else {
        if (!_ttsDuckedMusic) return;
        _ttsDuckedMusic = false;
        try { if (typeof p.playSound === 'function') p.playSound('Music Background', '1'); } catch (e3) {}
      }
    } catch (e) { /* noop */ }
  }
  /* Tunggu daftar voice terisi (Chrome memuatnya async; bicara saat daftar
     masih kosong = sunyi). Timeout 900ms agar tak menunggu selamanya. */
  function ttsEnsureVoices(cb) {
    try {
      var synth = window.speechSynthesis;
      var vs = null;
      try { vs = synth.getVoices() || []; } catch (e) { vs = []; }
      if ((vs && vs.length) || _ttsVoicesReady) { _ttsVoicesReady = true; cb(); return; }
      var done = false;
      var finish = function () {
        if (done) return; done = true; _ttsVoicesReady = true;
        try { synth.removeEventListener('voiceschanged', finish); } catch (e2) {}
        cb();
      };
      try {
        if (synth.addEventListener) synth.addEventListener('voiceschanged', finish);
        else synth.onvoiceschanged = finish;
      } catch (e3) {}
      setTimeout(finish, 900);
    } catch (e) { try { cb(); } catch (e2) {} }
  }
  function syncTtsToggle(btn) {
    if (!btn) return;
    btn.classList.toggle('tour-chat__tts-toggle--on', !!_ttsEnabled);
    btn.setAttribute('aria-pressed', _ttsEnabled ? 'true' : 'false');
    btn.setAttribute('title', _ttsEnabled ? 'Matikan suara jawaban' : 'Aktifkan suara jawaban');
    var lbl = btn.querySelector('.tour-chat__tts-label');
    if (lbl) lbl.textContent = _ttsEnabled ? 'Bacakan jawaban: Aktif' : 'Bacakan jawaban';
  }

  /* ================================================================
     RENDER: RIGHT PANEL — Contextual Answer / Insight card
     ================================================================ */
  function renderRightAnswer(presentation) {
    var d = _dom; if (!d) return;
    cancelProgressiveReveal();
    ttsStop();
    d.rightEyebrow.textContent = presentation.eyebrow || '';
    d.rightTitle.textContent = _lastQuestion || presentation.title || '';
    d.rightSubtitle.textContent = presentation.subtitle || '';
    d.rightBodyText.textContent = '';
    if (presentation.body) { startProgressiveReveal(presentation.body, d.rightBodyText); ttsSpeak(presentation.body); }
    // Web / grounding sources
    d.rightSources.innerHTML = '';
    if (presentation.sources && presentation.sources.length) {
      if (presentation.webSearch) d.rightSources.appendChild(el('div', 'tour-chat__right-sources-label', 'SUMBER WEB'));
      presentation.sources.forEach(function (s) {
        if (!s || !s.url) return;
        var a = el('a', 'tour-chat__right-sources-link', s.title || s.url);
        a.href = s.url; a.target = '_blank'; a.rel = 'noopener,noreferrer';
        d.rightSources.appendChild(a);
      });
    }
    // Actions
    d.rightActions.innerHTML = '';
    if (presentation.actions && presentation.actions.length) { presentation.actions.forEach(function (a) { var btn = el('button', 'tour-chat__right-action', a.label); btn.type = 'button'; btn.addEventListener('click', function () { if (a.action === 'play_video360') { playVideo360(a.video); } else if (a.action === 'focus') { var hs = data.hotspots.find(function (h) { return h.scene === currentSceneId() && normalize(h.title) === normalize(a.hotspot || ''); }); if (hs) focusHotspot(hs); } else if (a.action === 'navigate' && a.sceneId) { var sc = sceneById(a.sceneId); if (sc) navigate(sc); } else if (a.url) { window.open(a.url, '_blank', 'noopener,noreferrer'); } else if (a.query) submitAI(a.query); }); d.rightActions.appendChild(btn); }); }
    // Related prompts — folded into the unified suggestions box
    _dynamicPrompts = (presentation.relatedPrompts && presentation.relatedPrompts.length) ? presentation.relatedPrompts.slice() : [];
    renderSuggestions();
    // Recommendations — list with Google Maps links
    d.rightRecsList.innerHTML = '';
    if (presentation.recommendations && presentation.recommendations.length) {
      d.rightRecs.style.display = '';
      presentation.recommendations.forEach(function (r) {
        var item = el('div', 'tour-chat__right-rec');
        var txt = el('div', 'tour-chat__right-rec-text');
        var nm = el('div', 'tour-chat__right-rec-name', r.name);
        var meta = el('div', 'tour-chat__right-rec-meta');
        if (r.type) meta.appendChild(el('span', 'tour-chat__right-rec-type', r.type));
        if (r.note) meta.appendChild(el('span', 'tour-chat__right-rec-note', r.note));
        txt.appendChild(nm); txt.appendChild(meta);
        var link = el('a', 'tour-chat__right-rec-map', 'Buka Maps');
        var href = r.url || ((typeof r.lat === 'number' || typeof r.lng === 'number') ? ('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(String(r.lat) + ',' + String(r.lng))) : '');
        if (href) {
          link.href = href;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
        } else {
          link.removeAttribute('href');
          link.setAttribute('aria-disabled', 'true');
        }
        item.appendChild(txt); item.appendChild(link);
        d.rightRecsList.appendChild(item);
      });
    } else {
      d.rightRecs.style.display = 'none';
    }
    if (d.rightHistoryBtn) d.rightHistoryBtn.textContent = '\u2756 Riwayat Pertanyaan';
    d.rightStack.classList.add('tour-chat__right-stack--visible');
    // Mobile: ketika jawaban muncul / pertanyaan diklik, scroll sheet langsung ke jawaban (impeccable street-view)
    if (_dom && _dom.mobileSheet && window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
      var sheetAns = _dom.sheetSections && _dom.sheetSections.secAnswer;
      if (sheetAns) {
        if (sheetAns.classList.contains('tour-chat__sheet-section--collapsed')) {
          sheetAns.classList.remove('tour-chat__sheet-section--collapsed');
          var ah = sheetAns.querySelector('.tour-chat__sheet-section-header'); if (ah) ah.setAttribute('aria-expanded','true');
        }
        _dom.mobileSheet.classList.remove('tour-chat__mobile-sheet--expanded');
        setTimeout(function(){ try{ var b=_dom.sheetBody; if(b) b.scrollTo({top: sheetAns.offsetTop - 8, behavior:'smooth'}); else sheetAns.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e){ try{ _dom.sheetBody.scrollTop = sheetAns.offsetTop; }catch(e2){} } }, 140);
      }
    }
  }

  function renderRightThinking() {
    var d = _dom; if (!d) return;
    cancelProgressiveReveal();
    ttsStop();
    d.rightEyebrow.textContent = '';
    d.rightTitle.textContent = 'Memahami...';
    d.rightSubtitle.textContent = '';
    d.rightBodyText.innerHTML = '<div class="tour-chat__right-thinking"><span class="tour-chat__right-thinking-spark">&#x2728;</span><span class="tour-chat__right-thinking-text">Memahami pertanyaan Anda</span><div class="tour-chat__right-thinking-dots"><span></span><span></span><span></span></div></div>';
    d.rightActions.innerHTML = '';
    _dynamicPrompts = [];
    renderSuggestions();
  }

  function showArrival(name) { return; }

  /* ================================================================
     PRESENTATION ADAPTER
     ================================================================ */
  function destinationContext(destSceneId) {
    if (!destSceneId) return '';
    var sc = sceneById(destSceneId);
    if (!sc) return '';
    var si = getSceneIdentityEntry(destSceneId);
    var parts = [];
    // Deskripsi lengkap (untuk guide), bukan short description
    var desc = (si && si.description) || sc.description || '';
    if (desc) parts.push(desc);
    // highlight & "Bisa ditanyakan" sengaja tidak ditampilkan di panel kanan
    return parts.join('\n\n');
  }

  function resolvePresentation(result, query) {
    if (!result) return null;
    var intent = result.intent || '', reply = result.reply || '';
    if (intent === 'location' || intent === 'CURRENT_LOCATION' || intent === 'area_info') { var sc = result.scene ? sceneById(result.scene) : currentScene(); return { type: 'location', eyebrow: 'LOKASI', title: sceneDisplayName(sc), subtitle: tourName, body: reply, actions: [{ label: 'Rekomendasi berikutnya', query: 'Rekomendasi berikutnya' }], relatedPrompts: ['Apa yang menarik?', 'Ke mana berikutnya?'] }; }
    if (/^(navigate|navigation|recommend|recommendation|route_recommendation|go_to_scene|facility_recommendation)$/.test(intent) || intent === 'NAVIGATION' || intent === 'NAVIGATION_REQUEST' || intent === 'ROUTE_RECOMMENDATION') {
      var rc = currentScene();
      var exits = graphExitIds(rc.id);
      var destId = result.scene || (result.actions && result.actions[0] && result.actions[0].scene_id) || (result.navigationTarget && result.navigationTarget.sceneId) || (exits[0] ? exits[0].id : null);
      var destScene = destId ? sceneById(destId) : null;
      var ctx = destId ? destinationContext(destId) : '';
      var body = reply + (ctx ? '\n\n' + ctx : '');
      return { type: 'journey', eyebrow: 'JELAJAH BERIKUTNYA', title: destScene ? sceneDisplayName(destScene) : sceneDisplayName(exits[0] || rc), subtitle: 'Rekomendasi perjalanan', body: body, actions: exits.slice(0, 2).map(function (e, i) { return { label: sceneDisplayName(e), sceneId: e.id, action: 'navigate' }; }), relatedPrompts: ['Rute terbaik?', 'Apa yang menarik?'] };
    }
    if (intent === 'DESTINATION_DISCOVERY' || _lastDestinationResults.length > 0) { var dests = _lastDestinationResults.length > 0 ? _lastDestinationResults : (result.destination ? [result.destination] : []); if (dests.length) return { type: 'destination', eyebrow: 'DESTINASI ' + catalogLabel.toUpperCase(), title: dests[0].name, body: reply, actions: [], relatedPrompts: ['Destinasi lain?'] }; }
    if (intent === 'NEARBY_SEARCH') return { type: 'service', eyebrow: 'SEKITAR LAWANG SEWU', title: result.entities && result.entities[0] ? result.entities[0].name : 'Rekomendasi sekitar', body: reply, actions: result.actions || [], recommendations: result.recommendations || [], relatedPrompts: ['Hotel sekitar Lawang Sewu', 'Restoran dekat Lawang Sewu', 'Pusat oleh-oleh dekat Lawang Sewu'] };
    if (intent === 'public_service' || intent === 'PUBLIC_SERVICE') return { type: 'service', eyebrow: 'LAYANAN PUBLIK', title: result.entities && result.entities[0] ? result.entities[0].name : 'Layanan', body: reply, relatedPrompts: [] };
    if (intent === 'clarify' || result.needs_clarification) return { type: 'story', eyebrow: 'Klarifikasi', title: 'Maksud Anda?', body: reply, actions: (result.clarification_options || []).map(function (o) { return { label: o, query: o }; }), relatedPrompts: [] };
    if (intent === 'skin_action') return { type: 'notice', eyebrow: 'Aktivitas', title: 'Aktivitas', body: reply, relatedPrompts: [] };
    if (intent === 'video360') {
      var videoActions = [];
      if (result.video360 && result.video360.scene) videoActions.push({ label: 'Putar video 360', action: 'play_video360', video: result.video360 });
      return { type: 'story', eyebrow: 'VIDEO 360', title: 'Video 360', body: reply, actions: videoActions, relatedPrompts: ['Ke mana berikutnya?'] };
    }
    var eyebrow = 'WAWASAN';
    if (intent === 'NAME_ORIGIN' || intent === 'TOUR_HISTORY' || intent === 'TOUR_DESCRIPTION' || intent === 'TOUR_HIGHLIGHTS') eyebrow = 'SEJARAH';
    var actions = [];
    if (result.navigationTarget && result.navigationTarget.sceneId) actions.push({ label: 'Kunjungi area ini', sceneId: result.navigationTarget.sceneId, action: 'navigate' });
    var subjectName = result.entities && result.entities[0] ? result.entities[0].name : '';
    var hs = data.hotspots.find(function (h) { return h.scene === currentSceneId() && normalize(h.title) === normalize(subjectName); });
    if (hs) actions.push({ label: 'Sorot di panorama', hotspot: hs.title, action: 'focus' });
    var relatedPrompts = [];
    if (result.conversation_update && result.conversation_update.referenced_scene_id) { relatedPrompts.push('Tunjukkan objek'); relatedPrompts.push('Ke mana berikutnya?'); }
    else if (subjectName) { relatedPrompts.push('Apa hubungannya dengan ' + subjectName + '?'); relatedPrompts.push('Ke mana berikutnya?'); }
    var title = subjectName || questionSubject(query) || tourName;
    var storyActions = (result.actions && result.actions.length) ? result.actions : actions;
    return { type: 'story', eyebrow: eyebrow, title: title, body: reply, actions: storyActions, relatedPrompts: relatedPrompts };
  }

  /* Extract the subject label from the question to use as panel title. */
  function questionSubject(query) {
    var t = (query || '').trim().replace(/\s+/g, ' ');
    if (!t) return '';
    var trimmed = t.replace(/[?؟]+$/g, '').replace(/\.\s*$/g, '').trim();
    if (!trimmed) return t;
    // Use the full question as the subject so visitors can read it completely.
    return trimmed;
  }

  /* ================================================================
     SUBMIT — first question exits Ask Overlay into Exploration Mode
     ================================================================ */
  function submitAI(value) {
    var query = value.trim(); var now = Date.now();
    if (!query || (normalize(query) === normalize(lastSubmitted.text) && now - lastSubmitted.at < 900)) return;
    lastSubmitted = { text: query, at: now };
    _lastQuestion = query;
    _hasConversed = true;

    var fromDiscovery = _uiState === UIState.DISCOVERY;
    _conversationHistory.push({ role: 'user', text: query, scene: currentSceneId(), at: Date.now() });

    if (fromDiscovery) {
      if (_dom.discInput) { _dom.discInput.placeholder = 'Sedang memproses pertanyaan Anda\u2026'; _dom.discInput.disabled = true; }
      if (_dom.discAskbar) _dom.discAskbar.classList.add('tour-chat__disc-askbar--loading');
    } else {
      renderLeftPanel();
      renderRightThinking();
    }
    try { navCardHideFloat(); } catch (eNavFloat) {}

    // Hybrid curated answer: admin set "fix" answer for a suggested question → balas langsung tanpa AI.
    var curatedAns = getCuratedAnswer(query);
    // Curated Q&A database (team-editable data/curated-qa.json) → balas langsung tanpa AI.
    var qaItem = curatedAns ? null : matchCuratedQA(query);
    var local = null, deterministic = false;
    if (curatedAns) {
      local = { reply: curatedAns, intent: 'curated', confidence: 1, action: 'none', curated: true };
      deterministic = true;
    } else if (qaItem) {
      local = { reply: qaItem.answer, intent: 'curated_qa', confidence: 1, action: 'none', curated: true, curatedQaId: qaItem.id };
      deterministic = true;
    } else {
      try {
        var clarified = resolveClarificationChoice(query);
        local = clarified ? clarified : localRespond(query);
        deterministic = !!local && !!local.intent;
      } catch (e) { local = null; deterministic = false; }
    }

    var resolved = deterministic ? Promise.resolve(local) : remoteRespond(query).then(function (r) { if (!r || !r.reply || r.reply === 'Layanan AI tidak terjangkau.' || r.reply === 'Respons AI tidak valid.') return null; return r; });

    // Jawaban kurasi (fix answer) terasa templat bila muncul instan. Beri jeda
    // singkat seolah diproses AI — acak 1,2–1,9 dtk agar terasa alami, bukan kaku.
    if (deterministic && local && local.curated) {
      var thinkMs = 1200 + Math.floor(Math.random() * 700);
      resolved = Promise.all([resolved, new Promise(function (res) { setTimeout(res, thinkMs); })]).then(function (arr) { return arr[0]; });
    }

    // Safety: never leave the user stuck in the Ask overlay if the request hangs.
    var settled = false;
    var safety = setTimeout(function () {
      if (settled) return; settled = true;
      hideDiscStatus();
      if (fromDiscovery) setUIState(UIState.WORKSPACE);
      renderRightAnswer({ eyebrow: 'Kesalahan', title: 'Waktu Habis', body: 'Maaf, jawaban membutuhkan waktu terlalu lama. Coba lagi.', relatedPrompts: [] });
      remember('assistant', 'Timeout');
    }, 15000);

    resolved.then(function (result) {
      if (settled) return; settled = true; clearTimeout(safety);
      if (!result || !result.reply) result = { reply: 'Maaf, tidak dapat memproses pertanyaan ini.', intent: 'error', action: 'none' };
      if (result.needs_clarification && result.clarification_options && result.clarification_options.length) pendingClarification = { type: 'remote', options: result.clarification_options };
      result.reply = sanitizeReply(result.reply);

      var presentation = resolvePresentation(result, query);
      if (presentation) {
        presentation.recommendations = (result.intent === 'NEARBY_SEARCH' && result.recommendations && result.recommendations.length) ? result.recommendations : null;
        presentation.sources = result.sources || [];
        presentation.webSearch = !!result.web_search_used;
      }
      _conversationHistory.push({ role: 'assistant', text: result.reply, scene: currentSceneId(), intent: result.intent, query: query, presentation: presentation, at: Date.now() });

      hideDiscStatus();
      if (fromDiscovery) setUIState(UIState.WORKSPACE);
      _currentPresentation = presentation;
      renderRightAnswer(presentation);
      try {
        var _navScene = null;
        if (result.action === 'go_to_scene' && result.scene) _navScene = result.scene;
        else if (result.actions && result.actions.length) {
          for (var _ai = 0; _ai < result.actions.length; _ai++) {
            var _a = result.actions[_ai];
            if (_a && _a.type === 'go_to_scene' && (_a.scene_id || _a.scene)) { _navScene = _a.scene_id || _a.scene; break; }
          }
        }
        if (_navScene && sceneById(_navScene)) { var _nc = showNavCard(_navScene); result._navScheduled = true; scheduleAiNavigation(_navScene, _nc); }
      } catch (eNav) {}
      applyAction(result);
      updateConversationState(result, query);
      if (!fromDiscovery) renderLeftPanel();
      remember('user', query); remember('assistant', result.reply);
    }).catch(function () {
      if (settled) return; settled = true; clearTimeout(safety);
      hideDiscStatus();
      // Always land in State 2 so the user is never stuck in the Ask overlay.
      if (fromDiscovery) setUIState(UIState.WORKSPACE);
      renderRightAnswer({ eyebrow: 'Kesalahan', title: 'Koneksi Terputus', body: 'Maaf, terjadi gangguan koneksi.', relatedPrompts: [] });
      remember('assistant', 'Koneksi terputus');
    });
  }

  /* ================================================================
     MOUNT
     ================================================================ */
  function mount() {
    if (document.getElementById('tour-chat')) return;
    var root = el('aside', 'tour-chat tour-chat--not-ready');
    root.id = 'tour-chat'; root.setAttribute('aria-label', 'AI Guide ' + data.name); root.setAttribute('data-state', 'closed');
    root.setAttribute('role', 'region');

    // AI Button
    var aiBtn = el('button', 'tour-chat__ai-btn'); aiBtn.type = 'button'; aiBtn.setAttribute('aria-label', 'Buka AI Guide');
    aiBtn.innerHTML = '<span class="tour-chat__ai-btn-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 1L14.5 8.5L22 11L14.5 13.5L12 21L9.5 13.5L2 11L9.5 8.5L12 1Z" fill="currentColor"/></svg></span><span>AI Guide</span>';
    aiBtn.addEventListener('click', function () { setUIState(_hasConversed ? UIState.WORKSPACE : UIState.DISCOVERY); });

    // Veil
    var veil = el('div', 'tour-chat__veil');
    veil.addEventListener('click', function () { setUIState(UIState.CLOSED); });

    /* ---- DISCOVERY: fullscreen immersive ---- */
    var discovery = el('div', 'tour-chat__discovery');
    discovery.setAttribute('role', 'dialog'); discovery.setAttribute('aria-modal', 'true'); discovery.setAttribute('aria-label', 'Tanya AI Guide');

    // Top bar (close only)
    var discTopbar = el('div', 'tour-chat__disc-topbar');
    var discClose = el('button', 'tour-chat__disc-close'); discClose.type = 'button'; discClose.setAttribute('aria-label', 'Tutup AI Guide');
    discClose.appendChild(el('span', 'tour-chat__disc-close-icon', '\u2715'));
    discClose.appendChild(el('span', 'tour-chat__disc-close-label', 'Tutup'));
    discClose.addEventListener('click', function () { setUIState(UIState.CLOSED); });
    discTopbar.appendChild(discClose);

    // Center content
    var discCenter = el('div', 'tour-chat__disc-center');
    var discInner = el('div', 'tour-chat__disc-inner');

    // Branding
    var discBrand = el('div', 'tour-chat__disc-brand');
    discBrand.appendChild(el('div', 'tour-chat__disc-title', data.name || tourName));
    // Location context
    var discLoc = el('div', 'tour-chat__disc-location');
    var discLocIcon = el('span', 'tour-chat__disc-location-icon');
    discLocIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    discLoc.appendChild(discLocIcon);
    var discLocText = document.createTextNode('Anda berada di ');
    discLoc.appendChild(discLocText);
    var discLocName = el('span', 'tour-chat__disc-location-name');
    discLoc.appendChild(discLocName);
    discBrand.appendChild(discLoc);
    discInner.appendChild(discBrand);

    // Headline
    var discHeadline = el('div', 'tour-chat__disc-headline');
    var headlineText = el('div', 'tour-chat__disc-headline-text');
    headlineText.textContent = 'Apa yang ingin Anda ketahui?';
    discHeadline.appendChild(headlineText);
    discInner.appendChild(discHeadline);

    // Mode selector — Teks / Suara (demo). Panorama tetap terlihat.
    var discModes = el('div', 'tour-chat__disc-modes');
    discModes.appendChild(el('div', 'tour-chat__disc-modes-label', 'PILIH MODE AI GUIDE'));
    var discModesRow = el('div', 'tour-chat__disc-modes-row');
    var modeTextBtn = el('button', 'tour-chat__disc-mode tour-chat__disc-mode--active'); modeTextBtn.type = 'button'; modeTextBtn.setAttribute('aria-pressed', 'true');
    var ti2 = el('span', 'tour-chat__disc-mode-icon'); ti2.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>';
    modeTextBtn.appendChild(ti2);
    modeTextBtn.appendChild(el('span', 'tour-chat__disc-mode-text', 'Tanya lewat teks'));
    var modeVoiceBtn = el('button', 'tour-chat__disc-mode'); modeVoiceBtn.type = 'button'; modeVoiceBtn.setAttribute('aria-pressed', 'false');
    var vi2 = el('span', 'tour-chat__disc-mode-icon'); vi2.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="10" x2="4" y2="14"></line><line x1="8" y1="7" x2="8" y2="17"></line><line x1="12" y1="4" x2="12" y2="20"></line><line x1="16" y1="7" x2="16" y2="17"></line><line x1="20" y1="10" x2="20" y2="14"></line></svg>';
    modeVoiceBtn.appendChild(vi2);
    modeVoiceBtn.appendChild(el('span', 'tour-chat__disc-mode-text', 'Mulai ngobrol'));
    discModesRow.appendChild(modeTextBtn); discModesRow.appendChild(modeVoiceBtn);
    discModes.appendChild(discModesRow);
    // Mode diletakkan paling atas.
    discInner.insertBefore(discModes, discInner.firstChild);

    var voiceCaps = voiceCapabilities();
    if (!voiceCaps.supported && !voiceAudioCapable()) { modeVoiceBtn.disabled = true; modeVoiceBtn.title = 'Browser tidak mendukung pengenalan suara / ucapan'; modeVoiceBtn.classList.add('tour-chat__disc-mode--disabled'); }
    function setDiscMode(voice) {
      modeTextBtn.classList.toggle('tour-chat__disc-mode--active', !voice);
      modeVoiceBtn.classList.toggle('tour-chat__disc-mode--active', voice);
      modeTextBtn.setAttribute('aria-pressed', voice ? 'false' : 'true');
      modeVoiceBtn.setAttribute('aria-pressed', voice ? 'true' : 'false');
    }
    modeTextBtn.addEventListener('click', function () { setDiscMode(false); });
    modeVoiceBtn.addEventListener('click', function () {
      if (modeVoiceBtn.disabled) return;
      setDiscMode(true);
      // Masuk mode suara: tab Teks/Suara aktif di Suara, panel teks disembunyikan.
      voiceSetMode('voice');
      setUIState(UIState.WORKSPACE);
    });

    /* ---- Shared speech-to-text config (Web Speech API) ---- */
    var _supportsSpeech = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
    var SILENCE_DELAY = 2500;
    function _buildRecognition() {
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SR) return null;
      var r = new SR();
      r.lang = 'id-ID'; r.interimResults = true; r.continuous = true; r.maxAlternatives = 1;
      return r;
    }
    function _cleanTranscript(s) {
      if (!s) return '';
      return s.replace(/[\u00A0\u1680\u2000-\u200B\u202F\u205F\u3000\uFEFF\s]+/g, ' ').replace(/^\s+|\s+$/g, '');
    }

    /* ---- Real-time mic waveform (Web Audio AnalyserNode — real input, no fake anim) ---- */
    var _waveGen = 0, _wave = null;
    function _waveStop() {
      _waveGen++;
      if (_wave) {
        if (_wave.raf) cancelAnimationFrame(_wave.raf);
        if (_wave.stream) _wave.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
        if (_wave.ctx && _wave.ctx.state !== 'closed') { try { _wave.ctx.close(); } catch (e) {} }
        if (_wave.cv && _wave.cv.parentNode) _wave.cv.parentNode.removeChild(_wave.cv);
        _wave = null;
      }
    }
    function _waveStart(wrap) {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
      _waveStop();
      var gen = ++_waveGen;
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        if (gen !== _waveGen) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
        var ctx = new AC();
        var analyser = ctx.createAnalyser();
        analyser.fftSize = 1024;
        ctx.createMediaStreamSource(stream).connect(analyser);
        var cv = document.createElement('canvas');
        cv.className = 'tour-chat__wave';
        var c = cv.getContext('2d');
        _wave = { ctx: ctx, analyser: analyser, stream: stream, buf: new Uint8Array(analyser.fftSize), cv: cv, c: c, raf: 0 };
        wrap.appendChild(cv);
        function draw() {
          if (!_wave || _wave.cv !== cv) return;
          _wave.raf = requestAnimationFrame(draw);
          var w = Math.max(64, Math.floor(wrap.clientWidth || cv.clientWidth || 240));
          if (cv.width !== w) cv.width = w;
          cv.height = 36;
          analyser.getByteTimeDomainData(_wave.buf);
          c.clearRect(0, 0, w, 36);
          c.fillStyle = 'rgba(6,5,3,0.55)';
          roundRect(c, 0, 0, w, 36, 9); c.fill();
          var mid = 18, step = _wave.buf.length / w;
          c.strokeStyle = 'rgba(233,205,140,0.16)'; c.lineWidth = 1;
          c.beginPath(); c.moveTo(0, mid + 0.5); c.lineTo(w, mid + 0.5); c.stroke();
          c.strokeStyle = 'rgba(233,205,140,0.95)'; c.lineWidth = 1.8; c.lineJoin = 'round'; c.lineCap = 'round';
          c.beginPath();
          for (var x = 0; x < w; x++) {
            var v = (_wave.buf[Math.floor(x * step)] - 128) / 128;
            var y = mid + v * (mid - 2);
            if (x === 0) c.moveTo(x, y); else c.lineTo(x, y);
          }
          c.stroke();
        }
        draw();
      }).catch(function () { if (gen === _waveGen) _waveStop(); });
    }
    function roundRect(c, x, y, w, h, r) {
      c.beginPath();
      c.moveTo(x + r, y);
      c.arcTo(x + w, y, x + w, y + h, r);
      c.arcTo(x + w, y + h, x, y + h, r);
      c.arcTo(x, y + h, x, y, r);
      c.arcTo(x, y, x + w, y, r);
      c.closePath();
    }

    // Ask bar
    var discAsk = el('div', 'tour-chat__disc-ask');
    var discAskbar = el('div', 'tour-chat__disc-askbar');
    var discAskSpark = el('span', 'tour-chat__disc-ask-spark');
    discAskSpark.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 1L14.5 8.5L22 11L14.5 13.5L12 21L9.5 13.5L2 11L9.5 8.5L12 1Z" fill="currentColor"/></svg>';
    var discTextarea = el('div', 'tour-chat__disc-ask-textarea');
    var discInput = el('input', 'tour-chat__disc-ask-input');
    discInput.type = 'text'; discInput.placeholder = 'Tanyakan apa saja tentang ' + (data.name || tourName) + '\u2026';
    discInput.setAttribute('aria-label', 'Tanya pemandu virtual'); discInput.maxLength = 500;
    discTextarea.appendChild(discInput);
    var discMic = el('button', 'tour-chat__disc-ask-mic'); discMic.type = 'button'; discMic.setAttribute('aria-label', 'Rekam suara'); discMic.setAttribute('aria-pressed', 'false'); discMic.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3Z" fill="currentColor"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3M8 21h8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var discSend = el('button', 'tour-chat__disc-ask-send'); discSend.type = 'button'; discSend.setAttribute('aria-label', 'Kirim pertanyaan'); discSend.disabled = true;
    discSend.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    discInput.addEventListener('input', function () { if (!discRecognizing) discSend.disabled = !discInput.value.trim(); });
    function submitDiscovery(v) {
      if (!v) return;
      discSend.disabled = true;
      submitAI(v);
    }
    discInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); var v = discInput.value.trim(); if (v) { submitDiscovery(v); discInput.value = ''; discSend.disabled = true; } }
      if (e.key === 'Escape') setUIState(UIState.CLOSED);
    });
    discSend.addEventListener('click', function () {
      if (discRecognizing) { stopDiscSpeech(); return; }
      var v = discInput.value.trim(); if (v) { submitDiscovery(v); discInput.value = ''; discSend.disabled = true; }
    });

    /* ---- Speech-to-text for Discovery ask bar (mirrors question bar) ---- */
    var discRecognizing = false, discFinalTranscript = '', discSilenceTimer = null, discRecognition = null;
    var discDefaultPlaceholder = discInput.placeholder;
    if (!_supportsSpeech) { discMic.disabled = true; discMic.title = 'Browser tidak mendukung rekam suara'; discMic.style.opacity = '0.4'; discMic.style.cursor = 'default'; }
    function resetDiscSilenceTimer() {
      if (discSilenceTimer) clearTimeout(discSilenceTimer);
      discSilenceTimer = setTimeout(function () {
        var v = _cleanTranscript(discInput.value);
        stopDiscSpeech();
        if (v) { discInput.value = ''; submitDiscovery(v); }
      }, SILENCE_DELAY);
    }
    function setDiscRecordingUI(on) {
      discRecognizing = on;
      // Saat mic merekam: hentikan speech TTS + pause musik latar agar tidak
      // bocor ke mic; saat berhenti: kembalikan musik.
      if (on) { ttsStop(); ttsDuckMusic(true); } else { ttsDuckMusic(false); }
      discMic.setAttribute('aria-pressed', on ? 'true' : 'false');
      discMic.classList.toggle('tour-chat__disc-ask-mic--recording', on);
      if (on) _waveStart(discTextarea); else _waveStop();
      if (on) {
        discInput.placeholder = 'Silahkan bicara dan tanyakan apapun tentang ' + (data.name || tourName) + '\u2026';
        discSend.classList.add('tour-chat__disc-ask-send--stop');
        discSend.disabled = false;
        discSend.setAttribute('aria-label', 'Berhenti merekam');
        discSend.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><rect x="6" y="6" width="12" height="12" rx="2.5" fill="currentColor"/></svg>';
      } else {
        discInput.placeholder = discDefaultPlaceholder;
        discSend.classList.remove('tour-chat__disc-ask-send--stop');
        discSend.setAttribute('aria-label', 'Kirim pertanyaan');
        discSend.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        discSend.disabled = !discInput.value.trim();
      }
    }
    function startDiscSpeech() {
      if (!_supportsSpeech || discRecognizing) return;
      var rec = _buildRecognition(); if (!rec) return;
      discRecognition = rec;
      discFinalTranscript = _cleanTranscript(discInput.value) ? _cleanTranscript(discInput.value) + ' ' : '';
      rec.onresult = function (e) {
        var interim = '';
        for (var i = e.resultIndex; i < e.results.length; i++) {
          var t = _cleanTranscript(e.results[i][0].transcript);
          if (e.results[i].isFinal) discFinalTranscript += t + ' '; else interim += t + ' ';
        }
        discInput.value = _cleanTranscript(discFinalTranscript + ' ' + interim);
        discSend.disabled = false;
        resetDiscSilenceTimer();
      };
      rec.onerror = function () { try { rec.stop(); } catch (e) {} };
      rec.onend = function () { if (discRecognizing) { try { rec.start(); } catch (e) {} } else { setDiscRecordingUI(false); } };
      setDiscRecordingUI(true);
      resetDiscSilenceTimer();
      try { rec.start(); } catch (e) { setDiscRecordingUI(false); }
    }
    function stopDiscSpeech() {
      if (discSilenceTimer) { clearTimeout(discSilenceTimer); discSilenceTimer = null; }
      if (discRecognition) { try { discRecognition.stop(); } catch (e) {} }
      setDiscRecordingUI(false);
      discInput.value = _cleanTranscript(discInput.value);
      discSend.disabled = !discInput.value.trim();
      discInput.focus();
    }
    discMic.addEventListener('click', function () { if (discRecognizing) stopDiscSpeech(); else startDiscSpeech(); });
    gvaSrRegisterStopper(function () { if (discRecognizing) stopDiscSpeech(); });
    discAskbar.appendChild(discAskSpark);
    discAskbar.appendChild(discTextarea);
    discAskbar.appendChild(discMic);
    discAskbar.appendChild(discSend);
    discAsk.appendChild(discAskbar);
    discInner.appendChild(discAsk);

    // Explore themes — "TOPIK YANG BISA DIJELAJAHI" (per-tour via recommended-questions.json)
    var discThemes = el('div', 'tour-chat__disc-themes');
    discThemes.appendChild(el('div', 'tour-chat__disc-themes-label', 'JELAJAHI TOPIK'));
    var discThemesChips = el('div', 'tour-chat__disc-themes-chips');
    function renderThemes() {
      discThemesChips.innerHTML='';
      var recThemes = (_recQuestions && _recQuestions.state1 && _recQuestions.state1.themes && _recQuestions.state1.themes.length) ? _recQuestions.state1.themes : [
        { label: 'Sejarah', query: 'Sejarah ' + tourName },
        { label: 'Arsitektur', query: 'Arsitektur ' + tourName },
        { label: 'Highlight', query: 'Apa yang menarik dari ' + tourName + '?' },
        { label: 'Fasilitas', query: 'Fasilitas di sini' },
        { label: 'Rute', query: 'Ke mana sebaiknya saya lanjut?' },
        { label: 'Layanan', query: 'Layanan publik' }
      ];
      recThemes.slice(0, 4).forEach(function (t) {
        var chip = el('button', 'tour-chat__disc-theme');
        chip.type = 'button';
        chip.textContent = t.label;
        chip.addEventListener('click', function () { submitAI(t.query); });
        discThemesChips.appendChild(chip);
      });
    }
    renderThemes();
    // Refresh when per-tour file loads async
    loadRecommended().then(function(){ renderThemes(); });
    discThemes.appendChild(discThemesChips);

    // Smart starters (label "COBA TANYAKAN" dihapus)
    var discStarters = el('div', 'tour-chat__disc-starters');
    var discStartersList = el('div', 'tour-chat__disc-starters-list');
    discStarters.appendChild(discStartersList);
    discInner.appendChild(discStarters);
    discInner.appendChild(discThemes);

    // Status (loading/error)
    var discStatus = el('div', 'tour-chat__disc-status');
    var discStatusRetry = el('button', 'tour-chat__disc-status-retry', 'Coba lagi');
    discStatusRetry.type = 'button'; discStatusRetry.style.display = 'none';
    discStatus.appendChild(discStatusRetry);
    discInner.appendChild(discStatus);

    discCenter.appendChild(discInner);
    discovery.appendChild(discTopbar);
    discovery.appendChild(discCenter);

    // Discovery close bottom button
    var discoveryClose = el('button', 'tour-chat__discovery-close');
    discoveryClose.innerHTML = '&#x2715; Tutup';
    discoveryClose.addEventListener('click', function () { setUIState(UIState.CLOSED); });

    /* ---- LEFT PANEL — Exploration Context ---- */
    var left = el('div', 'tour-chat__left');
    var leftClose = el('button', 'tour-chat__left-close'); leftClose.type = 'button';
    (function syncLeftCloseLabel() {
      var isMobileNow = !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
      leftClose.setAttribute('aria-label', isMobileNow ? 'Sembunyikan panel kiri' : 'Tutup Mode AI Guide');
      var lbl = isMobileNow ? 'Sembunyikan' : 'Tutup Mode AI Guide';
      var existing = leftClose.querySelector('.tour-chat__left-close-label');
      if (existing) existing.textContent = lbl;
      else leftClose.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 5l-7 7 7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="tour-chat__left-close-label">' + lbl + '</span>';
    })();
    window.addEventListener('resize', function () {
      var isMob = !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
      var lab = leftClose.querySelector('.tour-chat__left-close-label');
      if (lab) { var t = isMob ? 'Sembunyikan' : 'Tutup Mode AI Guide'; if (lab.textContent !== t) { lab.textContent = t; leftClose.setAttribute('aria-label', isMob ? 'Sembunyikan panel kiri' : 'Tutup Mode AI Guide'); } }
    });
    leftClose.addEventListener('click', function () {
      var isMobNow = !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
      if (isMobNow) { toggleMobileContext(); return; } closeWorkspaceSmooth();
    });
    left.appendChild(leftClose);
    // Collapse tab desktop: sembunyikan panel kiri ke kiri (peek), klik lagi untuk tampil.
    var leftCollapse = el('button', 'tour-chat__panel-toggle tour-chat__panel-toggle--left'); leftCollapse.type = 'button';
    leftCollapse.setAttribute('aria-expanded', 'true'); leftCollapse.setAttribute('aria-label', 'Sembunyikan panel kiri');
    leftCollapse.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>';
    leftCollapse.addEventListener('click', function () {
      var collapsed = left.classList.toggle('tour-chat__left--collapsed');
      leftCollapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      leftCollapse.setAttribute('aria-label', collapsed ? 'Tampilkan panel kiri' : 'Sembunyikan panel kiri');
    });
    left.appendChild(leftCollapse);
    var leftHeader = el('div', 'tour-chat__left-header');
    leftHeader.appendChild(el('div', 'tour-chat__left-title', 'Anda sedang berada di :'));
    var leftContext = el('div', 'tour-chat__left-context');
    var leftCtxIcon = el('div', 'tour-chat__left-context-icon');
    leftCtxIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    leftContext.appendChild(leftCtxIcon);
    var leftContextName = el('span', 'tour-chat__left-context-name');
    leftContext.appendChild(leftContextName);
    leftHeader.appendChild(leftContext);
    var leftContextDesc = el('div', 'tour-chat__left-context-desc');
    leftHeader.appendChild(leftContextDesc);
    left.appendChild(leftHeader);

    var leftPano = el('div', 'tour-chat__left-pano'); leftPano.style.display = 'none';
    left.appendChild(leftPano);

    var leftBody = el('div', 'tour-chat__left-body');

    left.appendChild(leftBody);

    /* ---- OBJECTS PANEL — separate bottom-center strip ---- */
    var objectsPanel = el('div', 'tour-chat__objects-panel'); objectsPanel.style.display = 'none';
    var objectsHead = el('div', 'tour-chat__objects-head');
    objectsHead.appendChild(el('div', 'tour-chat__objects-label', 'OBJEK YANG BISA DILIHAT'));
    objectsPanel.appendChild(objectsHead);
    var objectsList = el('div', 'tour-chat__objects-list');
    objectsPanel.appendChild(objectsList);
    var objectsAll = el('button', 'tour-chat__objects-all', 'Lihat semua objek');
    objectsAll.type = 'button';
    objectsAll.addEventListener('click', function () { openObjectsSheet('obj'); });
    objectsPanel.appendChild(objectsAll);
    // Collapse tab desktop: sembunyikan panel objek ke bawah (peek), klik lagi untuk tampil.
    var objectsCollapse = el('button', 'tour-chat__panel-toggle tour-chat__panel-toggle--objects'); objectsCollapse.type = 'button';
    objectsCollapse.setAttribute('aria-expanded', 'true'); objectsCollapse.setAttribute('aria-label', 'Sembunyikan panel objek');
    objectsCollapse.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    objectsCollapse.addEventListener('click', function () {
      var collapsed = objectsPanel.classList.toggle('tour-chat__objects-panel--collapsed');
      objectsCollapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      objectsCollapse.setAttribute('aria-label', collapsed ? 'Tampilkan panel objek' : 'Sembunyikan panel objek');
    });
    objectsPanel.appendChild(objectsCollapse);

    var leftSugList = el('div', 'tour-chat__left-sug-list');
    var sugBox = el('div', 'tour-chat__left-suggestions');
    var sugLabelEl = el('div', 'tour-chat__left-sug-label', (_recQuestions && _recQuestions.state2 && _recQuestions.state2.workspaceHeader) || 'MUNGKIN ANDA INGIN TAHU');
    sugBox.appendChild(sugLabelEl);
    loadRecommended().then(function(){ if(_recQuestions && _recQuestions.state2 && _recQuestions.state2.workspaceHeader) sugLabelEl.textContent = _recQuestions.state2.workspaceHeader; });
    sugBox.appendChild(leftSugList);
    // Minimize: ciutkan box saran, panel jawaban melebar; klik lagi kembali semula.
    var sugMin = el('button', 'tour-chat__sug-min'); sugMin.type = 'button';
    sugMin.setAttribute('aria-expanded', 'true'); sugMin.setAttribute('aria-label', 'Perkecil kotak saran');
    sugMin.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
    sugMin.addEventListener('click', function () {
      var min = sugBox.classList.toggle('tour-chat__left-suggestions--min');
      if (rightStack) rightStack.classList.toggle('tour-chat__right-stack--sugmin', min);
      sugMin.setAttribute('aria-expanded', min ? 'false' : 'true');
      sugMin.setAttribute('aria-label', min ? 'Tampilkan kotak saran' : 'Perkecil kotak saran');
    });
    sugBox.appendChild(sugMin);

    left.appendChild(leftBody);

    /* ---- LEFT footer: object info catalog access (only when scene has no objects) ---- */
    var objectsAllCorner = el('button', 'tour-chat__objects-all tour-chat__objects-all--corner');
    objectsAllCorner.type = 'button';
    objectsAllCorner.addEventListener('click', function () { openObjectsSheet('pano'); });
    left.appendChild(objectsAllCorner);

    /* ---- RIGHT STACK: answer panel + follow-up question bar (aligned bottom) ---- */
    var rightStack = el('div', 'tour-chat__right-stack');
    var right = el('div', 'tour-chat__right');
    var rightBrand = el('div', 'tour-chat__right-brand');
    rightBrand.appendChild(el('span', 'tour-chat__right-brand-icon', '\u2726'));
    rightBrand.appendChild(el('span', 'tour-chat__right-brand-text', 'AI GUIDE'));
    var ttsToggle = el('button', 'tour-chat__tts-toggle'); ttsToggle.type = 'button';
    ttsToggle.setAttribute('aria-label', 'Suara jawaban'); ttsToggle.setAttribute('aria-pressed', 'false');
    ttsToggle.innerHTML = '<svg viewBox="0 0 24 24" fill="none"><path d="M4 9v6h4l5 5V4L7 9H4Z" fill="currentColor"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg><span class="tour-chat__tts-label">Bacakan jawaban</span>';
    ttsToggle.addEventListener('click', function () {
      _ttsEnabled = !_ttsEnabled;
      try { window.localStorage.setItem('gva-tts-enabled', _ttsEnabled ? '1' : '0'); } catch (e) {}
      syncTtsToggle(ttsToggle);
      if (!_ttsEnabled) { ttsStop(); return; }
      ttsWarmVoices();
      ttsSpeak('Suara jawaban aktif.');
    });
    rightBrand.appendChild(ttsToggle);
    right.appendChild(rightBrand);
    var rightHeader = el('div', 'tour-chat__right-header');
    var rightHeaderText = el('div', 'tour-chat__right-header-text');
    var rightEyebrow = el('div', 'tour-chat__right-eyebrow'); rightHeaderText.appendChild(rightEyebrow);
    var rightTitle = el('div', 'tour-chat__right-title'); rightHeaderText.appendChild(rightTitle);
    var rightSubtitle = el('div', 'tour-chat__right-subtitle'); rightHeaderText.appendChild(rightSubtitle);
    rightHeader.appendChild(rightHeaderText); right.appendChild(rightHeader);
    var rightBody = el('div', 'tour-chat__right-body');
    var rightBodyText = el('div', 'tour-chat__right-body-text'); rightBodyText.setAttribute('role', 'status'); rightBodyText.setAttribute('aria-live', 'polite'); rightBodyText.setAttribute('aria-atomic', 'false'); rightBody.appendChild(rightBodyText); right.appendChild(rightBody);
    var rightSources = el('div', 'tour-chat__right-sources'); rightBody.appendChild(rightSources);
    var rightActions = el('div', 'tour-chat__right-actions'); right.appendChild(rightActions);
    var rightRecs = el('div', 'tour-chat__right-recs');
    rightRecs.appendChild(el('div', 'tour-chat__right-recs-label', 'REKOMENDASI'));
    var rightRecsList = el('div', 'tour-chat__right-recs-list'); rightRecs.appendChild(rightRecsList); right.appendChild(rightRecs);
    var rightFooter = el('div', 'tour-chat__right-footer');
    var rightHistoryBtn = el('button', 'tour-chat__right-history', '\u2756 Riwayat Pertanyaan');
    rightHistoryBtn.type = 'button';
    rightHistoryBtn.addEventListener('click', function () { _historySource = 'text'; setUIState(UIState.EXPANDED); });
    rightFooter.appendChild(rightHistoryBtn);
    right.appendChild(rightFooter);
    rightStack.appendChild(right);

    /* ---- Location indicator ---- */
    var location = el('div', 'tour-chat__location');
    var locIcon = el('span', 'tour-chat__location-icon');
    locIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    location.appendChild(locIcon);
    var locationText = el('span', 'tour-chat__location-text');
    var locationName = el('span', 'tour-chat__location-name');
    locationText.appendChild(document.createTextNode('Anda sekarang berada di '));
    locationText.appendChild(locationName);
    location.appendChild(locationText);
    location.setAttribute('role', 'button');
    location.setAttribute('tabindex', '0');
    location.addEventListener('click', function () { toggleMobileContext(); });
    location.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleMobileContext(); } });

    /* ---- Arrival notification ---- */
    var arrival = el('div', 'tour-chat__arrival');
    arrival.appendChild(el('div', 'tour-chat__arrival-check', '\u2713'));
    var arrivalName = el('div', 'tour-chat__arrival-name');
    arrival.appendChild(arrivalName);
    arrival.appendChild(el('div', 'tour-chat__arrival-sub', 'Sekarang Anda berada di area ini.'));

    /* ---- PERSISTENT QUESTION BAR (Exploration Mode) ---- */
    var questionbar = el('div', 'tour-chat__questionbar');
    var qbRow = el('div', 'tour-chat__questionbar-row');
    var qbSpark = el('span', 'tour-chat__questionbar-spark');
    qbSpark.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 1L14.5 8.5L22 11L14.5 13.5L12 21L9.5 13.5L2 11L9.5 8.5L12 1Z" fill="currentColor"/></svg>';
    var qbText = el('div', 'tour-chat__questionbar-textarea');
    var qbInput = el('input', 'tour-chat__questionbar-input');
    qbInput.type = 'text'; qbInput.placeholder = 'Tanyakan apa saja yang ingin Anda ketahui tentang ' + (data.name || tourName) + '\u2026';
    qbInput.setAttribute('aria-label', 'Tanya pemandu virtual'); qbInput.maxLength = 500;
    qbText.appendChild(qbInput);
    var qbMic = el('button', 'tour-chat__questionbar-mic'); qbMic.type = 'button'; qbMic.setAttribute('aria-label', 'Rekam suara'); qbMic.setAttribute('aria-pressed', 'false'); qbMic.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 0 0-6 0v5a3 3 0 0 0 3 3Z" fill="currentColor"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3M8 21h8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var qbSend = el('button', 'tour-chat__questionbar-send'); qbSend.type = 'button'; qbSend.setAttribute('aria-label', 'Kirim pertanyaan'); qbSend.disabled = true;
    qbSend.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    qbInput.addEventListener('input', function () { if (!recognizing) qbSend.disabled = !qbInput.value.trim(); });
    qbInput.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); var v = qbInput.value.trim(); if (v) { submitAI(v); qbInput.value = ''; qbSend.disabled = true; } } });
    qbSend.addEventListener('click', function () {
      if (recognizing) { stopSpeech(); return; }
      var v = qbInput.value.trim(); if (v) { submitAI(v); qbInput.value = ''; qbSend.disabled = true; }
    });

    /* ---- Speech-to-text (Web Speech API) — question bar ---- */
    var recognition = null, recognizing = false, finalTranscript = '';
    var silenceTimer = null;
    var qbDefaultPlaceholder = qbInput.placeholder;
    /* Marquee placeholder: teks default yang panjang di-scroll perlahan agar
       terbaca penuh. Pause saat fokus/mengetik/merekam; state lain statis. */
    var qbMarqueeTimer = null;
    var qbMarqueeText = qbDefaultPlaceholder;
    var qbMarqueePos = 0;
    var qbMarqueeTick = 0;
    function stopQbMarquee(restore) {
      if (qbMarqueeTimer) { clearInterval(qbMarqueeTimer); qbMarqueeTimer = null; }
      if (restore && qbInput && qbMarqueeText) qbInput.placeholder = qbMarqueeText;
    }
    function startQbMarquee() {
      stopQbMarquee();
      if (!qbInput || !qbMarqueeText) return;
      qbMarqueePos = 0; qbMarqueeTick = 0;
      qbInput.placeholder = qbMarqueeText;
      qbMarqueeTimer = setInterval(function () {
        if (!qbInput || qbInput.value) return;
        try { if (document.activeElement === qbInput) return; } catch (e) {}
        if (recognizing) return;
        try { if (!qbInput.offsetParent) return; } catch (e2) {}
        qbMarqueeTick++;
        if (qbMarqueeTick < 7) { qbInput.placeholder = qbMarqueeText; return; }
        var t = qbMarqueeText;
        qbMarqueePos = (qbMarqueePos + 1) % (t.length + 4);
        if (qbMarqueePos < t.length) {
          qbInput.placeholder = t.slice(qbMarqueePos) + '  •  ' + t.slice(0, qbMarqueePos);
        } else {
          qbInput.placeholder = qbMarqueeText;
        }
      }, 200);
    }
    qbInput.addEventListener('focus', function () { stopQbMarquee(true); });
    qbInput.addEventListener('blur', function () { if (qbInput && !qbInput.value && !recognizing) startQbMarquee(); });
    startQbMarquee();
    function resetSilenceTimer() {
      if (silenceTimer) clearTimeout(silenceTimer);
      silenceTimer = setTimeout(function () {
        var v = _cleanTranscript(qbInput.value);
        stopSpeech();
        if (v) { qbInput.value = ''; submitAI(v); }
      }, SILENCE_DELAY);
    }
    if (!_supportsSpeech) { qbMic.disabled = true; qbMic.title = 'Browser tidak mendukung rekam suara'; qbMic.style.opacity = '0.4'; qbMic.style.cursor = 'default'; }
    function setRecordingUI(on) {
      recognizing = on;
      // Saat mic merekam: hentikan speech TTS + pause musik latar agar tidak
      // bocor ke mic; saat berhenti: kembalikan musik.
      if (on) { ttsStop(); ttsDuckMusic(true); } else { ttsDuckMusic(false); }
      qbMic.setAttribute('aria-pressed', on ? 'true' : 'false');
      qbMic.classList.toggle('tour-chat__questionbar-mic--recording', on);
      if (on) _waveStart(qbText); else _waveStop();
      if (on) {
        stopQbMarquee();
        qbInput.placeholder = 'Silahkan bicara dan tanyakan apapun tentang ' + (data.name || tourName) + '\u2026';
        qbSend.classList.add('tour-chat__questionbar-send--stop');
        qbSend.disabled = false;
        qbSend.setAttribute('aria-label', 'Berhenti merekam');
        qbSend.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="6" y="6" width="12" height="12" rx="2.5" fill="currentColor"/></svg>';
      } else {
        qbInput.placeholder = qbDefaultPlaceholder;
        startQbMarquee();
        qbSend.classList.remove('tour-chat__questionbar-send--stop');
        qbSend.setAttribute('aria-label', 'Kirim pertanyaan');
        qbSend.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        qbSend.disabled = !qbInput.value.trim();
      }
    }
    function startSpeech() {
      if (!_supportsSpeech || recognizing) return;
      recognition = _buildRecognition(); if (!recognition) return;
      finalTranscript = _cleanTranscript(qbInput.value) ? _cleanTranscript(qbInput.value) + ' ' : '';
      recognition.onresult = function (e) {
        var interim = '';
        for (var i = e.resultIndex; i < e.results.length; i++) {
          var t = _cleanTranscript(e.results[i][0].transcript);
          if (e.results[i].isFinal) finalTranscript += t + ' '; else interim += t + ' ';
        }
        qbInput.value = _cleanTranscript(finalTranscript + ' ' + interim);
        qbSend.disabled = false;
        resetSilenceTimer();
      };
      recognition.onerror = function () { try { recognition.stop(); } catch (e) {} };
      recognition.onend = function () { if (recognizing) { try { recognition.start(); } catch (e) {} } else { setRecordingUI(false); } };
      setRecordingUI(true);
      resetSilenceTimer();
      try { recognition.start(); } catch (e) { setRecordingUI(false); }
    }
    function stopSpeech() {
      if (silenceTimer) { clearTimeout(silenceTimer); silenceTimer = null; }
      if (recognition) { try { recognition.stop(); } catch (e) {} }
      setRecordingUI(false);
      qbInput.value = _cleanTranscript(qbInput.value);
      qbSend.disabled = !qbInput.value.trim();
      qbInput.focus();
    }
    qbMic.addEventListener('click', function () { if (recognizing) stopSpeech(); else startSpeech(); });
    gvaSrRegisterStopper(function () { if (recognizing) stopSpeech(); });
    qbRow.appendChild(qbSpark); qbRow.appendChild(qbText); qbRow.appendChild(qbMic); qbRow.appendChild(qbSend);
    questionbar.appendChild(qbRow);
    rightStack.appendChild(sugBox);
    rightStack.appendChild(questionbar);
    // Footer riwayat pindah ke bawah questionbar (teks kecil, tanpa background besar).
    rightStack.appendChild(rightFooter);

    /* ---- HISTORY drawer — Riwayat Pertanyaan (secondary) ---- */
    var history = el('div', 'tour-chat__history');
    var historyPanel = el('div', 'tour-chat__history-panel');
    var historyHeader = el('div', 'tour-chat__history-header');
    var historyTitle = el('div', 'tour-chat__history-title', 'RIWAYAT PERTANYAAN');
    historyHeader.appendChild(historyTitle);
    var historyCount = el('div', 'tour-chat__history-count'); historyCount.style.display = 'none';
    historyHeader.appendChild(historyCount);
    var historyClear = el('button', 'tour-chat__history-clear', 'Hapus'); historyClear.type = 'button';
    historyClear.setAttribute('aria-label', 'Hapus semua riwayat');
    historyClear.addEventListener('click', function () {
      if (_historySource === 'voice') {
        try { if (_voice.el && _voice.el.transcriptHistory) { _voice.el.transcriptHistory.length = 0; if (_voice.el.renderHistory) _voice.el.renderHistory(); } } catch (eC) {}
      } else {
        _conversationHistory.length = 0;
      }
      renderHistory();
    });
    var historyClose = el('button', 'tour-chat__history-close'); historyClose.type = 'button'; historyClose.innerHTML = '&#x2715;';
    historyClose.addEventListener('click', function () { setUIState(UIState.WORKSPACE); });
    historyHeader.appendChild(historyClose); historyPanel.appendChild(historyHeader);
    var historyBody = el('div', 'tour-chat__history-body'); historyPanel.appendChild(historyBody);
    history.appendChild(historyPanel);
    history.addEventListener('click', function (e) { if (e.target === history) setUIState(UIState.WORKSPACE); });

    /* ---- OBJECTS sheet — "Lihat semua objek" ---- */
    var contextSheet = el('div', 'tour-chat__context-sheet');
    var csPanel = el('div', 'tour-chat__context-sheet-panel');
    var csHeader = el('div', 'tour-chat__context-sheet-header');
    var csTitle = el('div', 'tour-chat__context-sheet-title', 'JELAJAHI');
    csHeader.appendChild(csTitle);
    var csClose = el('button', 'tour-chat__context-sheet-close'); csClose.type = 'button'; csClose.innerHTML = '&#x2715;';
    csClose.addEventListener('click', function () { closeObjectsSheet(); });
    csHeader.appendChild(csClose); csPanel.appendChild(csHeader);
    var csBody = el('div', 'tour-chat__context-sheet-body'); csPanel.appendChild(csBody);
    contextSheet.appendChild(csPanel);
    contextSheet.addEventListener('click', function (e) { if (e.target === contextSheet) closeObjectsSheet(); });

    /* ---- Mode tab Teks/Suara (kanan atas desktop, top bar mobile) ---- */
    // Build once; visibility diatur oleh syncVoiceModeUI().
    var modeTab = voiceBuildTab();
    modeTab.tab.style.display = 'none';

    /* ---- Assemble ---- */
    root.appendChild(veil);
    root.appendChild(modeTab.tab);
    root.appendChild(discovery); root.appendChild(discoveryClose);
    root.appendChild(left); root.appendChild(rightStack); root.appendChild(arrival); root.appendChild(history); root.appendChild(contextSheet); root.appendChild(objectsPanel); root.appendChild(aiBtn);
document.body.appendChild(root);

    // Mobile: tapping anywhere outside the left drawer hides it (the pill and
    // the in-drawer hide button stay usable). Keeps the panorama explorable.
    _dom = {
      root: root, aiBtn: aiBtn, veil: veil,
      modeTab: modeTab.tab, modeTabText: modeTab.textBtn, modeTabVoice: modeTab.voiceBtn,
      discovery: discovery, discTopbar: discTopbar, discInput: discInput, discSend: discSend, discTextarea: discTextarea, discAskbar: discAskbar,
      discLocName: discLocName, discStarters: discStartersList, discStatus: discStatus, discStatusRetry: discStatusRetry,
      discoveryClose: discoveryClose,
      left: left, leftHeader: leftHeader, leftContextName: leftContextName, leftContextDesc: leftContextDesc, leftPano: leftPano,
      objectsPanel: objectsPanel, objectsList: objectsList, objectsAll: objectsAll, objectsAllCorner: objectsAllCorner, leftSugList: leftSugList, sugBox: sugBox,
      right: right, rightEyebrow: rightEyebrow, rightTitle: rightTitle, rightSubtitle: rightSubtitle, ttsToggle: ttsToggle,
      rightBodyText: rightBodyText, rightSources: rightSources, rightActions: rightActions,
      rightRecs: rightRecs, rightRecsList: rightRecsList, rightHistoryBtn: rightHistoryBtn, rightStack: rightStack,
      questionbar: questionbar, qbInput: qbInput, qbSend: qbSend,
      location: location, locationName: locationName, arrival: arrival, arrivalName: arrivalName,
      history: history, historyBody: historyBody, historyCount: historyCount, historyTitle: historyTitle,
      contextSheet: contextSheet, csBody: csBody, csTitle: csTitle
    };
    syncTtsToggle(ttsToggle);
    ttsWarmVoices();
    if (!ttsSupported()) { ttsToggle.disabled = true; ttsToggle.title = 'Browser tidak mendukung suara'; ttsToggle.style.opacity = '0.4'; ttsToggle.style.cursor = 'default'; }

    // --- MOBILE STREET-VIEW SHEET (impeccable adapt) ---
    // Single bottom sheet that holds ALL State-2 panels on mobile.
    // Top 38-44% stays panorama; bottom sheet holds context/pano/objects/answer/ask.
    var mobileSheet = null, sheetBody = null, sheetExpandBtn = null, sheetCloseBtn = null;
    var _sheetSections = {}; // pano / objects / suggestions
    function isMobileSheetActive() { return !!(mobileSheet && window.matchMedia && window.matchMedia('(max-width: 768px)').matches); }
    function createMobileSheet() {
      if (mobileSheet) return;
      mobileSheet = el('div', 'tour-chat__mobile-sheet'); mobileSheet.setAttribute('aria-label', 'Panel AI Guide');
      var handle = el('div', 'tour-chat__sheet-handle'); handle.setAttribute('aria-hidden', 'true');
      var header = el('div', 'tour-chat__sheet-header');
      var headerMain = el('div', 'tour-chat__sheet-header-main');
      var hIcon = el('span', 'tour-chat__sheet-header-icon');
      hIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
      headerMain.appendChild(hIcon);
      var hTextWrap = el('div', 'tour-chat__sheet-header-text'); hTextWrap.style.cssText = 'min-width:0;flex:1;display:flex;flex-direction:column;gap:2px;';
      var hTitle = el('div', 'tour-chat__sheet-header-title', data.name || tourName);
      hTextWrap.appendChild(hTitle);
      headerMain.appendChild(hTextWrap);
      header.appendChild(headerMain);
      var headerActions = el('div', 'tour-chat__sheet-header-actions');
      sheetCloseBtn = el('button', 'tour-chat__sheet-close'); sheetCloseBtn.type = 'button'; sheetCloseBtn.setAttribute('aria-label', 'Tutup AI Guide'); sheetCloseBtn.innerHTML = '&#x2715;';
      sheetCloseBtn.addEventListener('click', function () { closeWorkspaceSmooth(); });
      headerActions.appendChild(sheetCloseBtn);
      header.appendChild(headerActions);
      sheetBody = el('div', 'tour-chat__sheet-body');
      // Accordion sections
      function makeSection(id, label, collapsed) {
        var sec = el('div', 'tour-chat__sheet-section' + (collapsed ? ' tour-chat__sheet-section--collapsed' : ''));
        sec.dataset.section = id;
        var head = el('button', 'tour-chat__sheet-section-header'); head.type = 'button'; head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        var lbl = el('span', 'tour-chat__sheet-section-label', label);
        var cnt = el('span', 'tour-chat__sheet-section-count', '');
        var chev = el('span', 'tour-chat__sheet-section-chevron', '›');
        head.appendChild(lbl); head.appendChild(cnt); head.appendChild(chev);
        var body = el('div', 'tour-chat__sheet-section-body');
        head.addEventListener('click', function () {
          var col = sec.classList.toggle('tour-chat__sheet-section--collapsed');
          head.setAttribute('aria-expanded', col ? 'false' : 'true');
        });
        sec.appendChild(head); sec.appendChild(body);
        _sheetSections[id] = { section: sec, header: head, body: body, countEl: cnt, chev: chev };
        return sec;
      }
      var secPano = makeSection('pano', 'Jelajahi dari sini', false);
      var secObjects = makeSection('objects', 'Objek terlihat', true);
      var secAnswer = makeSection('answer', 'Jawaban & Wawasan', false);
      var secAsk = el('div', 'tour-chat__sheet-section'); secAsk.dataset.section = 'ask';
      // Assemble body: context stays always visible on top (not accordion), then accordion sections
      var contextWrap = el('div', 'tour-chat__sheet-section'); contextWrap.style.cssText = 'padding:14px;border-bottom:1px solid var(--ai-border);';
      // context will be filled by renderLeftPanel -> we move left's header/pano into sections
      sheetBody.appendChild(contextWrap);
      sheetBody.appendChild(secPano);
      sheetBody.appendChild(secObjects);
      sheetBody.appendChild(secAnswer);
      sheetBody.appendChild(secAsk);
      // Store refs for later move
      _sheetSections.contextWrap = contextWrap;
      _sheetSections.secPano = secPano; _sheetSections.secObjects = secObjects; _sheetSections.secAnswer = secAnswer; _sheetSections.secAsk = secAsk;
      // Keep header title in sync with scene
      _sheetSections.hTitle = hTitle;
      mobileSheet.appendChild(handle); mobileSheet.appendChild(header); mobileSheet.appendChild(sheetBody);
      root.appendChild(mobileSheet);
      // expose to _dom
      _dom.mobileSheet = mobileSheet; _dom.sheetBody = sheetBody; _dom.sheetSections = _sheetSections;
      _dom.sheetCloseBtn = sheetCloseBtn;
      // Intercept old objectsPanel "Lihat semua" to open accordion instead of overlay on mobile
      var _origOpenObjectsSheet = openObjectsSheet;
      // Keep original for desktop
    }
    function updateSheetExpandIcon() {
      // No-op — mode expand/collapse dihapus (split statis 50/50)
    }
    function updatePanoramaSplit() {
      var cont = document.getElementById('container');
      if (!cont) return;
      var isMob = !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
      var isWs = _uiState === UIState.WORKSPACE && _dom && _dom.root && _dom.root.getAttribute('data-state') === 'open' && mobileSheet && mobileSheet.style.display !== 'none';
      if (!isMob || !isWs) {
        document.body.classList.remove('ai-guide-split','ai-guide-expanded');
        cont.style.height = '100%'; cont.style.transition = '';
        // Stop Pano2VR mobile menu transition before restoring full viewport.
        var sk = window.skin || (window.pano && window.pano.skinObj) || null;
        var menuEls = ['_bottom_menu_mobile', '_top_menu_mobile', '_shadow_bottom', '_bottom_menu', '_top_menu'];
        if (sk) for (var mi = 0; mi < menuEls.length; mi++) {
          var me = sk[menuEls[mi]];
          if (me && me.style) me.style.transition = 'none';
        }
        // pastikan viewer full
        setTimeout(function(){ try{ if(window.pano && window.pano.setViewerSize) window.pano.setViewerSize(cont.offsetWidth, cont.offsetHeight);}catch(e){} }, 80);
        return;
      }
      // Split statis 50/50: panorama 50vh, sheet 50vh — tanpa expand/collapse
      document.body.classList.add('ai-guide-split');
      document.body.classList.remove('ai-guide-expanded');
      // CSS handle height via body class; trigger pano resize
      setTimeout(function(){ try{ var c=document.getElementById('container'); if(c && window.pano && window.pano.setViewerSize) window.pano.setViewerSize(c.offsetWidth,c.offsetHeight);}catch(e){} }, 380);
    }
    function syncMobileSheetLayout() {
      var isMob = !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
      if (!isMob) {
        if (mobileSheet) mobileSheet.style.display = 'none';
        // Restore desktop positions: move panels back to root if they were moved
        try {
          // Restore leftHeader & leftPano back into left (they were split into sheet sections)
          var _activeWs = _uiState === UIState.WORKSPACE || _uiState === UIState.EXPANDED;
          if (leftHeader && left && leftHeader.parentNode !== left) {
            // leftPano should be after leftHeader
            left.appendChild(leftHeader);
            if (leftPano && leftPano.parentNode !== left) left.appendChild(leftPano);
            // leftBody if exists, ensure inside left
            if (typeof leftBody !== 'undefined' && leftBody && leftBody.parentNode !== left) left.appendChild(leftBody);
          }
          if (left) left.style.display = _activeWs ? '' : 'none';
          if (leftPano) leftPano.style.display = _activeWs ? '' : 'none';
          if (objectsPanel) {
            if (objectsPanel.parentNode !== root) root.appendChild(objectsPanel);
            // hanya tampilkan saat workspace aktif + ada objek; saat close/discovery tetap hidden
            objectsPanel.style.display = _activeWs ? (objectsList && objectsList.children.length ? '' : 'none') : 'none';
          }
          // Restore questionbar back into rightStack
          if (questionbar && rightStack && questionbar.parentNode !== rightStack) {
            // secAsk is inside sheet; move qb back
            rightStack.appendChild(questionbar);
          }
          if (rightStack && rightStack.parentNode !== root) root.appendChild(rightStack);
          if (rightStack) rightStack.style.display = _activeWs ? '' : 'none';
          if (questionbar) questionbar.style.display = _activeWs ? '' : 'none';
        } catch(e) {}
        return;
      }
      if (!mobileSheet) createMobileSheet();
      var _isWs = _uiState === UIState.WORKSPACE && _dom && _dom.root && _dom.root.getAttribute('data-state') === 'open' && !_voiceMode;
      mobileSheet.style.display = _isWs ? '' : 'none';
      if (!_isWs) { updatePanoramaSplit(); return; }
      // Move panels into sheet sections (idempotent)
      try {
        var secPanoBody = _sheetSections.secPano.querySelector('.tour-chat__sheet-section-body');
        var secObjectsBody = _sheetSections.secObjects.querySelector('.tour-chat__sheet-section-body');
        var secAnswerBody = _sheetSections.secAnswer.querySelector('.tour-chat__sheet-section-body');
        var secAskBody = _sheetSections.secAsk;
        var ctxWrap = _sheetSections.contextWrap;
        // Context: leftHeader inside contextWrap
        if (leftHeader && ctxWrap && leftHeader.parentNode !== ctxWrap) ctxWrap.appendChild(leftHeader);
        if (leftPano && secPanoBody && leftPano.parentNode !== secPanoBody) secPanoBody.appendChild(leftPano);
        if (left) left.style.display = 'none';
        if (objectsPanel && secObjectsBody && objectsPanel.parentNode !== secObjectsBody) secObjectsBody.appendChild(objectsPanel);
        if (rightStack && secAnswerBody && rightStack.parentNode !== secAnswerBody) {
          var qb = questionbar;
          if (qb && secAskBody && qb.parentNode !== secAskBody) {
            if (qb.parentNode === rightStack) rightStack.removeChild(qb);
            secAskBody.appendChild(qb);
          }
          secAnswerBody.appendChild(rightStack);
        }
        // Update counts (use stored countEl to avoid querySelector on wrapper object)
        var panoCount = (leftPano && leftPano.querySelectorAll('.tour-chat__left-pano-card').length) || 0;
        if (_sheetSections.pano && _sheetSections.pano.countEl) _sheetSections.pano.countEl.textContent = panoCount ? panoCount + ' rute' : '';
        var objCount = (objectsList && objectsList.children.length) || 0;
        if (_sheetSections.objects && _sheetSections.objects.countEl) {
          _sheetSections.objects.countEl.textContent = objCount ? objCount + ' objek' : '';
        }
        // Sembunyikan section Objek terlihat jika tidak ada objek di panorama ini (mobile only)
        if (_sheetSections.secObjects) {
          var isMobHide = !!(window.matchMedia && window.matchMedia('(max-width: 768px)').matches);
          if (isMobHide) _sheetSections.secObjects.style.display = objCount ? '' : 'none';
          else _sheetSections.secObjects.style.display = '';
        }
        // Sync header location
        var sc = currentScene(); if (sc && _sheetSections.hTitle) { _sheetSections.hTitle.textContent = sceneDisplayName(sc); _sheetSections.hSub.textContent = 'AI Guide • ' + (sc.description ? sc.description.slice(0,48) + '…' : 'ketuk untuk detail'); }
      } catch(e) {}
      updatePanoramaSplit();
    }
    // Initialize sheet on load and on resize
    syncMobileSheetLayout();
    window.addEventListener('resize', syncMobileSheetLayout);
    // Hook renderLeftPanel to keep sheet in sync
    var _origRenderLeftPanel = renderLeftPanel;
    renderLeftPanel = function () { _origRenderLeftPanel.apply(this, arguments); syncMobileSheetLayout(); };
    // Hook setUIState to show/hide sheet + split
    var _origSetUIState = setUIState;
    setUIState = function(s){ _origSetUIState(s); setTimeout(function(){ syncMobileSheetLayout(); updatePanoramaSplit(); }, 60); };
    // Will be wrapped after definition; do it via monkey patch on window setUIState if needed
    // Instead, add observer for data-state changes
    var _sheetObserver = new MutationObserver(function () {
      var st = root.getAttribute('data-state');
      if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
        if (st === 'open' && _uiState === UIState.WORKSPACE) { if (mobileSheet) { mobileSheet.style.display = ''; syncMobileSheetLayout(); } }
        else if (mobileSheet) { /* keep hidden when not workspace, but allow discovery to stay */ if (st !== 'open' || _uiState !== UIState.WORKSPACE) { /* hide sheet when discovery/closed */ if (st === 'closed' || _uiState === UIState.DISCOVERY) mobileSheet.style.display = 'none'; } }
      }
      updatePanoramaSplit();
    });
    try { _sheetObserver.observe(root, { attributes: true, attributeFilter: ['data-state'] }); } catch(e) {}
    window.addEventListener('resize', function(){ syncMobileSheetLayout(); updatePanoramaSplit(); });

    // Status retry

    // Escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (_uiState === UIState.EXPANDED) setUIState(UIState.WORKSPACE);
        else if (_uiState === UIState.WORKSPACE) closeWorkspaceSmooth();
        else if (_uiState === UIState.DISCOVERY) setUIState(UIState.CLOSED);
      }
    });

    // Scene change listener — visibility-aware + pano event hook
    var lastScene = currentSceneId();
    function handleSceneChanged(ns) {
      if (ns === lastScene) return;
      lastScene = ns;
      _sceneRevision++;
      try { voiceUpdateLoc(); } catch (e0) {}
      try { navCardOnArrival(ns); } catch (eNav) {}
      navResolveArrival(ns);
      if (_voice.pendingNavigationSpeech && _voice.pendingNavigationSpeech.sceneId === ns) {
        var arrival = _voice.pendingNavigationSpeech;
        _voice.pendingNavigationSpeech = null;
        setTimeout(function () {
          // Pengguna dapat menyela lalu berpindah lagi sebelum timer berjalan.
          // Jangan bacakan deskripsi tujuan lama pada panorama baru.
          if (!_voice.endRequested && currentSceneId() === arrival.sceneId) voiceSpeak(arrival.text);
        }, 250);
      }
      refreshLocation();
      if (_uiState === UIState.DISCOVERY) renderDiscovery();
      if (_uiState === UIState.WORKSPACE) { renderLeftPanel(); var sc = currentScene(); if (sc) showArrival(sceneDisplayName(sc)); }
      if (_pendingVideoPlay && ns === _pendingVideoPlay.scene) {
        var pv = _pendingVideoPlay; _pendingVideoPlay = null;
        setTimeout(function () { aimAndPlayVideo360(pv); }, 600);
      }
      if (_pendingObjectFocus && ns === _pendingObjectFocus.sceneId) {
        var pf = _pendingObjectFocus; _pendingObjectFocus = null;
        setTimeout(function () {
          if (pf.infoId) { objectPanTo(pf.sceneId, pf.oiIndex || 0); openInfoFrame(pf.label, pf.infoId, pf.oiIndex || 0); }
          else if (pf.label) { focusObject(pf.label); }
        }, 450);
      }
    }
    var _scenePollTimer = null;
    function startScenePolling() {
      if (_scenePollTimer) return;
      _scenePollTimer = setInterval(function () {
        if (document.hidden) return;
        handleSceneChanged(currentSceneId());
      }, 1800);
    }
    function stopScenePolling() { if (_scenePollTimer) { clearInterval(_scenePollTimer); _scenePollTimer = null; } }
    document.addEventListener('visibilitychange', function () { if (document.hidden) stopScenePolling(); else startScenePolling(); });
    // Hook pano events if available (instant, no polling lag)
    if (window.pano && typeof window.pano.on === 'function') { try {
      var _sceneWatchTimer = null;
      // Saat navigasi dimulai, tunggu getCurrentNode() benar-benar berganti
      // (cek tiap 80ms, maks ~2s) lalu update panel — tanpa jeda fixed yang
      // bisa membaca scene basi dan tanpa menunggu polling 1.8s.
      window.pano.on('openNext', function () {
        if (_sceneWatchTimer) { clearInterval(_sceneWatchTimer); _sceneWatchTimer = null; }
        var _from = null;
        try { _from = currentSceneId(); } catch (e) {}
        var _waited = 0;
        _sceneWatchTimer = setInterval(function () {
          _waited += 80;
          var cur = null;
          try { cur = currentSceneId(); } catch (e2) {}
          if (cur && cur !== _from) {
            clearInterval(_sceneWatchTimer); _sceneWatchTimer = null;
            try { handleSceneChanged(cur); } catch (e3) {}
          } else if (_waited >= 2000) {
            clearInterval(_sceneWatchTimer); _sceneWatchTimer = null;
            try { handleSceneChanged(currentSceneId()); } catch (e4) {}
          }
        }, 80);
      });
    } catch(e){} }
    startScenePolling();

    // Fullscreen handler
    function onFS() { var fs = document.fullscreenElement || document.webkitFullscreenElement; if (fs) { if (fs !== root && !fs.contains(root)) fs.appendChild(root); } else { if (root.parentNode !== document.body) document.body.appendChild(root); } }
    document.addEventListener('fullscreenchange', onFS); document.addEventListener('webkitfullscreenchange', onFS);

    refreshLocation();
  }

  function historyMeta(msg) {
    var parts = [];
    if (msg.scene) { try { var sc = sceneById(msg.scene); if (sc) parts.push(sceneDisplayName(sc)); } catch (e) {} }
    if (msg.at) { try { parts.push(new Date(msg.at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })); } catch (e2) {} }
    return parts.join(' • ');
  }

  function updateHistoryCount() {
    var d = _dom; if (!d || !d.historyCount) return;
    var n = 0;
    historyMessages().forEach(function (m) { if (m.role === 'user') n++; });
    d.historyCount.textContent = n ? (n + ' percakapan') : '';
    d.historyCount.style.display = n ? '' : 'none';
  }

  function renderHistory() {
    var d = _dom; if (!d) return;
    if (d.historyTitle) d.historyTitle.textContent = (_historySource === 'voice') ? 'RIWAYAT PERCAKAPAN SUARA' : 'RIWAYAT PERTANYAAN';
    d.historyBody.innerHTML = '';
    var turns = [];
    var cur = null;
    historyMessages().forEach(function (msg) {
      if (msg.role === 'user') { cur = { q: msg, a: null }; turns.push(cur); }
      else if (cur && !cur.a) { cur.a = msg; }
      else { cur = { q: null, a: msg }; turns.push(cur); }
    });
    var list = el('div', 'tour-chat__history-list');
    if (!turns.length) {
      var empty = el('div', 'tour-chat__history-empty');
      empty.appendChild(el('div', 'tour-chat__history-empty-icon', '\u2726'));
      empty.appendChild(el('div', 'tour-chat__history-empty-text', 'Belum ada riwayat percakapan.'));
      empty.appendChild(el('div', 'tour-chat__history-empty-sub', 'Tanyakan sesuatu pada AI Guide, semuanya tersimpan rapi di sini.'));
      list.appendChild(empty);
    }
    turns.forEach(function (t) {
      var turn = el('div', 'tour-chat__history-turn');
      if (t.q) {
        var qb = el('button', 'tour-chat__history-q'); qb.type = 'button';
        qb.setAttribute('aria-label', 'Buka kembali jawaban');
        qb.appendChild(el('div', 'tour-chat__history-q-text', t.q.text));
        var qm = historyMeta(t.q);
        if (qm) qb.appendChild(el('div', 'tour-chat__history-meta', qm));
        if (_historySource === 'text') { (function (m) { qb.addEventListener('click', function () { reopenHistoryItem(m); }); })(t.q); }
        else { qb.style.cursor = 'default'; }
        turn.appendChild(qb);
      }
      if (t.a) {
        var row = el('div', 'tour-chat__history-a');
        row.appendChild(el('div', 'tour-chat__history-avatar', '\u2726'));
        var abody = el('div', 'tour-chat__history-a-body');
        var atext = el('div', 'tour-chat__history-a-text', t.a.text);
        abody.appendChild(atext);
        var more = el('button', 'tour-chat__history-more', 'Selengkapnya'); more.type = 'button'; more.style.display = 'none';
        more.addEventListener('click', function () {
          var ex = atext.classList.toggle('tour-chat__history-a-text--expanded');
          more.textContent = ex ? 'Tutup' : 'Selengkapnya';
        });
        abody.appendChild(more);
        var am = historyMeta(t.a);
        if (am) abody.appendChild(el('div', 'tour-chat__history-meta', am));
        row.appendChild(abody);
        turn.appendChild(row);
      }
      list.appendChild(turn);
    });
    d.historyBody.appendChild(list);
    try {
      var texts = list.querySelectorAll('.tour-chat__history-a-text');
      for (var i = 0; i < texts.length; i++) {
        var tx = texts[i];
        if (tx.textContent.length > 200 || tx.scrollHeight > tx.clientHeight + 6) {
          var btn = tx.parentNode.querySelector('.tour-chat__history-more');
          if (btn) btn.style.display = '';
        }
      }
    } catch (e) {}
    d.historyBody.scrollTop = d.historyBody.scrollHeight;
    updateHistoryCount();
  }

  function reopenHistoryItem(msg) {
    var idx = _conversationHistory.indexOf(msg);
    if (idx < 0) return;
    var next = _conversationHistory[idx + 1];
    if (next && next.role === 'assistant' && next.presentation) {
      setUIState(UIState.WORKSPACE);
      _currentPresentation = next.presentation;
      renderRightAnswer(next.presentation);
    }
  }

  function renderObjTab(container) {
    var list = el('div', 'tour-chat__context-sheet-list');
    var seen = {}, all = [];
    var known = {};
    if (_knowledgeData && _knowledgeData.entries) { _knowledgeData.entries.forEach(function (e) { known[e.id] = e; }); }
    // All objinfo2 objects from knowledge
    Object.keys(known).forEach(function (rid) {
      var m = /objinfo2:(\d+)/.exec(rid);
      if (!m) return;
      var e = known[rid];
      var label = e && e.title ? e.title : '';
      if (!label) return;
      var key = normalize(label); if (seen[key]) return; seen[key] = true;
      all.push({ label: label, kind: 'info', infoId: m[1] });
    });
    // Explore entities as info
    if (_exploreMenuData && _exploreMenuData.entries) {
      var infoTitles = [];
      all.forEach(function (it) {
        if (it.kind !== 'info') return;
        var ke = known['webview:objinfo2:' + it.infoId];
        infoTitles.push(it.label);
        if (ke && ke.aliases) infoTitles = infoTitles.concat(ke.aliases);
      });
      infoTitles = infoTitles.map(normalize).filter(function (t) { return t && t.length >= 3; });
      _exploreMenuData.entries.forEach(function (e) {
        var key = normalize(e.title); if (seen[key]) return;
        var entAliases = [e.title].concat(e.aliases || []).map(normalize).filter(function (a) { return a && a.length >= 3; });
        var dup = entAliases.some(function (a) {
          return infoTitles.some(function (b) {
            if (a === b || a.indexOf(b) !== -1 || b.indexOf(a) !== -1) return true;
            var ta = a.split(/\s+/), tb = b.split(/\s+/);
            for (var x = 0; x < ta.length; x++) for (var y = 0; y < tb.length; y++) if (ta[x].length >= 4 && ta[x] === tb[y]) return true;
            return false;
          });
        });
        if (dup) return;
        seen[key] = true;
        all.push({ label: e.title, kind: 'entity', entity: e });
      });
    }
    all.forEach(function (o) {
      var card = el('button', 'tour-chat__obj-card'); card.type = 'button';
      // Media (object cover image, with graceful fallbacks)
      var media = el('div', 'tour-chat__obj-card-media');
      var img = el('img', 'tour-chat__obj-card-img'); img.alt = o.label; img.loading = 'lazy';
      media.appendChild(img);
      card.appendChild(media);
      // Body: title + small "Ada di: <panorama>"
      var body = el('div', 'tour-chat__obj-card-body');
      body.appendChild(el('div', 'tour-chat__obj-card-name', o.label));
      var sceneName = '';
      if (o.kind === 'info') {
        var rs = resolveObjectScene(o.infoId);
        if (rs) { var sc = sceneById(rs.sceneId); if (sc) sceneName = sceneDisplayName(sc); }
        setObjCardImage(img, o.infoId);
      } else if (o.kind === 'entity' && o.entity && o.entity.sceneIds && o.entity.sceneIds.length) {
        var es0 = sceneById(o.entity.sceneIds[0]);
        if (es0) {
          sceneName = sceneDisplayName(es0);
          var t = panoThumbnailUrl(es0.id, 'Point01');
          if (t) { img.src = t; img.addEventListener('error', function () { img.classList.add('tour-chat__obj-card-img--ph'); }); }
          else img.classList.add('tour-chat__obj-card-img--ph');
        }
      }
      if (sceneName) {
        var meta = el('div', 'tour-chat__obj-card-scene');
        meta.appendChild(el('span', 'tour-chat__obj-card-scene-ico', '🏛'));
        meta.appendChild(el('span', null, 'Ada di: ' + sceneName));
        body.appendChild(meta);
      }
      card.appendChild(body);
      card.addEventListener('click', function () {
        closeObjectsSheet();
        if (o.kind === 'info') {
          var rs2 = resolveObjectScene(o.infoId);
          var targetScene = rs2 ? sceneById(rs2.sceneId) : null;
          if (targetScene && targetScene.id !== currentSceneId()) {
            // Take the visitor to the panorama that contains this object.
            // Open the node DIRECTLY at the object's view (pan/tilt/fov) so the
            // panorama never flashes its default view before re-aiming.
            var _oi = rs2 ? (rs2.oiIndex || 0) : 0;
            _pendingObjectFocus = { sceneId: targetScene.id, label: o.label, infoId: o.infoId, oiIndex: _oi };
            loadOI().then(function (map) {
              var arr = (map && map[targetScene.id]) || [];
              var p = arr[_oi] || arr[0] || null;
              if (p && window.pano && typeof window.pano.openNext === 'function') {
                window.pano.openNext('{' + targetScene.id + '}', p.pan + '/' + p.tilt + '/' + OBJECT_VIEW_FOV);
              } else {
                navigate(targetScene);
              }
              try { history.replaceState(null, '', '#' + targetScene.id); } catch (e) {}
            });
          } else {
            // Already in the right panorama — just aim the camera at the object.
            objectPanTo(currentSceneId(), rs2 ? rs2.oiIndex : 0);
            openInfoFrame(o.label, o.infoId, rs2 ? rs2.oiIndex : 0);
          }
        } else if (o.kind === 'entity') {
          var es = (o.entity && o.entity.sceneIds && o.entity.sceneIds.length) ? sceneById(o.entity.sceneIds[0]) : null;
          if (es && es.id !== currentSceneId()) navigate(es);
          submitAI('Apa itu ' + o.label + '?');
        }
      });
      list.appendChild(card);
    });
    container.appendChild(list);
  }

  // Tab panorama: semua panorama di lokasi ini (nama dari data AI Studio).
  function renderPanoTab(container) {
    var list = el('div', 'tour-chat__context-sheet-list');
    var scenes = (data && data.scenes) || [];
    scenes.forEach(function (sc) {
      if (!sc || !sc.id) return;
      var name = sceneDisplayName(sc);
      var card = el('button', 'tour-chat__obj-card'); card.type = 'button';
      var media = el('div', 'tour-chat__obj-card-media');
      var img = el('img', 'tour-chat__obj-card-img'); img.alt = name; img.loading = 'lazy';
      var t = navCardThumbFor(sc.id);
      if (t) { img.src = t; img.addEventListener('error', function () { img.classList.add('tour-chat__obj-card-img--ph'); }); }
      else img.classList.add('tour-chat__obj-card-img--ph');
      media.appendChild(img);
      card.appendChild(media);
      var body = el('div', 'tour-chat__obj-card-body');
      body.appendChild(el('div', 'tour-chat__obj-card-name', name));
      if (sc.id === currentSceneId()) {
        var here = el('div', 'tour-chat__obj-card-scene');
        here.appendChild(el('span', 'tour-chat__obj-card-scene-ico', '\uD83D\uDCCD'));
        here.appendChild(el('span', null, 'Sedang di sini'));
        body.appendChild(here);
      }
      card.appendChild(body);
      card.addEventListener('click', function () {
        var target = sceneById(sc.id);
        closeObjectsSheet();
        if (target && target.id !== currentSceneId()) navigate(target);
      });
      list.appendChild(card);
    });
    container.appendChild(list);
  }

  function openObjectsSheet(defaultTab) {
    var d = _dom; if (!d) return;
    d.csBody.innerHTML = '';

    var tabs = el('div', 'tour-chat__context-sheet-tabs');
    var tabPano = el('button', 'tour-chat__context-sheet-tab'); tabPano.type = 'button'; tabPano.setAttribute('role', 'tab'); tabPano.textContent = 'Lihat Seluruh Panorama';
    var tabObj = el('button', 'tour-chat__context-sheet-tab'); tabObj.type = 'button'; tabObj.setAttribute('role', 'tab'); tabObj.textContent = 'Lihat Seluruh Objek Informasi';
    tabs.appendChild(tabPano); tabs.appendChild(tabObj);
    d.csBody.appendChild(tabs);

    var pane = el('div', 'tour-chat__context-sheet-pane');
    d.csBody.appendChild(pane);

    function activate(which) {
      var isPano = which !== 'obj';
      tabPano.classList.toggle('tour-chat__context-sheet-tab--active', isPano);
      tabObj.classList.toggle('tour-chat__context-sheet-tab--active', !isPano);
      tabPano.setAttribute('aria-selected', isPano ? 'true' : 'false');
      tabObj.setAttribute('aria-selected', isPano ? 'false' : 'true');
      if (d.csTitle) d.csTitle.textContent = isPano ? 'SELURUH PANORAMA' : 'OBJEK INFORMASI';
      pane.innerHTML = '';
      if (isPano) renderPanoTab(pane); else renderObjTab(pane);
    }
    tabPano.addEventListener('click', function () { activate('pano'); });
    tabObj.addEventListener('click', function () { activate('obj'); });

    d.contextSheet.classList.add('tour-chat__context-sheet--visible');
    activate(defaultTab === 'obj' ? 'obj' : 'pano');
  }

  function closeObjectsSheet() { var d = _dom; if (d) d.contextSheet.classList.remove('tour-chat__context-sheet--visible'); }

  function openInfoFrame(label, infoId, oiIndex) {
      var d = _dom; if (!d || !infoId) return;
      // Point the panorama toward the object WITHOUT changing zoom.
      objectPanTo(currentSceneId(), oiIndex || 0);
      // Render the object info iframe inside the right answer panel.
      var lang = 'id';
      if (window.pano && typeof window.pano.getLanguage === 'function') {
        var l = (window.pano.getLanguage() || '').toLowerCase();
        if (l.indexOf('en') !== -1) lang = 'en';
        else if (l.indexOf('nl') !== -1) lang = 'nl';
        else if (l.indexOf('ar') !== -1) lang = 'sa';
        else if (l.indexOf('zh') !== -1) lang = 'zh';
      }
      var entry = _knowledgeData && _knowledgeData.entries && _knowledgeData.entries.find(function (e) {
        return e.id === 'webview:objinfo2:' + String(infoId);
      });
      var translated = entry && entry.translations && entry.translations[lang];
      var description = translated && translated.content || (entry && entry.content) || '';
      cancelProgressiveReveal();
      d.rightEyebrow.textContent = 'OBJEK INFORMASI';
      d.rightTitle.textContent = (translated && translated.title) || (entry && entry.title) || label || 'Objek Informasi';
      d.rightSubtitle.textContent = 'Deskripsi objek';
      d.rightBodyText.innerHTML = '';
      if (description) {
        startProgressiveReveal(description, d.rightBodyText);
      } else if (objectInfoPage) {
        var langIso = langMap[lang] || lang;
        var src = objectInfoPage + '?' + objectInfoDetailParam + '=' + encodeURIComponent(infoId) + '&lang=' + langIso + objectInfoExtra;
        var ifr = el('iframe', 'tour-chat__right-iframe');
        ifr.setAttribute('frameborder', '0');
        ifr.setAttribute('allowfullscreen', 'true');
        ifr.setAttribute('src', src);
        d.rightBodyText.appendChild(ifr);
      }
      d.rightActions.innerHTML = '';
      // Object info is not a nearby search; clear any leftover recommendations.
      d.rightRecs.style.display = 'none';
      _dynamicPrompts = [];
      renderSuggestions();
      // Keep the left exploration panel ("OBJEK YANG BISA DILIHAT" list) visible
      // alongside the object info so both sections stay reachable.
      if (_uiState !== UIState.WORKSPACE && _uiState !== UIState.EXPANDED) setUIState(UIState.WORKSPACE);
      renderLeftPanel();
      d.rightStack.classList.add('tour-chat__right-stack--visible');
    }

  function closeInfoFrame() {
    // No-op: info frame lives inside the right panel; closing is handled by closing AI guide.
  }

  // Resolve the cover image URL for an object info page. Uses the PHP proxy
  // first (exact URL from the tp=d template); falls back to a best-effort slug
  // URL for hosts without PHP. Returns a Promise resolving to the URL or null.
  function loadObjCover(infoId, lang, label) {
    var id = String(infoId || '').replace(/[^0-9]/g, '');
    if (!id) return Promise.resolve(null);
    function slugUrl() {
      var slug = (label || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
      if (!slug || !objectInfoBase) return null;
      return proxyImg(objectInfoBase.replace(/\/$/, '') + '/' + slug + '.png');
    }
    return fetch(basePath + 'api/objinfo-cover.php?info=' + id + '&lang=' + encodeURIComponent(lang || 'id'))
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { return (d && d.image) ? d.image : slugUrl(); })
      .catch(function () { return slugUrl(); });
  }

  function findHotspotInScene(sceneId, label) {
    var norm = normalize(label);
    var hits = (data.hotspots || []).filter(function (h) { return h.scene === sceneId && normalize(h.title) === norm; });
    return hits[0] || null;
  }

  function focusObject(label) {
    var h = findHotspotInScene(currentSceneId(), label);
    if (h) { focusHotspot(h); return true; }
    return false;
  }

  function goToObject(label) {
    var norm = normalize(label);
    if (!norm) return;
    // 1) Hotspot visible in the current panorama → follow where it points (another scene).
    var here = findHotspotInScene(currentSceneId(), label);
    if (here && here.target) {
      var tgt = sceneById(here.target);
      if (tgt && tgt.id !== currentSceneId()) {
        navigate(tgt);
        _pendingObjectFocus = { sceneId: tgt.id, label: label };
        return;
      }
      focusHotspot(here);
      return;
    }
    // 2) Same hotspot label exists elsewhere → go to the scene it points to.
    var elsewhere = (data.hotspots || []).find(function (h) { return normalize(h.title) === norm; });
    if (elsewhere && elsewhere.target) {
      var target = sceneById(elsewhere.target);
      if (target && target.id !== currentSceneId()) {
        navigate(target);
        _pendingObjectFocus = { sceneId: target.id, label: label };
        return;
      }
      if (target) focusObject(label);
      return;
    }
    // 3) Fallback: navigate to the scene whose name matches.
    var ms = findSceneMatches(label);
    if (ms.length) navigate(ms[0].scene);
  }

  function toggleMobileContext() {
    var d = _dom; if (!d) return;
    if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
      var sheet = d.mobileSheet || document.querySelector('.tour-chat__mobile-sheet');
      if (sheet) {
        if (d.sheetSections && d.sheetSections.secAnswer) {
          var ansSec = d.sheetSections.secAnswer;
          if (ansSec.classList.contains('tour-chat__sheet-section--collapsed')) {
            ansSec.classList.remove('tour-chat__sheet-section--collapsed');
            ansSec.querySelector('.tour-chat__sheet-section-header').setAttribute('aria-expanded', 'true');
          }
        }
        // scroll sheet into view
        try { sheet.scrollTop = 0; } catch(e) {}
        return;
      }
      d.left.classList.toggle('tour-chat__left--mobile-hidden');
    }
  }

  function refreshLocation() {
    var d = _dom; if (!d) return; var s = currentScene(); var name = s ? sceneDisplayName(s) : '';
    d.locationName.textContent = name;
    if (d.sheetSections && d.sheetSections.hTitle) {
      d.sheetSections.hTitle.textContent = name || (data.name || tourName);
    }
  }

  /* ================================================================
     BOOT
     ================================================================ */
  function boot() {
    if (!window.pano) { window.setTimeout(boot, 100); return; }
    mount();
    loadCatalog(); loadSceneIdentity(); loadKnowledge(); loadExploreMenu(); loadRecommended(); loadFlow(); loadProviderHealth();
    loadKnowledge().then(function () { setTimeout(preloadObjCovers, 1200); });
    loadOI();
    loadCuratedQA();
    function showWhenReady() {
      if (!_dom) return;
      var skinObj = window.skin || (window.pano && window.pano.skinObj) || null;
      var ready = false;
      // Muncul BERSAMA skin: skin.js baru menampilkan container "bottom" (yang memuat
      // ".ggskin.ggskin_container.bottom-menu" dan elemen skin lain) setelah intro
      // di-skip (variabel VB == "skip" -> opacity 1, visibility inherit).
      // Jangan pernah pakai document.readyState atau hitungan container sebagai sinyal.
      var bottomEl = (skinObj && skinObj._bottom) || document.querySelector('.ggskin.ggskin_container.bottom-menu') || null;
      if (bottomEl) {
        var cs = getComputedStyle(bottomEl);
        if (cs.visibility !== 'hidden' && cs.display !== 'none' && parseFloat(cs.opacity) > 0.3) ready = true;
      }
      // Mobile: bottom menu mobile uses same VB=="skip" alpha logic as desktop _bottom.
      // Sebelumnya cek _mobile langsung yang visible sejak load pada lebar <=768px
      // → AI button muncul di awal sebelum intro skip. Sekarang mirror desktop:
      // tunggu _bottom_menu_mobile (atau VB=="skip") baru ready.
      if (!ready && skinObj) {
        var mobileBottom = skinObj._bottom_menu_mobile || null;
        if (!mobileBottom && skinObj._mobile) {
          try { mobileBottom = skinObj._mobile.querySelector('.ggskin_container'); } catch(e) {}
        }
        if (mobileBottom) {
          var mcs = getComputedStyle(mobileBottom);
          if (mcs.visibility !== 'hidden' && mcs.display !== 'none' && parseFloat(mcs.opacity) > 0.3) ready = true;
        } else if (skinObj._mobile) {
          var vbVal = null;
          try { vbVal = window.pano && typeof window.pano.getVariableValue === 'function' ? window.pano.getVariableValue('VB') : (skinObj.player ? skinObj.player.getVariableValue('VB') : null); } catch(e) {}
          if (vbVal === 'skip' || vbVal === 'show') {
            var mcs2 = getComputedStyle(skinObj._mobile);
            if (mcs2.visibility !== 'hidden' && mcs2.display !== 'none' && parseFloat(mcs2.opacity) > 0.05) ready = true;
          }
        }
      }
      if (ready) { _dom.root.classList.remove('tour-chat--not-ready'); if (_uiState === UIState.CLOSED) setUIState(UIState.CLOSED); setTimeout(function () { if (_dom) _dom.aiBtn.classList.add('tour-chat__ai-btn--visible'); }, 200); return; }
      setTimeout(showWhenReady, 300);
    }
    setTimeout(showWhenReady, 1500);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
