<?php

declare(strict_types=1);

const APP_ROOT = __DIR__;
const DB_FILE = APP_ROOT . '/storage/app.sqlite';
const TOURS_DIR = APP_ROOT . '/storage/tours';
define('SOURCE_AI', getenv('GUIDE_RUNTIME_SOURCE') ?: 'C:/xampp/htdocs/govirtual-ai/govirtual-ai'); // lokasi runtime master; bisa diganti lewat env
require_once __DIR__ . '/studio.php';
require_once __DIR__ . '/qa_import.php';
require_once __DIR__ . '/scene_import.php';
require_once __DIR__ . '/app/schema.php';
require_once __DIR__ . '/app/cities.php';
require_once __DIR__ . '/app/tour_form.php';
require_once __DIR__ . '/app/stats.php';
require_once __DIR__ . '/app/dashboard.php';
require_once __DIR__ . '/app/runtime.php';
require_once __DIR__ . '/app/ux.php';
require_once __DIR__ . '/app/shell_v2.php';
require_once __DIR__ . '/app/i18n.php';
require_once __DIR__ . '/app/wizard.php';
require_once __DIR__ . '/app/city_view.php';
require_once __DIR__ . '/app/city_sources.php';
require_once __DIR__ . '/app/scene_view.php';
require_once __DIR__ . '/app/tour_summary.php';
require_once __DIR__ . '/app/health.php';
require_once __DIR__ . '/app/health_page.php';
require_once __DIR__ . '/app/keys_page.php';
require_once __DIR__ . '/app/playground.php';
require_once __DIR__ . '/app/jobs.php';
require_once __DIR__ . '/app/users.php';
require_once __DIR__ . '/app/theme.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function slugify(string $s): string { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? ''; return trim($s, '-') ?: 'tour-' . date('YmdHis'); }
function ensure_dirs(): void { foreach ([APP_ROOT . '/storage', TOURS_DIR] as $d) if (!is_dir($d)) mkdir($d, 0777, true); }
function cities_dir(): string { return APP_ROOT . '/storage/cities'; }
function city_dir(string $slug): string { return cities_dir() . '/' . $slug; }
function city_slugify(string $s): string { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? ''; return trim($s, '-') ?: 'kota-' . date('YmdHis'); }
function all_cities(bool $scoped = true): array {
    cities_reconcile_folders();
    $rows = db()->query('SELECT c.*, (SELECT COUNT(*) FROM tours t WHERE t.city_id=c.id) AS tour_count FROM cities c ORDER BY c.name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
    $cities = [];
    $scope = $scoped ? user_scope() : null;
    foreach ($rows as $r) {
        if ($scope !== null && !in_array((int)$r['id'], $scope, true)) continue;
        $meta = read_json(city_dir($r['slug']) . '/meta.json', []);
        $cities[] = ['id' => (int)$r['id'], 'slug' => $r['slug'], 'name' => $r['name'], 'province' => (string)$r['province'], 'tour_count' => (int)$r['tour_count'],
            'entries' => count(read_json(city_dir($r['slug']) . '/knowledge.json', [])['entries'] ?? []), 'updated_at' => (string)($meta['updatedAt'] ?? $r['updated_at'])];
    }
    return $cities;
}
function city_knowledge(string $slug): array { return read_json(city_dir($slug) . '/knowledge.json', ['version' => 1, 'city' => $slug, 'entries' => []]); }
/**
 * Config webview per kota (dari meta.json → "webview"). Default Semarang hanya berlaku untuk kota "semarang";
 * kota lain mulai netral (tanpa katalog/halaman milik Semarang) dan diisi lewat Pengaturan Kota.
 * Dipakai build_files → config/tour.json.
 */
function city_webview_config(string $slug): array {
    $meta = read_json(city_dir($slug) . '/meta.json', []);
    $wv = is_array($meta['webview'] ?? null) ? $meta['webview'] : [];
    if ($slug === 'semarang') {
        $cfg = [
            'catalogPath' => 'data/semarang-destinations.json',
            'catalogSrc' => SOURCE_AI . '/data/semarang-destinations.json',
            'catalogLabel' => '',
            'categoryPage' => 'https://semarang.govirtual.id/webview/kategori.php?lang=id',
            'objectInfoBase' => 'https://webview-govirtual.id/semarang-v2/assets/images/objekinfo/lawang_sewu',
            'objectInfoPage' => 'https://semarang.govirtual.id/webview/objinfo2.php',
            'objectInfoDetailParam' => 'info',
            'objectInfoExtra' => '&vd=1&tp=m',
            'langMap' => ['id' => 'id', 'en' => 'en', 'sa' => 'sa', 'zh' => 'zh'],
        ];
    } else {
        $cfg = [
            'catalogPath' => '', 'catalogSrc' => '', 'catalogLabel' => '', 'categoryPage' => '',
            'objectInfoBase' => '', 'objectInfoPage' => '', 'objectInfoDetailParam' => 'info', 'objectInfoExtra' => '',
            'langMap' => ['id' => 'id', 'en' => 'en'],
        ];
    }
    foreach ($cfg as $k => $v) {
        if (isset($wv[$k]) && $wv[$k] !== '') {
            $cfg[$k] = is_array($wv[$k]) ? $wv[$k] : (string)$wv[$k];
        }
    }
    // catalogSrc mengikuti catalogPath bila di-set per kota dan sumbernya belum diisi eksplisit.
    if ($cfg['catalogPath'] !== '' && ($cfg['catalogSrc'] === '' || ($cfg['catalogPath'] !== 'data/semarang-destinations.json' && str_contains($cfg['catalogSrc'], 'semarang-destinations.json')))) {
        $cfg['catalogSrc'] = SOURCE_AI . '/data/' . basename($cfg['catalogPath']);
    }
    return $cfg;
}
function city_sources(string $slug): array { return read_json(city_dir($slug) . '/sources.json', ['sources' => []]); }
function city_save_knowledge(string $slug, array $data): void { $data['updatedAt'] = date('c'); write_json(city_dir($slug) . '/knowledge.json', $data); $m = read_json(city_dir($slug) . '/meta.json', []); $m['updatedAt'] = date('c'); $m['name'] = $m['name'] ?? $slug; write_json(city_dir($slug) . '/meta.json', $m); }
function city_save_sources(string $slug, array $data): void { write_json(city_dir($slug) . '/sources.json', $data); }
function city_raw_dir(string $slug): string { $d = city_dir($slug) . '/raw'; if (!is_dir($d)) mkdir($d, 0777, true); return $d; }
/**
 * Deteksi teks "chrome" hasil scraping (menu, footer, widget, penerjemah) yang
 * tidak layak menjadi knowledge. Mencegah jawaban sampah seperti daftar menu.
 */
function city_junk_text(string $title, string $content): bool {
    $t = $title . ' ' . $content;
    $pat = '/(toggle navigation|search results for|displaying \{result|beri rating terjemahan|google terjemahan|translate »|'
         . 'daftar informasi berkala|daftar informasi setiap saat|daftar informasi serta merta|daftar informasi dikecualikan|'
         . 'profil ppid|ppid pelaksana|all rights reserved|peta tata ruang|produk hukum|pengadaan barang dan jasa|'
         . 'this mode (enables|adjusts|provides|helps|configures)|visual impairments|cognitive impairments|'
         . 'epilepsy|adhd|screen-readers|pantau cctv|pantau sampah|pantau banjir|peta bencana|link terkait|'
         . '\d+\s*°c|sumber data cuaca|kebijakan privasi|smart city)/i';
    if (preg_match($pat, $t)) return true;
    // Teks tanpa pemisah (menu terjalin) atau huruf jauh lebih banyak dari spasi.
    $len = max(1, strlen($content));
    $spaces = substr_count($content, ' ');
    if ($len > 120 && ($spaces / $len) < 0.04) return true;
    return false;
}

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
        if (city_junk_text($title, $body)) continue; // buang menu/footer/widget
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
        if (tour_city_slug($t) !== $citySlug) continue;
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
/** Dulu membuat folder kota sebagai efek samping membuka halaman. Sekarang kota berasal dari tabel `cities` (db_migrate); folder dibuat saat ditulis. */
function city_seed_from_tours(): void {}
function db(): PDO {
    static $cached = null; // satu koneksi + satu kali migrasi per request
    if ($cached instanceof PDO) return $cached;
    ensure_dirs();
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tours (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE NOT NULL, name TEXT NOT NULL, location TEXT NOT NULL DEFAULT "Semarang", category TEXT NOT NULL DEFAULT "", export_path TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "draft", created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    // migration: category column (DB lama tanpa kolom)
    $cols = array_column($pdo->query('PRAGMA table_info(tours)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('category', $cols, true)) $pdo->exec('ALTER TABLE tours ADD COLUMN category TEXT NOT NULL DEFAULT ""');
    $pdo->exec('CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, provider TEXT NOT NULL, label TEXT NOT NULL DEFAULT "", api_key TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, priority INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (skey TEXT PRIMARY KEY, svalue TEXT NOT NULL DEFAULT "", updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT "qa", created_at TEXT NOT NULL)');
    $ucols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('role', $ucols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT "qa"');
    // Tidak ada akun bawaan lagi. Buat admin pertama lewat CLI: php tools/user-admin.php create <username> admin
    $cached = $pdo; // set sebelum migrasi agar helper yang memanggil db() tidak rekursif
    db_migrate($pdo);
    return $pdo;
}
/** Password bawaan lama. Dipakai hanya untuk mendeteksi akun yang harus segera ganti password. */
function legacy_default_passwords(): array { return ['admin123', 'admin12345', 'qa12345']; }
function user_uses_default_password(array $row): bool {
    foreach (legacy_default_passwords() as $p) if (password_verify($p, (string)$row['password_hash'])) return true;
    return false;
}
function password_policy_error(string $pw): ?string {
    if (strlen($pw) < 10) return 'Password minimal 10 karakter.';
    if (in_array($pw, legacy_default_passwords(), true)) return 'Password ini terlalu umum. Pilih yang lain.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Password harus mengandung huruf dan angka.';
    return null;
}
function user_count(): int { return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn(); }
function user_find(string $username): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE username=?');
    $s->execute([$username]);
    $u = $s->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}
function current_user(): ?array {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $u = $_SESSION['user'] ?? null;
    return is_array($u) ? $u : null;
}
function require_login(): ?array {
    if (PHP_SAPI === 'cli') return null;
    $u = current_user();
    if (!$u) {
        header('Location: ?a=login');
        exit;
    }
    return $u;
}
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}
function csrf_valid(?string $token): bool {
    return is_string($token) && hash_equals(csrf_token(), $token);
}
/**
 * Halaman konfirmasi untuk aksi yang mengubah data. GET hanya menampilkan ini; eksekusi terjadi lewat POST.
 * $typeToConfirm: jika diisi, pengguna harus mengetik teks tersebut (untuk aksi destruktif).
 */
function confirm_page(string $title, string $descHtml, string $postUrl, string $btnLabel, string $backUrl, bool $danger = false, string $typeToConfirm = '', array $hidden = []): void {
    $field = '';
    foreach ($hidden as $hk => $hv) $field .= '<input type="hidden" name="' . h((string)$hk) . '" value="' . h((string)$hv) . '">';
    $field .= $typeToConfirm !== ''
        ? '<label for="confirm_name">Ketik <b>' . h($typeToConfirm) . '</b> untuk melanjutkan</label><input id="confirm_name" name="confirm_name" required autocomplete="off" placeholder="' . h($typeToConfirm) . '">'
        : '';
    layout($title, '<div class="card" style="max-width:640px"><h2>' . h($title) . '</h2><div style="margin:12px 0">' . $descHtml . '</div>'
        . '<form method="post" action="' . h($postUrl) . '" style="display:grid;gap:10px">' . $field
        . '<p style="display:flex;gap:8px;margin:0"><button class="btn ' . ($danger ? 'btn-danger' : 'btn-primary') . '" type="submit">' . h($btnLabel) . '</button>'
        . '<a class="btn btn-ghost" href="' . h($backUrl) . '">Batal</a></p></form></div>');
    exit;
}
/** Pindahkan folder ke storage/trash/ (bisa dipulihkan) alih-alih menghapus permanen. */
function move_to_trash(string $path, string $label, array $meta = []): string {
    $trash = APP_ROOT . '/storage/trash';
    if (!is_dir($trash)) mkdir($trash, 0777, true);
    $dest = $trash . '/' . preg_replace('/[^a-z0-9._-]+/i', '-', $label) . '-' . date('Ymd-His');
    if (is_dir($path)) { if (!@rename($path, $dest)) { throw new RuntimeException('Gagal memindahkan ke trash: ' . $path); } }
    else mkdir($dest, 0777, true);
    write_json($dest . '/_trashed.json', ['label' => $label, 'originalPath' => $path, 'trashedAt' => date('c'), 'meta' => $meta]);
    return $dest;
}
function require_admin(): ?array {
    $u = require_login() ?: current_user(); // di CLI require_login() mengembalikan null; sesi tetap dibaca
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h2>403 — Akses ditolak</h2><p>Akun ' . h((string)($u['username'] ?? '')) . ' (role: ' . h((string)($u['role'] ?? '')) . ') tidak punya izin aksi ini. Hanya admin.</p><p><a href="?">← Kembali</a></p>';
        exit;
    }
    return $u;
}
function require_editor(): ?array {
    $u = require_login() ?: current_user();
    if (($u['role'] ?? '') === 'qa') {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h2>403 — Akses ditolak</h2><p>Role QA hanya bisa lihat & test, tidak bisa menyimpan/men-deploy/mengelola API key.</p><p><a href="?">← Kembali</a></p>';
        exit;
    }
    return $u;
}
function json_rev(string $file): string {
    $s = @stat($file);
    return $s === false ? '' : sprintf('%d-%d', $s['mtime'], $s['size']);
}
function rev_check(string $file, string $expected): void {
    if ($expected !== '' && json_rev($file) !== $expected) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<h2>⚠ Perubahan orang lain terdeteksi</h2><p>File <code>' . h(basename($file)) . '</code> sudah diubah akun lain setelah halaman ini dibuka. Simpan dibatalkan agar tidak menimpa (rollback) pekerjaan mereka.</p><p><a href="?">← Muat ulang & lihat perubahan terbaru</a></p>';
        exit;
    }
}
function rev_field(string $file): string {
    return '<input type="hidden" name="rev_' . h(basename($file, '.json')) . '" value="' . h(json_rev($file)) . '">';
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
function api_key_find(int $id): ?array { $s = db()->prepare('SELECT * FROM api_keys WHERE id=?'); $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC); return $row ?: null; }
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
    $L[] = 'GROQ_FALLBACK_MODELS=' . setting_get('GROQ_FALLBACK_MODELS', 'openai/gpt-oss-20b');
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
        $L[] = $envName . '_BASE_URL=' . setting_get($envName . '_BASE_URL', ''); // kosong = belum diisi (tidak ditebak)
        $L[] = '';
    }
    // Web search fallback (Gemini grounding) toggle.
    $L[] = '# --- Web search fallback ---';
    $L[] = 'AI_WEB_SEARCH=' . setting_get('AI_WEB_SEARCH', 'false');
    $L[] = 'AI_WEB_SEARCH_TIMEOUT=' . setting_get('AI_WEB_SEARCH_TIMEOUT', '20');
    $L[] = 'AI_WEB_SEARCH_PROVIDERS=' . setting_get('AI_WEB_SEARCH_PROVIDERS', 'groq,openrouter,gemini');
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
/**
 * Auto-resolve export_path when folder renamed.
 * If stored path missing, scan parent for any dir with pano.xml.
 * Returns resolved path (original if no match or ambiguous).
 */
/** Path web (relatif terhadap host) untuk folder export di bawah htdocs; string kosong bila tidak bisa ditentukan. */
function export_web_path(string $exportPath): string {
    $htdocs = rtrim(str_replace('\\', '/', dirname(APP_ROOT)), '/');
    $export = rtrim(str_replace('\\', '/', $exportPath), '/');
    if ($export === '' || !str_starts_with($export, $htdocs . '/')) return '';
    return '/' . implode('/', array_map('rawurlencode', explode('/', substr($export, strlen($htdocs) + 1))));
}
function resolve_export_path(string $stored): string {
    $stored = str_replace('\\', '/', $stored);
    if ($stored === '' || is_dir($stored)) return $stored;
    $parent = dirname($stored);
    if (!is_dir($parent)) return $stored;
    $found = null;
    foreach (array_filter(scandir($parent), fn($e) => $e !== '.' && $e !== '..' && is_dir("$parent/$e")) as $entry) {
        $candidate = "$parent/$entry";
        if (is_file("$candidate/pano.xml")) {
            if ($found !== null) return $stored; // ambiguous, give up
            $found = $candidate;
        }
    }
    return $found ?? $stored;
}
function read_json(string $file, array $fallback = []): array { $raw = is_file($file) ? json_decode((string)file_get_contents($file), true) : null; return is_array($raw) ? $raw : $fallback; }
/**
 * Simpan versi lama file konten (tour/kota) ke .history/ sebelum ditimpa, maks 20 versi per file.
 * Hanya untuk file langsung di storage/tours/{slug}/ dan storage/cities/{slug}/ (bukan package turunan, bukan parsed.json).
 */
function json_history_snapshot(string $file, string $newJson): void {
    if (!is_file($file)) return;
    $norm = static fn(string $p): string => rtrim(str_replace('\\', '/', $p), '/');
    $parent = $norm(dirname($file, 2));
    if ($parent !== $norm(TOURS_DIR) && $parent !== $norm(cities_dir())) return;
    $base = basename($file);
    if (substr($base, -5) !== '.json' || $base[0] === '_' || $base === 'parsed.json') return;
    $old = @file_get_contents($file);
    if ($old === false || $old === '' || $old === $newJson) return;
    $h = dirname($file) . '/.history';
    if (!is_dir($h) && !@mkdir($h, 0777, true)) return;
    $stem = substr($base, 0, -5);
    @copy($file, $h . '/' . $stem . '-' . date('Ymd-His') . sprintf('-%03d', (int)(fmod(microtime(true), 1) * 1000)) . '.json');
    $all = glob($h . '/' . $stem . '-*.json') ?: [];
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - 20)) as $o) @unlink($o);
}
/** Tulis JSON secara atomik (file sementara + rename) dan berversi untuk file konten. */
function write_json(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Gagal membuat JSON untuk ' . basename($file) . ': ' . json_last_error_msg());
    json_history_snapshot($file, $json);
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, $json) === false) throw new RuntimeException('Gagal menulis ' . basename($file) . ' (disk penuh atau tidak punya izin).');
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        if (@file_put_contents($file, $json) === false) throw new RuntimeException('Gagal menulis ' . basename($file) . '.');
    }
}
/**
 * Gabungkan baris hasil edit form ke record lama berdasarkan id, supaya field yang tidak
 * ada di form (response, sceneIds, translations, items, ...) tidak hilang saat disimpan.
 * Record baru (id belum ada) memakai $newDefaults sebagai nilai awal.
 */
function merge_edited(array $existing, array $edited, array $newDefaults = [], string $idKey = 'id'): array {
    $byId = [];
    foreach ($existing as $e) if (is_array($e) && isset($e[$idKey])) $byId[(string)$e[$idKey]] = $e;
    $out = [];
    foreach ($edited as $row) {
        $id = (string)($row[$idKey] ?? '');
        $out[] = isset($byId[$id]) ? array_replace($byId[$id], $row) : array_replace($newDefaults, $row);
    }
    return $out;
}
/**
 * Ganti nama/slug tour lama dengan yang baru di dalam data JSON hasil clone, tanpa merusak teks lain:
 * nilai yang persis sama dengan slug lama diganti; nama lama diganti hanya sebagai kata utuh (bukan potongan kata).
 */
function rename_in_data($v, string $oldSlug, string $newSlug, string $oldName, string $newName) {
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = rename_in_data($x, $oldSlug, $newSlug, $oldName, $newName); return $v; }
    if (!is_string($v)) return $v;
    if ($v === $oldSlug) return $newSlug;
    if (mb_strlen($oldName) >= 4) {
        $r = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($oldName, '/') . '(?![\p{L}\p{N}])/u', str_replace(['\\', '$'], ['\\\\', '\\$'], $newName), $v);
        if (is_string($r)) $v = $r;
    }
    return $v;
}
function content_files(): array { return ['curated-answers' => 'Curated Questions', 'knowledge' => 'Knowledge', 'scene-identity' => 'Scene Identity', 'visitor-tips' => 'Visitor Tips', 'nearby' => 'Nearby', 'recommended-questions' => 'Recommended Questions (State1/State2)', 'flow' => 'Navigation Flow']; }
function export_snapshot(string $exportPath): array {
    $exportPath = rtrim(str_replace('\\', '/', $exportPath), '/');
    $state = [];
    foreach (['pano.xml', 'skin.js', 'index.html'] as $f) {
        $path = "$exportPath/$f";
        $state[$f] = file_hash_cached($path); // '' bila file tidak ada; cache berdasarkan mtime+ukuran
    }
    return $state;
}
function detect_export_changes(array $parsed, string $exportPath): ?array {
    $old = $parsed['_exportState'] ?? null;
    if (!$old || !is_array($old)) return null; // no baseline yet
    $cur = export_snapshot($exportPath);
    $changed = [];
    foreach (['pano.xml', 'skin.js', 'index.html'] as $f) {
        $oldVal = $old[$f] ?? '';
        $curVal = $cur[$f] ?? '';
        if ($oldVal !== '' && $curVal !== '' && $oldVal !== $curVal) $changed[] = $f;
    }
    return $changed ? $changed : null;
}
function content_template_flow(array $tour, array $parsedScenes): array {
    $si = read_json(tour_dir((string)$tour['slug']) . '/scene-identity.json', []);
    $siScenes = is_array($si['scenes'] ?? null) ? $si['scenes'] : [];
    $order = array_map(fn($s) => (string)$s['id'], $parsedScenes);
    $nodes = [];
    foreach ($parsedScenes as $s) {
        $id = (string)$s['id'];
        $nm = (string)($siScenes[$id]['placeName'] ?? $siScenes[$id]['displayName'] ?? '');
        if (trim($nm) === '') $nm = (string)($s['displayName'] ?? $s['name'] ?? $id);
        $nodes[$id] = ['displayName' => $nm];
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
            : $name.' merupakan bagian dari kawasan '.$tour['name'].' di '.$tour['location'].'. Area ini terhubung dengan jalur eksplorasi agrowisata dan menjadi titik orientasi pengunjung.';
        $long = $isPlaceholder
            ? $prettyName.' (ID: '.$id.') adalah panorama yang terdeteksi dari export tour. Belum ada deskripsi kurasi; silakan ganti nama dan isi deskripsi via Scene Editor agar guide ramah pengunjung. Area ini tetap terhubung dengan navigasi hotspot dan dapat dilengkapi objek info jika tersedia.'
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

/** Buat file konten awal dari template. Default HANYA membuat yang belum ada; $overwrite=true menimpa (versi lama masuk .history). */
function seed_content(array $tour, bool $overwrite = false): array {
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
    $written = []; $skipped = [];
    foreach ($templates as $name => $data) {
        $f = $dir . '/' . $name . '.json';
        if (is_file($f) && !$overwrite) { $skipped[] = $name; continue; }
        write_json($f, $data);
        $written[] = $name;
    }
    return ['written' => $written, 'skipped' => $skipped];
}
function all_tours(): array {
    $db = db();
    $rows = $db->query('SELECT * FROM tours ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    $scope = user_scope(); // editor/QA dengan cakupan kota hanya melihat tour di kotanya
    if ($scope !== null) $rows = array_values(array_filter($rows, fn($r) => in_array((int)($r['city_id'] ?? 0), $scope, true)));
    foreach ($rows as &$r) {
        $resolved = resolve_export_path((string)$r['export_path']);
        if ($resolved !== (string)$r['export_path']) {
            $up = $db->prepare('UPDATE tours SET export_path=? WHERE id=?');
            $up->execute([$resolved, (int)$r['id']]);
            $r['export_path'] = $resolved;
        }
    }
    return $rows;
}
function get_tour(int $id): ?array {
    $db = db();
    $s = $db->prepare('SELECT * FROM tours WHERE id=?'); $s->execute([$id]); $r = $s->fetch(PDO::FETCH_ASSOC); $s = null;
    if ($r) {
        $resolved = resolve_export_path((string)$r['export_path']);
        if ($resolved !== (string)$r['export_path']) {
            $up = $db->prepare('UPDATE tours SET export_path=? WHERE id=?');
            $up->execute([$resolved, $id]);
            $r['export_path'] = $resolved;
        }
    }
    return $r ?: null;
}
function save_tour(array $in): int {
    $pdo = db(); $now = date('c'); $id = (int)($in['id'] ?? 0);
    $name = trim((string)($in['name'] ?? '')); if ($name === '') throw new RuntimeException('Nama tour wajib diisi.');
    $slug = slugify((string)($in['slug'] ?? $name));
    // Kota: pilih dari daftar (city_id) atau ketik nama baru ('new_city'/'location'); `location` mengikuti nama kota.
    $cityId = (int)($in['city_id'] ?? 0);
    $cityRow = $cityId > 0 ? city_row_by_id($cityId) : null;
    if (!$cityRow) {
        $typed = trim((string)($in['new_city'] ?? '')) ?: trim((string)($in['location'] ?? ''));
        if ($typed === '') throw new RuntimeException('Kota wajib dipilih atau diisi.');
        $cityRow = city_ensure($typed);
    }
    $cityId = (int)$cityRow['id'];
    if (!city_in_scope($cityId)) throw new RuntimeException('Anda tidak punya akses ke kota ini.');
    $location = (string)$cityRow['name'];
    $category = trim((string)($in['category'] ?? ''));
    $path = str_replace('\\', '/', trim((string)($in['export_path'] ?? '')));
    if ($id) {
        $stmt = $pdo->prepare('UPDATE tours SET slug=?, name=?, location=?, city_id=?, category=?, export_path=?, updated_at=? WHERE id=?');
        $stmt->execute([$slug, $name, $location, $cityId, $category, $path, $now, $id]);
        return $id;
    }
    $stmt = $pdo->prepare('INSERT INTO tours(slug,name,location,city_id,category,export_path,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
    $stmt->execute([$slug, $name, $location, $cityId, $category, $path, $now, $now]);
    return (int)$pdo->lastInsertId();
}
function pano_default_views(string $path): array {
    $out = [];
    $file = rtrim($path, '/\\') . '/pano.xml';
    if (!is_file($file)) return $out;
    $xml = simplexml_load_file($file); if (!$xml) return $out;
    foreach ($xml->panorama as $p) {
        $id = (string)$p['id']; if ($id === '') continue;
        $st = $p->view->start ?? null;
        if ($st !== null) $out[$id] = ['pan' => (float)($st['pan'] ?? 0), 'tilt' => (float)($st['tilt'] ?? 0), 'fov' => (float)($st['fov'] ?? 100)];
    }
    return $out;
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
        // Default view per node (yaw=pan, pitch=tilt, fov) — dipakai guide saat navigate
        $st = $p->view->start ?? null;
        $scenes[] = ['id' => $id, 'name' => $name, 'displayName' => $name, 'rawName' => $title, 'customNodeId' => $custom, 'pan' => (float)($st['pan'] ?? 0), 'tilt' => (float)($st['tilt'] ?? 0), 'fov' => (float)($st['fov'] ?? 100), 'tags' => array_values(array_filter(explode('|', (string)($ud['tags'] ?? '')))), 'aliases' => array_values(array_filter([$custom, $title]))];
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
    // Safety: never wipe a target that is the master itself or contains it.
    if (path_is_same_or_inside($target, SOURCE_AI) || path_is_same_or_inside(SOURCE_AI, $target)) {
        throw new RuntimeException('copy_runtime ditolak: target bertumpang tindih dengan SOURCE_AI: ' . $target);
    }
    remove_tree($target);
    $skipFiles = ['.env.example', 'README.md', 'install-snippet.html', 'api/chat.php', 'config/tour.json', 'data/curated-answers.json', 'data/curated-qa.json', 'data/explore-menu-knowledge.json', 'data/knowledge.json', 'data/lawang-sewu-nearby.json', 'data/lawang-sewu-visitor-tips.json', 'data/nearby.json', 'data/place-map.json', 'data/place-registry.json', 'data/recommended-questions.json', 'data/flow.json', 'data/scene-identity.json', 'data/scene-identity (2).json', 'data/tour-data.js', 'data/visitor-tips.json', 'data/oi-positions.json', 'data/city-instructions.json', 'data/city-knowledge.json'];
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
    $skipPrefixes = ['config/', 'data/', 'api/_cover_cache/', 'api/cache/', 'docs/', 'tests/', 'tools/'];
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
/**
 * Skema konfigurasi package + versi runtime export. Naikkan saat kontrak berubah.
 */
const PACKAGE_CONFIG_SCHEMA_VERSION = 2;
const RUNTIME_VERSION = '2.1.0-voice-demo';

/**
 * Load per-tour voice settings. Migrasi default: tur tanpa file voice.json → mode off
 * (skema lama tidak pernah aktif). Field realtime dipertahankan tapi tidak diklaim aktif
 * sampai kredensial server tersedia.
 */
function tour_voice_settings(string $slug): array {
    $file = tour_dir($slug) . '/voice.json';
    $raw = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($raw)) $raw = [];
    $mode = (string)($raw['mode'] ?? '');
    if (!in_array($mode, ['off', 'demo', 'realtime'], true)) $mode = 'off';
    return [
        'mode' => $mode,
        'realtime_configured' => !empty($raw['realtime_configured']),
        'provider' => (string)($raw['provider'] ?? 'gemini-live'),
        'model' => (string)($raw['model'] ?? 'gemini-2.5-flash-native-audio-latest'),
        'voice' => (string)($raw['voice'] ?? 'Kore'),
        'language' => (string)($raw['language'] ?? 'id'),
        'idle_timeout' => (int)($raw['idle_timeout'] ?? 60),
        'session_duration' => (int)($raw['session_duration'] ?? 300),
        'max_concurrent' => (int)($raw['max_concurrent'] ?? 2),
        'allowedOrigin' => (string)($raw['allowedOrigin'] ?? ''),
        'answerPolicy' => (string)($raw['answerPolicy'] ?? 'curated'),
    ];
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
    // Pastikan tiap scene punya default view (pan/tilt/fov) untuk navigasi yang benar
    $parsedScenes = $parsed['scenes'] ?? [];
    $defViews = pano_default_views((string)$tour['export_path']);
    foreach ($parsedScenes as &$sc) {
        $sid = (string)($sc['id'] ?? '');
        if ($sid === '' || !isset($defViews[$sid])) continue;
        if (!isset($sc['pan'])) { $sc['pan'] = $defViews[$sid]['pan']; $sc['tilt'] = $defViews[$sid]['tilt']; $sc['fov'] = $defViews[$sid]['fov']; }
    }
    unset($sc);
    $modernObjectInfo = [];
    $modernObjectViews = [];
    $panoPath = rtrim((string)$tour['export_path'], '/\\') . '/pano.xml';
    if (is_file($panoPath) && ($panoXml = simplexml_load_file($panoPath))) {
        foreach ($panoXml->panorama as $panorama) {
            $node = trim((string)($panorama->userdata['customnodeid'] ?? ''), '{} ');
            if ($node === '') continue;
            foreach (($panorama->media->webelement ?? []) as $element) {
                $target = (string)($element['id'] ?? '');
                if ($target !== '') $modernObjectViews[$node][$target] = [
                    'pan' => (float)$element['pan'],
                    'tilt' => (float)$element['tilt'],
                    'fov' => (float)$element['fov'],
                ];
            }
        }
    }
    $webviewPath = dirname(APP_ROOT) . '/semarang/webview/json/data.json';
    $modernDbPath = dirname(APP_ROOT) . '/semarang/assets/ext/database.js';
    if (tour_city_slug($tour) === 'semarang' && is_file($webviewPath) && is_file($modernDbPath)) {
        $modernDbText = preg_replace('/^\s*window\.TourDatabase\s*=\s*/', '', rtrim((string)file_get_contents($modernDbPath), "; \r\n\t"));
        $modernDb = json_decode(preg_replace('/^\s*\/\/[^\r\n]*$/m', '', $modernDbText), true);
        $records = is_array($modernDb) ? ($modernDb[$tour['slug']] ?? []) : [];
        if ($records) {
            $ids = [];
            foreach ($records as $record) {
                $id = (string)($record['infoID'] ?? '');
                $node = trim((string)($record['nodeID'] ?? ''), '{} ');
                $target = (string)($record['targetID'] ?? '');
                if ($id !== '') $ids[$id] = $modernObjectViews[$node][$target] ?? null;
            }
            foreach ((read_json($webviewPath, [])['data'] ?? []) as $group) {
                foreach (($group['location'] ?? []) as $loc) {
                    foreach (($loc['obj_info'] ?? []) as $obj) {
                        $id = (string)($obj['old_par_id'] ?? '');
                        if (isset($ids[$id]) && !isset($modernObjectInfo[$id])) {
                            $modernObjectInfo[$id] = [
                                'name' => $obj['oi_name'] ?? [],
                                'description' => $obj['oi_desc_2'] ?? $obj['oi_desc'] ?? [],
                                'image' => $obj['img_thumb'] ?? null,
                                'view' => $ids[$id],
                            ];
                        }
                    }
                }
            }
        }
    }
    $tourData = [
        'id' => $tour['slug'],
        'modernObjectInfo' => $modernObjectInfo,
        'name' => $tour['name'],
        'scenes' => $parsedScenes,
        'hotspots' => $parsed['hotspots'],
        'panoExits' => $exits,
        'thumbPrefix' => $thumbPrefix,
        'routes' => [],
        'objectInfo' => $objects,
        'video360' => $parsed['video360'] ?? [],
        '_sceneRegistry' => array_column($parsedScenes, null, 'id')
    ];
    $config = [
        'configSchemaVersion' => PACKAGE_CONFIG_SCHEMA_VERSION,
        'runtimeVersion' => RUNTIME_VERSION,
        'tourId' => $tour['slug'],
        'tourName' => $tour['name'],
        'location' => $tour['location'],
        'nearbyLabel' => $tour['name'] . ' dan ' . $tour['location'],
        'language' => 'id',
        'features' => ['exploreKnowledge' => true, 'publicService' => true, 'destinationDiscovery' => true, 'destinationCatalog' => true, 'sceneIdentity' => true, 'answerability' => true],
        'voice' => tour_voice_settings($tour['slug']),
        'data' => ['visitorTipsPath' => 'data/visitor-tips.json', 'nearbyPath' => 'data/nearby.json'],
        'frontend' => array_merge(city_webview_config(tour_city_slug($tour)), [
            'catalogLabel' => $tour['location'],
            'nearbyLabel' => $tour['name'] . ' dan ' . $tour['location'],
        ])
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
    $citySlug = tour_city_slug($tour);
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
    // Curated Q&A (team-editable, per-tour): answered before AI, NOT shown in AI guide.
    write_json($out . '/data/curated-qa.json', read_json($dir . '/curated-qa.json', ['version' => '1.0', 'tourId' => $tour['slug'], 'description' => 'Curated Q&A database — team-editable. Matched BEFORE AI pipeline.', 'items' => []]));
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
    // Nama node flow diselaraskan dengan data AI Studio (scene-identity),
    // agar "kemana berikutnya" tidak menyebut nama teknis seperti "default".
    if (!empty($flow['nodes']) && is_array($flow['nodes'])) {
        $changed = false;
        foreach ($flow['nodes'] as $sid => $node) {
            $nm = '';
            if (isset($mappedScenes[$sid])) $nm = trim((string)($mappedScenes[$sid]['placeName'] ?? $mappedScenes[$sid]['displayName'] ?? ''));
            if ($nm === '') continue;
            if (is_array($node)) { if (($node['displayName'] ?? '') !== $nm) { $flow['nodes'][$sid]['displayName'] = $nm; $changed = true; } }
            else { $flow['nodes'][$sid] = ['displayName' => $nm]; $changed = true; }
        }
        if ($changed) write_json($dir . '/flow.json', $flow);
    }
    write_json($out . '/data/flow.json', $flow);
    // Generate oi-positions from parsed pano hotspot_oi_polygon (for objects-panel pitch/yaw navigation like backup)
    $oiSrc = $parsed['oiPositions'] ?? [];
    // Preserve manual position ORDER fixes stored in tour-root oi-positions.json
    // (order encodes object-to-polygon mapping). If a scene's marker SET is
    // unchanged (same pan/tilt), keep the root order; else use fresh parse.
    $oiRoot = read_json($dir . '/oi-positions.json', []);
    $oiRootScenes = is_array($oiRoot) ? ($oiRoot['scenes'] ?? []) : [];
    if (is_array($oiRootScenes)) {
        foreach ($oiSrc as $sid => $plist) {
            if (!isset($oiRootScenes[$sid]) || !is_array($oiRootScenes[$sid])) continue;
            $oiNorm = function ($arr) {
                $s = [];
                foreach ((array)$arr as $pp) {
                    if (!is_array($pp)) continue;
                    $s[] = round((float)($pp['pan'] ?? 0), 2) . ',' . round((float)($pp['tilt'] ?? 0), 2);
                }
                sort($s);
                return $s;
            };
            if ($oiNorm($oiRootScenes[$sid]) === $oiNorm($plist)) {
                $oiSrc[$sid] = array_values($oiRootScenes[$sid]);
            }
        }
    }
    if (empty($oiSrc)) {
        // fallback: try to generate empty but guide.js will fetch pano.xml live
        write_json($out . '/data/oi-positions.json', ['scenes' => new stdClass()]);
    } else {
        write_json($out . '/data/oi-positions.json', ['scenes' => $oiSrc]);
    }
    file_put_contents($out . '/README.md', "# GOVIRTUAL AI\n\nPackage ready for " . $tour['name'] . " (" . $tour['location'] . ").\n\n- Copy this folder into the tour export root.\n- Edit `data/knowledge.json` and `config/tour.json` for location-specific content.\n");
    // Keep catalog if source had it – copy if exists in source and not yet in out
    $wvc2 = city_webview_config(tour_city_slug($tour));
    $catalogSrc = $wvc2['catalogSrc'];
    $catalogDst = $out . '/' . $wvc2['catalogPath'];
    if (is_file($catalogSrc) && !is_file($catalogDst)) copy($catalogSrc, $catalogDst);
    $pubSrc = SOURCE_AI . '/data/public-services.json';
    $pubDst = $out . '/data/public-services.json';
    if (is_file($pubSrc) && !is_file($pubDst)) copy($pubSrc, $pubDst);
    // Explore menu knowledge: use harvested file if present, else empty stub
    $exploreSrc = $dir . '/explore-menu-knowledge.json';
    $explore = is_file($exploreSrc) ? read_json($exploreSrc, ['entries' => []]) : ['version' => '1.0', 'tourId' => $tour['slug'], 'sourceType' => 'none', 'entries' => []];
    write_json($out . '/data/explore-menu-knowledge.json', $explore);
    // Tema: tema bawaan = tidak ada tambahan apa pun di index.html (tampilan AI Guide yang ada tidak berubah).
    $themeResolved = theme_resolve($tour); $themeIsDefault = ($themeResolved === theme_defaults());
    file_put_contents($out . '/config/theme.css', theme_css($themeResolved));
    // Sync snippet with govirtual-ai reference (GOVIRTUAL_AI_CONFIG, basePath, catalogPath, tourLocation)
    $snippet ="<!-- GOVIRTUAL AI — Add this block before </body> in the tour index.html -->\n"
        . "<link rel=\"stylesheet\" href=\"./govirtual-ai/frontend/guide.css\">\n"
        . ($themeIsDefault ? '' : "<link rel=\"stylesheet\" href=\"./govirtual-ai/config/theme.css\">\n")
        . "<script src=\"./govirtual-ai/data/tour-data.js\"></script>\n"
        . "<script>\nwindow.GOVIRTUAL_AI_CONFIG = {\n"
        . "  basePath: \"./govirtual-ai/\",\n"
        . "  tourId: " . json_encode($tour['slug'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  tourName: " . json_encode($tour['name'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  tourLocation: " . json_encode($tour['location'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  nearbyLabel: " . json_encode($tour['name'] . ' dan ' . $tour['location'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  catalogPath: " . json_encode($wvc2['catalogPath'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  objectInfoBase: " . json_encode($wvc2['objectInfoBase'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  objectInfoPage: " . json_encode($wvc2['objectInfoPage'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  objectInfoDetailParam: " . json_encode($wvc2['objectInfoDetailParam'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  objectInfoExtra: " . json_encode($wvc2['objectInfoExtra'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ",\n"
        . "  langMap: " . json_encode($wvc2['langMap'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . "\n"
        . "};\n</script>\n"
        . "<script src=\"./govirtual-ai/frontend/guide.js\"></script>\n";
    file_put_contents($out . '/install-snippet.html', $snippet);
     return ['out' => $out, 'files' => ['install-snippet.html', 'README.md', 'data/tour-data.js', 'config/tour.json', 'data/curated-answers.json', 'data/knowledge.json', 'data/scene-identity.json', 'data/place-map.json', 'data/place-registry.json', 'data/visitor-tips.json', 'data/nearby.json', 'data/recommended-questions.json', 'data/flow.json', 'data/semarang-destinations.json', 'data/oi-positions.json']];
}
/**
 * Normalize a filesystem path for safe comparison.
 * - Forward slashes only (Windows separators handled).
 * - Trailing slash stripped (root "/" and "C:/" preserved).
 * - Lowercased on Windows (case-insensitive comparison).
 */
function path_normalize_for_compare(string $path): string {
    $p = str_replace('\\', '/', trim((string)$path));
    $isWin = DIRECTORY_SEPARATOR === '\\';
    if ($isWin && preg_match('/^[a-z]:\/$/i', $p) === 1) return strtolower($p);
    $p = rtrim($p, '/');
    if ($p === '') return '';
    if ($p === '/') return '/';
    return $isWin ? strtolower($p) : $p;
}

/**
 * True when $path is identical to $base or located inside $base
 * (component-aware: "a/b/c" is inside "a/b", but "a/bc" is not).
 */
function path_is_same_or_inside(string $path, string $base): bool {
    $p = path_normalize_for_compare($path);
    $b = path_normalize_for_compare($base);
    if ($b === '' || $p === '') return false;
    if ($p === $b) return true;
    if ($b === '/') return true;
    return str_starts_with($p, $b . '/');
}

/**
 * Validator export paket: cek skema konfigurasi, kapabilitas voice, isolasi tur,
 * dan pastikan tidak ada kredensial permanen yang bocor ke bundle publik.
 * Dipanggil sebelum deploy. Gagal => throw RuntimeException dengan pesan jelas.
 */
function validate_package_export(string $source, array $tour): void {
    $cfgFile = $source . '/config/tour.json';
    if (!is_file($cfgFile)) throw new RuntimeException('Export validator: config/tour.json tidak ditemukan di package.');
    $cfg = json_decode((string)file_get_contents($cfgFile), true);
    if (!is_array($cfg)) throw new RuntimeException('Export validator: config/tour.json tidak valid.');
    $schema = (int)($cfg['configSchemaVersion'] ?? 0);
    if ($schema === 0) throw new RuntimeException('Export validator: config/tour.json tidak punya configSchemaVersion. Generate ulang package (skema v' . PACKAGE_CONFIG_SCHEMA_VERSION . ').');
    if ($schema > PACKAGE_CONFIG_SCHEMA_VERSION) throw new RuntimeException('Export validator: configSchemaVersion ' . $schema . ' lebih baru dari yang didukung (' . PACKAGE_CONFIG_SCHEMA_VERSION . '). Perbarui Studio/runtime.');
    // Isolasi tur: tourId/tourName package harus milik tour ini.
    $norm = fn(string $s): string => (string)preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    $expect = $norm($tour['name']);
    $tag = (string)($cfg['tourName'] ?? $cfg['tourId'] ?? '');
    if ($tag !== '' && $norm($tag) !== $expect && $norm($tag) !== $norm($tour['slug'])) {
        throw new RuntimeException('Export validator: config/tour.json milik "' . $tag . '", bukan "' . $tour['name'] . '". Generate ulang package.');
    }
    // Kapabilitas voice: mode realtime butuh realtime_configured + endpoint server.
    $voice = is_array($cfg['voice'] ?? null) ? $cfg['voice'] : [];
    $mode = (string)($voice['mode'] ?? 'off');
    if (!in_array($mode, ['off', 'demo', 'realtime'], true)) {
        throw new RuntimeException('Export validator: mode voice tidak dikenal ("' . $mode . '").');
    }
    if ($mode === 'realtime' && empty($voice['realtime_configured'])) {
        throw new RuntimeException('Export validator: mode realtime dipilih tapi realtime_configured=false. Adapter realtime belum siap — set mode=demo atau siapkan endpoint provider.');
    }
    // Tidak ada kredensial permanen di bundle publik: config.php harus tanpa kunci,
    // ai-client.js tanpa default key. Skim ini memblokir regresi kunci hardcoded.
    $configPhp = (string)@file_get_contents($source . '/api/config.php');
    if (preg_match('/["\']?(GEMINI_API_KEY|OPENROUTER_API_KEY|GROQ_API_KEY)["\']?\s*=>?\s*["\']?([A-Za-z0-9_-]{12,})/', $configPhp)) {
        throw new RuntimeException('Export validator: api/config.php mengandung kunci hardcoded. Pindahkan ke .env dan regenerasi package.');
    }
    $aiClient = (string)@file_get_contents($source . '/ai-client.js');
    if (preg_match('/geminiApiKey\s*\|\|\s*["\']([A-Za-z0-9_-]{12,})/', $aiClient)) {
        throw new RuntimeException('Export validator: ai-client.js mengandung default API key di bundle publik. Hapus hardcoded key.');
    }
    // Bukan rahasia yang dilarang keras, tapi jaga agar peta lokasi tetap milik tur.
    if (isset($cfg['tourId']) && $tour['slug'] !== '' && (string)$cfg['tourId'] !== '' && (string)$cfg['tourId'] !== $tour['slug'] && (string)$cfg['tourId'] !== $tour['name']) {
        throw new RuntimeException('Export validator: tourId package tidak cocok dengan slug tur ini.');
    }
}

function deploy_package(array $tour): string {
    $source = tour_dir($tour['slug']) . '/package/govirtual-ai';
    $targetRoot = rtrim((string)$tour['export_path'], '/\\');
    $target = $targetRoot . '/govirtual-ai'; $index = $targetRoot . '/index.html';
    if (!is_dir($source)) throw new RuntimeException('Generate package dulu.');
    if (!is_file($index)) throw new RuntimeException('index.html target tidak ditemukan: ' . $index);
    // Validator export: versi skema, kapabilitas, dan tidak ada rahasia di bundle.
    validate_package_export($source, $tour);
    // Guard: never deploy INTO the shared runtime source, and never let the
    // source and target overlap in either direction (master overwrite or
    // recursive self-copy). Compares the FINAL destination ($target, i.e.
    // <export>/govirtual-ai), not just the export root — for a tour whose
    // export_path is the parent of SOURCE_AI, $target === SOURCE_AI and must be rejected.
    $srcNorm = path_normalize_for_compare((string)realpath($source));
    $tgtNorm = path_normalize_for_compare((string)realpath($target));
    $masterNorm = path_normalize_for_compare((string)realpath(SOURCE_AI));
    if (path_is_same_or_inside($target, $source)) {
        throw new RuntimeException('Deploy ditolak: target berada di dalam/di sumber package (penyalinan rekursif): ' . $target);
    }
    if (path_is_same_or_inside($source, $target)) {
        throw new RuntimeException('Deploy ditolak: sumber package berada di dalam target (recursive copy): ' . $source);
    }
    if ($masterNorm !== '' && path_is_same_or_inside($target, SOURCE_AI)) {
        throw new RuntimeException('Deploy ke shared runtime (SOURCE_AI) ditolak: ' . $target . '. export_path tour ini (' . $targetRoot . ') adalah induk dari/sama dengan SOURCE_AI (' . SOURCE_AI . '). Pindahkan file tour ke folder sendiri (mis. C:/xampp/htdocs/semarang/nama-tour), update export_path di Edit Tour, lalu Generate + Deploy ulang.');
    }
    if ($srcNorm !== '' && $tgtNorm !== '' && $tgtNorm === $srcNorm) {
        throw new RuntimeException('Deploy ditolak: target sama dengan sumber package: ' . $target);
    }
    // Guard: package data wajib milik tour ini — cegah data lokasi lain (mis. Lawang Sewu) ter-deploy.
    $norm = fn(string $s): string => (string)preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    $expect = $norm($tour['name']);
    foreach (['recommended-questions.json', 'flow.json', 'curated-answers.json'] as $probe) {
        $pf = $source . '/data/' . $probe;
        if (!is_file($pf)) continue;
        $pkg = json_decode((string)file_get_contents($pf), true);
        $tag = (string)($pkg['tourName'] ?? $pkg['tourId'] ?? '');
        if ($norm($tag) !== '' && $norm($tag) !== $expect && $norm($tag) !== $norm($tour['slug'])) {
            throw new RuntimeException('Package data tidak cocok dengan tour "' . $tour['name'] . '" (' . $probe . ' = "' . $tag . '"). Generate ulang package dulu.');
        }
    }
    $backup = $targetRoot . '/.ai-generator-backup/' . date('Ymd-His');
    if (is_dir($target)) { if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0777, true); rename($target, $backup); }
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/'); $dest = $target . '/' . $relative;
            if ($item->isDir()) { if (!is_dir($dest)) mkdir($dest, 0777, true); }
            else { if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0777, true); copy($item->getPathname(), $dest); }
        }
        // Override per-tour di luar package (mis. custom.css pano): disimpan di hub agar tidak tertimpa saat export dibuat ulang.
        $overrideDir = tour_dir($tour['slug']) . '/overrides';
        if (is_dir($overrideDir)) {
            foreach (glob($overrideDir . '/*') ?: [] as $ov) {
                if (is_file($ov)) copy($ov, $targetRoot . '/' . basename($ov));
            }
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
        // Refresh snapshot baseline (snippet re-inject modifies index.html hash)
        $parsed = read_json(tour_dir($tour['slug']) . '/parsed.json');
        if ($parsed) { $parsed['_exportState'] = export_snapshot($tour['export_path']); write_json(tour_dir($tour['slug']) . '/parsed.json', $parsed); }
    } catch (Throwable $e) {
        remove_tree($target); if (is_dir($backup)) rename($backup, $target); throw $e;
    }
    // Backup lama menumpuk di web root; simpan N terbaru saja (setting deploy_backup_keep, default 3).
    prune_deploy_backups($targetRoot . '/.ai-generator-backup', (int)setting_get('deploy_backup_keep', '3'));
    return $backup;
}
/** Hapus backup deploy terlama, sisakan $keep terbaru. Hanya menyentuh folder bernama YYYYMMDD-HHMMSS. $dryRun=true hanya mengembalikan daftar. */
function prune_deploy_backups(string $dir, int $keep = 3, bool $dryRun = false): array {
    if (!is_dir($dir)) return [];
    $all = array_values(array_filter(glob($dir . '/*', GLOB_ONLYDIR) ?: [], static fn($d) => (bool)preg_match('/^\d{8}-\d{6}$/', basename($d))));
    sort($all);
    $victims = array_slice($all, 0, max(0, count($all) - max(1, $keep)));
    if (!$dryRun) foreach ($victims as $d) remove_tree($d);
    return $victims;
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
    if (!current_user() && PHP_SAPI !== 'cli') { http_response_code(401); exit; } // hanya untuk pengguna Studio yang login
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
    // Incoming hotspot = view OF this scene from another POV
    $imgPath = '';
    foreach (($parsed['hotspots']??[]) as $h) {
        if (($h['target']??'')!==$scene || empty($h['scene'])) continue;
        $p = rtrim((string)$tour['export_path'],'/\\').'/images/'.$thumbPrefix.$h['scene'].'_'.$h['id'].'.webp';
        if (is_file($p)) { $imgPath = $p; break; }
    }
    // Fallback: outgoing hotspot = view FROM this scene
    if (!$imgPath) {
        foreach (($parsed['hotspots']??[]) as $h) {
            if ($h['scene']!==$scene) continue;
            $p = rtrim((string)$tour['export_path'],'/\\').'/images/'.$thumbPrefix.$scene.'_'.$h['id'].'.webp';
            if (is_file($p)) { $imgPath = $p; break; }
        }
    }
    // Fallback: try any thumb for this scene
    if (!$imgPath) {
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
// Sampul kota (storage/cities/<slug>/cover.jpg) untuk kartu "Pilih kota". Hanya untuk pengguna Studio yang login.
if (($_GET['a'] ?? '') === 'cityCover') {
    if (!current_user() && PHP_SAPI !== 'cli') { http_response_code(401); exit; }
    $slug = city_slugify((string)($_GET['city'] ?? ''));
    $file = $slug !== '' ? city_dir($slug) . '/cover.jpg' : '';
    if ($file === '' || !is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    readfile($file);
    exit;
}
// Sampul tur (preview.jpg dari folder ekspor) untuk kartu dashboard. Hanya untuk pengguna Studio yang login.
if (($_GET['a'] ?? '') === 'tourPreview') {
    if (!current_user() && PHP_SAPI !== 'cli') { http_response_code(401); exit; }
    $tour = get_tour((int)($_GET['id'] ?? 0));
    $file = $tour ? rtrim((string)$tour['export_path'], '/\\') . '/preview.jpg' : '';
    if ($file === '' || !is_file($file)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=3600');
    readfile($file);
    exit;
}
if (($_GET['a'] ?? '') === 'panoview') {
    if (!current_user() && PHP_SAPI !== 'cli') { http_response_code(401); exit; } // hanya untuk pengguna Studio yang login
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
            // Penyedia custom wajib punya base URL & model sendiri (jangan ditebak), sama seperti runtime.
            if ($baseUrl === '') {
                if ($provider === 'openrouter') $baseUrl = 'https://openrouter.ai/api/v1';
                elseif ($provider === 'groq') $baseUrl = 'https://api.groq.com/openai/v1';
                else { $r['error'] = 'Base URL belum diisi untuk penyedia ini.'; return $r; }
            }
            if ($model === '') {
                if ($provider === 'openrouter') $model = 'openai/gpt-4o-mini';
                elseif ($provider === 'groq') $model = 'groq/compound';
                else { $r['error'] = 'Model belum diisi untuk penyedia ini.'; return $r; }
            }
            $payload = ['messages' => [['role' => 'user', 'content' => 'Ping']], 'max_tokens' => 8, 'model' => $model];
            $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . trim($key)];
            // Header yang juga dikirim runtime (lihat ai_provider_request), supaya hasil tes sama dengan produksi.
            if ($provider === 'openrouter') $headers[] = 'HTTP-Referer: ' . (getenv('APP_URL') ?: 'https://localhost');
            if ($provider === 'opencode') $headers[] = 'x-opencode-session: govirtual-app';
            $res = test_key_request(rtrim($baseUrl, '/') . '/chat/completions', $headers, $payload);
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
    if (!current_user()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Sesi login berakhir. Muat ulang halaman lalu masuk lagi.']); exit; }
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
    $reply=''; $provider=''; $meta=[];
    $metaKeys = array_flip(['intent','source','answerSource','answerType','facet','confidence','curatedId','latencyMs','model','fallback','fallbackUsed']);
    if ($apiUrl !== '') {
        $res = testanswer_http_post($apiUrl, $payload);
        if ($res['ok']) {
            $d = json_decode($res['body'], true);
            if (is_array($d)) { $reply = (string)($d['reply'] ?? $d['respons'] ?? $d['answer'] ?? ''); $provider = (string)($d['provider'] ?? 'backend'); $meta = array_intersect_key($d, $metaKeys); }
        }
    }
    // Fallback: panggil API pusat govirtual-ai (Lawang Sewu) sebagai cadangan jika lokasi belum deploy
    if ($reply === '' && $apiUrl !== str_replace(' ', '%20', '')) { /* keep */ }
    if ($reply === '') {
        $fallbackUrl = central_api_url();
        $res2 = testanswer_http_post($fallbackUrl, $payload);
        if ($res2['ok']) {
            $d2 = json_decode($res2['body'], true);
            if (is_array($d2)) { $reply = (string)($d2['reply'] ?? $d2['respons'] ?? $d2['answer'] ?? ''); $provider = (string)($d2['provider'] ?? 'fallback-central'); $meta = array_intersect_key($d2, $metaKeys); }
        }
    }
    if ($reply === '') { echo json_encode(['ok'=>false,'error'=>'AI gagal menjawab (backend lokasi & central). Pastikan sudah Deploy & server aktif.', 'apiUrl'=>$apiUrl]); exit; }
    echo json_encode(['ok'=>true,'reply'=>$reply,'provider'=>$provider,'meta'=>$meta,'deployed'=>$apiUrl !== '' && $provider !== 'fallback-central'], JSON_UNESCAPED_UNICODE); exit;
}

// Generate deskripsi scene via AI (tombol ✨ di Scene Editor) — konteks: nama panorama
if (($_GET['a'] ?? '') === 'genDesc') {
    header('Content-Type: application/json; charset=utf-8');
    $genUser = current_user();
    if (!$genUser) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Sesi login berakhir. Muat ulang halaman lalu masuk lagi.']); exit; }
    if (($genUser['role'] ?? '') === 'qa') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Role QA tidak boleh memakai generator deskripsi.']); exit; }
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
        $res2 = testanswer_http_post(central_api_url(), $payload);
        if ($res2['ok']) { $d2 = json_decode($res2['body'], true); if (is_array($d2)) $reply = (string)($d2['reply'] ?? $d2['respons'] ?? $d2['answer'] ?? ''); }
    }
    if ($reply==='') { echo json_encode(['ok'=>false,'error'=>'AI gagal menjawab (backend lokasi & central). Pastikan sudah Deploy & server aktif.']); exit; }
    echo json_encode(['ok'=>true,'reply'=>$reply], JSON_UNESCAPED_UNICODE); exit;
}

// Live-test stored API key. Secret stays server-side.
if (($_GET['a'] ?? '') === 'testKey') {
    header('Content-Type: application/json; charset=utf-8');
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'admin required']); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }
    if (!csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF tidak valid']); exit; }
    $row = api_key_find((int)($_POST['key_id'] ?? 0));
    if (!$row || trim((string)$row['api_key']) === '') { echo json_encode(['ok' => false, 'error' => 'key tidak ditemukan']); exit; }
    $provider = (string)$row['provider'];
    $envName = provider_env_name($provider);
    $models = ['gemini' => 'GEMINI_MODEL', 'openrouter' => 'OPENROUTER_MODEL', 'groq' => 'GROQ_MODEL', 'ollama' => 'OLLAMA_MODEL'];
    $bases = ['openrouter' => 'OPENROUTER_BASE_URL', 'ollama' => 'OLLAMA_URL'];
    $model = setting_get($models[$provider] ?? $envName . '_MODEL', '');
    $base = setting_get($bases[$provider] ?? $envName . '_BASE_URL', '');
    echo json_encode(test_provider_key($provider, (string)$row['api_key'], $model, $base), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Tes koneksi dari form "Tambah penyedia" — data dari form, TIDAK disimpan. Key tidak dikembalikan ke browser.
if (($_GET['a'] ?? '') === 'testDraft') {
    header('Content-Type: application/json; charset=utf-8');
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'admin') { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'admin required']); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST required']); exit; }
    if (!csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF tidak valid']); exit; }
    $name = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', trim((string)($_POST['provider'] ?? ''))));
    $key = trim((string)($_POST['key'] ?? ''));
    $base = rtrim(trim((string)($_POST['base'] ?? '')), '/');
    $model = trim((string)($_POST['model'] ?? ''));
    if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Isi nama provider dulu.'], JSON_UNESCAPED_UNICODE); exit; }
    if ($key === '') { echo json_encode(['ok' => false, 'error' => 'Isi API key yang mau dites.'], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(test_provider_key($name, $key, $model, $base), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Playground: jawaban uji AI (login + CSRF di dalam handler).
if (($_GET['a'] ?? '') === 'playAnswer') { play_answer_route(); }

// STUDIO_ROUTE_RUNNER hanya didefinisikan oleh runner.php (smoke test) agar routing bisa dijalankan dari CLI.
if (PHP_SAPI !== 'cli' || defined('STUDIO_ROUTE_RUNNER')) {
$action = $_GET['a'] ?? 'list'; $error = '';

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        $row = $u !== '' ? user_find($u) : null;
        if ($row && (int)($row['active'] ?? 1) === 0 && password_verify($p, (string)$row['password_hash'])) { audit_write('loginDitolak', ['username' => $u, 'detail' => 'akun nonaktif']); $row = null; $error = '<div class="card bad"><p>Akun ini dinonaktifkan. Hubungi admin.</p></div>'; }
        elseif ($row && password_verify($p, (string)$row['password_hash'])) {
            if (session_status() === PHP_SESSION_NONE) @session_start();
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => (string)$row['username'], 'role' => (string)$row['role']];
            $_SESSION['must_change_pw'] = user_uses_default_password($row) || (int)($row['must_change'] ?? 0) === 1;
            audit_write('login', ['username' => (string)$row['username']]);
            header('Location: ' . (!empty($_SESSION['must_change_pw']) ? '?a=account' : '?'));
            exit;
        }
        usleep(400000); // perlambat tebakan password
        if ($error === '') { $error = '<div class="card bad"><p>Username atau password salah.</p></div>'; audit_write('loginGagal', ['username' => $u]); }
    }
    if (user_count() === 0) {
        $error .= '<div class="card warn"><h3>Belum ada akun</h3><p>Buat admin pertama lewat terminal:</p><p><code>php tools/user-admin.php create admin admin</code></p></div>';
    }
    $body = $error
        . '<div class="login-wrap"><form class="card" method="post" action="?a=login"><h2>🔐 Masuk — AI Guide Studio</h2>'
        . '<label for="login_user">Username</label><input id="login_user" name="username" required autocomplete="username">'
        . '<label for="login_pass">Password</label><input id="login_pass" name="password" type="password" required autocomplete="current-password">'
        . '<p><button class="btn btn-primary" type="submit">Masuk</button></p></form></div>';
    layout('Masuk — AI Guide Studio', $body);
    exit;
}
if ($action === 'logout') {
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION = [];
    session_destroy();
    header('Location: ?');
    exit;
}
if ($action !== 'thumb') {
    require_login();
    // Akun yang masih memakai password bawaan wajib menggantinya dulu.
    if (!empty($_SESSION['must_change_pw']) && !in_array($action, ['account', 'saveAccount'], true)) { header('Location: ?a=account'); exit; }
    // PHP memotong form yang melebihi max_input_vars TANPA error. Tolak simpan daripada menyimpan data terpotong.
    $maxVars = (int)ini_get('max_input_vars');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $maxVars > 0 && count($_POST) >= $maxVars) {
        http_response_code(413);
        layout('Form terlalu besar', '<div class="card bad"><h2>Form terlalu besar — tidak disimpan</h2><p>Form ini mengirim ≥ ' . $maxVars . ' field sehingga sebagian data akan hilang. Data Anda <b>belum</b> disimpan supaya tidak rusak.</p><p class="muted">Naikkan <code>max_input_vars</code> di php.ini, atau simpan lebih sedikit item sekaligus.</p><p><a class="btn" href="javascript:history.back()">← Kembali</a></p></div>');
        exit;
    }
    // Semua POST wajib membawa token CSRF (disisipkan otomatis ke setiap form oleh layout()).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        layout('Sesi tidak valid', '<div class="card bad"><h2>Token keamanan tidak valid</h2><p>Halaman kedaluwarsa atau sesi berganti. Data Anda <b>belum</b> disimpan. Muat ulang halaman lalu coba lagi.</p><p><a class="btn" href="javascript:history.back()">← Kembali</a> <a class="btn btn-primary" href="?">Ke dashboard</a></p></div>');
        exit;
    }
}

// Cakupan kota (editor/QA yang dibatasi) dan jejak audit untuk semua aksi tulis.
enforce_scope();
if ($_SERVER['REQUEST_METHOD'] === 'POST') audit_write($action, ['tour_id' => (int)($_GET['id'] ?? $_POST['id'] ?? 0) ?: null, 'city' => (string)($_GET['city'] ?? $_POST['city'] ?? '')]);

if ($action === 'account' || $action === 'saveAccount') {
    $me = current_user(); $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveAccount') {
        $row = $me ? user_find((string)$me['username']) : null;
        $cur = (string)($_POST['current_password'] ?? ''); $new = (string)($_POST['new_password'] ?? ''); $conf = (string)($_POST['confirm_password'] ?? '');
        if (!$row || !password_verify($cur, (string)$row['password_hash'])) $msg = '<div class="card bad"><p>Password saat ini salah.</p></div>';
        elseif ($new !== $conf) $msg = '<div class="card bad"><p>Konfirmasi password tidak sama.</p></div>';
        elseif ($err = password_policy_error($new)) $msg = '<div class="card bad"><p>' . h($err) . '</p></div>';
        elseif ($new === $cur) $msg = '<div class="card bad"><p>Password baru harus berbeda dari yang lama.</p></div>';
        else {
            $u = db()->prepare('UPDATE users SET password_hash=?, must_change=0 WHERE id=?'); $u->execute([password_hash($new, PASSWORD_DEFAULT), (int)$row['id']]);
            session_regenerate_id(true); unset($_SESSION['must_change_pw']);
            header('Location: ?a=account&saved=1'); exit;
        }
    }
    if (isset($_GET['saved'])) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Password berhasil diganti.</p></div>';
    if (!empty($_SESSION['must_change_pw'])) $msg .= '<div class="card warn"><p><b>Akun Anda masih memakai password bawaan.</b> Ganti dulu sebelum memakai Studio.</p></div>';
    $body = $msg . '<div class="card" style="max-width:520px"><h2>Akun saya</h2><p class="muted">Masuk sebagai <b>' . h((string)($me['username'] ?? '')) . '</b> (' . h((string)($me['role'] ?? '')) . ')</p>'
        . '<form method="post" action="?a=saveAccount" style="display:grid;gap:10px">'
        . '<label for="cur_pw">Password saat ini</label><input id="cur_pw" type="password" name="current_password" required autocomplete="current-password">'
        . '<label for="new_pw">Password baru</label><input id="new_pw" type="password" name="new_password" required minlength="10" autocomplete="new-password"><span class="muted" style="font-size:12px">Minimal 10 karakter, mengandung huruf dan angka.</span>'
        . '<label for="conf_pw">Ulangi password baru</label><input id="conf_pw" type="password" name="confirm_password" required minlength="10" autocomplete="new-password">'
        . '<p><button class="btn btn-primary" type="submit">Simpan password</button></p></form></div>';
    layout('Akun saya', $body); exit;
}

// Project-scoped actions need a valid tour id. If missing, show friendly pick-a-project state instead of error pages.
$projectActions = ['view','edit','import','scenes','knowledge','curated','curatedqa','prompts','tips','nearby','flow','voice','history','test','publish','deploy','generate','exportScenes','importScenes'];
if (in_array($action, $projectActions, true)) {
    $cur = get_tour((int)($_GET['id'] ?? 0));
    if (!$cur) {
        $tours = all_tours();
        $pick = '<div class="empty"><div class="empty-ic">' . studio_icon('scenes', 48) . '</div><h3>Pilih project dulu</h3><p>Halaman ini butuh konteks project. Pilih salah satu project di bawah, atau buat project baru untuk mulai.</p>'
            . '<div class="pick-tour-list">';
        foreach ($tours as $t) {
            $pick .= '<a class="pick-tour" href="?a=' . h($action) . '&id=' . (int)$t['id'] . '"><span class="pick-tour-name">' . h($t['name']) . '</span><span class="pick-tour-loc">' . h($t['location']) . '</span></a>';
        }
        $pick .= '</div><a class="btn btn-primary" href="?a=new">+ Buat project baru</a></div>';
        layout('Pilih Project', $pick);
        exit;
    }
}

try {
    // QA read-only: semua aksi tulis admin-only
    if (in_array($action, ['save','saveAs','delete','import','seedContent','saveContent','saveScenes','importScenes','savePrompts','saveCurated','saveCuratedQA','saveFlow','saveKnowledge','saveTips','saveNearby','generate','saveKeys','deploy','resync','citySave','citySourceUrl','citySourceFile','citySourceText','citySourceRefresh','cityDelete','citySourceDelete','syncRuntime','cityNew','importCuratedQA','saveVoice','importPrompts','savePrompts','aihealthRun','restoreHistory','bulkImport','jobs','jobEnqueue','healthEnqueue','healthBase'], true)) require_editor();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
        $err = null; $postId = (int)($_POST['id'] ?? 0);
        $newPath = rtrim(str_replace('\\', '/', trim((string)($_POST['export_path'] ?? ''))), '/');
        $oldTour = $postId > 0 ? get_tour($postId) : null;
        $oldPath = $oldTour ? rtrim(str_replace('\\', '/', (string)$oldTour['export_path']), '/') : null;
        // Validasi hanya bila path berubah, supaya tour dengan folder lama yang hilang tetap bisa diedit.
        if ($oldPath === null || $newPath !== $oldPath) $err = export_path_error($newPath);
        if ($err === null) {
            try {
                $id = save_tour($_POST);
                if ($postId === 0) { // tour baru: salin konten (opsional), import pano, buat konten awal
                    $prep = prepare_new_tour($id, (int)($_POST['clone_from'] ?? 0) ?: null, !empty($_POST['auto_prepare']));
                    header('Location: ?a=view&id=' . $id . '&created=' . urlencode(implode('; ', $prep['done']) . ($prep['warnings'] ? ' ⚠ ' . implode('; ', $prep['warnings']) : ''))); exit;
                }
                header('Location: ?a=view&id=' . $id); exit;
            }
            catch (PDOException $e) { $err = str_contains($e->getMessage(), 'UNIQUE') ? 'Slug sudah dipakai tour lain. Ubah slug-nya.' : 'Gagal menyimpan: ' . $e->getMessage(); }
            catch (RuntimeException $e) { $err = $e->getMessage(); }
        }
        render_tour_form(array_merge(['id' => 0, 'name' => '', 'slug' => '', 'city_id' => 0, 'new_city' => '', 'category' => '', 'export_path' => ''], $_POST), '<div class="card bad"><p>' . h((string)$err) . '</p></div>');
        exit;
    }
    if ($action === 'delete') {
        $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $tid = (int)$tour['id'];
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') confirm_page('Hapus project “' . $tour['name'] . '”',
            '<p>Project ini akan dikeluarkan dari Studio beserta seluruh kontennya (scene, knowledge, Q&amp;A, package).</p><p class="muted">Folder export Pano2VR tidak disentuh. Data dipindahkan ke <code>storage/trash/</code>, jadi masih bisa dipulihkan secara manual.</p>',
            '?a=delete&id=' . $tid, 'Hapus project', '?a=view&id=' . $tid, true, (string)$tour['name']);
        if (trim((string)($_POST['confirm_name'] ?? '')) !== (string)$tour['name']) throw new RuntimeException('Nama yang diketik tidak cocok. Penghapusan dibatalkan.');
        move_to_trash(tour_dir($tour['slug']), 'tour-' . $tour['slug'], ['tour' => $tour]);
        $pdo = db(); $s = $pdo->prepare('DELETE FROM tours WHERE id=?'); $s->execute([$tid]);
        header('Location: ?'); exit;
    }
    if ($action === 'saveAs' && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ?a=view&id=' . (int)($_GET['id'] ?? 0) . '#saveas'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveAs') {
        $orig = get_tour((int)($_GET['id'] ?? $_POST['id'] ?? 0)); if (!$orig) throw new RuntimeException('Tour asal tidak ditemukan.');
        $newName = trim((string)($_POST['new_name'] ?? '')); if ($newName === '') throw new RuntimeException('Nama baru wajib diisi.');
        $newSlug = slugify((string)($_POST['new_slug'] ?? $newName));
        $newExport = trim((string)($_POST['new_export'] ?? ''));
        if ($err = export_path_error($newExport)) throw new RuntimeException($err);
        $dupe = db()->prepare('SELECT 1 FROM tours WHERE slug=?'); $dupe->execute([$newSlug]);
        if ($dupe->fetchColumn()) throw new RuntimeException('Slug sudah dipakai: ' . $newSlug);
        // Kota: ikut kota tour asal, kecuali nama kota lain diketik.
        $typedCity = trim((string)($_POST['new_location'] ?? ''));
        $in = ['name' => $newName, 'slug' => $newSlug, 'category' => (string)($orig['category'] ?? ''), 'export_path' => $newExport];
        if ($typedCity !== '' && strcasecmp($typedCity, (string)$orig['location']) !== 0) $in['new_city'] = $typedCity; else $in['city_id'] = (int)($orig['city_id'] ?? 0);
        $newId = save_tour($in);
        clone_tour_content($orig, $newName, $newSlug, isset($_POST['copy_parsed']) && $_POST['copy_parsed'] === '1');
        header('Location: ?a=view&id=' . $newId); exit;
    }
    if ($action === 'import') { $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') confirm_page('Import pano &amp; skin', '<p>Studio akan membaca ulang <code>pano.xml</code> dan <code>skin.js</code> dari folder export <b>' . h($tour['name']) . '</b> dan menimpa data scene hasil import sebelumnya (<code>parsed.json</code>).</p><p class="muted">Konten yang sudah Anda tulis (knowledge, Q&amp;A, tips, dll.) tidak diubah.</p>', '?a=import&id=' . (int)$tour['id'], 'Import sekarang', '?a=view&id=' . (int)$tour['id']);
        tour_import_pano($tour); header('Location: ?a=view&id=' . $tour['id']); exit; }
    if ($action === 'seedContent') {
        $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') confirm_page('Reset konten ke template', '<p>Knowledge, intent, scene identity, tips, tempat sekitar, dan rekomendasi pertanyaan <b>' . h($tour['name']) . '</b> akan <b>ditimpa</b> dengan template awal.</p><p class="muted">Versi sebelumnya tetap tersimpan di folder <code>.history</code> tour ini, tetapi tidak muncul otomatis di editor. Gunakan hanya bila ingin mengulang dari nol.</p>', '?a=seedContent&id=' . (int)$tour['id'], 'Timpa dengan template', '?a=view&id=' . (int)$tour['id'], true, '', ['overwrite' => '1']);
        $sr = seed_content($tour, !empty($_POST['overwrite']));
        header('Location: ?a=view&id=' . $tour['id'] . '&seeded=' . count($sr['written']) . '&kept=' . count($sr['skipped']) . '#content'); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveContent') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $name = (string)($_GET['name'] ?? ''); if (!array_key_exists($name, content_files())) throw new RuntimeException('Section tidak valid.');
        $raw = (string)($_POST['content'] ?? ''); json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $cf = tour_dir($tour['slug']) . '/' . $name . '.json';
        rev_check($cf, (string)($_POST['rev_' . $name] ?? ''));
        file_put_contents($cf, $raw); header('Location: ?a=view&id=' . $tour['id'] . '#content'); exit;
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
            for ($k=0;$k<3;$k++){
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
        rev_check($dir . '/scene-identity.json', (string)($_POST['rev_scene-identity'] ?? ''));
        write_json($dir . '/scene-identity.json', $si);
        $do = (string)($_POST['do'] ?? '');
        if ($do==='save_generate') { $r=build_files($tour); header('Location: ?a=scenes&id='.$tour['id'].'&saved=1&generated=1'); exit; }
        if ($do==='save_deploy') { build_files($tour); $backup=deploy_package($tour); header('Location: ?a=scenes&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=scenes&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'exportScenes') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir = tour_dir($tour['slug']);
        $parsed = read_json($dir.'/parsed.json'); if (!$parsed) throw new RuntimeException('Belum ada parsed.json — import pano/skin dulu.');
        $si = read_json($dir.'/scene-identity.json', ['scenes'=>[]]);
        [$rows, $thumbs] = scene_rows_for_export($tour, $parsed, $si);
        if (($_GET['fmt'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="scenes-'.$tour['slug'].'.csv"');
            echo scene_export_csv($tour, $rows); exit;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="scenes-'.$tour['slug'].'.xlsx"');
        echo scene_build_xlsx(array_merge(['Panorama (thumbnail)'], SCENE_CSV_HEADERS), $rows, $thumbs); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'importScenes') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir = tour_dir($tour['slug']);
        $f = $_FILES['scene_file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Pilih file .xlsx atau .csv dulu.');
        if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gagal (kode '.$f['error'].').');
        $imported = scene_parse_file($f['tmp_name'], (string)($f['name'] ?? ''));
        $si = read_json($dir.'/scene-identity.json', ['scenes'=>[]]);
        [$si, $count] = scene_apply_import($si, $imported);
        rev_check($dir.'/scene-identity.json', (string)($_POST['rev_scene-identity'] ?? ''));
        write_json($dir.'/scene-identity.json', $si);
        header('Location: ?a=scenes&id='.$tour['id'].'&imported='.$count); exit;
    }
    if ($action === 'scenes') {
        $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) { layout('Tour hilang', '<p>Tour tidak ditemukan.</p>'); exit; }
        $parsed = read_json(tour_dir($tour['slug']) . '/parsed.json');
        if (!$parsed) { layout('Belum import', '<div class="card"><h2>Impor pano dulu</h2><p class="muted">Scene belum terdeteksi. Impor panorama dari folder export terlebih dahulu.</p><p><a class="btn btn-primary" href="?a=import&id=' . (int)$tour['id'] . '">Impor pano</a> <a class="btn btn-ghost" href="?a=view&id=' . (int)$tour['id'] . '">Kembali</a></p></div>'); exit; }
        $si = read_json(tour_dir($tour['slug']) . '/scene-identity.json', ['scenes' => []]);
        $knowledge = read_json(tour_dir($tour['slug']) . '/knowledge.json', ['entries' => []]);
        $msg = '';
        if (isset($_GET['saved'])) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Scene disimpan' . (isset($_GET['generated']) ? ' dan paket dibuat' : '') . (isset($_GET['deployed']) ? ' dan situs publik diperbarui' : '') . '.</p></div>';
        if (isset($_GET['imported'])) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ ' . (int)$_GET['imported'] . ' scene diperbarui dari file.</p></div>';
        render_scene_page($tour, $parsed, $si, $knowledge, $msg); exit;
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
        rev_check($dir . '/recommended-questions.json', (string)($_POST['rev_recommended-questions'] ?? ''));
        write_json($dir . '/recommended-questions.json', $rec);
        $do=(string)($_POST['do']??'');
        if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=prompts&id='.$tour['id'].'&saved=1&generated=1'); exit; }
        if($do==='save_deploy'){ build_files($tour); $backup=deploy_package($tour); header('Location: ?a=prompts&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=prompts&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'importPrompts') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir = tour_dir($tour['slug']);
        $f = $_FILES['prompt_file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Pilih file .xlsx atau .csv dulu.');
        if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gagal (kode '.$f['error'].').');
        $import = prompt_parse_file($f['tmp_name'], (string)($f['name'] ?? ''));
        $rec = read_json($dir.'/recommended-questions.json', content_template_recommended_questions($tour));
        if ($import['themes']) $rec['state1']['themes'] = $import['themes'];
        if ($import['starters']) $rec['state1']['starters'] = $import['starters'];
        $rec['updatedAt'] = date('c');
        rev_check($dir.'/recommended-questions.json', (string)($_POST['rev_recommended-questions'] ?? ''));
        write_json($dir.'/recommended-questions.json', $rec);
        $count = count($import['themes']) + count($import['starters']);
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save_generate') { build_files($tour); header('Location: ?a=prompts&id='.$tour['id'].'&imported='.$count.'&generated=1'); exit; }
        if ($do === 'save_deploy') { build_files($tour); deploy_package($tour); header('Location: ?a=prompts&id='.$tour['id'].'&imported='.$count.'&deployed=1'); exit; }
        header('Location: ?a=prompts&id='.$tour['id'].'&imported='.$count); exit;
    }
    if ($action === 'prompts') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) { layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $rec = read_json(tour_dir($tour['slug']).'/recommended-questions.json', content_template_recommended_questions($tour));
        $saved=isset($_GET['saved']); $generated=isset($_GET['generated']); $deployed=isset($_GET['deployed']);
        $msg=''; if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Recommended Questions disimpan.</p>'.($generated?'<p class=ok>✓ Package digenerate.</p>':'').($deployed?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        if(isset($_GET['imported'])) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ '.((int)$_GET['imported']).' pertanyaan diupdate dari file upload.</p>'.($generated?'<p class=ok>✓ Package digenerate.</p>':'').($deployed?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        $body=$msg;
        $body.='<div class=card><h2>Recommended Questions — '.h($tour['name']).'</h2><p class=muted>Edit pertanyaan rekomendasi State 1 (Discovery: COBA TANYAKAN & JELAJAHI TOPIK) dan State 2 (Workspace: MUNGKIN ANDA INGIN TAHU fallback). Berbeda per lokasi.</p><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a> <a class=btn href="?a=scenes&id='.(int)$tour['id'].'" class="btn btn-ai">Scene Editor</a></p></div>';
        $body.='<div class=card><h3>Bulk Import / Update Questions</h3><p class=muted>Upload .xlsx atau .csv dengan format yang sama seperti Question_ANGKRINGAN_KAMPUNG_JAWI.xlsx: kolom B/C untuk 6 tema dan F/G/H untuk 3 starter. State 2 tidak diubah.</p><form method=post enctype="multipart/form-data" action="?a=importPrompts&id='.(int)$tour['id'].'">'.rev_field(tour_dir($tour['slug']).'/recommended-questions.json').'<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><input type=file name=prompt_file accept=".xlsx,.csv" required style="flex:1;min-width:220px"><button type=submit name=do value=save class="btn btn-primary">⬆ Upload & Update Massal</button><button type=submit name=do value=save_generate class="btn btn-success">⬆ Upload + Generate</button><button type=submit name=do value=save_deploy class="btn btn-warning" onclick="return confirm(\'Upload, generate, dan deploy?\')">🚀 Upload + Deploy</button></div></form></div>';
        $body.='<form method=post action="?a=savePrompts&id='.(int)$tour['id'].'">' . rev_field(tour_dir($tour['slug']).'/recommended-questions.json');
        $body.='<div class=card><h3>State 1 — Discovery: COBA TANYAKAN (3 starters)</h3><p class=muted>Muncul di overlay awal. Contoh: "Apa yang menarik di sini?" / "Mengapa disebut '.h($tour['name']).'?"</p>';
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
            $intents[]=['id'=>$id,'category'=>$cat,'aliases'=>$aliases,'keywords'=>$keywords,'negativePatterns'=>$neg];
        }
        // Gabung ke intent lama: field di luar form (response, requiresState, action, dynamic) dipertahankan.
        $intents=merge_edited((array)($data['intents'] ?? []), $intents, ['dynamic'=>true,'action'=>null]);
        if(isset($_POST['add_intent'])) $intents[]=['id'=>'G'.str_pad((string)(count($intents)+1),2,'0',STR_PAD_LEFT).'_NEW','category'=>'custom','aliases'=>[],'keywords'=>[],'negativePatterns'=>[],'dynamic'=>true,'action'=>null];
        $data['intents']=$intents; rev_check($dir.'/curated-answers.json', (string)($_POST['rev_curated-answers'] ?? '')); write_json($dir.'/curated-answers.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=curated&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); $b=deploy_package($tour); header('Location: ?a=curated&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=curated&id='.$tour['id'].'&saved=1'); exit;
    }
    // Curated Q&A editor (FAQ tim: jawaban langsung tanpa AI)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveCuratedQA') {
        $tour=get_tour((int)$_GET['id']); if(!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir=tour_dir($tour['slug']); $data=read_json($dir.'/curated-qa.json',['version'=>'1.0','tourId'=>$tour['slug'],'items'=>[]]);
        $items=[];
        $count=(int)($_POST['qa_count']??0);
        for($i=0;$i<$count;$i++){
            if(isset($_POST["del_$i"])) continue;
            $id=trim((string)($_POST["qa_{$i}_id"]??'')); if($id==='') continue;
            $question=trim((string)($_POST["qa_{$i}_question"]??''));
            $answer=trim((string)($_POST["qa_{$i}_answer"]??''));
            if($question==='' && $answer==='') continue;
            $aliases=array_values(array_filter(array_map('trim', explode(',', (string)($_POST["qa_{$i}_aliases"]??'')))));
            $keywords=array_values(array_filter(array_map('trim', explode(',', (string)($_POST["qa_{$i}_keywords"]??'')))));
            $items[]=['id'=>$id,'question'=>$question,'answer'=>$answer,'aliases'=>$aliases,'keywords'=>$keywords];
        }
        $items=merge_edited((array)($data['items'] ?? []), $items);
        if(isset($_POST['add_qa'])) $items[]=['id'=>'QA'.str_pad((string)(count($items)+1),2,'0',STR_PAD_LEFT).'_NEW','question'=>'','answer'=>'','aliases'=>[],'keywords'=>[]];
        $data['items']=$items; rev_check($dir.'/curated-qa.json', (string)($_POST['rev_curated-qa'] ?? '')); write_json($dir.'/curated-qa.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=curatedqa&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); $b=deploy_package($tour); header('Location: ?a=curatedqa&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=curatedqa&id='.$tour['id'].'&saved=1'); exit;
    }
    // Import Curated Q&A dari file Excel/CSV (upload)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'importCuratedQA') {
        $tour=get_tour((int)$_GET['id']); if(!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir=tour_dir($tour['slug']);
        $f=$_FILES['qa_file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Pilih file .xlsx atau .csv dulu.');
        if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload gagal (kode '.$f['error'].').');
        $items=qa_parse_file_to_items($f['tmp_name'], (string)($f['name'] ?? ''));
        $data=read_json($dir.'/curated-qa.json',['version'=>'1.0','tourId'=>$tour['slug'],'items'=>[]]);
        $data['items']=$items; rev_check($dir.'/curated-qa.json', (string)($_POST['rev_curated-qa'] ?? '')); write_json($dir.'/curated-qa.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=curatedqa&id='.$tour['id'].'&imported='.count($items).'&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); $b=deploy_package($tour); header('Location: ?a=curatedqa&id='.$tour['id'].'&imported='.count($items).'&deployed=1'); exit; }
        header('Location: ?a=curatedqa&id='.$tour['id'].'&imported='.count($items)); exit;
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
        rev_check($dir . '/flow.json', (string)($_POST['rev_flow'] ?? ''));
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
        $body .= '<form method=post action="?a=saveFlow&id=' . (int)$tour['id'] . '">' . rev_field(tour_dir($tour['slug']).'/flow.json');
        // Start scene selector
        $startOpts = '';
        foreach (($flow['order'] ?: array_column($parsed['scenes'] ?? [], 'id')) as $sid) {
            $dn = $flow['nodes'][$sid]['displayName'] ?? $si['scenes'][$sid]['displayName'] ?? $sid;
            $startOpts .= '<option value="' . h($sid) . '"' . (($flow['startScene'] ?? '') === $sid ? ' selected' : '') . '>' . h($dn) . ' (' . h($sid) . ')</option>';
        }
        $body .= '<div class=card><label style="font-size:12px;color:var(--pill-text)">🎬 Titik Awal Panorama</label><select name="startScene" style="max-width:420px">' . $startOpts . '</select><p class=muted style="font-size:12px;margin-top:6px">Tur dimulai dari panorama ini. Urutan di bawah menentukan panorama berikutnya yang disarankan.</p></div>';
        // Ordered flow rows (drag & drop + thumbnail panorama + viewer)
        $body .= '<div class=card><h3>Urutan Kunjungan (kemana berikutnya)</h3><p class=muted style="font-size:12px">Urutkan sesuai alur yang Anda inginkan. <b>Geser baris</b> (drag &amp; drop) memakai ikon <b>&#8942;&#8942;</b> di kiri untuk mengubah urutan. Gambar di kiri tiap baris adalah <b>thumbnail panorama</b> — klik untuk membuka viewer. Ubah dropdown untuk mengganti panorama baris itu.</p>';
        $body .= '<div id="flowList" style="display:flex;flex-direction:column;gap:8px">';
        $order = $flow['order'] ?: array_column($parsed['scenes'] ?? [], 'id');
        foreach ($order as $idx => $sid) {
            $dn = $flow['nodes'][$sid]['displayName'] ?? $si['scenes'][$sid]['displayName'] ?? $sid;
            $thumbUrl = '?a=thumb&id=' . (int)$tour['id'] . '&scene=' . urlencode((string)$sid);
            $previewUrl = '?a=panoview&id=' . (int)$tour['id'] . '&scene=' . urlencode((string)$sid);
            $body .= '<div class="flow-row" data-idx="' . $idx . '" style="display:flex;gap:10px;align-items:center;padding:8px;border:1px solid var(--border);border-radius:10px;background:var(--surface-1)">';
            $body .= '<span class="flow-drag" draggable="true" title="Geser untuk mengubah urutan" style="cursor:grab;font-size:18px;line-height:1;letter-spacing:-2px;color:var(--pill-text);user-select:none;padding:2px 6px">&#8942;&#8942;</span>';
            $body .= '<span class="flow-num" style="font-size:13px;font-weight:600;min-width:24px;color:var(--pill-text)">' . ($idx + 1) . '.</span>';
            $body .= '<a class="flow-thumb" href="' . h($previewUrl) . '" target="_blank" rel="noopener" title="Buka viewer panorama" style="flex:0 0 auto;display:block;width:96px;height:60px;border-radius:8px;overflow:hidden;border:1px solid var(--border);background:var(--code-bg)"><img src="' . h($thumbUrl) . '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block" onerror="this.style.display=\'none\'"></a>';
            $body .= '<input type="hidden" name="flow_' . $idx . '_scene" value="' . h($sid) . '">';
            $body .= '<select name="flow_' . $idx . '_scene" onchange="flowThumb(this)" style="max-width:320px;flex:1;min-width:170px">';
            foreach (($parsed['scenes'] ?? []) as $s) {
                $oid = (string)$s['id'];
                $odn = $flow['nodes'][$oid]['displayName'] ?? $si['scenes'][$oid]['displayName'] ?? $s['displayName'] ?? $oid;
                $body .= '<option value="' . h($oid) . '"' . ($oid === $sid ? ' selected' : '') . '>' . h($odn === $oid ? 'node ' . $oid : $odn) . '</option>';
            }
            $body .= '</select>';
            $body .= '<input name="flow_' . $idx . '_displayName" value="' . h($dn === $sid ? '' : $dn) . '" placeholder="(opsional) label" style="max-width:180px">';
            $body .= '<label style="font-size:12px;white-space:nowrap"><input type=checkbox name="del_' . $idx . '" value=1> Hapus</label>';
            $body .= '</div>';
        }
        $body .= '</div>';
        $body .= '<input type=hidden name=flow_count value="' . count($order) . '"></div>';
        $body .= '<div class=card style="position:sticky;bottom:0;background:var(--surface-2);display:flex;gap:8px;flex-wrap:wrap"><button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning">🚀 Deploy</button> <span style="font-size:12px;color:var(--pill-text);align-self:center">Urutan ini dipakai untuk pertanyaan "Ke mana berikutnya?" dan ikut ter-export/sync.</span></div>';
        $body .= '</form>';
        $body .= '<script>
(function(){
  var list = document.getElementById("flowList");
  if(!list) return;
  var dragEl = null;
  function reindex(){
    var rows = Array.prototype.slice.call(list.querySelectorAll(".flow-row"));
    rows.forEach(function(r,n){
      r.setAttribute("data-idx", n);
      Array.prototype.forEach.call(r.querySelectorAll("[name^=flow_]"), function(el){
        el.name = el.name.replace(/^flow_\\d+_/, "flow_" + n + "_");
      });
      var del = r.querySelector("input[type=checkbox][name^=del_]");
      if(del) del.name = "del_" + n;
      var num = r.querySelector(".flow-num");
      if(num) num.textContent = (n + 1) + ".";
    });
    var cnt = document.querySelector("[name=flow_count]");
    if(cnt) cnt.value = rows.length;
  }
  list.addEventListener("dragstart", function(e){
    var row = e.target.closest ? e.target.closest(".flow-row") : null;
    if(!row) return;
    dragEl = row; row.style.opacity = "0.5";
    try{ e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", "x"); }catch(x){}
  });
  list.addEventListener("dragend", function(e){
    var row = e.target.closest ? e.target.closest(".flow-row") : null;
    if(row) row.style.opacity = "";
    Array.prototype.forEach.call(list.querySelectorAll(".flow-row"), function(r){ r.style.borderColor = ""; });
    dragEl = null; reindex();
  });
  list.addEventListener("dragover", function(e){
    e.preventDefault();
    if(!dragEl) return;
    var row = e.target.closest ? e.target.closest(".flow-row") : null;
    if(!row || row === dragEl) return;
    var rect = row.getBoundingClientRect();
    if((e.clientY - rect.top) > rect.height / 2) list.insertBefore(dragEl, row.nextSibling);
    else list.insertBefore(dragEl, row);
  });
  list.addEventListener("drop", function(e){ e.preventDefault(); reindex(); });
})();
function flowThumb(sel){
  var row = sel.closest ? sel.closest(".flow-row") : null; if(!row) return;
  var m = location.search.match(/[?&]id=(\\d+)/); var tid = m ? m[1] : "";
  var sid = encodeURIComponent(sel.value);
  var img = row.querySelector(".flow-thumb img");
  var link = row.querySelector(".flow-thumb");
  if(img) img.src = "?a=thumb&id=" + tid + "&scene=" + sid;
  if(link) link.href = "?a=panoview&id=" + tid + "&scene=" + sid;
}
</script>';
        layout('Navigation Flow — ' . $tour['name'], $body); exit;
    }
    /* ================================================================
     * PERCAKAPAN SUARA (per-tur) — mode: off | demo | realtime
     * Konfigurasi ditulis ke <slug>/voice.json lalu di-build oleh
     * build_files() ke package config/tour.json['voice']. Migrasi terpisah
     * dari runtime sync (sync_runtime_all men-skip config/).
     * ================================================================ */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveVoice') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $dir = tour_dir($tour['slug']);
        $mode = (string)($_POST['mode'] ?? 'off');
        if (!in_array($mode, ['off', 'demo', 'realtime'], true)) $mode = 'off';
        // Pertahankan flag realtime yang sudah aktif (Studio belum punya UI gateway).
        $prevVoice = read_json($dir . '/voice.json', []);
        $settings = [
            'version' => 2,
            'mode' => $mode,
            'realtime_configured' => $mode === 'realtime' ? true : (bool)($prevVoice['realtime_configured'] ?? false),
            'provider' => 'gemini-live',
            'model' => 'gemini-2.5-flash-native-audio-latest',
            'voice' => in_array((string)($_POST['voice'] ?? 'Kore'), ['Kore', 'Aoede', 'Charon', 'Fenrir', 'Puck'], true) ? (string)$_POST['voice'] : 'Kore',
            'language' => trim((string)($_POST['language'] ?? 'id')) ?: 'id',
            'idle_timeout' => max(15, min(600, (int)($_POST['idle_timeout'] ?? 60))),
            'session_duration' => max(30, min(3600, (int)($_POST['session_duration'] ?? 300))),
            'max_concurrent' => max(1, min(10, (int)($_POST['max_concurrent'] ?? 2))),
            'allowedOrigin' => trim((string)($_POST['allowedOrigin'] ?? '')),
            'answerPolicy' => in_array((string)($_POST['answerPolicy'] ?? 'curated'), ['curated', 'knowledge'], true) ? (string)$_POST['answerPolicy'] : 'curated',
        ];
        rev_check($dir . '/voice.json', (string)($_POST['rev_voice'] ?? ''));
        write_json($dir . '/voice.json', $settings);
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'save_generate') { build_files($tour); header('Location: ?a=voice&id=' . $tour['id'] . '&saved=1&generated=1'); exit; }
        if ($do === 'save_deploy') { build_files($tour); deploy_package($tour); header('Location: ?a=voice&id=' . $tour['id'] . '&saved=1&deployed=1'); exit; }
        header('Location: ?a=voice&id=' . $tour['id'] . '&saved=1'); exit;
    }
    if ($action === 'voice') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) { layout('Tour hilang', '<p>Tour tidak ditemukan.</p>'); exit; }
        $settings = tour_voice_settings($tour['slug']);
        $saved = isset($_GET['saved']); $generated = isset($_GET['generated']); $deployed = isset($_GET['deployed']);
        $msg = '';
        if ($saved) $msg .= '<div class=card style="border-color:var(--success)"><p class=ok>✓ Pengaturan Percakapan Suara disimpan.</p>' . ($generated ? '<p class=ok>✓ Package digenerate.</p>' : '') . ($deployed ? '<p class=ok>✓ Deploy ke export selesai.</p>' : '') . '</div>';
        $modeDesc = [
            'off' => 'Nonaktif — tombol "Mulai ngobrol" tidak ditampilkan. Mode teks tetap berjalan.',
            'demo' => 'Demo browser — SpeechRecognition + speechSynthesis + tool deterministik.',
            'realtime' => 'Gemini Live — adapter native audio tersedia, tetapi aktivasi memerlukan ephemeral-token API atau proxy WebSocket server. Navigasi dan fakta tetap divalidasi tool lokal.',
        ];
        $sel = static fn(string $v) => ($settings['mode'] === $v ? ' selected' : '');
        $body = $msg;
        $body .= '<div class=card><h2>Percakapan Suara — ' . h($tour['name']) . '</h2><p class=muted>Konfigurasi mode suara per tur. Skema konfigurasi package: <b>v' . PACKAGE_CONFIG_SCHEMA_VERSION . '</b> · runtime: <b>' . h(RUNTIME_VERSION) . '</b>. Migrasi terpisah dari runtime sync (config/ tidak disinkronkan oleh <code>sync_runtime_all</code>).</p>';
        $body .= '<p><a class=btn href="?a=view&id=' . (int)$tour['id'] . '">← Kembali</a></p></div>';
        $body .= '<form method=post action="?a=saveVoice&id=' . (int)$tour['id'] . '">' . rev_field(tour_dir($tour['slug']) . '/voice.json');
        $body .= '<div class=card><h3>Aktivasi</h3><p class=muted>Pilih mode. Untuk pilot tanpa API key berbayar, gunakan <b>Demo browser</b>.</p>';
        foreach (['off' => 'Nonaktif', 'demo' => 'Demo browser', 'realtime' => 'Gemini Live (menunggu gateway aman)'] as $mv => $ml) {
            $body .= '<label style="display:block;margin:6px 0"><input type=radio name=mode value="' . $mv . '"' . $sel($mv) . '> <b>' . $ml . '</b><br><span class=muted style="font-size:12px">' . $modeDesc[$mv] . '</span></label>';
        }
        $body .= '</div>';
        $body .= '<div class=card><h3>Percakapan</h3>';
        $body .= '<p class=muted>Provider realtime: <b>Gemini Live</b> · model: <code>gemini-2.5-flash-native-audio-latest</code>.</p>';
        $body .= '<label style="font-size:12px;color:var(--pill-text)">Suara Gemini</label><select name=voice>';
        foreach (['Kore', 'Aoede', 'Charon', 'Fenrir', 'Puck'] as $voiceName) $body .= '<option value="' . $voiceName . '"' . ($settings['voice'] === $voiceName ? ' selected' : '') . '>' . $voiceName . '</option>';
        $body .= '</select>';
        $body .= '<label style="font-size:12px;color:var(--pill-text)">Bahasa awal</label><select name=language><option value=id' . ($settings['language'] === 'id' ? ' selected' : '') . '>Indonesia</option><option value=en' . ($settings['language'] === 'en' ? ' selected' : '') . '>English</option></select>';
        $body .= '<label style="font-size:12px;color:var(--pill-text)">Kebijakan jawaban</label><select name=answerPolicy><option value=curated' . ($settings['answerPolicy'] === 'curated' ? ' selected' : '') . '>Kurasi (G01–G20)</option><option value=knowledge' . ($settings['answerPolicy'] === 'knowledge' ? ' selected' : '') . '>Knowledge + retrieval</option></select>';
        $body .= '</div>';
        $body .= '<div class=card><h3>Penggunaan (pilot)</h3>';
        $body .= '<label style="font-size:12px;color:var(--pill-text)">Idle timeout (detik)</label><input type=number name=idle_timeout min=15 max=600 value="' . (int)$settings['idle_timeout'] . '">';
        $body .= '<label style="font-size:12px;color:var(--pill-text)">Durasi target sesi (detik)</label><input type=number name=session_duration min=30 max=3600 value="' . (int)$settings['session_duration'] . '">';
        $body .= '<label style="font-size:12px;color:var(--pill-text)">Sesi paralel maks</label><input type=number name=max_concurrent min=1 max=10 value="' . (int)$settings['max_concurrent'] . '">';
        $body .= '</div>';
        $body .= '<div class=card><h3>Deployment</h3>';
        $body .= '<label style="font-size:12px;color:var(--pill-text)">Allowed Origin (kosong = same-origin + localhost)</label><input name=allowedOrigin value="' . h($settings['allowedOrigin']) . '" placeholder="https://semarang.govirtual.id">';
        $body .= '<p class=muted style="font-size:12px">Rahasia provider tidak pernah ditulis ke browser. Endpoint server menerbitkan token Gemini singkat, satu kali pakai.</p>';
        $body .= '</div>';
        $body .= '<div class=card style="position:sticky;bottom:0;background:var(--surface-2);display:flex;gap:8px;flex-wrap:wrap"><button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning">🚀 Deploy</button> <span style="font-size:12px;color:var(--pill-text);align-self:center">Simpan hanya mengubah <code>' . h($tour['slug']) . '/voice.json</code>. Generate menulis ke package. Deploy menyalin ke export (target master ditolak oleh guard).</span></div>';
        $body .= '</form>';
        layout('Percakapan Suara — ' . $tour['name'], $body); exit;
    }
    if ($action === 'curated') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/curated-answers.json',['intents'=>[]]);
        $saved=isset($_GET['saved']); $gen=isset($_GET['generated']); $dep=isset($_GET['deployed']);
        $msg=''; if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Curated Questions disimpan.</p>'.($gen?'<p class=ok>✓ Package digenerate.</p>':'').($dep?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        $body=$msg.'<div class=card><h2>Curated Questions — '.h($tour['name']).'</h2><p class=muted>Pertanyaan prioritas yang dijawab sebelum AI pipeline. Edit aliases/keywords per lokasi.</p><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div>';
        $body.='<form method=post action="?a=saveCurated&id='.(int)$tour['id'].'">' . rev_field(tour_dir($tour['slug']).'/curated-answers.json');
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
    if ($action === 'curatedqa') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/curated-qa.json',['version'=>'1.0','tourId'=>$tour['slug'],'items'=>[]]);
        $saved=isset($_GET['saved']); $gen=isset($_GET['generated']); $dep=isset($_GET['deployed']);
        $msg=''; if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Curated Q&A disimpan.</p>'.($gen?'<p class=ok>✓ Package digenerate.</p>':'').($dep?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        $imported=isset($_GET['imported'])?(int)$_GET['imported']:0;
        $msg=''; if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Curated Q&A disimpan.</p>'.($gen?'<p class=ok>✓ Package digenerate.</p>':'').($dep?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        if($imported>0) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ '.$imported.' Q&A diimpor dari file.</p>'.($gen?'<p class=ok>✓ Package digenerate.</p>':'').($dep?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        $body=$msg.'<div class=card><h2>Curated Q&A — '.h($tour['name']).'</h2><p class=muted>Pertanyaan + jawaban tim. Jika user menulis pertanyaan cocok, jawaban langsung diberikan (tanpa AI). Tidak muncul di panel rekomendasi AI Guide.</p><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div>';
        $body.='<div class=card><form method=post enctype="multipart/form-data" action="?a=importCuratedQA&id='.(int)$tour['id'].'" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">'.rev_field(tour_dir($tour['slug']).'/curated-qa.json').'<div><label style="font-size:12px;color:var(--pill-text)">Import Excel/CSV (kolom: Question, Answer, Aliases, Keywords)</label><input type=file name=qa_file accept=".xlsx,.csv" required></div><button type=submit name="do" value="save" class="btn btn-primary">📥 Import</button> <button type=submit name="do" value="save_generate" class="btn btn-success">📥 Import + Generate</button> <button type=submit name="do" value="save_deploy" class="btn btn-warning">📥 Import + Deploy</button></form><p class=muted style="margin-top:8px">Import akan menimpa seluruh isi Curated Q&A saat ini ('.count($data['items']??[]).' items).</p></div>';
        $body.='<form method=post action="?a=saveCuratedQA&id='.(int)$tour['id'].'">' . rev_field(tour_dir($tour['slug']).'/curated-qa.json');
        $body.='<div style="display:grid;gap:12px">';
        foreach(($data['items']??[]) as $idx=>$it){
            $body.='<div class=card style="border-left:4px solid var(--ai-accent)"><div style="display:flex;justify-content:space-between;align-items:center"><strong>'.h($it['id']??'QA'.($idx+1)).'</strong> <label style="font-size:12px"><input type=checkbox name="del_'.$idx.'" value=1> Hapus</label></div>';
            $body.='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px"><div><label style="font-size:12px;color:var(--pill-text)">ID</label><input name="qa_'.$idx.'_id" value="'.h($it['id']??'').'"></div><div><label style="font-size:12px;color:var(--pill-text)">Question</label><input name="qa_'.$idx.'_question" value="'.h($it['question']??'').'"></div></div>';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Answer</label><textarea name="qa_'.$idx.'_answer" rows=3 style="font-size:13px">'.h($it['answer']??'').'</textarea>';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Aliases (pisah koma)</label><input name="qa_'.$idx.'_aliases" value="'.h(implode(', ', $it['aliases']??[])).'" placeholder="alias1, alias2">';
            $body.='<label style="font-size:12px;color:var(--pill-text)">Keywords (pisah koma)</label><input name="qa_'.$idx.'_keywords" value="'.h(implode(', ', $it['keywords']??[])).'" placeholder="kata kunci untuk pencocokan">';
            $body.='</div>';
        }
        $body.='</div><input type=hidden name=qa_count value="'.count($data['items']??[]).'">';
        $body.='<div class=card style="position:sticky;bottom:0;background:var(--surface-2);z-index:5;display:flex;gap:8px;flex-wrap:wrap"><button type="submit" name="add_qa" value="1" class="btn btn-ghost">+ Tambah Q&A</button> <button type="submit" name="do" value="save" class="btn btn-primary">💾 Simpan</button> <button type="submit" name="do" value="save_generate" class="btn btn-success">💾 + Generate</button> <button type="submit" name="do" value="save_deploy" class="btn btn-warning" onclick="return confirm(\'Simpan, generate, deploy?\')">🚀 Simpan + Deploy</button></div></form>';
        layout('Curated Q&A — '.$tour['name'],$body); exit;
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
            $entries[]=['id'=>$id,'title'=>$title,'aliases'=>$aliases,'type'=>$type,'content'=>$content];
        }
        // Gabung ke entry lama: sceneIds, tags, categories, provenance, dan terjemahan EN tetap dipertahankan.
        $oldEntries=(array)($data['entries'] ?? []);
        $oldById=[]; foreach($oldEntries as $oe) if(is_array($oe) && isset($oe['id'])) $oldById[(string)$oe['id']]=$oe;
        $entries=merge_edited($oldEntries, $entries, ['tags'=>['manual'],'sceneIds'=>[],'source'=>'ai-generator','sourceType'=>'manual','provenance'=>'manual']);
        foreach($entries as &$en){
            $en['entityId']=$en['entityId'] ?? $en['id'];
            $en['sourceId']=$en['sourceId'] ?? $en['id'];
            if(!isset($oldById[(string)$en['id']])){
                $en['categories']=[$en['type']];
                $en['translations']=['id'=>['title'=>$en['title'],'content'=>$en['content']],'en'=>['title'=>$en['title'],'content'=>$en['content']]];
            } else {
                $tr=is_array($en['translations'] ?? null) ? $en['translations'] : [];
                $tr['id']=['title'=>$en['title'],'content'=>$en['content']];
                $en['translations']=$tr;
            }
        }
        unset($en);
        if(isset($_POST['add_entry'])) $entries[]=['id'=>'manual:'.slugify('entry baru '.(count($entries)+1)),'entityId'=>'manual:entry','title'=>'Judul Baru','aliases'=>[],'type'=>'information','categories'=>['informasi'],'tags'=>['manual'],'sceneIds'=>[],'content'=>'Isi konten...','source'=>'ai-generator','provenance'=>'manual','translations'=>['id'=>['title'=>'Judul Baru','content'=>'Isi konten...']]];
        $data['entries']=$entries; $data['updatedAt']=date('c'); rev_check($dir.'/knowledge.json', (string)($_POST['rev_knowledge'] ?? '')); write_json($dir.'/knowledge.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=knowledge&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); $b=deploy_package($tour); header('Location: ?a=knowledge&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=knowledge&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'knowledge') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/knowledge.json',['entries'=>[]]);
        $saved=isset($_GET['saved']); $gen=isset($_GET['generated']); $dep=isset($_GET['deployed']);
        $msg=''; if($saved) $msg.='<div class=card style="border-color:var(--success)"><p class=ok>✓ Knowledge disimpan.</p>'.($gen?'<p class=ok>✓ Package digenerate.</p>':'').($dep?'<p class=ok>✓ Deploy selesai.</p>':'').'</div>';
        $body=$msg.'<div class=card><h2>Knowledge — '.h($tour['name']).' ('.count($data['entries']??[]).' entries)</h2><p class=muted>Judul + aliases dipakai untuk pencocokan pertanyaan. Content dipakai AI untuk jawaban.</p><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div>';
        $body.='<form method=post action="?a=saveKnowledge&id='.(int)$tour['id'].'">' . rev_field(tour_dir($tour['slug']).'/knowledge.json');
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
            $topics[]=['id'=>$id,'title'=>$title,'aliases'=>$aliases,'category'=>$cat,'content'=>$content];
        }
        // Gabung ke topik lama; 'items' dipertahankan. Hanya item "Info" tunggal (hasil otomatis) yang disegarkan.
        $topics=merge_edited((array)($data['topics'] ?? []), $topics);
        foreach($topics as &$tp){
            $its=$tp['items'] ?? null;
            if(!is_array($its) || !$its) $tp['items']=[['label'=>'Info','detail'=>(string)($tp['content'] ?? '')]];
            elseif(count($its)===1 && is_array($its[0]) && ($its[0]['label'] ?? '')==='Info') $tp['items'][0]['detail']=(string)($tp['content'] ?? '');
        }
        unset($tp);
        if(isset($_POST['add_topic'])) $topics[]=['id'=>'topic-'.(count($topics)+1),'title'=>'Topik Baru','aliases'=>[],'category'=>'umum','content'=>'Isi tips...','items'=>[]];
        $data['topics']=$topics; rev_check($dir.'/visitor-tips.json', (string)($_POST['rev_visitor-tips'] ?? '')); write_json($dir.'/visitor-tips.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=tips&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); deploy_package($tour); header('Location: ?a=tips&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=tips&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'tips') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/visitor-tips.json',['topics'=>[]]);
        $saved=isset($_GET['saved']); $msg=$saved?'<div class=card style="border-color:var(--success)"><p class=ok>✓ Visitor Tips disimpan.</p></div>':'';
        $body=$msg.'<div class=card><h2>Visitor Tips — '.h($tour['name']).'</h2><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div><form method=post action="?a=saveTips&id='.(int)$tour['id'].'">' . rev_field(tour_dir($tour['slug']).'/visitor-tips.json');
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
        $items=merge_edited((array)($data['items'] ?? []), $items);
        if(isset($_POST['add_item'])) $items[]=['id'=>'nearby-'.(count($items)+1),'name'=>'Tempat Baru','category'=>'wisata','address'=>'','distance'=>'','travelTime'=>'','aliases'=>[]];
        $data['items']=$items; rev_check($dir.'/nearby.json', (string)($_POST['rev_nearby'] ?? '')); write_json($dir.'/nearby.json',$data);
        $do=(string)($_POST['do']??''); if($do==='save_generate'){ $r=build_files($tour); header('Location: ?a=nearby&id='.$tour['id'].'&saved=1&generated=1'); exit; } if($do==='save_deploy'){ build_files($tour); deploy_package($tour); header('Location: ?a=nearby&id='.$tour['id'].'&saved=1&deployed=1'); exit; }
        header('Location: ?a=nearby&id='.$tour['id'].'&saved=1'); exit;
    }
    if ($action === 'nearby') {
        $tour=get_tour((int)$_GET['id']); if(!$tour){ layout('Tour hilang','<p>Tour tidak ditemukan.</p>'); exit; }
        $data=read_json(tour_dir($tour['slug']).'/nearby.json',['items'=>[]]);
        $saved=isset($_GET['saved']); $msg=$saved?'<div class=card style="border-color:var(--success)"><p class=ok>✓ Nearby disimpan.</p></div>':'';
        $body=$msg.'<div class=card><h2>Nearby — '.h($tour['name']).'</h2><p><a class=btn href="?a=view&id='.(int)$tour['id'].'">← Kembali</a></p></div><form method=post action="?a=saveNearby&id='.(int)$tour['id'].'">' . rev_field(tour_dir($tour['slug']).'/nearby.json');
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
    if (in_array($action, ['users', 'userCreate', 'userUpdate', 'userReset', 'audit'], true)) {
        require_admin();
        if ($action === 'audit') { render_audit_page(); exit; }
        $flash = ''; $err = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { try { $flash = process_user_action($action); } catch (RuntimeException $e) { $err = $e->getMessage(); } }
        render_users_page($flash, $err); exit;
    }
    if ($action === 'theme') { require_editor(); render_theme_page(); exit; }
    if ($action === 'saveTheme' && $_SERVER['REQUEST_METHOD'] === 'POST') { require_editor(); $msg = process_save_theme(); render_theme_page($msg); exit; }
    if ($action === 'themeCss') {
        header('Content-Type: application/json; charset=utf-8');
        $th = theme_sanitize($_GET);
        echo json_encode(['css' => theme_css($th, '#themePreview'), 'fonts' => theme_font_urls($th), 'contrast' => theme_contrast($th['accent'], $th['surface'])]); exit;
    }
    if ($action === 'health') { $hc = trim((string)($_GET['city'] ?? '')); render_health_page('', $hc !== '' ? city_slugify($hc) : ''); exit; }
    if ($action === 'healthBase' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try { health_save_base((string)($_POST['city'] ?? ''), (string)($_POST['base'] ?? '')); $flash = 'Alamat publik kota disimpan.'; }
        catch (RuntimeException $e) { $flash = ''; $GLOBALS['error'] = '<div class="card bad"><p>' . h($e->getMessage()) . '</p></div>'; }
        header('Location: ?a=health'); exit;
    }
    if ($action === 'healthEnqueue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try { $batch = health_enqueue_scope((string)($_POST['scope'] ?? 'all'), (string)($_POST['ref'] ?? ''), !empty($_POST['ui'])); }
        catch (RuntimeException $e) { header('Location: ?a=health&error=' . urlencode($e->getMessage())); exit; }
        job_spawn_worker();
        header('Location: ?a=jobs&batch=' . urlencode($batch)); exit;
    }
    if ($action === 'jobs') { render_jobs_page((string)($_GET['batch'] ?? '')); exit; }
    if ($action === 'jobEnqueue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $batch = job_enqueue((string)($_POST['type'] ?? ''), (array)($_POST['tour'] ?? []), (string)(current_user()['username'] ?? ''));
        job_spawn_worker();
        header('Location: ?a=jobs&batch=' . urlencode($batch)); exit;
    }
    if ($action === 'jobsStatus') { header('Content-Type: application/json; charset=utf-8'); echo json_encode(job_batch_summary((string)($_GET['batch'] ?? '')), JSON_UNESCAPED_UNICODE); exit; }
    if ($action === 'bulkImport') { render_bulk_import($_SERVER['REQUEST_METHOD'] === 'POST' ? process_bulk_import() : ''); exit; }
    // ---- Daftar scene (JSON) untuk drawer Uji Chat ----
    if ($action === 'sceneList') {
        header('Content-Type: application/json; charset=utf-8');
        $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) { echo json_encode(['scenes' => []]); exit; }
        $dir = tour_dir($tour['slug']); $parsed = read_json($dir . '/parsed.json', []); $si = read_json($dir . '/scene-identity.json', ['scenes' => []]); $out = [];
        foreach (($parsed['scenes'] ?? []) as $sc) { $sid = (string)($sc['id'] ?? ''); if ($sid === '') continue; $label = (string)($si['scenes'][$sid]['displayName'] ?? $sc['displayName'] ?? $sc['name'] ?? $sid); $out[] = ['id' => $sid, 'label' => $label . ($label !== $sid ? ' (' . $sid . ')' : '')]; }
        echo json_encode(['scenes' => $out], JSON_UNESCAPED_UNICODE); exit;
    }
    // ---- Riwayat Perubahan: lihat & pulihkan versi sebelumnya dari .history ----
    if ($action === 'history' || $action === 'restoreHistory') {
        $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $tid = (int)$tour['id']; $dir = tour_dir($tour['slug']); $hdir = $dir . '/.history';
        $editable = ['curated-answers', 'curated-qa', 'knowledge', 'scene-identity', 'visitor-tips', 'nearby', 'recommended-questions', 'flow', 'voice'];
        $snapRe = '/^([a-z-]+)-(\d{8})-(\d{6})-(\d{3})\.json$/';
        if ($action === 'restoreHistory') {
            $file = basename((string)($_POST['snapshot'] ?? ''));
            if (!preg_match($snapRe, $file, $m) || !in_array($m[1], $editable, true)) throw new RuntimeException('Versi riwayat tidak valid.');
            $src = $hdir . '/' . $file;
            if (!is_file($src)) throw new RuntimeException('Versi riwayat sudah tidak ada.');
            $data = json_decode((string)file_get_contents($src), true);
            if (!is_array($data)) throw new RuntimeException('Versi riwayat rusak dan tidak bisa dipulihkan.');
            // write_json menyimpan versi yang sekarang ke riwayat dulu, jadi pemulihan ini bisa dibatalkan juga.
            write_json($dir . '/' . $m[1] . '.json', $data);
            header('Location: ?a=history&id=' . $tid . '&restored=' . urlencode($m[1])); exit;
        }
        $labels = ['curated-answers' => 'Intent & jawaban kurasi', 'curated-qa' => 'Tanya Jawab Terkurasi', 'knowledge' => 'Pengetahuan AI', 'scene-identity' => 'Identitas scene', 'visitor-tips' => 'Tips pengunjung', 'nearby' => 'Tempat sekitar', 'recommended-questions' => 'Rekomendasi pertanyaan', 'flow' => 'Alur navigasi', 'voice' => 'Percakapan suara'];
        $rows = []; foreach ($editable as $stem) foreach (glob($hdir . '/' . $stem . '-*.json') ?: [] as $f) { $b = basename($f); if (preg_match($snapRe, $b, $m)) $rows[] = ['stem' => $stem, 'file' => $b, 'at' => $m[2] . $m[3], 'size' => filesize($f), 'path' => $f]; }
        usort($rows, fn($a, $b) => strcmp($b['at'], $a['at']));
        $html = '';
        if (isset($_GET['restored'])) $html .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Versi dipulihkan. Versi sebelumnya tetap tersimpan di riwayat, jadi bisa dikembalikan lagi.</p></div>';
        $html .= '<div class="card"><h2 style="margin-top:0">Riwayat Perubahan — ' . h($tour['name']) . '</h2><p class="muted">Setiap kali konten disimpan, versi sebelumnya dicatat di sini (20 versi terakhir per bagian). Pulihkan untuk kembali ke versi tersebut; versi yang sedang berjalan juga ikut dicatat, jadi aman.</p>';
        if (!$rows) $html .= '<div class="empty"><h3>Belum ada riwayat</h3><p>Riwayat muncul setelah Anda menyimpan perubahan pada salah satu editor.</p></div>';
        else {
            $html .= '<table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr style="text-align:left;color:var(--text-muted)"><th style="padding:8px">Bagian</th><th>Disimpan pada</th><th>Ukuran</th><th></th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $when = DateTime::createFromFormat('YmdHis', $r['at']);
                $html .= '<tr style="border-top:1px solid var(--border)"><td style="padding:8px">' . h($labels[$r['stem']] ?? $r['stem']) . '</td><td>' . ($when ? h($when->format('d M Y H:i:s')) : h($r['at'])) . '</td><td>' . number_format($r['size'] / 1024, 1) . ' KB</td>'
                    . '<td style="text-align:right"><form method="post" action="?a=restoreHistory&id=' . $tid . '" style="margin:0" onsubmit="return confirm(\'Pulihkan versi ini? Versi yang sekarang tetap disimpan di riwayat.\')"><input type="hidden" name="snapshot" value="' . h($r['file']) . '"><button class="btn btn-ghost btn-sm" type="submit">Pulihkan</button></form></td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</div>';
        layout('Riwayat Perubahan', $html); exit;
    }
    // ---- Publikasi: status konten → package → terpasang, di satu tempat ----
    if ($action === 'publish') {
        $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $tid = (int)$tour['id']; $dir = tour_dir($tour['slug']); $st = studio_tour_stats($tour);
        $contentFiles = ['parsed','scene-identity','knowledge','curated-answers','curated-qa','recommended-questions','flow','visitor-tips','nearby','voice'];
        $contentAt = 0; foreach ($contentFiles as $cf) { $m = is_file("$dir/$cf.json") ? (int)filemtime("$dir/$cf.json") : 0; if ($m > $contentAt) $contentAt = $m; }
        $pkgFile = $dir . '/package/govirtual-ai/config/tour.json';
        $pkgAt = is_file($pkgFile) ? (int)filemtime($pkgFile) : 0;
        $export = rtrim((string)$tour['export_path'], '/\\');
        $depFile = $export !== '' ? $export . '/govirtual-ai/config/tour.json' : '';
        $depAt = ($depFile !== '' && is_file($depFile)) ? (int)filemtime($depFile) : 0;
        $fmt = static fn(int $t): string => $t ? date('d M Y H:i', $t) : '—';
        $row = static fn(string $icon, string $label, string $detail, string $cls): string => '<li style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border)"><span class="' . $cls . '" style="font-size:18px;line-height:1.3">' . $icon . '</span><div><b>' . h($label) . '</b><div class="muted" style="font-size:13px">' . $detail . '</div></div></li>';
        $stale = $pkgAt > 0 && $contentAt > $pkgAt;
        $steps = '<ul style="list-style:none;margin:0;padding:0">';
        $steps .= $row($st['scenes'] > 0 ? '✓' : '!', '1. Konten tour', $st['scenes'] > 0 ? (int)$st['scenes'] . ' scene · ' . (int)$st['knowledgeTotal'] . ' knowledge · ' . (int)$st['intents'] . ' intent · diubah terakhir ' . h($fmt($contentAt)) : 'Belum ada scene. <a href="?a=import&id=' . $tid . '">Import pano dulu</a>.', $st['scenes'] > 0 ? 'ok' : 'warn');
        $steps .= $row($pkgAt === 0 ? '!' : ($stale ? '!' : '✓'), '2. Package', $pkgAt === 0 ? 'Belum pernah di-generate.' : ($stale ? 'Ada perubahan konten setelah package terakhir dibuat (' . h($fmt($pkgAt)) . '). <b>Generate ulang</b> agar perubahan ikut.' : 'Terbaru · dibuat ' . h($fmt($pkgAt))), ($pkgAt === 0 || $stale) ? 'warn' : 'ok');
        $needDeploy = $pkgAt > 0 && ($depAt === 0 || $pkgAt > $depAt);
        $steps .= $row($depAt === 0 ? '!' : ($needDeploy ? '!' : '✓'), '3. Terpasang di lokasi', $export === '' ? 'Folder export belum diisi di <a href="?a=edit&id=' . $tid . '">pengaturan tour</a>.' : ($depAt === 0 ? 'Belum terpasang di <code>' . h($export) . '</code>.' : ($needDeploy ? 'Versi terpasang (' . h($fmt($depAt)) . ') lebih lama dari package. <b>Deploy</b> untuk memperbarui.' : 'Sinkron dengan package · dipasang ' . h($fmt($depAt)))), ($depAt === 0 || $needDeploy) ? 'warn' : 'ok');
        $steps .= '</ul>';
        $issuesHtml = '';
        if ($st['issues']) { $issuesHtml = '<div class="card"><h3>Perlu diperiksa sebelum publikasi</h3><ul>'; foreach (array_slice($st['issues'], 0, 10) as $is) $issuesHtml .= '<li>' . h(is_array($is) ? (string)($is['message'] ?? $is['msg'] ?? json_encode($is, JSON_UNESCAPED_UNICODE)) : (string)$is) . '</li>'; $issuesHtml .= '</ul></div>'; }
        $backups = $export !== '' && is_dir($export . '/.ai-generator-backup') ? (glob($export . '/.ai-generator-backup/*', GLOB_ONLYDIR) ?: []) : [];
        rsort($backups);
        $bk = $backups ? '<p class="muted">' . count($backups) . ' backup tersimpan di <code>' . h($export) . '/.ai-generator-backup/</code>. Terbaru: <code>' . h(basename($backups[0])) . '</code></p>' : '<p class="muted">Belum ada backup (muncul setelah deploy pertama).</p>';
        $actions = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">'
            . '<a class="btn' . (($pkgAt === 0 || $stale) ? ' btn-primary' : '') . '" href="?a=generate&id=' . $tid . '">Generate package</a>'
            . '<a class="btn' . ((!$stale && $pkgAt > 0 && ($depAt === 0 || $needDeploy)) ? ' btn-primary' : '') . '" href="?a=deploy&id=' . $tid . '">Deploy ke lokasi</a>'
            . '<form method="post" action="?a=resync&id=' . $tid . '" onsubmit="return confirm(\'Import ulang pano/skin, generate package, lalu deploy? Package lama di-backup otomatis.\')" style="margin:0"><button class="btn btn-ghost" type="submit">Import ulang + Generate + Deploy</button></form>'
            . '<a class="btn btn-ghost" href="?a=test&id=' . $tid . '">Uji AI Guide →</a></div>';
        layout('Publikasi', '<div class="card"><h2 style="margin-top:0">Publikasi — ' . h($tour['name']) . '</h2><p class="muted">Urutannya: konten → package → terpasang. Menyimpan di editor <b>tidak</b> otomatis mempublikasikan.</p>' . $steps . $actions . '</div>' . $issuesHtml . '<div class="card"><h3>Backup deploy</h3>' . $bk . '</div>');
        exit;
    }
    // ---- Uji AI Guide: tanya langsung ke backend guide per scene ----
    if ($action === 'test') {
        $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        $tid = (int)$tour['id']; $dir = tour_dir($tour['slug']);
        $parsed = read_json($dir . '/parsed.json', []); $si = read_json($dir . '/scene-identity.json', ['scenes' => []]);
        $opts = '';
        foreach (($parsed['scenes'] ?? []) as $sc) {
            $sid = (string)($sc['id'] ?? ''); if ($sid === '') continue;
            $label = (string)($si['scenes'][$sid]['displayName'] ?? $sc['displayName'] ?? $sc['name'] ?? $sid);
            $opts .= '<option value="' . h($sid) . '">' . h($label) . ($label !== $sid ? ' (' . h($sid) . ')' : '') . '</option>';
        }
        $rec = read_json($dir . '/recommended-questions.json', []); $quick = [];
        foreach (array_merge((array)($rec['state1']['starters'] ?? []), (array)($rec['state1']['themes'] ?? [])) as $qq) { $t = is_array($qq) ? (string)($qq['question'] ?? $qq['text'] ?? $qq['label'] ?? '') : (string)$qq; if ($t !== '') $quick[] = $t; }
        $quick = array_slice(array_values(array_unique($quick)), 0, 8);
        $chips = ''; foreach ($quick as $qq) $chips .= '<button type="button" class="btn btn-ghost btn-sm" data-q="' . h($qq) . '">' . h($qq) . '</button> ';
        $body = '<div class="card"><h2 style="margin-top:0">Uji AI Guide — ' . h($tour['name']) . '</h2>'
            . '<p class="muted">Pertanyaan dikirim ke backend guide yang sama dengan produksi. Jika tour belum di-deploy, jawaban memakai server pusat sebagai cadangan (ditandai di bawah).</p>'
            . ($opts === '' ? '<p class="warn">Belum ada scene. <a href="?a=import&id=' . $tid . '">Import pano dulu</a>.</p>' : '')
            . '<label for="t_scene">Posisi pengunjung (scene)</label><select id="t_scene">' . $opts . '</select>'
            . ($chips !== '' ? '<p class="muted" style="margin:10px 0 4px;font-size:12px">Pertanyaan rekomendasi tour ini:</p><div style="display:flex;gap:6px;flex-wrap:wrap">' . $chips . '</div>' : '')
            . '<form id="t_form" style="display:flex;gap:8px;margin-top:12px" onsubmit="return false"><input id="t_q" placeholder="Ketik pertanyaan pengunjung…" style="flex:1" autocomplete="off"><button class="btn btn-primary" id="t_send" type="submit">Kirim</button></form></div>'
            . '<div id="t_log" class="card" aria-live="polite"><p class="muted" id="t_empty">Belum ada percakapan. Pilih scene lalu ajukan pertanyaan.</p></div>'
            . '<script>(function(){'
            . 'var tid=' . $tid . ',log=document.getElementById("t_log"),q=document.getElementById("t_q"),sc=document.getElementById("t_scene"),btn=document.getElementById("t_send");'
            . 'function esc(s){var d=document.createElement("div");d.textContent=(s==null?"":String(s));return d.innerHTML;}'
            . 'function add(html){var e=document.getElementById("t_empty");if(e)e.remove();var d=document.createElement("div");d.style.cssText="padding:10px 0;border-bottom:1px solid var(--border)";d.innerHTML=html;log.appendChild(d);return d;}'
            . 'function ask(text){text=(text||"").trim();if(!text||!sc.value)return;btn.disabled=true;var t0=Date.now();add("<b>Pengunjung</b> <span class=muted>@ "+esc(sc.options[sc.selectedIndex].text)+"</span><div>"+esc(text)+"</div>");var w=add("<span class=muted>AI sedang menjawab…</span>");'
            . 'fetch("?a=testAnswer&id="+tid+"&scene="+encodeURIComponent(sc.value)+"&q="+encodeURIComponent(text),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){var ms=Date.now()-t0;'
            . 'if(d.ok){var m=[];m.push("provider: "+esc(d.provider||"?"));m.push(ms+" ms");if(d.deployed===false)m.push("<b class=warn>memakai server pusat (tour belum di-deploy)</b>");if(d.meta){Object.keys(d.meta).forEach(function(k){m.push(esc(k)+": "+esc(typeof d.meta[k]==="object"?JSON.stringify(d.meta[k]):d.meta[k]));});}'
            . 'w.innerHTML="<b>AI Guide</b><div style=\"white-space:pre-wrap\">"+esc(d.reply)+"</div><div class=muted style=\"font-size:12px;margin-top:4px\">"+m.join(" · ")+"</div>";}'
            . 'else{w.innerHTML="<span class=bad>Gagal: "+esc(d.error||"tidak diketahui")+"</span>";}}).catch(function(e){w.innerHTML="<span class=bad>Gagal menghubungi server: "+esc(e.message)+"</span>";}).finally(function(){btn.disabled=false;q.value="";q.focus();});}'
            . 'document.getElementById("t_form").addEventListener("submit",function(){ask(q.value);});'
            . 'document.querySelectorAll("[data-q]").forEach(function(b){b.addEventListener("click",function(){ask(b.getAttribute("data-q"));});});'
            . '})();</script>';
        layout('Uji AI Guide', $body); exit;
    }
    if ($action === 'generate') { $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') confirm_page('Generate package', '<p>Package AI Guide <b>' . h($tour['name']) . '</b> akan dibangun ulang dari konten terbaru. Ini <b>belum</b> memasang ke lokasi publik — pemasangan dilakukan lewat tombol Deploy.</p>', '?a=generate&id=' . (int)$tour['id'], 'Generate package', '?a=view&id=' . (int)$tour['id']);
        $r = build_files($tour); layout('Package generated', '<div class="card"><p class="ok">Package siap: <code>' . h($r['out']) . '</code></p><ul><li>' . implode('</li><li>', array_map('h', $r['files'])) . '</li></ul><p><a class="btn" href="?a=view&id=' . (int)$tour['id'] . '">Kembali</a></p></div>'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'saveKeys') {
        require_admin();
        if (!csrf_valid($_POST['csrf_token'] ?? null)) throw new RuntimeException('CSRF tidak valid. Muat ulang halaman lalu coba lagi.');
        // Validasi dulu, sebelum ada yang ditulis: provider utama/cadangan harus dikenal, dan penyedia baru harus lengkap.
        $newName = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', trim((string)($_POST['new_provider_name'] ?? ''))));
        $newKey = trim((string)($_POST['new_provider_key'] ?? ''));
        $newBase = rtrim(trim((string)($_POST['new_provider_base'] ?? '')), '/');
        $newModel = trim((string)($_POST['new_provider_model'] ?? ''));
        $newLabel = trim((string)($_POST['new_provider_label'] ?? ''));
        if ($newName !== '') {
            if (strlen($newName) < 2 || strlen($newName) > 30) throw new RuntimeException('Nama provider harus 2–30 huruf atau angka.');
            if (in_array($newName, ['ai', 'gemini', 'openrouter', 'groq', 'ollama'], true)) throw new RuntimeException('Nama "' . $newName . '" sudah dipakai provider bawaan. Pilih nama lain.');
            if ($newKey === '') throw new RuntimeException('Isi API key untuk penyedia baru, atau kosongkan nama provider.');
            if (!preg_match('#^https?://[^\s/]+#i', $newBase)) throw new RuntimeException('Base URL penyedia baru harus diawali http:// atau https:// (contoh: https://api.deepseek.com/v1).');
            if ($newModel === '') throw new RuntimeException('Isi nama model untuk penyedia baru (contoh: deepseek-chat).');
        }
        $allowedProviders = array_values(array_unique(array_merge(['gemini', 'openrouter', 'groq', 'ollama'], api_key_providers(), $newName !== '' ? [$newName] : [])));
        foreach (array_values(array_diff($allowedProviders, ['gemini', 'openrouter', 'groq', 'ollama'])) as $cp) {
            $base = trim((string)($_POST['set_' . provider_env_name($cp) . '_BASE_URL'] ?? ''));
            if ($base !== '' && !preg_match('#^https?://[^\s/]+#i', $base)) throw new RuntimeException('Base URL ' . $cp . ' harus diawali http:// atau https://.');
        }
        $primaryPosted = trim((string)($_POST['set_AI_PROVIDER'] ?? ''));
        if ($primaryPosted !== '' && !in_array($primaryPosted, $allowedProviders, true)) throw new RuntimeException('Penyedia utama "' . $primaryPosted . '" tidak dikenal.');
        foreach (array_filter(array_map('trim', explode(',', (string)($_POST['set_AI_FALLBACK_PROVIDERS'] ?? '')))) as $fb) {
            if (!in_array($fb, $allowedProviders, true)) throw new RuntimeException('Penyedia cadangan "' . $fb . '" tidak dikenal. Tambahkan dulu sebagai penyedia.');
        }
        // Existing blank replacement means preserve secret. Provider comes from DB, never client input.
        $postedIds = (array)($_POST['k_id'] ?? []);
        foreach ($postedIds as $i => $idStr) {
            $id = (int)$idStr; $existing = $id > 0 ? api_key_find($id) : null; if (!$existing) continue;
            if (isset($_POST['del_' . $i])) { api_key_delete($id); continue; }
            $label = trim((string)($_POST["k_{$i}_label"] ?? ''));
            $replacement = trim((string)($_POST["k_{$i}_key"] ?? ''));
            $active = isset($_POST["k_{$i}_active"]) ? 1 : 0;
            $prio = (int)($_POST["k_{$i}_priority"] ?? 0);
            api_key_save((string)$existing['provider'], $label, $replacement !== '' ? $replacement : (string)$existing['api_key'], $active, $prio, $id);
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
        // Penyedia baru (OpenAI-compatible) dari kartu "Tambah penyedia": key, base URL, dan model disimpan bersamaan.
        if ($newName !== '') {
            $maxPrio = 0; foreach (api_key_list($newName) as $r) $maxPrio = max($maxPrio, (int)$r['priority']);
            api_key_save($newName, $newLabel, $newKey, 1, $maxPrio + 1);
            setting_set(provider_env_name($newName) . '_BASE_URL', $newBase);
            setting_set(provider_env_name($newName) . '_MODEL', $newModel);
        }
        // General env settings
        foreach (['AI_PROVIDER','AI_FALLBACK_PROVIDERS','AI_REQUEST_TIMEOUT','AI_DEBUG','AI_REASONING_MODE','GEMINI_MODEL','GEMINI_FALLBACK_MODELS','OPENROUTER_MODEL','OPENROUTER_BASE_URL','GROQ_MODEL','OLLAMA_ENABLED','OLLAMA_URL','OLLAMA_MODEL','OLLAMA_EMBED_MODEL','OLLAMA_NUM_PREDICT','OLLAMA_TEMPERATURE','AI_WEB_SEARCH','AI_WEB_SEARCH_TIMEOUT','AI_WEB_SEARCH_PROVIDERS'] as $skey) {
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
    if ($action === 'playground') { render_playground(); exit; }
    if ($action === 'keys') {
        render_keys_page($error); exit;
    }
    if ($action === 'aihealth') {
        $db = db();
        $db->exec('CREATE TABLE IF NOT EXISTS ai_health_log (id INTEGER PRIMARY KEY AUTOINCREMENT, checked_at TEXT NOT NULL, provider TEXT NOT NULL, model TEXT NOT NULL, ok INTEGER NOT NULL, latency_ms INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL DEFAULT "")');
        $cfg = [];
        foreach ($db->query('SELECT skey, svalue FROM settings WHERE skey IN ("AI_PROVIDER","AI_FALLBACK_PROVIDERS")') as $r) $cfg[$r['skey']] = $r['svalue'];
        $lastRun = (string)$db->query('SELECT MAX(checked_at) FROM ai_health_log')->fetchColumn();
        $latest = [];
        if ($lastRun !== '') {
            $s = $db->prepare('SELECT provider, model, ok, latency_ms, error, checked_at FROM ai_health_log WHERE checked_at = ? ORDER BY provider, model');
            $s->execute([$lastRun]);
            $latest = $s->fetchAll(PDO::FETCH_ASSOC);
        }
        $lastOk = $db->query("SELECT provider || '|' || model AS k, MAX(CASE WHEN ok=1 THEN checked_at END) AS last_ok FROM ai_health_log GROUP BY provider, model")->fetchAll(PDO::FETCH_KEY_PAIR);
        $history = $db->query("SELECT substr(checked_at,1,10) AS d, provider, SUM(ok) AS okc, COUNT(*) - SUM(ok) AS failc FROM ai_health_log WHERE checked_at >= date('now','-14 days') GROUP BY d, provider ORDER BY d DESC, provider")->fetchAll(PDO::FETCH_ASSOC);
        $primary = strtolower($cfg['AI_PROVIDER'] ?? 'gemini');
        $primaryOkLatest = false;
        foreach ($latest as $r) if ($r['provider'] === $primary && (int)$r['ok'] === 1) $primaryOkLatest = true;
        $stale = $lastRun === '' || (time() - (int)strtotime($lastRun)) > 26 * 3600;
        if ($lastRun === '') $banner = '<div class="card" style="border-color:var(--warning)"><p class="warn">Belum pernah dicek. Klik "Cek sekarang" atau jadwalkan tugas harian.</p></div>';
        elseif ($stale) $banner = '<div class="card" style="border-color:var(--warning)"><p class="warn">Pengecekan terakhir ' . h($lastRun) . ' sudah lebih dari 26 jam. Jadwal harian mungkin tidak berjalan.</p></div>';
        elseif (!$primaryOkLatest) $banner = '<div class="card" style="border-color:var(--danger)"><p class="bad">Provider utama (' . h($primary) . ') GAGAL pada pengecekan terakhir. AI Guide akan memakai fallback.</p></div>';
        else $banner = '<div class="card" style="border-color:var(--success)"><p class="ok">Provider utama (' . h($primary) . ') OK pada ' . h($lastRun) . '.</p></div>';
        $body = '<div class="card"><h2>🩺 AI Health</h2><p class="muted">Uji hidup tiap API key dan model yang dipakai AI Guide, memakai jalur runtime produksi. Fallback saat ini: <code>' . h($primary) . ' → ' . h($cfg['AI_FALLBACK_PROVIDERS'] ?? '-') . '</code>. Dicek otomatis setiap hari lewat Windows Task Scheduler.</p>'
            . '<form method="post" action="?a=aihealthRun" style="margin-top:10px"><button type="submit" class="btn btn-primary">▶ Cek sekarang</button></form></div>'
            . $banner;
        $rows = '';
        foreach ($latest as $r) {
            $st = (int)$r['ok'] === 1 ? '<span class="ok">OK</span>' : '<span class="bad">GAGAL</span>';
            $rows .= '<tr><td>' . h($r['provider']) . '</td><td><code>' . h($r['model']) . '</code></td><td>' . $st . '</td><td>' . (int)$r['latency_ms'] . ' ms</td><td>' . h((string)($lastOk[$r['provider'] . '|' . $r['model']] ?? '')) . '</td><td class="muted" style="font-size:12px">' . h(mb_substr((string)$r['error'], 0, 160)) . '</td></tr>';
        }
        $body .= '<div class="card"><h3>Status terakhir' . ($lastRun !== '' ? ' — ' . h($lastRun) : '') . '</h3>' . ($rows !== '' ? '<table><tr><th>Provider</th><th>Model</th><th>Status</th><th>Latensi</th><th>OK terakhir</th><th>Error</th></tr>' . $rows . '</table>' : '<p class="muted">Belum ada data.</p>') . '</div>';
        $hRows = '';
        foreach ($history as $r) $hRows .= '<tr><td>' . h($r['d']) . '</td><td>' . h($r['provider']) . '</td><td class="ok">' . (int)$r['okc'] . '</td><td class="bad">' . (int)$r['failc'] . '</td></tr>';
        $body .= '<div class="card"><h3>Riwayat 14 hari</h3>' . ($hRows !== '' ? '<table><tr><th>Tanggal</th><th>Provider</th><th>OK</th><th>Gagal</th></tr>' . $hRows . '</table>' : '<p class="muted">Belum ada riwayat.</p>') . '</div>';
        layout('AI Health', $body); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'aihealthRun') {
        $php = PHP_BINARY ?: 'C:/xampp/php/php.exe';
        shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/_ai_health_check.php') . ' 2>&1');
        header('Location: ?a=aihealth'); exit;
    }
    if ($action === 'deploy') { $tour = get_tour((int)($_GET['id'] ?? 0)); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') confirm_page('Deploy AI Guide', '<p>AI Guide <b>' . h($tour['name']) . '</b> akan dipasang ke:</p><p><code>' . h((string)$tour['export_path']) . '</code></p><p class="muted">Package lama otomatis dicadangkan sebelum diganti, dan <code>index.html</code> di lokasi tersebut ikut diperbarui.</p>', '?a=deploy&id=' . (int)$tour['id'], 'Deploy sekarang', '?a=view&id=' . (int)$tour['id']);
        $backup = deploy_package($tour); layout('Deploy selesai', '<div class="card"><p class="ok">AI Guide terpasang ke <code>' . h($tour['export_path']) . '</code>.</p><p>Backup package lama: <code>' . h($backup) . '</code></p><p><a class="btn" href="?a=view&id=' . (int)$tour['id'] . '">Kembali</a></p></div>'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resync') {
        $tour = get_tour((int)$_GET['id']); if (!$tour) throw new RuntimeException('Tour tidak ditemukan.');
        tour_import_pano($tour);
        $r = build_files($tour);
        $backup = deploy_package($tour);
        header('Location: ?a=view&id=' . $tour['id'] . '&resynced=1'); exit;
    }
    /* ---- City Knowledge actions ---- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citySave') {
        $slug = city_slugify((string)($_POST['city'] ?? '')); if ($slug === '') throw new RuntimeException('Kota tidak valid.');
        $name = trim((string)($_POST['name'] ?? '')) ?: $slug;
        $m = read_json(city_dir($slug) . '/meta.json', []); $m['name'] = $name; if (isset($_POST['instructions'])) $m['instructions'] = trim((string)$_POST['instructions']); write_json(city_dir($slug) . '/meta.json', $m);
        $kb = city_knowledge($slug); $count = (int)($_POST['entry_count'] ?? 0); $entries = [];
        for ($i = 0; $i < $count; $i++) {
            if (isset($_POST["del_$i"])) continue;
            $id = trim((string)($_POST["entry_{$i}_id"] ?? '')); $title = trim((string)($_POST["entry_{$i}_title"] ?? '')); if ($id === '' && $title === '') continue;
            if ($id === '') $id = 'manual:' . city_slugify($title);
            $aliases = array_values(array_filter(array_map('trim', explode(',', (string)($_POST["entry_{$i}_aliases"] ?? '')))));
            $content = trim((string)($_POST["entry_{$i}_content"] ?? ''));
            $entries[] = ['id' => $id, 'title' => $title, 'aliases' => $aliases, 'content' => $content];
        }
        // Gabung ke entry lama (hasil impor sumber: source, url, tags, terjemahan EN tetap dipertahankan).
        $oldCityById = []; foreach ((array)($kb['entries'] ?? []) as $oe) if (is_array($oe) && isset($oe['id'])) $oldCityById[(string)$oe['id']] = $oe;
        $entries = merge_edited((array)($kb['entries'] ?? []), $entries, ['entityId' => '', 'type' => 'city-information', 'categories' => ['informasi kota'], 'tags' => ['manual'], 'sceneIds' => [], 'source' => $name, 'sourceType' => 'manual', 'provenance' => 'manual']);
        foreach ($entries as &$ce) {
            if (empty($ce['entityId'])) $ce['entityId'] = $ce['id'];
            if (!isset($oldCityById[(string)$ce['id']])) $ce['translations'] = ['id' => ['title' => $ce['title'], 'content' => $ce['content']], 'en' => ['title' => $ce['title'], 'content' => $ce['content']]];
            else { $tr = is_array($ce['translations'] ?? null) ? $ce['translations'] : []; $tr['id'] = ['title' => $ce['title'], 'content' => $ce['content']]; $ce['translations'] = $tr; }
        }
        unset($ce);
        if (isset($_POST['add_entry'])) $entries[] = ['id' => 'manual:' . city_slugify('judul baru ' . (count($entries) + 1)), 'entityId' => 'manual:entry', 'title' => 'Judul Baru', 'aliases' => [], 'type' => 'city-information', 'categories' => ['informasi kota'], 'tags' => ['manual'], 'sceneIds' => [], 'content' => 'Isi konten...', 'source' => $name, 'sourceType' => 'manual', 'provenance' => 'manual', 'translations' => ['id' => ['title' => 'Judul Baru', 'content' => 'Isi konten...'], 'en' => ['title' => 'Judul Baru', 'content' => 'Isi konten...']]];
        rev_check(city_dir($slug) . '/knowledge.json', (string)($_POST['rev_knowledge'] ?? ''));
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
        $cityLabel = (string)(read_json(city_dir($slug) . '/meta.json', [])['name'] ?? $slug);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') confirm_page('Hapus kota “' . $cityLabel . '”', '<p>Knowledge, instructions, dan sumber data kota ini akan dikeluarkan dari Studio. Tour yang memakai kota ini tidak dihapus, tetapi tidak lagi mendapat knowledge kota.</p><p class="muted">Data dipindahkan ke <code>storage/trash/</code> sehingga masih bisa dipulihkan secara manual.</p>', '?a=cityDelete&city=' . urlencode($slug), 'Hapus kota', '?a=cities', true, $cityLabel);
        if (trim((string)($_POST['confirm_name'] ?? '')) !== $cityLabel) throw new RuntimeException('Nama yang diketik tidak cocok. Penghapusan dibatalkan.');
        move_to_trash(city_dir($slug), 'city-' . $slug);
        header('Location: ?a=cities'); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citySourceText') {
        $slug = city_slugify((string)($_POST['city'] ?? ''));
        try { $added = city_source_add_text($slug, (string)($_POST['title'] ?? ''), (string)($_POST['text'] ?? '')); }
        catch (RuntimeException $e) { header('Location: ?a=citySources&city=' . urlencode($slug) . '&error=' . urlencode($e->getMessage())); exit; }
        $synced = city_sync_packages($slug);
        header('Location: ?a=citySources&city=' . urlencode($slug) . '&saved=1&added=' . $added . '&synced=' . count($synced)); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citySourceRefresh') {
        $slug = city_slugify((string)($_POST['city'] ?? ''));
        try { $r = city_source_refresh_url($slug, (int)($_POST['idx'] ?? -1)); }
        catch (RuntimeException $e) { header('Location: ?a=citySources&city=' . urlencode($slug) . '&error=' . urlencode($e->getMessage())); exit; }
        city_sync_packages($slug);
        header('Location: ?a=citySources&city=' . urlencode($slug) . '&refreshed=1&added=' . (int)$r['added']); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'citySourceDelete') {
        $slug = city_slugify((string)($_POST['city'] ?? ''));
        try { $topics = city_source_delete($slug, (int)($_POST['idx'] ?? -1)); }
        catch (RuntimeException $e) { header('Location: ?a=citySources&city=' . urlencode($slug) . '&error=' . urlencode($e->getMessage())); exit; }
        city_sync_packages($slug);
        header('Location: ?a=citySources&city=' . urlencode($slug) . '&deleted=1&topics=' . $topics); exit;
    }
    if ($action === 'syncRuntime') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') confirm_page('Sinkronkan runtime ke semua tour', '<p>Kode runtime AI Guide dari master (<code>' . h(SOURCE_AI) . '</code>) akan disalin ke <b>semua</b> package tour dan semua lokasi yang sudah di-deploy.</p><p class="muted">Data per-tour (<code>data/</code>, <code>config/</code>) tidak diubah. Proses ini bisa memakan waktu beberapa menit.</p>', '?a=syncRuntime', 'Sinkronkan semua', '?');
        set_time_limit(0);
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
            // Kota dicatat di database (sumber daftar kota) DAN folder knowledge-nya. Knowledge yang sudah ada tidak ditimpa.
            $row = city_ensure($name);
            $slug = (string)$row['slug'];
            city_ensure_files($slug, $name);
            $m = read_json(city_dir($slug) . '/meta.json', []); $m['name'] = $name; $m['updatedAt'] = date('c'); write_json(city_dir($slug) . '/meta.json', $m);
            header('Location: ?a=city&city=' . urlencode($slug)); exit;
        }
        $body = $error . '<form class="card" method="post" action="?a=cityNew"><label for="city_name">Nama kota / wilayah</label><input id="city_name" name="name" placeholder="contoh: Semarang" required autocomplete="off"><p class="muted">Slug dibuat otomatis dari nama. Knowledge ini hanya dipakai tour dengan lokasi yang sama.</p><p><button class="btn btn-primary" type="submit">Buat City</button></p></form>';
        layout('New City', $body); exit;
    }
    if ($action === 'city') {
        $slug = city_slugify((string)($_GET['city'] ?? ''));
        $msg = '';
        if (isset($_GET['saved'])) { $synced = (int)($_GET['synced'] ?? 0); $msg = '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Pengetahuan kota disimpan' . ($synced > 0 ? ' dan dikirim ke ' . $synced . ' tour.' : '.') . '</p></div>'; }
        render_city_page($slug, $msg); exit;
    }
    if ($action === 'citySources') {
        $slug = city_slugify((string)($_GET['city'] ?? ''));
        $msg = '';
        if (isset($_GET['saved'])) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Sumber diproses: ' . (int)($_GET['added'] ?? 0) . ' topik dibuat, dikirim ke ' . (int)($_GET['synced'] ?? 0) . ' tour.</p></div>';
        if (isset($_GET['deleted'])) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Sumber dihapus' . ((int)($_GET['topics'] ?? 0) > 0 ? ' beserta ' . (int)$_GET['topics'] . ' topik yang dibuat darinya' : '') . '.</p></div>';
        if (isset($_GET['refreshed'])) $msg .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Website diambil ulang: ' . (int)($_GET['added'] ?? 0) . ' topik diperbarui.</p></div>';
        if (isset($_GET['error'])) $msg .= '<div class="card bad"><p>' . h((string)$_GET['error']) . '</p></div>';
        render_city_sources($slug, $msg); exit;
    }
} catch (Throwable $e) {
    // Validasi Penyedia AI gagal: tampilkan lagi halaman Penyedia dengan pesannya (sebelumnya pesan ini hilang).
    if ($action === 'saveKeys' && $e instanceof RuntimeException) { render_keys_page($e->getMessage()); exit; }
    $error = '<div class="card bad">' . h($e->getMessage()) . '</div>';
}

if ($action === 'new' || $action === 'edit') {
    $t = $action === 'edit' ? get_tour((int)($_GET['id'] ?? 0)) : ['id' => 0, 'name' => '', 'slug' => '', 'city_id' => ctype_digit((string)($_GET['city'] ?? '')) ? (int)$_GET['city'] : (int)(city_row_by_slug(city_slugify((string)($_GET['city'] ?? '')))['id'] ?? 0), 'location' => '', 'category' => '', 'export_path' => ''];
    if (!$t) throw new RuntimeException('Tour tidak ditemukan.');
    render_tour_form($t, $error); exit;
}
    if ($action === 'view') {
        $t = get_tour((int)($_GET['id'] ?? 0)); if (!$t) { layout('Tour hilang', '<p>Tour tidak ditemukan.</p>'); exit; }
        $s = studio_tour_stats($t);
        $parsed = read_json(tour_dir($t['slug']) . '/parsed.json');
        render_tour_summary($t, $s, $parsed, $error); exit; }

/* ---- Project Dashboard (tour list): app/dashboard.php ---- */
render_dashboard($error);
}
