<?php

declare(strict_types=1);

/* PHP 7.x compatibility polyfills for PHP 8 string functions */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

require_once __DIR__ . '/guide-policy.php';

/* ================================================================
 * ENVIRONMENT CONFIGURATION
 * ================================================================
 * 1. Try loading .env file
 * 2. Fallback to hardcoded config.php
 */
(function () {
    $envFile = dirname(__DIR__) . '/.env';
    $loaded = false;
    if (is_file($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (strpos($line, '=') === false) continue;
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') $value = substr($value, 1, -1);
                elseif (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") $value = substr($value, 1, -1);
                if (!isset($_ENV[$key]) && getenv($key) === false) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                }
            }
            $loaded = true;
        }
    }
    // Fallback: hardcoded config
    if (!$loaded || getenv('GEMINI_API_KEY') === false || getenv('GEMINI_API_KEY') === '') {
        require_once __DIR__ . '/config.php';
    }
})();

const AI_PROMPT_VERSION = '2.0.0';
const AI_RETRIEVAL_VERSION = '2.0.0';
const AI_KNOWLEDGE_INDEX_VERSION = '2';
const AI_V2_VERSION = '2.0.0';
const AI_MAX_HISTORY = 12;
const AI_MAX_CONTEXT_ITEMS = 80;
const AI_ALLOWED_ACTIONS = ['none', 'go_to_scene', 'focus_hotspot', 'show_hotspot_info', 'start_route', 'continue_route', 'show_facility', 'open_information', 'open_explore', 'open_menu', 'open_category', 'play_360', 'set_language'];

/* ================================================================
 * CURATED DEMO INTELLIGENCE LAYER
 * ================================================================
 * High-confidence curated answers for demo presentation.
 * Matched BEFORE normal AI pipeline but AFTER deterministic UI/navigation.
 */

function ai_load_curated_answers(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $file = dirname(__DIR__) . '/data/curated-answers.json';
    if (!is_file($file)) {
        error_log('[tour-ai] curated-answers.json NOT FOUND at: ' . $file);
        return $cache = [];
    }
    $raw = json_decode((string)@file_get_contents($file), true);
    if (!is_array($raw) || empty($raw['intents'])) {
        error_log('[tour-ai] curated-answers.json PARSE FAILED or empty');
        return $cache = [];
    }
    return $cache = (array)($raw['intents'] ?? []);
}

function ai_load_flow(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $file = dirname(__DIR__) . '/data/flow.json';
    if (!is_file($file)) return $cache = [];
    $raw = json_decode((string)@file_get_contents($file), true);
    if (!is_array($raw) || empty($raw['order'])) return $cache = [];
    return $cache = $raw;
}

function ai_flow_next(string $currentSceneId, array $flow, array $visited = []): ?array
{
    $order = array_values((array)($flow['order'] ?? []));
    if (!$order) return null;
    $nodes = (array)($flow['nodes'] ?? []);
    $pos = array_search($currentSceneId, $order, true);
    if ($pos === false) $pos = -1;
    $seen = array_flip(array_map('strval', $visited));
    $n = count($order);
    // Walk forward, skipping visited scenes so user never loops back.
    for ($k = 1; $k <= $n; $k++) {
        $idx = ($pos + $k) % $n;
        $sid = (string)$order[$idx];
        if ($sid === $currentSceneId) continue;
        if (isset($seen[$sid])) continue;
        return ['sceneId' => $sid, 'label' => (string)($nodes[$sid]['displayName'] ?? ''), 'navigable' => true];
    }
    return null;
}

function ai_curated_normalize(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\w\s]/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function ai_curated_match(string $message, array $session = []): ?array
{
    $intents = ai_load_curated_answers();
    if (!$intents) return null;

    $normalized = ai_curated_normalize($message);
    if ($normalized === '') return null;

    // Check negative patterns first (navigation words should not trigger informational intents)
    $hasNavigationWords = (bool) preg_match('/\b(bawa saya|antar saya|pergi ke|menuju|pindah ke|ajak saya|ke sana|kesana|ke situ)\b/i', $message);
    $hasInfoWords = (bool) preg_match('/\b(apa|siapa|kenapa|mengapa|bagaimana|kapan|berapa|dimana|di mana|ceritakan|jelaskan|deskripsikan|tentang|arti|makna|fungsi|sejarah)\b/i', $message);

    // Navigation intent always wins when user explicitly asks to move
    if ($hasNavigationWords && !$hasInfoWords) {
        // Check navigation curated intents only
        foreach ($intents as $intent) {
            if (($intent['category'] ?? '') !== 'navigation') continue;
            if (isset($intent['requiresState'])) {
                $stateKey = $intent['requiresState'];
                if (empty($session[$stateKey])) continue;
            }
            foreach ($intent['aliases'] ?? [] as $alias) {
                $normAlias = ai_curated_normalize($alias);
                if ($normAlias !== '' && mb_strlen($normAlias) >= 3 && $normalized === $normAlias) {
                    return $intent;
                }
            }
        }
    }

    // Score each intent
    $best = null;
    $bestScore = 0;

    foreach ($intents as $intent) {
        $score = 0;

        // Check aliases (exact normalized match = highest score)
        foreach ($intent['aliases'] ?? [] as $alias) {
            $normAlias = ai_curated_normalize($alias);
            if ($normAlias === '' || mb_strlen($normAlias) < 3) continue;

            if ($normalized === $normAlias) {
                $score = max($score, 100);
                break;
            }
            // Substring match
            if (mb_strlen($normAlias) >= 5 && str_contains($normalized, $normAlias)) {
                $score = max($score, 80 + (mb_strlen($normAlias) / mb_strlen($normalized)) * 10);
            }
            // Reverse substring (alias contained in query)
            if (mb_strlen($normalized) >= 5 && str_contains($normAlias, $normalized)) {
                $score = max($score, 60);
            }
        }

        // Check keyword combinations (all keywords must be present)
        if ($score < 60 && !empty($intent['keywords'])) {
            $allPresent = true;
            foreach ($intent['keywords'] as $kw) {
                $normKw = ai_curated_normalize($kw);
                if ($normKw === '' || !str_contains($normalized, $normKw)) {
                    $allPresent = false;
                    break;
                }
            }
            if ($allPresent) {
                $score = max($score, 50 + count($intent['keywords']) * 5);
            }
        }

        // Check negative patterns (reduce score)
        foreach ($intent['negativePatterns'] ?? [] as $neg) {
            $normNeg = ai_curated_normalize($neg);
            if ($normNeg !== '' && str_contains($normalized, $normNeg)) {
                $score = max(0, $score - 30);
            }
        }

        // Check state requirements
        if ($score > 0 && isset($intent['requiresState'])) {
            $stateKey = $intent['requiresState'];
            if (empty($session[$stateKey])) {
                $score = 0;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $intent;
        }
    }

    // Only return if confidence is high enough
    return ($best !== null && $bestScore >= 45) ? $best : null;
}

function ai_curated_resolve_place(string $sceneId, array $tour = []): array
{
    $map = ai_load_place_map();
    $scene = $map['scenes'][$sceneId] ?? null;
    $placeId = $scene['placeId'] ?? '';
    $placeDef = $map['places'][$placeId] ?? null;
    return [
        'sceneId' => $sceneId,
        'placeId' => $placeId,
        'placeName' => $placeDef['placeDisplayName'] ?? ($scene['placeDisplayName'] ?? ''),
        'description' => $placeDef['description'] ?? '',
    ];
}

function ai_curated_recommend_destination(string $currentSceneId, array $tour): ?array
{
    $graph = ai_graph($tour);
    $neighbors = $graph[$currentSceneId] ?? [];
    $map = ai_load_place_map();
    $currentPlace = $map['scenes'][$currentSceneId]['placeId'] ?? '';
    $candidates = [];
    foreach ($neighbors as $sceneId) {
        $sceneDef = $map['scenes'][$sceneId] ?? null;
        if (!$sceneDef) continue;
        if ($sceneDef['placeId'] === $currentPlace) continue;
        $candidates[] = ['sceneId' => $sceneId, 'placeId' => $sceneDef['placeId'], 'placeName' => $sceneDef['placeDisplayName'] ?? $sceneId];
    }
    if (!$candidates) return null;
    $seen = [];
    $unique = [];
    foreach ($candidates as $c) {
        if (!isset($seen[$c['placeId']])) { $seen[$c['placeId']] = true; $unique[] = $c; }
    }
    return $unique[0] ?? $candidates[0];
}

function ai_curated_response(string $intentId, string $message, array $session, array $tour): ?array
{
    $intents = ai_load_curated_answers();
    $intent = null;
    foreach ($intents as $i) {
        if (($i['id'] ?? '') === $intentId) { $intent = $i; break; }
    }
    if (!$intent) return null;

    $currentSceneId = (string)($session['lastSceneId'] ?? '');
    $place = ai_curated_resolve_place($currentSceneId, $tour);
    $tourName = (string)($tour['name'] ?? ai_tour_config()['tourName'] ?? 'tur ini');
    $placeName = $place['placeName'] ?: ('Area utama ' . $tourName);

    switch ($intentId) {
        case 'G01_CURRENT_LOCATION':
            $placeDesc = $place['description'] ?: "salah satu area yang dapat dijelajahi dalam tur virtual {$tourName}.";
            $reply = "Anda sekarang berada di {$placeName}. {$placeDesc} Dari sini, Anda dapat mengenal area sekitar sekaligus melanjutkan perjalanan ke bagian lain {$tourName}.";
            return ['reply' => $reply, 'intent' => 'CURRENT_LOCATION', 'confidence' => 1.0, 'action' => 'none', 'entities' => [], 'provider' => 'curated'];

        case 'G02_AREA_DESCRIPTION':
            $desc = $place['description'] ?: '';
            $reply = "Saat ini Anda sedang menjelajahi {$placeName} di {$tourName}. Area ini merupakan bagian dari pengalaman tur virtual {$tourName}.";
            if ($desc !== '') $reply .= " " . $desc;
            $reply .= " Jika Anda ingin, saya juga bisa menceritakan objek menarik di sekitar atau merekomendasikan area berikutnya.";
            return ['reply' => $reply, 'intent' => 'PLACE_DESCRIPTION', 'confidence' => 1.0, 'action' => 'none', 'entities' => [], 'provider' => 'curated'];

        case 'G03_TOUR_OVERVIEW':
        case 'G04_NAME_ORIGIN':
        case 'G05_GEDUNG_A':
        case 'G06_TUGU_MARMER':
        case 'G07_NIS':
        case 'G08_COLONIAL_ARCHITECTURE':
        case 'G09_STAINED_GLASS':
        case 'G10_RAILWAY_HISTORY':
        case 'G11_SEMARANG_IMPORTANCE':
        case 'G05_PHOTO_SPOTS':
        case 'G06_ACTIVITIES':
        case 'G07_BEST_TIME':
        case 'G08_NEARBY':
        case 'G09_TIPS':
            $reply = $intent['response'] ?? '';
            if ($reply === '') return null;
            return ['reply' => $reply, 'intent' => 'ENTITY_DESCRIPTION', 'confidence' => 1.0, 'action' => 'none', 'entities' => [], 'provider' => 'curated'];

        case 'G10_ROUTE_RECOMMENDATION':
        case 'G11_FOLLOWUP_NAVIGATION':
        case 'G12_ROUTE_RECOMMENDATION':
        case 'G13_FOLLOWUP_NAVIGATION':
            return ai_curated_navigation($intentId, $session, $tour, $placeName, $tourName);

        case 'G14_NAVIGATION_RAILWAY_GALLERY':
        case 'G15_NAVIGATION_GEDUNG_D':
            $destPlaceId = $intent['destinationPlaceId'] ?? '';
            $map = ai_load_place_map();
            $destPlace = $map['places'][$destPlaceId] ?? null;
            if (!$destPlace) return null;
            $destName = $destPlace['placeDisplayName'] ?? $destPlaceId;
            $variantGroup = $destPlace['variantGroup'] ?? [];
            $destSceneId = $variantGroup[0] ?? '';
            if ($destSceneId === '') return null;
            $reply = "Tentu. Mari kita menuju {$destName}.";
            if ($intentId === 'G14_NAVIGATION_RAILWAY_GALLERY') {
                $reply = "Tentu. Mari kita menuju {$destName}. Di sana Anda dapat melanjutkan eksplorasi koleksi yang menjadi bagian penting dari cerita {$tourName}.";
            }
            return [
                'reply' => $reply,
                'intent' => 'NAVIGATION_REQUEST',
                'confidence' => 1.0,
                'action' => 'go_to_scene',
                'scene' => $destSceneId,
                'entities' => [['type' => 'destination', 'id' => $destPlaceId, 'name' => $destName]],
                'provider' => 'curated',
            ];
    }

    return null;
}

function ai_curated_navigation(string $intentId, array $session, array $tour, string $placeName, string $tourName): ?array
{
    if ($intentId === 'G10_ROUTE_RECOMMENDATION' || $intentId === 'G12_ROUTE_RECOMMENDATION') {
        $currentSceneId = (string)($session['lastSceneId'] ?? '');
        $rec = ai_curated_recommend_destination($currentSceneId, $tour);
        if ($rec) {
            $reply = "Dari {$placeName}, saya menyarankan melanjutkan ke {$rec['placeName']}. Area tersebut memberikan pengalaman berbeda dalam menjelajahi {$tourName}. Kalau berkenan, saya bisa langsung membawa Anda ke sana.";
            return [
                'reply' => $reply,
                'intent' => 'ROUTE_RECOMMENDATION',
                'confidence' => 1.0,
                'action' => 'none',
                'entities' => [],
                'provider' => 'curated',
                'recommendation_target' => ['sceneId' => $rec['sceneId'], 'placeId' => $rec['placeId'], 'label' => $rec['placeName'], 'sourceSceneId' => $currentSceneId, 'navigable' => true],
            ];
        }
        return null;
    }
    // G11/G13 followup navigation
    $target = $session['lastRecommendedDestination'] ?? null;
    if (!$target || empty($target['sceneId'])) return null;
    $targetPlace = $target['label'] ?? 'tujuan sebelumnya';
    return [
        'reply' => "Baik, mari kita lanjut ke {$targetPlace}.",
        'intent' => 'FOLLOWUP_NAVIGATION',
        'confidence' => 1.0,
        'action' => 'go_to_scene',
        'scene' => $target['sceneId'],
        'entities' => [],
        'provider' => 'curated',
    ];
}

function ai_load_env(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $file = dirname(__DIR__) . '/.env';
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === '' || getenv($key) !== false) continue;
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

ai_load_env();

function ai_tour_config(): array
{
    static $config;
    if ($config !== null) return $config;
    $raw = json_decode((string)@file_get_contents(dirname(__DIR__) . '/config/tour.json'), true);
    return $config = is_array($raw) ? $raw : [];
}

function ai_config_terms(string $key, array $fallback): string
{
    $terms = (array)(ai_tour_config()[$key] ?? $fallback);
    $terms = array_values(array_filter(array_map(static fn($term): string => trim((string)$term), $terms)));
    return implode('|', array_map(static fn(string $term): string => preg_quote($term, '/'), $terms ?: $fallback));
}

function ai_config(): array
{
    return [
        'ollama_url' => rtrim(getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434', '/'),
        'chat_model' => getenv('OLLAMA_MODEL') ?: 'qwen2.5:7b',
        'embed_model' => getenv('OLLAMA_EMBED_MODEL') ?: 'nomic-embed-text',
        'reasoning_mode' => in_array(getenv('AI_REASONING_MODE'), ['fast', 'balanced', 'deep'], true) ? getenv('AI_REASONING_MODE') : 'balanced',
        'num_predict' => max(256, min(1200, (int)(getenv('OLLAMA_NUM_PREDICT') ?: 600))),
        'temperature' => max(0.0, min(0.5, (float)(getenv('OLLAMA_TEMPERATURE') ?: 0.2))),
        'debug' => filter_var(getenv('AI_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    ];
}

function ai_normalize(string $value): string
{
    $value = mb_strtolower($value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $value));
}

function ai_tokens(string $value): array
{
    static $stop = ['yang', 'dan', 'atau', 'dari', 'untuk', 'saya', 'anda', 'ada', 'apa', 'ini', 'itu', 'ke', 'di', 'the', 'and', 'for', 'what', 'where'];
    return array_values(array_unique(array_filter(explode(' ', ai_normalize($value)), static function (string $v) use ($stop): bool { return strlen($v) >= 2 && !in_array($v, $stop, true); })));
}

function ai_similarity(string $a, string $b): float
{
    $a = ai_normalize($a); $b = ai_normalize($b);
    if ($a === '' || $b === '') return 0.0;
    if (str_contains($a, $b) || str_contains($b, $a)) return min(1.0, min(strlen($a), strlen($b)) / max(1, max(strlen($a), strlen($b))) + 0.35);
    $distance = levenshtein(substr($a, 0, 255), substr($b, 0, 255));
    return max(0.0, 1.0 - ($distance / max(strlen($a), strlen($b))));
}

function ai_post_json(string $url, array $payload, int $timeout = 25): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $timeout]);
    $response = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!is_string($response) || $status < 200 || $status >= 300) return null;
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function ai_canonical_tour(array $raw): array
{
    $scenes = []; $sceneIds = [];
    foreach (array_slice((array)($raw['scenes'] ?? []), 0, AI_MAX_CONTEXT_ITEMS) as $scene) {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($scene['id'] ?? ''));
        if ($id === '') continue;
        $sceneIds[$id] = true;
        $scenes[] = ['id' => $id, 'name' => mb_substr((string)($scene['name'] ?? $id), 0, 100), 'rawName' => mb_substr((string)($scene['rawName'] ?? ''), 0, 100), 'displayName' => mb_substr((string)($scene['displayName'] ?? ''), 0, 100), 'aliases' => array_slice(array_map('strval', (array)($scene['aliases'] ?? [])), 0, 20), 'description' => mb_substr((string)($scene['description'] ?? ''), 0, 500), 'highlights' => array_slice((array)($scene['highlights'] ?? []), 0, 12), 'facilities' => array_slice((array)($scene['facilities'] ?? []), 0, 12), 'whoFor' => array_slice((array)($scene['whoFor'] ?? []), 0, 12)];
    }
    $hotspots = [];
    foreach (array_slice((array)($raw['hotspots'] ?? []), 0, AI_MAX_CONTEXT_ITEMS * 2) as $hotspot) {
        $scene = (string)($hotspot['scene'] ?? ''); $target = (string)($hotspot['target'] ?? '');
        if (!isset($sceneIds[$scene], $sceneIds[$target])) continue;
        $hotspots[] = ['scene' => $scene, 'title' => mb_substr((string)($hotspot['title'] ?? ''), 0, 100), 'target' => $target, 'pan' => (float)($hotspot['pan'] ?? 0), 'tilt' => (float)($hotspot['tilt'] ?? 0)];
    }
    $routes = [];
    foreach (array_slice((array)($raw['routes'] ?? []), 0, 30) as $route) {
        $ids = array_values(array_filter((array)($route['scenes'] ?? []), static function ($id) use ($sceneIds): bool { return isset($sceneIds[(string)$id]); }));
        if (!$ids) continue;
        $routes[] = ['id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($route['id'] ?? '')), 'name' => mb_substr((string)($route['name'] ?? ''), 0, 100), 'aliases' => array_slice((array)($route['aliases'] ?? []), 0, 20), 'scenes' => $ids];
    }
    return ['name' => mb_substr((string)($raw['name'] ?? ''), 0, 60), 'scenes' => $scenes, 'hotspots' => $hotspots, 'routes' => $routes, 'video360' => array_slice((array)($raw['video360'] ?? []), 0, 20), 'ui' => array_slice((array)($raw['ui'] ?? []), 0, 20)];
}

function ai_graph(array $tour): array
{
    $graph = [];
    foreach ($tour['scenes'] as $scene) $graph[$scene['id']] = [];
    foreach ($tour['hotspots'] as $edge) if ($edge['scene'] !== $edge['target']) $graph[$edge['scene']][] = $edge['target'];
    return array_map('array_values', array_map('array_unique', $graph));
}

function ai_find_path(array $graph, string $from, string $to): array
{
    if ($from === $to && isset($graph[$from])) return [$from];
    if (!isset($graph[$from], $graph[$to])) return [];
    $queue = [[$from]]; $seen = [$from => true];
    while ($queue) {
        $path = array_shift($queue); $node = end($path);
        foreach ($graph[$node] ?? [] as $next) {
            if (isset($seen[$next])) continue;
            $candidate = [...$path, $next];
            if ($next === $to) return $candidate;
            $seen[$next] = true; $queue[] = $candidate;
        }
    }
    return [];
}

function ai_normalize_knowledge(array $entry): array
{
    $id = 'knowledge-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($entry['id'] ?? 'unknown'));
    $title = (string)($entry['title'] ?? '');
    return ['id' => $id, 'entityId' => $entry['entityId'] ?? null, 'title' => $title, 'aliases' => array_values(array_unique((array)($entry['aliases'] ?? []))), 'type' => $entry['type'] ?? 'information', 'categories' => $entry['categories'] ?? [], 'tags' => $entry['tags'] ?? [], 'recommendedFor' => $entry['recommendedFor'] ?? [], 'capabilities' => $entry['capabilities'] ?? [], 'facts' => $entry['facts'] ?? [], 'sceneIds' => $entry['sceneIds'] ?? [], 'routeIds' => $entry['routeIds'] ?? [], 'content' => (string)($entry['content'] ?? ''), 'source' => (string)($entry['source'] ?? ''), 'mappingConfidence' => (string)($entry['mappingConfidence'] ?? 'UNKNOWN')];
}

function ai_load_knowledge(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $file = dirname(__DIR__) . '/data/knowledge.json';
    $raw = json_decode((string)@file_get_contents($file), true);
    $entries = (array)($raw['entries'] ?? []);
    if (empty($entries)) error_log('[tour-ai] knowledge.json EMPTY or NOT FOUND at: ' . $file);
    // City knowledge (city-knowledge.json) — hanya ada jika tour lokasi punya knowledge kota.
    $cityFile = dirname(__DIR__) . '/data/city-knowledge.json';
    $cityRaw = json_decode((string)@file_get_contents($cityFile), true);
    if (is_array($cityRaw) && !empty($cityRaw['entries'])) {
        $cityEntries = array_map('ai_normalize_knowledge', (array)$cityRaw['entries']);
        foreach ($cityEntries as $ce) $entries[] = $ce;
    }
    return $cache = array_map('ai_normalize_knowledge', $entries);
}

function ai_embed(string $text, array $config): ?array
{
    if ($config['reasoning_mode'] === 'fast') return null;
    $response = ai_post_json($config['ollama_url'] . '/api/embed', ['model' => $config['embed_model'], 'input' => $text], 8);
    $vector = $response['embeddings'][0] ?? $response['embedding'] ?? null;
    return is_array($vector) ? array_map('floatval', $vector) : null;
}

function ai_cosine(array $a, array $b): float
{
    if (count($a) !== count($b) || !$a) return 0.0;
    $dot = $aa = $bb = 0.0;
    foreach ($a as $i => $v) { $dot += $v * $b[$i]; $aa += $v * $v; $bb += $b[$i] * $b[$i]; }
    return $aa > 0 && $bb > 0 ? max(0.0, min(1.0, $dot / (sqrt($aa) * sqrt($bb)))) : 0.0;
}

function ai_embedding_index(array $knowledge, array $config): array
{
    $hash = hash('sha256', json_encode($knowledge)) ?: '';
    $cacheDir = __DIR__ . '/cache/ai'; $cacheFile = $cacheDir . '/knowledge-embeddings.json';
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (($cached['hash'] ?? '') === $hash && ($cached['model'] ?? '') === $config['embed_model'] && ($cached['version'] ?? '') === AI_KNOWLEDGE_INDEX_VERSION) return (array)($cached['vectors'] ?? []);
    $vectors = [];
    foreach ($knowledge as $entry) {
        $vector = ai_embed($entry['title'] . '. ' . implode(', ', $entry['aliases']) . '. ' . $entry['content'], $config);
        if ($vector === null) return [];
        $vectors[$entry['id']] = $vector;
    }
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    @file_put_contents($cacheFile, json_encode(['hash' => $hash, 'model' => $config['embed_model'], 'version' => AI_KNOWLEDGE_INDEX_VERSION, 'vectors' => $vectors]));
    return $vectors;
}

function ai_standalone_query(string $message, array $session, array $history): string
{
    $parts = [$message];
    $profile = (array)($session['visitorProfile'] ?? []);
    foreach ($profile as $key => $value) if ($value !== null && $value !== false && $value !== [] && $value !== '') $parts[] = $key . ': ' . (is_array($value) ? implode(', ', $value) : json_encode($value));
    if (preg_match('/\b(itu|sana|tadi|lanjut|next|there|that)\b/i', $message)) {
        foreach (['lastEntity', 'lastIntent', 'lastSceneId'] as $key) if (!empty($session[$key])) $parts[] = $key . ': ' . (is_array($session[$key]) ? json_encode($session[$key]) : $session[$key]);
        $last = end($history); if (is_array($last)) $parts[] = (string)($last['text'] ?? '');
    }
    return implode('. ', $parts);
}

function ai_retrieve(string $query, array $session, array $tour, array $config, int $limit = 6): array
{
    $knowledge = ai_load_knowledge(); $tokens = ai_tokens($query); $queryVector = ai_embed($query, $config); $vectors = $queryVector ? ai_embedding_index($knowledge, $config) : [];
    $scene = (string)($session['lastSceneId'] ?? ''); $profileText = ai_normalize(json_encode($session['visitorProfile'] ?? [], JSON_UNESCAPED_UNICODE)); $scored = [];
    foreach ($knowledge as $entry) {
        $titleAliases = ai_normalize($entry['title'] . ' ' . implode(' ', $entry['aliases']));
        $all = ai_normalize($titleAliases . ' ' . implode(' ', $entry['categories']) . ' ' . implode(' ', $entry['recommendedFor']) . ' ' . $entry['content']);
        $entity = 0.0; $lexicalHits = 0;
        foreach ($tokens as $token) { if (str_contains($titleAliases, $token)) $entity = max($entity, 1.0); elseif (strlen($token) >= 4) foreach (explode(' ', $titleAliases) as $candidate) if (ai_similarity($token, $candidate) >= 0.78) $entity = max($entity, 0.75); if (str_contains($all, $token)) $lexicalHits++; }
        $lexical = $tokens ? min(1.0, $lexicalHits / count($tokens)) : 0.0;
        $semantic = isset($vectors[$entry['id']]) ? ai_cosine($queryVector, $vectors[$entry['id']]) : 0.0;
        $sceneScore = $scene !== '' && in_array($scene, $entry['sceneIds'], true) ? 1.0 : 0.0;
        $visitor = 0.0; foreach ($entry['recommendedFor'] as $tag) if (str_contains($profileText, ai_normalize((string)$tag))) $visitor = 1.0;
        $score = ($vectors ? 0.40 : 0.0) * $semantic + 0.25 * $entity + ($vectors ? 0.15 : 0.55) * $lexical + 0.08 * $sceneScore + 0.07 * $visitor + 0.05 * ($entry['routeIds'] ? 1 : 0);
        if ($score >= 0.12) $scored[] = ['entry' => $entry, 'score' => round(min(1, $score), 4)];
    }
    usort($scored, static function (array $a, array $b): int { return $b['score'] <=> $a['score']; });
    $seen = []; $result = [];
    foreach ($scored as $item) { $key = ai_normalize($item['entry']['title']); if (isset($seen[$key])) continue; $seen[$key] = true; $result[] = $item; if (count($result) >= $limit) break; }
    return ['items' => $result, 'mode' => $vectors ? 'hybrid' : 'lexical-fallback'];
}

function ai_parse_json(string $content): ?array
{
    $content = trim($content);
    // Strip markdown code fences if present.
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
    $content = trim($content);
    // Fast path: the whole content is already valid JSON.
    $decoded = json_decode($content, true);
    if (is_array($decoded)) return $decoded;
    // Tolerant path: extract the first balanced JSON object or array from
    // prose (Gemini sometimes prepends/explanatory text around the JSON).
    $decoded = ai_extract_first_json($content);
    return is_array($decoded) ? $decoded : null;
}

function ai_extract_first_json(string $content): ?array
{
    $openers = ['{' => '}', '[' => ']'];
    foreach ($openers as $open => $close) {
        $start = strpos($content, $open);
        if ($start === false) continue;
        $depth = 0;
        $inString = false;
        $escaped = false;
        $len = strlen($content);
        for ($i = $start; $i < $len; $i++) {
            $c = $content[$i];
            if ($inString) {
                if ($escaped) { $escaped = false; }
                elseif ($c === '\\') { $escaped = true; }
                elseif ($c === '"') { $inString = false; }
                continue;
            }
            if ($c === '"') { $inString = true; }
            elseif ($c === $open) { $depth++; }
            elseif ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($content, $start, $i - $start + 1);
                    $decoded = json_decode($candidate, true);
                    if (is_array($decoded)) return $decoded;
                    break;
                }
            }
        }
    }
    return null;
}

function ai_validate_profile(array $updates): array
{
    $result = [];
    $allowed = ['transport', 'traveling_with_children', 'service_preference', 'interests', 'destination_preference', 'accessibility_needs'];
    foreach ($updates as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        if ($key === 'traveling_with_children' && is_bool($value)) $result[$key] = $value;
        elseif (in_array($key, ['interests', 'accessibility_needs'], true) && is_array($value)) $result[$key] = array_slice(array_values(array_filter(array_map('strval', $value))), 0, 10);
        elseif (is_string($value) && mb_strlen($value) <= 80) $result[$key] = $value;
    }
    return $result;
}

/**
 * Validate provider-provided location recommendations (e.g. hotels, places of
 * worship). Each must have a name and a numeric lat/lng so the frontend can
 * render a Google Maps link. Max 8 items, safe for JSON.
 */
function ai_validate_recommendations(array $recs): array
{
    $out = [];
    foreach (array_slice($recs, 0, 8) as $r) {
        if (!is_array($r)) continue;
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') continue;
        $lat = filter_var($r['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($r['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false) continue;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $out[] = [
            'name' => mb_substr($name, 0, 120),
            'type' => mb_substr((string)($r['type'] ?? ''), 0, 60),
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
            'note' => mb_substr((string)($r['note'] ?? ''), 0, 200),
        ];
    }
    return $out;
}

function ai_validate_response(array $data, array $tour, array $retrieval): array
{
    // Normalize retrieval shape: accept both ['items'=>[ ['entry'=>..., 'score'=>...] ]] and
    // a flat list of entry arrays (with 'relevance' as score).
    $rawItems = is_array($retrieval['items'] ?? null) ? $retrieval['items'] : $retrieval;
    $items = array_values(array_map(static function ($it): array {
        if (is_array($it) && isset($it['entry']) && is_array($it['entry'])) {
            $entry = $it['entry'];
            $score = isset($it['score']) ? (float)$it['score'] : (isset($it['relevance']) ? (float)$it['relevance'] : 0.0);
        } else {
            $entry = is_array($it) ? $it : [];
            $score = isset($it['relevance']) ? (float)$it['relevance'] : 0.0;
        }
        return ['entry' => $entry, 'score' => $score];
    }, is_array($rawItems) ? $rawItems : []));
    $sceneIds = array_column($tour['scenes'], 'id');
    $routeMap = array_column($tour['routes'], null, 'id');
    $retrievedIds = array_column(array_column($items, 'entry'), 'id');
    $rawActions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
    if (!$rawActions && isset($data['action'])) {
        $rawActions[] = ['type' => $data['action'], 'scene_id' => $data['scene'] ?? null, 'route_id' => $data['route'] ?? null, 'hotspot' => $data['hotspot'] ?? null, 'execution' => 'autoExecute'];
    }
    $actions = [];
    foreach (array_slice($rawActions, 0, 4) as $raw) {
        $type = (string)($raw['type'] ?? 'none');
        if (!in_array($type, AI_ALLOWED_ACTIONS, true) || $type === 'none') continue;
        $action = ['type' => $type, 'execution' => in_array($raw['execution'] ?? '', ['autoExecute', 'userConfirm', 'suggestion'], true) ? $raw['execution'] : 'suggestion'];
        if ($type === 'go_to_scene') {
            $id = (string)($raw['scene_id'] ?? $raw['scene'] ?? '');
            if (!in_array($id, $sceneIds, true)) continue;
            $action['scene_id'] = $id;
        } elseif ($type === 'start_route') {
            $id = (string)($raw['route_id'] ?? $raw['route'] ?? '');
            if (!isset($routeMap[$id])) continue;
            $action['route_id'] = $id;
            $action['scene_ids'] = $routeMap[$id]['scenes'];
        } elseif (in_array($type, ['focus_hotspot', 'show_hotspot_info'], true)) {
            $title = (string)($raw['hotspot'] ?? '');
            $scene = (string)($raw['scene_id'] ?? '');
            $valid = array_filter($tour['hotspots'], static function (array $h) use ($title, $scene): bool { return $h['title'] === $title && ($scene === '' || $h['scene'] === $scene); });
            if (!$valid) continue;
            $action['hotspot'] = $title;
        } elseif ($type === 'set_language') {
            $language = (string)($raw['language'] ?? '');
            if (!in_array($language, ['id', 'en', 'nl', 'ar', 'zh'], true)) continue;
            $action['language'] = $language;
        }
        $actions[] = $action;
    }
    $llmConfidence = max(0, min(1, (float)($data['confidence'] ?? 0)));
    $evidence = (float)($items[0]['score'] ?? 0);
    if ($actions) $evidence = max($evidence, 0.75);
    $effective = round(0.55 * $llmConfidence + 0.45 * $evidence, 3);
    if ($effective < 0.48) {
        $actions = array_values(array_filter($actions, static function (array $a): bool { return $a['execution'] !== 'autoExecute'; }));
    }
    $used = array_values(array_intersect(array_map('strval', (array)($data['used_source_ids'] ?? [])), $retrievedIds));
    $sources = [];
    foreach ($items as $item) {
        if (in_array($item['entry']['id'], $used, true)) {
            $sources[] = ['id' => $item['entry']['id'], 'title' => $item['entry']['title'], 'url' => $item['entry']['source'], 'relevance' => $item['score']];
        }
    }
    $primary = $actions[0] ?? null;
    $conversationUpdate = is_array($data['conversation_update'] ?? null) ? $data['conversation_update'] : [];
    $referencedSceneId = (string)($conversationUpdate['referenced_scene_id'] ?? '');
    $recommendedRouteId = (string)($conversationUpdate['recommended_route_id'] ?? '');
    $referencedEntityId = (string)($conversationUpdate['referenced_entity_id'] ?? '');
    return [
        'reply' => trim((string)($data['reply'] ?? '')) ?: 'Saya belum menemukan bagian spesifik itu. Saya tetap bisa membantu mencari lokasi, fasilitas, atau rute yang tersedia.',
        'intent' => mb_substr((string)($data['intent'] ?? 'answer'), 0, 60),
        'confidence' => $effective,
        'entities' => array_slice((array)($data['entities'] ?? []), 0, 10),
        'profile_updates' => ai_validate_profile((array)($data['profile_updates'] ?? [])),
        'needs_clarification' => (bool)($data['needs_clarification'] ?? false),
        'clarification_options' => array_slice(array_values(array_filter((array)($data['clarification_options'] ?? []), 'is_string')), 0, 4),
        'actions' => $actions,
        'cards' => array_slice((array)($data['cards'] ?? []), 0, 4),
        'sources' => $sources,
        'recommendations' => ai_validate_recommendations((array)($data['recommendations'] ?? [])),
        'action' => $primary['type'] ?? 'none',
        'scene' => $primary['scene_id'] ?? null,
        'hotspot' => $primary['hotspot'] ?? null,
        'route' => $primary['scene_ids'] ?? null,
        'conversation_update' => [
            'recommended_route_id' => isset($routeMap[$recommendedRouteId]) ? $recommendedRouteId : null,
            'referenced_scene_id' => in_array($referencedSceneId, $sceneIds, true) ? $referencedSceneId : ($primary['scene_id'] ?? null),
            'referenced_entity_id' => $referencedEntityId !== '' ? $referencedEntityId : null,
        ],
    ];
}

/** Validate and sanitize provider response array for safety.
 * - Ensure reply is string and not empty.
 * - Remove any technical labels (node IDs, rawNames) from reply.
 * - Ensure no system prompt leakage (check for certain phrases).
 * - Ensure no API key leakage.
 * - Ensure actions are allowed.
 * Returns sanitized array or null if invalid.
 */
function ai_validate_provider_response(array $data, array $tour, array $retrieval, string $currentSceneId): ?array
{
    $validated = ai_validate_response($data, $tour, $retrieval);
    if (!is_array($validated) || empty($validated['reply'])) return null;

    $reply = (string)$validated['reply'];
    $reply = ai_technical_label_check($reply, $currentSceneId);

    $lower = strtolower($reply);
    $forbidden = ['system prompt', 'api key', 'knowledge.json', 'retrieval', 'embedding', 'database tidak'];
    foreach ($forbidden as $f) if (str_contains($lower, $f)) return null;

    $conf = (float)($validated['confidence'] ?? 0.0);
    $validated['confidence'] = max(0.0, min(1.0, $conf));
    $validated['reply'] = $reply;
    return $validated;
}

function ai_fallback(array $retrieval, array $tour = [], string $currentScene = ''): array
{
    $place = ai_place_context($currentScene, $tour);
    if ($retrieval['items']) {
        $item = $retrieval['items'][0];
        $entry = $item['entry'];
        $summary = mb_substr(trim((string)$entry['content']), 0, 220);
        if (mb_strlen((string)$entry['content']) > 220) $summary .= '...';
        return [
            'reply' => $entry['title'] . '. ' . $summary,
            'intent' => 'retrieval_fallback',
            'confidence' => round($item['score'], 3),
            'entities' => [['type' => $entry['type'], 'id' => $entry['id'], 'name' => $entry['title']]],
            'profile_updates' => [],
            'needs_clarification' => false,
            'clarification_options' => [],
            'actions' => [],
            'cards' => [],
            'sources' => [['id' => $entry['id'], 'title' => $entry['title'], 'url' => $entry['source'], 'relevance' => $item['score']]],
            'action' => 'none',
            'scene' => null,
            'hotspot' => null,
            'route' => null,
        ];
    }
    // Place-aware structural answer: place display name, no panorama framing, no forced nav exits.
    if ($tour['scenes']) {
        $scenes = array_column($tour['scenes'], null, 'id');
        $scene = $scenes[$currentScene] ?? $tour['scenes'][0];
        $placeName = $place['currentPlaceName'] !== '' ? $place['currentPlaceName'] : ($scene['displayName'] ?? $scene['rawName'] ?? $scene['name'] ?? 'area ini');
        $name = $scene['name'] ?? $placeName;
        $reply = ai_fallback_template('GENERAL_CONVERSATION', $place, $tour);
        return [
            'reply' => $reply,
            'intent' => 'fallback_structural',
            'confidence' => 0.8,
            'entities' => [['type' => 'place', 'id' => $place['currentPlaceId'] !== '' ? $place['currentPlaceId'] : $scene['id'], 'name' => $name]],
            'profile_updates' => [],
            'needs_clarification' => false,
            'clarification_options' => [],
            'actions' => [],
            'cards' => [],
            'sources' => [],
            'action' => 'none',
            'scene' => null,
            'hotspot' => null,
            'route' => null,
        ];
    }
    $reply = ai_fallback_template('GENERAL_CONVERSATION', $place, $tour);
    return ['reply' => $reply, 'intent' => 'fallback', 'confidence' => 0.1, 'entities' => [], 'profile_updates' => [], 'needs_clarification' => false, 'clarification_options' => [], 'actions' => [], 'cards' => [], 'sources' => [], 'action' => 'none', 'scene' => null, 'hotspot' => null, 'route' => null];
}

/* ================================================================
 * V2 PLACE-AWARE INTENT-FIRST PIPELINE
 * ================================================================
 * Concept separation:
 *   RAW SCENE  - Pano2VR node (node51 / "Main Siang")
 *   PLACE      - visitor-facing area (placeId=main-area, "Area Utama")
 *   ENTITY     - knowledge topic (need NOT be navigable)
 *   DESTINATION- navigable place/scene used ONLY for navigation intents
 *   KNOWLEDGE  - verified facts attached to tour/place/entity/scene
 * ================================================================ */

const AI_V2_INTENTS = ['CURRENT_LOCATION', 'PLACE_DESCRIPTION', 'ENTITY_DESCRIPTION', 'TOUR_DESCRIPTION', 'FACTUAL_QUESTION', 'HIGHLIGHTS', 'FACILITY', 'NEARBY_SEARCH', 'NAVIGATION_REQUEST', 'ROUTE_RECOMMENDATION', 'FOLLOWUP_NAVIGATION', 'UNKNOWN_FACT', 'GENERAL_CONVERSATION', 'GENERAL_KNOWLEDGE', 'HYBRID'];

function ai_load_place_map(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $raw = json_decode((string)@file_get_contents(dirname(__DIR__) . '/data/place-map.json'), true);
    $scenes = [];
    foreach ((array)($raw['scenes'] ?? []) as $scene) {
        $id = (string)($scene['sceneId'] ?? '');
        if ($id !== '') $scenes[$id] = [
            'sceneId' => $id,
            'rawName' => (string)($scene['rawName'] ?? ''),
            'displayName' => (string)($scene['displayName'] ?? ''),
            'placeId' => (string)($scene['placeId'] ?? ''),
            'placeDisplayName' => (string)($scene['placeDisplayName'] ?? ''),
            'variant' => $scene['variant'] ?? null,
            'confidence' => (string)($scene['confidence'] ?? 'NEEDS_REVIEW'),
        ];
    }
    $places = [];
    foreach ((array)($raw['places'] ?? []) as $place) {
        $id = (string)($place['placeId'] ?? '');
        if ($id !== '') $places[$id] = [
            'placeId' => $id,
            'placeDisplayName' => (string)($place['placeDisplayName'] ?? ''),
            'aliases' => array_map('strval', (array)($place['aliases'] ?? [])),
            'variantGroup' => array_values(array_map('strval', (array)($place['variantGroup'] ?? []))),
            'confidence' => (string)($place['confidence'] ?? 'NEEDS_REVIEW'),
            'description' => (string)($place['description'] ?? ''),
        ];
    }
    return $cache = ['scenes' => $scenes, 'places' => $places];
}

/** Load place-registry.json (visitor-facing place names with aliases). */
function ai_load_place_registry(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $raw = json_decode((string)@file_get_contents(dirname(__DIR__) . '/data/place-registry.json'), true);
    $places = [];
    foreach ((array)($raw['places'] ?? []) as $id => $place) {
        $places[(string)$id] = [
            'name' => (string)($place['name'] ?? ''),
            'sceneIds' => array_values(array_map('strval', (array)($place['sceneIds'] ?? []))),
            'aliases' => array_values(array_map('strval', (array)($place['aliases'] ?? []))),
        ];
    }
    return $cache = $places;
}

/** Load scene-identity.json (enriched visitor-facing scene/place descriptions). */
function ai_load_scene_identity(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $file = dirname(__DIR__) . '/data/scene-identity.json';
    if (!is_file($file)) return $cache = ['scenes' => [], 'places' => [], 'version' => ''];
    $raw = json_decode((string)@file_get_contents($file), true);
    if (!is_array($raw)) return $cache = ['scenes' => [], 'places' => [], 'version' => ''];
    return $cache = [
        'version' => (string)($raw['version'] ?? ''),
        'scenes' => (array)($raw['scenes'] ?? []),
        'places' => (array)($raw['places'] ?? []),
    ];
}

/** Get enriched scene identity by scene ID. */
function ai_get_scene_identity(string $sceneId): ?array
{
    $identity = ai_load_scene_identity();
    return $identity['scenes'][$sceneId] ?? null;
}

/** Get enriched place identity by place ID. */
function ai_get_place_identity(string $placeId): ?array
{
    $identity = ai_load_scene_identity();
    return $identity['places'][$placeId] ?? null;
}

/** Find place by visitor-facing name (placeName, displayName, aliases). */
function ai_find_place_by_visitor_name(string $query): ?array
{
    $identity = ai_load_scene_identity();
    $q = ai_normalize($query);
    if ($q === '') return null;
    foreach ($identity['places'] as $pid => $place) {
        $name = (string)($place['name'] ?? '');
        if ($name !== '' && str_contains($q, ai_normalize($name))) return ['placeId' => $pid] + $place;
        foreach ((array)($place['aliases'] ?? []) as $alias) {
            if (ai_normalize($alias) !== '' && str_contains($q, ai_normalize($alias))) return ['placeId' => $pid] + $place;
        }
    }
    return null;
}

/** Get enriched description for a scene (scene-level, then place-level fallback). */
function ai_get_enriched_description(string $sceneId): array
{
    $scene = ai_get_scene_identity($sceneId);
    if ($scene) {
        $placeId = (string)($scene['placeId'] ?? '');
        $place = $placeId !== '' ? ai_get_place_identity($placeId) : null;
        $description = (string)($scene['description'] ?? '');
        if ($description === '' && $place) $description = (string)($place['description'] ?? '');
        return [
            'found' => true,
            'sceneId' => $sceneId,
            'placeId' => $placeId,
            'placeName' => (string)($scene['placeName'] ?? ($place['name'] ?? '')),
            'description' => $description,
            'shortDescription' => (string)($scene['shortDescription'] ?? ($place['shortDescription'] ?? '')),
            'highlights' => (array)($scene['highlights'] ?? ($place['highlights'] ?? [])),
            'talkingPoints' => (array)($scene['talkingPoints'] ?? ($place['talkingPoints'] ?? [])),
            'suggestedQuestions' => (array)($scene['suggestedQuestions'] ?? ($place['suggestedQuestions'] ?? [])),
            'relatedKnowledgeIds' => (array)($scene['relatedKnowledgeIds'] ?? ($place['relatedKnowledgeIds'] ?? [])),
            'contentGrounding' => (string)($scene['contentGrounding'] ?? ($place['contentGrounding'] ?? '')),
            'aiHints' => (array)($scene['aiHints'] ?? []),
        ];
    }
    // Fallback: check place by scene ID mapping
    $registry = ai_load_place_registry();
    foreach ($registry as $pid => $p) {
        if (in_array($sceneId, $p['sceneIds'], true)) {
            $place = ai_get_place_identity($pid);
            if ($place) {
                return [
                    'found' => true,
                    'sceneId' => $sceneId,
                    'placeId' => $pid,
                    'placeName' => (string)($place['name'] ?? ''),
                    'description' => (string)($place['description'] ?? ''),
                    'shortDescription' => (string)($place['shortDescription'] ?? ''),
                    'highlights' => (array)($place['highlights'] ?? []),
                    'talkingPoints' => (array)($place['talkingPoints'] ?? []),
                    'suggestedQuestions' => (array)($place['suggestedQuestions'] ?? []),
                    'relatedKnowledgeIds' => (array)($place['relatedKnowledgeIds'] ?? []),
                    'contentGrounding' => (string)($place['contentGrounding'] ?? ''),
                    'aiHints' => [],
                ];
            }
        }
    }
    return ['found' => false, 'sceneId' => $sceneId, 'placeId' => '', 'placeName' => '', 'description' => '', 'shortDescription' => '', 'highlights' => [], 'talkingPoints' => [], 'suggestedQuestions' => [], 'relatedKnowledgeIds' => [], 'contentGrounding' => '', 'aiHints' => []];
}

/** Get visitor-facing label for a scene (place name, never raw/technical name). */
function ai_get_visitor_label(string $sceneId): string
{
    $registry = ai_load_place_registry();
    foreach ($registry as $place) {
        if (in_array($sceneId, $place['sceneIds'], true) && $place['name'] !== '') return $place['name'];
    }
    $map = ai_load_place_map();
    $scene = $map['scenes'][$sceneId] ?? null;
    return $scene ? ($scene['placeDisplayName'] ?: $scene['displayName']) : '';
}

/** Check reply for technical label leakage. Returns cleaned reply. */
function ai_technical_label_check(string $reply, string $sceneId): string
{
    $map = ai_load_place_map();
    $scene = $map['scenes'][$sceneId] ?? null;
    if (!$scene) return $reply;
    $visitor = ai_get_visitor_label($sceneId);
    if ($visitor === '') $visitor = $scene['placeDisplayName'] ?: $scene['displayName'];
    $technical = [$scene['rawName'], $sceneId];
    foreach ($technical as $bad) {
        if ($bad !== '' && $bad !== $visitor && mb_stripos($reply, $bad) !== false) {
            $reply = str_ireplace($bad, $visitor, $reply);
        }
    }
    return $reply;
}

function ai_classify_intent(string $message, array $session = []): string
{
    $q = ' ' . mb_strtolower(trim($message)) . ' ';
    $entityTerms = ai_config_terms('intentTerms', ['tour', 'gedung', 'area', 'ruang', 'galeri', 'museum']);
    $navigationTerms = ai_config_terms('navigationTerms', ['gedung', 'area', 'ruang', 'pintu', 'galeri', 'museum']);
    $tourName = trim((string)(ai_tour_config()['tourName'] ?? ''));
    $tourPattern = $tourName !== '' ? preg_quote(mb_strtolower($tourName), '/') : 'tour ini|tur ini';

    // 1. Visitor tips backed by curated local data.
    if (preg_match('/(waktu terbaik|jam terbaik|bulan terbaik|musim terbaik|kapan.*berkunjung|spot foto|tempat foto|foto terbaik|hunting foto|instagramable|aktivitas|kegiatan|bisa ngapain|apa yang bisa dilakukan|heritage walk)/i', $q)) return 'VISITOR_TIPS';

    // 1b. Unknown current/dynamic facts — never let these fall into navigation/description.
    if (preg_match('/(harga tiket|tiket masuk|biaya masuk|berapa (harga|biaya|bayar)|jam buka|jam operasional|jam tutup|buka sampai|jam berapa|pukul berapa|banyak pengunjung|jumlah pengunjung|karyawan|staf|pegawai|berapa tiket|tiket berapa|hari ini|acara|event|sekarang (ramai|sepi)|cuaca|kondisi)/i', $q)) return 'UNKNOWN_FACT';

    // 2. Current location
    if (preg_match('/(saya (di|ada di)|aku (di|ada di)|posisi saya|keberadaan saya|saya sekarang|aku sekarang|di mana (saya|aku)|di mana saya berada|dimana saya|sedang berada)/i', $q)) return 'CURRENT_LOCATION';

    // 2a. Name-origin questions (before public service to avoid false matches)
    if (preg_match('/(kenapa|mengapa).*(disebut|dinamai|diberi nama|sebutan|panggilan)/i', $q)) return 'ENTITY_DESCRIPTION';
    if (preg_match('/(asal.?usul|asal nama|sejarah nama)/i', $q)) return 'ENTITY_DESCRIPTION';

    // 2b. Nearby domain must win over broad public-service terms such as station or tourism.
    $nearbyNouns = 'hotel|penginapan|restoran|rumah makan|kafe|cafe|oleh-oleh|souvenir|wisata|museum|landmark|halte|brt|terminal|stasiun|bandara|pelabuhan|transportasi';
    if (preg_match('/(' . $nearbyNouns . ').*(sekitar|dekat|terdekat|lawang sewu|nearby|around)|(sekitar|dekat|terdekat|lawang sewu|nearby|around).*(' . $nearbyNouns . ')/i', $q)) return 'NEARBY_SEARCH';

    // 2c. Public service query — BEFORE navigation to catch service-related terms
    if (preg_match('/(darurat|ambulans|polisi|pemadam|kebakaran|layanan publik|nomor bantuan|call center|pengaduan|bantuan medis|rumah sakit|klinik|apotek|kesehatan|paramedis|emergency|112|110|lapor|lapor semar|whatsapp.*semar|wa.*semar|pajak|pbb|kependudukan|ktp|identitas|chatbot.*pemkot|libas|kriminal|transportasi umum|stasiun kereta|kantor pemerintah|pemerintahan|walikota|gubernur|dinas|pariwisata|kehilangan|barang hilang)/i', $q)) return 'PUBLIC_SERVICE';

    // 3. Navigation — MUST come before general conversation (before "oke"/"baik" etc.)
    if (preg_match('/\bdrone\b/i', $q)) return 'NAVIGATION_REQUEST';
    if (preg_match('/(bawa (saya|aku)|antar (saya|aku)|menuju ke|(?:saya\s+)?mau\s+(?:lihat|liat|ke|kelihat)|(?:saya\s+)?ingin\s+(?:lihat|liat|ke|melihat)|(?:saya\s+)?pengen\s+(?:lihat|liat|ke)|cara (ke|menuju)|petunjuk (ke|menuju)|route|rute ke|arah (ke|menuju)|navigasi)/i', $q) && preg_match('/(' . $navigationTerms . ')/i', $q)) return 'NAVIGATION_REQUEST';
    if (preg_match('/(ke sana|kesana|ke situ|di sana|di situ)/i', $q)) return 'FOLLOWUP_NAVIGATION';
    if (preg_match('/(rekomendasi|recommend|saran (ke|untuk)|kemana (saya|aku)|ke mana (saya|aku)|mulai (dari|di) mana|rute wisata|rute tur|rute terbaik|jalur (tur|wisata|jalan)|(kemana|mana) yang sebaiknya|area mana yang|sebaiknya saya)/i', $q)) return 'ROUTE_RECOMMENDATION';

    // 4. General conversation — after navigation to avoid false matches on "oke", "baik", "bawa"
    if (preg_match('/^(halo|hai|hello|hi|hey|selamat (pagi|siang|sore|malam)|terima kasih|makasih|thanks|thank you|sampai jumpa|dadah|bye)\b/i', $q)) return 'GENERAL_CONVERSATION';
    if (preg_match('/\b(siapa kamu|kamu siapa|kamu (bisa|dapat|mau) apa|perkenalkan|kenalkan)\b/i', $q)) return 'GENERAL_CONVERSATION';
    if (preg_match('/^(oke|ok|sip|bagus|baik|terima kasih)\b/i', $q)) return 'GENERAL_CONVERSATION';

    // 5. NEARBY_SEARCH: proximity queries
    $placeNouns = 'toilet|wc|mushola|masjid|atm|bank|restoran|rumah makan|kafe|cafe|warung|toko|souvenir|spbu|pom bensin|rumah sakit|klinik|apotek|hotel|penginapan|taman|parkir|indomaret|alfamart|apartemen|gedung';
    if (preg_match('/(' . $placeNouns . ').*(sekitar|dekat|terdekat|sini|ini|nearby|around)|(sekitar|dekat|terdekat|sini|ini|nearby|around).*(' . $placeNouns . ')/i', $q)) return 'NEARBY_SEARCH';
    if (preg_match('/(toilet|wc|mushola|masjid|muslim|parkir|restoran|makan|minum|kafe|cafe|souvenir|oleh-oleh|ramah anak|stroller|akses|kursi roda|fasilitas|layanan|peminjaman)/i', $q) && !preg_match('/(makanan khas|masakan khas|kuliner|rekomendasi makanan)/i', $q)) return 'FACILITY';

    // 6. HYBRID: tour entity + general concept in one question — check BEFORE entity description.
    if (preg_match('/(' . $entityTerms . ')/i', $q) && preg_match('/(umum|kolonial|arsitektur|desain|tropis|jendela|bukaan|pintu|kaca|patri|gaya|konsep|perbandingan|bandingkan)/i', $q)) return 'HYBRID';

    // 7. Tour-hallucination boundary: "siapa arsitek/pencipta/pemilik [entity]?" or "kapan [entity] dipindahkan/dibangun?" = FACTUAL_QUESTION (before entity description)
    if (preg_match('/siapa (arsitek|pencipta|perancang|pembuat|desainer|pemilik|pengelola)/i', $q) && preg_match('/(' . $entityTerms . ')/i', $q)) return 'FACTUAL_QUESTION';
    if (preg_match('/kapan/i', $q) && preg_match('/(dipindahkan|pindah|dibangun|didirikan|diresmikan|selesai dibangun)/i', $q) && preg_match('/(' . $entityTerms . ')/i', $q)) return 'FACTUAL_QUESTION';

    // 8. ENTITY_DESCRIPTION: specific entity queries — after hybrid and hallucination boundary.
    if (preg_match('/(kenapa|mengapa|siapa|kapan)/i', $q) && preg_match('/(' . $entityTerms . ')/i', $q)) return 'ENTITY_DESCRIPTION';
    if (preg_match('/(ceritakan|jelaskan|deskripsikan|cerita|info|informasi|sejarah|apa itu|tentang|apa yang menarik|apa saja yang bisa|apa yang bisa|pelajari)/i', $q) && preg_match('/(' . $entityTerms . ')/i', $q)) return 'ENTITY_DESCRIPTION';

    // 9. TOUR_DESCRIPTION: root tour entity — after entity description to avoid conflicts.
    //    Also covers first-time visitor questions.
    if (preg_match('/(tentang tur|tour ini|tur ini|' . $tourPattern . '|bagian mana.*menarik.*dijelajahi)/i', $q) && !preg_match('/(' . $navigationTerms . ')/i', $q)) return 'TOUR_DESCRIPTION';

    // 10. PLACE_DESCRIPTION: describe current/pointed area
    if (preg_match('/(ceritakan|jelaskan|deskripsikan|cerita (tentang|mengenai|soal)|info (tentang|mengenai|soal)|informasi (tentang|mengenai|soal)|apa (itu|yang dimaksud)|sejarah|kapan (dibangun|didirikan)|siapa (yang|perancang|arsitek)|bagaimana sejarah|bercerita)/i', $q) && preg_match('/(area|tempat|ruangan|di sini|ini|sana|tersebut)/i', $q)) return 'PLACE_DESCRIPTION';

    // 11. HIGHLIGHTS
    if (preg_match('/(apa (yang )?(bisa|dapat) (saya|aku) (lihat|temukan|nikmati)|spot (foto|instagram)|foto|menarik di|menarik dari|daya tarik|hal (yang )?menarik|highlight|unggulan|apa yang menarik)/i', $q) && !preg_match('/(gedung|lokomotif|tugu|maket|sapi|kereta|balkon|galeri|museum|cetak biru|tembakan|anne|revitalisasi|hantu)/i', $q)) return 'HIGHLIGHTS';

    // 12. General knowledge: architectural, cultural, historical, geographical, linguistic, food, etc.
    if (preg_match('/\b(arsitektur|kolonial|heritage|kaca.?patri|kriya|sejarah umum|budaya|tradisi|kuliner|makanan|khas|geografi|provinsi|kereta.?api|nis|perusahaan.?kereta|tropis|bangunan tua|pemandangan|cagar budaya|museum)/i', $q)) return 'GENERAL_KNOWLEDGE';
    if (preg_match('/(apa itu|apa yang dimaksud|apa arti|pengertian|definisi|kenapa|mengapa|bagaimana)/i', $q) && !preg_match('/(gedung|lokomotif|tugu|maket|sapi|kereta|lawang sewu|balkon|galeri|museum|cetak biru|tembakan|anne|revitalisasi|tiket|hantu|area|tempat|ruangan)/i', $q)) return 'GENERAL_KNOWLEDGE';
    if (preg_match('/(semarang|indonesia|jawa|tengah)/i', $q) && !preg_match('/(lawang sewu|gedung|lokomotif|tugu|maket|sapi|kereta|balkon|galeri|museum|cetak biru|tembakan)/i', $q)) return 'GENERAL_KNOWLEDGE';
    if (preg_match('/(apa (itu|yang dimaksud|arti|fungsi|kegunaan))/i', $q) && !preg_match('/(lawang sewu|gedung|lokomotif|tugu|maket|sapi|kereta|balkon|galeri|museum|cetak biru|tembakan|revitalisasi|tiket|hantu|area|tempat|ruangan)/i', $q)) return 'GENERAL_KNOWLEDGE';

    // 13. FACTUAL_QUESTION: remaining wh-questions
    if (preg_match('/(berapa|kapan|siapa|dimana|di mana|kenapa|mengapa|bagaimana)/i', $q)) return 'FACTUAL_QUESTION';

    return 'GENERAL_CONVERSATION';
}

/**
 * ================================================================
 * QUESTION FACET CLASSIFIER
 * ================================================================
 * Classifies WHAT the user wants to know about the subject.
 * Intent alone is insufficient — facet drives response strategy.
 */
function ai_classify_facet(string $message, string $intent = ''): string
{
    $q = ai_normalize($message);

    // NAME_ORIGIN: why is it called X
    if (preg_match('/(kenapa|apa sebab|apa alasan|mengapa).*(disebut|dinamai|diberi nama|panggilan|sebutan)/i', $q)) return 'NAME_ORIGIN';
    if (preg_match('/(disebut|dinamai|diberi nama|panggilan|sebutan).*(kenapa|apa sebab|apa alasan|mengapa)/i', $q)) return 'NAME_ORIGIN';
    if (preg_match('/(asal.?usul|asal nama|sejarah nama)/i', $q)) return 'NAME_ORIGIN';

    // DEFINITION: what is X
    if (preg_match('/^(apa itu|apa yang dimaksud|definisi|pengertian)\b/i', $q)) return 'DEFINITION';

    // IMPORTANCE: why is X important / why does it matter
    if (preg_match('/(kenapa|mengapa|apa sebab).*(penting|bermakna|signifikan|berguna|bermanfaat)/i', $q)) return 'IMPORTANCE';
    if (preg_match('/(penting|bermakna|signifikan).*(kenapa|mengapa|untuk apa|guna)/i', $q)) return 'IMPORTANCE';

    // HIGHLIGHTS: what's interesting / standout features
    if (preg_match('/(apa yang )?(menarik|unik|istimewa|unggulan|daya tarik|highlight)/i', $q)) return 'HIGHLIGHTS';

    // ARCHITECTURE: architectural features
    if (preg_match('/(arsitektur|kolonial|desain|bangunan|struktur|atap|pilar|jendela|kaca patri|lantai|facade)/i', $q)) return 'ARCHITECTURE';

    // HISTORY: historical context
    if (preg_match('/(sejarah|bersejarah|masa lalu|kolonial|penjajahan|belanda|nis|zaman)/i', $q) && !preg_match('/(arsitektur|desain|struktur)/i', $q)) return 'HISTORY';

    // FUNCTION: what is it used for
    if (preg_match('/(fungsi|kegunaan|untuk apa|dipakai|digunakan|peran)/i', $q)) return 'FUNCTION';

    // WHAT_CAN_I_LEARN: learning outcomes
    if (preg_match('/(apa yang bisa|apa yang dapat|bisa (saya|aku) (pelajari|ketahui|lihat|temukan)|pelajari|ketahui)/i', $q)) return 'WHAT_CAN_I_LEARN';

    // RELATIONSHIP: connection between entities
    if (preg_match('/(hubungan|kaitan|korelasi|berhubungan|berkaitan)/i', $q)) return 'RELATIONSHIP';

    // FIRST_VISIT_PLAN: recommendations for first-time visitors
    if (preg_match('/(pertama kali|baru pertama|pemula|first time|mulai dari|rekomendasi|sebaiknya|bagian mana|area mana|menarik untuk dijelajahi)/i', $q)) return 'FIRST_VISIT_PLAN';

    // RECOMMENDATION: what should I do next
    if (preg_match('/(rekomendasi|saran|sebaiknya|ke mana|lanjut|berikutnya|selanjutnya)/i', $q)) return 'RECOMMENDATION';

    // DESCRIPTION: general description
    if (preg_match('/(ceritakan|jelaskan|deskripsikan|tentang|info|informasi)/i', $q)) return 'DESCRIPTION';

    // LOCATION: where is something
    if (preg_match('/(di mana|lokasi|posisi|terletak|berada)/i', $q)) return 'LOCATION';

    return 'DESCRIPTION'; // default facet
}

/**
 * ================================================================
 * EXPLORE MENU KNOWLEDGE DOMAIN
 * ================================================================
 * Loads data/explore-menu-knowledge.json (Jelajah menu entities).
 * These are visitor-facing place descriptions from the live webview.
 */

function ai_load_explore_menu(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $file = dirname(__DIR__) . '/data/explore-menu-knowledge.json';
    if (!is_file($file)) {
        error_log('[tour-ai] explore-menu-knowledge.json NOT FOUND at: ' . $file);
        return $cache = [];
    }
    $raw = json_decode((string)@file_get_contents($file), true);
    return $cache = (array)($raw['entries'] ?? []);
}

function ai_resolve_explore_entity(string $query): ?array
{
    $q = ai_normalize($query);
    if ($q === '') return null;
    $entries = ai_load_explore_menu();
    if (!$entries) return null;

    // Tier 1: Exact alias substring match (min 3 chars).
    foreach ($entries as $entry) {
        $title = ai_normalize($entry['title']);
        foreach (array_merge([$title], array_map('ai_normalize', $entry['aliases'] ?? [])) as $candidate) {
            if ($candidate !== '' && strlen($candidate) >= 3 && str_contains($q, $candidate)) {
                // Convert to knowledge-entry format for pipeline compatibility.
                return [
                    'id' => $entry['id'],
                    'title' => $entry['title'],
                    'aliases' => $entry['aliases'] ?? [],
                    'type' => 'explore-place',
                    'content' => $entry['description'] ?? '',
                    'sceneIds' => $entry['sceneIds'] ?? [],
                    'categories' => ['jelajah', 'explore-menu'],
                    'tags' => ['explore-menu', 'visitor-facing'],
                    'recommendedFor' => [],
                    'capabilities' => [],
                    'facts' => [],
                    'routeIds' => [],
                    'exploreMenu' => [
                        'entityId' => $entry['entityId'] ?? '',
                        'placeId' => $entry['placeId'] ?? '',
                        'relatedKnowledgeIds' => $entry['relatedKnowledgeIds'] ?? [],
                        'relatedExploreIds' => $entry['relatedExploreIds'] ?? [],
                        'thumbnail' => $entry['thumbnail'] ?? '',
                        'navigationUrl' => $entry['navigationUrl'] ?? '',
                    ],
                ];
            }
        }
    }

    // Tier 2: Token-overlap scoring.
    $qTokens = array_diff(ai_tokens($q), ['lawang', 'sewu']);
    if (!$qTokens) return null;
    $best = null; $bestScore = 0.0;
    foreach ($entries as $entry) {
        $needle = ai_normalize($entry['title'] . ' ' . implode(' ', $entry['aliases'] ?? []));
        $hits = 0;
        foreach ($qTokens as $token) if (str_contains($needle, $token)) $hits++;
        $score = $hits / count($qTokens);
        if ($score > $bestScore) { $bestScore = $score; $best = $entry; }
    }
    if ($bestScore >= 0.6 && $best) {
        return [
            'id' => $best['id'],
            'title' => $best['title'],
            'aliases' => $best['aliases'] ?? [],
            'type' => 'explore-place',
            'content' => $best['description'] ?? '',
            'sceneIds' => $best['sceneIds'] ?? [],
            'categories' => ['jelajah', 'explore-menu'],
            'tags' => ['explore-menu', 'visitor-facing'],
            'recommendedFor' => [],
            'capabilities' => [],
            'facts' => [],
            'routeIds' => [],
            'exploreMenu' => [
                'entityId' => $best['entityId'] ?? '',
                'placeId' => $best['placeId'] ?? '',
                'relatedKnowledgeIds' => $best['relatedKnowledgeIds'] ?? [],
                'relatedExploreIds' => $best['relatedExploreIds'] ?? [],
                'thumbnail' => $best['thumbnail'] ?? '',
                'navigationUrl' => $best['navigationUrl'] ?? '',
            ],
        ];
    }

    return null;
}

function ai_resolve_entity(string $query, array $session = []): ?array
{
    $q = ai_normalize($query);
    if ($q === '') return null;

    // 0. Explore-menu entities (highest priority — visitor-facing named places from Jelajah menu).
    //    Explicit named subject MUST override current place context.
    $explore = ai_resolve_explore_entity($query);
    if ($explore) return $explore;

    $knowledge = ai_load_knowledge();
    // 1. Exact entity title / alias (normalized) -> winner, no fuzzy second-guessing.
    //    Min length 3: single-letter tokens leak from transliterated non-Latin aliases ("المبنى A" -> "a").
    foreach ($knowledge as $entry) {
        $title = ai_normalize($entry['title']);
        foreach (array_merge([$title], array_map('ai_normalize', $entry['aliases'])) as $candidate) {
            if ($candidate !== '' && strlen($candidate) >= 3 && str_contains($q, $candidate)) return $entry;
        }
    }
    $tourTokens = ai_tokens((string)(ai_tour_config()['tourName'] ?? ''));
    if ($tourTokens && !array_diff($tourTokens, ai_tokens($q))) {
        foreach ($knowledge as $entry) if (($entry['type'] ?? '') === 'overview') return $entry;
    }
    // 2. Token-overlap alias match (multi-word aliases after tour-name removal).
    $qTokens = array_diff(ai_tokens($q), $tourTokens);
    $best = null; $bestScore = 0.0;
    foreach ($knowledge as $entry) {
        $needle = ai_normalize($entry['title'] . ' ' . implode(' ', $entry['aliases']));
        $hits = 0; foreach ($qTokens as $token) if (str_contains($needle, $token)) $hits++;
        if (!$qTokens) continue;
        $score = $hits / count($qTokens);
        if ($score > $bestScore) { $bestScore = $score; $best = $entry; }
    }
    return $bestScore >= 0.6 ? $best : null;
}

function ai_place_context(string $currentSceneId, array $tour = []): array
{
    $map = ai_load_place_map();
    $scenes = $map['scenes'];
    $tourScenes = array_column((array)($tour['scenes'] ?? []), 'id');
    if (!isset($scenes[$currentSceneId]) && $tourScenes) $currentSceneId = (string)$tourScenes[0];
    $scene = $scenes[$currentSceneId] ?? null;
    if (!$scene) return ['currentSceneId' => $currentSceneId, 'currentPlaceId' => '', 'currentPlaceName' => '', 'currentRawSceneName' => '', 'currentSceneDisplayName' => '', 'enrichedDescription' => ''];
    $visitorLabel = ai_get_visitor_label($currentSceneId);
    // Enriched description from scene-identity.json
    $enriched = ai_get_enriched_description($currentSceneId);
    return [
        'currentSceneId' => $currentSceneId,
        'currentPlaceId' => (string)$scene['placeId'],
        'currentPlaceName' => $visitorLabel !== '' ? $visitorLabel : (string)$scene['placeDisplayName'],
        'currentRawSceneName' => (string)$scene['rawName'],
        'currentSceneDisplayName' => (string)($scene['displayName'] ?: $scene['rawName']),
        'enrichedDescription' => (string)($enriched['description'] ?? ''),
        'enrichedHighlights' => (array)($enriched['highlights'] ?? []),
        'enrichedTalkingPoints' => (array)($enriched['talkingPoints'] ?? []),
        'enrichedRelatedKnowledgeIds' => (array)($enriched['relatedKnowledgeIds'] ?? []),
        'enrichedContentGrounding' => (string)($enriched['contentGrounding'] ?? ''),
    ];
}

function ai_retrieve_v2(string $query, array $place, array $knowledge = [], int $limit = 5): array
{
    // Place -> mapped entities (EXACT/LIKELY) -> tour knowledge -> entity resolver.
    $scored = [];
    $scene = (string)($place['currentSceneId'] ?? '');
    $placeId = (string)($place['currentPlaceId'] ?? '');
    $placeName = (string)($place['currentPlaceName'] ?? '');
    $knowledge = $knowledge ?: ai_load_knowledge();
    foreach ($knowledge as $entry) {
        $titleAliases = ai_normalize($entry['title'] . ' ' . implode(' ', $entry['aliases']));
        $all = ai_normalize($titleAliases . ' ' . implode(' ', $entry['categories']) . ' ' . implode(' ', $entry['recommendedFor']) . ' ' . $entry['content']);
        $sceneScore = $scene !== '' && in_array($scene, $entry['sceneIds'], true) ? 1.0 : 0.0;
        $exact = 0.0; foreach (array_merge([ai_normalize($entry['title'])], array_map('ai_normalize', $entry['aliases'])) as $candidate) if ($candidate !== '' && strlen($candidate) >= 3 && str_contains(ai_normalize($query), $candidate)) { $exact = 1.0; break; }
        $tokens = ai_tokens($query); $lexical = 0.0; if ($tokens) { $hits = 0; foreach ($tokens as $token) if (str_contains($all, $token)) $hits++; $lexical = min(1.0, $hits / count($tokens)); }
        // Ranking: exact requested entity > current-place EXACT knowledge > current-place LIKELY > tour-wide.
        $rank = 0.0;
        if ($exact >= 1.0) $rank = 1.0;
        elseif ($sceneScore > 0.0) $rank = ($entry['mappingConfidence'] === 'EXACT' ? 0.95 : 0.85);
        elseif ($placeName !== '' && (str_contains(ai_normalize($entry['title']), ai_normalize($placeName)) || in_array(ai_normalize($placeName), array_map('ai_normalize', $entry['aliases']), true))) $rank = 0.80;
        elseif ($lexical >= 0.6) $rank = 0.70;
        $score = ($rank > 0.0) ? round($rank, 3) : 0.0;
        if ($score > 0.0) $scored[] = ['entry' => $entry, 'score' => $score, 'reason' => $rank >= 1.0 ? 'exact' : ($sceneScore > 0.0 ? 'scene-map' : ($rank >= 0.8 ? 'place-map' : 'lexical'))];
    }
    // Tour-knowledge fallback tier (scene -> place -> mapped entities -> tour knowledge). Graph never ranked for facts.
    // Overview outranks generic lexical matches (0.75 > 0.70) but never beats exact/scene/place knowledge.
    $hasStrong = false;
    foreach ($scored as $s) { if ($s['score'] >= 0.8) { $hasStrong = true; break; } }
    if (!$hasStrong) {
        foreach ($knowledge as $entry) {
            if (($entry['type'] ?? '') === 'overview') { $scored[] = ['entry' => $entry, 'score' => 0.75, 'reason' => 'tour-knowledge']; break; }
        }
    }
    usort($scored, static function (array $a, array $b): int { return $b['score'] <=> $a['score']; });
    return ['items' => array_slice($scored, 0, $limit), 'mode' => 'place-aware'];
}

function ai_compose_reply(string $intent, string $reply, array $place, array $entities = []): array
{
    $sceneName = (string)($place['currentSceneDisplayName'] ?? '');
    $placeName = (string)($place['currentPlaceName'] ?? '');
    $prefix = ($placeName !== '' && $intent === 'CURRENT_LOCATION') ? 'Anda sekarang berada di ' . $placeName . '.' : '';
    $reply = trim($prefix . ($prefix !== '' && $reply !== '' ? ' ' : '') . $reply);
    // Post-check: strip technical/raw scene names leaking into visitor output.
    $reply = ai_technical_label_check($reply, (string)($place['currentSceneId'] ?? ''));
    $subject = $entities[0]['name'] ?? null;
    return [
        'reply' => $reply,
        'intent' => $intent,
        'confidence' => 0.8,
        'entities' => array_slice($entities, 0, 5),
        'profile_updates' => [],
        'needs_clarification' => false,
        'clarification_options' => [],
        'actions' => [],
        'cards' => [],
        'sources' => [],
        'action' => 'none',
        'scene' => null,
        'hotspot' => null,
        'route' => null,
    ];
}

function ai_graceful_reply(string $intent, string $message, array $place, bool $isProximityQuery): array
{
    $placeName = (string)($place['currentPlaceName'] ?? '');
    $where = $placeName !== '' ? $placeName : 'lokasi ini';
    if ($isProximityQuery) {
        $reply = 'Maaf, saat ini saya belum dapat menampilkan daftar tempat di sekitar ' . $where . '. Silakan tanyakan hal lain seputar lokasi ini, atau minta saya menuntun Bapak/Ibu ke titik menarik terdekat.';
    } else {
        $reply = 'Maaf, jawaban belum dapat saya susun saat ini. Bapak/Ibu dapat mengajukan pertanyaan lain atau meminta panduan menuju bagian tertentu dari lokasi ini.';
    }
    $result = ai_compose_reply($intent, $reply, $place);
    $result['confidence'] = 0.4;
    return $result;
}

function ai_resolve_destination(string $query, array $place, array $tour = []): ?array
{
    // Navigation destinations are PLACES/SCENES (not knowledge entities). Scene-graph aware.
    $q = ai_normalize($query);
    $map = ai_load_place_map();
    $places = $map['places']; $scenes = $map['scenes'];
    $currentSceneId = (string)($place['currentSceneId'] ?? '');
    $tourScenes = array_column((array)($tour['scenes'] ?? []), 'id');
    $candidates = [];
    foreach ($places as $p) {
        foreach ($p['variantGroup'] as $sceneId) {
            if ($sceneId !== $currentSceneId && in_array($sceneId, $tourScenes, true)) {
                $names = array_merge([ai_normalize($p['placeDisplayName'])], array_map('ai_normalize', [$p['placeDisplayName'], $scenes[$sceneId]['rawName'] ?? '', $scenes[$sceneId]['displayName'] ?? '']), array_map('ai_normalize', (array)($p['aliases'] ?? [])));
                $score = 0.0;
                foreach (array_values(array_unique(array_filter($names))) as $name) {
                    if ($name !== '' && str_contains($q, $name)) { $score = max($score, 1.0); break; }
                    // Partial match: check if any specific word from the name appears in query
                    $genericWords = ['area', 'gedung', 'ruang', 'koridor', 'lorong', 'pintu', 'jalan', 'taman', 'halaman'];
                    $words = array_filter(explode(' ', $name), function ($w) use ($genericWords) { return strlen($w) >= 4 && !in_array($w, $genericWords); });
                    foreach ($words as $word) {
                        if (str_contains($q, $word)) { $score = max($score, 0.85); break 2; }
                    }
                }
                if ($score > 0.0) $candidates[] = ['placeId' => $p['placeId'], 'placeName' => $p['placeDisplayName'], 'sceneId' => $sceneId, 'score' => $score];
            }
        }
    }
    if (!$candidates) return null;
    usort($candidates, static function (array $a, array $b): int { return $b['score'] <=> $a['score']; });
    $best = $candidates[0];
    return ['destinationPlaceId' => $best['placeId'], 'destinationPlaceName' => $best['placeName'], 'destinationSceneId' => $best['sceneId'], 'fromSceneId' => $currentSceneId, 'path' => ai_find_path(ai_graph($tour), $currentSceneId, $best['sceneId']), 'navigable' => true];
}

function ai_retrieve_evidence(string $message, string $intent, array $place, ?array $subject): array
{
    $knowledge = ai_load_knowledge();
    if ($subject) return ai_retrieve_v2($message, $place, [$subject], 5);
    // For general queries without specific entity, retrieve by place context.
    return ai_retrieve_v2($message, $place, $knowledge, 5);
}

function ai_v2_recommend_destination(string $currentSceneId, array $tour): ?array
{
    // Deterministic graph-based recommendation: pick a navigable neighbor that is a unique physical place (not a day/night variant of current).
    $graph = ai_graph($tour);
    $neighbors = $graph[$currentSceneId] ?? [];
    $map = ai_load_place_map();
    $currentPlace = $map['scenes'][$currentSceneId]['placeId'] ?? '';
    $candidates = [];
    foreach ($neighbors as $sceneId) {
        $sceneDef = $map['scenes'][$sceneId] ?? null;
        if (!$sceneDef) continue;
        // Skip day/night variant of current place.
        if ($sceneDef['placeId'] === $currentPlace) continue;
        $candidates[] = ['sceneId' => $sceneId, 'placeId' => $sceneDef['placeId'], 'placeName' => $sceneDef['placeDisplayName'] ?? $sceneId];
    }
    if (!$candidates) return null;
    // Prefer unique placeId (not already in candidates).
    $seen = []; $unique = [];
    foreach ($candidates as $c) {
        if (!isset($seen[$c['placeId']])) { $seen[$c['placeId']] = true; $unique[] = $c; }
    }
    $pick = $unique[0] ?? $candidates[0];
    return ['sceneId' => $pick['sceneId'], 'placeId' => $pick['placeId'], 'placeName' => $pick['placeName'], 'navigable' => true];
}

function ai_v2_pipeline(string $message, array $session = [], array $tour = [], array $config = []): array
{
    $history = (array)($session['history'] ?? []);
    $currentSceneId = (string)($session['lastSceneId'] ?? '');
    $place = ai_place_context($currentSceneId, $tour);
    $intent = ai_classify_intent($message, $session);
    $facet = ai_classify_facet($message, $intent);
    $subject = null; $retrieval = ['items' => [], 'mode' => 'place-aware'];
    $navigation = null;

    // UNKNOWN_FACT: deterministic honest answer, no disambiguation, no invented numbers.
    // UNKNOWN_FACT → let Gemini handle (no deterministic template)

    // CURRENT_LOCATION: place name + enriched description (scene-identity.json preferred, then place-map, then fallback).
    if ($intent === 'CURRENT_LOCATION') {
        $reply = '';
        // Priority 1: enriched scene-identity.json description
        $enrichedDesc = (string)($place['enrichedDescription'] ?? '');
        if ($enrichedDesc !== '') {
            $reply = $enrichedDesc;
        }
        // Priority 2: place-map.json description
        if ($reply === '') {
            $placeDef = $place['currentPlaceName'] !== '' ? ai_load_place_map()['places'][$place['currentPlaceId']] ?? null : null;
            if ($placeDef) $reply = (string)($placeDef['description'] ?? '');
        }
        // Priority 3: deterministic fallback template
        if ($reply === '') $reply = ai_fallback_template('CURRENT_LOCATION', $place, $tour);
        $result = ai_compose_reply($intent, $reply, $place, []);
        return ['result' => $result, 'debug' => ['intent' => $intent, 'subjectType' => null, 'subjectId' => null, 'currentSceneId' => $currentSceneId, 'currentRawSceneName' => $place['currentRawSceneName'], 'currentPlaceId' => $place['currentPlaceId'], 'currentPlaceName' => $place['currentPlaceName'], 'resolvedEntityId' => null, 'knowledgeIds' => [], 'retrievalScores' => [], 'navigationIntent' => null, 'responseStrategy' => 'location', 'descriptionSource' => ($enrichedDesc !== '' ? 'enriched-scene' : (($placeDef['description'] ?? '') !== '' ? 'place-map' : 'deterministic'))]];
    }

    // Intent-first resolution. Subject resolved BEFORE navigation is even considered.
    if (in_array($intent, ['ENTITY_DESCRIPTION', 'TOUR_DESCRIPTION', 'PLACE_DESCRIPTION', 'FACTUAL_QUESTION', 'HIGHLIGHTS', 'GENERAL_KNOWLEDGE', 'HYBRID'], true)) {
        $subject = ai_resolve_entity($message, $session);
        $retrieval = ai_retrieve_evidence($message, $intent, $place, $subject);
    }

    // GENERAL_CONVERSATION: also try entity resolution (explore-menu entities may be mentioned).
    if ($intent === 'GENERAL_CONVERSATION') {
        $exploreEntity = ai_resolve_explore_entity($message);
        if ($exploreEntity) {
            $subject = $exploreEntity;
            $intent = 'ENTITY_DESCRIPTION';
            $retrieval = ['items' => [['entry' => $exploreEntity, 'score' => 0.95]], 'mode' => 'explore-menu'];
        }
    }

    // ROUTE_RECOMMENDATION: flow-based "ke mana berikutnya" → navigate forward, never loop back.
    if ($intent === 'ROUTE_RECOMMENDATION') {
        $visited = (array)($session['visitedSceneIds'] ?? []);
        if ($currentSceneId !== '' && in_array($currentSceneId, $visited, true) === false) $visited[] = $currentSceneId;
        $flow = ai_load_flow();
        $rec = $flow ? ai_flow_next($currentSceneId, $flow, $visited) : ai_v2_recommend_destination($currentSceneId, $tour);
        if ($rec) {
            $map = ai_load_place_map();
            $sceneDef = $map['scenes'][$rec['sceneId']] ?? null;
            $label = $rec['label'] !== '' ? $rec['label'] : ($sceneDef['placeDisplayName'] ?? $rec['sceneId']);
            $reply = 'Saya arahkan ke ' . $label . '.';
            $result = ai_compose_reply($intent, $reply, $place, []);
            $result['actions'] = [['type' => 'go_to_scene', 'scene_id' => $rec['sceneId'], 'execution' => 'autoExecute']];
            $result['action'] = 'go_to_scene';
            $result['scene'] = $rec['sceneId'];
            $result['recommendation_target'] = ['sceneId' => $rec['sceneId'], 'placeId' => $sceneDef['placeId'] ?? '', 'label' => $label, 'sourceSceneId' => $currentSceneId, 'navigable' => true];
            return ['result' => $result, 'debug' => ['intent' => $intent, 'subjectType' => 'destination', 'subjectId' => $sceneDef['placeId'] ?? '', 'currentSceneId' => $currentSceneId, 'currentRawSceneName' => $place['currentRawSceneName'], 'currentPlaceId' => $place['currentPlaceId'], 'currentPlaceName' => $place['currentPlaceName'], 'resolvedEntityId' => null, 'knowledgeIds' => [], 'retrievalScores' => [], 'navigationIntent' => $rec['sceneId'], 'responseStrategy' => 'flow-navigate']];
        }
        $reply = ai_fallback_template('ROUTE_RECOMMENDATION', $place, $tour);
        $result = ai_compose_reply($intent, $reply, $place, []);
        return ['result' => $result, 'debug' => ['intent' => $intent, 'subjectType' => null, 'subjectId' => null, 'currentSceneId' => $currentSceneId, 'currentRawSceneName' => $place['currentRawSceneName'], 'currentPlaceId' => $place['currentPlaceId'], 'currentPlaceName' => $place['currentPlaceName'], 'resolvedEntityId' => null, 'knowledgeIds' => [], 'retrievalScores' => [], 'navigationIntent' => null, 'responseStrategy' => 'recommendation-fallback']];
    }

    // Navigation only when intent explicitly asks for it.
    if ($intent === 'FOLLOWUP_NAVIGATION') {
        $targetId = (string)($session['lastNavigableSceneId'] ?? $session['lastReferencedDestination'] ?? $session['lastRecommendedSceneId'] ?? '');
        // Also check recommendation_target stored by ROUTE_RECOMMENDATION.
        $recTarget = $session['lastRecommendedDestination'] ?? null;
        if ($targetId === '' && $recTarget && isset($recTarget['sceneId'])) {
            $targetId = (string)$recTarget['sceneId'];
        }
        if ($targetId !== '' && in_array($targetId, array_column((array)($tour['scenes'] ?? []), 'id'), true)) {
            $map = ai_load_place_map();
            $sceneDef = $map['scenes'][$targetId] ?? null;
            $navigation = ['destinationPlaceId' => $sceneDef['placeId'] ?? '', 'destinationPlaceName' => $sceneDef['placeDisplayName'] ?? $targetId, 'destinationSceneId' => $targetId, 'fromSceneId' => $currentSceneId, 'path' => ai_find_path(ai_graph($tour), $currentSceneId, $targetId), 'navigable' => true];
        }
    } elseif ($intent === 'NAVIGATION_REQUEST') {
        $navigation = ai_resolve_destination($message, $place, $tour);
    }

    // NAVIGATION: only here, only with explicit nav intent.
    if ($navigation) {
        $reply = 'Saya bisa membawa Anda ke ' . $navigation['destinationPlaceName'] . '.';
        $action = ['type' => 'go_to_scene', 'scene_id' => $navigation['destinationSceneId'], 'execution' => 'suggestion'];
        $result = ai_compose_reply($intent, $reply, $place, [['type' => 'destination', 'id' => $navigation['destinationPlaceId'], 'name' => $navigation['destinationPlaceName']]]);
        $result['actions'] = [$action];
        $result['action'] = 'go_to_scene';
        $result['scene'] = $navigation['destinationSceneId'];
        return ['result' => $result, 'debug' => ['intent' => $intent, 'subjectType' => 'destination', 'subjectId' => $navigation['destinationPlaceId'], 'currentSceneId' => $currentSceneId, 'currentRawSceneName' => $place['currentRawSceneName'], 'currentPlaceId' => $place['currentPlaceId'], 'currentPlaceName' => $place['currentPlaceName'], 'resolvedEntityId' => null, 'knowledgeIds' => [], 'retrievalScores' => [], 'navigationIntent' => $navigation['destinationSceneId'], 'responseStrategy' => 'navigate']];
    }

    // For intents that should go through the provider (natural language responses):
    // All informational intents including FACILITY/NEARBY_SEARCH — Gemini handles these.
    if (in_array($intent, ['PLACE_DESCRIPTION', 'ENTITY_DESCRIPTION', 'TOUR_DESCRIPTION', 'HIGHLIGHTS', 'FACTUAL_QUESTION', 'GENERAL_KNOWLEDGE', 'HYBRID', 'GENERAL_CONVERSATION', 'FACILITY', 'NEARBY_SEARCH'], true)) {
        $entities = [];
        if ($subject) $entities[] = ['type' => $subject['type'] ?? 'entity', 'id' => $subject['id'] ?? '', 'name' => $subject['title'] ?? ''];
        elseif ($retrieval['items']) {
            $top = $retrieval['items'][0]['entry'];
            $entities[] = ['type' => $top['type'] ?? 'entity', 'id' => $top['id'] ?? '', 'name' => $top['title'] ?? ''];
        }
        $result = ai_compose_reply($intent, '', $place, $entities);
        $result['_knowledgeMode'] = $intent === 'GENERAL_KNOWLEDGE' ? 'general' : ($intent === 'HYBRID' ? 'hybrid' : 'tour');
        $result['_retrieval'] = $retrieval;
        $result['conversation_update'] = [
            'recommended_route_id' => null,
            'referenced_scene_id' => $result['scene'] ?? null,
            'referenced_entity_id' => $entities[0]['id'] ?? null,
        ];
        return ['result' => $result, 'debug' => ['intent' => $intent, 'subjectType' => $entities[0]['type'] ?? null, 'subjectId' => $entities[0]['id'] ?? null, 'currentSceneId' => $currentSceneId, 'currentRawSceneName' => $place['currentRawSceneName'], 'currentPlaceId' => $place['currentPlaceId'], 'currentPlaceName' => $place['currentPlaceName'], 'resolvedEntityId' => $entities[0]['id'] ?? null, 'knowledgeIds' => array_column(array_column($retrieval['items'], 'entry'), 'id'), 'retrievalScores' => array_column($retrieval['items'], 'score'), 'navigationIntent' => null, 'responseStrategy' => 'provider-evidence']];
    }

    // Final fallback
    $reply = ai_fallback_template('GENERAL_CONVERSATION', $place, $tour);
    $result = ai_compose_reply($intent, $reply, $place, []);
    return ['result' => $result, 'debug' => ['intent' => $intent, 'subjectType' => null, 'subjectId' => null, 'currentSceneId' => $currentSceneId, 'currentRawSceneName' => $place['currentRawSceneName'], 'currentPlaceId' => $place['currentPlaceId'], 'currentPlaceName' => $place['currentPlaceName'], 'resolvedEntityId' => null, 'knowledgeIds' => [], 'retrievalScores' => [], 'navigationIntent' => null, 'responseStrategy' => 'generic']];
}

/* ================================================================
 * CATALOG CROSS-REFERENCE
 * ================================================================
 * Check if reply text mentions destinations from configured catalog.
 * Used to offer virtual tours when Gemini mentions cataloged places.
 */

function ai_load_semarang_catalog(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $path = (string)(ai_tour_config()['frontend']['catalogPath'] ?? '');
    if ($path === '') return $cache = [];
    $file = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $path), '/');
    if (!is_file($file)) return $cache = [];
    $raw = json_decode((string)@file_get_contents($file), true);
    return $cache = (array)($raw['destinations'] ?? []);
}

function ai_cross_reference_catalog(string $replyText, array $excludeNames = []): array
{
    $catalog = ai_load_semarang_catalog();
    if (!$catalog) return [];
    $matches = [];
    $replyLower = mb_strtolower($replyText);
    $excludeNames = array_values(array_unique(array_merge(
        [mb_strtolower((string)(ai_tour_config()['tourName'] ?? ''))],
        array_map(static fn($n): string => mb_strtolower(trim((string)$n)), $excludeNames)
    )));
    foreach ($catalog as $dest) {
        $name = $dest['name'] ?? '';
        if ($name === '') continue;
        $nameLower = mb_strtolower($name);
        // Skip excluded names
        if (in_array($nameLower, $excludeNames, true)) continue;
        // Check if the destination name (or significant part) appears in the reply
        if (mb_strlen($nameLower) >= 5 && mb_strpos($replyLower, $nameLower) !== false) {
            $matches[] = [
                'id' => $dest['id'] ?? '',
                'name' => $name,
                'description' => $dest['description'] ?? '',
                'virtualTourUrl' => $dest['virtualTourUrl'] ?? '',
                'virtualTourAvailable' => !empty($dest['virtualTourUrl']),
                'category' => $dest['category'] ?? '',
            ];
        }
    }
    return $matches;
}

/* ================================================================
 * LAWANG SEWU VISITOR TIPS DOMAIN
 * ================================================================ */

function ai_load_lawang_sewu_visitor_tips(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $file = dirname(__DIR__) . '/data/lawang-sewu-visitor-tips.json';
    if (!is_file($file)) return $cache = [];
    $raw = json_decode((string)@file_get_contents($file), true);
    return $cache = is_array($raw) ? $raw : [];
}

function ai_resolve_lawang_sewu_visitor_tips(string $message): ?array
{
    $data = ai_load_lawang_sewu_visitor_tips();
    $topics = (array)($data['topics'] ?? []);
    if (!$topics) return null;

    $q = ai_normalize_service_text($message);
    $wanted = '';
    if (preg_match('/bulan|musim|kemarau|hujan/i', $q)) $wanted = 'best-time-month';
    elseif (preg_match('/waktu|jam|pukul|kapan/i', $q)) $wanted = 'best-time-day';
    elseif (preg_match('/foto|instagram|hunting|portrait|selfie/i', $q)) $wanted = 'photo-spots';
    elseif (preg_match('/aktivitas|kegiatan|ngapain|dilakukan|heritage walk|pre wedding|shooting|edukasi/i', $q)) $wanted = 'activities';

    foreach ($topics as $topic) {
        if (($topic['id'] ?? '') !== $wanted) continue;
        return [
            'reply' => (string)($topic['content'] ?? ''),
            'intent' => 'VISITOR_TIPS',
            'confidence' => 0.96,
            'entities' => [['type' => 'visitor_tip', 'id' => $topic['id'], 'name' => $topic['title']]],
            'needs_clarification' => false,
            'clarification_options' => [],
            'actions' => [],
            'action' => 'none',
            'scene' => null,
            'provider' => 'lawang-sewu-visitor-tips',
        ];
    }
    return null;
}

/* ================================================================
 * LAWANG SEWU NEARBY DOMAIN
 * ================================================================ */

function ai_load_lawang_sewu_nearby(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $file = dirname(__DIR__) . '/data/lawang-sewu-nearby.json';
    if (!is_file($file)) return $cache = [];
    $raw = json_decode((string)@file_get_contents($file), true);
    return $cache = is_array($raw) ? $raw : [];
}

function ai_lawang_sewu_maps_url(array $item): string
{
    $query = trim((string)($item['name'] ?? '') . ' ' . (string)($item['address'] ?? '') . ' Lawang Sewu Semarang');
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
}

function ai_resolve_lawang_sewu_nearby(string $message): ?array
{
    $data = ai_load_lawang_sewu_nearby();
    $items = (array)($data['items'] ?? []);
    if (!$items) return null;

    $q = ai_normalize_service_text($message);
    $category = '';
    if (preg_match('/hotel|penginapan/i', $q)) $category = 'hotel';
    elseif (preg_match('/restoran|rumah makan|makan|kafe|cafe/i', $q)) $category = 'restoran';
    elseif (preg_match('/oleh oleh|souvenir|suvenir/i', $q)) $category = 'oleh-oleh';
    elseif (preg_match('/wisata|museum|landmark/i', $q)) $category = 'wisata';
    elseif (preg_match('/transportasi|halte|brt|terminal|stasiun|bandara|pelabuhan|akses/i', $q)) $category = 'transportasi';

    $matches = [];
    foreach ($items as $item) {
        $haystack = ai_normalize_service_text(implode(' ', array_merge([$item['name'] ?? '', $item['category'] ?? ''], $item['aliases'] ?? [])));
        $specific = $haystack !== '' && (mb_strpos($q, ai_normalize_service_text((string)($item['name'] ?? ''))) !== false);
        if ($specific || ($category !== '' && ($item['category'] ?? '') === $category)) $matches[] = $item;
    }
    if (!$matches) return null;

    $matches = array_slice($matches, 0, 5);
    $reply = $category === '' ? 'Pilihan sekitar Lawang Sewu:' : 'Pilihan ' . $category . ' sekitar Lawang Sewu:';
    foreach ($matches as $i => $item) {
        $reply .= "\n" . ($i + 1) . '. ' . $item['name'];
        if (!empty($item['distance'])) $reply .= ' — ' . $item['distance'];
        if (!empty($item['travelTime'])) $reply .= ' (' . $item['travelTime'] . ')';
        if (!empty($item['address'])) $reply .= "\n   " . $item['address'];
    }
    $reply .= "\n\n" . ($data['disclaimer'] ?? 'Jarak dan waktu bersifat perkiraan; verifikasi sebelum berangkat.');

    $recommendations = [];
    $actions = [];
    foreach ($matches as $item) {
        $url = ai_lawang_sewu_maps_url($item);
        $recommendations[] = [
            'name' => (string)($item['name'] ?? ''),
            'type' => (string)($item['category'] ?? 'lainnya'),
            'note' => trim((string)($item['distance'] ?? '') . ((string)($item['travelTime'] ?? '') !== '' ? ' · ' . (string)$item['travelTime'] : '')),
            'url' => $url,
        ];
        $actions[] = ['label' => 'Buka di Google Maps', 'url' => $url];
    }

    return [
        'reply' => $reply,
        'intent' => 'NEARBY_SEARCH',
        'confidence' => 0.94,
        'entities' => array_map(static fn(array $item): array => ['type' => 'nearby_place', 'id' => $item['id'], 'name' => $item['name']], $matches),
        'needs_clarification' => false,
        'clarification_options' => [],
        'actions' => $actions,
        'recommendations' => $recommendations,
        'action' => 'none',
        'scene' => null,
        'provider' => 'lawang-sewu-nearby',
    ];
}

/* ================================================================
 * PUBLIC SERVICE KNOWLEDGE DOMAIN
 * ================================================================
 * Loads data/public-services.json for city public service queries.
 * Separate from tour scene knowledge and destination discovery.
 */

function ai_load_public_services(): array
{
    static $cache;
    if ($cache !== null) return $cache;
    $file = dirname(__DIR__) . '/data/public-services.json';
    if (!is_file($file)) return $cache = [];
    $raw = json_decode((string)@file_get_contents($file), true);
    return $cache = (array)($raw['services'] ?? []);
}

function ai_normalize_service_text(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\w\s]/u', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function ai_search_public_services(string $query): array
{
    $services = ai_load_public_services();
    if (!$services) return [];

    $normalized = ai_normalize_service_text($query);
    if ($normalized === '') return [];

    // Discovery queries: return top services, filtered by category if specified
    if (preg_match('/^(ada|apa saja|apa aja|tersedia|daftar|list|jenis|layanan publik|public service|layanan kota)/i', $normalized)) {
        $scored = [];
        // Check if query specifies a category (e.g., "rumah sakit", "polisi", "transportasi")
        $categoryFilter = '';
        if (preg_match('/(rumah sakit|klinik|kesehatan|rs)/i', $normalized)) $categoryFilter = 'rumah sakit';
        elseif (preg_match('/(polisi|kepolisian|kantor polisi)/i', $normalized)) $categoryFilter = 'kepolisian';
        elseif (preg_match('/(transportasi|stasiun|kereta|bus)/i', $normalized)) $categoryFilter = 'transportasi';
        elseif (preg_match('/(pemerintahan|pemerintah|kantor|walikota)/i', $normalized)) $categoryFilter = 'pemerintahan';
        elseif (preg_match('/(wisata|pariwisata|tur)/i', $normalized)) $categoryFilter = 'pariwisata';
        
        foreach ($services as $service) {
            $cat = mb_strtolower($service['category'] ?? '');
            $name = mb_strtolower($service['name'] ?? '');
            $aliases = implode(' ', $service['aliases'] ?? []);
            if ($categoryFilter !== '') {
                if (stripos($cat, $categoryFilter) !== false || stripos($name, $categoryFilter) !== false || stripos($aliases, $categoryFilter) !== false) {
                    $scored[] = ['service' => $service, 'score' => 80];
                }
            } else {
                $scored[] = ['service' => $service, 'score' => 50];
            }
        }
        if ($scored) return array_slice($scored, 0, 6);
        // Fallback: return first 6
        foreach (array_slice($services, 0, 6) as $service) {
            $scored[] = ['service' => $service, 'score' => 50];
        }
        return $scored;
    }

    $scored = [];
    foreach ($services as $service) {
        $score = 0;
        $name = mb_strtolower($service['name'] ?? '');
        $category = mb_strtolower($service['category'] ?? '');
        $description = mb_strtolower($service['description'] ?? '');

        // Exact name match
        if (ai_normalize_service_text($name) === $normalized) {
            $score = max($score, 100);
        }
        // Name contains query
        elseif (mb_strpos($name, $normalized) !== false) {
            $score = max($score, 80);
        }
        // Query contains name
        elseif (mb_strpos($normalized, ai_normalize_service_text($name)) !== false) {
            $score = max($score, 70);
        }

        // Category match (exact)
        if (ai_normalize_service_text($category) === $normalized) {
            $score = max($score, 85);
        }
        // Category contains query
        elseif (mb_strpos($category, $normalized) !== false) {
            $score = max($score, 65);
        }
        // Query contains category words
        elseif (mb_strpos($normalized, $category) !== false) {
            $score = max($score, 60);
        }

        // Description match
        if (mb_strpos($description, $normalized) !== false) {
            $score = max($score, 55);
        }

        // Multi-word matching: check if all words appear in name/category/aliases
        $words = array_filter(explode(' ', $normalized));
        if (count($words) > 1) {
            $allFields = $name . ' ' . $category . ' ' . $description . ' ' . implode(' ', $service['aliases'] ?? []);
            $allPresent = true;
            foreach ($words as $word) {
                if (mb_strlen($word) < 2) continue;
                if (mb_stripos($allFields, $word) === false) {
                    $allPresent = false;
                    break;
                }
            }
            if ($allPresent) {
                $score = max($score, 70);
            }
        }

        // Alias match (with word boundary check to avoid false substring matches)
        foreach ($service['aliases'] ?? [] as $alias) {
            $normAlias = ai_normalize_service_text($alias);
            if ($normAlias === '') continue;
            if ($normAlias === $normalized) {
                $score = max($score, 90);
            } elseif (preg_match('/\b' . preg_quote($normAlias, '/') . '\b/i', $normalized) || preg_match('/\b' . preg_quote($normalized, '/') . '\b/i', $normAlias)) {
                $score = max($score, 75);
            }
        }

        if ($score > 0) {
            $scored[] = ['service' => $service, 'score' => $score];
        }
    }

    usort($scored, function ($a, $b) { return $b['score'] - $a['score']; });
    return array_slice($scored, 0, 6);
}

function ai_classify_public_service_intent(string $message): string
{
    $q = ' ' . mb_strtolower(trim($message)) . ' ';

    // Exact service lookup — asking for specific contact info
    if (preg_match('/(nomor|no\.?|telepon|telp\.?|hubungi|打电话|berapa).*?(darurat|ambulans|polisi|pemadam|kebakaran|112|110|rumah sakit)/i', $q)) return 'PUBLIC_SERVICE_EXACT';
    if (preg_match('/(darurat|ambulans|polisi|pemadam|kebakaran|112|110|rumah sakit|klinik|apotek).*?(nomor|no\.?|telepon|telp\.?|hubungi|berapa)/i', $q)) return 'PUBLIC_SERVICE_EXACT';

    // Discovery — asking for available services
    if (preg_match('/(ada|apa saja|apa aja|tersedia|daftar|list|jenis).*?(layanan|publik|jasa|service)/i', $q)) return 'PUBLIC_SERVICE_DISCOVERY';
    if (preg_match('/(layanan|publik|jasa|service).*?(ada|apa saja|apa aja|tersedia|daftar|list|jenis)/i', $q)) return 'PUBLIC_SERVICE_DISCOVERY';
    if (preg_match('/(layanan publik|public service|layanan kota)/i', $q)) return 'PUBLIC_SERVICE_DISCOVERY';

    // Follow-up — selecting from previous results
    if (preg_match('/^(yang )?(pertama|kedua|ketiga|keempat|kelima|1st|2nd|3rd|4th|5th)/i', $q)) return 'PUBLIC_SERVICE_FOLLOWUP';
    if (preg_match('/(nomor|no\.?|telepon|telp\.?|alamat|website|buka|detail).*nya/i', $q)) return 'PUBLIC_SERVICE_FOLLOWUP';

    // General public service query
    return 'PUBLIC_SERVICE';
}

function ai_resolve_public_service(string $message, array $session = []): ?array
{
    $intent = ai_classify_public_service_intent($message);

    // Follow-up: use last results
    if ($intent === 'PUBLIC_SERVICE_FOLLOWUP') {
        $lastResults = $session['lastPublicServiceResults'] ?? [];
        if (empty($lastResults)) return null;

        // Number selection
        $numMap = ['pertama' => 0, '1st' => 0, '1' => 0, 'kedua' => 1, '2nd' => 1, '2' => 1, 'ketiga' => 2, '3rd' => 2, '3' => 2, 'keempat' => 3, '4th' => 3, '4' => 3, 'kelima' => 4, '5th' => 4, '5' => 4];
        $selected = null;

        foreach ($numMap as $word => $idx) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $message)) {
                if (isset($lastResults[$idx])) {
                    $selected = $lastResults[$idx];
                }
                break;
            }
        }

        if (!$selected && !empty($lastResults)) {
            $selected = $lastResults[0];
        }

        if (!$selected) return null;

        // Determine what info they want
        $phone = $selected['phone'] ?? null;
        $website = $selected['website'] ?? null;
        $contactUrl = $selected['contactUrl'] ?? null;
        $address = $selected['address'] ?? null;

        $reply = $selected['name'] . ':';
        if ($phone) $reply .= "\n📞 Telepon: " . $phone;
        if ($address) $reply .= "\n📍 Alamat: " . $address;
        if ($website) $reply .= "\n🌐 Website: " . $website;
        if ($contactUrl && $contactUrl !== $website) $reply .= "\n💬 WhatsApp: " . $contactUrl;
        if (!$phone && !$website && !$address && !$contactUrl) $reply .= "\nInformasi kontak tidak tersedia dalam data layanan saat ini.";

        return [
            'reply' => $reply,
            'intent' => 'PUBLIC_SERVICE',
            'confidence' => 0.92,
            'entities' => [['type' => 'public_service', 'id' => $selected['id'], 'name' => $selected['name']]],
            'needs_clarification' => false,
            'clarification_options' => [],
            'actions' => [],
            'action' => 'none',
            'scene' => null,
            'provider' => 'public-service-catalog',
            '_publicServiceResults' => [$selected],
        ];
    }

    // Search
    $results = ai_search_public_services($message);
    if (empty($results)) return null;

    $services = array_column($results, 'service');
    $best = $services[0];

    // Exact lookup — return specific service
    if ($intent === 'PUBLIC_SERVICE_EXACT') {
        $phone = $best['phone'] ?? null;
        $emergency = $best['emergencyNumber'] ?? null;
        $website = $best['website'] ?? null;
        $contactUrl = $best['contactUrl'] ?? null;
        $address = $best['address'] ?? null;

        $reply = $best['name'] . ':';
        if ($emergency) $reply .= "\n🚨 Nomor darurat: " . $emergency;
        elseif ($phone) $reply .= "\n📞 Telepon: " . $phone;
        if ($address) $reply .= "\n📍 Alamat: " . $address;
        if ($website) $reply .= "\n🌐 Website: " . $website;
        if ($contactUrl && $contactUrl !== $website) $reply .= "\n💬 WhatsApp: " . $contactUrl;
        if (!$phone && !$website && !$address && !$contactUrl) $reply .= "\nInformasi kontak tidak tersedia dalam data layanan saat ini.";

        return [
            'reply' => $reply,
            'intent' => 'PUBLIC_SERVICE',
            'confidence' => 0.95,
            'entities' => [['type' => 'public_service', 'id' => $best['id'], 'name' => $best['name']]],
            'needs_clarification' => false,
            'clarification_options' => [],
            'actions' => [],
            'action' => 'none',
            'scene' => null,
            'provider' => 'public-service-catalog',
            '_publicServiceResults' => $services,
        ];
    }

    // Discovery — list multiple services
    if (count($services) > 1) {
        $reply = 'Beberapa layanan publik yang relevan:';
        $options = [];
        foreach (array_slice($services, 0, 4) as $idx => $s) {
            $num = $idx + 1;
            $reply .= "\n$num. {$s['name']}";
            if (!empty($s['phone'])) $reply .= ' — ' . $s['phone'];
            $options[] = $s['name'];
        }
        $reply .= "\n\nMau detail layanan yang mana? Sebutkan nomor atau namanya.";

        return [
            'reply' => $reply,
            'intent' => 'PUBLIC_SERVICE',
            'confidence' => 0.90,
            'entities' => array_map(function ($s) { return ['type' => 'public_service', 'id' => $s['id'], 'name' => $s['name']]; }, $services),
            'needs_clarification' => true,
            'clarification_options' => $options,
            'actions' => [],
            'action' => 'none',
            'scene' => null,
            'provider' => 'public-service-catalog',
            '_publicServiceResults' => $services,
        ];
    }

    // Single result
    $phone = $best['phone'] ?? null;
    $website = $best['website'] ?? null;
    $contactUrl = $best['contactUrl'] ?? null;

    $reply = $best['description'] ?? $best['name'];
    if ($phone) $reply .= "\n📞 Telepon: " . $phone;
    if ($website) $reply .= "\n🌐 Website: " . $website;
    if ($contactUrl && $contactUrl !== $website) $reply .= "\n💬 WhatsApp: " . $contactUrl;

    return [
        'reply' => $reply,
        'intent' => 'PUBLIC_SERVICE',
        'confidence' => 0.92,
        'entities' => [['type' => 'public_service', 'id' => $best['id'], 'name' => $best['name']]],
        'needs_clarification' => false,
        'clarification_options' => [],
        'actions' => [],
        'action' => 'none',
        'scene' => null,
        'provider' => 'public-service-catalog',
        '_publicServiceResults' => $services,
    ];
}
