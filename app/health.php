<?php

declare(strict_types=1);

/**
 * Cek kesehatan AI Guide per lokasi.
 *
 * Yang diperiksa (berurutan, dan hasilnya disimpan):
 *  1. halaman   : index.html tur bisa dibuka dan memuat snippet AI Guide
 *  2. aset      : guide.js, guide.css, dan config/tour.json tersedia
 *  3. jawaban   : AI benar-benar menjawab pertanyaan nyata lewat tour-chat.php (dengan data scene)
 *  4. tampilan  : (opsional, --ui) browser sungguhan memastikan tombol AI Guide tampil dan terlihat
 *
 * Target: domain live jika alamat publik kota sudah diisi (mis. https://semarang.govirtual.id),
 * jika belum, localhost pada folder export.
 */

function health_migrate(PDO $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(cities)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('public_base', $cols, true)) $pdo->exec('ALTER TABLE cities ADD COLUMN public_base TEXT NOT NULL DEFAULT ""');
    $pdo->exec('CREATE TABLE IF NOT EXISTS health_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch TEXT NOT NULL,
        tour_id INTEGER NOT NULL,
        at TEXT NOT NULL,
        check_name TEXT NOT NULL,
        status TEXT NOT NULL,
        detail TEXT NOT NULL DEFAULT "",
        ms INTEGER NOT NULL DEFAULT 0,
        target TEXT NOT NULL DEFAULT ""
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_health_tour ON health_results(tour_id, at)');
    // Alamat publik bawaan untuk kota yang sudah live (bisa diubah di halaman Kesehatan).
    $pdo->exec('UPDATE cities SET public_base="https://semarang.govirtual.id" WHERE slug="semarang" AND public_base=""');
    $pdo->exec('UPDATE cities SET public_base="https://lampung-selatan.govirtual.id" WHERE slug="lampung-selatan" AND public_base=""');
}

/** Permintaan HTTP sederhana. Mengembalikan kode, isi, dan waktu (ms). */
function health_http(string $method, string $url, ?string $body = null, int $timeout = 45): array
{
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'AIGuideStudio-HealthCheck/1.0'];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = (string)$body; $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $opts);
    $t0 = microtime(true);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($res) ? $res : '', 'ms' => (int)round((microtime(true) - $t0) * 1000), 'err' => $err];
}

/** URL dasar tur yang diperiksa: domain live (kota) atau localhost. Mengembalikan [url, target]. */
function health_base_url(array $tour): array
{
    $export = rtrim(str_replace('\\', '/', (string)$tour['export_path']), '/');
    $folder = basename($export);
    $city = city_row_by_id((int)($tour['city_id'] ?? 0));
    $base = rtrim((string)($city['public_base'] ?? ''), '/');
    if ($base !== '' && $folder !== '' && $folder !== '.') return [$base . '/' . rawurlencode($folder) . '/', 'live'];
    $web = export_web_path($export);
    return ['http://localhost' . $web . '/', 'localhost'];
}

/** Pertanyaan uji: pertanyaan rekomendasi pertama (jika ada) + satu pertanyaan umum. */
function health_questions(array $tour, array $parsed): array
{
    $rec = read_json(tour_dir((string)$tour['slug']) . '/recommended-questions.json', []);
    $first = (string)($rec['state1']['starters'][0]['query'] ?? '');
    $qs = $first !== '' ? [$first] : [];
    $qs[] = 'Ceritakan tentang tempat ini.';
    $scene = '';
    foreach ((array)($parsed['scenes'] ?? []) as $sc) {
        $nm = (string)($sc['displayName'] ?? $sc['name'] ?? $sc['id'] ?? '');
        if ($nm !== '' && !preg_match('/^node\d+$/i', $nm) && $nm !== (string)($sc['id'] ?? '')) { $scene = (string)$sc['id']; break; }
    }
    if ($scene === '') $scene = (string)($parsed['scenes'][0]['id'] ?? '');
    return [$qs, $scene];
}

/** Jalankan semua pemeriksaan (tanpa UI) untuk satu tour. Mengembalikan daftar hasil. */
function health_check_tour(array $tour, bool $withUi = false): array
{
    $out = [];
    $add = function (string $name, string $status, string $detail, int $ms, string $target) use (&$out) {
        $out[] = ['check' => $name, 'status' => $status, 'detail' => $detail, 'ms' => $ms, 'target' => $target];
    };
    [$base, $target] = health_base_url($tour);
    $parsed = read_json(tour_dir((string)$tour['slug']) . '/parsed.json', []);

    // 1. halaman
    $r = health_http('GET', $base . 'index.html');
    $hasSnippet = str_contains($r['body'], 'govirtual-ai') || str_contains($r['body'], 'GOVIRTUAL AI');
    if ($r['code'] !== 200) $add('halaman', 'fail', 'HTTP ' . $r['code'] . ($r['err'] !== '' ? ' — ' . $r['err'] : '') . ' pada ' . $base, $r['ms'], $target);
    elseif (!$hasSnippet) $add('halaman', 'fail', 'Halaman terbuka, tetapi snippet AI Guide tidak ditemukan di index.html', $r['ms'], $target);
    else $add('halaman', 'ok', 'Halaman terbuka dan memuat AI Guide', $r['ms'], $target);

    // 2. aset
    $missing = [];
    foreach (['govirtual-ai/frontend/guide.js', 'govirtual-ai/frontend/guide.css', 'govirtual-ai/config/tour.json'] as $a) {
        $ra = health_http('GET', $base . $a, null, 30);
        if ($ra['code'] !== 200) $missing[] = $a . ' (HTTP ' . $ra['code'] . ')';
    }
    $add('aset', $missing ? 'fail' : 'ok', $missing ? 'Tidak bisa dimuat: ' . implode(', ', $missing) : 'guide.js, guide.css, dan config tersedia', 0, $target);

    // 3. jawaban AI (pertanyaan nyata)
    [$questions, $scene] = health_questions($tour, $parsed);
    $payloadBase = ['history' => [], 'conversationState' => ['lastSceneId' => $scene, 'visitedSceneIds' => [$scene], 'visitorProfile' => []], 'viewContext' => [],
        'tourContext' => ['scenes' => $parsed['scenes'] ?? [], 'hotspots' => $parsed['hotspots'] ?? [], 'routes' => $parsed['routes'] ?? [], 'video360' => $parsed['video360'] ?? []]];
    foreach ($questions as $q) {
        $payload = json_encode(['aiRequestId' => 'health-' . bin2hex(random_bytes(3)), 'message' => $q, 'currentScene' => $scene] + $payloadBase, JSON_UNESCAPED_UNICODE);
        $ra = health_http('POST', $base . 'govirtual-ai/api/tour-chat.php', (string)$payload, 90);
        $d = json_decode($ra['body'], true);
        $reply = is_array($d) ? trim((string)($d['reply'] ?? $d['respons'] ?? $d['answer'] ?? '')) : '';
        $label = 'jawaban: ' . mb_substr($q, 0, 40);
        if ($ra['code'] !== 200 || !is_array($d)) $add('jawaban', 'fail', 'Endpoint AI HTTP ' . $ra['code'] . ' — ' . mb_substr(strip_tags($ra['body']), 0, 120), $ra['ms'], $target);
        elseif (isset($d['error'])) $add('jawaban', 'fail', 'AI mengembalikan error: ' . (string)$d['error'], $ra['ms'], $target);
        elseif (mb_strlen($reply) < 15) $add('jawaban', 'fail', 'Jawaban kosong atau terlalu pendek untuk: "' . $q . '"', $ra['ms'], $target);
        elseif ($ra['ms'] > 15000) $add('jawaban', 'warn', 'Jawaban lambat (' . round($ra['ms'] / 1000, 1) . ' dtk) untuk: "' . $q . '"', $ra['ms'], $target);
        else $add('jawaban', 'ok', 'AI menjawab (' . round($ra['ms'] / 1000, 1) . ' dtk): ' . mb_substr($reply, 0, 90) . '…', $ra['ms'], $target);
    }

    // 4. tampilan (browser) — opsional
    if ($withUi) {
        $ui = health_ui_check($base);
        $add('tampilan', $ui['status'], $ui['detail'], $ui['ms'], $target);
    }
    return $out;
}

/** Pemeriksaan tampilan lewat Edge headless (tools/ui_check.mjs). */
function health_ui_check(string $url): array
{
    $script = APP_ROOT . '/tools/ui_check.mjs';
    if (!is_file($script)) return ['status' => 'warn', 'detail' => 'tools/ui_check.mjs belum ada', 'ms' => 0];
    $cmd = 'node ' . escapeshellarg($script) . ' ' . escapeshellarg($url) . ' 2>&1';
    $t0 = microtime(true);
    $out = (string)shell_exec($cmd);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $j = json_decode(trim((string)substr($out, strrpos($out, "\n{") !== false ? strrpos($out, "\n{") + 1 : 0)), true);
    if (!is_array($j)) return ['status' => 'fail', 'detail' => 'Pemeriksaan tampilan gagal dijalankan: ' . mb_substr($out, 0, 160), 'ms' => $ms];
    if (!empty($j['ok'])) return ['status' => 'ok', 'detail' => 'Tombol AI Guide tampil dan terlihat (' . (int)$j['ms'] . ' ms)', 'ms' => $ms];
    return ['status' => 'fail', 'detail' => 'Tombol AI Guide tidak tampil: ' . (string)($j['reason'] ?? 'tidak diketahui'), 'ms' => $ms];
}

/** Simpan hasil pemeriksaan satu tour. */
function health_store(string $batch, int $tourId, array $results): void
{
    $ins = db()->prepare('INSERT INTO health_results(batch,tour_id,at,check_name,status,detail,ms,target) VALUES(?,?,?,?,?,?,?,?)');
    $at = date('c');
    foreach ($results as $r) $ins->execute([$batch, $tourId, $at, $r['check'], $r['status'], mb_substr((string)$r['detail'], 0, 400), (int)$r['ms'], (string)$r['target']]);
}

/** Ringkasan terbaru per tour: status keseluruhan + hasil tiap pemeriksaan dari run terakhir. */
function health_latest(): array
{
    $rows = db()->query('SELECT h.* FROM health_results h JOIN (SELECT tour_id, MAX(at) AS last FROM health_results GROUP BY tour_id) m ON m.tour_id=h.tour_id AND m.last=h.at ORDER BY h.tour_id, h.id')->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['tour_id']]['at'] = $r['at'];
    foreach ($rows as $r) $out[(int)$r['tour_id']]['checks'][$r['check_name']] = ['status' => $r['status'], 'detail' => $r['detail'], 'ms' => (int)$r['ms']];
    foreach ($out as $tid => $d) {
        $st = array_column($d['checks'], 'status');
        $out[$tid]['overall'] = in_array('fail', $st, true) ? 'fail' : (in_array('warn', $st, true) ? 'warn' : 'ok');
    }
    return $out;
}
