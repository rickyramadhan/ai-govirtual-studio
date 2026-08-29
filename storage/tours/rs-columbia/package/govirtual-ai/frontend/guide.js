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
      if (ns) { navigate(ns); return { reply: 'Saya bawa Anda ke ' + sel.name + '.', intent: 'navigation', confidence: 0.95, action: 'go_to_scene', scene: ns.id }; }
      var r = sel.name + ' adalah salah satu destinasi di katalog ' + catalogLabel + '.'; if (sel.description) r += ' ' + sel.description; if (sel.virtualTourAvailable) r += ' Tur virtualnya tersedia. Mau saya buka?';
      return { reply: r, intent: 'DESTINATION_DISCOVERY', confidence: 0.92, action: 'none', destination: sel };
    }
    if (isConfirmationResponse(query) && _pendingDestination && _pendingDestination.virtualTourAvailable) { var dest = _pendingDestination; _pendingDestination = null; if (validateTourUrl(dest.virtualTourUrl)) return { reply: 'Baik, saya buka tur virtual ' + dest.name + '.', intent: 'DESTINATION_DISCOVERY', confidence: 0.95, action: 'open_virtual_tour', destinationUrl: dest.virtualTourUrl }; }
    if (isVirtualTourOpenQuery(query) && _pendingDestination && _pendingDestination.virtualTourAvailable) { var dest2 = _pendingDestination; _pendingDestination = null; if (validateTourUrl(dest2.virtualTourUrl)) return { reply: 'Baik, saya buka tur virtual ' + dest2.name + '.', intent: 'DESTINATION_DISCOVERY', confidence: 0.95, action: 'open_virtual_tour', destinationUrl: dest2.virtualTourUrl }; }
    if (isDestinationNameQuery(query)) { var matches = searchDestinations(query, null); if (matches.length > 0) { var best = matches[0].destination; var ns2 = findNavigableScene(best.name); if (ns2) { navigate(ns2); return { reply: 'Saya bawa Anda ke ' + best.name + '.', intent: 'navigation', confidence: 0.95, action: 'go_to_scene', scene: ns2.id }; } _pendingDestination = { destinationId: best.id, name: best.name, virtualTourUrl: best.virtualTourUrl, virtualTourAvailable: best.virtualTourAvailable }; _lastDestinationResults = matches.slice(0, 4).map(function (m) { return m.destination; }); var r2 = best.name + ' merupakan salah satu destinasi dalam katalog ' + catalogLabel + '.'; if (best.description) r2 += ' ' + best.description; if (best.virtualTourAvailable) r2 += ' Tur virtualnya juga tersedia. Mau saya buka sekarang?'; return { reply: r2, intent: 'DESTINATION_DISCOVERY', confidence: 0.93, action: 'none', destination: best }; } }
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
    var sceneId = params.sceneId || currentSceneId(), si = getSceneIdentityEntry(sceneId), ge = graphExitIds(sceneId), sL = !!_catalog, pA = isAnyProviderAvailable();
    var suggestions = [], used = {};
    function tryAdd(q, intent, ei, eid, conf, label) { if (used[intent]) return; var ev = evaluateSuggestionAnswerability({ question: q, intent: intent, sceneId: sceneId, sceneIdentity: si, graphExits: ge, catalogLoaded: sL, providerAvailable: pA }); if (!ev.answerable) return; used[intent] = true; suggestions.push({ id: sceneId + '-' + intent + '-' + suggestions.length, label: label || q, prompt: q, intent: intent, evidenceType: ev.evidenceType, evidenceIds: ev.evidenceIds, confidence: ev.confidence }); }
    if (si && (si.contentGrounding === 'direct' || si.contentGrounding === 'direct-likely')) { var sq = si.suggestedQuestions || []; for (var i = 0; i < sq.length && suggestions.length < 4; i++) { var q = sqQ(sq[i]); var ci = 'SCENE_DESCRIPTION'; if (/apa itu|fungsi|kenapa|siapa|sejarah/i.test(q)) ci = 'ENTITY_DESCRIPTION'; else if (/apa yang menarik|highlight/i.test(q)) ci = 'HIGHLIGHTS'; else if (/ke mana|lanjut|rekomendasi/i.test(q)) ci = 'NAVIGATION'; tryAdd(q, ci, null, null, 0, null); } }
    if (si && si.contentGrounding === 'shared-tour' && suggestions.length < 4) { var tq = si.suggestedQuestions || []; for (var j = 0; j < tq.length && suggestions.length < 4; j++) { var tqj = sqQ(tq[j]); var ti = 'TOUR_DESCRIPTION'; if (/kenapa|asal nama/i.test(tqj)) ti = 'NAME_ORIGIN'; else if (/apa yang menarik/i.test(tqj)) ti = 'HIGHLIGHTS'; else if (/hubungan|kereta api/i.test(tqj)) ti = 'TOUR_CONNECTION'; else if (/ke mana|lanjut/i.test(tqj)) ti = 'NAVIGATION'; tryAdd(tqj, ti, null, null, 0, null); } }
    if (si && si.contentGrounding === 'related-tour-knowledge' && suggestions.length < 4) { var rq = si.suggestedQuestions || []; for (var k = 0; k < rq.length && suggestions.length < 4; k++) tryAdd(sqQ(rq[k]), 'RELATED_KNOWLEDGE', null, null, 0, null); }
    if (suggestions.length < 4 && si && si.placeName) tryAdd('Di mana saya?', 'CURRENT_LOCATION', null, null, 0, 'Di mana saya?');
    var flowNext = flowNextSceneId(sceneId);
    if (suggestions.length < 4 && flowNext && ge.length > 0) tryAdd('Ke mana sebaiknya saya lanjut?', 'NAVIGATION', null, null, 0, flowNext.label ? 'Ke ' + flowNext.label + ' berikutnya?' : 'Ke mana berikutnya?');
    else if (suggestions.length < 4 && ge.length > 0) tryAdd('Ke mana sebaiknya saya lanjut?', 'NAVIGATION', null, null, 0, 'Ke mana berikutnya?');
    if (suggestions.length < 4 && si && si.contentGrounding === 'identity-only') {
      var recFb = (_recQuestions && _recQuestions.state2 && _recQuestions.state2.fallback) ? _recQuestions.state2.fallback : null;
      var fb = recFb && recFb.length ? recFb.map(function(r){ return { q: r.q, i: r.intent||'TOUR_DESCRIPTION' }; }) : [{ q: 'Apa asal nama ' + tourName + '?', i: 'NAME_ORIGIN' }, { q: 'Apa yang menarik dari ' + tourName + '?', i: 'TOUR_HIGHLIGHTS' }, { q: 'Apa sejarah ' + tourName + '?', i: 'TOUR_HISTORY' }];
      for (var f = 0; f < fb.length && suggestions.length < 4; f++) tryAdd(fb[f].q, fb[f].i, null, null, 0, null);
    }
    if (suggestions.length < 4 && _exploreMenuData && _exploreMenuData.entries) { for (var ei = 0; ei < _exploreMenuData.entries.length && suggestions.length < 4; ei++) tryAdd('Apa itu ' + _exploreMenuData.entries[ei].title + '?', 'ENTITY_DESCRIPTION', null, null, 0, null); }
    if (suggestions.length < 4 && sL) tryAdd('Ada destinasi lain di ' + catalogLabel + '?', 'DESTINATION_DISCOVERY', null, null, 0, 'Destinasi');
    if (suggestions.length < 4 && pA) { var gq = [{ q: 'Apa itu arsitektur kolonial?', i: 'GENERAL_KNOWLEDGE' }, { q: 'Bagaimana fungsi kaca patri pada bangunan kolonial?', i: 'GENERAL_KNOWLEDGE' }]; for (var g = 0; g < gq.length && suggestions.length < 4; g++) tryAdd(gq[g].q, gq[g].i, null, null, 0, null); }
    return suggestions.slice(0, 4);
  }

  /* PANO2VR BRIDGE */
  function getSkinPlayer() { if (window.pano && typeof window.pano.setLanguage === 'function') return window.pano; if (window.skin && window.skin.player && typeof window.skin.player.setLanguage === 'function') return window.skin.player; return null; }
  function runSkinAction(action) {
    var player = getSkinPlayer(); var skinObj = window.skin || (window.pano && window.pano.skinObj) || null;
    function openPopup(bk, pk) { var b = skinObj && skinObj[bk]; var p = skinObj && skinObj[pk]; if (b && typeof b.onclick === 'function') b.onclick(); if (!p) return false; p.ggVisible = true; p.style.opacity = '1'; p.style.visibility = 'inherit'; p.style.pointerEvents = 'auto'; return true; }
    switch (action) {
      case 'set_language_en': if (player) player.setLanguage('en'); return true;
      case 'set_language_id': if (player) player.setLanguage('id'); return true;
      case 'set_language_nl': if (player) player.setLanguage('nl'); return true;
      case 'set_language_ar': if (player) player.setLanguage('ar'); return true;
      case 'set_language_zh': if (player) player.setLanguage('zh-CN'); return true;
      case 'open_explore': return openPopup('_jelajah', '_url_popupdiscover');
      case 'open_menu': return openPopup('_icon_menu', '_url_popupmenu') || openPopup('_menu', '_url_popupmenu');
      case 'open_information': return openPopup('_icon_info', '_url_popupinformasi');
      case 'open_public_service': if (skinObj && skinObj._icon_public && typeof skinObj._icon_public.onclick === 'function') skinObj._icon_public.onclick(); return true;
      case 'play_360': if (skinObj && skinObj._btn360 && typeof skinObj._btn360.onclick === 'function') skinObj._btn360.onclick(); return true;
      case 'open_category': if (skinObj && skinObj._button_kategori && typeof skinObj._button_kategori.onclick === 'function') skinObj._button_kategori.onclick(); return true;
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
      if (_pendingDroneChoice) { var c = _pendingDroneChoice; _pendingDroneChoice = null; if (/\b(utama|area utama|main|depan|halaman)\b/.test(t)) { var ms = sceneById(c.mainId); if (ms) { navigate(ms); return { reply: 'Saya arahkan ke view drone Area Utama.', intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: ms.id }; } } var bs = sceneById(c.balkonId); if (bs) { navigate(bs); return { reply: 'Saya arahkan ke view drone Balkon.', intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: bs.id }; } }
      var md = sceneById('node51'), mn = sceneById('node50'), bd = sceneById('node53'), bn = sceneById('node52');
      var ms2 = active && active.variant === 'night' ? mn : md, bs2 = active && active.variant === 'night' ? bn : bd;
      if (!ms2) ms2 = md || mn; if (!bs2) bs2 = bd || bn;
      if (ms2 && bs2) { _pendingDroneChoice = { mainId: ms2.id, balkonId: bs2.id }; pendingClarification = { type: 'drone', options: ['Drone Area Utama', 'Drone Balkon'] }; return { reply: 'Ada 2 view drone:\n1. Drone Area Utama\n2. Drone Balkon\n\nMau yang mana?', intent: 'clarify', confidence: 0.90, needs_clarification: true, clarification_options: ['Drone Area Utama', 'Drone Balkon'], action: 'none' }; }
    }
    if (continueRoute) { if (conversationState.activeRouteSceneIds && conversationState.activeRouteSceneIds.length) { var ci = conversationState.activeRouteSceneIds.indexOf(currentSceneId()); var nsid = ci >= 0 ? conversationState.activeRouteSceneIds[ci + 1] : conversationState.activeRouteSceneIds[0]; var ns = nsid ? sceneById(nsid) : null; if (ns) return { reply: 'Saya lanjutkan ke ' + sceneDisplayName(ns) + '.', intent: 'navigate', confidence: 0.92, action: 'go_to_scene', scene: ns.id }; } var fn = flowNextSceneId(currentSceneId()); if (fn) { var fns = sceneById(fn.sceneId); if (fns) return { reply: 'Saya lanjutkan ke ' + fn.label + '.', intent: 'navigate', confidence: 0.92, action: 'go_to_scene', scene: fns.id }; } }
    if (pronounFollowUp) { var tid = (conversationState.lastRecommendedDestination && conversationState.lastRecommendedDestination.sceneId) || conversationState.lastReferencedDestination || conversationState.lastRecommendedSceneId || conversationState.lastNavigableSceneId || null; if (!tid && conversationState.activeRouteSceneIds && conversationState.activeRouteSceneIds.length) { var ai = conversationState.activeRouteSceneIds.indexOf(currentSceneId()); tid = ai >= 0 ? conversationState.activeRouteSceneIds[ai + 1] : conversationState.activeRouteSceneIds[0]; } if (tid) { var ts = sceneById(tid); if (ts) return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(ts) + '. ' + sceneSummary(ts), intent: 'navigate', confidence: 0.88, action: 'go_to_scene', scene: ts.id }; } return { reply: 'Maksud Anda tujuan yang mana?', intent: 'clarify', confidence: 0.4, needs_clarification: true, clarification_options: [], action: 'none' }; }
    if (wantsFacility) { var fm = contextualSceneMatches(query).filter(function (m) { return /anak|keluarga|tunggu|waiting|service|lobi/.test(normalize(m.scene.name + ' ' + (m.scene.aliases || []).join(' '))); }); if (fm.length > 1 && fm[0].score === fm[1].score) return clarify(fm, 'scene'); if (fm[0]) { var fs = fm[0].scene; conversationState.lastRecommendedSceneId = fs.id; conversationState.lastNavigableSceneId = fs.id; return { reply: 'Untuk menunggu, saya rekomendasikan ' + sceneDisplayName(fs) + '. ' + sceneSummary(fs), intent: 'facility_recommendation', confidence: 0.93, action: 'show_facility', scene: fs.id }; } }
    if (wantsRoute || /\b(mobil|kendaraan|anak|keluarga|eksekutif|vip|lansia|disabilitas)\b/.test(t)) { var rm = findRouteMatches(query); if (rm.length > 1 && rm[0].score === rm[1].score) return clarify(rm, 'route'); if (rm[0]) { var route = rm[0].route; var first = route.scenes[0]; conversationState.recommendedRouteId = route.id; conversationState.lastRecommendedSceneId = first; conversationState.lastNavigableSceneId = first; var fo = sceneById(first); if (fo) { navigate(fo); return { reply: 'Saya arahkan ke ' + sceneDisplayName(fo) + '. Urutan: ' + route.scenes.map(function (sid) { var s = sceneById(sid); return s ? sceneDisplayName(s) : sid; }).join(' \u2192 ') + '.', intent: 'navigate', confidence: 0.94, action: 'go_to_scene', scene: first }; } } }
    var isInfoNav = /\b(pukul|jam|berapa|buka|tutup|harga|tiket|biaya|sejarah|kenapa|mengapa|apa itu|apa yang|ceritakan|jelaskan|deskripsikan|arti|makna|fungsi|menarik|unik|bagaimana|dimana|di mana|kapan|siapa|hubungan|kaitan|hubungannya|berkaitan)\b/.test(t);
    if (!isInfoNav && /(?:^|\b)(?:ke|menuju|pergi ke|bawa(?:\s+saya)?\s+ke|antar(?:\s+saya)?\s+ke|arahkan(?:\s+saya)?\s+ke|(?:saya\s+)?mau\s+(?:lihat|liat|ke|kelihat)|(?:saya\s+)?ingin\s+(?:lihat|liat|ke|melihat)|(?:saya\s+)?pengen\s+(?:lihat|liat|ke)|go to|take me to)\b/.test(t)) { var sm = contextualSceneMatches(query); if (sm.length > 0) { conversationState.lastReferencedDestination = sm[0].scene.id; conversationState.lastNavigableSceneId = sm[0].scene.id; navigate(sm[0].scene); return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(sm[0].scene) + '. ' + sceneSummary(sm[0].scene), intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: sm[0].scene.id }; } }
    if (/\b(di sini|area ini|tempat ini)\b/.test(t) && active && !/\b(kenapa|mengapa|apa|siapa|kapan|bagaimana|berapa|dimana|di mana|ceritakan|jelaskan|deskripsikan|tentang)\b/.test(t)) return { reply: sceneSummary(active), intent: 'area_info', confidence: 0.95, scene: active.id };
    return null;
  }
  function clarify(matches, type) { var opts = matches.slice(0, 3).map(function (m) { return type === 'route' ? m.route.name : sceneDisplayName(m.scene); }); pendingClarification = { type: type, options: opts }; return { reply: 'Maksud Anda yang mana?', intent: 'clarify', confidence: 0.4, needs_clarification: true, clarification_options: opts, action: 'none' }; }
  function navigate(scene) { window.pano.openNext('{' + scene.id + '}', ''); location.hash = scene.id; }
  function goToSceneId(id) { if (!id) return; window.pano.openNext('{' + id + '}', ''); location.hash = id; }
  function focusHotspot(h) { if (typeof window.pano.moveTo === 'function') window.pano.moveTo(h.pan + '/' + h.tilt + '/70', 1); }
  function sceneById(sid) { return data.scenes.find(function (s) { return s.id === sid; }); }
  function findNavigableScene(name) { var t = normalize(name); if (!t) return null; var best = null, bs = 0; for (var i = 0; i < data.scenes.length; i++) { var s = data.scenes[i]; var sc = 0; var sn = normalize(sceneDisplayName(s)); if (t === sn) sc = 100; else if (sn.length >= 3 && t.indexOf(sn) !== -1) sc = sn.length * 2; else if (sn.length >= 3 && sn.indexOf(t) !== -1) sc = t.length * 2; (s.aliases || []).forEach(function (a) { var an = normalize(a); if (an.length >= 3 && t.indexOf(an) !== -1) sc = Math.max(sc, an.length + 5); if (an.length >= 3 && an.indexOf(t) !== -1) sc = Math.max(sc, t.length + 5); }); if (sc > bs) { bs = sc; best = s; } } return bs >= 5 ? best : null; }
  var _sceneRegistry = data._sceneRegistry || {};
  var _dynamicPrompts = [];
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
  function sceneSummary(s) { if (!s) return ''; var p = []; if (s.description) p.push(s.description); var h = (s.highlights || []).join(', '); var f = (s.facilities || []).join(', '); if (h) p.push('Yang menonjol: ' + h + '.'); if (f) p.push('Fasilitas: ' + f + '.'); return p.join(' '); }
  function contentCapabilities(s) { if (!s) return null; return { hasDescription: !!s.description, hasHighlights: !!(s.highlights && s.highlights.length), hasNavigation: graphExitIds(s.id).length > 0 }; }
  function graphExitIds(sid) { var g = buildSceneGraph(), seen = {}; return (g[sid] || []).filter(function (id) { return id !== sid; }).map(function (id) { return sceneById(id); }).filter(Boolean).filter(function (s) { var l = sceneDisplayName(s.id); if (seen[l]) return false; seen[l] = true; return true; }); }
  function recommendDestination(s) { conversationState.lastRecommendedDestination = null; if (!s) return null; var exits = graphExitIds(s.id); if (!exits.length) return null; var t = exits[0]; conversationState.lastRecommendedDestination = { sceneId: t.id, label: sceneDisplayName(t), sourceSceneId: s.id, navigable: true }; conversationState.lastRecommendedSceneId = t.id; conversationState.lastNavigableSceneId = t.id; return t; }

  function localRespond(query) {
    var t = normalize(query), aid = currentSceneId(), active = sceneById(aid);
    var ctx = resolveContextualIntent(query, active); if (ctx) return ctx;
    var sa = skinActionFromQuery(query); if (sa) return { reply: sa.reply, intent: 'skin_action', confidence: 0.95, action: sa.action };
    if (/\b(video|hotspot|konten).{0,20}360|\b360\b.{0,20}(apa saja|apa aja|tersedia|ada|video|hotspot)/.test(t)) { var cr = (data.video360 || []).filter(function (i) { return i.scene === aid; }); var all = (data.video360 || []).slice(); var ord = cr.concat(all.filter(function (i) { return cr.indexOf(i) === -1; })); if (!ord.length) return { reply: 'Belum ada video 360 terdaftar.', intent: 'video360', confidence: 0.8 }; var fv = ord[0]; var fs = sceneById(fv.scene) || { name: fv.scene, id: fv.scene }; return { reply: 'Ada ' + ord.length + ' video 360: ' + ord.map(function (i) { var sc = sceneById(i.scene); return i.title + ' di ' + (sc ? sceneDisplayName(sc) : i.scene); }).join('; ') + '. Klik tombol di bawah untuk langsung memutarnya.', intent: 'video360', confidence: 0.97, action: 'none', video360: fv }; }
    if (/^(halo|hai|hi|hello)\b/.test(t)) return { reply: 'Halo. Saya pemandu virtual ' + data.name + '. Tanyakan lokasi atau fasilitas.' };
    if (/(di mana saya|saya di mana|posisi saya|sedang berada|where am i)/.test(t)) { if (!active) return { reply: 'Area aktif belum dikenali.', intent: 'location', confidence: 0.5 }; var lr = 'Anda sedang berada di ' + sceneDisplayName(active) + '.'; var lsi = getSceneIdentityEntry(active.id); if (lsi && (lsi.shortDescription || lsi.description)) lr += ' ' + ((lsi.shortDescription || lsi.description) + '').replace(/\.\s*$/, '') + '.'; if (lsi && lsi.highlights && lsi.highlights.length) lr += '\n\nYang menonjol di sini: ' + lsi.highlights.slice(0, 3).join('; ') + '.'; return { reply: lr, intent: 'location', confidence: 0.98, scene: active.id }; }
    if (/(rekomendasi berikutnya|habis ini (ke mana|kemana)|(ke mana|kemana) (berikutnya|selanjutnya|sebaiknya( saya)? lanjut)|setelah ini( saya)? (ke mana|kemana)|selanjutnya (ke mana|kemana)|lanjut (ke mana|kemana)|\bnext\b)/.test(t)) { var rec = recommendDestination(active); return rec ? { reply: 'Saya bawa Anda ke ' + sceneDisplayName(rec) + '.', intent: 'recommend', confidence: 0.95, action: 'go_to_scene', scene: rec.id } : { reply: 'Belum ada rekomendasi.', intent: 'recommend', confidence: 0.5, scene: active ? active.id : null }; }
    if (/(arahkan|antar|bawa|guide|bring|take me|go).*(dari|from).*(ke|menuju|to)|(?:dari|from).*(?:ke|menuju|to)/.test(t)) { var fm = t.match(/(?:dari|from)\s+(.+?)\s+(?:ke|menuju|to)\s+(.+)/); if (fm) { var rel = routeBetweenScenes(fm[1], fm[2]); if (rel) return { reply: 'Rute dari ' + sceneDisplayName(rel.fromScene) + ' ke ' + sceneDisplayName(rel.toScene) + ': ' + rel.path.map(function (sid) { var s = sceneById(sid); return s ? sceneDisplayName(s) : sid; }).join(' \u2192 ') + '.', intent: 'route_relation', confidence: 0.95, action: 'start_route', route: rel.path }; } }
    if (/(apa saja|daftar|lokasi tersedia)/.test(t)) return { reply: 'Lokasi: ' + data.scenes.map(sceneDisplayName).join(', ') + '.' };
    if (pendingClarification) { var chosen = pendingClarification.options.find(function (o) { return normalize(o) === t || t.indexOf(normalize(o)) !== -1; }); var ct = pendingClarification.type; pendingClarification = null; if (chosen) return localRespond(chosen); }
    var rm = findRouteMatches(query); if (rm.length > 1 && rm[0].score === rm[1].score) return clarify(rm, 'route');
    if (/(kembali|awal|utama|mulai)/.test(t)) { var home = data.scenes[0]; if (home) navigate(home); return { reply: 'Kembali ke awal tur.' }; }
    var lh = findHotspot(query, aid); if (lh && lh.score > 0 && /\b(tunjukkan|sorot|buka|lihat|tampilkan|show|highlight|fokus|focus)\b/.test(t)) { focusHotspot(lh.hotspot); return { reply: 'Saya menyorot ' + lh.hotspot.title + '.' }; }
    if (/(ke sana|kesana|di sana|ke situ|di situ)/.test(t)) { var did = conversationState.lastNavigableEntity ? data.scenes.find(function (s) { return (s.name || '').toLowerCase().indexOf(conversationState.lastNavigableEntity.toLowerCase()) !== -1 || (s.displayName || '').toLowerCase().indexOf(conversationState.lastNavigableEntity.toLowerCase()) !== -1; }) : null; var did2 = did ? did.id : ((conversationState.lastRecommendedDestination && conversationState.lastRecommendedDestination.sceneId) || conversationState.lastRecommendedSceneId || conversationState.lastNavigableSceneId); var ds = did2 ? sceneById(did2) : null; if (ds && ds.id !== aid) { navigate(ds); return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(ds) + '.', intent: 'navigate', confidence: 0.96 }; } if (ds && ds.id === aid) return { reply: 'Anda sudah di ' + sceneDisplayName(ds) + '.', intent: 'location', confidence: 0.96 }; }
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
    if (action.type === 'go_to_scene' && sceneById(action.scene_id || action.scene)) { navigate(sceneById(action.scene_id || action.scene)); return; }
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
    function tryPhp() { return fetch(apiUrl, { method: 'POST', signal: activeRequest.signal, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ aiRequestId: 'web-' + Date.now().toString(36), message: query, currentScene: currentSceneId(), history: history, conversationState: conversationState, viewContext: ctx.view, tourContext: { scenes: data.scenes, hotspots: data.hotspots, routes: data.routes, video360: data.video360 } }) }).then(function (r) { if (!r.ok) throw new Error('PHP ' + r.status); return r.json(); }); }
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

    // Hide non-essential Pano2VR skin elements while AI mode is active, using a
    // toggled CLASS on the skin container (not inline style mutation). Removing
    // the class restores the skin exactly, so it can never be left non-interactive.
    toggleSkinHidden(state !== UIState.CLOSED && state !== UIState.DISCOVERY);

    switch (state) {
      case UIState.CLOSED:
        d.aiBtn.style.display = '';
        d.aiBtn.classList.add('tour-chat__ai-btn--visible');
        d.root.setAttribute('data-state', 'closed');
        break;
      case UIState.DISCOVERY:
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
        d.left.style.display = '';
        d.rightStack.style.display = '';
        d.questionbar.style.display = '';
        d.location.style.display = '';
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
     hotspots stay usable for scene navigation.
     NOTE: a hidden container is not enough on its own — Pano2VR sets
     `pointer-events: auto` on inner buttons/images, which would otherwise
     override the parent's `pointer-events: none` and keep blocking drags on the
     panorama behind it. So we disable pointer-events recursively on every
     descendant, while still skipping hotspot subtrees. */
  function setSkinPointerEvents(el, enable) {
    if (!el || !el.classList) return;
    if (el.ggId === 'hotspot' || el.classList.contains('ggskin_hotspot')) return;
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

  function toggleSkinHidden(hide) {
    var skinObj = window.skin || (window.pano && window.pano.skinObj) || null;
    var skinDiv = skinObj && skinObj.divSkin ? skinObj.divSkin : null;
    if (!skinDiv) return;
    var children = skinDiv.children;
    for (var i = 0; i < children.length; i++) {
      var e = children[i];
      if (!e.classList || !e.classList.contains('ggskin')) continue;
      if (isHotspotSkin(e)) continue;
      if (hide) {
        if (!e.hasAttribute('data-gva-op')) e.setAttribute('data-gva-op', e.style.opacity || '');
        e.style.opacity = '0';
        setSkinPointerEvents(e, false);
      } else {
        if (e.hasAttribute('data-gva-op')) { e.style.opacity = e.getAttribute('data-gva-op'); e.removeAttribute('data-gva-op'); }
        setSkinPointerEvents(e, true);
      }
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
    // Populate "COBA TANYAKAN" starters (max 3, deduplicated)
    d.discStarters.innerHTML = '';
    var starters = buildSmartStarters();
    var seen = {}, deduped = [];
    starters.forEach(function (s) {
      var key = questionKey(s.query);
      if (seen[key]) return; seen[key] = 1; deduped.push(s);
    });
    deduped.slice(0, 3).forEach(function (s) {
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
    // Per-tour override via recommended-questions.json (State1)
    if (_recQuestions && _recQuestions.state1 && _recQuestions.state1.starters && _recQuestions.state1.starters.length) {
      var out=[]; for(var i=0;i<_recQuestions.state1.starters.length && out.length<4;i++){ var s=_recQuestions.state1.starters[i]; if(s && s.query) out.push({ icon: '\u2728', title: s.label||s.desc||s.query, desc: s.desc||s.query, query: s.query }); }
      if(out.length) return out.slice(0,4);
    }
    var scene = currentScene();
    var si = scene ? getSceneIdentityEntry(scene.id) : null;
    var starters = [];
    if (si && si.suggestedQuestions && si.suggestedQuestions.length) {
      var q = sqQ(si.suggestedQuestions[0]);
      starters.push({ icon: '\uD83D\uDCCD', title: 'Tentang tempat ini', desc: q, query: q });
    }
    starters.push({ icon: '\uD83C\uDFDB\uFE0F', title: 'Asal nama', desc: 'Mengapa disebut ' + tourName + '?', query: 'Mengapa disebut ' + tourName + '?' });
    starters.push({ icon: '\u2728', title: 'Yang menarik', desc: 'Apa yang menarik di area ini?', query: 'Apa yang menarik di area ini?' });
    starters.push({ icon: '\uD83E\uDDED', title: 'Lanjut ke mana', desc: 'Setelah dari sini sebaiknya ke mana?', query: 'Ke mana sebaiknya saya lanjut?' });
    return starters.slice(0, 4);
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
    var dynamic = (_dynamicPrompts || []).map(function (p) { return { label: p, prompt: p }; });
    var seen = {}, merged = [];
    function pushIfNew(s) {
      if (!s || !s.prompt) return;
      var key = normalize(s.prompt);
      if (seen[key]) return;
      seen[key] = true;
      merged.push(s);
    }
    // Conversation-driven prompts first (most relevant to current dialog),
    // then scene-contextual suggestions — keeps every panorama feeling dynamic.
    dynamic.forEach(pushIfNew);
    contextual.forEach(pushIfNew);
    merged = merged.slice(0, 4);
    d.leftSugList.innerHTML = '';
    merged.forEach(function (s) {
      var btn = el('button', 'tour-chat__left-sug', s.label); btn.type = 'button';
      btn.addEventListener('click', function () { submitAI(s.prompt); });
      d.leftSugList.appendChild(btn);
    });
    if (d.sugBox) d.sugBox.style.display = merged.length ? '' : 'none';
  }

  /* ================================================================
     RENDER: LEFT PANEL — Exploration Context ("Where am I?")
     ================================================================ */
  function renderLeftPanel() {
    var d = _dom; if (!d) return;
    var scene = currentScene();
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
        img.alt = ex.title; img.loading = 'lazy';
        media.appendChild(img);
        media.appendChild(el('div', 'tour-chat__left-pano-title', ex.title));
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
      si.relatedKnowledgeIds.forEach(function (rid) {
        var m = /objinfo2:(\d+)/.exec(rid);
        if (!m) return;
        var e = known[rid];
        var label = e && e.title ? e.title : '';
        if (!label && m) label = 'Objek #' + m[1];
        if (!label) return;
        var key = normalize(label); if (seen[key]) return;
        seen[key] = true;
        items.push({ label: label, kind: 'info', infoId: m[1], oiIndex: oiIdx });
        oiIdx++;
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
      for (var i = 0; i < rki.length; i++) {
        var m = /objinfo2:(\d+)/.exec(rki[i]);
        if (m && m[1] === id) { candidates.push({ sceneId: sid, oiIndex: i }); break; }
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
    img.addEventListener('error', function () { img.removeAttribute('src'); img.classList.add('tour-chat__obj-card-img--ph'); });
    fetch(basePath + 'api/objinfo-cover.php?info=' + encodeURIComponent(infoId) + '&lang=id')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (d && d.image) img.src = proxyImg(d.image);
        else img.classList.add('tour-chat__obj-card-img--ph');
      })
      .catch(function () { img.classList.add('tour-chat__obj-card-img--ph'); });
  }

  /* ================================================================
     RENDER: RIGHT PANEL — Contextual Answer / Insight card
     ================================================================ */
  function renderRightAnswer(presentation) {
    var d = _dom; if (!d) return;
    cancelProgressiveReveal();
    d.rightEyebrow.textContent = presentation.eyebrow || '';
    d.rightTitle.textContent = presentation.title || '';
    d.rightSubtitle.textContent = presentation.subtitle || '';
    d.rightBodyText.textContent = '';
    if (presentation.body) startProgressiveReveal(presentation.body, d.rightBodyText);
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
    var desc = (si && (si.shortDescription || si.description)) || sc.description || '';
    if (desc) parts.push(desc);
    if (si && si.highlights && si.highlights.length) parts.push('Yang menonjol: ' + si.highlights.slice(0, 2).join('; ') + '.');
    if (si && si.suggestedQuestions && si.suggestedQuestions.length) parts.push('Bisa ditanyakan: ' + sqQ(si.suggestedQuestions[0]));
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
    if (intent === 'DESTINATION_DISCOVERY' || _lastDestinationResults.length > 0) { var dests = _lastDestinationResults.length > 0 ? _lastDestinationResults : (result.destination ? [result.destination] : []); if (dests.length) return { type: 'destination', eyebrow: 'DESTINASI ' + catalogLabel.toUpperCase(), title: dests[0].name, body: reply, actions: dests.filter(function (d) { return d.virtualTourAvailable; }).slice(0, 2).map(function (d) { return { label: 'Virtual Tour', url: d.virtualTourUrl }; }), relatedPrompts: ['Destinasi lain?'] }; }
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
    return { type: 'story', eyebrow: eyebrow, title: title, body: reply, actions: actions, relatedPrompts: relatedPrompts };
  }

  /* Extract a short subject label from the question to use as panel title. */
  function questionSubject(query) {
    var t = (query || '').trim().replace(/\s+/g, ' ');
    if (!t) return '';
    var trimmed = t.replace(/[?؟]+$/g, '').replace(/\.\s*$/g, '').trim();
    if (!trimmed) return t;
    // For short entity questions, use the whole question as the subject.
    return trimmed.length <= 40 ? trimmed : trimmed.slice(0, 40).trim() + '\u2026';
  }

  /* ================================================================
     SUBMIT — first question exits Ask Overlay into Exploration Mode
     ================================================================ */
  function submitAI(value) {
    var query = value.trim(); var now = Date.now();
    if (!query || (normalize(query) === normalize(lastSubmitted.text) && now - lastSubmitted.at < 900)) return;
    lastSubmitted = { text: query, at: now };
    _hasConversed = true;

    var fromDiscovery = _uiState === UIState.DISCOVERY;
    _conversationHistory.push({ role: 'user', text: query, scene: currentSceneId() });

    if (fromDiscovery) {
      if (_dom.discInput) { _dom.discInput.placeholder = 'Sedang memproses pertanyaan Anda\u2026'; _dom.discInput.disabled = true; }
    } else {
      renderLeftPanel();
      renderRightThinking();
    }

    // Hybrid curated answer: admin set "fix" answer for a suggested question → balas langsung tanpa AI.
    var curatedAns = getCuratedAnswer(query);
    var local = null, deterministic = false;
    if (curatedAns) {
      local = { reply: curatedAns, intent: 'curated', confidence: 1, action: 'none', curated: true };
      deterministic = true;
    } else {
      try {
        var clarified = resolveClarificationChoice(query);
        local = clarified ? clarified : localRespond(query);
        deterministic = !!local && !!local.intent;
      } catch (e) { local = null; deterministic = false; }
    }

    var resolved = deterministic ? Promise.resolve(local) : remoteRespond(query).then(function (r) { if (!r || !r.reply || r.reply === 'Layanan AI tidak terjangkau.' || r.reply === 'Respons AI tidak valid.') return null; return r; });

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
        presentation.recommendations = (result.recommendations && result.recommendations.length) ? result.recommendations : null;
      }
      _conversationHistory.push({ role: 'assistant', text: result.reply, scene: currentSceneId(), intent: result.intent, query: query, presentation: presentation });

      hideDiscStatus();
      if (fromDiscovery) setUIState(UIState.WORKSPACE);
      _currentPresentation = presentation;
      renderRightAnswer(presentation);
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
    var discEyebrow = el('div', 'tour-chat__disc-eyebrow');
    discEyebrow.appendChild(el('span', 'tour-chat__disc-eyebrow-icon', '\u2726'));
    discEyebrow.appendChild(document.createTextNode(' AI GUIDE'));
    discBrand.appendChild(discEyebrow);
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
      discMic.setAttribute('aria-pressed', on ? 'true' : 'false');
      discMic.classList.toggle('tour-chat__disc-ask-mic--recording', on);
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
      recThemes.forEach(function (t) {
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

    // Smart starters — "COBA TANYAKAN" (rendered before themes per hierarchy)
    var discStarters = el('div', 'tour-chat__disc-starters');
    discStarters.appendChild(el('div', 'tour-chat__disc-starters-label', 'COBA TANYAKAN'));
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
    var objectsAll = el('button', 'tour-chat__objects-all', 'Lihat semua objek');
    objectsAll.type = 'button';
    objectsAll.addEventListener('click', function () { openObjectsSheet(); });
    objectsHead.appendChild(objectsAll);
    objectsPanel.appendChild(objectsHead);
    var objectsList = el('div', 'tour-chat__objects-list');
    objectsPanel.appendChild(objectsList);

    var leftSugList = el('div', 'tour-chat__left-sug-list');
    var sugBox = el('div', 'tour-chat__left-suggestions');
    var sugLabelEl = el('div', 'tour-chat__left-sug-label', (_recQuestions && _recQuestions.state2 && _recQuestions.state2.workspaceHeader) || 'MUNGKIN ANDA INGIN TAHU');
    sugBox.appendChild(sugLabelEl);
    loadRecommended().then(function(){ if(_recQuestions && _recQuestions.state2 && _recQuestions.state2.workspaceHeader) sugLabelEl.textContent = _recQuestions.state2.workspaceHeader; });
    sugBox.appendChild(leftSugList);

    left.appendChild(leftBody);

    /* ---- RIGHT STACK: answer panel + follow-up question bar (aligned bottom) ---- */
    var rightStack = el('div', 'tour-chat__right-stack');
    var right = el('div', 'tour-chat__right');
    var rightBrand = el('div', 'tour-chat__right-brand');
    rightBrand.appendChild(el('span', 'tour-chat__right-brand-icon', '\u2726'));
    rightBrand.appendChild(el('span', 'tour-chat__right-brand-text', 'AI GUIDE'));
    right.appendChild(rightBrand);
    var rightHeader = el('div', 'tour-chat__right-header');
    var rightHeaderText = el('div', 'tour-chat__right-header-text');
    var rightEyebrow = el('div', 'tour-chat__right-eyebrow'); rightHeaderText.appendChild(rightEyebrow);
    var rightTitle = el('div', 'tour-chat__right-title'); rightHeaderText.appendChild(rightTitle);
    var rightSubtitle = el('div', 'tour-chat__right-subtitle'); rightHeaderText.appendChild(rightSubtitle);
    rightHeader.appendChild(rightHeaderText); right.appendChild(rightHeader);
    var rightBody = el('div', 'tour-chat__right-body');
    var rightBodyText = el('div', 'tour-chat__right-body-text'); rightBodyText.setAttribute('role', 'status'); rightBodyText.setAttribute('aria-live', 'polite'); rightBodyText.setAttribute('aria-atomic', 'false'); rightBody.appendChild(rightBodyText); right.appendChild(rightBody);
    var rightActions = el('div', 'tour-chat__right-actions'); right.appendChild(rightActions);
    var rightRecs = el('div', 'tour-chat__right-recs');
    rightRecs.appendChild(el('div', 'tour-chat__right-recs-label', 'REKOMENDASI'));
    var rightRecsList = el('div', 'tour-chat__right-recs-list'); rightRecs.appendChild(rightRecsList); right.appendChild(rightRecs);
    var rightFooter = el('div', 'tour-chat__right-footer');
    var rightHistoryBtn = el('button', 'tour-chat__right-history', '\u2756 Riwayat Pertanyaan');
    rightHistoryBtn.type = 'button';
    rightHistoryBtn.addEventListener('click', function () { setUIState(UIState.EXPANDED); });
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
      qbMic.setAttribute('aria-pressed', on ? 'true' : 'false');
      qbMic.classList.toggle('tour-chat__questionbar-mic--recording', on);
      if (on) {
        qbInput.placeholder = 'Silahkan bicara dan tanyakan apapun tentang ' + (data.name || tourName) + '\u2026';
        qbSend.classList.add('tour-chat__questionbar-send--stop');
        qbSend.disabled = false;
        qbSend.setAttribute('aria-label', 'Berhenti merekam');
        qbSend.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none"><rect x="6" y="6" width="12" height="12" rx="2.5" fill="currentColor"/></svg>';
      } else {
        qbInput.placeholder = qbDefaultPlaceholder;
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
    qbRow.appendChild(qbSpark); qbRow.appendChild(qbText); qbRow.appendChild(qbMic); qbRow.appendChild(qbSend);
    questionbar.appendChild(qbRow);
    rightStack.appendChild(sugBox);
    rightStack.appendChild(questionbar);

    /* ---- HISTORY drawer — Riwayat Pertanyaan (secondary) ---- */
    var history = el('div', 'tour-chat__history');
    var historyPanel = el('div', 'tour-chat__history-panel');
    var historyHeader = el('div', 'tour-chat__history-header');
    historyHeader.appendChild(el('div', 'tour-chat__history-title', 'RIWAYAT PERTANYAAN'));
    var historyClose = el('button', 'tour-chat__history-close'); historyClose.type = 'button'; historyClose.innerHTML = '&#x2715;';
    historyClose.addEventListener('click', function () { setUIState(UIState.WORKSPACE); });
    historyHeader.appendChild(historyClose); historyPanel.appendChild(historyHeader);
    var historyBody = el('div', 'tour-chat__history-body'); historyPanel.appendChild(historyBody);
    history.appendChild(historyPanel);

    /* ---- OBJECTS sheet — "Lihat semua objek" ---- */
    var contextSheet = el('div', 'tour-chat__context-sheet');
    var csPanel = el('div', 'tour-chat__context-sheet-panel');
    var csHeader = el('div', 'tour-chat__context-sheet-header');
    csHeader.appendChild(el('div', 'tour-chat__context-sheet-title', 'OBJEK YANG DAPAT DIJELAJAHI'));
    var csClose = el('button', 'tour-chat__context-sheet-close'); csClose.type = 'button'; csClose.innerHTML = '&#x2715;';
    csClose.addEventListener('click', function () { closeObjectsSheet(); });
    csHeader.appendChild(csClose); csPanel.appendChild(csHeader);
    var csBody = el('div', 'tour-chat__context-sheet-body'); csPanel.appendChild(csBody);
    contextSheet.appendChild(csPanel);
    contextSheet.addEventListener('click', function (e) { if (e.target === contextSheet) closeObjectsSheet(); });

    /* ---- Assemble ---- */
    root.appendChild(veil);
    root.appendChild(discovery); root.appendChild(discoveryClose);
    root.appendChild(left); root.appendChild(rightStack); root.appendChild(arrival); root.appendChild(history); root.appendChild(contextSheet); root.appendChild(objectsPanel); root.appendChild(aiBtn);
document.body.appendChild(root);

    // Mobile: tapping anywhere outside the left drawer hides it (the pill and
    // the in-drawer hide button stay usable). Keeps the panorama explorable.
    _dom = {
      root: root, aiBtn: aiBtn, veil: veil,
      discovery: discovery, discTopbar: discTopbar, discInput: discInput, discSend: discSend,
      discLocName: discLocName, discStarters: discStartersList, discStatus: discStatus, discStatusRetry: discStatusRetry,
      discoveryClose: discoveryClose,
      left: left, leftContextName: leftContextName, leftContextDesc: leftContextDesc, leftPano: leftPano,
      objectsPanel: objectsPanel, objectsList: objectsList, leftSugList: leftSugList, sugBox: sugBox,
      right: right, rightEyebrow: rightEyebrow, rightTitle: rightTitle, rightSubtitle: rightSubtitle,
      rightBodyText: rightBodyText, rightActions: rightActions,
      rightRecs: rightRecs, rightRecsList: rightRecsList, rightHistoryBtn: rightHistoryBtn, rightStack: rightStack,
      questionbar: questionbar, qbInput: qbInput, qbSend: qbSend,
      location: location, locationName: locationName, arrival: arrival, arrivalName: arrivalName,
      history: history, historyBody: historyBody,
      contextSheet: contextSheet, csBody: csBody
    };

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
      var _isWs = _uiState === UIState.WORKSPACE && _dom && _dom.root && _dom.root.getAttribute('data-state') === 'open';
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
      lastScene = ns; refreshLocation();
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
    if (window.pano && typeof window.pano.on === 'function') { try { window.pano.on('openNext', function () { setTimeout(function(){ handleSceneChanged(currentSceneId()); }, 300); }); } catch(e){} }
    startScenePolling();

    // Fullscreen handler
    function onFS() { var fs = document.fullscreenElement || document.webkitFullscreenElement; if (fs) { if (fs !== root && !fs.contains(root)) fs.appendChild(root); } else { if (root.parentNode !== document.body) document.body.appendChild(root); } }
    document.addEventListener('fullscreenchange', onFS); document.addEventListener('webkitfullscreenchange', onFS);

    refreshLocation();
  }

  function renderHistory() {
    var d = _dom; if (!d) return;
    d.historyBody.innerHTML = '';
    var list = el('div', 'tour-chat__history-list');
    _conversationHistory.forEach(function (msg) {
      if (msg.role === 'user') {
        var item = el('button', 'tour-chat__history-q'); item.type = 'button';
        item.appendChild(el('div', 'tour-chat__history-q-label', 'PERTANYAAN'));
        item.appendChild(el('div', 'tour-chat__history-q-text', msg.text));
        item.addEventListener('click', function () { reopenHistoryItem(msg); });
        list.appendChild(item);
      } else {
        var a = el('div', 'tour-chat__history-a');
        a.appendChild(el('div', 'tour-chat__history-a-text', msg.text));
        list.appendChild(a);
      }
    });
    d.historyBody.appendChild(list);
    d.historyBody.scrollTop = d.historyBody.scrollHeight;
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

  function openObjectsSheet() {
    var d = _dom; if (!d) return;
    d.csBody.innerHTML = '';
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
            navigate(targetScene);
            _pendingObjectFocus = { sceneId: targetScene.id, label: o.label, infoId: o.infoId, oiIndex: rs2 ? rs2.oiIndex : 0 };
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
    d.csBody.appendChild(list);
    d.contextSheet.classList.add('tour-chat__context-sheet--visible');
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
        var src = objectInfoPage + '?info=' + encodeURIComponent(infoId) + '&lang=' + lang + '&vd=1&tp=m';
        var ifr = el('iframe', 'tour-chat__right-iframe');
        ifr.setAttribute('frameborder', '0');
        ifr.setAttribute('allowfullscreen', 'true');
        ifr.setAttribute('src', src);
        d.rightBodyText.appendChild(ifr);
      }
      d.rightActions.innerHTML = '';
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
    loadOI();
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
      if (ready) { _dom.root.classList.remove('tour-chat--not-ready'); setUIState(UIState.CLOSED); setTimeout(function () { if (_dom) _dom.aiBtn.classList.add('tour-chat__ai-btn--visible'); }, 200); return; }
      setTimeout(showWhenReady, 300);
    }
    setTimeout(showWhenReady, 1500);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
