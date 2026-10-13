<?php

declare(strict_types=1);

require_once __DIR__ . '/ai-core.php';
require_once __DIR__ . '/ai-provider.php';
require_once __DIR__ . '/guide-policy.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'CLI') === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'CLI') !== 'POST' && PHP_SAPI !== 'cli') { http_response_code(405); echo json_encode(['error' => 'method_not_allowed']); exit; }
$rawInput = file_get_contents('php://input') ?: '';
if ($rawInput === '' && PHP_SAPI === 'cli') $rawInput = file_get_contents('php://stdin') ?: '';
$input = json_decode($rawInput, true);
if (!is_array($input)) { http_response_code(400); echo json_encode(['error' => 'invalid_json']); exit; }
$message = trim((string)($input['message'] ?? ''));
if ($message === '' || mb_strlen($message) > 240) { http_response_code(400); echo json_encode(['error' => $message === '' ? 'empty_message' : 'message_too_long']); exit; }

$started = microtime(true); $requestId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['aiRequestId'] ?? '')) ?: bin2hex(random_bytes(8));
$config = ai_config();
$tour = ai_canonical_tour(is_array($input['tourContext'] ?? null) ? $input['tourContext'] : []);
if (!$tour['scenes']) { http_response_code(400); echo json_encode(['error' => 'missing_tour_context']); exit; }
$sceneIds = array_column($tour['scenes'], 'id');
$currentScene = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['currentScene'] ?? ''));
if (!in_array($currentScene, $sceneIds, true)) $currentScene = $tour['scenes'][0]['id'];
$history = array_values(array_filter(array_slice((array)($input['history'] ?? []), -AI_MAX_HISTORY), static function ($item): bool { return is_array($item) && in_array($item['role'] ?? '', ['user', 'assistant'], true) && isset($item['text']); }));
$session = is_array($input['conversationState'] ?? null) ? $input['conversationState'] : [];
$session['lastSceneId'] = $currentScene;
$session['visitorProfile'] = is_array($session['visitorProfile'] ?? null) ? $session['visitorProfile'] : [];
$session['history'] = $history;
$view = is_array($input['viewContext'] ?? null) ? $input['viewContext'] : [];
$view['visibleHotspots'] = array_slice((array)($view['visibleHotspots'] ?? []), 0, 8);
$graph = ai_graph($tour);
$scene = current(array_filter($tour['scenes'], static function (array $item) use ($currentScene): bool { return $item['id'] === $currentScene; })) ?: $tour['scenes'][0];
$nearby = array_values(array_filter($tour['hotspots'], static function (array $hotspot) use ($currentScene): bool { return $hotspot['scene'] === $currentScene; }));

/* ================================================================
 * V2 INTENT-FIRST PIPELINE
 * ================================================================ */
$place = ai_place_context($currentScene, $tour);
$intent = ai_classify_intent($message, $session);
$standaloneQuery = ai_standalone_query($message, $session, $history);
$retrievalStarted = microtime(true);
$retrieval = ai_retrieve($standaloneQuery, $session, $tour, $config, $config['reasoning_mode'] === 'fast' ? 4 : 6);
$retrievalMs = (int)((microtime(true) - $retrievalStarted) * 1000);
$grounding = array_map(static function (array $item): array { return $item['entry'] + ['relevance' => $item['score']]; }, $retrieval['items']);

// Deterministic intents: answer locally, no provider needed.
$deterministicIntents = ['CURRENT_LOCATION', 'NAVIGATION_REQUEST', 'FOLLOWUP_NAVIGATION', 'ROUTE_RECOMMENDATION'];
$pipeline = ai_v2_pipeline($message, $session, $tour, $config);
if (in_array($intent, $deterministicIntents, true)) {
    $result = $pipeline['result'];
    $result['provider'] = 'v2-state-engine';
    $result['aiRequestId'] = $requestId;
    $result['conversation_update'] = [
        'recommended_route_id' => $result['action'] === 'go_to_scene' ? null : ($session['recommendedRouteId'] ?? null),
        'referenced_scene_id' => $result['scene'] ?? null,
        'referenced_entity_id' => $result['entities'][0]['id'] ?? null,
    ];
    if ($config['debug']) $result['_debug'] = array_merge($pipeline['debug'], ['provider' => $result['provider'], 'model' => 'state-engine', 'fallbackUsed' => false, 'attempts' => [], 'providerLatencyMs' => 0, 'totalLatencyMs' => (int)((microtime(true) - $started) * 1000), 'retrievalMode' => $retrieval['mode'], 'retrievedIds' => array_column(array_column($retrieval['items'], 'entry'), 'id'), 'scores' => array_column($retrieval['items'], 'score'), 'versions' => ['prompt' => AI_PROMPT_VERSION, 'retrieval' => AI_RETRIEVAL_VERSION, 'v2' => AI_V2_VERSION]]);
    error_log('[tour-ai] request=' . $requestId . ' scene=' . $currentScene . ' intent=' . $intent . ' provider=v2-state-engine retrieval=' . count($retrieval['items']) . ' modelMs=0');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ================================================================
 * CURATED DEMO INTELLIGENCE LAYER
 * ================================================================
 * High-confidence curated answers checked BEFORE provider path.
 * Covers 15 golden demo intents with alias/keyword matching.
 */
$curatedMatch = ai_curated_match($message, $session);
// Skip curated 'nearby' intents (G08_NEARBY) so proximity/hotel queries always go to the AI provider (Gemini/Groq) and get context-aware answers.
if ($curatedMatch && ($curatedMatch['category'] ?? '') !== 'nearby') {
    $curatedResult = ai_curated_response($curatedMatch['id'], $message, $session, $tour);
    if ($curatedResult) {
        $curatedResult['aiRequestId'] = $requestId;
        $curatedResult['conversation_update'] = [
            'recommended_route_id' => null,
            'referenced_scene_id' => $curatedResult['scene'] ?? null,
            'referenced_entity_id' => $curatedResult['entities'][0]['id'] ?? null ?? null,
        ];
        // Store recommendation target if present
        if (!empty($curatedResult['recommendation_target'])) {
            $curatedResult['recommendation_target'] = $curatedResult['recommendation_target'];
        }
        if ($config['debug']) $curatedResult['_debug'] = ['intent' => $curatedMatch['id'], 'category' => $curatedMatch['category'] ?? '', 'provider' => 'curated', 'confidence' => 1.0];
        error_log('[tour-ai] request=' . $requestId . ' scene=' . $currentScene . ' curated=' . $curatedMatch['id'] . ' provider=curated');
        echo json_encode($curatedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ================================================================
 * LAWANG SEWU VISITOR TIPS DOMAIN
 * ================================================================ */
if ($intent === 'VISITOR_TIPS') {
    $tipsResult = ai_resolve_lawang_sewu_visitor_tips($message);
    if ($tipsResult) {
        $tipsResult['aiRequestId'] = $requestId;
        $tipsResult['conversation_update'] = [
            'recommended_route_id' => null,
            'referenced_scene_id' => null,
            'referenced_entity_id' => $tipsResult['entities'][0]['id'] ?? null,
        ];
        if ($config['debug']) $tipsResult['_debug'] = ['intent' => 'VISITOR_TIPS', 'provider' => 'lawang-sewu-visitor-tips', 'confidence' => $tipsResult['confidence'] ?? 0.9];
        echo json_encode($tipsResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ================================================================
 * PUBLIC SERVICE KNOWLEDGE DOMAIN
 * ================================================================
 * Handles city public service queries from public-services.json.
 * Deterministic â€” no LLM needed.
 */
if ($intent === 'PUBLIC_SERVICE') {
    $publicResult = ai_resolve_public_service($message, $session);
    if ($publicResult) {
        $publicResult['aiRequestId'] = $requestId;
        $publicResult['conversation_update'] = [
            'recommended_route_id' => null,
            'referenced_scene_id' => null,
            'referenced_entity_id' => $publicResult['entities'][0]['id'] ?? null,
        ];
        // Preserve public service state for follow-ups
        if (!empty($publicResult['_publicServiceResults'])) {
            $publicResult['session_update'] = ['lastPublicServiceResults' => $publicResult['_publicServiceResults']];
            unset($publicResult['_publicServiceResults']);
        }
        if ($config['debug']) $publicResult['_debug'] = ['intent' => 'PUBLIC_SERVICE', 'provider' => 'public-service-catalog', 'confidence' => $publicResult['confidence'] ?? 0.9];
        error_log('[tour-ai] request=' . $requestId . ' scene=' . $currentScene . ' intent=PUBLIC_SERVICE provider=public-service-catalog');
        echo json_encode($publicResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ================================================================
 * OUT-OF-DOMAIN & GREETING GUARD
 * Sapaan dijawab ramah; pertanyaan di luar topik tur ditolak sopan
 * (bukan ditempeli deskripsi scene yang tidak relevan).
 * ================================================================ */
$guardTourName = trim((string)($tour['name'] ?? $tour['tourName'] ?? '')) ?: (trim((string)(ai_tour_config()['tourName'] ?? '')) ?: 'tur ini');
if (ai_is_greeting($message)) {
    $greetResult = [
        'reply' => 'Halo! Saya pemandu virtual ' . $guardTourName . '. Saya bisa menjelaskan panorama, sejarah, fasilitas, tips kunjungan, dan membantu navigasi antar area. Mau mulai dari mana?',
        'intent' => 'GENERAL_CONVERSATION',
        'confidence' => 0.9,
        'entities' => [],
        'profile_updates' => [],
        'needs_clarification' => false,
        'clarification_options' => [],
        'actions' => [],
        'cards' => [],
        'sources' => [],
        'action' => 'none',
        'scene' => $currentScene,
        'provider' => 'greeting',
        'aiRequestId' => $requestId,
        'conversation_update' => ['recommended_route_id' => null, 'referenced_scene_id' => $currentScene, 'referenced_entity_id' => null],
    ];
    if ($config['debug']) $greetResult['_debug'] = ['intent' => 'GENERAL_CONVERSATION', 'provider' => 'greeting', 'rule' => 'ai_is_greeting'];
    error_log('[tour-ai] request=' . $requestId . ' intent=GREETING provider=greeting');
    echo json_encode($greetResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (ai_is_offtopic($message)) {
    $offResult = [
        'reply' => 'Maaf, pertanyaan itu di luar topik tur ini. Saya fokus membantu seputar ' . $guardTourName . ' â€” panorama, sejarah, fasilitas, tips, atau rute kunjungan. Ada yang ingin Anda ketahui tentang lokasi ini?',
        'intent' => 'OUT_OF_DOMAIN',
        'confidence' => 0.9,
        'entities' => [],
        'profile_updates' => [],
        'needs_clarification' => false,
        'clarification_options' => [],
        'actions' => [],
        'cards' => [],
        'sources' => [],
        'action' => 'none',
        'scene' => $currentScene,
        'provider' => 'off-topic-guard',
        'aiRequestId' => $requestId,
        'conversation_update' => ['recommended_route_id' => null, 'referenced_scene_id' => $currentScene, 'referenced_entity_id' => null],
    ];
    if ($config['debug']) $offResult['_debug'] = ['intent' => 'OUT_OF_DOMAIN', 'provider' => 'off-topic-guard', 'rule' => 'ai_is_offtopic'];
    error_log('[tour-ai] request=' . $requestId . ' intent=OUT_OF_DOMAIN provider=off-topic-guard');
    echo json_encode($offResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Provider path: all description, knowledge, conversation intents go to LLM for natural language composition.
// BUT first check if internal knowledge can answer deterministically.
$pipelineEvidence = $pipeline['result']['_retrieval'] ?? $retrieval;
$groundingEvidence = array_map(static function (array $item): array { return $item['entry'] + ['relevance' => $item['score']]; }, $pipelineEvidence['items'] ?? $retrieval['items']);
$facet = ai_classify_facet($message, $intent);

// --- PROXIMITY QUERIES: skip internal knowledge, go straight to Gemini ---
$isProximityQuery = preg_match('/(sekitar|terdekat|dekat|nearby|around|di sini|sini)/i', $message)
    && preg_match('/(masjid|gereja|rumah sakit|apotek|klinik|hotel|penginapan|restoran|cafe|kuliner|makan|makanan|masakan|oleh[- ]?oleh|souvenir|belanja|bank|atm|spbu|parkir|terminal|stasiun)/i', $message);

// --- DETERMINISTIC FALLBACK: internal knowledge first, Gemini only if needed ---
$exploreEntity = $pipeline['result']['entities'][0] ?? null;
$internalReply = null;

// Intent yang butuh penalaran/pengetahuan umum (mis. harga tiket, jam buka,
// pertanyaan faktual di luar data) TIDAK boleh dijawab dengan tempelan konten
// knowledge teratas â€” harus lewat provider agar relevan & tidak "halu".
$forceProvider = in_array($intent, ['UNKNOWN_FACT', 'FACTUAL_QUESTION'], true);

if (!$isProximityQuery && !$forceProvider) {
    // Priority 1: Explore-menu entity with description
    if ($exploreEntity && ($exploreEntity['type'] ?? '') === 'explore-place' && !empty($exploreEntity['content']) && !ai_is_stub_text((string)$exploreEntity['content'])) {
        $internalReply = $exploreEntity['content'];
    }

    // Priority 2: Knowledge entity with content from retrieval
    // (entri placeholder/template yang belum dikurasi diabaikan agar tidak "halu").
    if (!$internalReply && !empty($groundingEvidence)) {
        $topEvidence = $groundingEvidence[0];
        if (!empty($topEvidence['content']) && ($topEvidence['type'] ?? '') !== 'overview' && !ai_is_stub_text((string)$topEvidence['content'])) {
            $internalReply = $topEvidence['content'];
        }
    }

    // Priority 3: Enriched scene identity description
    if (!$internalReply) {
        $enrichedDesc = (string)($place['enrichedDescription'] ?? '');
        if ($enrichedDesc !== '' && !ai_is_stub_text($enrichedDesc) && in_array($intent, ['PLACE_DESCRIPTION', 'CURRENT_LOCATION'], true)) {
            $internalReply = $enrichedDesc;
        }
    }
}

// If internal knowledge has an answer, return it directly â€” no Gemini needed.
if ($internalReply) {
    $result = ai_compose_reply($intent, $internalReply, $place, $exploreEntity ? [$exploreEntity] : []);
    $result['provider'] = 'internal-knowledge';
    $result['aiRequestId'] = $requestId;
    $result['facet'] = $facet;
    $result['conversation_update'] = [
        'recommended_route_id' => null,
        'referenced_scene_id' => $result['scene'] ?? null,
        'referenced_entity_id' => $exploreEntity['id'] ?? null,
    ];
    if ($config['debug']) $result['_debug'] = array_merge($pipeline['debug'], ['provider' => 'internal-knowledge', 'facet' => $facet, 'model' => 'none', 'fallbackUsed' => false, 'attempts' => [], 'providerLatencyMs' => 0, 'totalLatencyMs' => (int)((microtime(true) - $started) * 1000)]);
    error_log('[tour-ai] request=' . $requestId . ' scene=' . $currentScene . ' intent=' . $intent . ' facet=' . $facet . ' provider=internal-knowledge');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ================================================================
 * FACILITY REGISTRY FAST-PATH â€” jawab fasilitas secara deterministik
 * (toilet/musala/parkir/playground, dll) tanpa menunggu provider.
 * ================================================================ */
$facility = ai_resolve_facility($message);
if ($facility) {
    $facName = trim((string)($facility['name'] ?? ''));
    $facNote = trim((string)($facility['note'] ?? ''));
    $facAvailable = !isset($facility['available']) || !empty($facility['available']);
    if ($facAvailable) {
        $facReply = ($facName !== '' ? $facName . ': ' : '') . $facNote;
    } else {
        $facReply = ($facName !== '' ? $facName . ' tidak tersedia di area tur ini. ' : 'Fasilitas tersebut tidak tersedia di area tur ini. ') . $facNote;
    }
    $facReply = trim(preg_replace('/\s+/', ' ', $facReply));
    if ($facReply === '') $facReply = $facAvailable ? 'Fasilitas tersebut tersedia di area tur ini.' : 'Fasilitas tersebut tidak tersedia di area tur ini.';
    $facResult = [
        'reply' => $facReply,
        'intent' => $facAvailable ? 'FACILITY' : 'FACILITY_UNAVAILABLE',
        'confidence' => 0.95,
        'entities' => [],
        'profile_updates' => [],
        'needs_clarification' => false,
        'clarification_options' => [],
        'actions' => [],
        'cards' => [],
        'sources' => [],
        'action' => 'none',
        'scene' => $currentScene,
        'provider' => 'facility-registry',
        'aiRequestId' => $requestId,
        'conversation_update' => [
            'recommended_route_id' => null,
            'referenced_scene_id' => $currentScene,
            'referenced_entity_id' => null,
        ],
    ];
    if ($config['debug']) $facResult['_debug'] = ['intent' => $facResult['intent'], 'provider' => 'facility-registry', 'facilityId' => (string)($facility['id'] ?? '')];
    error_log('[tour-ai] request=' . $requestId . ' scene=' . $currentScene . ' intent=' . $facResult['intent'] . ' provider=facility-registry');
    echo json_encode($facResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- GEMINI FALLBACK: only for questions internal knowledge can't answer ---
$guideCtx = [
    'intent' => $intent,
    'facet' => $facet,
    'placeName' => (string)($place['currentPlaceName'] ?? ''),
    'sceneId' => $currentScene,
    'subjectName' => (string)($pipeline['result']['entities'][0]['name'] ?? $session['lastEntity'] ?? ''),
    'navTarget' => (string)($pipeline['result']['scene'] ?? ''),
    'sceneIds' => $sceneIds,
    'routes' => (array)($tour['routes'] ?? []),
    'language' => strtolower((string)($view['language'] ?? $session['visitorProfile']['language'] ?? 'id')),
    'tour' => $tour,
    'tourName' => (string)($tour['name'] ?? $tour['tourName'] ?? ''),
    'tourLocation' => (string)($tour['location'] ?? $tour['area'] ?? ''),
    'nearbyLabel' => (string)($tour['nearbyLabel'] ?? $tour['location'] ?? ''),
];
$system = ai_build_guide_system_prompt($guideCtx);

// Enriched scene identity context for provider
$enrichedIdentity = ai_get_enriched_description($currentScene);
$enrichedContext = [];
if ((string)($enrichedIdentity['description'] ?? '') !== '') {
    $enrichedContext['SCENE_DESCRIPTION'] = $enrichedIdentity['description'];
}
if (!empty($enrichedIdentity['highlights'])) {
    $enrichedContext['HIGHLIGHTS'] = $enrichedIdentity['highlights'];
}
if (!empty($enrichedIdentity['talkingPoints'])) {
    $enrichedContext['TALKING_POINTS'] = $enrichedIdentity['talkingPoints'];
}
if (!empty($enrichedIdentity['relatedKnowledgeIds'])) {
    $enrichedContext['RELATED_KNOWLEDGE_IDS'] = $enrichedIdentity['relatedKnowledgeIds'];
}
if ((string)($enrichedIdentity['contentGrounding'] ?? '') !== '') {
    $enrichedContext['CONTENT_GROUNDING'] = $enrichedIdentity['contentGrounding'];
}

// Named place resolution: if query mentions a place name, resolve its identity
$namedPlace = ai_find_place_by_visitor_name($message);
$namedPlaceContext = null;
if ($namedPlace) {
    $namedPlaceContext = [
        'PLACE_NAME' => (string)($namedPlace['name'] ?? ''),
        'PLACE_DESCRIPTION' => (string)($namedPlace['description'] ?? ''),
        'PLACE_HIGHLIGHTS' => (array)($namedPlace['highlights'] ?? []),
        'PLACE_TALKING_POINTS' => (array)($namedPlace['talkingPoints'] ?? []),
    ];
}
// Explore-menu entity: inject as named place context for LLM composition
$exploreEntity = $pipeline['result']['entities'][0] ?? null;
if ($exploreEntity && ($exploreEntity['type'] ?? '') === 'explore-place' && !empty($exploreEntity['content'])) {
    $namedPlaceContext = [
        'PLACE_NAME' => (string)($exploreEntity['title'] ?? ''),
        'PLACE_DESCRIPTION' => (string)($exploreEntity['content'] ?? ''),
        'PLACE_HIGHLIGHTS' => [],
        'PLACE_TALKING_POINTS' => [],
        'SOURCE' => 'explore-menu',
    ];
}

$nearbyIntent = in_array($intent, ['NEARBY_SEARCH', 'FACILITY'], true)
    || (bool)preg_match('/\b(hotel|penginapan|restoran|rumah makan|kafe|cafe|kuliner|makanan|masakan|oleh[- ]?oleh|souvenir|suvenir|belanja|transportasi|halte|brt|terminal|stasiun|bandara)\b/i', $message);
$localNearbyResult = $nearbyIntent ? ai_resolve_lawang_sewu_nearby($message) : null;

$userPromptParts = [
    'QUESTION' => $message,
    'INTENT' => $intent,
    'CURRENT_PLACE' => ['currentSceneId' => $currentScene, 'currentPlaceId' => $place['currentPlaceId'], 'currentPlaceName' => $place['currentPlaceName']],
    'CURRENT_TOUR_STATE' => ['scene' => $scene, 'nearbyHotspots' => $nearby, 'activeRouteId' => $session['activeRouteId'] ?? null, 'recommendedRouteId' => $session['recommendedRouteId'] ?? null, 'lastReferencedDestination' => $session['lastReferencedDestination'] ?? null, 'lastNavigableSceneId' => $session['lastNavigableSceneId'] ?? null],
    'VISITOR_STATE' => $session['visitorProfile'],
    'SESSION_CONTEXT' => ['lastIntent' => $session['lastIntent'] ?? null, 'lastEntity' => $session['lastEntity'] ?? null, 'lastReply' => $session['lastReply'] ?? null],
    'VIEW_CONTEXT' => $view,
    'CONVERSATION' => $history,
    'VERIFIED_FACTS' => $groundingEvidence,
    'AVAILABLE_UI' => $tour['ui'],
];
if ($localNearbyResult) $userPromptParts['LOCAL_NEARBY_FALLBACK'] = ['reply' => $localNearbyResult['reply'], 'recommendations' => $localNearbyResult['recommendations'] ?? [], 'disclaimer' => ai_load_lawang_sewu_nearby()['disclaimer'] ?? ''];
if ($enrichedContext) $userPromptParts['ENRICHED_SCENE_CONTEXT'] = $enrichedContext;
if ($namedPlaceContext) $userPromptParts['NAMED_PLACE_CONTEXT'] = $namedPlaceContext;
$userPrompt = json_encode($userPromptParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$modelStarted = microtime(true);
$providerResult = ai_provider_router([
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $userPrompt],
], ['provider' => getenv('AI_PROVIDER') ?: 'groq', 'model' => ai_provider_model(strtolower(trim(getenv('AI_PROVIDER') ?: 'groq'))), 'temperature' => $config['temperature'], 'max_tokens' => $config['num_predict'], 'json_mode' => true, 'system' => $system, 'user' => $userPrompt, 'timeout' => (int)(getenv('AI_REQUEST_TIMEOUT') ?: 25)]);
$modelMs = (int)((microtime(true) - $modelStarted) * 1000);
$content = (string)($providerResult['content'] ?? '');
$aiData = ai_parse_json($content);

// Log provider result for debugging
if (!$providerResult['success'] || !$aiData) {
    error_log('[tour-ai-debug] provider=' . ($providerResult['provider'] ?? 'none') . ' success=' . (($providerResult['success'] ?? false) ? '1' : '0') . ' error=' . ($providerResult['error'] ?? 'none') . ' content_len=' . mb_strlen($content) . ' http=' . ($providerResult['http_code'] ?? 0));
    if ($content !== '') error_log('[tour-ai-debug] raw_content=' . substr($content, 0, 300));
}

// Deterministic enriched fallback: use scene-identity.json or explore-menu when provider fails
$enrichedFallback = null;
// Priority 1: explore-menu entity description (explicit named subject)
if ($exploreEntity && ($exploreEntity['type'] ?? '') === 'explore-place' && !empty($exploreEntity['content']) && !ai_is_stub_text((string)$exploreEntity['content'])) {
    $enrichedFallback = [
        'reply' => $exploreEntity['content'],
        'intent' => $intent,
        'confidence' => 0.9,
        'entities' => [],
        'profile_updates' => [],
        'needs_clarification' => false,
        'clarification_options' => [],
        'actions' => [],
        'cards' => [],
        'sources' => [],
        'action' => 'none',
        'scene' => $currentScene,
        'hotspot' => null,
        'route' => null,
        'provider' => 'explore-menu',
        'conversation_update' => [
            'recommended_route_id' => null,
            'referenced_scene_id' => $currentScene,
            'referenced_entity_id' => null,
        ],
    ];
}
// Priority 2: current scene enriched description
if (!$enrichedFallback) {
    $enrichedDesc = (string)($enrichedIdentity['description'] ?? '');
    // Priority 3: named place description (for "Ceritakan [place name]" queries)
    if ($enrichedDesc === '' && $namedPlace) {
        $enrichedDesc = (string)($namedPlace['description'] ?? '');
    }
    if ($enrichedDesc !== '' && !ai_is_stub_text($enrichedDesc)) {
        $enrichedFallback = [
            'reply' => $enrichedDesc,
            'intent' => $intent,
            'confidence' => 0.85,
            'entities' => [],
            'profile_updates' => [],
            'needs_clarification' => false,
            'clarification_options' => [],
            'actions' => [],
            'cards' => [],
            'sources' => [],
            'action' => 'none',
            'scene' => $currentScene,
            'hotspot' => null,
            'route' => null,
            'provider' => 'enriched-identity',
            'conversation_update' => [
                'recommended_route_id' => null,
                'referenced_scene_id' => $currentScene,
                'referenced_entity_id' => null,
            ],
        ];
    }
}

if ($aiData) {
    $validated = ai_validate_provider_response($aiData, $tour, $pipelineEvidence, $currentScene);
    if ($validated !== null) {
        $result = $validated;
        $result['intent'] = $intent;
        // Recommendations only valid for nearby searches; strip AI-provided ones otherwise.
        if ($intent !== 'NEARBY_SEARCH') {
            $result['recommendations'] = [];
        }
        $result['provider'] = (string)($providerResult['provider'] ?? 'ollama');
        if ($localNearbyResult && empty($result['recommendations'])) {
            $result['recommendations'] = $localNearbyResult['recommendations'] ?? [];
            $result['actions'] = $localNearbyResult['actions'] ?? [];
        }
        // Preserve recommendation_target from pipeline if present.
        if (isset($pipeline['result']['recommendation_target'])) {
            $result['recommendation_target'] = $pipeline['result']['recommendation_target'];
        }
        // Ensure conversation_update is set.
        if (!isset($result['conversation_update'])) {
            $result['conversation_update'] = [
                'recommended_route_id' => $result['action'] === 'go_to_scene' ? null : ($session['recommendedRouteId'] ?? null),
                'referenced_scene_id' => $result['scene'] ?? null,
                'referenced_entity_id' => $result['entities'][0]['id'] ?? null,
            ];
        }
        // --- CATALOG CROSS-REFERENCE: check if reply mentions destinations with virtual tours ---
        $replyText = (string)($result['reply'] ?? '');
        if ($replyText !== '' && $isProximityQuery) {
            $catalogMatches = ai_cross_reference_catalog($replyText, [(string)($place['currentPlaceName'] ?? '')]);
            if (!empty($catalogMatches)) {
                $tourOffers = [];
                foreach ($catalogMatches as $cm) {
                    if (!empty($cm['virtualTourUrl'])) {
                        $tourOffers[] = $cm['name'];
                    }
                }
                if (!empty($tourOffers)) {
                    $result['reply'] = $replyText . "\n\nBeberapa di antaranya memiliki tur virtual: " . implode(', ', $tourOffers) . ". Mau saya buka salah satu?";
                    $result['_catalogMatches'] = $catalogMatches;
                }
            }
        }
    } elseif ($enrichedFallback && !$isProximityQuery) {
        $result = $enrichedFallback;
    } else {
        // Provider returned invalid JSON â€” try to use raw text as reply
        $rawReply = trim($content);
        if ($rawReply !== '' && mb_strlen($rawReply) > 10) {
            $result = ai_compose_reply($intent, $rawReply, $place, $exploreEntity ? [$exploreEntity] : []);
            $result['provider'] = (string)($providerResult['provider'] ?? 'unknown') . '-raw';
        } else {
            $result = ai_graceful_reply($intent, $message, $place, $isProximityQuery);
            $result['provider'] = $isProximityQuery ? 'graceful-nearby' : 'graceful-fallback';
        }
        $result['conversation_update'] = [
            'recommended_route_id' => $result['action'] === 'go_to_scene' ? null : ($session['recommendedRouteId'] ?? null),
            'referenced_scene_id' => $result['scene'] ?? null,
            'referenced_entity_id' => $result['entities'][0]['id'] ?? null,
        ];
    }
} elseif ($localNearbyResult && ($isProximityQuery || $nearbyIntent)) {
    // Pertanyaan sekitar (termasuk kuliner/oleh-oleh) yang provider-nya gagal:
    // pakai data nearby deterministik, bukan deskripsi scene yang tak relevan.
    $result = $localNearbyResult;
    $result['provider'] = 'nearby-local';
} elseif ($enrichedFallback && !$isProximityQuery) {
    $result = $enrichedFallback;
} elseif ($localNearbyResult) {
    $result = $localNearbyResult;
    $result['provider'] = 'nearby-local';
} else {
    $result = ai_graceful_reply($intent, $message, $place, $isProximityQuery);
    $result['provider'] = $isProximityQuery ? 'graceful-nearby' : 'graceful-fallback';
    $result['conversation_update'] = [
        'recommended_route_id' => $result['action'] === 'go_to_scene' ? null : ($session['recommendedRouteId'] ?? null),
        'referenced_scene_id' => $result['scene'] ?? null,
        'referenced_entity_id' => $result['entities'][0]['id'] ?? null,
    ];
}

/* ================================================================
 * WEB SEARCH FALLBACK (Gemini Google Search grounding)
 * Dipakai hanya untuk pertanyaan informasi yang TIDAK terjawab knowledge
 * internal (atau saat provider utama gagal). Hasil + sumber web dikirim
 * ke UI (web_search_used + sources).
 * ================================================================ */
$webEligible = !in_array($intent, ['NAVIGATION_REQUEST', 'FOLLOWUP_NAVIGATION', 'ROUTE_RECOMMENDATION', 'CURRENT_LOCATION', 'FACILITY', 'NEARBY_SEARCH', 'VISITOR_TIPS', 'PUBLIC_SERVICE'], true);
$providerWeak = in_array((string)($result['provider'] ?? ''), ['graceful-fallback', 'graceful-nearby', 'provider_unavailable'], true) || (float)($result['confidence'] ?? 1) <= 0.45;
$noInternal = count($groundingEvidence) === 0;
// Budget total respons: sisa waktu dihitung agar tidak melewati timeout klien.
$budgetMs = (int)(getenv('AI_TOTAL_BUDGET_MS') ?: 12000);
$remainingMs = $budgetMs - (int)((microtime(true) - $started) * 1000);
if ($webEligible && ($noInternal || $providerWeak) && $remainingMs > 2500) {
    $searchSystem = 'Anda asisten pemandu wisata berbahasa Indonesia yang cerdas dan ramah. Jawab pertanyaan pengguna secara ringkas (2-5 kalimat), akurat, dan mudah dipahami. Manfaatkan hasil pencarian web untuk fakta terkini. '
        . 'Konteks: tur "' . (string)($guideCtx['tourName'] ?? '') . '"'
        . (($guideCtx['placeName'] ?? '') !== '' ? ', lokasi saat ini "' . $guideCtx['placeName'] . '"' : '')
        . '. Jangan mengarang; jika tidak yakin, katakan dengan jujur.';
    // Fallback web search berlapis: Groq â†’ OpenRouter â†’ Gemini (urutan via env).
    // Timeout dibatasi oleh sisa budget agar tidak menabrak timeout klien.
    $wsTimeout = max(2, min((int)(getenv('AI_WEB_SEARCH_TIMEOUT') ?: 20), (int)floor($remainingMs / 1000)));
    $ws = ai_web_search_backup($message, $searchSystem, $wsTimeout);
    if ($ws && trim((string)$ws['reply']) !== '') {
        $result['reply'] = (string)$ws['reply'];
        $result['sources'] = (array)($ws['sources'] ?? []);
        $result['web_search_used'] = true;
        $result['provider'] = (string)($ws['provider'] ?? 'web-search');
        $result['confidence'] = max((float)($result['confidence'] ?? 0), 0.65);
    }
}

$result['reply'] = ai_strip_leading_greeting((string)($result['reply'] ?? ''));
$result['aiRequestId'] = $requestId;
if ($config['debug']) $result['_debug'] = array_merge($pipeline['debug'], ['provider' => $result['provider'], 'model' => (string)($providerResult['model'] ?? ''), 'fallbackUsed' => (bool)($providerResult['fallback_used'] ?? false), 'attempts' => $providerResult['attempted_providers'] ?? [], 'providerLatencyMs' => (int)($providerResult['latency_ms'] ?? 0), 'totalLatencyMs' => (int)((microtime(true) - $started) * 1000), 'retrievalMode' => $retrieval['mode'], 'retrievedIds' => array_column(array_column($pipelineEvidence['items'] ?? [], 'entry'), 'id'), 'scores' => array_column($pipelineEvidence['items'] ?? [], 'score'), 'versions' => ['prompt' => AI_PROMPT_VERSION, 'retrieval' => AI_RETRIEVAL_VERSION, 'v2' => AI_V2_VERSION], 'sceneIdentityLoaded' => !empty($enrichedIdentity['found']), 'descriptionSource' => $result['provider'] === 'enriched-identity' ? 'enriched-identity' : 'provider']);
error_log('[tour-ai] request=' . $requestId . ' scene=' . $currentScene . ' intent=' . $intent . ' provider=' . $result['provider'] . ' retrieval=' . count($pipelineEvidence['items'] ?? []) . ' modelMs=' . $modelMs);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
