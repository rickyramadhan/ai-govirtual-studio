<?php
/**
 * _regen_questions.php — Generate context-aware recommended questions for ALL tour locations.
 *
 * Pipeline (NER + knowledge-graph + RAG-lite + LLM):
 *   1. Load every tour's knowledge.json (entities), scene-identity.json (per-scene facts),
 *      curated-answers.json (golden intents), parsed.json (scene/hotspot graph).
 *   2. Build a knowledge graph: entity <-> scene <-> hotspot neighbors.
 *   3. NER/entity extraction: collect location-specific named entities
 *      (titles, aliases, highlights, talkingPoints) unique to THIS tour.
 *   4. RAG-style context pack: entity list, scene descriptions, highlights,
 *      talking points, curated answers (trimmed).
 *   5. Call the existing AI provider (Gemini primary / OpenRouter fallback)
 *      with a strict prompt: output 3 starters + 6 themes + 4 fallbacks as JSON,
 *      all anchored to the location's real entities/context.
 *   6. Write recommended-questions.json (source dir) and package data copy.
 *
 * Usage (from repo root):
 *   c:\xampp\php\php.exe _regen_questions.php           # all tours
 *   c:\xampp\php\php.exe _regen_questions.php zie-batik # single tour (slug)
 *   c:\xampp\php\php.exe _regen_questions.php all dry   # dry-run (no provider calls)
 *   ?a=_regenQuestions   — via web (optional tiny UI below)
 *
 * Note: writing the source JSON only; re-run build/deploy (Save+Generate) to
 * push into each package + export_path.
 */

declare(strict_types=1);

// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('APP_ROOT', __DIR__);
define('TOURS_DIR', APP_ROOT . '/storage/tours');

$onlySlug = $argv[1] ?? ($_GET['slug'] ?? '');
$dryRun = in_array($argv[2] ?? ($_GET['dry'] ?? ''), ['1', 'dry', 'true'], true);

function rq_read_json(string $file, array $fallback = []): array {
    $raw = is_file($file) ? @json_decode((string)@file_get_contents($file), true) : null;
    return is_array($raw) ? $raw : $fallback;
}

function rq_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/** All tour slugs under storage/tours that have parsed.json (real locations). */
function rq_tour_slugs(): array {
    $out = [];
    foreach (glob(TOURS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (is_file($dir . '/parsed.json')) $out[] = $slug;
    }
    sort($out);
    return $out;
}

/* ================================================================
 * CONTEXT BUILDERS (NER / knowledge graph / RAG pack)
 * ================================================================ */

/** Named entities unique to this tour: entity titles + aliases from knowledge.json. */
function rq_entities(array $knowledge): array {
    $seen = [];
    foreach (($knowledge['entries'] ?? []) as $e) {
        $title = trim((string)($e['title'] ?? ''));
        if ($title === '') continue;
        $key = rq_slugify($title);
        if ($key === '') continue;
        if (!isset($seen[$key])) {
            $seen[$key] = [
                'name' => $title,
                'type' => (string)($e['type'] ?? 'information'),
                'aliases' => array_values(array_unique(array_filter(array_map('trim', (array)($e['aliases'] ?? []))))),
                'content' => trim((string)($e['content'] ?? '')),
            ];
        }
    }
    return array_values($seen);
}

/** Distinct scene facts: displayName, description, highlights, talkingPoints, suggestedQuestions. */
function rq_scene_facts(array $sceneIdentity): array {
    $out = [];
    foreach (($sceneIdentity['scenes'] ?? []) as $id => $sc) {
        $out[] = [
            'sceneId' => (string)$id,
            'name' => (string)($sc['displayName'] ?? $sc['placeName'] ?? $id),
            'description' => trim((string)($sc['description'] ?? '')),
            'highlights' => array_values(array_filter(array_map('trim', (array)($sc['highlights'] ?? [])))),
            'talkingPoints' => array_values(array_filter(array_map('trim', (array)($sc['talkingPoints'] ?? [])))),
            'grounding' => (string)($sc['contentGrounding'] ?? ''),
        ];
    }
    // Dedup by name
    $seen = []; $uniq = [];
    foreach ($out as $f) {
        $k = rq_slugify($f['name']);
        if (isset($seen[$k])) continue;
        $seen[$k] = true; $uniq[] = $f;
    }
    return $uniq;
}

/** Knowledge graph: entity-name <-> scene adjacency via sceneIds + relatedKnowledgeIds. */
function rq_knowledge_graph(array $knowledge, array $sceneIdentity): array {
    $graph = ['entities' => [], 'scenes' => []];
    foreach (($sceneIdentity['scenes'] ?? []) as $id => $sc) {
        $graph['scenes'][(string)$id] = [
            'name' => (string)($sc['displayName'] ?? $sc['placeName'] ?? $id),
            'entities' => array_values(array_unique(array_map('strval', (array)($sc['relatedKnowledgeIds'] ?? [])))),
        ];
    }
    foreach (($knowledge['entries'] ?? []) as $e) {
        $graph['entities'][(string)($e['id'] ?? '')] = [
            'name' => (string)($e['title'] ?? ''),
            'types' => array_values(array_unique(array_merge((array)($e['categories'] ?? []), [(string)($e['type'] ?? '')]))),
        ];
    }
    return $graph;
}

/** Hotspot-based navigation graph (scene neighbors) — for "rute/lanjut ke mana" questions. */
function rq_nav_graph(array $parsed): array {
    $g = [];
    foreach (($parsed['scenes'] ?? []) as $s) $g[(string)($s['id'] ?? '')] = [];
    foreach (($parsed['hotspots'] ?? []) as $h) {
        $from = (string)($h['scene'] ?? ''); $to = (string)($h['target'] ?? '');
        if ($from !== '' && $to !== '' && $from !== $to && isset($g[$from])) $g[$from][] = $to;
    }
    foreach ($g as &$n) $n = array_values(array_unique($n));
    return $g;
}

/** Curated answers → topic keywords/names the location actually knows about. */
function rq_curated_topics(array $curated): array {
    $topics = [];
    foreach (($curated['intents'] ?? []) as $i) {
        $cat = (string)($i['category'] ?? '');
        $resp = trim((string)($i['response'] ?? ''));
        if ($resp !== '') $topics[] = ['id' => (string)($i['id'] ?? ''), 'category' => $cat, 'summary' => mb_substr($resp, 0, 160)];
    }
    return $topics;
}

/** Build a compact, high-signal context pack for the LLM (RAG-lite). */
function rq_context_pack(string $slug): array {
    $dir = TOURS_DIR . '/' . $slug;
    $knowledge = rq_read_json($dir . '/knowledge.json');
    $sceneIdentity = rq_read_json($dir . '/scene-identity.json');
    $curated = rq_read_json($dir . '/curated-answers.json');
    $parsed = rq_read_json($dir . '/parsed.json');
    $existing = rq_read_json($dir . '/recommended-questions.json');
    $tourName = (string)($existing['tourName'] ?? $knowledge['tourId'] ?? $slug);

    return [
        'tourName' => $tourName,
        'slug' => $slug,
        'entities' => rq_entities($knowledge),
        'sceneFacts' => rq_scene_facts($sceneIdentity),
        'curatedTopics' => rq_curated_topics($curated),
        'navGraph' => rq_nav_graph($parsed),
        'graph' => rq_knowledge_graph($knowledge, $sceneIdentity),
    ];
}

/* ================================================================
 * LLM CALL (reuse existing provider stack from a deployed package)
 * ================================================================ */

/** Provider lib is identical across packages; require ONCE so no redeclare clash. */
function rq_ensure_provider(): string {
    static $loaded = '';
    if ($loaded !== '') return $loaded;
    $pkg = TOURS_DIR . '/zie-batik/package/govirtual-ai';
    if (!is_file($pkg . '/api/ai-provider.php')) return '';
    require_once $pkg . '/api/ai-provider.php';
    // ai-provider.php pulls ai-core.php which loads .env of the package. Good — envs already set.
    $loaded = $pkg;
    return $loaded;
}

function rq_provider_call(string $slug, string $system, string $user): array {
    $pkg = rq_ensure_provider();
    if ($pkg === '') return ['success' => false, 'error' => 'no_provider_lib', 'content' => ''];

    $provider = strtolower(trim(getenv('AI_PROVIDER') ?: 'gemini'));
    $options = [
        'provider' => $provider,
        'model' => ai_provider_model($provider),
        'temperature' => 0.4,
        'max_tokens' => 900,
        'json_mode' => true,
        'system' => $system,
        'user' => $user,
        'timeout' => 45,
    ];
    return ai_provider_router(
        [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        $options
    );
}

/* ================================================================
 * PROMPT BUILDERS
 * ================================================================ */

function rq_build_system(): string {
    return <<<'EOS'
Anda adalah kurator konten untuk platform tur virtual (AI Guide). Tugas Anda membuat "suggestion questions" (pertanyaan rekomendasi yang bisa diklik pengguna) yang SANGAT spesifik dan kontekstual untuk SATU lokasi tur.

Aturan ketat:
1. Setiap pertanyaan harus menyebut atau menyiratkan entitas/fakta nyata dari lokasi (sejarah, mitos lokal, arsitektur, spot foto, aktivitas, objek info, area dalam tur). JANGAN pakai pertanyaan generik yang bisa dipakai di lokasi mana pun (mis. "Di mana toilet?").
2. Gunakan BAHASA INDONESIA yang kasual, menarik, mengundang rasa penasaran, seperti pengguna bicara ke pemandu lokal.
3. Variasikan bentuk: tanya sejarah, asal nama, arsitektur, highlight, aktivitas, rute lanjutan, fasilitas, layanan publik, objek ikonik.
4. Output MURNI JSON (tanpa teks lain), dengan bentuk:
{
  "starters": [ { "label": "...", "query": "...", "desc": "..." }, ... x3 ],
  "themes": [ { "label": "...", "query": "..." }, ... x6 ],
  "fallback": [ { "q": "...", "intent": "NAME_ORIGIN|TOUR_HIGHLIGHTS|TOUR_HISTORY|NAVIGATION|TOUR_DESCRIPTION" }, ... x4 ],
  "workspaceHeader": "MUNGKIN ANDA INGIN TAHU"
}
Aturan output:
- "starters" = 3 pertanyaan pembuka untuk overlay awal (label pendek, query kalimat utuh, desc = query).
- "themes" = 6 label topik (label pendek 1-3 kata) + query kalimat utuh yang bisa dijelajahi.
- "fallback" = 4 pertanyaan dipakai saat panorama identity-only (bukan per-scene, tetap spesifik ke lokasi).
- Semua query harus spesifik ke lokasi, tidak template. Jangan gunakan placeholder [NAMA].
EOS;
}

function rq_build_user(array $ctx): string {
    $parts = [];
    $parts[] = "LOKASI: " . $ctx['tourName'] . " (slug: " . $ctx['slug'] . ")";

    if ($ctx['entities']) {
        $parts[] = "\nENTITAS/OBJEK DI LOKASI INI (nama nyata — wajib dipakai sebagian):";
        foreach (array_slice($ctx['entities'], 0, 40) as $e) {
            $line = "- " . $e['name'] . " (" . $e['type'] . ")";
            if ($e['aliases']) $line .= " | alias: " . implode(', ', array_slice($e['aliases'], 0, 3));
            if ($e['content'] !== '' && $e['content'] !== 'Pusat pengalaman virtual untuk ' . $ctx['tourName'] . '.') {
                $line .= " | " . mb_substr($e['content'], 0, 140);
            }
            $parts[] = $line;
        }
    } else {
        $parts[] = "\nENTITAS/OBJEK DI LOKASI INI: (tidak tersedia)";
    }

    if ($ctx['sceneFacts']) {
        $parts[] = "\nAREA/PANORAMA DI TUR INI (deskripsi + highlight + poin bicara):";
        foreach (array_slice($ctx['sceneFacts'], 0, 30) as $f) {
            $line = "- " . $f['name'];
            if ($f['highlights']) $line .= " | highlight: " . implode('; ', array_slice($f['highlights'], 0, 3));
            if ($f['talkingPoints']) $line .= " | poin: " . implode('; ', array_slice($f['talkingPoints'], 0, 3));
            if ($f['description'] !== '') $line .= " | " . mb_substr($f['description'], 0, 160);
            $parts[] = $line;
        }
    }

    if ($ctx['curatedTopics']) {
        $parts[] = "\nTOPIC YANG SUDAH DIKURASI (pakai konteks ini untuk pertanyaan sejarah/aktivitas/spot):";
        foreach ($ctx['curatedTopics'] as $t) $parts[] = "- [" . $t['category'] . "] " . $t['summary'];
    }

    if ($ctx['navGraph']) {
        $parts[] = "\nGRAF NAVIGASI (tetangga panorama — untuk pertanyaan rute/lanjut):";
        foreach (array_slice($ctx['navGraph'], 0, 15) as $from => $to) {
            if ($to) $parts[] = "- " . $from . " -> " . implode(', ', array_slice($to, 0, 6));
        }
    }

    $parts[] = "\nSekarang hasilkan suggestion questions baru untuk lokasi ini. Output JSON murni.";
    return implode("\n", $parts);
}

/* ================================================================
 * PARSING / VALIDATION
 * ================================================================ */

function rq_extract_json(string $content): ?array {
    $content = trim($content);
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
    $content = trim($content);
    $decoded = json_decode($content, true);
    if (is_array($decoded)) return $decoded;
    // tolerant scan for first {..}
    $start = strpos($content, '{');
    if ($start === false) return null;
    $depth = 0; $inStr = false; $esc = false;
    for ($i = $start, $n = strlen($content); $i < $n; $i++) {
        $c = $content[$i];
        if ($inStr) { if ($esc) $esc = false; elseif ($c === '\\') $esc = true; elseif ($c === '"') $inStr = false; continue; }
        if ($c === '"') $inStr = true;
        elseif ($c === '{') $depth++;
        elseif ($c === '}') { $depth--; if ($depth === 0) { $cand = json_decode(substr($content, $start, $i - $start + 1), true); if (is_array($cand)) return $cand; break; } }
    }
    return null;
}

/** Normalize AI output into the canonical recommended-questions.json schema. */
function rq_validate(array $parsed, array $ctx): array {
    $tn = $ctx['tourName'];
    $norm = static function (string $s): string {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\w\s]/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    };

    $intents = ['NAME_ORIGIN', 'TOUR_HIGHLIGHTS', 'TOUR_HISTORY', 'NAVIGATION', 'TOUR_DESCRIPTION'];

    // Starters: 3 items, each with label/query/desc
    $starters = [];
    $seenStarters = [];
    foreach (($parsed['starters'] ?? []) as $s) {
        if (count($starters) >= 3) break;
        $label = trim((string)($s['label'] ?? ''));
        $query = trim((string)($s['query'] ?? ''));
        if ($label === '' || $query === '') continue;
        $k = $norm($query);
        if (isset($seenStarters[$k])) continue;
        $seenStarters[$k] = true;
        $starters[] = [
            'label' => $label,
            'query' => $query,
            'desc' => trim((string)($s['desc'] ?? $query)),
        ];
    }
    // Pad with tour-name-based defaults if AI gave fewer than 3
    while (count($starters) < 3) {
        $idx = count($starters);
        $pads = [
            ['label' => 'Apa yang menarik di sini?', 'query' => 'Apa yang menarik di area ini?', 'desc' => 'Apa yang menarik di area ini?'],
            ['label' => "Mengapa disebut $tn?", 'query' => "Mengapa disebut $tn?", 'desc' => "Mengapa disebut $tn?"],
            ['label' => 'Jelaskan tempat ini', 'query' => "Ceritakan tentang $tn", 'desc' => "Ceritakan tentang $tn"],
        ];
        $starters[] = $pads[$idx];
    }

    // Themes: 6 items, each with label/query
    $themes = [];
    $seenThemes = [];
    foreach (($parsed['themes'] ?? []) as $t) {
        if (count($themes) >= 6) break;
        $label = trim((string)($t['label'] ?? ''));
        $query = trim((string)($t['query'] ?? ''));
        if ($label === '' || $query === '') continue;
        $k = $norm($query);
        if (isset($seenThemes[$k])) continue;
        $seenThemes[$k] = true;
        $themes[] = ['label' => $label, 'query' => $query];
    }
    while (count($themes) < 6) {
        $idx = count($themes);
        $pads = [
            ['label' => 'Sejarah', 'query' => "Sejarah $tn"],
            ['label' => 'Arsitektur', 'query' => "Arsitektur $tn"],
            ['label' => 'Highlight', 'query' => "Apa yang menarik dari $tn?"],
            ['label' => 'Fasilitas', 'query' => 'Fasilitas di sini'],
            ['label' => 'Rute', 'query' => 'Ke mana sebaiknya saya lanjut?'],
            ['label' => 'Layanan', 'query' => 'Layanan publik'],
        ];
        $themes[] = $pads[$idx];
    }

    // Fallback: 4 items, each with q/intent
    $fallback = [];
    $seenFallback = [];
    foreach (($parsed['fallback'] ?? []) as $f) {
        if (count($fallback) >= 4) break;
        $q = trim((string)($f['q'] ?? $f['query'] ?? ''));
        $intent = strtoupper(trim((string)($f['intent'] ?? '')));
        if ($q === '') continue;
        if (!in_array($intent, $intents, true)) $intent = 'TOUR_DESCRIPTION';
        $k = $norm($q);
        if (isset($seenFallback[$k])) continue;
        $seenFallback[$k] = true;
        $fallback[] = ['q' => $q, 'intent' => $intent];
    }
    while (count($fallback) < 4) {
        $idx = count($fallback);
        $pads = [
            ['q' => "Apa asal nama $tn?", 'intent' => 'NAME_ORIGIN'],
            ['q' => "Apa yang menarik dari $tn?", 'intent' => 'TOUR_HIGHLIGHTS'],
            ['q' => "Apa sejarah $tn?", 'intent' => 'TOUR_HISTORY'],
            ['q' => 'Ke mana sebaiknya saya lanjut?', 'intent' => 'NAVIGATION'],
        ];
        $fallback[] = $pads[$idx];
    }

    $workspaceHeader = trim((string)($parsed['workspaceHeader'] ?? ''));
    if ($workspaceHeader === '') $workspaceHeader = 'MUNGKIN ANDA INGIN TAHU';

    return [
        'version' => '1.0',
        'tourId' => $ctx['slug'],
        'tourName' => $tn,
        'updatedAt' => date('c'),
        'state1' => ['starters' => $starters, 'themes' => $themes],
        'state2' => ['fallback' => $fallback, 'workspaceHeader' => $workspaceHeader],
        // ponytail: notes field omitted from prompt; add if AI ever returns it
    ];
}

/* ================================================================
 * MAIN
 * ================================================================ */

$slugs = $onlySlug !== '' && $onlySlug !== 'all' ? [$onlySlug] : rq_tour_slugs();
$total = count($slugs);
$ok = 0; $fail = 0;

echo "=== REGEN QUESTIONS ===\n";
echo "Tours: $total | Dry-run: " . ($dryRun ? 'YES' : 'NO') . "\n";
echo "Mode: " . ($onlySlug !== '' && $onlySlug !== 'all' ? 'single: ' . $onlySlug : 'all') . "\n";
echo "========================\n\n";

foreach ($slugs as $i => $slug) {
    $num = $i + 1;
    echo "[$num/$total] $slug... ";

    $ctx = rq_context_pack($slug);
    echo 'ctx:' . count($ctx['entities']) . 'e ' . count($ctx['sceneFacts']) . 's ' . count($ctx['curatedTopics']) . 'c ';

    if ($dryRun) {
        echo "DRY (skip provider)\n";
        $ok++;
        continue;
    }

    $system = rq_build_system();
    $user = rq_build_user($ctx);
    $result = rq_provider_call($slug, $system, $user);

    if (!$result['success']) {
        echo "FAIL: " . ($result['error'] ?? 'unknown') . "\n";
        $fail++;
        continue;
    }

    $content = (string)($result['content'] ?? '');
    $parsed = rq_extract_json($content);
    if (!$parsed) {
        echo "FAIL: unparseable JSON\n";
        $fail++;
        continue;
    }

    $validated = rq_validate($parsed, $ctx);
    $json = json_encode($validated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        echo "FAIL: json_encode error\n";
        $fail++;
        continue;
    }

    // Write source
    $srcFile = TOURS_DIR . '/' . $slug . '/recommended-questions.json';
    if (file_put_contents($srcFile, $json) === false) {
        echo "FAIL: write\n";
        $fail++;
        continue;
    }

    // Write package data copy
    $pkgDir = TOURS_DIR . '/' . $slug . '/package/govirtual-ai/data';
    if (!is_dir($pkgDir)) @mkdir($pkgDir, 0777, true);
    $pkgFile = $pkgDir . '/recommended-questions.json';
    $pkgOk = false;
    if (is_dir($pkgDir)) {
        $pkgOk = file_put_contents($pkgFile, $json) !== false;
    }

    echo "OK (pkg-copy:" . ($pkgOk ? 'yes' : 'no') . ")\n";
    $ok++;
}

echo "\n=== DONE: $ok OK, $fail FAIL ===\n";
if ($ok > 0 && !$dryRun) {
    echo "==> Run Save+Generate (Build) in hub to push to export paths.\n";
}

exit($fail > 0 ? 1 : 0);