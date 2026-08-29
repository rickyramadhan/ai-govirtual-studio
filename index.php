<?php

declare(strict_types=1);

const APP_ROOT = __DIR__;
const DB_FILE = APP_ROOT . '/storage/app.sqlite';
const TOURS_DIR = APP_ROOT . '/storage/tours';
const SOURCE_AI = 'C:/xampp/htdocs/govirtual-ai/govirtual-ai';
require_once __DIR__ . '/studio.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function slugify(string $s): string { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? ''; return trim($s, '-') ?: 'tour-' . date('YmdHis'); }
function ensure_dirs(): void { foreach ([APP_ROOT . '/storage', TOURS_DIR] as $d) if (!is_dir($d)) mkdir($d, 0777, true); }
function cities_dir(): string { return APP_ROOT . '/storage/cities'; }
function city_dir(string $slug): string { return cities_dir() . '/' . $slug; }
function city_slugify(string $s): string { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? ''; return trim($s, '-') ?: 'kota-' . date('YmdHis'); }
function all_cities(): array {
    $dir = cities_dir(); if (!is_dir($dir)) return [];
    $cities = [];
    foreach (array_filter(scandir($dir), fn($f) => $f !== '.' && $f !== '..' && is_dir($dir . '/' . $f)) as $slug) {
        $meta = read_json($dir . '/' . $slug . '/meta.json', []);
        $cities[] = ['slug' => $slug, 'name' => (string)($meta['name'] ?? ucwords(str_replace('-', ' ', $slug))), 'entries' => count(read_json($dir . '/' . $slug . '/knowledge.json', [])['entries'] ?? []), 'updated_at' => (string)($meta['updatedAt'] ?? '')];
    }
    usort($cities, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
    return $cities;
}
function city_knowledge(string $slug): array { return read_json(city_dir($slug) . '/knowledge.json', ['version' => 1, 'city' => $slug, 'entries' => []]); }
function city_sources(string $slug): array { return read_json(city_dir($slug) . '/sources.json', ['sources' => []]); }
function city_save_knowledge(string $slug, array $data): void { $data['updatedAt'] = date('c'); write_json(city_dir($slug) . '/knowledge.json', $data); $m = read_json(city_dir($slug) . '/meta.json', []); $m['updatedAt'] = date('c'); $m['name'] = $m['name'] ?? $slug; write_json(city_dir($slug) . '/meta.json', $m); }
function city_save_sources(string $slug, array $data): void { write_json(city_dir($slug) . '/sources.json', $data); }
function city_raw_dir(string $slug): string { $d = city_dir($slug) . '/raw'; if (!is_dir($d)) mkdir($d, 0777, true); return $d; }
/** Ingest text chunk (dari URL fetch atau file upload) menjadi knowledge entries. */
function city_ingest_text(string $slug, string $text, string $sourceName, string $sourceType): int {
    $kb = city_knowledge($slug);
    $chunks = preg_split('/\n\s*\n|\n(?=[A-Z0-9][^\n]{2,60}\n)/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?? [];
    $added = 0; $used = [];
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk); if (mb_strlen($chunk) < 80) continue;
        $lines = array_values(array_filter(array_map('trim', explode("\n", $chunk)), fn($l) => $l !== ''));
        $title = mb_substr($lines[0] ?? '', 0, 120);
        if ($title === '') continue;
        $title = preg_replace('/[.:;]+$/', '', $title);
        if (isset($used[mb_strtolower($title)])) continue;
        $used[mb_strtolower($title)] = true;
        $body = implode("\n", array_slice($lines, 1));
        if ($body === '') $body = $chunk;
        $id = 'src:' . city_slugify($title);
        $kb['entries'][] = ['id' => $id, 'entityId' => $id, 'title' => $title, 'aliases' => [], 'type' => 'city-information', 'categories' => ['informasi kota'], 'tags' => ['source', $sourceType], 'sceneIds' => [], 'content' => mb_substr($body, 0, 4000), 'source' => $sourceName, 'sourceType' => $sourceType, 'provenance' => 'ingested', 'translations' => ['id' => ['title' => $title, 'content' => mb_substr($body, 0, 4000)], 'en' => ['title' => $title, 'content' => mb_substr($body, 0, 4000)]]];
        $added++;
    }
    if ($added > 0) city_save_knowledge($slug, $kb);
    return $added;
}
/** Fetch + parse text dari URL (HTML/plain). SSRF-dibatasi ke http/https publik. */
function city_fetch_url(string $url): array {
    if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'error' => 'URL harus http/https.'];
    $host = parse_url($url, PHP_URL_HOST);
    if ($host === false || $host === null || $host === '') return ['ok' => false, 'error' => 'URL tidak valid.'];
    if (filter_var($host, FILTER_VALIDATE_IP) || strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.local')) return ['ok' => false, 'error' => 'URL ke alamat internal/lokal dilarang.'];
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'user_agent' => 'Mozilla/5.0 (compatible; AIGuideHub/1.0)'], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['ok' => false, 'error' => 'Gagal mengambil URL.'];
    // Deteksi halaman challenge WAF (SafeLine/Cloudflare/dll) yang memblokir non-browser.
    $low = strtolower($raw);
    if (preg_match('#(safeline|slg-bg|slg-box|slg-title|challenge-platform|cf-browser-verification|vaptcha|waf[-_]?challenge|sec-cpt|verify you are human|just a moment)#', $low)) return ['ok' => false, 'error' => 'Website dilindungi WAF (firewall anti-bot) dan menolak fetch otomatis. Unduh halaman sebagai file HTML lalu upload via form Upload File.'];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $raw);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//script|//style|//noscript') as $n) $n->parentNode->removeChild($n);
    $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{3,}/', "\n\n", (string)$dom->textContent)) ?? '');
    if (mb_strlen($text) < 40) return ['ok' => false, 'error' => 'Konten terlalu pendek/kosong. Mungkin halaman di-render via JavaScript (SPA) — unduh file HTML lalu upload manual.'];
    return ['ok' => true, 'text' => $text];
}
/** Parse file upload (txt/md/csv → teks; docx → teks via XML; pdf → teks mentah). */
function city_parse_file(string $path, string $ext): string {
    $ext = strtolower($ext);
    if ($ext === 'txt' || $ext === 'md' || $ext === 'csv') return (string)file_get_contents($path);
    if ($ext === 'html' || $ext === 'htm') {
        $raw = (string)file_get_contents($path);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $raw);
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript') as $n) $n->parentNode->removeChild($n);
        return trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{3,}/', "\n\n", (string)$dom->textContent)) ?? '');
    }
    if ($ext === 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) { $xml = $zip->getFromName('word/document.xml'); $zip->close(); if ($xml !== false) return trim(preg_replace('/\s+/', ' ', (string)strip_tags($xml)) ?? ''); }
        return '';
    }
    if ($ext === 'pdf') { $t = (string)file_get_contents($path); if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $t, $m)) return trim(implode("\n", array_map(fn($s) => preg_replace('/[^\x20-\x7E\x0A\x0D]/', ' ', $s), $m[1])) ?? ''); return ''; }
    if ($ext === 'doc') return trim((string)file_get_contents($path)); // .doc legacy → teks mentah
    return '';
}
/** Sync semua package tours lokasi tertentu dengan city knowledge (city-knowledge.json). */
function city_sync_packages(string $citySlug): array {
    $kb = city_knowledge($citySlug);
    $cityName = (string)(read_json(city_dir($citySlug) . '/meta.json', [])['name'] ?? $citySlug);
    $instructions = (string)(read_json(city_dir($citySlug) . '/meta.json', [])['instructions'] ?? '');
    $synced = [];
    foreach (all_tours() as $t) {
        if (city_slugify((string)$t['location']) !== $citySlug) continue;
        $pkg = tour_dir($t['slug']) . '/package/govirtual-ai/data';
        if (!is_dir($pkg)) continue;
        $out = $kb;
        $out['city'] = $cityName;
        write_json($pkg . '/city-knowledge.json', $out);
        write_json($pkg . '/city-instructions.json', ['city' => $cityName, 'instructions' => $instructions]);
        $synced[] = $t['slug'];
    }
    return $synced;
}
/** Seed city dirs dari lokasi tour yang belum punya knowledge kota (mis. Semarang). */
function city_seed_from_tours(): void {
    foreach (all_tours() as $t) {
        $slug = city_slugify((string)$t['location']);
        if ($slug === '' || is_dir(city_dir($slug))) continue;
        $dir = city_dir($slug);
        mkdir($dir, 0777, true);
        write_json($dir . '/meta.json', ['name' => trim((string)$t['location']), 'updatedAt' => date('c')]);
        write_json($dir . '/knowledge.json', ['version' => 1, 'city' => $slug, 'entries' => []]);
        write_json($dir . '/sources.json', ['sources' => []]);
    }
}
function db(): PDO {
    ensure_dirs();
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS tours (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE NOT NULL, name TEXT NOT NULL, location TEXT NOT NULL DEFAULT "Semarang", category TEXT NOT NULL DEFAULT "", export_path TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "draft", created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    // migration: category column (DB lama tanpa kolom)
    $cols = array_column($pdo->query('PRAGMA table_info(tours)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('category', $cols, true)) $pdo->exec('ALTER TABLE tours ADD COLUMN category TEXT NOT NULL DEFAULT ""');
    $pdo->exec('CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT NOT NULL, label TEXT NOT NULL DEFAULT "", api_key TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, priority INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (skey TEXT PRIMARY KEY, svalue TEXT NOT NULL DEFAULT "", updated_at TEXT NOT NULL)');
    return $pdo;
}
function api_key_list(string $provider = ''): array {
    $pdo = db();
    if ($provider !== '') { $s = $pdo->prepare('SELECT * FROM api_keys WHERE provider=? ORDER BY priority ASC, id ASC'); $s->execute([$provider]); return $s->fetchAll(PDO::FETCH_ASSOC); }
    return $pdo->query('SELECT * FROM api_keys ORDER BY provider ASC, priority ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
}
function api_key_save(string $provider, string $label, string $key, int $isActive = 1, int $priority = 0, int $id = 0): int {
    $pdo = db(); $now = date('c');
    if ($id) { $s = $pdo->prepare('UPDATE api_keys SET provider=?, label=?, api_key=?, is_active=?, priority=?, updated_at=? WHERE id=?'); $s->execute([$provider, $label, $key, $isActive, $priority, $now, $id]); return $id; }
    $s = $pdo->prepare('INSERT INTO api_keys(provider,label,api_key,is_active,priority,created_at,updated_at) VALUES(?,?,?,?,?,?,?)'); $s->execute([$provider, $label, $key, $isActive, $priority, $now, $now]); return (int)$pdo->lastInsertId();
}
function api_key_delete(int $id): void { $s = db()->prepare('DELETE FROM api_keys WHERE id=?'); $s->execute([$id]); }
function api_key_active_keys(string $provider): array {
    $rows = api_key_list($provider); $keys = [];
    foreach ($rows as $r) if ((int)$r['is_active'] === 1 && trim($r['api_key']) !== '') $keys[] = trim($r['api_key']);
    return $keys;
}
function setting_get(string $key, string $default = ''): string { $s = db()->prepare('SELECT svalue FROM settings WHERE skey=?'); $s->execute([$key]); $v = $s->fetchColumn(); return $v !== false ? (string)$v : $default; }
function setting_set(string $key, string $value): void { $pdo = db(); $now = date('c'); $s = $pdo->prepare('INSERT INTO settings(skey,svalue,updated_at) VALUES(?,?,?) ON CONFLICT(skey) DO UPDATE SET svalue=excluded.svalue, updated_at=excluded.updated_at'); $s->execute([$key, $value, $now]); }
/**
 * List every provider that has at least one key row in the hub database.
 * Order: known providers first (gemini, openrouter, groq, ollama), then the rest alphabetically.
 */
function api_key_providers(): array {
    $rows = db()->query('SELECT DISTINCT provider FROM api_keys ORDER BY provider ASC')->fetchAll(PDO::FETCH_COLUMN);
    $known = ['gemini', 'openrouter', 'groq', 'ollama'];
    $rest = array_values(array_filter($rows, fn($p) => !in_array($p, $known, true)));
    return array_merge($known, $rest);
}
function provider_env_name(string $provider): string {
    return strtoupper(preg_replace('/[^a-zA-Z0-9]+/', '_', $provider));
}
/**
 * Generate .env content from the hub database (settings + api_keys).
 * Layout: umum → GEMINI (primary + backup) → OPENROUTER (primary + backup) → GROQ → OLLAMA → provider lain (custom, OpenAI-compatible).
 */
function env_render_from_db(): string {
    $L = [];
    $L[] = '# =====================================================';
    $L[] = '# AI Guide Hub - auto-generated .env (di-manage via hub ?a=keys)';
    $L[] = '# JANGAN edit manual di sini - semua perubahan akan ditimpa saat Deploy.';
    $L[] = '# Generated: ' . date('c');
    $L[] = '# =====================================================';
    $L[] = '';
    $L[] = 'AI_PROVIDER=' . (setting_get('AI_PROVIDER', 'gemini'));
    $fb = setting_get('AI_FALLBACK_PROVIDERS', 'openrouter');
    $L[] = 'AI_FALLBACK_PROVIDERS=' . $fb;
    $L[] = 'AI_REQUEST_TIMEOUT=' . setting_get('AI_REQUEST_TIMEOUT', '25');
    $L[] = 'AI_DEBUG=' . setting_get('AI_DEBUG', 'false');
    $L[] = 'AI_REASONING_MODE=' . setting_get('AI_REASONING_MODE', 'balanced');
    $L[] = 'OLLAMA_NUM_PREDICT=' . setting_get('OLLAMA_NUM_PREDICT', '600');
    $L[] = 'OLLAMA_TEMPERATURE=' . setting_get('OLLAMA_TEMPERATURE', '0.2');
    $L[] = '';
    // GEMINI keys
    $gk = api_key_active_keys('gemini');
    if (!$gk) $gk = [setting_get('GEMINI_API_KEY', '')];
    if (!$gk[0]) $gk[0] = setting_get('GEMINI_API_KEY', '');
    $L[] = '# --- Gemini (primary + backups) ---';    $L[] = 'GEMINI_API_KEY=' . $gk[0];
    foreach (array_slice($gk, 1) as $i => $k) $L[] = 'GEMINI_API_KEY_' . ($i + 1) . '=' . $k;
    $L[] = 'GEMINI_MODEL=' . setting_get('GEMINI_MODEL', 'gemini-3.5-flash-lite');
    $L[] = 'GEMINI_FALLBACK_MODELS=' . setting_get('GEMINI_FALLBACK_MODELS', 'gemini-3.5-flash');
    $L[] = '';
    // OPENROUTER keys
    $ok = api_key_active_keys('openrouter');
    if (!$ok) $ok = [setting_get('OPENROUTER_API_KEY', '')];
    if (!$ok[0]) $ok[0] = setting_get('OPENROUTER_API_KEY', '');
    $L[] = '# --- OpenRouter (primary + backups) ---';
    $L[] = 'OPENROUTER_API_KEY=' . $ok[0];
    foreach (array_slice($ok, 1) as $i => $k) $L[] = 'OPENROUTER_API_KEY_' . ($i + 1) . '=' . $k;
    $L[] = 'OPENROUTER_MODEL=' . setting_get('OPENROUTER_MODEL', 'openai/gpt-4o-mini');
    $L[] = 'OPENROUTER_BASE_URL=' . setting_get('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1');
    $L[] = '';
    // GROQ
    $gr = api_key_active_keys('groq');
    if (!$gr) $gr = [setting_get('GROQ_API_KEY', '')];
    if (!$gr[0]) $gr[0] = setting_get('GROQ_API_KEY', '');
    $L[] = '# --- Groq (primary + backups) ---';
    $L[] = 'GROQ_API_KEY=' . $gr[0];
    foreach (array_slice($gr, 1) as $i => $k) $L[] = 'GROQ_API_KEY_' . ($i + 1) . '=' . $k;
    $L[] = 'GROQ_MODEL=' . setting_get('GROQ_MODEL', 'groq/compound');
    $L[] = '';
    // OLLAMA
    $L[] = '# --- Ollama (lokal, opsional) ---';
    $L[] = 'OLLAMA_ENABLED=' . setting_get('OLLAMA_ENABLED', 'false');
    $L[] = 'OLLAMA_URL=' . setting_get('OLLAMA_URL', 'http://127.0.0.1:11434');
    $L[] = 'OLLAMA_MODEL=' . setting_get('OLLAMA_MODEL', 'qwen2.5:7b');
    $L[] = 'OLLAMA_EMBED_MODEL=' . setting_get('OLLAMA_EMBED_MODEL', 'nomic-embed-text');
    $L[] = '';
    // CUSTOM / OTHER providers (OpenAI-compatible). Env pattern: {PROVIDER}_API_KEY, _MODEL, _BASE_URL.
    foreach (api_key_providers() as $prov) {
        if (in_array($prov, ['gemini', 'openrouter', 'groq', 'ollama'], true)) continue;
        $envName = provider_env_name($prov);
        $keys = api_key_active_keys($prov);
        if (!$keys) $keys = [setting_get($envName . '_API_KEY', '')];
        if (!$keys[0]) $keys[0] = setting_get($envName . '_API_KEY', '');
        $L[] = '# --- ' . ucfirst($prov) . ' (OpenAI-compatible) ---';
        $L[] = $envName . '_API_KEY=' . $keys[0];
        foreach (array_slice($keys, 1) as $i => $k) $L[] = $envName . '_API_KEY_' . ($i + 1) . '=' . $k;
        $L[] = $envName . '_MODEL=' . setting_get($envName . '_MODEL', '');
        $L[] = $envName . '_BASE_URL=' . setting_get($envName . '_BASE_URL', 'https://api.' . $prov . '.com/v1');
        $L[] = '';
    }
    return implode("\n", $L);
}
function env_write_to(string $targetEnv): void {
    $dir = dirname($targetEnv); if (!is_dir($dir)) mkdir($dir, 0777, true);
    file_put_contents($targetEnv, env_render_from_db());
}
/**
 * Sync the generated .env to: master SOURCE_AI, semua tour package, dan semua deploy (export_path).
 * Return list of written paths.
 */
function env_sync_all(): array {
    $written = [];
    $targets = [];
    // master
    $targets[] = SOURCE_AI . '/.env';
    // semua tour packages
    foreach (all_tours() as $t) {
        $pkg = tour_dir($t['slug']) . '/package/govirtual-ai/.env';
        if (is_dir(dirname($pkg))) $targets[] = $pkg;
        $exp = rtrim((string)$t['export_path'], '/\\');
        if ($exp !== '' && is_dir($exp . '/govirtual-ai')) $targets[] = $exp . '/govirtual-ai/.env';
    }
    foreach (array_unique($targets) as $envPath) {
        if (!is_dir(dirname($envPath))) continue;
        env_write_to($envPath); $written[] = $envPath;
    }
    return $written;
}
function tour_dir(string $slug): string { return TOURS_DIR . '/' . $slug; }
function read_json(string $file, array $fallback = []): array { $raw = is_file($file) ? json_decode((string)file_get_contents($file), true) : null; return is_array($raw) ? $raw : $fallback; }
function write_json(string $file, array $data): void { if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true); file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
function content_files(): array { return ['curated-answers' => 'Curated Questions', 'knowledge' => 'Knowledge', 'scene-identity' => 'Scene Identity', 'visitor-tips' => 'Visitor Tips', 'nearby' => 'Nearby', 'recommended-questions' => 'Recommended Questions (State1/State2)', 'flow' => 'Navigation Flow']; }
function content_template_flow(array $tour, array $parsedScenes): array {
    $order = array_map(fn($s) => (string)$s['id'], $parsedScenes);
    $nodes = [];
    foreach ($parsedScenes as $s) {
        $id = (string)$s['id'];
        $nodes[$id] = ['displayName' => (string)($s['displayName'] ?? $s['name'] ?? $id)];
    }
    return ['version' => '1.0', 'tourId' => $tour['slug'], 'tourName' => $tour['name'], 'updatedAt' => date('c'), 'startScene' => $order[0] ?? '', 'order' => $order, 'nodes' => $nodes];
}
function content_template_recommended_questions(array $tour): array {
    $tn = $tour['name'];
    return [
        'version' => '1.0',
        'tourId' => $tour['slug'],
        'tourName' => $tn,
        'updatedAt' => date('c'),
        'state1' => [
            'starters' => [
                ['label' => 'Apa yang menarik di sini?', 'query' => 'Apa yang menarik di area ini?', 'desc' => 'Apa yang menarik di area ini?'],
                ['label' => 'Mengapa disebut '.$tn.'?', 'query' => 'Mengapa disebut '.$tn.'?', 'desc' => 'Mengapa disebut '.$tn.'?'],
                ['label' => 'Jelaskan tempat ini', 'query' => 'Ceritakan tentang '.$tn, 'desc' => 'Ceritakan tentang '.$tn],
            ],
            'themes' => [
                ['label' => 'Sejarah', 'query' => 'Sejarah '.$tn],
                ['label' => 'Arsitektur', 'query' => 'Arsitektur '.$tn],
                ['label' => 'Highlight', 'query' => 'Apa yang menarik dari '.$tn.'?'],
                ['label' => 'Fasilitas', 'query' => 'Fasilitas di sini'],
                ['label' => 'Rute', 'query' => 'Ke mana sebaiknya saya lanjut?'],
                ['label' => 'Layanan', 'query' => 'Layanan publik'],
            ],
        ],
        'state2' => [
            'fallback' => [
                ['q' => 'Apa asal nama '.$tn.'?', 'intent' => 'NAME_ORIGIN'],
                ['q' => 'Apa yang menarik dari '.$tn.'?', 'intent' => 'TOUR_HIGHLIGHTS'],
                ['q' => 'Apa sejarah '.$tn.'?', 'intent' => 'TOUR_HISTORY'],
                ['q' => 'Ke mana sebaiknya saya lanjut?', 'intent' => 'NAVIGATION'],
            ],
            'workspaceHeader' => 'MUNGKIN ANDA INGIN TAHU',
        ],
        'notes' => 'Edit starter/theme per lokasi. State1 = Discovery (COBA TANYAKAN & JELAJAHI TOPIK). State2 fallback dipakai saat panorama identity-only.'
    ];
}
function content_template_curated(array $tour, array $sceneNames): array {
    $tn = $tour['name'];
    $tnl = mb_strtolower($tn);
    $loc = $tour['location'] ?? 'area';
    // filter out literal "default" titles — source data artifact, not real names
    $good = array_values(array_filter($sceneNames, fn($n) => mb_strtolower(trim($n)) !== 'default'));
    $sceneA = $good[0] ?? $tn . ' Area 1';
    $sceneB = $good[1] ?? $tn . ' Area 2';
    $sceneC = $good[2] ?? $tn . ' Area 3';
    $responses = [
        'G03_TOUR_OVERVIEW' => $tn . ' adalah destinasi di ' . $loc . ' yang dapat Anda jelajahi melalui tur virtual ini. Tur mencakup area seperti ' . $sceneA . ', ' . $sceneB . ', dan ' . $sceneC . '. Jelajahi setiap panorama untuk mengenal lebih dekat suasana, fungsi, dan cerita di balik ' . $tn . '.',
        'G04_NAME_ORIGIN' => 'Nama ' . $tn . ' melekat sebagai identitas destinasi ini di ' . $loc . '. Untuk informasi lebih detail tentang asal usul dan makna namanya, silakan tanyakan objek atau area spesifik di dalam tur, atau cek panel informasi pada panorama terkait.',
        'G05_PHOTO_SPOTS' => 'Untuk spot foto terbaik di ' . $tn . ', perhatikan area dengan sudut panorama paling terbuka seperti ' . $sceneA . ' dan ' . $sceneB . '. Gunakan fitur zoom dan putar 360 derajat untuk menemukan komposisi terbaik sebelum berfoto.',
        'G06_ACTIVITIES' => 'Di ' . $tn . ', pengunjung dapat berkeliling menikmati ' . $sceneA . ', ' . $sceneB . ', dan ' . $sceneC . ', berinteraksi dengan objek informasi yang tersedia, serta merencanakan rute kunjungan berikutnya melalui rekomendasi di dalam tur.',
        'G07_BEST_TIME' => 'Waktu terbaik berkunjung ke ' . $tn . ' umumnya pagi atau sore hari saat suasana lebih nyaman. Periksa jam operasional resmi dari pengelola, dan cek objek informasi di dalam tur untuk keterangan tambahan.',
        'G08_NEARBY' => 'Sekitar ' . $tn . ' di ' . $loc . ' terdapat berbagai destinasi lain yang bisa dikunjungi. Gunakan menu rekomendasi di tur atau tanyakan nama tempat tertentu untuk saya arahkan.',
        'G09_TIPS' => 'Tips berkunjung ke ' . $tn . ': siapkan pakaian dan alas kaki yang nyaman, bawa kamera untuk mengabadikan momen di ' . $sceneA . ' dan ' . $sceneB . ', serta simak petunjuk pengelola. Ikuti rute tur agar tidak ada area yang terlewat.',
    ];
    return [
        'version' => '1.0',
        'tourId' => $tour['slug'],
        'description' => 'Curated high-confidence answers for ' . $tn . '. Matched BEFORE normal AI pipeline.',
        'intents' => [
            ['id' => 'G01_CURRENT_LOCATION', 'category' => 'location', 'aliases' => ['saya sekarang berada di mana', 'posisi saya di mana', 'di mana saya', 'sekarang saya ada di mana', 'saya sedang berada di area apa', 'ini area apa', 'posisi saya', 'saya di mana', 'ada di mana', 'sedang berada di mana', 'saya lagi di mana', 'lokasi saya sekarang', 'ini tempat apa', 'where am i', 'current location'], 'keywords' => ['di mana saya', 'posisi saya', 'berada di mana', 'ada di mana', 'area apa', 'ini area', 'lokasi saya', 'saya ada di mana'], 'negativePatterns' => ['bawa', 'antar', 'pergi', 'ke sana', 'ke situ', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G02_AREA_DESCRIPTION', 'category' => 'description', 'aliases' => ['ceritakan area ini', 'ceritakan tempat ini', 'ceritakan area yang sedang saya lihat', 'apa yang ada di area ini', 'jelaskan tempat yang sedang saya lihat', 'deskripsikan area ini', 'describe this area', 'tell me about this area', 'ringkas area ini', 'summary area ini', 'apa isi area ini', 'jelaskan area ini', 'ceritakan sedikit tentang area ini', 'deskripsikan tempat ini', 'apa yang bisa dilihat di sini', 'apa saja yang ada di sini', 'ceritakan tentang panorama ini', 'jelaskan panorama ini', 'gambarkan area ini', 'seperti apa tempat ini'], 'keywords' => ['ceritakan area', 'ceritakan tempat', 'jelaskan area', 'jelaskan tempat', 'apa yang ada di area', 'apa isi area', 'deskripsikan area', 'deskripsikan tempat', 'ringkas area', 'summary area', 'area ini', 'tempat ini', 'sedang saya lihat', 'panorama ini', 'di sini'], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'ke sana', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G03_TOUR_OVERVIEW', 'category' => 'overview', 'response' => $responses['G03_TOUR_OVERVIEW'], 'aliases' => ['tentang tempat ini', 'ceritakan tempat ini', 'gambaran umum', 'apa yang menarik dari ' . $tnl, 'ceritakan ' . $tnl, 'apa yang istimewa dari ' . $tnl, 'apa daya tarik ' . $tnl, 'kenapa ' . $tnl . ' menarik', 'tentang ' . $tnl, 'apa itu ' . $tnl, 'keunikan ' . $tnl, 'keistimewaan ' . $tnl, $tnl . ' itu apa', 'ceritakan tentang ' . $tnl, 'jelaskan ' . $tnl, 'profil ' . $tnl, 'info ' . $tnl], 'keywords' => ['tentang tempat ini', 'ceritakan tempat ini', 'gambaran umum', $tnl, 'menarik', 'istimewa', 'daya tarik', 'keunikan', 'tentang ' . $tnl, 'apa itu ' . $tnl, 'profil ' . $tnl], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'ke sana', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G04_NAME_ORIGIN', 'category' => 'factual', 'response' => $responses['G04_NAME_ORIGIN'], 'aliases' => ['kenapa disebut ' . $tnl, 'kenapa namanya ' . $tnl, 'apa arti ' . $tnl, $tnl . ' artinya apa', 'mengapa dinamakan ' . $tnl, 'asal nama ' . $tnl . ' apa', 'arti ' . $tnl, 'makna ' . $tnl, 'kenapa dinamakan ' . $tnl, 'apa makna ' . $tnl, 'nama ' . $tnl . ' berasal dari mana', 'sejarah ' . $tnl, 'cerita ' . $tnl, 'asal usul ' . $tnl], 'keywords' => [$tnl, 'arti', 'makna', 'nama', 'disebut', 'dinamakan', 'kenapa', 'asal', 'sejarah', 'cerita', 'asal usul'], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'ke sana', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G05_PHOTO_SPOTS', 'category' => 'photo', 'response' => $responses['G05_PHOTO_SPOTS'], 'aliases' => ['spot foto', 'foto terbaik', 'tempat foto', 'spot foto di ' . $tnl, 'tempat foto terbaik ' . $tnl, 'foto bagus di ' . $tnl, 'spot foto instagramable', 'tempat berfoto', 'foto menarik', 'spot foto estetik', 'foto untuk instagram', 'tempat foto ciamik', 'foto bersama keluarga', 'spot foto keluarga'], 'keywords' => ['spot foto', 'foto', 'berfoto', 'tempat foto', 'foto terbaik', 'instagramable', 'foto bagus'], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'ke sana', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G06_ACTIVITIES', 'category' => 'activity', 'response' => $responses['G06_ACTIVITIES'], 'aliases' => ['bisa ngapain', 'aktivitas', 'kegiatan', 'bisa ngapain di ' . $tnl, 'aktivitas di ' . $tnl, 'kegiatan di ' . $tnl, 'yang bisa dilakukan di ' . $tnl, 'seru seruan di ' . $tnl, 'main apa di ' . $tnl, 'aktivitas seru', 'ada kegiatan apa', 'aktivitas keluarga', 'aktivitas anak', 'wisata edukasi', 'wisata belajar', 'jalan jalan', 'nongkrong'], 'keywords' => ['aktivitas', 'kegiatan', 'bisa ngapain', 'bisa dilakukan', 'seru', 'edukasi', 'belajar', 'main'], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'ke sana', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G07_BEST_TIME', 'category' => 'visit', 'response' => $responses['G07_BEST_TIME'], 'aliases' => ['kapan datang', 'jam terbaik', 'waktu terbaik', 'kapan datang ke ' . $tnl, 'jam terbaik ke ' . $tnl, 'waktu terbaik ke ' . $tnl, 'kapan buka', 'jam operasional', 'hari apa buka', 'kapan sebaiknya ke ' . $tnl, 'waktu yang tepat ke ' . $tnl, 'jam berkunjung', 'buka jam berapa', 'buka hari apa', 'jam buka tutup', 'kapan libur', 'waktu kunjungan terbaik', 'pagi siang sore malam'], 'keywords' => ['kapan datang', 'jam terbaik', 'waktu terbaik', 'jam buka', 'jam operasional', 'kapan buka', 'buka jam berapa', 'waktu kunjungan'], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'ke sana', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G08_NEARBY', 'category' => 'nearby', 'response' => $responses['G08_NEARBY'], 'aliases' => ['dekat sini ada apa', 'sekitar sini', 'tempat terdekat', 'dekat ' . $tnl . ' ada apa', 'sekitar ' . $tnl, 'tempat terdekat dari ' . $tnl, 'wisata dekat ' . $tnl, 'kuliner dekat ' . $tnl, 'makanan sekitar', 'tempat wisata sekitar', 'objek wisata terdekat', 'destinasi sekitar', 'tempat makan dekat', 'penginapan dekat', 'hotel dekat', 'dekat sini ada wisata apa'], 'keywords' => ['dekat sini', 'sekitar sini', 'tempat terdekat', 'dekat ' . $tnl, 'sekitar ' . $tnl, 'wisata sekitar', 'dekat'], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'ke sana', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G09_TIPS', 'category' => 'tips', 'response' => $responses['G09_TIPS'], 'aliases' => ['tips berkunjung', 'saran kunjungan', 'yang perlu disiapkan', 'tips berkunjung ke ' . $tnl, 'saran kunjungan ' . $tnl, 'yang perlu disiapkan ke ' . $tnl, 'tips wisata ' . $tnl, 'saran sebelum ke ' . $tnl, 'persiapan ke ' . $tnl, 'bawa apa ke ' . $tnl, 'tips berwisata', 'saran untuk pengunjung', 'hal yang perlu diperhatikan', 'tips untuk keluarga', 'tips untuk anak anak', 'yang harus dibawa', 'pakaian yang cocok', 'perlengkapan'], 'keywords' => ['tips', 'saran', 'persiapan', 'disiapkan', 'bawa', 'perlu diperhatikan', 'tips berkunjung', 'saran kunjungan'], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'ke sana', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G10_ROUTE_RECOMMENDATION', 'category' => 'navigation', 'aliases' => ['setelah dari sini sebaiknya saya ke mana', 'rekomendasi berikutnya', 'sebaiknya saya lanjut ke mana', 'tempat berikutnya apa', 'setelah ini ke mana', 'habis ini ke mana', 'next area', 'next spot', 'lanjut ke mana', 'kemana selanjutnya', 'mana yang sebaiknya saya kunjungi', 'rute wisata', 'urutan kunjungan', 'mulai dari mana', 'pertama ke mana', 'selanjutnya ke mana', 'rekomendasi rute', 'saran jalan jalan'], 'keywords' => ['rekomendasi', 'berikutnya', 'lanjut', 'sebaiknya', 'ke mana', 'habis ini', 'setelah', 'rute', 'selanjutnya'], 'negativePatterns' => ['bawa saya', 'antar saya', 'pergi ke', 'menuju'], 'dynamic' => true, 'action' => null],
            ['id' => 'G11_FOLLOWUP_NAVIGATION', 'category' => 'navigation', 'aliases' => ['oke bawa saya ke sana', 'bawa saya ke sana', 'ayo ke sana', 'oke lanjut', 'ya antar saya ke sana', 'ke sana', 'kesana', 'ke situ', 'ya bawa', 'oke antar', 'lanjut ke sana', 'ayo lanjut', 'bawa saya ke tempat itu', 'antar saya ke tempat itu', 'lanjutkan', 'bawa kesana', 'antar kesana', 'ayo kita lanjut', 'oke kita lanjut', 'siap lanjut'], 'keywords' => ['ke sana', 'kesana', 'ke situ', 'lanjut', 'bawa', 'antar', 'lanjutkan'], 'negativePatterns' => ['apa', 'kenapa', 'mengapa', 'siapa', 'kapan', 'berapa', 'dimana', 'di mana', 'ceritakan', 'jelaskan', 'deskripsikan'], 'dynamic' => true, 'requiresState' => 'lastRecommendedDestination', 'action' => 'go_to_scene'],
        ],
    ];
}
function content_template_knowledge(array $tour, array $sceneNames, array $objectIds = []): array {
    $entries = [];
    $samples = [
        ['title' => $tour['name'], 'type' => 'destination', 'content' => 'Pusat pengalaman virtual untuk ' . $tour['name'] . '.'],
        ['title' => $sceneNames[0] ?? ($tour['name'] . ' Area 1'), 'type' => 'scene', 'content' => 'Scene utama yang perlu diisi dengan identitas, fungsi, dan catatan verifikasi.'],
        ['title' => $sceneNames[1] ?? ($tour['name'] . ' Area 2'), 'type' => 'scene', 'content' => 'Scene pendukung untuk melengkapi cerita perjalanan pengunjung.'],
        ['title' => 'Jam kunjungan', 'type' => 'visitor-info', 'content' => 'Isi jam operasional, jam ramai, dan kondisi terbaik berkunjung.'],
    ];
    foreach ($samples as $i => $sample) {
        $entries[] = ['id' => 'seed:' . ($i + 1), 'entityId' => 'seed:' . ($i + 1), 'title' => $sample['title'], 'aliases' => [strtolower($sample['title'])], 'type' => $sample['type'], 'categories' => [$sample['type']], 'tags' => ['seed', $tour['slug']], 'sceneIds' => [], 'content' => $sample['content'], 'source' => 'ai-generator', 'sourceId' => (string)($i + 1), 'sourceType' => 'seed', 'sourceFile' => 'ai-generator', 'sourceLine' => 0, 'mappingConfidence' => 'SEED', 'mappingEvidence' => 'template starter', 'provenance' => 'ai-generator', 'translations' => ['id' => ['title' => $sample['title'], 'content' => $sample['content']], 'en' => ['title' => $sample['title'], 'content' => $sample['content']]]];
    }
    // Load webview knowledge to auto-plant real data for known objectIds
    $wkPath = __DIR__ . '/storage/webview-knowledge.json';
    $wk = file_exists($wkPath) ? json_decode(file_get_contents($wkPath), true) : null;
    $objsByGroup = $wk['objectsByGroup'] ?? [];
    $discByGroup = $wk['discoveriesByGroup'] ?? [];
    $itemByGroup = $wk['itemByGroup'] ?? [];
    foreach ($objectIds as $oid) {
        $oid = (string)$oid;
        $source = $objsByGroup[$oid] ?? $discByGroup[$oid] ?? null;
        $item = $itemByGroup[$oid] ?? null;
        if ($source) {
            $title = $source['name'];
            $desc = $source['desc'];
            $translations = $source['translations'] ?? [];
            $idTrans = $translations['id'] ?? ['name' => $title, 'desc' => $desc];
            $enTrans = $translations['en'] ?? ['name' => 'Object ' . $oid, 'desc' => 'Information for Object ' . $oid . '.'];
            $tr = ['id' => ['title' => $idTrans['name'] ?: $title, 'content' => $idTrans['desc'] ?: $desc], 'en' => ['title' => $enTrans['name'] ?: 'Object ' . $oid, 'content' => $enTrans['desc'] ?: 'Information for Object ' . $oid . '.']];
            foreach ($translations as $lang => $t) {
                if ($lang === 'id' || $lang === 'en') continue;
                $tr[$lang] = ['title' => $t['name'] ?: $title, 'content' => $t['desc'] ?: $desc];
            }
            $entries[] = ['id' => 'webview:objinfo2:' . $oid, 'entityId' => 'objinfo2:' . $oid, 'title' => $title, 'aliases' => [strtolower($title), 'objek ' . $oid], 'type' => 'information', 'categories' => ['informasi'], 'tags' => ['webview', 'objinfo2', 'auto'], 'sceneIds' => [], 'content' => $desc, 'source' => 'webview-import', 'sourceId' => $oid, 'sourceType' => 'objinfo2', 'sourceFile' => 'semarang-260828.sql', 'sourceLine' => 0, 'mappingConfidence' => 'WEBVIEW', 'mappingEvidence' => 'webview-knowledge auto-plant', 'provenance' => 'webview-import', 'translations' => $tr];
        } elseif ($item) {
            $entries[] = ['id' => 'webview:objinfo2:' . $oid, 'entityId' => 'objinfo2:' . $oid, 'title' => $item['title'] ?? 'Objek ' . $oid, 'aliases' => [strtolower($item['title'] ?? 'objek ' . $oid), 'objek ' . $oid], 'type' => 'information', 'categories' => ['informasi'], 'tags' => ['webview', 'objinfo2', 'db_items'], 'sceneIds' => [], 'content' => $item['desc'] ?? 'Informasi untuk Objek ' . $oid . '.', 'source' => 'db_items', 'sourceId' => $oid, 'sourceType' => 'objinfo2', 'sourceFile' => 'semarang-260828.sql', 'sourceLine' => 0, 'mappingConfidence' => 'WEBVIEW', 'mappingEvidence' => 'webview-knowledge db_items fallback', 'provenance' => 'db_items', 'translations' => ['id' => ['title' => $item['title'] ?? 'Objek ' . $oid, 'content' => $item['desc'] ?? 'Informasi untuk Objek ' . $oid . '.'], 'en' => ['title' => 'Object ' . $oid, 'content' => 'Information for Object ' . $oid . '.']]];
        } else {
            $entries[] = ['id' => 'webview:objinfo2:' . $oid, 'entityId' => 'objinfo2:' . $oid, 'title' => 'Objek ' . $oid, 'aliases' => ['objek ' . $oid, 'objek #' . $oid], 'type' => 'information', 'categories' => ['informasi'], 'tags' => ['webview', 'objinfo2', 'auto'], 'sceneIds' => [], 'content' => 'Informasi untuk Objek ' . $oid . ' di ' . $tour['name'] . '. Ganti dengan deskripsi terverifikasi dari pengelola.', 'source' => 'ai-generator:auto', 'sourceId' => $oid, 'sourceType' => 'objinfo2', 'sourceFile' => 'ai-generator', 'sourceLine' => 0, 'mappingConfidence' => 'AUTO', 'mappingEvidence' => 'auto from skin.js objinfo ids', 'provenance' => 'ai-generator', 'translations' => ['id' => ['title' => 'Objek ' . $oid, 'content' => 'Informasi untuk Objek ' . $oid . ' di ' . $tour['name'] . '.'], 'en' => ['title' => 'Object ' . $oid, 'content' => 'Information for Object ' . $oid . ' at ' . $tour['name'] . '.']]];
        }
    }
    return ['version' => 2, 'tourId' => $tour['slug'], 'updatedAt' => date('c'), 'generatedBy' => 'ai-generator', 'entries' => $entries];
}
function content_template_scene_identity(array $tour, array $parsedScenes, array $objectIds = [], array $objectsByScene = []): array {
    $scenes = [];
    foreach ($parsedScenes as $idx => $scene) {
        $id = (string)($scene['id'] ?? 'scene-' . (count($scenes) + 1)); $name = (string)($scene['displayName'] ?? $scene['name'] ?? $id);
        // ONLY scenes that actually contain data-info-id in pano.xml get relatedKnowledgeIds.
        // No round-robin fallback — panel must stay hidden for empty panoramas (like Lawang Sewu perfect).
        $related = [];
        if (isset($objectsByScene[$id]) && $objectsByScene[$id]) {
            foreach (array_unique($objectsByScene[$id]) as $oid) $related[] = 'webview:objinfo2:' . $oid;
        }
        $hasRelated = !empty($related);
        $isPlaceholder = ($name === $id || preg_match('/^node\d+$/i', $name));
        $prettyName = $isPlaceholder ? 'Area '.strtoupper($id) : $name;
        $short = $isPlaceholder
            ? $prettyName.' adalah salah satu titik panorama di kawasan '.$tour['name'].', '.$tour['location'].' — terhubung dengan jalur eksplorasi agrowisata.'
            : $name.' merupakan bagian dari kawasan '.$tour['name'].' di '.$tour['location'].', Semarang. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.';
        $long = $isPlaceholder
            ? $prettyName.' (ID: '.$id.') adalah panorama yang terdeteksi dari export Pano2VR. Belum ada deskripsi kurasi; silakan ganti nama dan isi deskripsi via Scene Editor agar guide ramah pengunjung. Area ini tetap terhubung dengan navigasi hotspot dan dapat dilengkapi objek info jika tersedia.'
            : $name.' adalah area di dalam kawasan '.$tour['name'].', '.$tour['location'].'. Area ini menjadi bagian dari pengalaman virtual yang menghubungkan jalur eksplorasi, titik pandang, dan fasilitas pendukung. Deskripsi ini masih template — silakan lengkapi via Scene Editor dengan fakta terverifikasi agar left-context terisi seperti Lawang Sewu.';
        $highlights = $hasRelated ? ['Titik dengan objek info','Terhubung jalur eksplorasi'] : ['Titik panorama','Terhubung jalur eksplorasi'];
        $scenes[$id] = [
            'rawName' => $name,
            'displayName' => $isPlaceholder ? $prettyName : $name,
            'placeId' => slugify($name),
            'placeName' => $isPlaceholder ? $prettyName : $name,
            'variant' => null,
            'type' => 'area',
            'confidence' => $hasRelated ? 'NEEDS_REVIEW' : 'NEEDS_REVIEW',
            'evidence' => 'Seed template from AI Generator' . ($hasRelated ? ' + auto obj mapping' : '') . ($isPlaceholder ? ' (placeholder name)' : ''),
            'shortDescription' => $short,
            'description' => $long,
            'highlights' => $highlights,
            'talkingPoints' => [$name, 'jalur eksplorasi', $tour['name']],
            'suggestedQuestions' => ['Apa yang menarik di sini?', 'Ceritakan area ' . ($isPlaceholder ? $prettyName : $name) . '.', 'Di mana saya sekarang?'],
            'relatedKnowledgeIds' => $related,
            'contentGrounding' => $hasRelated ? 'direct' : 'identity-only',
            'aiHints' => ['currentLocation' => 'Anda sekarang berada di ' . ($isPlaceholder ? $prettyName : $name) . ' — kawasan '.$tour['name'].'.', 'describeHere' => $long, 'interestingHere' => 'Gunakan highlights terverifikasi.', 'navigation' => 'Gunakan graph/hotspot hanya untuk navigasi.']
        ];
    }
    return ['version' => '1.1-seed', 'tourId' => $tour['slug'], 'generatedAt' => date('Y-m-d'), 'notes' => 'Template starter. Ganti semua placeholder dengan fakta terverifikasi.', 'scenes' => $scenes, 'places' => []];
}
function content_template_visitor_tips(array $tour): array {
    return ['version' => '1.0', 'updatedAt' => date('Y-m-d'), 'source' => 'AI Generator', 'sourceType' => 'manual', 'disclaimer' => 'Template starter. Sesuaikan dengan aturan pengelola, cuaca, dan akses terbaru.', 'topics' => [
        ['id' => 'best-time-day', 'title' => 'Waktu terbaik dalam sehari', 'aliases' => ['jam terbaik', 'waktu terbaik', 'kapan sebaiknya berkunjung'], 'category' => 'waktu', 'content' => 'Isi rekomendasi jam kunjungan terbaik untuk ' . $tour['name'] . '.', 'items' => [['label' => 'Pagi', 'detail' => 'Isi alasan kunjungan pagi.', 'recommendation' => 'direkomendasikan'], ['label' => 'Sore', 'detail' => 'Isi alasan kunjungan sore.', 'recommendation' => 'direkomendasikan']]],
        ['id' => 'photo-spots', 'title' => 'Spot foto terbaik', 'aliases' => ['spot foto', 'tempat foto', 'instagramable'], 'category' => 'foto', 'content' => 'Isi spot foto utama yang aman dan terverifikasi.', 'items' => [['label' => 'Spot 1', 'detail' => 'Isi keterangan spot foto pertama.']]],
        ['id' => 'activities', 'title' => 'Aktivitas di lokasi', 'aliases' => ['aktivitas', 'kegiatan', 'bisa ngapain'], 'category' => 'aktivitas', 'content' => 'Isi aktivitas yang diperbolehkan di lokasi ini.', 'items' => [['label' => 'Aktivitas 1', 'detail' => 'Isi aktivitas utama.']]],
    ]];
}
function content_template_nearby(array $tour): array {
    return ['version' => '1.0', 'updatedAt' => date('Y-m-d'), 'origin' => $tour['name'] . ', ' . $tour['location'], 'disclaimer' => 'Template starter. Jarak dan waktu harus diverifikasi sebelum dipublikasikan.', 'items' => [
        ['id' => 'nearby-hotel-1', 'name' => 'Hotel Terdekat', 'category' => 'hotel', 'address' => 'Isi alamat hotel terdekat', 'distance' => 'Isi jarak', 'travelTime' => 'Isi waktu tempuh', 'aliases' => ['hotel terdekat']],
        ['id' => 'nearby-food-1', 'name' => 'Tempat Makan Terdekat', 'category' => 'restoran', 'address' => 'Isi alamat tempat makan', 'distance' => 'Isi jarak', 'travelTime' => 'Isi waktu tempuh', 'aliases' => ['tempat makan terdekat']],
        ['id' => 'nearby-attraction-1', 'name' => 'Destinasi Sekitar', 'category' => 'wisata', 'address' => 'Isi alamat destinasi', 'distance' => 'Isi jarak', 'travelTime' => 'Isi waktu tempuh', 'aliases' => ['destinasi sekitar']],
    ]];
}
function infer_scene_names_from_hotspots(array $parsed): array {
    $freq = [];
    foreach (($parsed['hotspots'] ?? []) as $h) {
        $t = trim($h['target'] ?? '');
        $s = trim($h['title'] ?? '');
        if ($t === '' || $s === '' || $t === ($h['scene'] ?? '') || preg_match('/^(exit|keluar|close)$/i', $s) || mb_strtolower($s) === 'default') continue;
        if (!isset($freq[$t])) $freq[$t] = [];
        $freq[$t][] = $s;
    }
    $map = [];
    foreach ($freq as $node => $titles) {
        $counts = array_count_values($titles);
        arsort($counts);
        $map[$node] = array_key_first($counts);
    }
    return $map;
}

function seed_content(array $tour): void {
    $dir = tour_dir($tour['slug']); $parsed = read_json($dir . '/parsed.json');
    if (!$parsed) throw new RuntimeException('Import pano/skin dulu.');

    // infer panorama names from hotspot labels for placeholder nodes
    $nameMap = infer_scene_names_from_hotspots($parsed);
    $scenes = $parsed['scenes'] ?? [];
    foreach ($scenes as &$scene) {
        $id = $scene['id'] ?? '';
        $cur = $scene['name'] ?? $id;
        $isPlaceholder = ($cur === $id || preg_match('/^node\d+$/i', $cur) || mb_strtolower($cur) === 'default');
        if ($isPlaceholder && isset($nameMap[$id])) {
            $inferred = $nameMap[$id];
            if (mb_strtolower($inferred) === 'default') continue;
            $scene['name'] = $inferred;
            $scene['displayName'] = $inferred;
            $scene['customNodeId'] = $scene['customNodeId'] ?: strtolower(preg_replace('/[^a-z0-9]+/', '-', $inferred));
            if (!in_array($inferred, $scene['aliases'] ?? [], true)) $scene['aliases'][] = $inferred;
        }
    }
    unset($scene);
    // persist inferred names back to parsed.json so templates get them
    $parsed['scenes'] = $scenes;
    write_json($dir . '/parsed.json', $parsed);

    $sceneNames = [];
    foreach ($scenes as $scene) $sceneNames[] = (string)($scene['displayName'] ?? $scene['name'] ?? $scene['id']);
    $objectIds = array_values(array_unique($parsed['skin']['objectIds'] ?? []));
    foreach (($parsed['objects'] ?? []) as $o) if (!in_array($o['infoId'], $objectIds, true)) $objectIds[] = $o['infoId'];
    $objectsByScene = [];
    foreach (($parsed['objects'] ?? []) as $o) {
        $sid = (string)($o['sceneId'] ?? '');
        if ($sid === '') continue;
        if (!isset($objectsByScene[$sid])) $objectsByScene[$sid] = [];
        $objectsByScene[$sid][] = $o['infoId'];
    }
    $templates = [
        'curated-answers' => content_template_curated($tour, $sceneNames),
        'knowledge' => content_template_knowledge($tour, $sceneNames, $objectIds),
        'scene-identity' => content_template_scene_identity($tour, $scenes, $objectIds, $objectsByScene),
        'visitor-tips' => content_template_visitor_tips($tour),
        'nearby' => content_template_nearby($tour),
        'recommended-questions' => content_template_recommended_questions($tour),
    ];
    foreach ($templates as $name => $data) write_json($dir . '/' . $name . '.json', $data);
}
function all_tours(): array { return db()->query('SELECT * FROM tours ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC); }
function get_tour(int $id): ?array { $s = db()->prepare('SELECT * FROM tours WHERE id=?'); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function save_tour(array $in): int {
    $pdo = db(); $now = date('c'); $id = (int)($in['id'] ?? 0);
    $name = trim((string)($in['name'] ?? '')); if ($name === '') throw new RuntimeException('Nama tour wajib diisi.');
    $slug = slugify((string)($in['slug'] ?? $name));
    $location = trim((string)($in['location'] ?? 'Semarang')) ?: 'Semarang';
    $category = trim((string)($in['category'] ?? ''));
    $path = str_replace('\\', '/', trim((string)($in['export_path'] ?? '')));
    if ($id) {
        $stmt = $pdo->prepare('UPDATE tours SET slug=?, name=?, location=?, category=?, export_path=?, updated_at=? WHERE id=?');
        $stmt->execute([$slug, $name, $location, $category, $path, $now, $id]);
        return $id;
    }
    $stmt = $pdo->prepare('INSERT INTO tours(slug,name,location,category,export_path,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$slug, $name, $location, $category, $path, $now, $now]);
    return (int)$pdo->lastInsertId();
}
function parse_pano(string $path): array {
    $file = rtrim($path, '/\\') . '/pano.xml';
    if (!is_file($file)) throw new RuntimeException('pano.xml tidak ditemukan: ' . $file);
    $xml = simplexml_load_file($file); if (!$xml) throw new RuntimeException('pano.xml gagal diparse.');
    $start = (string)($xml['start'] ?? ''); $scenes = []; $hotspots = []; $objects = []; $videos = []; $oiPositions = [];
    $raw = (string)file_get_contents($file);
    foreach ($xml->panorama as $p) {
        $id = (string)$p['id']; if ($id === '') continue;
        $ud = $p->userdata; $custom = (string)($ud['customnodeid'] ?? ''); $title = trim((string)($ud['title'] ?? ''));
        $name = $title ?: ($custom ? ucwords(str_replace(['-', '_'], ' ', $custom)) : $id);
        $scenes[] = ['id' => $id, 'name' => $name, 'displayName' => $name, 'rawName' => $title, 'customNodeId' => $custom, 'tags' => array_values(array_filter(explode('|', (string)($ud['tags'] ?? '')))), 'aliases' => array_values(array_filter([$custom, $title]))];
        foreach ($p->hotspots->hotspot ?? [] as $h) {
            $url = (string)($h['url'] ?? '');
            if (preg_match('/\{([^}]+)\}/', $url, $m)) $hotspots[] = ['scene' => $id, 'target' => $m[1], 'title' => (string)($h['title'] ?? ''), 'id' => (string)($h['id'] ?? ''), 'pan' => (float)($h['pan'] ?? 0), 'tilt' => (float)($h['tilt'] ?? 0)];
            // OI polygon positions (for objects-panel pitch/yaw)
            $skinid = (string)($h['skinid'] ?? '');
            if (str_contains($skinid, 'hotspot_oi_polygon')) {
                if (!isset($oiPositions[$id])) $oiPositions[$id] = [];
                $oiPositions[$id][] = ['pan' => (float)($h['pan'] ?? 0), 'tilt' => (float)($h['tilt'] ?? 0), 'fov' => (float)($h['fov'] ?? 70)];
            }
        }
        $sx = $p->asXML() ?: '';
        // data-info-id may be encoded as &quot; in xml (webelement code attr)
        $sxDecoded = html_entity_decode($sx, ENT_QUOTES | ENT_XML1, 'UTF-8');
        if (preg_match_all('/data-info-id=["\']?(\d+)/i', $sxDecoded, $mm)) foreach (array_unique($mm[1]) as $oid) $objects[] = ['sceneId' => $id, 'infoId' => $oid, 'label' => 'Objek ' . $oid];
        // Also check raw file segment for this panorama (for webelement code with &quot;)
        if (preg_match('/<panorama id="'.preg_quote($id,'/').'".*?(?=<panorama|<\/tour)/s', $raw, $pm)) {
            $block = html_entity_decode($pm[0], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (preg_match_all('/data-info-id=["\']?(\d+)/i', $block, $mm2)) foreach (array_unique($mm2[1]) as $oid) {
                if (!in_array($oid, array_column(array_filter($objects, fn($o)=>$o['sceneId']===$id), 'infoId'), true)) $objects[] = ['sceneId' => $id, 'infoId' => $oid, 'label' => 'Objek ' . $oid];
            }
            // webelement pan/tilt for OI (when no hotspot_oi_polygon, use webelement position as fallback)
            if (preg_match_all('/<webelement[^>]*pan="([^"]+)"[^>]*tilt="([^"]+)"[^>]*fov="([^"]+)"[^>]*code="[^"]*data-info-id[^"]*"/s', $pm[0], $wm, PREG_SET_ORDER)) {
                foreach ($wm as $w) {
                    if (!isset($oiPositions[$id])) $oiPositions[$id] = [];
                    // avoid duplicate if already have hotspot
                    $pan = (float)$w[1]; $tilt=(float)$w[2]; $fov=(float)$w[3];
                    $exists=false; foreach($oiPositions[$id] as $ex) if(abs($ex['pan']-$pan)<0.01 && abs($ex['tilt']-$tilt)<0.01) $exists=true;
                    if(!$exists) $oiPositions[$id][]=['pan'=>$pan,'tilt'=>$tilt,'fov'=>$fov];
                }
            }
        }
        if (preg_match_all('/(media\/[^"\\\']+\.(?:mp4|webm|m3u8))/i', $sx, $vm)) foreach (array_unique($vm[1]) as $v) $videos[] = ['sceneId' => $id, 'src' => $v];
    }
    return ['startNode' => $start, 'scenes' => $scenes, 'hotspots' => $hotspots, 'objects' => $objects, 'video360' => $videos, 'oiPositions' => $oiPositions];
}
function parse_skin(string $path): array {
    $file = rtrim($path, '/\\') . '/skin.js'; if (!is_file($file)) return ['iframes' => [], 'objectIds' => []];
    $js = str_replace('\\/', '/', (string)file_get_contents($file)); $iframes = []; $ids = [];
    if (preg_match_all('/objinfo2\.php\?[^"<>]*?(?:id|info)=(\d+)/i', $js, $m)) foreach ($m[1] as $id) { $ids[] = $id; $iframes[] = ['infoId' => $id]; }
    if (preg_match_all('/data-info-id=["\']?(\d+)/i', $js, $m2)) foreach ($m2[1] as $id) $ids[] = $id;
    return ['iframes' => $iframes, 'objectIds' => array_values(array_unique($ids))];
}
function remove_tree(string $path): void {
    if (!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($path);
}
function copy_runtime(string $target): void {
    if (!is_dir(SOURCE_AI)) throw new RuntimeException('Shared AI runtime tidak ditemukan: ' . SOURCE_AI);
    remove_tree($target);
    $skipFiles = ['.env.example', 'README.md', 'install-snippet.html', 'api/chat.php', 'config/tour.json', 'data/curated-answers.json', 'data/explore-menu-knowledge.json', 'data/knowledge.json', 'data/lawang-sewu-nearby.json', 'data/lawang-sewu-visitor-tips.json', 'data/nearby.json', 'data/place-map.json', 'data/place-registry.json', 'data/scene-identity.json', 'data/scene-identity (2).json', 'data/tour-data.js', 'data/visitor-tips.json', 'data/oi-positions.json', 'data/city-instructions.json', 'data/city-knowledge.json'];
    $skipPrefixes = ['api/_cover_cache/', 'api/cache/', 'docs/', 'tests/', 'tools/'];
    $sourceRoot = rtrim(str_replace('\\', '/', SOURCE_AI), '/');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SOURCE_AI, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        $source = str_replace('\\', '/', $item->getPathname());
        $relative = ltrim(substr($source, strlen($sourceRoot)), '/');
        $blocked = in_array($relative, $skipFiles, true);
        foreach ($skipPrefixes as $prefix) if (str_starts_with($relative, $prefix)) { $blocked = true; break; }
        if ($blocked) continue;
        $destination = $target . '/' . $relative;
        if ($item->isDir()) { if (!is_dir($destination)) mkdir($destination, 0777, true); }
        else { if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0777, true); copy($source, $destination); }
    }
    // Do not delete catalog files – they are needed for Semarang generic tours. Keep them if source has them.
    foreach (['data/curated-answers.json','data/explore-menu-knowledge.json','data/lawang-sewu-nearby.json','data/lawang-sewu-visitor-tips.json','data/place-map.json','data/place-registry.json','data/scene-identity (2).json','install-snippet.html','api/cache/ai/knowledge-embeddings.json'] as $rel) {
        $file = $target . '/' . $rel;
        if (is_file($file)) unlink($file);
    }
    // Overwrite .env from hub DB (single source of truth for API keys)
    env_write_to($target . '/.env');
}
/**
 * Sync runtime-only changes (frontend/guide.js, frontend/guide.css, api/*, ai-client.js, .htaccess)
 * dari SOURCE_AI master ke semua package & lokasi ter-deploy. Data per-tour (data/, config/) TIDAK disentuh.
 * Return [slug => ['copied'=>int, 'targets'=>int, 'note'=>string]].
 */
function sync_runtime_all(): array {
    if (!is_dir(SOURCE_AI)) throw new RuntimeException('Shared AI runtime tidak ditemukan: ' . SOURCE_AI);
    $skipFiles = ['.env.example', 'README.md', 'install-snippet.html', 'api/chat.php'];
    $skipPrefixes = ['data/', 'config/', 'api/_cover_cache/', 'api/cache/', 'docs/', 'tests/', 'tools/'];
    $sourceRoot = rtrim(str_replace('\\', '/', SOURCE_AI), '/');
    $sourceFiles = []; // relative => absolute source path
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(SOURCE_AI, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir()) continue;
        $source = str_replace('\\', '/', $item->getPathname());
        $relative = ltrim(substr($source, strlen($sourceRoot)), '/');
        $blocked = in_array($relative, $skipFiles, true);
        foreach ($skipPrefixes as $prefix) if (str_starts_with($relative, $prefix)) { $blocked = true; break; }
        if ($blocked) continue;
        $sourceFiles[$relative] = $source;
    }
    $results = [];
    foreach (all_tours() as $t) {
        $targets = [];
        $pkg = tour_dir($t['slug']) . '/package/govirtual-ai';
        if (is_dir($pkg)) $targets[] = $pkg;
        $exp = rtrim((string)$t['export_path'], '/\\');
        if ($exp !== '' && is_dir($exp . '/govirtual-ai')) $targets[] = $exp . '/govirtual-ai';
        if (!$targets) { $results[$t['slug']] = ['copied' => 0, 'targets' => 0, 'note' => 'skip (belum ada package/deploy)']; continue; }
        $copied = 0;
        foreach ($targets as $target) {
            foreach ($sourceFiles as $relative => $src) {
                $dst = $target . '/' . $relative;
                if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0777, true);
                copy($src, $dst);
                $copied++;
            }
            env_write_to($target . '/.env');
        }
        $results[$t['slug']] = ['copied' => $copied, 'targets' => count($targets), 'note' => 'ok'];
    }
    return $results;
}
function build_files(array $tour): array {
    $dir = tour_dir($tour['slug']); $parsed = read_json($dir . '/parsed.json'); if (!$parsed) throw new RuntimeException('Import dulu sebelum generate.');
    $objects = $parsed['objects'] ?? [];
    foreach (($parsed['skin']['objectIds'] ?? []) as $oid) if (!in_array($oid, array_column($objects, 'infoId'), true)) $objects[] = ['sceneId' => '', 'infoId' => $oid, 'label' => 'Objek ' . $oid];
    $exits = [];
    foreach (($parsed['hotspots'] ?? []) as $h) {
        $from = (string)$h['scene'];
        if (!isset($exits[$from])) $exits[$from] = [];
        $exits[$from][] = ['target' => (string)$h['target'], 'title' => (string)$h['title'], 'suffix' => (string)($h['id'] ?? '')];
    }
    $out = $dir . '/package/govirtual-ai';
    // Detect thumbnail prefix from images directory (e.g. ht_node_thumb_10_ vs ht_node_thumb_1_)
    $thumbPrefix = 'ht_node_thumb_1_'; // fallback
    // Try export images dir first, then source fallback
    $candidates = [rtrim((string)$tour['export_path'], '/\\') . '/images', dirname($out) . '/images', SOURCE_AI . '/../images'];
    foreach ($candidates as $imgDir) {
        if (is_dir($imgDir)) {
            foreach (scandir($imgDir) as $f) {
                if (preg_match('/^(ht_node_thumb_\d+_)/', $f, $m)) { $thumbPrefix = $m[1]; break 2; }
            }
        }
    }
    // Sync with govirtual-ai reference: include id/name at top-level for guide.js
    $tourData = [
        'id' => $tour['slug'],
        'name' => $tour['name'],
        'scenes' => $parsed['scenes'],
        'hotspots' => $parsed['hotspots'],
        'panoExits' => $exits,
        'thumbPrefix' => $thumbPrefix,
        'routes' => [],
        'objectInfo' => $objects,
        'video360' => $parsed['video360'] ?? [],
        '_sceneRegistry' => array_column($parsed['scenes'], null, 'id')
    ];
    $config = [
        'tourId' => $tour['slug'],
        'tourName' => $tour['name'],
        'location' => $tour['location'],
        'nearbyLabel' => $tour['name'] . ' dan ' . $tour['location'],
        'language' => 'id',
        'features' => ['exploreKnowledge' => true, 'publicService' => true, 'destinationDiscovery' => true, 'destinationCatalog' => true, 'sceneIdentity' => true, 'answerability' => true],
        'data' => ['visitorTipsPath' => 'data/visitor-tips.json', 'nearbyPath' => 'data/nearby.json'],
        'frontend' => [
            'catalogPath' => 'data/semarang-destinations.json',
            'catalogLabel' => $tour['location'],
            'nearbyLabel' => $tour['name'] . ' dan ' . $tour['location'],
            'categoryPage' => 'https://semarang.govirtual.id/webview/kategori.php?lang=id',
            'objectInfoBase' => 'https://webview-govirtual.id/semarang-v2/assets/images/objekinfo/lawang_sewu',
            'objectInfoPage' => 'https://semarang.govirtual.id/webview/objinfo2.php',
            'objectInfoDetailParam' => 'id',
            'objectInfoExtra' => '&vd=1&tp=m'
        ]
    ];
    copy_runtime($out);
    if (!is_dir($out . '/data')) mkdir($out . '/data', 0777, true); if (!is_dir($out . '/config')) mkdir($out . '/config', 0777, true);
    file_put_contents($out . '/data/tour-data.js', 'window.GoVirtualTourData = ' . json_encode($tourData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';' . PHP_EOL);
    write_json($out . '/config/tour.json', $config);
    // Ensure knowledge & scene-identity exist (auto-regenerate if missing/seed)
    $knowledge = read_json($dir . '/knowledge.json', ['entries' => []]);
    if (empty($knowledge['entries']) || (count($knowledge['entries']) <= 4 && isset($knowledge['entries'][0]['id']) && str_starts_with($knowledge['entries'][0]['id'], 'seed:'))) {
        // re-seed to include object entries if current file is still placeholder
        $sceneNamesTmp = array_map(fn($s) => (string)($s['displayName'] ?? $s['name'] ?? $s['id']), $parsed['scenes'] ?? []);
        $oidsTmp = array_values(array_unique(array_merge($parsed['skin']['objectIds'] ?? [], array_column($parsed['objects'] ?? [], 'infoId'))));
        $knowledge = content_template_knowledge($tour, $sceneNamesTmp, $oidsTmp);
        write_json($dir . '/knowledge.json', $knowledge);
    }
    write_json($out . '/data/knowledge.json', $knowledge);
    // City knowledge: hanya untuk tour lokasi yang sama (multi-city isolation)
    $citySlug = city_slugify((string)$tour['location']);
    if (is_dir(city_dir($citySlug))) {
        $cityMeta = read_json(city_dir($citySlug) . '/meta.json', []);
        $ckb = city_knowledge($citySlug);
        if (!empty($ckb['entries'])) {
            $ckb['city'] = (string)($cityMeta['name'] ?? $citySlug);
            write_json($out . '/data/city-knowledge.json', $ckb);
        }
        write_json($out . '/data/city-instructions.json', ['city' => (string)($cityMeta['name'] ?? $citySlug), 'instructions' => (string)($cityMeta['instructions'] ?? '')]);
    }
    write_json($out . '/data/curated-answers.json', read_json($dir . '/curated-answers.json', ['version' => '1.0', 'tourId' => $tour['slug'], 'intents' => []]));
    $userScenes = read_json($dir . '/scene-identity.json', ['scenes' => []]);
    // Auto-fix empty relatedKnowledgeIds: repopulate from objects if still placeholder
    $needsFix = true; $hasAnyRelated = false;
    foreach (($userScenes['scenes'] ?? []) as $sc) { if (!empty($sc['relatedKnowledgeIds'])) { $hasAnyRelated = true; break; } }
    if (!$hasAnyRelated && !empty($parsed['skin']['objectIds'] ?? [])) $needsFix = true; else $needsFix = false;
    if ($needsFix || empty($userScenes['scenes'])) {
        $sceneNamesTmp = array_map(fn($s) => (string)($s['displayName'] ?? $s['name'] ?? $s['id']), $parsed['scenes'] ?? []);
        $oidsTmp = array_values(array_unique(array_merge($parsed['skin']['objectIds'] ?? [], array_column($parsed['objects'] ?? [], 'infoId'))));
        $objectsBySceneTmp = [];
        foreach (($parsed['objects'] ?? []) as $o) { $sid = (string)($o['sceneId'] ?? ''); if ($sid === '') continue; $objectsBySceneTmp[$sid][] = $o['infoId']; }
        $inferredScenes = $parsed['scenes'] ?? [];
        $nameMap = infer_scene_names_from_hotspots($parsed);
        foreach ($inferredScenes as &$sc) { $sid = $sc['id'] ?? ''; $cur = $sc['name'] ?? $sid; if (($cur === $sid || preg_match('/^node\d+$/i', $cur)) && isset($nameMap[$sid])) { $sc['name'] = $nameMap[$sid]; $sc['displayName'] = $nameMap[$sid]; } } unset($sc);
        $fixed = content_template_scene_identity($tour, $inferredScenes, $oidsTmp, $objectsBySceneTmp);
        // Merge: keep user edits but fill related ids if empty
        foreach ($fixed['scenes'] as $sid => $sc) {
            if (!isset($userScenes['scenes'][$sid]) || empty($userScenes['scenes'][$sid]['relatedKnowledgeIds'])) {
                $userScenes['scenes'][$sid] = $sc;
            }
        }
        if (empty($userScenes['scenes'])) $userScenes = $fixed;
        write_json($dir . '/scene-identity.json', $userScenes);
    }
    $mappedScenes = [];
    $scenesByOrder = array_values($parsed['scenes']);
    $i = 0;
    foreach (($userScenes['scenes'] ?? []) as $k => $v) {
        $realId = $k;
        if (str_starts_with((string)$k, 'scene-') && isset($scenesByOrder[$i])) {
            $realId = $scenesByOrder[$i]['id'];
        }
        $mappedScenes[$realId] = $v;
        $i++;
    }
    if (empty($mappedScenes)) {
        foreach ($parsed['scenes'] as $s) {
            $mappedScenes[$s['id']] = ['rawName' => $s['displayName'], 'displayName' => $s['displayName'], 'placeId' => slugify($s['displayName']), 'placeName' => $s['displayName'], 'variant' => null, 'type' => 'area', 'confidence' => 'NEEDS_REVIEW', 'evidence' => 'Fallback seed', 'shortDescription' => 'Isi deskripsi singkat untuk ' . $s['displayName'] . '.', 'description' => 'Isi deskripsi detail.', 'highlights' => [], 'talkingPoints' => [], 'suggestedQuestions' => [], 'relatedKnowledgeIds' => [], 'contentGrounding' => 'identity-only', 'aiHints' => ['currentLocation' => 'Anda sekarang berada di ' . $s['displayName'] . '.']];
        }
    }
    $userScenes['scenes'] = $mappedScenes;
    write_json($out . '/data/scene-identity.json', $userScenes);
    // Patch _sceneRegistry agar tour-chat__left-context-name pakai displayName kurasi (seperti Lawang Sewu), bukan placeholder node48
    $tourData['_sceneRegistry'] = $mappedScenes;
    file_put_contents($out . '/data/tour-data.js', 'window.GoVirtualTourData = ' . json_encode($tourData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';' . PHP_EOL);
    write_json($out . '/data/place-map.json', ['scenes' => array_map(fn($s) => ['sceneId' => $s['id'], 'placeId' => $tour['slug']], $parsed['scenes'])]);
    write_json($out . '/data/place-registry.json', ['places' => [['placeId' => $tour['slug'], 'name' => $tour['name'], 'location' => $tour['location']]]]);
    write_json($out . '/data/visitor-tips.json', read_json($dir . '/visitor-tips.json', ['topics' => []]));
    write_json($out . '/data/nearby.json', read_json($dir . '/nearby.json', ['items' => []]));
    // Recommended questions per-tour (State1 discovery + State2 workspace fallback)
    $rec = read_json($dir . '/recommended-questions.json', []);
    if (empty($rec) || empty($rec['state1'])) { $rec = content_template_recommended_questions($tour); write_json($dir . '/recommended-questions.json', $rec); }
    write_json($out . '/data/recommended-questions.json', $rec);
    // Navigation flow (admin-set ordered path for "kemana berikutnya")
    $flow = read_json($dir . '/flow.json', []);
    if (empty($flow) || empty($flow['order'])) { $flow = content_template_flow($tour, $parsed['scenes'] ?? []); write_json($dir . '/flow.json', $flow); }
    write_json($out . '/data/flow.json', $flow);
    // Generate oi-positions from parsed pano hotspot_oi_polygon (for objects-panel pitch/yaw navigation like backup)
    $oiSrc = $parsed['oiPositions'] ?? [];
    if (empty($oiSrc)) {
        // fallback: try to generate empty but guide.js will fetch pano.xml live
        write_json($out . '/data/oi-positions.json', ['scenes' => new stdClass()]);
    } else {
        write_json($out . '/data/oi-positions.json', ['scenes' => $oiSrc]);
    }
    file_put_contents($out . '/README.md', "# GOVIRTUAL AI\n\nPackage ready for " . $tour['name'] . " (" . $tour['location'] . ").\n\n- Copy this folder into Pano2VR export root.\n- Edit `data/knowledge.json` and `config/tour.json` for location-specific content.\n");
    // Keep catalog if source had it – copy if exists in source and not yet in out
    $catalogSrc = SOURCE_AI . '/data/semarang-destinations.json';
    $catalogDst = $out . '/data/semarang-destinations.json';
    if (is_file($catalogSrc) && !is_file($catalogDst)) copy($catalogSrc, $catalogDst);
    $pubSrc = SOURCE_AI . '/data/public-services.json';
    $pubDst = $out . '/data/public-services.json';
    if (is_file($pubSrc) && !is_file($pubDst)) copy($pubSrc, $pubDst);
    // Explore menu knowledge: use harvested file if present, else empty stub
    $exploreSrc = $dir . '/explore-menu-knowledge.json';
    $explore = is_file($exploreSrc) ? read_json($exploreSrc, ['entries' => []]) : ['version' => '1.0', 'tourId' => $tour['slug'], 'sourceType' => 'none', 'entries' => []];
    write_json($out . '/data/explore-menu-knowledge.json', $explore);
    // Sync snippet with govirtual-ai reference (GOVIRTUAL_AI_CONFIG, basePath, catalogPath, tourLocation)
    $snippet = "<!-- GOVIRTUAL AI — Add this block before </body> in your Pano2VR index.html -->\n"
        . "<link rel=\"stylesheet\" href=\"./govirtual-ai/frontend/guide.css\">\n"
        . "<script src=\"./govirtual-ai/data/tour-data.js\"></script>\n"
        . "<script>\nwindow.GOVIRTUAL_AI_CONFIG = {\n"
        . "  basePath: \"./govirtual-ai/\",\n"
        . "  tourId: " . json_encode($tour['slug'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  tourName: " . json_encode($tour['name'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  tourLocation: " . json_encode($tour['location'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  nearbyLabel: " . json_encode($tour['name'] . ' dan ' . $tour['location'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  catalogPath: \"data/semarang-destinations.json\",\n"
        . "  objectInfoBase: \"https://webview-govirtual.id/semarang-v2/assets/images/objekinfo/lawang_sewu\",\n"
        . "  objectInfoPage: \"https://semarang.govirtual.id/webview/objinfo2.php\"\n"
        . "};\n</script>\n"
        . "<script src=\"./govirtual-ai/frontend/guide.js\"></script>\n";
    file_put_contents($out . '/install-snippet.html', $snippet);
     return ['out' => $out, 'files' => ['install-snippet.html', 'README.md', 'data/tour-data.js', 'config/tour.json', 'data/curated-answers.json', 'data/knowledge.json', 'data/scene-identity.json', 'data/place-map.json', 'data/place-registry.json', 'data/visitor-tips.json', 'data/nearby.json', 'data/recommended-questions.json', 'data/flow.json', 'data/semarang-destinations.json', 'data/oi-positions.json']];
}
function deploy_package(array $tour): string {
    $source = tour_dir($tour['slug']) . '/package/govirtual-ai';
    $targetRoot = rtrim((string)$tour['export_path'], '/\\');
    $target = $targetRoot . '/govirtual-ai'; $index = $targetRoot . '/index.html';
    if (!is_dir($source)) throw new RuntimeException('Generate package dulu.');
    if (!is_file($index)) throw new RuntimeException('index.html target tidak ditemukan: ' . $index);
    $backup = $targetRoot . '/.ai-generator-backup/' . date('Ymd-His');
    if (is_dir($target)) { if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0777, true); rename($target, $backup); }
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/'); $dest = $target . '/' . $relative;
            if ($item->isDir()) { if (!is_dir($dest)) mkdir($dest, 0777, true); }
            else { if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0777, true); copy($item->getPathname(), $dest); }
        }
        $pinSrc = SOURCE_AI . '/frontend/assets/location-pin.png';
        $pinDst = $targetRoot . '/images/location-pin.png';
        if (is_file($pinSrc) && !is_file($pinDst)) {
            if (!is_dir(dirname($pinDst))) mkdir(dirname($pinDst), 0777, true);
            copy($pinSrc, $pinDst);
        }
        $html = (string)file_get_contents($index);
        $snippet = (string)file_get_contents($source . '/install-snippet.html');
        $markerStart = '<!-- GOVIRTUAL AI START -->'; $markerEnd = '<!-- GOVIRTUAL AI END -->';
        $block = $markerStart . "\n" . $snippet . $markerEnd;
        if (str_contains($html, $markerStart) && str_contains($html, $markerEnd)) $html = preg_replace('/<!-- GOVIRTUAL AI START -->.*?<!-- GOVIRTUAL AI END -->/s', $block, $html, 1) ?? $html;
        elseif (stripos($html, '</body>') !== false) $html = preg_replace('/<\/body>/i', $block . "\n</body>", $html, 1) ?? $html;
        else throw new RuntimeException('Penutup </body> tidak ditemukan.');
        // Cleanup legacy wrong config var (GoVirtualAIConfig) if present
        $html = str_replace('window.GoVirtualAIConfig', 'window.GOVIRTUAL_AI_CONFIG', $html);
        // Remove duplicate legacy apiBase injection if present
        file_put_contents($index, $html);
        // Fresh .env from hub DB (single source of truth) so deployed copy always has latest keys
        env_write_to($target . '/.env');
    } catch (Throwable $e) {
        remove_tree($target); if (is_dir($backup)) rename($backup, $target); throw $e;
    }
    return $backup;
}
function validate_parsed(array $parsed): array {
    $errors = []; $ids = array_column($parsed['scenes'] ?? [], 'id');
    if (!$ids) $errors[] = 'Tidak ada scene.';
    foreach (($parsed['hotspots'] ?? []) as $h) if (!in_array($h['target'], $ids, true)) $errors[] = 'Hotspot target hilang: ' . $h['scene'] . ' -> ' . $h['target'];
    $placeholder=0;
    foreach (($parsed['scenes'] ?? []) as $s) {
        $d = (string)($s['displayName'] ?? $s['name'] ?? $s['id']);
        if ($d === $s['id'] || preg_match('/^node\d+$/i', $d)) $placeholder++;
    }
    if ($placeholder>0) $errors[] = "Ada $placeholder panorama masih nama placeholder (nodeXX) — ganti via Scene Editor (🎨) agar guide ramah pengunjung. Panel objek hanya muncul di panorama yang punya relatedKnowledgeIds.";
    return $errors;
}
function layout_v1(string $title, string $body): void { echo '<!doctype html><html><head><meta charset="utf-8"><title>' . h($title) . '</title><style>body{font-family:system-ui,Segoe UI,Arial;margin:0;background:#0f172a;color:#e5e7eb}a{color:#93c5fd}main{max-width:1180px;margin:auto;padding:28px}.card{background:#111827;border:1px solid #334155;border-radius:16px;padding:18px;margin:14px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}input,textarea{width:100%;box-sizing:border-box;background:#020617;color:#e5e7eb;border:1px solid #475569;border-radius:10px;padding:10px}button,.btn{background:#2563eb;color:white;border:0;border-radius:10px;padding:10px 14px;text-decoration:none;display:inline-block}table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #334155;padding:8px;text-align:left}.muted{color:#94a3b8}.bad{color:#fca5a5}.ok{color:#86efac}.warn{color:#facc15}.key-mon{font-family:Consolas,monospace;font-size:12px;color:#93c5fd;word-break:break-all}</style></head><body><main><h1>' . h($title) . '</h1><p><a href="?">Tour</a> · <a href="?a=new">Tambah</a> · <a href="?a=keys">🔑 API Keys</a></p>' . $body . '</main></body></html>'; }

// Thumb proxy for scene editor — serves ht_node_thumb_*.webp from export
if (($_GET['a'] ?? '') === 'thumb') {
    $tid = (int)($_GET['id'] ?? 0);
    $scene = (string)($_GET['scene'] ?? '');
    $tour = $tid ? get_tour($tid) : null;
    if (!$tour || $scene==='') { http_response_code(404); exit; }
    $parsed = read_json(tour_dir($tour['slug']).'/parsed.json');
    $thumbPrefix = 'ht_node_thumb_1_';
    $candidates = [rtrim((string)$tour['export_path'],'/\\').'/images'];
    foreach ($candidates as $imgDir) {
        if (is_dir($imgDir)) { foreach (scandir($imgDir) as $f) if (preg_match('/^(ht_node_thumb_\d+_)/',$f,$m)) { $thumbPrefix=$m[1]; break 2; } }
    }
    $exits = [];
    foreach (($parsed['hotspots']??[]) as $h) if ($h['scene']===$scene) { $exits[]=$h['id']; }
    $suffix = $exits[0] ?? 'Point01';
    $imgPath = rtrim((string)$tour['export_path'],'/\\').'/images/'.$thumbPrefix.$scene.'_'.$suffix.'.webp';
    if (!is_file($imgPath)) {
        // fallback: try any thumb for this scene
        $dir = rtrim((string)$tour['export_path'],'/\\').'/images';
        if (is_dir($dir)) {
            foreach (scandir($dir) as $f) if (str_starts_with($f, $thumbPrefix.$scene.'_')) { $imgPath=$dir.'/'.$f; break; }
        }
    }
    if (!is_file($imgPath)) { http_response_code(404); exit; }
    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=86400');
    readfile($imgPath);
    exit;
}
if (($_GET['a'] ?? '') === 'panoview') {
    $tid = (int)($_GET['id'] ?? 0);
    $scene = (string)($_GET['scene'] ?? '');
    $tour = $tid ? get_tour($tid) : null;
    if (!$tour) { http_response_code(404); exit; }
    $export = rtrim((string)$tour['export_path'],'/\\');
    $thumbPrefix = 'ht_node_thumb_1_';
    if (is_dir($export.'/images')) foreach(scandir($export.'/images') as $f) if(preg_match('/^(ht_node_thumb_\d+_)/',$f,$m)){ $thumbPrefix=$m[1]; break; }
    // Simple preview page with panorama thumb + link to real tour
    $html = '<!doctype html><meta charset="utf-8"><style>body{margin:0;background:#0f172a;color:#e5e7eb;font-family:system-ui} a{color:#93c5fd} .wrap{max-width:900px;margin:20px auto;padding:16px}</style>';
    $html .= '<div class=wrap><h2>'.h($scene).' — '.h($tour['name']).'</h2>';
    $html .= '<p><a href="'.h($export.'/index.html#'.$scene).'" target=_blank>Buka panorama asli (index.html#'.$scene.')</a></p>';
    $html .= '<p class=muted>Export: '.h($tour['export_path']).'</p>';
    $html .= '<img src="?a=thumb&id='.$tour['id'].'&scene='.h($scene).'" alt="Preview panorama '.h($scene).' — '.h($tour['name']).'" style="width:100%;max-width:800px;border-radius:12px;border:1px solid var(--border)" loading="lazy">'; 
    $html .= '<p><a href="?a=scenes&id='.$tour['id'].'">← Kembali ke Scene Editor</a></p></div>';
    echo $html; exit;
}

// Test AI answer for a suggested question (Scene Editor hybrid).
// Konsisten 100% dengan produksi (Lawang Sewu / Sandi Buana / lokasi lain):
// memanggil api/tour-chat.php milik lokasi tsb — pipeline SAMA seperti guide.js.
function testanswer_http_post(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>40,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ['ok'=>is_string($body)&&$body!=='' ,'body'=>$body,'code'=>$code,'error'=>$err];
}
/**
 * Raw HTTP POST helper for live key testing. Returns body / HTTP code / curl error / timing (ms).
 */
function test_key_request(string $url, array $headers, array $payload, int $timeout = 30): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $t0 = microtime(true);
    $body = curl_exec($ch);
    $timeMs = (int)((microtime(true) - $t0) * 1000);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['body' => is_string($body) ? $body : '', 'code' => $code, 'time_ms' => $timeMs, 'error' => $error];
}
/**
 * Live-test satu provider key: kirim request minimal ke API aslinya.
 * gemini → REST generateContent; ollama → /api/generate; lainnya (openrouter, groq, custom) → OpenAI-compatible /chat/completions.
 */
function test_provider_key(string $provider, string $key, string $model = '', string $baseUrl = ''): array {
    $provider = strtolower($provider);
    $r = ['provider' => $provider, 'model' => $model !== '' ? $model : 'auto', 'ok' => false, 'http' => 0, 'reply' => '', 'error' => '', 'time_ms' => 0];
    if (trim($key) === '') { $r['error'] = 'API key kosong.'; return $r; }
    try {
        if ($provider === 'gemini') {
            $m = $model !== '' ? $model : 'gemini-2.0-flash';
            $res = test_key_request(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($m) . ':generateContent',
                ['Content-Type: application/json', 'x-goog-api-key: ' . trim($key)],
                ['contents' => [['parts' => [['text' => 'Ping']]]]]
            );
            $r['model'] = $m;
            $d = json_decode($res['body'], true);
            $r['http'] = $res['code']; $r['time_ms'] = $res['time_ms'];
            if ($res['error'] !== '') $r['error'] = 'curl: ' . $res['error'];
            elseif ($res['code'] === 200) { $r['ok'] = true; $r['reply'] = trim((string)($d['candidates'][0]['content']['parts'][0]['text'] ?? 'OK')); }
            else $r['error'] = (string)($d['error']['message'] ?? $res['body']);
        } elseif ($provider === 'ollama') {
            $base = rtrim($baseUrl !== '' ? $baseUrl : 'http://127.0.0.1:11434', '/');
            $m = $model !== '' ? $model : 'qwen2.5:7b';
            $res = test_key_request($base . '/api/generate', ['Content-Type: application/json'], ['model' => $m, 'prompt' => 'Ping', 'stream' => false]);
            $r['model'] = $m;
            $d = json_decode($res['body'], true);
            $r['http'] = $res['code']; $r['time_ms'] = $res['time_ms'];
            if ($res['error'] !== '') $r['error'] = 'curl: ' . $res['error'];
            elseif ($res['code'] === 200) { $r['ok'] = true; $r['reply'] = trim((string)($d['response'] ?? 'OK')); }
            else $r['error'] = (string)($d['error'] ?? $res['body']);
        } else {
            // OpenAI-compatible: openrouter, groq, dan provider custom
            if ($baseUrl === '') {
                if ($provider === 'openrouter') $baseUrl = 'https://openrouter.ai/api/v1';
                elseif ($provider === 'groq') $baseUrl = 'https://api.groq.com/openai/v1';
                else $baseUrl = 'https://api.' . $provider . '.com/v1';
            }
            if ($model === '') {
                if ($provider === 'openrouter') $model = 'openai/gpt-4o-mini';
                elseif ($provider === 'groq') $model = 'groq/compound';
            }
            $payload = ['messages' => [['role' => 'user', 'content' => 'Ping']], 'max_tokens' => 8];
            if ($model !== '') $payload['model'] = $model;
            $res = test_key_request(rtrim($baseUrl, '/') . '/chat/completions', ['Content-Type: application/json', 'Authorization: Bearer ' . trim($key)], $payload);
            $r['model'] = $model !== '' ? $model : '(default)';
            $d = json_decode($res['body'], true);
            $r['http'] = $res['code']; $r['time_ms'] = $res['time_ms'];
            if ($res['error'] !== '') $r['error'] = 'curl: ' . $res['error'];
            elseif ($res['code'] === 200) { $r['ok'] = true; $r['reply'] = trim((string)($d['choices'][0]['message']['content'] ?? 'OK')); }
            else $r['error'] = (string)($d['error']['message'] ?? ($d['error'] ?? $res['body']));
        }
    } catch (Throwable $e) {
        $r['error'] = $e->getMessage();
    }
    return $r;
}
if (($_GET['a'] ?? '') === 'testAnswer') {
    header('Content-Type: application/json; charset=utf-8');
    $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) { echo json_encode(['ok'=>false,'error'=>'tour tidak ditemukan']); exit; }
    $scene = (string)($_GET['scene'] ?? ''); $q = (string)($_GET['q'] ?? '');
    if ($scene==='' || $q==='') { echo json_encode(['ok'=>false,'error'=>'scene/pertanyaan kosong']); exit; }
    $parsed = read_json(tour_dir($tour['slug']).'/parsed.json', []);
    // Endpoint lokasi: {export}/govirtual-ai/api/tour-chat.php (SAMA dengan produksi guide)
    $htdocs = rtrim(str_replace('\\','/', dirname(APP_ROOT)), '/');
    $export = rtrim(str_replace('\\','/', (string)$tour['export_path']), '/');
    $apiUrl = '';
    if ($export !== '' && str_starts_with($export, $htdocs.'/')) {
        $rel = implode('/', array_map('rawurlencode', explode('/', substr($export, strlen($htdocs)+1))));
        $apiUrl = 'http://localhost/'.$rel.'/govirtual-ai/api/tour-chat.php';
    }
    $payload = [
        'aiRequestId' => 'ai-gen-test-'.bin2hex(random_bytes(3)),
        'message' => $q,
        'currentScene' => $scene,
        'history' => [],
        'conversationState' => ['lastSceneId'=>$scene,'visitedSceneIds'=>[$scene],'visitorProfile'=>[]],
        'viewContext' => [],
        'tourContext' => [
            'scenes' => $parsed['scenes'] ?? [],
            'hotspots' => $parsed['hotspots'] ?? [],
            'routes' => $parsed['routes'] ?? [],
            'video360' => $parsed['video360'] ?? [],
        ],
    ];
    $reply=''; $provider='';
    if ($apiUrl !== '') {
        $res = testanswer_http_post($apiUrl, $payload);
        if ($res['ok']) {
            $d = json_decode($res['body'], true);
            if (is_array($d)) { $reply = (string)($d['reply'] ?? $d['respons'] ?? $d['answer'] ?? ''); $provider = (string)($d['provider'] ?? 'backend'); }
        }
    }
    // Fallback: panggil API pusat govirtual-ai (Lawang Sewu) sebagai cadangan jika lokasi belum deploy
    if ($reply === '' && $apiUrl !== str_replace(' ', '%20', '')) { /* keep */ }
    if ($reply === '') {
        $fallbackUrl = 'http://localhost/govirtual-ai/govirtual-ai/api/tour-chat.php';
        $res2 = testanswer_http_post($fallbackUrl, $payload);
        if ($res2['ok']) {
            $d2 = json_decode($res2['body'], true);
            if (is_array($d2)) { $reply = (string)($d2['reply'] ?? $d2['respons'] ?? $d2['answer'] ?? ''); $provider = (string)($d2['provider'] ?? 'fallback-central'); }
        }
    }
    if ($reply === '') { echo json_encode(['ok'=>false,'error'=>'AI gagal menjawab (backend lokasi & central). Pastikan sudah Deploy & server aktif.', 'apiUrl'=>$apiUrl]); exit; }
    echo json_encode(['ok'=>true,'reply'=>$reply,'provider'=>$provider]); exit;
}

// Generate deskripsi scene via AI (tombol ✨ di Scene Editor) — konteks: nama panorama
if (($_GET['a'] ?? '') === 'genDesc') {
    header('Content-Type: application/json; charset=utf-8');
    $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) { echo json_encode(['ok'=>false,'error'=>'tour tidak ditemukan']); exit; }
    $scene = (string)($_GET['scene'] ?? ''); $field = (string)($_GET['field'] ?? 'short');
    if ($scene==='') { echo json_encode(['ok'=>false,'error'=>'scene kosong']); exit; }
    $parsed = read_json(tour_dir($tour['slug']).'/parsed.json', []);
    $si = read_json(tour_dir($tour['slug']).'/scene-identity.json', ['scenes'=>[]]);
    $display = $scene;
    foreach (($parsed['scenes']??[]) as $s) { if ((string)$s['id']===$scene) { $display = (string)($s['displayName'] ?? $s['name'] ?? $scene); break; } }
    if (!empty($si['scenes'][$scene]['displayName'])) $display = (string)$si['scenes'][$scene]['displayName'];
    if ($display==='' || str_starts_with($display,'node')) $display = $scene;
    $q = $field==='full'
        ? "Tulis deskripsi lengkap panorama wisata berikut (2-3 paragraf pendek, bahasa Indonesia, ramah pengunjung). Nama panorama: ".$display.". Ceritakan suasana dan hal menarik yang bisa dilihat/dinikmati pengunjung di lokasi ini. Jawab langsung dengan isi deskripsinya saja, tanpa kata pengantar."
        : "Buat deskripsi singkat satu kalimat (maks 20 kata, bahasa Indonesia) untuk panorama wisata bernama '".$display."'. Jawab langsung dengan deskripsinya saja.";
    $htdocs = rtrim(str_replace('\\','/', dirname(APP_ROOT)), '/');
    $export = rtrim(str_replace('\\','/', (string)$tour['export_path']), '/');
    $apiUrl = '';
    if ($export !== '' && str_starts_with($export, $htdocs.'/')) {
        $rel = implode('/', array_map('rawurlencode', explode('/', substr($export, strlen($htdocs)+1))));
        $apiUrl = 'http://localhost/'.$rel.'/govirtual-ai/api/tour-chat.php';
    }
    $payload = [
        'aiRequestId' => 'ai-desc-'.bin2hex(random_bytes(3)),
        'message' => $q,
        'currentScene' => $scene,
        'history' => [],
        'conversationState' => ['lastSceneId'=>$scene,'visitedSceneIds'=>[$scene],'visitorProfile'=>[]],
        'viewContext' => [],
        'tourContext' => ['scenes'=>$parsed['scenes']??[],'hotspots'=>$parsed['hotspots']??[],'routes'=>$parsed['routes']??[],'video360'=>$parsed['video360']??[]],
    ];
    $reply='';
    if ($apiUrl !== '') {
        $res = testanswer_http_post($apiUrl, $payload);
        if ($res['ok']) { $d = json_decode($res['body'], true); if (is_array($d)) $reply = (string)($d['reply'] ?? $d['respons'] ?? $d['answer'] ?? ''); }
    }
    if ($reply==='') {
        $res2 = testanswer_http_post('http://localhost/govirtual-ai/govirtual-ai/api/tour-chat.php', $payload);
        if ($res2['ok']) { $d2 = json_decode($res2['body'], true); if (is_array($d2)) $reply = (string)($d2['reply'] ?? $d2['respons'] ?? $d2['answer'] ?? ''); }
    }
    if ($reply==='') { echo json_encode(['ok'=>false,'error'=>'AI gagal menjawab (backend lokasi & central). Pastikan sudah Deploy & server aktif.']); exit; }
    echo json_encode(['ok'=>true,'reply'=>$reply], JSON_UNESCAPED_UNICODE); exit;
}

// Live-test satu API key (dipakai tombol ▶ Test di halaman ?a=keys)
if (($_GET['a'] ?? '') === 'testKey') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }
    $provider = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $_POST['provider'] ?? ''));
    $key = trim((string)($_POST['key'] ?? ''));
    $model = trim((string)($_POST['model'] ?? ''));
    $base = trim((string)($_POST['base_url'] ?? ''));
    if ($provider === '' || $key === '') { echo json_encode(['ok' => false, 'error' => 'provider/key kosong']); exit; }
    echo json_encode(test_provider_key($provider, $key, $model, $base), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = $_GET['a'] ?? 'list'; $error = '';

// Project-scoped actions need a valid tour id. If missing, show friendly pick-a-project state instead of error pages.
$projectActions = ['view','edit','import','scenes','knowledge','curated','prompts','tips','nearby','test','deploy','generate'];
if (in_array($action, $projectActions, true)) {
    $cur = get_tour((int)($_GET['id'] ?? 0));
    if (!$cur) {
        $tours = all_tours();
        $pick = '<div class="empty"><div class="empty-ic">' . studio_icon('scenes', 48) . '</div><h3>Pilih project dulu</h3><p>Halaman ini butuh konteks project. Pilih salah satu project di bawah, atau buat project baru untuk mulai.</p>'
            . '<div class="pick-tour-list">';
        foreach ($tours as $t) {
            $pick .= '<a class="pick-tour" href="?a=scenes&id=' . (int)$t['id'] . '"><span class="pick-tour-name">' . h($t['name']) . '</span><span class="pick-tour-loc">' . h($t['location']) . '</span></a>';
        }
        $pick .= '</div><a class="btn btn-primary" href="?a=new">+ Buat project baru</a></div>';
        layout('Pilih Project', $pick);
        exit;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') { $id = save_tour($_POST); header('Location: ?a=view&id=' . $id); exit; }
    if ($action === 'delete') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        remove_tree(tour_dir($tour['slug']));
        $pdo = db(); $s = $pdo->prepare('DELETE FROM tours WHERE id=?'); $s->execute([$tour['id']]);
        header('Location: ?'); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveAs') {
        $orig = get_tour((int)($_GET['id'] ?? $_POST['id'] ?? 0)); if (!$orig) throw new RuntimeException('Tour asal tidak ditemukan.');
        $newName = trim((string)($_POST['new_name'] ?? '')); if ($newName==='') throw new RuntimeException('Nama baru wajib diisi.');
        $newSlug = slugify((string)($_POST['new_slug'] ?? $newName));
        $newLoc = trim((string)($_POST['new_location'] ?? $orig['location'])); $newExport = trim((string)($_POST['new_export'] ?? ''));
        // cek slug unik
        $pdo=db(); $chk=$pdo->prepare('SELECT id FROM tours WHERE slug=?'); $chk->execute([$newSlug]);
        if ($chk->fetch()) throw new RuntimeException('Slug sudah dipakai: '.$newSlug);
        $newId = save_tour(['name'=>$newName,'slug'=>$newSlug,'location'=>$newLoc,'export_path'=>$newExport]);
        $src = tour_dir($orig['slug']); $dst = tour_dir($newSlug);
        if (!is_dir($dst)) mkdir($dst,0777,true);
        // copy semua json kurasi (tanpa parsed yang akan re-import jika export beda)
        $copyFiles = ['curated-answers.json','knowledge.json','scene-identity.json','visitor-tips.json','nearby.json','recommended-questions.json','explore-menu-knowledge.json'];
        foreach ($copyFiles as $f) if (is_file($src.'/'.$f)) {
            $data = read_json($src.'/'.$f, []);
            // update tourId/tourName di dalam json
            $json = json_encode($data, JSON_UNESCAPED_UNICODE);
            $json = str_replace($orig['slug'], $newSlug, $json);
            $json = str_replace($orig['name'], $newName, $json);
            $data = json_decode($json, true) ?? $data;
            if (isset($data['tourId'])) $data['tourId']=$newSlug;
            if (isset($data['tourName'])) $data['tourName']=$newName;
            write_json($dst.'/'.$f, $data);
        }
        // jika export sama, copy parsed juga; jika beda, biarkan kosong agar import ulang ringan (hanya pano.xml+skin.js, bukan tiles)
        $copyParsed = isset($_POST['copy_parsed']) && $_POST['copy_parsed']==='1';
        if ($copyParsed && is_file($src.'/parsed.json')) copy($src.'/parsed.json', $dst.'/parsed.json');
        header('Location: ?a=view&id='.$newId); exit;
    }
    if ($action === 'import') { $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.'); $p = parse_pano($tour['export_path']); $p['skin'] = parse_skin($tour['export_path']); write_json(tour_dir($tour['slug']) . '/parsed.json', $p); header('Location: ?a=view&id=' . $tour['id']); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'seedContent') { $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.'); seed_content($tour); header('Location: ?a=view&id=' . $tour['id'] . '#content'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveContent') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $name = (string)($_GET['name'] ?? ''); if (!array_key_exists($name, content_files())) throw new RuntimeException('Section tidak valid.');
        $raw = (string)($_POST['content'] ?? ''); json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        file_put_contents(tour_dir($tour['slug']) . '/' . $name . '.json', $raw); header('Location: ?a=view&id=' . $tour['id'] . '#content'); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveScenes') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir = tour_dir($tour['slug']);
        $si = read_json($dir . '/scene-identity.json', ['scenes'=>[]]);
        $parsed = read_json($dir . '/parsed.json', ['scenes'=>[]]);
        $sceneIds = array_column($parsed['scenes']??[], 'id');
        foreach ($sceneIds as $sid) {
            $prefix = 'scene_'.$sid.'_';
            if (!isset($_POST[$prefix.'displayName'])) continue;
            $display = trim((string)($_POST[$prefix.'displayName'] ?? ''));
            $placeName = trim((string)($_POST[$prefix.'placeName'] ?? $display));
            $shortDesc = trim((string)($_POST[$prefix.'shortDescription'] ?? ''));
            $desc = trim((string)($_POST[$prefix.'description'] ?? ''));
            $highlightsRaw = (string)($_POST[$prefix.'highlights'] ?? '');
            $highlights = array_values(array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $highlightsRaw)))));
            $sugRaw = (string)($_POST[$prefix.'suggestedQuestions'] ?? '');
            $suggested = array_values(array_filter(array_map('trim', explode("\n", $sugRaw))));
            // Hybrid suggested questions: {q, mode:'ai'|'fix', answer}
            $suggested = [];
            for ($k=0;$k<4;$k++){
                $q = trim((string)($_POST[$prefix.'sq_'.$k.'_q'] ?? ''));
                if ($q==='') continue;
                $mode = ((string)($_POST[$prefix.'sq_'.$k.'_mode'] ?? 'ai'))==='fix' ? 'fix' : 'ai';
                $ans = trim((string)($_POST[$prefix.'sq_'.$k.'_answer'] ?? ''));
                $suggested[] = ['q'=>$q,'mode'=>$mode,'answer'=>$ans];
            }
            if (!isset($si['scenes'][$sid])) {
                $si['scenes'][$sid] = ['rawName'=>$display,'displayName'=>$display,'placeId'=>slugify($display),'placeName'=>$placeName,'variant'=>null,'type'=>'area','confidence'=>'NEEDS_REVIEW','evidence'=>'Edited via Scene Editor','shortDescription'=>$shortDesc,'description'=>$desc,'highlights'=>$highlights,'talkingPoints'=>[],'suggestedQuestions'=>$suggested,'relatedKnowledgeIds'=>[],'contentGrounding'=>'identity-only','aiHints'=>['currentLocation'=>"Anda sekarang berada di $display."]];
            } else {
                $si['scenes'][$sid]['displayName']=$display ?: $si['scenes'][$sid]['displayName'];
                $si['scenes'][$sid]['placeName']=$placeName ?: $display;
                $si['scenes'][$sid]['placeId']=slugify($placeName ?: $display);
                $si['scenes'][$sid]['shortDescription']=$shortDesc;
                $si['scenes'][$sid]['description']=$desc;
                if ($highlights) $si['scenes'][$sid]['highlights']=$highlights;
                if ($suggested) $si['scenes'][$sid]['suggestedQuestions']=$suggested;
                $si['scenes'][$sid]['aiHints']['currentLocation']="Anda sekarang berada di ".($placeName ?: $display).".";
            }
            // keep relatedKnowledgeIds/contentGrounding as is (auto from pano)
        }
        $si['generatedAt']=date('Y-m-d');
        write_json($dir . '/scene-identity.json', $si);
        $do = (string)($_POST['do'] ?? '');
        if ($do==='save_generate') { $r=build_files($tour); header('Location: ?a=scenes&id='.$tour['id'].'&saved=1&generated=1'); exit; }
        if ($do==='save_deploy') { build_files($tour); $backup=deploy_package($tour); header('Location: ?a=scenes&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=scenes&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'scenes') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) { layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $parsed = read_json(tour_dir($tour['slug']).'/parsed.json');
        if (!$parsed) { layout('Belum import','<div class=card><p>Import pano/skin dulu.</p><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">Kembali</a></p></div>'); exit; }
        $si = read_json(tour_dir($tour['slug']).'/scene-identity.json', ['scenes'=>[]]);
        $saved = isset($_GET['saved']); $generated = isset($_GET['generated']); $deployed = isset($_GET['deployed']);
        $msg='';
        if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Scene disimpan.</p>'.($generated?'<p class=ok>✓ Package digenerate.</p>':'').($deployed?'<p class=ok>✓ Deploy ke export selesai.</p>':'').'</div>';
        $body = $msg;
        $body .= '<div class=card><h2>Scene Editor — '.h($tour['name']).'</h2><p class=muted>'.h($tour['export_path']).' — '.count($parsed['scenes']??[]).' scene terdeteksi dari pano.xml. Klik thumbnail untuk preview, edit nama/deskripsi, lalu Save.</p>';
        $body .= '<p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a> <a class=btn href="?a=panoview&id='.(int)$tour['id'].'&scene='.h($parsed['scenes'][0]['id']??'').'" target=_blank class="btn btn-ghost">Preview Tour</a></p></div>';
        $body .= '<form method=post action="?a=saveScenes&id='.(int)$tour['id'].'" id=sceneForm>';
        $navOpts=''; $idx=0;
        foreach (($parsed['scenes']??[]) as $ns) {
            $nid=(string)$ns['id']; $nd=$ns['displayName']??$nid;
            $nsc=$si['scenes'][$nid]??null; if($nsc){ $nd=$nsc['displayName']??$nd; }
            $navOpts.='<option value="'.h($nid).'">'.h(($idx+1).'. '.($nd===$nid?'node '.$nid:$nd)).'</option>';
            $idx++;
        }
        $body .= '<div style="position:sticky;top:0;z-index:6;background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:8px 10px;margin-bottom:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
        $body .= '<span style="font-size:12px;color:var(--pill-text)">Lompat ke scene:</span><select onchange="jumpScene(this.value)" style="max-width:360px;flex:1;min-width:180px"><option value="">— pilih panorama —</option>'.$navOpts.'</select>';
        $body .= '<button type="button" class="btn btn-ghost btn-sm" onclick="toggleAll(true)">▾ Buka semua</button>';
        $body .= '<button type="button" class="btn btn-ghost btn-sm" onclick="toggleAll(false)">▴ Tutup semua</button>';
        $body .= '</div>';
        $body .= '<div style="display:grid;grid-template-columns:1fr;gap:16px">';
        $knowledge = read_json(tour_dir($tour['slug']).'/knowledge.json', ['entries'=>[]]);
        $objTitles = [];
        foreach ($knowledge['entries'] as $e) { $objTitles[(string)($e['id']??'')] = (string)($e['title']??$e['id']); }
        foreach (($parsed['scenes']??[]) as $scene) {
            $sid=(string)$scene['id']; $display=$scene['displayName']??$sid;
            $place=$display; $sc=$si['scenes'][$sid]??null;
            if($sc){ $display=$sc['displayName']??$display; $place=$sc['placeName']??$display; }
            $thumbUrl='?a=thumb&id='.(int)$tour['id'].'&scene='.urlencode($sid);
            $rkIds = $sc['relatedKnowledgeIds'] ?? [];
            $hasObj = !empty($rkIds);
            $rkNames = [];
            foreach ($rkIds as $rid) { $rkNames[] = $objTitles[(string)$rid] ?? $rid; }
            $badge = $hasObj ? '<span style="background:var(--success);color:var(--ok-text);font-size:11px;padding:3px 8px;border-radius:999px">'.count($rkIds).' objek info</span>' : '<span style="background:var(--pill-bg);color:var(--pill-text);font-size:11px;padding:3px 8px;border-radius:999px">Tidak ada objek</span>';
            $isPlaceholder = ($display===$sid || str_starts_with($display,'node'));
            $warn = $isPlaceholder ? '<span style="color:var(--warning);font-size:12px">⚠ Nama masih placeholder — ganti biar guide ramah pengunjung</span>' : '';
            $body .= '<details class=scene-card id="scene-'.h($sid).'" data-sid="'.h($sid).'">';
            $body .= '<summary style="cursor:pointer;display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;background:var(--surface-1)"><img src="'.h($thumbUrl).'" alt="" style="width:60px;height:38px;object-fit:cover;border-radius:6px;border:1px solid var(--border);background:var(--code-bg)" loading="lazy" onerror="this.style.display=\'none\'"><span style="flex:1;font-weight:600;font-size:13.5px">'.h($display).'</span><span style="font-size:11px;color:var(--pill-text)">'.h($sid).'</span>'.$badge.'<span class=chev style="font-size:12px;color:var(--pill-text);transition:transform .15s">▾</span></summary>';
            $body .= '<div style="margin-top:10px" class=scene-body>';
            $body .= '<div class=card style="display:grid;grid-template-columns:240px 1fr;gap:16px;align-items:start">';
            $body .= '<div><a href="?a=panoview&id='.(int)$tour['id'].'&scene='.urlencode($sid).'" target=_blank aria-label="Preview panorama '.h($display).'"><img src="'.h($thumbUrl).'" alt="Thumbnail '.h($display).' ('.h($sid).')" style="width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:10px;border:1px solid var(--border);background:var(--code-bg)" loading="lazy" onerror="this.style.display=\'none\'"></a>';
            $body .= '<div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap"><a href="?a=panoview&id='.(int)$tour['id'].'&scene='.urlencode($sid).'" target=_blank style="font-size:12px;color:var(--link-code)">👁 Preview</a> <a href="'.h(rtrim($tour['export_path'],'/\\').'/index.html#'.$sid).'" target=_blank style="font-size:12px;color:var(--link-code)">↗ Buka pano asli</a></div>';
            $body .= '<div style="margin-top:6px;font-size:12px;color:var(--pill-text)">ID: <code>'.h($sid).'</code><br>customNodeId: <code>'.h($scene['customNodeId']??'-').'</code></div>';
            $body .= '<div style="margin-top:6px">'.$badge.'</div></div>';
            $body .= '<div>';
            $body .= '<label style="font-size:12px;color:var(--pill-text)">Nama Panorama (displayName) '.$warn.'</label><input name="scene_'.$sid.'_displayName" value="'.h($display).'" placeholder="'.h($sid).'" style="margin-bottom:8px">';
            $body .= '<label style="font-size:12px;color:var(--pill-text)">Place Name (untuk AI: "Anda berada di ...")</label><input name="scene_'.$sid.'_placeName" value="'.h($place).'" style="margin-bottom:8px">';
            $body .= '<label style="font-size:12px;color:var(--pill-text)">Short Description (1 baris, untuk list) <button type="button" class="btn btn-ghost btn-sm" onclick="genDesc(\''.h($sid).'\',\'shortDescription\',this)">✨ AI</button></label><textarea name="scene_'.$sid.'_shortDescription" rows=2 style="margin-bottom:8px">'.h($sc['shortDescription']??'').'</textarea>';
            $body .= '<label style="font-size:12px;color:var(--pill-text)">Deskripsi lengkap (untuk guide) <button type="button" class="btn btn-ghost btn-sm" onclick="genDesc(\''.h($sid).'\',\'description\',this)">✨ AI</button></label><textarea name="scene_'.$sid.'_description" rows=4 style="margin-bottom:8px">'.h($sc['description']??'').'</textarea>';
            $body .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">';
            $body .= '<div><label style="font-size:12px;color:var(--pill-text)">Highlights (satu per baris)</label><textarea name="scene_'.$sid.'_highlights" rows=3>'.h(implode("\n",$sc['highlights']??[])).'</textarea></div>';
            $body .= '</div>';
            // Hybrid Suggested Questions — tiap pertanyaan bisa AI (mode=ai) atau Jawaban Fix (mode=fix)
            $sqList = $sc['suggestedQuestions'] ?? [];
            $sqArr=[];
            foreach ($sqList as $sq){ if(is_string($sq)) $sqArr[]=['q'=>$sq,'mode'=>'ai','answer'=>'']; else $sqArr[]=['q'=>$sq['q']??'','mode'=>$sq['mode']??'ai','answer'=>$sq['answer']??'']; }
            $body .= '<div style="margin-top:8px;border-top:1px solid var(--border);padding-top:8px"><label style="font-size:12px;color:var(--pill-text)">💬 Suggested Questions — Hybrid (bisa AI atau Jawaban Fix, admin set)</label>';
            for($k=0;$k<4;$k++){
                $item=$sqArr[$k]??['q'=>'','mode'=>'ai','answer'=>''];
                $body .= '<div style="display:grid;grid-template-columns:2fr 130px;gap:6px;margin-top:6px;align-items:start">';
                $body .= '<div><input name="scene_'.$sid.'_sq_'.$k.'_q" value="'.h($item['q']).'" placeholder="Pertanyaan '.($k+1).' (contoh: Apa yang menarik di sini?)"></div>';
                $body .= '<div><select name="scene_'.$sid.'_sq_'.$k.'_mode" style="width:100%"><option value="ai"'.($item['mode']==='ai'?' selected':'').'>🤖 AI</option><option value="fix"'.($item['mode']==='fix'?' selected':'').'>📝 Jawaban Fix</option></select></div>';
                $body .= '<div style="grid-column:1 / -1"><textarea name="scene_'.$sid.'_sq_'.$k.'_answer" rows=2 placeholder="(opsional) Jawaban fix — kalau kosong & mode AI, AI yang jawab">'.h($item['answer']).'</textarea>';
                $body .= '<div style="display:flex;gap:8px;align-items:center;margin-top:4px"><button type="button" onclick="testAI(\''.h($sid).'\','.$k.')" class="btn btn-ghost btn-sm">▶ Test AI</button> <span style="font-size:11px;color:var(--pill-text)">Cek jawaban AI (mode AI) untuk pertanyaan ini — pakai deskripsi/highlight/knowledge scene.</span></div>';
                $body .= '<div id="sq-test-'.h($sid).'-'.$k.'" style="display:none;margin-top:6px;background:var(--code-bg);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:12.5px;color:var(--text-primary);white-space:pre-wrap;line-height:1.6"></div>';
                $body .= '</div>';
                $body .= '</div>';
            }
            $body .= '<div style="font-size:11px;color:var(--pill-text);margin-top:6px">Mode <b>AI</b> → jawaban di-generate AI (pakai deskripsi/highlight/knowledge scene). Mode <b>Jawaban Fix</b> → jawaban ditampilkan apa adanya tanpa panggil AI. Kosongkan pertanyaan yang tidak dipakai.</div></div>';
            if($hasObj) $body .= '<div style="margin-top:6px;font-size:12px;color:var(--link-light)">Objek informasi di panorama ini:<br><code>'.h(implode('</code>, <code>', $rkNames)).'</code></div>';
            $body .= '</div></div></div></details>';
        }
        $body .= '</div>';
        $body .= '<div class=card style="position:sticky;bottom:0;background:var(--surface-2);z-index:5;display:flex;gap:10px;flex-wrap:wrap"><button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan Scene</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate Package</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning" onclick="return confirm(\'Simpan, generate, dan deploy ke export? \')">🚀 Simpan + Deploy ke Export</button> <span style="font-size:12px;color:var(--pill-text);align-self:center">Deploy akan update govirtual-ai + index.html di '.h($tour['export_path']).'</span></div>';
        $body .= '</form>';
        $body .= '<style>@media(max-width:900px){ .card[style*=\"grid-template-columns:240px\"]{grid-template-columns:1fr !important} }</style>';
        $body .= '<script>
function testAI(sid,k){
  var qEl=document.querySelector(\'[name="scene_\'+sid+\'_sq_\'+k+\'_q"]\');
  var mEl=document.querySelector(\'[name="scene_\'+sid+\'_sq_\'+k+\'_mode"]\');
  var box=document.getElementById(\'sq-test-\'+sid+\'-\'+k);
  var q=qEl?qEl.value.trim():\'\';
  var mode=mEl?mEl.value:\'ai\';
  if(!box){ alert(\'Kotak hasil tidak ditemukan.\'); return; }
  box.style.display=\'block\';
  if(mode===\'fix\'){ box.textContent=\'Mode = Jawaban Fix — jawaban langsung dari field \\\'Jawaban fix\\\' di atas (tidak panggil AI).\'; return; }
  if(!q){ box.textContent=\'Pertanyaan kosong — isi kolom pertanyaan dulu.\'; return; }
  box.textContent=\'⏳ Memanggil AI (backend lokasi — sama seperti produksi)…\';
  var url=\'?a=testAnswer&id='.(int)$tour['id'].'&scene=\'+encodeURIComponent(sid)+\'&q=\'+encodeURIComponent(q);
  fetch(url).then(function(r){ return r.json(); }).then(function(d){
    if(d && d.ok) box.textContent=(d.reply||\'(jawaban kosong)\');
    else box.textContent=\'❌ \'+(d&&d.error?d.error:\'gagal\');
  }).catch(function(e){ box.textContent=\'❌ \'+e; });
}
function jumpScene(sid){ if(!sid) return; var d=document.querySelector(\'.scene-card[data-sid="\'+sid+\'"]\'); if(d){ d.open=true; d.scrollIntoView({behavior:\'smooth\',block:\'start\'}); } }
function toggleAll(open){ document.querySelectorAll(\'.scene-card\').forEach(function(d){ d.open=open; }); }
function genDesc(sid,field,btn){
  var ta=document.querySelector(\'[name="scene_\'+sid+\'_\'+field+\'"]\');
  if(!ta){ alert(\'Field tidak ditemukan.\'); return; }
  var prev=btn.textContent; btn.disabled=true; btn.textContent=\'⏳ …\';
  fetch(\'?a=genDesc&id='.(int)$tour['id'].'&scene=\'+encodeURIComponent(sid)+\'&field=\'+field).then(function(r){ return r.json(); }).then(function(d){
    btn.disabled=false; btn.textContent=prev;
    if(d && d.ok){ ta.value=(d.reply||\'\').trim(); }
    else alert(\'❌ \'+(d&&d.error?d.error:\'gagal\'));
  }).catch(function(e){ btn.disabled=false; btn.textContent=prev; alert(\'❌ \'+e); });
}
</script>';
        layout('Scene Editor — '. $tour['name'], $body); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'savePrompts') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir = tour_dir($tour['slug']);
        $rec = read_json($dir . '/recommended-questions.json', content_template_recommended_questions($tour));
        // State1 starters (3)
        $starters=[];
        for($i=0;$i<3;$i++){
            $label=trim((string)($_POST["starter_{$i}_label"]??'')); $query=trim((string)($_POST["starter_{$i}_query"]??'')); $desc=trim((string)($_POST["starter_{$i}_desc"]??$query));
            if($label!=='' && $query!=='') $starters[]=['label'=>$label,'query'=>$query,'desc'=>$desc];
        }
        if($starters) $rec['state1']['starters']=$starters;
        $themes=[];
        for($i=0;$i<6;$i++){
            $label=trim((string)($_POST["theme_{$i}_label"]??'')); $query=trim((string)($_POST["theme_{$i}_query"]??''));
            if($label!=='' && $query!=='') $themes[]=['label'=>$label,'query'=>$query];
        }
        if($themes) $rec['state1']['themes']=$themes;
        $fallbacks=[];
        for($i=0;$i<4;$i++){
            $q=trim((string)($_POST["fallback_{$i}_q"]??'')); $intent=trim((string)($_POST["fallback_{$i}_intent"]??''));
            if($q!=='') $fallbacks[]=['q'=>$q,'intent'=>$intent?:'TOUR_DESCRIPTION'];
        }
        if($fallbacks) $rec['state2']['fallback']=$fallbacks;
        $header=trim((string)($_POST['workspaceHeader']??''));
        if($header!=='') $rec['state2']['workspaceHeader']=$header;
        $rec['updatedAt']=date('c');
        write_json($dir . '/recommended-questions.json', $rec);
        $do=(string)($_POST['do']??'');
        if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=prompts&id='.$tour['id'].'&saved=1&generated=1'); exit; }
        if($do==='save_deploy'){ build_files($tour); $backup=deploy_package($tour); header('Location: ?a=prompts&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=prompts&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'prompts') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) { layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $rec = read_json(tour_dir($tour['slug']).'/recommended-questions.json', content_template_recommended_questions($tour));
        // Auto-fix old "Sangga" to "Sandi"
        $jsonStr=json_encode($rec, JSON_UNESCAPED_UNICODE); if(strpos($jsonStr,'Sangga')!==false){ $jsonStr=str_replace(['Sangga Buana','Sangga','sangga buana'],['Sandi Buana','Sandi','sandi buana'],$jsonStr); $rec=json_decode($jsonStr,true); write_json(tour_dir($tour['slug']).'/recommended-questions.json',$rec); }
        $saved=isset($_GET['saved']); $generated=isset($_GET['generated']); $deployed=isset($_GET['deployed']);
        $msg=''; if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Recommended Questions disimpan.</p>'.($generated?'<p class=ok>✓ Package digenerate.</p>':'').($deployed?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        $body=$msg;
        $body.='<div class=card><h2>Recommended Questions — '.h($tour['name']).'</h2><p class=muted>Edit pertanyaan rekomendasi State 1 (Discovery: COBA TANYAKAN & JELAJAHI TOPIK) dan State 2 (Workspace: MUNGKIN ANDA INGIN TAHU fallback). Berbeda per lokasi.</p><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a> <a class=btn href="?a=scenes&id='.(int)$tour['id'].'" class="btn btn-ai">Scene Editor</a></p></div>';
        $body.='<form method=post action="?a=savePrompts&id='.(int)$tour['id'].'">';
        $body.='<div class=card><h3>State 1 — Discovery: COBA TANYAKAN (3 starters)</h3><p class=muted>Muncul di overlay awal. Contoh Sandi Buana: "Apa yang menarik di sini?" / "Mengapa disebut Sandi Buana?"</p>';
        for($i=0;$i<3;$i++){ $s=$rec['state1']['starters'][$i]??['label'=>'','query'=>'','desc'=>'']; $body.='<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px"><div><label style="font-size:12px;color:var(--pill-text)">Label '.$i.'</label><input name="starter_'.$i.'_label" value="'.h($s['label']??'').'" placeholder="Apa yang menarik"></div><div><label style="font-size:12px;color:var(--pill-text)">Query '.$i.'</label><input name="starter_'.$i.'_query" value="'.h($s['query']??'').'" placeholder="Apa yang menarik di Sandi Buana?"></div><div><label style="font-size:12px;color:var(--pill-text)">Desc '.$i.'</label><input name="starter_'.$i.'_desc" value="'.h($s['desc']??'').'"></div></div>'; }
        $body.='</div>';
        $body.='<div class=card><h3>State 1 — Discovery: JELAJAHI TOPIK (6 themes)</h3>';
        for($i=0;$i<6;$i++){ $t=$rec['state1']['themes'][$i]??['label'=>'','query'=>'']; $body.='<div style="display:grid;grid-template-columns:1fr 2fr;gap:8px;margin-bottom:8px"><div><label style="font-size:12px;color:var(--pill-text)">Label</label><input name="theme_'.$i.'_label" value="'.h($t['label']??'').'" placeholder="Sejarah"></div><div><label style="font-size:12px;color:var(--pill-text)">Query</label><input name="theme_'.$i.'_query" value="'.h($t['query']??'').'" placeholder="Sejarah '.$tour['name'].'"></div></div>'; }
        $body.='</div>';
        $body.='<div class=card><h3>State 2 — Workspace: Fallback (4 pertanyaan saat panorama identity-only)</h3><p class=muted>Muncul di "MUNGKIN ANDA INGIN TAHU" ketika panorama tidak punya relatedKnowledgeIds. Ganti "Sandi Buana" per lokasi.</p>';
        $body.='<label style="font-size:12px;color:var(--pill-text)">Header Workspace</label><input name="workspaceHeader" value="'.h($rec['state2']['workspaceHeader']??'MUNGKIN ANDA INGIN TAHU').'" style="margin-bottom:12px">';
        for($i=0;$i<4;$i++){ $f=$rec['state2']['fallback'][$i]??['q'=>'','intent'=>'']; $body.='<div style="display:grid;grid-template-columns:2fr 1fr;gap:8px;margin-bottom:8px"><div><label style="font-size:12px;color:var(--pill-text)">Pertanyaan '.$i.'</label><input name="fallback_'.$i.'_q" value="'.h($f['q']??'').'" placeholder="Mengapa disebut '.$tour['name'].'?"></div><div><label style="font-size:12px;color:var(--pill-text)">Intent</label><input name="fallback_'.$i.'_intent" value="'.h($f['intent']??'').'" placeholder="NAME_ORIGIN"></div></div>'; }
        $body.='</div>';
        $body.='<div class=card style="position:sticky;bottom:0;background:var(--surface-2);z-index:5;display:flex;gap:10px;flex-wrap:wrap"><button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning" onclick="return confirm(\'Simpan, generate, deploy?\')">🚀 Simpan + Deploy</button></div>';
        $body.='</form>';
        layout('Recommended Questions — '.$tour['name'], $body); exit;
    }
    // Curated Questions editor
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveCurated') {
        $tour=get_tour((int)$_GET['id']); if(!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir=tour_dir($tour['slug']); $data=read_json($dir.'/curated-answers.json',['intents'=>[]]);
        $intents=[];
        $count=(int)($_POST['intent_count']??0);
        for($i=0;$i<$count;$i++){
            if(isset($_POST["del_$i"])) continue;
            $id=trim((string)($_POST["intent_{$i}_id"]??'')); if($id==='') continue;
            $cat=trim((string)($_POST["intent_{$i}_category"]??'')); $aliases=array_values(array_filter(array_map('trim', explode(',', (string)($_POST["intent_{$i}_aliases"]??''))))); $keywords=array_values(array_filter(array_map('trim', explode(',', (string)($_POST["intent_{$i}_keywords"]??''))))); $neg=array_values(array_filter(array_map('trim', explode(',', (string)($_POST["intent_{$i}_negative"]??'')))));
            $intents[]=['id'=>$id,'category'=>$cat,'aliases'=>$aliases,'keywords'=>$keywords,'negativePatterns'=>$neg,'dynamic'=>true,'action'=>null];
        }
        if(isset($_POST['add_intent'])) $intents[]=['id'=>'G'.str_pad((string)(count($intents)+1),2,'0',STR_PAD_LEFT).'_NEW','category'=>'custom','aliases'=>[],'keywords'=>[],'negativePatterns'=>[],'dynamic'=>true,'action'=>null];
        $data['intents']=$intents; write_json($dir.'/curated-answers.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=curated&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); $b=deploy_package($tour); header('Location: ?a=curated&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=curated&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveFlow') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir = tour_dir($tour['slug']); $parsed = read_json($dir . '/parsed.json', []);
        $flow = read_json($dir . '/flow.json', content_template_flow($tour, $parsed['scenes'] ?? []));
        $order = [];
        $count = (int)($_POST['flow_count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            if (isset($_POST["del_$i"])) continue;
            $sid = trim((string)($_POST["flow_{$i}_scene"] ?? ''));
            if ($sid === '' || in_array($sid, $order, true)) continue;
            $order[] = $sid;
            $flow['nodes'][$sid]['displayName'] = trim((string)($_POST["flow_{$i}_displayName"] ?? $flow['nodes'][$sid]['displayName'] ?? $sid));
        }
        $start = trim((string)($_POST['startScene'] ?? ''));
        if ($start !== '' && in_array($start, $order, true)) $flow['startScene'] = $start;
        $flow['order'] = $order;
        $flow['updatedAt'] = date('c');
        write_json($dir . '/flow.json', $flow);
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save_generate') { build_files($tour); header('Location: ?a=flow&id=' . $tour['id'] . '&saved=1&generated=1'); exit; }
        if ($do === 'save_deploy') { build_files($tour); deploy_package($tour); header('Location: ?a=flow&id=' . $tour['id'] . '&saved=1&deployed=1'); exit; }
        header('Location: ?a=flow&id=' . $tour['id'] . '&saved=1'); exit;
    }
    if ($action === 'flow') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) { layout('Tour hilang', '<p>Tour tidak ditemukan.</p>'); exit; }
        $dir = tour_dir($tour['slug']);
        $parsed = read_json($dir . '/parsed.json');
        if (!$parsed) { layout('Belum import', '<div class=card><p>Import pano/skin dulu.</p><p><a class=btn href="?a=view&id=' . (int)$tour['id'] . '">Kembali</a></p></div>'); exit; }
        $si = read_json($dir . '/scene-identity.json', ['scenes' => []]);
        $flow = read_json($dir . '/flow.json', content_template_flow($tour, $parsed['scenes'] ?? []));
        $saved = isset($_GET['saved']); $generated = isset($_GET['generated']); $deployed = isset($_GET['deployed']);
        $msg = '';
        if ($saved) $msg .= '<div class=card style="border-color:var(--success)"><p class=ok>✓ Flow disimpan.</p>' . ($generated ? '<p class=ok>✓ Package digenerate.</p>' : '') . ($deployed ? '<p class=ok>✓ Deploy ke export selesai.</p>' : '') . '</div>';
        $body = $msg;
        $body .= '<div class=card><h2>Navigation Flow — ' . h($tour['name']) . '</h2><p class=muted>Atur urutan kunjungan panorama: pilih <b>titik awal</b> lalu susun urutan <b>kemana berikutnya</b>. Pertanyaan "Ke mana berikutnya?" akan mengikuti urutan ini tanpa kembali ke panorama yang sudah dikunjungi.</p>';
        $body .= '<p><a class=btn href="?a=view&id=' . (int)$tour['id'] . '">← Kembali</a></p></div>';
        $body .= '<form method=post action="?a=saveFlow&id=' . (int)$tour['id'] . '">';
        // Start scene selector
        $startOpts = '';
        foreach (($flow['order'] ?: array_column($parsed['scenes'] ?? [], 'id')) as $sid) {
            $dn = $flow['nodes'][$sid]['displayName'] ?? $si['scenes'][$sid]['displayName'] ?? $sid;
            $startOpts .= '<option value="' . h($sid) . '"' . (($flow['startScene'] ?? '') === $sid ? ' selected' : '') . '>' . h($dn) . ' (' . h($sid) . ')</option>';
        }
        $body .= '<div class=card><label style="font-size:12px;color:var(--pill-text)">🎬 Titik Awal Panorama</label><select name="startScene" style="max-width:420px">' . $startOpts . '</select><p class=muted style="font-size:12px;margin-top:6px">Tur dimulai dari panorama ini. Urutan di bawah menentukan panorama berikutnya yang disarankan.</p></div>';
        // Ordered flow rows (reorder via up/down buttons + JS)
        $body .= '<div class=card><h3>Urutan Kunjungan (kemana berikutnya)</h3><p class=muted style="font-size:12px">Urutkan sesuai alur yang Anda inginkan. Gunakan tombol ▲ ▼ untuk pindah posisi, atau pilih panorama dari dropdown tiap baris.</p>';
        $order = $flow['order'] ?: array_column($parsed['scenes'] ?? [], 'id');
        $allOpts = '';
        $i = 0;
        foreach (($parsed['scenes'] ?? []) as $s) {
            $sid = (string)$s['id'];
            $dn = $flow['nodes'][$sid]['displayName'] ?? $si['scenes'][$sid]['displayName'] ?? $s['displayName'] ?? $sid;
            $allOpts .= '<option value="' . h($sid) . '">' . h(($i + 1) . '. ' . ($dn === $sid ? 'node ' . $sid : $dn)) . '</option>';
            $i++;
        }
        foreach ($order as $idx => $sid) {
            $dn = $flow['nodes'][$sid]['displayName'] ?? $si['scenes'][$sid]['displayName'] ?? $sid;
            $body .= '<div class="flow-row" data-idx="' . $idx . '" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">';
            $body .= '<span style="font-size:13px;font-weight:600;min-width:28px;color:var(--pill-text)">' . ($idx + 1) . '.</span>';
            $body .= '<input type="hidden" name="flow_' . $idx . '_scene" value="' . h($sid) . '">';
            $body .= '<select name="flow_' . $idx . '_scene" style="max-width:340px;flex:1;min-width:200px">';
            foreach (($parsed['scenes'] ?? []) as $s) {
                $oid = (string)$s['id'];
                $odn = $flow['nodes'][$oid]['displayName'] ?? $si['scenes'][$oid]['displayName'] ?? $s['displayName'] ?? $oid;
                $body .= '<option value="' . h($oid) . '"' . ($oid === $sid ? ' selected' : '') . '>' . h($odn === $oid ? 'node ' . $oid : $odn) . '</option>';
            }
            $body .= '</select>';
            $body .= '<input name="flow_' . $idx . '_displayName" value="' . h($dn === $sid ? '' : $dn) . '" placeholder="(opsional) label" style="max-width:200px">';
            $body .= '<button type="button" class="btn btn-ghost btn-sm" onclick="flowMove(' . $idx . ',-1)" title="Naik">▲</button>';
            $body .= '<button type="button" class="btn btn-ghost btn-sm" onclick="flowMove(' . $idx . ',1)" title="Turun">▼</button>';
            $body .= '<label style="font-size:12px"><input type=checkbox name="del_' . $idx . '" value=1> Hapus</label>';
            $body .= '</div>';
        }
        $body .= '<input type=hidden name=flow_count value="' . count($order) . '"></div>';
        $body .= '<div class=card style="position:sticky;bottom:0;background:var(--surface-2);display:flex;gap:8px;flex-wrap:wrap"><button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning">🚀 Deploy</button> <span style="font-size:12px;color:var(--pill-text);align-self:center">Setiap panorama mendapat pertanyaan "Ke mana berikutnya?" yang mengikuti urutan ini.</span></div>';
        $body .= '</form>';
        $body .= '<script>
function flowMove(idx, dir){
  var rows = Array.prototype.slice.call(document.querySelectorAll(".flow-row"));
  var cur = rows.findIndex(function(r){ return parseInt(r.getAttribute("data-idx"),10) === idx; });
  if(cur < 0) return;
  var target = cur + dir;
  if(target < 0 || target >= rows.length) return;
  rows[cur].parentNode.insertBefore(rows[cur], dir < 0 ? rows[target] : rows[target].nextSibling);
  var n = 0;
  document.querySelectorAll(".flow-row").forEach(function(r){
    r.querySelectorAll("input[name$=_scene], input[name$=_displayName]").forEach(function(el){
      el.name = el.name.replace(/flow_\d+_/, "flow_" + n + "_");
    });
    var del = r.querySelector("input[type=checkbox][name^=del_]");
    if(del) del.name = "del_" + n;
    r.querySelector("span:first-child").textContent = (n + 1) + ".";
    r.setAttribute("data-idx", n);
    n++;
  });
  var cnt = document.querySelector("[name=flow_count]");
  if(cnt) cnt.value = n;
}
</script>';
        layout('Navigation Flow — ' . $tour['name'], $body); exit;
    }
    if ($action === 'curated') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/curated-answers.json',['intents'=>[]]);
        $saved=isset($_GET['saved']); $gen=isset($_GET['generated']); $dep=isset($_GET['deployed']);
        $msg=''; if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Curated Questions disimpan.</p>'.($gen?'<p class=ok>✓ Package digenerate.</p>':'').($dep?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        $body=$msg.'<div class=card><h2>Curated Questions — '.h($tour['name']).'</h2><p class=muted>Pertanyaan prioritas yang dijawab sebelum AI pipeline. Edit aliases/keywords per lokasi.</p><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div>';
        $body.='<form method=post action="?a=saveCurated&id='.(int)$tour['id'].'">';
        $body.='<div style="display:grid;gap:12px">';
        foreach(($data['intents']??[]) as $idx=>$it){
            $body.='<div class=card style="border-left:4px solid var(--ai-accent)"><div style="display:flex;justify-content:space-between;align-items:center"><strong>'.h($it['id']).'</strong> <label style="font-size:12px"><input type=checkbox name="del_'.$idx.'" value=1> Hapus</label></div>';
            $body.='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px"><div><label style="font-size:12px;color:var(--pill-text)">ID</label><input name="intent_'.$idx.'_id" value="'.h($it['id']).'"></div><div><label style="font-size:12px;color:var(--pill-text)">Category</label><input name="intent_'.$idx.'_category" value="'.h($it['category']??'').'"></div></div>';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Aliases (pisah koma)</label><input name="intent_'.$idx.'_aliases" value="'.h(implode(', ', $it['aliases']??[])).'" placeholder="saya di mana, posisi saya">';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Keywords (pisah koma)</label><input name="intent_'.$idx.'_keywords" value="'.h(implode(', ', $it['keywords']??[])).'">';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Negative Patterns (pisah koma)</label><input name="intent_'.$idx.'_negative" value="'.h(implode(', ', $it['negativePatterns']??[])).'">';
            $body.='</div>';
        }
        $body.='</div><input type=hidden name=intent_count value="'.count($data['intents']??[]).'">';
        $body.='<div class=card style="position:sticky;bottom:0;background:var(--surface-2);z-index:5;display:flex;gap:8px;flex-wrap:wrap"><button type="submit" name="add_intent" value="1" class="btn btn-ghost">+ Tambah Intent</button> <button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning" onclick="return confirm(\'Simpan, generate, deploy?\')">🚀 Simpan + Deploy</button></div></form>';
        layout('Curated Questions — '.$tour['name'],$body); exit;
    }
    // Knowledge editor
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveKnowledge') {
        $tour=get_tour((int)$_GET['id']); if(!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir=tour_dir($tour['slug']); $data=read_json($dir.'/knowledge.json',['entries'=>[]]);
        $count=(int)($_POST['entry_count']??0); $entries=[];
        for($i=0;$i<$count;$i++){
            if(isset($_POST["del_$i"])) continue;
            $id=trim((string)($_POST["entry_{$i}_id"]??'')); $title=trim((string)($_POST["entry_{$i}_title"]??'')); $aliases=array_values(array_filter(array_map('trim', explode(',', (string)($_POST["entry_{$i}_aliases"]??''))))); $type=trim((string)($_POST["entry_{$i}_type"]??'information')); $content=trim((string)($_POST["entry_{$i}_content"]??''));
            if($id==='' && $title==='') continue;
            if($id==='') $id='manual:'.slugify($title);
            $entries[]=['id'=>$id,'entityId'=>$id,'title'=>$title,'aliases'=>$aliases,'type'=>$type,'categories'=>[$type],'tags'=>['manual'],'sceneIds'=>[],'content'=>$content,'source'=>'ai-generator','sourceId'=>$id,'sourceType'=>'manual','provenance'=>'manual','translations'=>['id'=>['title'=>$title,'content'=>$content],'en'=>['title'=>$title,'content'=>$content]]];
        }
        if(isset($_POST['add_entry'])) $entries[]=['id'=>'manual:'.slugify('entry baru '.(count($entries)+1)),'entityId'=>'manual:entry','title'=>'Judul Baru','aliases'=>[],'type'=>'information','categories'=>['informasi'],'tags'=>['manual'],'sceneIds'=>[],'content'=>'Isi konten...','source'=>'ai-generator','provenance'=>'manual','translations'=>['id'=>['title'=>'Judul Baru','content'=>'Isi konten...']]];
        $data['entries']=$entries; $data['updatedAt']=date('c'); write_json($dir.'/knowledge.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=knowledge&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); $b=deploy_package($tour); header('Location: ?a=knowledge&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=knowledge&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'knowledge') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/knowledge.json',['entries'=>[]]);
        $saved=isset($_GET['saved']); $gen=isset($_GET['generated']); $dep=isset($_GET['deployed']);
        $msg=''; if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Knowledge disimpan.</p>'.($gen?'<p class=ok>✓ Package digenerate.</p>':'').($dep?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        $body=$msg.'<div class=card><h2>Knowledge — '.h($tour['name']).' ('.count($data['entries']??[]).' entries)</h2><p class=muted>Judul + aliases dipakai untuk pencocokan pertanyaan. Content dipakai AI untuk jawaban.</p><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div>';
        $body.='<form method=post action="?a=saveKnowledge&id='.(int)$tour['id'].'">';
        foreach(($data['entries']??[]) as $idx=>$e){
            $body.='<div class=card><div style="display:flex;justify-content:space-between"><strong>'.h($e['title']??$e['id']).'</strong> <label style="font-size:12px"><input type=checkbox name="del_'.$idx.'" value=1> Hapus</label></div>';
            $body.='<div style="display:grid;grid-template-columns:2fr 1fr;gap:8px"><div><label style="font-size:12px;color:var(--pill-text)">ID</label><input name="entry_'.$idx.'_id" value="'.h($e['id']).'"></div><div><label style="font-size:12px;color:var(--pill-text)">Type</label><input name="entry_'.$idx.'_type" value="'.h($e['type']??'information').'"></div></div>';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Title</label><input name="entry_'.$idx.'_title" value="'.h($e['title']??'').'">';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Aliases (pisah koma)</label><input name="entry_'.$idx.'_aliases" value="'.h(implode(', ', $e['aliases']??[])).'">';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Content</label><textarea name="entry_'.$idx.'_content" rows=4>'.h($e['content']??'').'</textarea>';
            $body.='</div>';
        }
        $body.='<input type=hidden name=entry_count value="'.count($data['entries']??[]).'">';
        $body.='<div class=card style="position:sticky;bottom:0;background:var(--surface-2);z-index:5;display:flex;gap:8px"><button type="submit" name="add_entry" value="1" class="btn btn-ghost">+ Tambah Entry</button> <button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning">🚀 Deploy</button></div></form>';
        layout('Knowledge — '.$tour['name'],$body); exit;
    }
    // Visitor Tips editor
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveTips') {
        $tour=get_tour((int)$_GET['id']); if(!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir=tour_dir($tour['slug']); $data=read_json($dir.'/visitor-tips.json',['topics'=>[]]);
        $topics=[];
        $count=(int)($_POST['topic_count']??0);
        for($i=0;$i<$count;$i++){
            if(isset($_POST["del_$i"])) continue;
            $id=trim((string)($_POST["topic_{$i}_id"]??'')); $title=trim((string)($_POST["topic_{$i}_title"]??'')); $cat=trim((string)($_POST["topic_{$i}_category"]??'')); $content=trim((string)($_POST["topic_{$i}_content"]??'')); $aliases=array_values(array_filter(array_map('trim', explode(',', (string)($_POST["topic_{$i}_aliases"]??'')))));
            if($id==='' && $title==='') continue;
            if($id==='') $id=slugify($title);
            $topics[]=['id'=>$id,'title'=>$title,'aliases'=>$aliases,'category'=>$cat,'content'=>$content,'items'=>[['label'=>'Info','detail'=>$content]]];
        }
        if(isset($_POST['add_topic'])) $topics[]=['id'=>'topic-'.(count($topics)+1),'title'=>'Topik Baru','aliases'=>[],'category'=>'umum','content'=>'Isi tips...','items'=>[]];
        $data['topics']=$topics; write_json($dir.'/visitor-tips.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=tips&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); deploy_package($tour); header('Location: ?a=tips&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=tips&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'tips') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/visitor-tips.json',['topics'=>[]]);
        $saved=isset($_GET['saved']); $msg=$saved?'<div class=card style="border-color:var(--success)"><p class=ok>✓ Visitor Tips disimpan.</p></div>':'';
        $body=$msg.'<div class=card><h2>Visitor Tips — '.h($tour['name']).'</h2><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div><form method=post action="?a=saveTips&id='.(int)$tour['id'].'">';
        foreach(($data['topics']??[]) as $idx=>$t){
            $body.='<div class=card><div style="display:flex;justify-content:space-between"><strong>'.h($t['title']).'</strong> <label style="font-size:12px"><input type=checkbox name="del_'.$idx.'" value=1> Hapus</label></div>';
            $body.='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><div><label style="font-size:12px;color:var(--pill-text)">ID</label><input name="topic_'.$idx.'_id" value="'.h($t['id']).'"></div><div><label style="font-size:12px;color:var(--pill-text)">Category</label><input name="topic_'.$idx.'_category" value="'.h($t['category']??'').'"></div></div>';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Title</label><input name="topic_'.$idx.'_title" value="'.h($t['title']).'">';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Aliases (pisah koma)</label><input name="topic_'.$idx.'_aliases" value="'.h(implode(', ', $t['aliases']??[])).'">';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Content</label><textarea name="topic_'.$idx.'_content" rows=3>'.h($t['content']??'').'</textarea>';
            $body.='</div>';
        }
        $body.='<input type=hidden name=topic_count value="'.count($data['topics']??[]).'"><div class=card style="position:sticky;bottom:0;background:var(--surface-2);display:flex;gap:8px"><button type="submit" name="add_topic" value="1" class="btn btn-ghost">+ Tambah Topik</button> <button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning">🚀 Deploy</button></div></form>';
        layout('Visitor Tips — '.$tour['name'],$body); exit;
    }
    // Nearby editor
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveNearby') {
        $tour=get_tour((int)$_GET['id']); if(!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir=tour_dir($tour['slug']); $data=read_json($dir.'/nearby.json',['items'=>[]]);
        $items=[];
        $count=(int)($_POST['item_count']??0);
        for($i=0;$i<$count;$i++){
            if(isset($_POST["del_$i"])) continue;
            $id=trim((string)($_POST["item_{$i}_id"]??'')); $name=trim((string)($_POST["item_{$i}_name"]??'')); $cat=trim((string)($_POST["item_{$i}_category"]??'')); $addr=trim((string)($_POST["item_{$i}_address"]??'')); $dist=trim((string)($_POST["item_{$i}_distance"]??'')); $time=trim((string)($_POST["item_{$i}_travelTime"]??'')); $aliases=array_values(array_filter(array_map('trim', explode(',', (string)($_POST["item_{$i}_aliases"]??'')))));
            if($id==='' && $name==='') continue;
            if($id==='') $id=slugify($name);
            $items[]=['id'=>$id,'name'=>$name,'category'=>$cat,'address'=>$addr,'distance'=>$dist,'travelTime'=>$time,'aliases'=>$aliases];
        }
        if(isset($_POST['add_item'])) $items[]=['id'=>'nearby-'.(count($items)+1),'name'=>'Tempat Baru','category'=>'wisata','address'=>'','distance'=>'','travelTime'=>'','aliases'=>[]];
        $data['items']=$items; write_json($dir.'/nearby.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=nearby&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); deploy_package($tour); header('Location: ?a=nearby&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=nearby&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'nearby') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/nearby.json',['items'=>[]]);
        $saved=isset($_GET['saved']); $msg=$saved?'<div class=card style="border-color:var(--success)"><p class=ok>✓ Nearby disimpan.</p></div>':'';
        $body=$msg.'<div class=card><h2>Nearby — '.h($tour['name']).'</h2><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div><form method=post action="?a=saveNearby&id='.(int)$tour['id'].'">';
        foreach(($data['items']??[]) as $idx=>$it){
            $body.='<div class=card><div style="display:flex;justify-content:space-between"><strong>'.h($it['name']).'</strong> <label style="font-size:12px"><input type=checkbox name="del_'.$idx.'" value=1> Hapus</label></div>';
            $body.='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><div><label style="font-size:12px;color:var(--pill-text)">ID</label><input name="item_'.$idx.'_id" value="'.h($it['id']).'"></div><div><label style="font-size:12px;color:var(--pill-text)">Category</label><input name="item_'.$idx.'_category" value="'.h($it['category']??'').'"></div></div>';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Name</label><input name="item_'.$idx.'_name" value="'.h($it['name']).'">';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Aliases (pisah koma)</label><input name="item_'.$idx.'_aliases" value="'.h(implode(', ', $it['aliases']??[])).'">';
            $body.='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><div><label style="font-size:12px;color:var(--pill-text)">Address</label><input name="item_'.$idx.'_address" value="'.h($it['address']??'').'"></div><div><label style="font-size:12px;color:var(--pill-text)">Distance</label><input name="item_'.$idx.'_distance" value="'.h($it['distance']??'').'"></div></div>';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Travel Time</label><input name="item_'.$idx.'_travelTime" value="'.h($it['travelTime']??'').'">';
            $body.='</div>';
        }
        $body.='<input type=hidden name=item_count value="'.count($data['items']??[]).'"><div class=card style="position:sticky;bottom:0;background:var(--surface-2);display:flex;gap:8px"><button type="submit" name="add_item" value="1" class="btn btn-ghost">+ Tambah Item</button> <button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning">🚀 Deploy</button></div></form>';
        layout('Nearby — '.$tour['name'],$body); exit;
    }
    if ($action === 'generate') { $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.'); $r = build_files($tour); layout('Package generated', '<div class="card"><p class="ok">Package siap: <code>' . h($r['out']) . '</code></p><ul><li>' . implode('</li><li>', array_map('h', $r['files'])) . '</li></ul><p><a class="btn" href="?a=view&id=' . (int)$tour['id'] . '">Kembali</a></p></div>'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveKeys') {
        // Save keys by DB id (robust against deletion shifting indices)
        $postedIds = (array)($_POST['k_id'] ?? []);
        foreach ($postedIds as $i => $idStr) {
            $id = (int)$idStr; if ($id <= 0) continue;
            if (isset($_POST['del_' . $i])) { api_key_delete($id); continue; }
            $prov = trim((string)($_POST["k_{$i}_provider"] ?? 'gemini'));
            $label = trim((string)($_POST["k_{$i}_label"] ?? ''));
            $val = trim((string)($_POST["k_{$i}_key"] ?? ''));
            if ($val === '' && !isset($_POST["k_{$i}_key"])) $val = '';
            $active = isset($_POST["k_{$i}_active"]) ? 1 : 0;
            $prio = (int)($_POST["k_{$i}_priority"] ?? 0);
            api_key_save($prov, $label, $val, $active, $prio, $id);
        }
        // Add new keys (add_provider_N / add_key_N / add_label_N)
        $newProviders = (array)($_POST['add_provider'] ?? []);
        $newKeys = (array)($_POST['add_key'] ?? []);
        $newLabels = (array)($_POST['add_label'] ?? []);
        for ($i = 0; $i < count($newKeys); $i++) {
            $p = trim((string)($newProviders[$i] ?? 'gemini')); if ($p === '') $p = 'gemini';
            $p = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $p));
            $v = trim((string)($newKeys[$i] ?? '')); if ($v === '') continue;
            $l = trim((string)($newLabels[$i] ?? ''));
            $maxPrio = 0; foreach (api_key_list($p) as $r) $maxPrio = max($maxPrio, (int)$r['priority']);
            api_key_save($p, $l, $v, 1, $maxPrio + 1);
        }
        // Add a brand-new custom provider (OpenAI-compatible) from the "Tambah Provider" card
        $newProvName = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', trim((string)($_POST['new_provider_name'] ?? ''))));
        $newProvKey = trim((string)($_POST['new_provider_key'] ?? ''));
        $newProvLabel = trim((string)($_POST['new_provider_label'] ?? ''));
        if ($newProvName !== '' && $newProvKey !== '') {
            $maxPrio = 0; foreach (api_key_list($newProvName) as $r) $maxPrio = max($maxPrio, (int)$r['priority']);
            api_key_save($newProvName, $newProvLabel, $newProvKey, 1, $maxPrio + 1);
        }
        // General env settings
        foreach (['AI_PROVIDER','AI_FALLBACK_PROVIDERS','AI_REQUEST_TIMEOUT','AI_DEBUG','AI_REASONING_MODE','GEMINI_MODEL','GEMINI_FALLBACK_MODELS','OPENROUTER_MODEL','OPENROUTER_BASE_URL','GROQ_MODEL','OLLAMA_ENABLED','OLLAMA_URL','OLLAMA_MODEL','OLLAMA_EMBED_MODEL','OLLAMA_NUM_PREDICT','OLLAMA_TEMPERATURE'] as $skey) {
            if (isset($_POST['set_' . $skey])) setting_set($skey, trim((string)$_POST['set_' . $skey]));
        }
        // Per-provider MODEL / BASE_URL for every custom provider (also saves new ones added above)
        foreach (api_key_providers() as $prov) {
            if (in_array($prov, ['gemini', 'openrouter', 'groq', 'ollama'], true)) continue;
            $envName = provider_env_name($prov);
            foreach ([$envName . '_MODEL', $envName . '_BASE_URL'] as $skey) {
                if (isset($_POST['set_' . $skey])) setting_set($skey, trim((string)$_POST['set_' . $skey]));
            }
        }
        $written = env_sync_all();
        $list = implode('<br><code>' , array_map('h', $written));
        layout('API Keys disimpan', '<div class="card" style="border-color:var(--success)"><p class="ok">✓ API keys & settings disimpan dan di-sync ke ' . count($written) . ' target.</p><p class="muted" style="font-size:12px">' . $list . '</p><p><a class="btn" href="?a=keys">← Kembali ke API Keys</a></p></div>');
        exit;
    }
    if ($action === 'keys') {
        $keys = api_key_list();
        $providers = [];
        foreach ($keys as $r) { $providers[$r['provider']][] = $r; }
        $body = $error;
        $body .= '<div class="card"><h2>🔑 API Keys — AI Guide Hub</h2><p class="muted">Satu sumber kebenaran API key. Simpan di sini → otomatis di-generate menjadi <code>.env</code> dan di-sync ke master, semua package, dan semua lokasi ter-deploy. Backup key dipakai otomatis oleh backend saat key utama gagal (Gemini & OpenRouter & Groq).</p>';
        $body .= '<p class="muted">Saat ini: <b>' . count($keys) . '</b> key terdaftar. Primer per provider = priority 0.</p></div>';
        $body .= '<form method="post" action="?a=saveKeys">';
        // General settings card
        $gFields = [
            'AI_PROVIDER' => 'Provider utama (gemini | openrouter | groq | ollama)',
            'AI_FALLBACK_PROVIDERS' => 'Fallback providers (pisah koma)',
            'AI_REQUEST_TIMEOUT' => 'Timeout request (detik)',
            'AI_DEBUG' => 'Debug (true/false)',
            'AI_REASONING_MODE' => 'Mode reasoning (fast|balanced|deep)',
            'GEMINI_MODEL' => 'Gemini model utama',
            'GEMINI_FALLBACK_MODELS' => 'Gemini fallback models (koma)',
            'OPENROUTER_MODEL' => 'OpenRouter model',
            'OPENROUTER_BASE_URL' => 'OpenRouter base URL',
            'GROQ_MODEL' => 'Groq model',
            'OLLAMA_ENABLED' => 'Ollama enabled (true/false)',
            'OLLAMA_URL' => 'Ollama URL',
            'OLLAMA_MODEL' => 'Ollama model',
            'OLLAMA_EMBED_MODEL' => 'Ollama embed model',
            'OLLAMA_NUM_PREDICT' => 'Ollama max tokens',
            'OLLAMA_TEMPERATURE' => 'Ollama temperature',
        ];
        $body .= '<div class="card" style="padding:var(--space-5)"><h3 style="margin-bottom:var(--space-4)">⚙️ Pengaturan Umum</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">';
        foreach ($gFields as $k => $desc) {
            $v = setting_get($k, '');
            $body .= '<div class="field" style="margin-bottom:0"><label for="set_' . h($k) . '" style="font-size:11px;color:var(--pill-text);text-transform:none;letter-spacing:0">' . h($k) . ' — ' . h($desc) . '</label><input id="set_' . h($k) . '" name="set_' . h($k) . '" value="' . h($v) . '" class="mono" autocomplete="off" spellcheck="false"></div>';
        }
        $body .= '</div></div>';
        // Keys per provider (dinamis: 4 bawaan + provider custom yang sudah ditambahkan)
        $idToIdx = [];
        foreach ($keys as $idx => $r) $idToIdx[(int)$r['id']] = $idx;
        $provLabels = [
            'gemini' => 'Gemini (Google)',
            'openrouter' => 'OpenRouter',
            'groq' => 'Groq',
            'ollama' => 'Ollama (lokal)',
        ];
        $customProviders = array_values(array_filter(api_key_providers(), fn($p) => !in_array($p, ['gemini', 'openrouter', 'groq', 'ollama'], true)));
        $allProviders = array_merge(array_keys($provLabels), $customProviders);
        foreach ($allProviders as $provKey) {
            $provLabel = $provLabels[$provKey] ?? ucfirst($provKey) . ' (OpenAI-compatible)';
            $isCustom = !isset($provLabels[$provKey]);
            $rows = $providers[$provKey] ?? [];
            $activeCount = 0; foreach ($rows as $r) if ((int)$r['is_active'] === 1) $activeCount++;
            $badge = $activeCount > 0
                ? '<span style="background:var(--success);color:var(--ok-text);font-size:11px;padding:3px 8px;border-radius:999px;margin-left:8px">'.$activeCount.' key aktif</span>'
                : '<span style="background:var(--warning);color:var(--warn-text);font-size:11px;padding:3px 8px;border-radius:999px;margin-left:8px">⚠ tidak ada key aktif</span>';
            $customTag = $isCustom ? '<span style="background:var(--ai-accent);color:#fff;font-size:10px;padding:2px 6px;border-radius:999px;margin-left:8px;vertical-align:middle">CUSTOM</span>' : '';
            $body .= '<div class="card" style="' . ($isCustom ? 'border:1px solid var(--ai-accent)' : '') . '"><h3>🔑 ' . h($provLabel) . $customTag . $badge . ' <span class="muted" style="font-size:12px">(priority 0 = primer, sisanya backup)</span></h3>';
            if ($rows) {
                $pidxs = [];
                foreach ($rows as $r) $pidxs[] = $idToIdx[(int)$r['id']] ?? 0;
                $body .= '<div style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><button type="button" onclick="testAllKeys(\'' . h($provKey) . '\', [' . implode(',', $pidxs) . '], \'provider-test-' . h($provKey) . '\')" class="btn btn-ghost btn-sm">🧪 Test Semua Key (' . count($pidxs) . ')</button> <span style="font-size:11px;color:var(--pill-text)">Cek langsung ke API masing-masing model (butuh koneksi internet; Ollama butuh server lokal aktif). Tombol ▶ juga ada per key.</span></div>';
                $body .= '<div id="provider-test-' . h($provKey) . '" style="display:none;margin:0 0 8px;background:var(--code-bg);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:12.5px;line-height:1.8"></div>';
            }
            if (!$rows) $body .= '<p class="muted">Belum ada key. Tambahkan di bawah.</p>';
            foreach ($rows as $r) {
                $pidx = $idToIdx[(int)$r['id']] ?? 0;
                $isPrimary = (int)$r['priority'] === 0 && (int)$r['is_active'] === 1;
                $prioBadge = $isPrimary ? '<span style="background:var(--primary);color:#fff;font-size:10px;padding:2px 6px;border-radius:999px;margin-left:4px;line-height:1.6">AKTIF</span>' : '';
                $body .= '<div style="display:grid;grid-template-columns:auto 1fr 1fr 60px auto auto;gap:8px;align-items:center;margin-bottom:4px">';
                $body .= '<input type="hidden" name="k_id[]" value="' . (int)$r['id'] . '">';
                $body .= '<input type="hidden" name="k_' . $pidx . '_provider" value="' . h($r['provider']) . '">';
                $body .= '<label style="font-size:12px"><input type="checkbox" name="del_' . $pidx . '" value="1"> Hapus</label>';
                $body .= '<input name="k_' . $pidx . '_label" value="' . h($r['label']) . '" placeholder="Label (contoh: Key A)">';
                $body .= '<input class="key-mon" name="k_' . $pidx . '_key" value="' . h($r['api_key']) . '" placeholder="API key">';
                $body .= '<div style="display:flex;align-items:center;gap:2px"><input name="k_' . $pidx . '_priority" value="' . h((string)$r['priority']) . '" placeholder="0" style="width:46px">' . $prioBadge . '</div>';
                $body .= '<label style="font-size:12px;white-space:nowrap"><input type="checkbox" name="k_' . $pidx . '_active" ' . ((int)$r['is_active'] === 1 ? 'checked' : '') . '> Aktif</label>';
                $body .= '<button type="button" onclick="testKey(' . (int)$pidx . ', \'' . h($r['provider']) . '\')" class="btn btn-success btn-sm" title="Test API key ini ke model yang aktif">▶ Test</button>';
                $body .= '</div>';
                $body .= '<div id="key-test-' . (int)$pidx . '" style="display:none;margin:0 0 8px;background:var(--code-bg);border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-size:12.5px;line-height:1.6;white-space:pre-wrap"></div>';
            }
            $body .= '<div style="margin-top:10px;border-top:1px solid var(--border);padding-top:10px"><label style="font-size:12px;color:var(--pill-text)">+ Tambah key baru ' . h($provLabel) . '</label>';
            $body .= '<div style="display:grid;grid-template-columns:1fr 2fr;gap:8px;margin-top:6px">';
            $body .= '<input name="add_label[]" placeholder="Label (contoh: Key B)">';
            $body .= '<input class="key-mon" name="add_key[]" placeholder="Paste API key di sini">';
            $body .= '<input type="hidden" name="add_provider[]" value="' . h($provKey) . '">';
            $body .= '</div></div>';
            // Custom provider: model & base URL setting
            if ($isCustom) {
                $envName = provider_env_name($provKey);
                $modelVal = setting_get($envName . '_MODEL', '');
                $baseVal = setting_get($envName . '_BASE_URL', 'https://api.' . $provKey . '.com/v1');
                $body .= '<div style="margin-top:10px;border-top:1px dashed var(--ai-accent);padding-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:8px">';
                $body .= '<div><label style="font-size:11px;color:var(--pill-text)">Model (env ' . h($envName) . '_MODEL)</label><input name="set_' . h($envName) . '_MODEL" value="' . h($modelVal) . '" placeholder="contoh: deepseek-chat" class="mono" spellcheck="false"></div>';
                $body .= '<div><label style="font-size:11px;color:var(--pill-text)">Base URL (env ' . h($envName) . '_BASE_URL)</label><input name="set_' . h($envName) . '_BASE_URL" value="' . h($baseVal) . '" placeholder="https://api.' . h($provKey) . '.com/v1" class="mono" spellcheck="false"></div>';
                $body .= '</div>';
                $body .= '<p class="muted" style="font-size:11px;margin-top:6px">Provider custom dianggap OpenAI-compatible: runtime memakai <code>' . h($envName) . '_API_KEY</code>, <code>' . h($envName) . '_MODEL</code>, <code>' . h($envName) . '_BASE_URL</code>. Bisa dipilih sebagai AI_PROVIDER / AI_FALLBACK_PROVIDERS.</p>';
            }
            $body .= '</div>';
        }
        // Add custom provider card
        $body .= '<div class="card" style="border:1px dashed var(--ai-accent);background:var(--surface-3)"><h3>➕ Tambah Provider Baru (OpenAI-compatible)</h3>';
        $body .= '<p class="muted">Tidak dibatasi hanya Gemini / Groq / OpenRouter / Ollama. Tambahkan provider apa pun yang punya API OpenAI-compatible — contoh: <b>DeepSeek</b>, <b>Mistral</b>, <b>Anthropic</b>, <b>OpenAI</b>, <b>xAI</b>, <b>Together</b>, <b>Cohere</b>, <b>Perplexity</b>, atau endpoint custom Anda sendiri.</p>';
        $body .= '<div style="display:grid;grid-template-columns:1fr 2fr;gap:8px">';
        $body .= '<div><label style="font-size:12px;color:var(--pill-text)">Nama provider</label><input name="new_provider_name" placeholder="contoh: deepseek" class="mono" spellcheck="false"></div>';
        $body .= '<div><label style="font-size:12px;color:var(--pill-text)">API key</label><input class="key-mon" name="new_provider_key" placeholder="Paste API key di sini"></div>';
        $body .= '</div>';
        $body .= '<label style="font-size:12px;color:var(--pill-text);margin-top:8px;display:block">Label opsional</label><input name="new_provider_label" placeholder="contoh: DeepSeek" style="margin-top:4px">';
        $body .= '<p class="muted" style="font-size:12px;margin-top:8px">Setelah simpan, provider baru muncul sebagai kartu di atas — isi <b>Model</b> & <b>Base URL</b> di sana, lalu set <code>AI_PROVIDER</code> atau tambahkan ke <code>AI_FALLBACK_PROVIDERS</code>.</p></div>';
        $body .= '<div class="card" style="position:sticky;bottom:0;background:var(--surface-2);z-index:5"><button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan & Sync Semua</button> <span style="font-size:12px;color:var(--pill-text)">Menulis .env ke master + semua package + semua lokasi ter-deploy.</span></div>';
        $body .= '</form>';
        // Live-test API keys: JS untuk tombol ▶ Test (per key) & 🧪 Test Semua Key (per provider)
        $body .= '<script>
function escHtml(s){ var d=document.createElement("div"); d.textContent=(s==null?"":String(s)); return d.innerHTML; }
function keyModel(provider){
  var names={gemini:"set_GEMINI_MODEL",openrouter:"set_OPENROUTER_MODEL",groq:"set_GROQ_MODEL",ollama:"set_OLLAMA_MODEL"};
  var n=names[provider]||("set_"+provider.toUpperCase()+"_MODEL");
  var el=document.querySelector("[name=\""+n+"\"]");
  return el?el.value.trim():"";
}
function keyBase(provider){
  var names={openrouter:"set_OPENROUTER_BASE_URL",ollama:"set_OLLAMA_URL"};
  var n=names[provider]||("set_"+provider.toUpperCase()+"_BASE_URL");
  var el=document.querySelector("[name=\""+n+"\"]");
  return el?el.value.trim():"";
}
function renderTestResult(d){
  if(!d) return "<span style=\"color:var(--danger)\">❌ Tidak ada respon dari server</span>";
  var t=(d.time_ms||0)+" ms";
  if(d.ok){
    var rep=d.reply?" — "+escHtml(d.reply.slice(0,90)):"";
    return "<span style=\"color:var(--link-light)\">✓ OK ("+t+")</span> <span style=\"color:var(--pill-text)\">HTTP "+d.http+" · model "+escHtml(d.model)+"</span>"+rep;
  }
  var err=d.error||("HTTP "+d.http);
  return "<span style=\"color:var(--danger)\">✗ Gagal ("+t+")</span> <span style=\"color:var(--danger)\">HTTP "+d.http+" — "+escHtml(err)+"</span>";
}
function testKey(pidx, provider){
  var keyEl=document.querySelector("[name=\"k_"+pidx+"_key\"]");
  var box=document.getElementById("key-test-"+pidx);
  if(!keyEl||!box) return;
  box.style.display="block";
  var key=keyEl.value.trim();
  if(!key){ box.innerHTML="<span style=\"color:var(--warning)\">⚠ Key kosong — isi API key dulu.</span>"; return; }
  box.innerHTML="<span style=\"color:var(--link-code)\">⏳ Testing "+escHtml(provider)+" ("+escHtml(keyModel(provider)||"auto")+")…</span>";
  var fd=new FormData();
  fd.append("provider",provider);
  fd.append("key",key);
  fd.append("model",keyModel(provider));
  fd.append("base_url",keyBase(provider));
  fetch("?a=testKey",{method:"POST",body:fd})
    .then(function(r){return r.json();})
    .then(function(d){ box.innerHTML=renderTestResult(d); })
    .catch(function(e){ box.innerHTML="<span style=\"color:var(--danger)\">❌ "+escHtml(e)+"</span>"; });
}
async function testAllKeys(provider, pidxList, boxId){
  var box=document.getElementById(boxId);
  if(!box) return;
  box.style.display="block";
  if(!pidxList||!pidxList.length){ box.innerHTML="<span style=\"color:var(--warning)\">Tidak ada key untuk di-test.</span>"; return; }
  box.innerHTML="<span style=\"color:var(--link-code)\">⏳ Testing "+escHtml(provider)+" ("+pidxList.length+" key)…</span>";
  var lines=[];
  for(var i=0;i<pidxList.length;i++){
    var pidx=pidxList[i];
    var keyEl=document.querySelector("[name=\"k_"+pidx+"_key\"]");
    if(!keyEl||!keyEl.value.trim()){ lines.push("<span style=\"color:var(--warning)\">• Key "+pidx+": kosong (skip)</span>"); continue; }
    box.innerHTML="<span style=\"color:var(--link-code)\">⏳ Testing key "+pidx+"…</span>";
    var fd=new FormData();
    fd.append("provider",provider);
    fd.append("key",keyEl.value.trim());
    fd.append("model",keyModel(provider));
    fd.append("base_url",keyBase(provider));
    try{
      var d=await (await fetch("?a=testKey",{method:"POST",body:fd})).json();
      lines.push("• Key "+pidx+": "+renderTestResult(d));
    }catch(e){
      lines.push("<span style=\"color:var(--danger)\">• Key "+pidx+": "+escHtml(e)+"</span>");
    }
  }
  box.innerHTML=lines.join("<div style=\"margin-top:4px\"></div>");
}
</script>';
        // Preview generated .env
        $body .= '<div class="card"><h3>👁️ Pratinjau .env yang akan di-generate</h3><textarea rows=18 readonly style="font-family:monospace;font-size:12px">' . h(env_render_from_db()) . '</textarea></div>';
        layout('API Keys', $body); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'deploy') { $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.'); $backup = deploy_package($tour); layout('Deploy selesai', '<div class="card"><p class="ok">AI Guide terpasang ke <code>' . h($tour['export_path']) . '</code>.</p><p>Backup package lama: <code>' . h($backup) . '</code></p><p><a class="btn" href="?a=view&id=' . (int)$tour['id'] . '">Kembali</a></p></div>'); exit; }
    /* ---- City Knowledge actions ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citySave') {
        $slug = city_slugify((string)($_POST['city'] ?? '')); if ($slug === '') throw new RuntimeException('Kota tidak valid.');
        $name = trim((string)($_POST['name'] ?? '')) ?: $slug;
        $m = read_json(city_dir($slug) . '/meta.json', []); $m['name'] = $name; $m['instructions'] = trim((string)($_POST['instructions'] ?? '')); write_json(city_dir($slug) . '/meta.json', $m);
        $kb = city_knowledge($slug); $count = (int)($_POST['entry_count'] ?? 0); $entries = [];
        for ($i = 0; $i < $count; $i++) {
            if (isset($_POST["del_$i"])) continue;
            $id = trim((string)($_POST["entry_{$i}_id"] ?? '')); $title = trim((string)($_POST["entry_{$i}_title"] ?? '')); if ($id === '' && $title === '') continue;
            if ($id === '') $id = 'manual:' . city_slugify($title);
            $aliases = array_values(array_filter(array_map('trim', explode(',', (string)($_POST["entry_{$i}_aliases"] ?? '')))));
            $content = trim((string)($_POST["entry_{$i}_content"] ?? ''));
            $entries[] = ['id' => $id, 'entityId' => $id, 'title' => $title, 'aliases' => $aliases, 'type' => 'city-information', 'categories' => ['informasi kota'], 'tags' => ['manual'], 'sceneIds' => [], 'content' => $content, 'source' => $name, 'sourceType' => 'manual', 'provenance' => 'manual', 'translations' => ['id' => ['title' => $title, 'content' => $content], 'en' => ['title' => $title, 'content' => $content]]];
        }
        if (isset($_POST['add_entry'])) $entries[] = ['id' => 'manual:' . city_slugify('judul baru ' . (count($entries) + 1)), 'entityId' => 'manual:entry', 'title' => 'Judul Baru', 'aliases' => [], 'type' => 'city-information', 'categories' => ['informasi kota'], 'tags' => ['manual'], 'sceneIds' => [], 'content' => 'Isi konten...', 'source' => $name, 'sourceType' => 'manual', 'provenance' => 'manual', 'translations' => ['id' => ['title' => 'Judul Baru', 'content' => 'Isi konten...'], 'en' => ['title' => 'Judul Baru', 'content' => 'Isi konten...']]];
        $kb['entries'] = $entries; city_save_knowledge($slug, $kb);
        $synced = city_sync_packages($slug);
        header('Location: ?a=city&city=' . urlencode($slug) . '&saved=1&synced=' . count($synced)); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citySourceUrl') {
        $slug = city_slugify((string)($_POST['city'] ?? '')); $url = trim((string)($_POST['url'] ?? ''));
        if ($slug === '' || $url === '') { header('Location: ?a=citySources&city=' . urlencode($slug) . '&error=' . urlencode('Kota & URL wajib diisi.')); exit; }
        $res = city_fetch_url($url);
        if (!$res['ok']) { header('Location: ?a=citySources&city=' . urlencode($slug) . '&error=' . urlencode($res['error'])); exit; }
        $src = city_sources($slug);
        $added = city_ingest_text($slug, $res['text'], $url, 'url');
        $src['sources'][] = ['type' => 'url', 'url' => $url, 'added' => $added, 'at' => date('c')];
        city_save_sources($slug, $src);
        $synced = city_sync_packages($slug);
        header('Location: ?a=citySources&city=' . urlencode($slug) . '&saved=1&added=' . $added . '&synced=' . count($synced)); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citySourceFile') {
        $slug = city_slugify((string)($_POST['city'] ?? '')); if ($slug === '') throw new RuntimeException('Kota tidak valid.');
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload file gagal.');
        $tmp = (string)$_FILES['file']['tmp_name']; $name = (string)$_FILES['file']['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['txt', 'md', 'csv', 'html', 'htm', 'docx', 'doc', 'pdf'], true)) throw new RuntimeException('Tipe file tidak didukung: .' . $ext);
        $safe = date('YmdHis') . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $name);
        if (!move_uploaded_file($tmp, city_raw_dir($slug) . '/' . $safe)) throw new RuntimeException('Gagal menyimpan file.');
        $text = city_parse_file(city_raw_dir($slug) . '/' . $safe, $ext);
        if (trim($text) === '') throw new RuntimeException('File tidak mengandung teks yang bisa dibaca.');
        $src = city_sources($slug);
        $added = city_ingest_text($slug, $text, $name, 'file:' . $ext);
        $src['sources'][] = ['type' => 'file', 'file' => $safe, 'original' => $name, 'added' => $added, 'at' => date('c')];
        city_save_sources($slug, $src);
        $synced = city_sync_packages($slug);
        header('Location: ?a=citySources&city=' . urlencode($slug) . '&saved=1&added=' . $added . '&synced=' . count($synced)); exit;
    }
    if ($action === 'cityDelete') {
        $slug = city_slugify((string)($_GET['city'] ?? '')); if ($slug === '') throw new RuntimeException('Kota tidak valid.');
        remove_tree(city_dir($slug));
        header('Location: ?a=cities'); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citySourceDelete') {
        $slug = city_slugify((string)($_POST['city'] ?? '')); $idx = (int)($_POST['idx'] ?? -1);
        $src = city_sources($slug);
        if (isset($src['sources'][$idx])) {
            $f = $src['sources'][$idx]['file'] ?? '';
            unset($src['sources'][$idx]); $src['sources'] = array_values($src['sources']);
            city_save_sources($slug, $src);
            if ($f !== '' && is_file(city_raw_dir($slug) . '/' . $f)) unlink(city_raw_dir($slug) . '/' . $f);
        }
        header('Location: ?a=citySources&city=' . urlencode($slug) . '&deleted=1'); exit;
    }
    if ($action === 'syncRuntime') {
        $r = sync_runtime_all();
        $rows = '';
        foreach ($r as $slug => $x) $rows .= '<tr><td><code>' . h($slug) . '</code></td><td>' . $x['copied'] . ' file × ' . $x['targets'] . ' target</td><td>' . h($x['note']) . '</td></tr>';
        layout('Runtime Sync', '<div class="card"><p class="ok">✓ Runtime AI Guide disinkronkan dari master ke semua package & lokasi ter-deploy.</p><p class="muted">Data per-tour (data/, config/) tidak diubah — hanya tampilan & logika runtime.</p><table style="width:100%;border-collapse:collapse"><thead><tr><th align=left>Tour</th><th align=left>Status</th><th align=left>Catatan</th></tr></thead><tbody>' . $rows . '</tbody></table><p><a class="btn btn-primary" href="?">Kembali ke Projects</a></p></div>'); exit;
    }
    /* ---- City Knowledge pages ---- */
    if ($action === 'cities') {
        city_seed_from_tours();
        $cities = all_cities();
        $cards = '';
        if (!$cities) {
            $cards = '<div class="empty"><div class="empty-ic">' . studio_icon('knowledge', 48) . '</div><h3>Belum ada knowledge kota</h3><p>Buat knowledge untuk kota secara keseluruhan (mis. Semarang) — AI guide akan menjawab pertanyaan umum tentang kota berdasarkan data yang Anda masukkan. Knowledge per-kota terpisah, tidak tercampur antar kota.</p></div>';
        } else {
            foreach ($cities as $c) {
                $cards .= '<article class="tour-card"><div class="tour-card-head"><div><h3><a href="?a=city&city=' . h($c['slug']) . '">' . h($c['name']) . '</a></h3><div class="tour-card-loc">' . studio_icon('settings', 12) . h($c['slug']) . '</div></div>'
                    . '<div class="card-menu"><button type="button" class="card-menu-btn" data-menu-btn aria-label="Menu ' . h($c['name']) . '" aria-haspopup="menu" aria-expanded="false">•••</button>'
                    . '<div class="card-menu-pop" role="menu"><a role="menuitem" href="?a=city&city=' . h($c['slug']) . '">Edit knowledge</a><a role="menuitem" href="?a=citySources&city=' . h($c['slug']) . '">Sources</a><a role="menuitem" class="is-danger" data-del-url="?a=cityDelete&city=' . h($c['slug']) . '" data-del-name="' . h($c['name']) . '" href="#">Delete city</a></div></div></div>'
                    . '<div class="tour-card-body"><div>' . studio_badge($c['entries'] > 0 ? 'ready' : 'draft') . '</div><span>' . studio_icon('knowledge', 14) . $c['entries'] . ' entries</span><span>' . studio_icon('sync', 14) . h($c['updated_at']) . '</span></div>'
                    . '<div class="tour-card-foot"><a class="btn btn-primary btn-sm" href="?a=city&city=' . h($c['slug']) . '">' . ($c['entries'] > 0 ? 'Kelola Knowledge' : 'Mulai Isi') . '</a></div></article>';
            }
        }
        $body = '<div class="dash-head"><div><h1>City Knowledge</h1><p>Knowledge umum per kota — dipakai semua tour di lokasi yang sama, terpisah antar kota.</p></div>'
            . '<div class="dash-actions"><a class="btn btn-primary" href="?a=cityNew">+ New City</a></div></div>'
            . '<div class="tour-grid">' . $cards . '</div>' . $error
            . '<script>' . studio_dash_js() . '</script>';
        layout('City Knowledge', $body); exit;
    }
    if ($action === 'cityNew') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = trim((string)($_POST['name'] ?? '')); if ($name === '') throw new RuntimeException('Nama kota wajib diisi.');
            $slug = city_slugify($name);
            $dir = city_dir($slug); if (!is_dir($dir)) mkdir($dir, 0777, true);
            $m = ['name' => $name, 'updatedAt' => date('c')]; write_json($dir . '/meta.json', $m);
            write_json($dir . '/knowledge.json', ['version' => 1, 'city' => $slug, 'entries' => []]);
            write_json($dir . '/sources.json', ['sources' => []]);
            header('Location: ?a=city&city=' . urlencode($slug)); exit;
        }
        $body = $error . '<form class="card" method="post" action="?a=cityNew"><label for="city_name">Nama kota / wilayah</label><input id="city_name" name="name" placeholder="contoh: Semarang" required autocomplete="off"><p class="muted">Slug dibuat otomatis dari nama. Knowledge ini hanya dipakai tour dengan lokasi yang sama.</p><p><button class="btn btn-primary" type="submit">Buat City</button></p></form>';
        layout('New City', $body); exit;
    }
    if ($action === 'city') {
        $slug = city_slugify((string)($_GET['city'] ?? '')); $kb = city_knowledge($slug);
        $name = (string)(read_json(city_dir($slug) . '/meta.json', [])['name'] ?? ucwords(str_replace('-', ' ', $slug)));
        $saved = isset($_GET['saved']); $synced = (int)($_GET['synced'] ?? 0);
        $msg = ''; if ($saved) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Knowledge kota disimpan' . ($synced > 0 ? ' & disinkronkan ke ' . $synced . ' package tour lokasi ini.' : '') . '</p></div>';
        $body = $msg . '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center"><h2 style="margin:0">Knowledge — ' . h($name) . ' (' . count($kb['entries'] ?? []) . ' entries)</h2><div style="display:flex;gap:8px"><a class="btn" href="?a=cities">← Cities</a> <a class="btn" href="?a=citySources&city=' . h($slug) . '">📥 Sources</a></div></div><p class="muted">Judul + aliases untuk pencocokan pertanyaan umum tentang kota. Content dipakai AI untuk jawaban.</p></div>';
        $cityMeta = read_json(city_dir($slug) . '/meta.json', []);
        $body .= '<div class="card" style="border-color:var(--ai-accent)"><h3>⚙️ Instructions (rules AI guide)</h3><p class="muted">Prompting yang dipakai sebagai rules untuk semua chat AI guide kota ini — role, constraints, knowledge base, cara representasi. Dipatok di atas aturan bawaan.</p><textarea name="instructions" rows="10" placeholder="Contoh:&#10;ROLE: Kamu adalah AI pemandu resmi Kota Semarang...&#10;CONSTRAINTS: ...">' . h((string)($cityMeta['instructions'] ?? '')) . '</textarea></div>';
        $body .= '<form method="post" action="?a=citySave">';
        foreach (($kb['entries'] ?? []) as $idx => $e) {
            $body .= '<div class="card"><div style="display:flex;justify-content:space-between"><strong>' . h($e['title'] ?? $e['id']) . '</strong> <label style="font-size:12px"><input type="checkbox" name="del_' . $idx . '" value="1"> Hapus</label></div>';
            $body .= '<label style="font-size:12px;color:var(--pill-text)">ID</label><input name="entry_' . $idx . '_id" value="' . h($e['id']) . '">';
            $body .= '<label style="font-size:12px;color:var(--pill-text)">Title</label><input name="entry_' . $idx . '_title" value="' . h($e['title'] ?? '') . '">';
            $body .= '<label style="font-size:12px;color:var(--pill-text)">Aliases (pisah koma)</label><input name="entry_' . $idx . '_aliases" value="' . h(implode(', ', $e['aliases'] ?? [])) . '">';
            $body .= '<label style="font-size:12px;color:var(--pill-text)">Content</label><textarea name="entry_' . $idx . '_content" rows="4">' . h($e['content'] ?? '') . '</textarea></div>';
        }
        $body .= '<input type="hidden" name="entry_count" value="' . count($kb['entries'] ?? []) . '"><input type="hidden" name="city" value="' . h($slug) . '"><input type="hidden" name="name" value="' . h($name) . '">';
        $body .= '<div class="card" style="position:sticky;bottom:0;background:var(--surface-2);z-index:5;display:flex;gap:8px"><button type="submit" name="add_entry" value="1" class="btn btn-ghost">+ Tambah Entry</button> <button type="submit" class="btn btn-primary">💾 Simpan & Sync</button></div></form>';
        layout('City Knowledge — ' . $name, $body); exit;
    }
    if ($action === 'citySources') {
        $slug = city_slugify((string)($_GET['city'] ?? '')); $src = city_sources($slug);
        $name = (string)(read_json(city_dir($slug) . '/meta.json', [])['name'] ?? ucwords(str_replace('-', ' ', $slug)));
        $msg = ''; if (isset($_GET['saved'])) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Source diproses. ' . (int)($_GET['added'] ?? 0) . ' knowledge entry ditambahkan, disinkronkan ke ' . (int)($_GET['synced'] ?? 0) . ' package tour.</p></div>';
        if (isset($_GET['deleted'])) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Source dihapus.</p></div>';
        if (isset($_GET['error'])) $msg .= '<div class="card bad"><p>' . h((string)$_GET['error']) . '</p></div>';
        $body = $msg . '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center"><h2 style="margin:0">Sources — ' . h($name) . '</h2><a class="btn" href="?a=city&city=' . h($slug) . '">← Edit Knowledge</a></div><p class="muted">Tambah sumber pengetahuan kota: fetch dari URL website, atau upload file (PDF, Word, txt, dll). Teks dipotong otomatis menjadi knowledge entries.</p></div>';
        $body .= '<form class="card" method="post" action="?a=citySourceUrl" style="border:1px dashed var(--ai-accent)"><h3>🌐 Fetch dari Website</h3><div style="display:flex;gap:8px"><input type="hidden" name="city" value="' . h($slug) . '"><input name="url" placeholder="https://contoh.id/halaman" required style="flex:1"><button class="btn btn-primary" type="submit">Fetch & Import</button></div></form>';
        $body .= '<form class="card" method="post" action="?a=citySourceFile" enctype="multipart/form-data" style="border:1px dashed var(--ai-accent)"><h3>📄 Upload File</h3><p class="muted">Didukung: .txt .md .csv .html .docx .doc .pdf</p><div style="display:flex;gap:8px;align-items:center"><input type="hidden" name="city" value="' . h($slug) . '"><input type="file" name="file" required accept=".txt,.md,.csv,.html,.htm,.docx,.doc,.pdf"><button class="btn btn-primary" type="submit">Upload & Import</button></div></form>';
        $body .= '<div class="card"><h3>Riwayat Sources</h3>';
        if (empty($src['sources'])) $body .= '<p class="muted">Belum ada source.</p>';
        foreach (($src['sources'] ?? []) as $idx => $s) {
            $label = $s['type'] === 'url' ? '🌐 ' . h($s['url']) : '📄 ' . h($s['original'] ?? $s['file']);
            $body .= '<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)"><span>' . $label . ' <span class="muted" style="font-size:12px">(+' . (int)$s['added'] . ' entries, ' . h($s['at']) . ')</span></span>'
                . '<form method="post" action="?a=citySourceDelete" style="display:inline"><input type="hidden" name="city" value="' . h($slug) . '"><input type="hidden" name="idx" value="' . $idx . '"><button class="btn btn-ghost btn-sm" type="submit" onclick="return confirm(\'Hapus source ini?\')">Hapus</button></form></div>';
        }
        $body .= '</div>';
        layout('Sources — ' . $name, $body); exit;
    }
} catch (Throwable $e) { $error = '<div class="card bad">' . h($e->getMessage()) . '</div>'; }

if ($action === 'new' || $action === 'edit') { $t = $action === 'edit' ? get_tour((int)$_GET['id']) : ['id'=>0,'name'=>'','slug'=>'','location'=>'Semarang','category'=>'','export_path'=>'']; $catOpts = '<option value="">— pilih kategori —</option>'; foreach (['Pariwisata','Agama','Pendidikan','Kesehatan','Investasi','Mice','Restoran','Infrastruktur','Pusat Perbelanjaan'] as $c) $catOpts .= '<option value="' . h($c) . '"' . ($t['category'] === $c ? ' selected' : '') . '>' . h($c) . '</option>'; layout($action === 'edit' ? 'Edit tour' : 'Tambah tour', $error . '<form class="card" method="post" action="?a=save"><input type="hidden" name="id" value="' . (int)$t['id'] . '"><label for="tour_name">Nama</label><input id="tour_name" name="name" value="' . h($t['name']) . '" required autocomplete="off"><label for="tour_slug">Slug</label><input id="tour_slug" name="slug" value="' . h($t['slug']) . '" autocomplete="off"><label for="tour_location">Lokasi</label><input id="tour_location" name="location" value="' . h($t['location']) . '" autocomplete="off"><label for="tour_category">Kategori</label><select id="tour_category" name="category">' . $catOpts . '</select><label for="tour_export">Export path</label><input id="tour_export" name="export_path" value="' . h($t['export_path']) . '" placeholder="C:/xampp/htdocs/semarang/sandibuana" autocomplete="off"><p><button class="btn btn-primary" type="submit">Simpan</button></p></form>'); exit; }
    if ($action === 'view') {
        $t = get_tour((int)$_GET['id']); if (!$t) { layout('Tour hilang', '<p>Tour tidak ditemukan.</p>'); exit; }
        $s = studio_tour_stats($t);
        $parsed = read_json(tour_dir($t['slug']) . '/parsed.json');
        $errs = $s['issues'];
        $hasScenes = $parsed && !empty($parsed['scenes']);
        $body = $error;

        // Header
        $deployBtn = $s['deployed'] || ($s['hasPackage'] && $s['exportSet']) ? '<form method="post" action="?a=deploy&id=' . (int)$t['id'] . '" onsubmit="return confirm(\'Replace installed AI Guide and update index.html? Backup stored locally.\')" style="display:inline-flex"><button class="btn btn-success">' . ($s['deployed'] ? 'Re-deploy' : 'Deploy') . '</button></form>' : '';
        $body .= '<div class="ov-head"><div><h1>' . h($t['name']) . '</h1><div class="ov-sub">' . h($t['location']) . ($t['export_path'] !== '' ? ' · <code>' . h($t['export_path']) . '</code>' : '') . '</div></div>'
            . '<div class="ov-actions">'
            . '<a class="btn btn-ghost" href="?a=edit&id=' . (int)$t['id'] . '">Edit</a>'
            . '<a class="btn btn-ghost" href="?a=import&id=' . (int)$t['id'] . '">Import pano/skin</a>'
            . ($hasScenes ? '<a class="btn btn-ghost" href="?a=scenes&id=' . (int)$t['id'] . '">Scene Editor (' . count($parsed['scenes']) . ')</a>' : '')
            . '<a class="btn btn-primary" href="?a=generate&id=' . (int)$t['id'] . '">Generate package</a>'
            . $deployBtn
            . '</div></div>';

        // Readiness + Issues + Setup Checklist
        $issueItems = '';
        foreach (array_slice($s['issues'], 0, 5) as $iss) {
            $issueItems .= '<div class="issue-item"><span class="issue-ic">' . studio_icon('settings', 16) . '</span><div class="issue-tx"><b>' . h($iss) . '</b></div><a class="btn btn-sm btn-ghost issue-act" href="?a=scenes&id=' . (int)$t['id'] . '">Fix</a></div>';
        }
        $issuesCard = '<div class="card"><h3>Needs Attention</h3>'
            . ($issueItems !== '' ? $issueItems : '<div class="empty" style="padding:var(--space-4)"><p style="margin:0">Tidak ada masalah terdeteksi.</p></div>')
            . '</div>';

        $checkItems = '';
        foreach ($s['steps'] as $st) {
            $ic = $st['done'] ? '<span class="check-ic check-ok">✓</span>' : '<span class="check-ic check-nd">•</span>';
            $checkItems .= '<li>' . $ic . '<div class="check-tx"><b>' . h($st['label']) . '</b><small>' . h($st['desc']) . '</small></div>'
                . ($st['done'] ? '' : '<a class="btn btn-sm btn-ghost check-act" href="' . h($st['act']) . '">' . h($st['actLabel']) . '</a>')
                . '</li>';
        }
        $checklist = '<div class="card"><h3>Setup Checklist</h3><ol class="checklist">' . $checkItems . '</ol></div>';

        $rightCol = '<div class="card"><h3>AI Guide Readiness</h3><div class="ready-ring"><div class="ready-ring-num">' . $s['ready'] . '%</div><div>' . studio_badge($s['status']) . '<div class="metric-sub">' . $s['doneSteps'] . '/' . $s['totalSteps'] . ' steps selesai</div></div></div>' . studio_readiness($s['ready']) . '</div>';

        $body .= '<div class="ov-grid"><div>' . $rightCol . $issuesCard . $checklist . '</div>';

        // Main column
        $mainCol = '';

        // Coverage
        $covItems = '';
        $coverage = [
            ['label' => 'Scenes dengan identitas', 'done' => $s['siScenes'], 'total' => $s['scenes']],
            ['label' => 'Knowledge entries', 'done' => $s['knowledgeDone'], 'total' => $s['knowledgeTotal']],
            ['label' => 'Curated intents', 'done' => $s['intents'], 'total' => max($s['intents'], 1)],
            ['label' => 'Recommended questions', 'done' => $s['recTotal'], 'total' => max($s['recTotal'], 1)],
            ['label' => 'Visitor tips', 'done' => $s['tipsTotal'], 'total' => max($s['tipsTotal'], 1)],
            ['label' => 'Nearby places', 'done' => $s['nearbyTotal'], 'total' => max($s['nearbyTotal'], 1)],
        ];
        foreach ($coverage as $c) {
            $pct = $c['total'] > 0 ? (int)round(min(100, $c['done'] / $c['total'] * 100)) : 0;
            $covItems .= '<div class="coverage-item"><div class="coverage-tx">' . h($c['label']) . ' <b>' . $c['done'] . '/' . $c['total'] . '</b></div><div class="coverage-bar">' . studio_readiness($pct) . '</div></div>';
        }
        $mainCol .= '<div class="card"><h3>Content Coverage</h3>' . $covItems . '</div>';

        // Tour Scenes preview
        if ($hasScenes) {
            $chips = '';
            $sceneSample = array_slice($parsed['scenes'], 0, 24);
            foreach ($sceneSample as $sc) {
                $nm = $sc['displayName'] ?? $sc['name'] ?? $sc['id'];
                $isPlaceholder = $nm === $sc['id'] || preg_match('/^node\d+$/i', $nm);
                $chips .= '<span class="scene-chip' . ($isPlaceholder ? ' style="border-color:var(--warning);color:var(--warning)"' : '') . '">' . h($nm) . '</span>';
            }
            $more = count($parsed['scenes']) > 24 ? '<div class="metric-sub" style="margin-top:var(--space-2)">+' . (count($parsed['scenes']) - 24) . ' scene lainnya…</div>' : '';
            $mainCol .= '<div class="card"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-3)"><h3 style="margin:0">Tour Scenes</h3><span class="metric-sub">' . $s['scenes'] . ' scenes · ' . $s['hotspots'] . ' hotspots</span></div><div class="scene-chips">' . $chips . '</div>' . $more . '</div>';
        }

        // Recent Activity — data nyata dari file system & DB timestamps
        $activity = '';
        $acts = [];
        if (is_dir($dir = tour_dir($t['slug']))) {
            foreach (glob($dir . '/*.json') as $f) $acts[] = ['t' => filemtime($f), 'ic' => 'knowledge', 'tx' => 'Data diperbarui', 'file' => basename($f)];
        }
        if ($s['hasPackage']) $acts[] = ['t' => filemtime($dir . '/package/govirtual-ai'), 'ic' => 'deployment', 'tx' => 'Package generate', 'file' => 'package/govirtual-ai'];
        if ($s['deployed']) $acts[] = ['t' => filemtime($t['export_path'] . '/govirtual-ai'), 'ic' => 'deployment', 'tx' => 'Deployed ke export', 'file' => $t['export_path']];
        $acts[] = ['t' => strtotime($t['updated_at'] ?? 'now') ?: time(), 'ic' => 'overview', 'tx' => 'Project dibuat/diupdate', 'file' => 'DB tours'];
        usort($acts, fn($a, $b) => $b['t'] <=> $a['t']);
        foreach (array_slice($acts, 0, 8) as $a) {
            $activity .= '<div class="activity-item"><span class="activity-ic">' . studio_icon($a['ic'], 16) . '</span><div class="activity-tx"><b>' . h($a['tx']) . '</b><br>' . h($a['file']) . '<div class="activity-time">' . date('d M Y H:i', (int)$a['t']) . '</div></div></div>';
        }
        $mainCol .= '<div class="card"><h3>Recent Activity</h3>' . $activity . '</div>';

        // Save As card — solusi 100 lokasi tanpa copy tiles besar
        $mainCol .= '<div class="card" id="saveas" style="border-color:var(--ai-accent)"><h3>Save As — Duplikat untuk Tim / Lokasi Baru</h3><p class="muted">Duplikat JSON kurasi (beberapa KB) tanpa copy tiles (ratusan MB). Untuk lokasi baru siapkan folder export minimal: <code>pano.xml + skin.js + preview.jpg + images/*.webp (thumbs)</code>.</p>';
        $mainCol .= '<form method="post" action="?a=saveAs&id=' . (int)$t['id'] . '" style="display:grid;gap:8px"><div class="form-2col"><div><label style="font-size:12px;color:var(--text-muted)">Nama Baru</label><input name="new_name" placeholder="Contoh: Kota Lama" required></div><div><label style="font-size:12px;color:var(--text-muted)">Slug Baru (auto)</label><input name="new_slug" placeholder="kota-lama"></div></div><div class="form-2col"><div><label style="font-size:12px;color:var(--text-muted)">Lokasi</label><input name="new_location" value="' . h($t['location']) . '"></div><div><label style="font-size:12px;color:var(--text-muted)">Export Path Baru (opsional)</label><input name="new_export" placeholder="C:/xampp/htdocs/semarang/kota-lama"></div></div><label style="font-size:12px"><input type=checkbox name="copy_parsed" value="1"> Juga copy parsed pano (jika lokasi sama; lokasi baru biarkan kosong, Import ulang pano.xml+skin.js)</label><p><button class="btn btn-ai">Duplikat Sekarang</button> <span style="font-size:12px;color:var(--text-muted)">Copy: curated, knowledge, scene-identity, visitor-tips, nearby, recommended-questions (bukan tiles).</span></p></form></div>';

        $body .= $mainCol . '</div>';

        // Content Center (semua section + raw JSON editors) — fungsi lama dipertahankan
        $body .= '<div class="card" id="content" style="margin-top:var(--space-5)"><div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--space-3)"><h3 style="margin:0">Content Center</h3><form method="post" action="?a=seedContent&id=' . (int)$t['id'] . '"><button class="btn btn-ghost btn-sm">Siapkan semua section (seed)</button></form></div><p class="muted">Edit per section tanpa coding JSON. Klik editor, ubah, Simpan / Generate / Deploy.</p></div>';
        $dir = tour_dir($t['slug']);
        $map = ['curated-answers'=>['label'=>'Curated Questions','icon'=>'✅','desc'=>'Pertanyaan prioritas (intents)','link'=>'?a=curated&id='.(int)$t['id'],'count'=>count((read_json($dir.'/curated-answers.json',[])['intents']??[])).' intents'],'knowledge'=>['label'=>'Knowledge','icon'=>'📚','desc'=>'Judul + konten jawaban AI','link'=>'?a=knowledge&id='.(int)$t['id'],'count'=>count((read_json($dir.'/knowledge.json',[])['entries']??[])).' entries'],'scene-identity'=>['label'=>'Scene Identity','icon'=>'🎨','desc'=>'Nama panorama + deskripsi (sudah ada Scene Editor)','link'=>'?a=scenes&id='.(int)$t['id'],'count'=>count((read_json($dir.'/scene-identity.json',[])['scenes']??[])).' scenes'],'visitor-tips'=>['label'=>'Visitor Tips','icon'=>'💡','desc'=>'Tips berkunjung','link'=>'?a=tips&id='.(int)$t['id'],'count'=>count((read_json($dir.'/visitor-tips.json',[])['topics']??[])).' topics'],'nearby'=>['label'=>'Nearby','icon'=>'📍','desc'=>'Tempat terdekat','link'=>'?a=nearby&id='.(int)$t['id'],'count'=>count((read_json($dir.'/nearby.json',[])['items']??[])).' items'],'recommended-questions'=>['label'=>'Recommended Qs','icon'=>'💬','desc'=>'State1 (Discovery) & State2 (Workspace)','link'=>'?a=prompts&id='.(int)$t['id'],'count'=>'State1/State2'],'flow'=>['label'=>'Navigation Flow','icon'=>'🧭','desc'=>'Urutan navigasi panorama','link'=>'?a=flow&id='.(int)$t['id'],'count'=>count((read_json($dir.'/flow.json',[])['order']??[])).' steps']];
        foreach (content_files() as $name => $label) {
            $info=$map[$name]??['label'=>$label,'icon'=>'📄','desc'=>'','link'=>'?a=view&id='.(int)$t['id'],'count'=>''];
            $content = json_encode(read_json($dir . '/' . $name . '.json', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $body .= '<div class=card style="display:flex;justify-content:space-between;align-items:center;gap:12px"><div><div style="font-weight:600">'.$info['icon'].' '.h($info['label']).' <span style="font-size:12px;color:var(--text-muted)">('.$info['count'].')</span></div><div style="font-size:12px;color:var(--text-muted)">'.h($info['desc']).'<br><code>storage/tours/'.h($t['slug']).'/'.$name.'.json</code></div></div><div style="display:flex;gap:8px"><a class=btn href="'.$info['link'].'">✏️ Edit</a></div></div>';
            $body .= '<details class=card style="margin-top:-8px"><summary style="cursor:pointer;font-size:12px;color:var(--text-muted)">Advanced: Edit JSON mentah</summary><form method=post action="?a=saveContent&id='.(int)$t['id'].'&name='.h($name).'"><textarea name=content rows=8 class="mono" style="font-size:12px">'.h($content?:'{}').'</textarea><p><button type="submit" class="btn btn-primary btn-sm">Simpan JSON</button></p></form></details>';
        }
        layout('Project Overview', $body); exit; }

/* ---- Project Dashboard (tour list) ---- */
$tours = all_tours();
$total = count($tours);
$readyCount = 0; $needsCount = 0; $deployedCount = 0; $sceneTotal = 0; $knowledgeTotal = 0;
$statsByTour = [];
foreach ($tours as $t) {
    $s = studio_tour_stats($t);
    $statsByTour[$t['id']] = $s;
    $sceneTotal += $s['scenes'];
    $knowledgeTotal += $s['knowledgeTotal'];
    if ($s['status'] === 'ready') $readyCount++;
    elseif ($s['status'] === 'needs') $needsCount++;
    elseif ($s['status'] === 'deployed') $deployedCount++;
}
$draftCount = $total - $readyCount - $needsCount - $deployedCount;
$metricReady = $readyCount + $deployedCount;

$filters = ['all' => 'All', 'draft' => 'Draft', 'needs' => 'Needs Attention', 'ready' => 'Ready', 'deployed' => 'Deployed'];
$statusCounts = ['draft' => $draftCount, 'needs' => $needsCount, 'ready' => $readyCount, 'deployed' => $deployedCount];
$filterHtml = '';
foreach ($filters as $k => $v) $filterHtml .= '<button type="button" class="filter-chip' . ($k === 'all' ? ' is-on' : '') . '" data-filter="' . $k . '" data-status-label="' . h($v) . '">' . h($v) . ' <span class="count">(' . ($k === 'all' ? $total : $statusCounts[$k] ?? 0) . ')</span></button>';
// Location filter chips (multi-city isolation)
$locNames = [];
foreach ($tours as $t) $locNames[trim((string)$t['location'])] = true;
$locFilter = '<span class="filter-sep">Lokasi</span><button type="button" class="filter-chip is-on" data-locfilter="__all__">Semua Kota</button>';
foreach (array_keys($locNames) as $loc) $locFilter .= '<button type="button" class="filter-chip" data-locfilter="' . h($loc) . '">' . h($loc) . '</button>';
// Category filter chips
$catNames = [];
foreach ($tours as $t) { $c = trim((string)$t['category']); if ($c !== '') $catNames[$c] = true; }
$catFilter = '<span class="filter-sep">Kategori</span><button type="button" class="filter-chip is-on" data-catfilter="__all__">Semua</button>';
foreach (array_keys($catNames) as $cat) $catFilter .= '<button type="button" class="filter-chip" data-catfilter="' . h($cat) . '">' . h($cat) . '</button>';

$cards = '';
if ($total === 0) {
    $cards = '<div class="empty"><div class="empty-ic">' . studio_icon('overview', 48) . '</div><h3>Belum ada project</h3><p>Buat project pertama: impor panorama tour, kurasi knowledge AI, lalu deploy ke website Anda.</p><a class="btn btn-primary" href="?a=new">Buat project baru</a></div>';
} else {
    foreach ($tours as $t) {
        $s = $statsByTour[$t['id']];
        $name = h($t['name']);
        $loc = h($t['location']);
        $badge = studio_badge($s['status']);
        $sceneMeta = $s['hasParsed'] ? $s['scenes'] . ' scenes' : 'Belum import';
        $intentsMeta = $s['intents'] > 0 ? $s['intents'] . ' intents' : '';
        $knowMeta = $s['knowledgeTotal'] > 0 ? $s['knowledgeTotal'] . ' knowledge' : '';
        $meta = '<span>' . studio_icon('scenes', 14) . $sceneMeta . '</span>';
        if ($intentsMeta) $meta .= '<span>' . studio_icon('questions', 14) . $intentsMeta . '</span>';
        if ($knowMeta) $meta .= '<span>' . studio_icon('knowledge', 14) . $knowMeta . '</span>';
        $continue = '?a=scenes&id=' . (int)$t['id'];
        if (!$s['hasParsed']) $continue = '?a=import&id=' . (int)$t['id'];
        elseif ($s['status'] === 'needs') $continue = '?a=knowledge&id=' . (int)$t['id'];
        elseif ($s['status'] === 'ready') $continue = '?a=deploy&id=' . (int)$t['id'];
        $cards .= '<article class="tour-card" data-status="' . $s['status'] . '" data-name="' . strtolower($name) . '" data-loc="' . strtolower($t['location']) . '" data-cat="' . strtolower(h($t['category'])) . '">'
            . '<div class="tour-card-head"><div><h3><a href="?a=view&id=' . (int)$t['id'] . '">' . $name . '</a></h3><div class="tour-card-loc">' . studio_icon('settings', 12) . $loc . ($t['category'] !== '' ? '<span class="tour-card-cat">' . h($t['category']) . '</span>' : '') . '</div></div>'
            . '<div class="card-menu"><button type="button" class="card-menu-btn" data-menu-btn aria-label="Menu ' . $name . '" aria-haspopup="menu" aria-expanded="false">•••</button>'
            . '<div class="card-menu-pop" role="menu"><a role="menuitem" href="?a=view&id=' . (int)$t['id'] . '">Open project</a><a role="menuitem" href="?a=edit&id=' . (int)$t['id'] . '">Edit settings</a><a role="menuitem" href="?a=import&id=' . (int)$t['id'] . '">Import pano/skin</a><a role="menuitem" href="?a=saveAs&id=' . (int)$t['id'] . '">Duplicate (Save As)</a><a role="menuitem" href="?a=generate&id=' . (int)$t['id'] . '">Generate package</a><button type="button" role="menuitem" class="is-danger" data-del-url="?a=delete&id=' . (int)$t['id'] . '" data-del-name="' . $name . '">Delete project</button></div></div></div>'
            . '<div class="tour-card-body"><div>' . $badge . '</div>' . $meta . studio_readiness($s['ready']) . '</div>'
            . '<div class="tour-card-foot"><a class="btn btn-primary btn-sm" href="' . $continue . '">' . ($s['status'] === 'ready' ? 'Deploy' : 'Continue Setup') . '</a><span class="readiness-pct" style="color:var(--text-muted);font-weight:500;min-width:auto">' . $s['doneSteps'] . '/' . $s['totalSteps'] . ' steps</span></div>'
            . '</article>';
    }
}

$body = '<div class="dash-head"><div><h1>Projects</h1><p>Kelola, kurasi, dan deploy AI Guide untuk setiap lokasi.</p></div>'
    . '<div class="dash-actions"><a class="btn btn-primary" href="?a=new">+ New Project</a></div></div>'
    . '<div class="metric-grid">'
    . studio_metric('Total Projects', (string)$total, 'semua tour di registry')
    . studio_metric('Ready to Deploy', (string)$metricReady, 'status ready / deployed', 'metric-ready')
    . studio_metric('Needs Attention', (string)$needsCount, 'kurasi belum lengkap', $needsCount > 0 ? 'metric-warn' : '')
    . studio_metric('Total Scenes', (string)$sceneTotal, 'panorama ter-parsing')
    . studio_metric('Knowledge Entries', (string)$knowledgeTotal, 'konten jawaban AI')
    . '</div>'
    . '<div class="toolbar"><input type="search" id="projSearch" placeholder="Cari project…" aria-label="Cari project"><div class="filters" id="projFilters">' . $filterHtml . $locFilter . $catFilter . '</div></div>'
    . '<div class="tour-grid">' . $cards . '</div>'
    . $error
    . '<script>' . studio_dash_js() . '</script>';
layout('Projects', $body);
