<?php
declare(strict_types=1);

// Hanya untuk CLI: dijadwalkan (Task Scheduler) atau dijalankan manual.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Cek kesehatan AI Guide dari terminal (cocok untuk penjadwalan).
 *
 *   php tools/health_check.php --all                 (semua lokasi, tanpa browser)
 *   php tools/health_check.php --all --ui            (semua lokasi, termasuk pemeriksaan tampilan)
 *   php tools/health_check.php --city=semarang       (satu kota)
 *   php tools/health_check.php --tour=nirwana        (satu lokasi)
 *
 * Keluar dengan kode 1 bila ada lokasi yang bermasalah (berguna untuk notifikasi).
 */

ob_start();
require_once dirname(__DIR__) . '/index.php';
ob_end_clean();

$opt = getopt('', ['all', 'city:', 'tour:', 'ui']);
$ui = isset($opt['ui']);
if (isset($opt['city'])) {
    $c = city_row_by_slug(city_slugify((string)$opt['city']));
    if (!$c) { fwrite(STDERR, "kota tidak ditemukan\n"); exit(2); }
    $q = db()->prepare('SELECT id FROM tours WHERE city_id=?'); $q->execute([(int)$c['id']]);
    $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
} elseif (isset($opt['tour'])) {
    $q = db()->prepare('SELECT id FROM tours WHERE slug=?'); $q->execute([(string)$opt['tour']]);
    $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
} elseif (isset($opt['all'])) {
    $ids = array_map('intval', db()->query('SELECT id FROM tours ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
} else {
    fwrite(STDERR, "pakai --all, --city=<slug>, atau --tour=<slug>\n"); exit(2);
}
if (!$ids) { fwrite(STDERR, "tidak ada lokasi\n"); exit(2); }

$batch = job_enqueue($ui ? 'healthui' : 'health', $ids, 'penjadwal');
$n = job_worker_run();
echo date('Y-m-d H:i') . " — batch $batch: $n pekerjaan diproses\n";

$latest = health_latest();
$bad = 0;
foreach ($ids as $id) {
    $t = get_tour($id);
    $d = $latest[$id] ?? null;
    $overall = $d['overall'] ?? 'none';
    if ($overall === 'fail') $bad++;
    echo str_pad((string)($t['slug'] ?? $id), 30) . ' ' . strtoupper($overall) . "\n";
    foreach ((array)($d['checks'] ?? []) as $name => $ck) {
        if ($ck['status'] !== 'ok') echo '    ' . $name . ': ' . $ck['detail'] . "\n";
    }
}
echo "\nRingkasan: " . count($ids) . " lokasi, $bad bermasalah\n";
exit($bad > 0 ? 1 : 0);
