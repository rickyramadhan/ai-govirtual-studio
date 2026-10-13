<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/qa_import.php';
require_once dirname(__DIR__) . '/scene_import.php';

const DEFAULT_SOURCE_DIR = 'C:/Users/Ricky/Downloads/Semarang_Revisi_80_Lokasi_CSV/csv';
const DB_FILE = __DIR__ . '/../storage/app.sqlite';
const TOURS_DIR = __DIR__ . '/../storage/tours';

if (!function_exists('slugify')) {
    function slugify(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}

function key_name(string $value): string {
    $value = strtolower(pathinfo($value, PATHINFO_FILENAME));
    $value = preg_replace('/^scenes-|(?:[-_](?:updated|revisi|unique))+$/i', '', $value) ?? $value;
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function fail(array $errors): never {
    fwrite(STDERR, "BATAL: validasi belum aman:\n- " . implode("\n- ", $errors) . "\n");
    exit(2);
}

$sourceArg = array_values(array_filter($argv, fn(string $arg): bool => str_starts_with($arg, '--source=')));
$sourceDir = $sourceArg ? substr($sourceArg[0], strlen('--source=')) : DEFAULT_SOURCE_DIR;
$files = glob(rtrim($sourceDir, '/\\') . '/*.csv') ?: [];
$errors = [];
if (!$files) $errors[] = 'Tidak ada CSV di folder sumber: ' . $sourceDir;
$expectedFileCount = count($files);

$db = new PDO('sqlite:' . DB_FILE);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$tours = $db->query('SELECT id, slug, name, export_path FROM tours ORDER BY id')->fetchAll();
$index = [];
foreach ($tours as $tour) {
    foreach (array_unique([key_name((string)$tour['slug']), key_name((string)$tour['name']), key_name(basename((string)$tour['export_path']))]) as $key) {
        if ($key !== '') $index[$key][(string)$tour['slug']] = $tour;
    }
}

$plans = [];
foreach ($files as $file) {
    $base = basename($file);
    $key = key_name($base);
    $candidates = array_values($index[$key] ?? []);
    if (count($candidates) !== 1) {
        $errors[] = $base . ' => ' . (count($candidates) ? 'tur ambigu' : 'tur tidak ditemukan') . ' [' . $key . ']';
        continue;
    }
    $tour = $candidates[0];
    $slug = (string)$tour['slug'];
    if (isset($plans[$slug])) {
        $errors[] = $base . ' => tur duplikat: ' . $slug;
        continue;
    }

    $dir = TOURS_DIR . '/' . $slug;
    $parsedFile = $dir . '/parsed.json';
    $identityFile = $dir . '/scene-identity.json';
    $parsed = json_decode((string)@file_get_contents($parsedFile), true);
    $identity = json_decode((string)@file_get_contents($identityFile), true);
    if (!is_array($parsed) || !is_array($parsed['scenes'] ?? null)) {
        $errors[] = $base . ' => parsed.json hilang/rusak untuk ' . $slug;
        continue;
    }
    if (!is_array($identity) || !is_array($identity['scenes'] ?? null)) {
        $errors[] = $base . ' => scene-identity.json hilang/rusak untuk ' . $slug;
        continue;
    }

    try { $rows = scene_parse_file($file, $base); }
    catch (Throwable $e) { $errors[] = $base . ' => parser: ' . $e->getMessage(); continue; }

    $expected = array_values(array_unique(array_map('strval', array_column($parsed['scenes'], 'id'))));
    $actual = array_map(fn(array $row): string => (string)$row['sceneid'], $rows);
    $duplicates = array_keys(array_filter(array_count_values($actual), fn(int $n): bool => $n > 1));
    $missing = array_values(array_diff($expected, $actual));
    $unknown = array_values(array_diff($actual, $expected));
    if ($duplicates) $errors[] = $base . ' => Scene ID duplikat: ' . implode(', ', $duplicates);
    if ($missing) $errors[] = $base . ' => Scene ID kurang: ' . implode(', ', $missing);
    if ($unknown) $errors[] = $base . ' => Scene ID tidak dikenal: ' . implode(', ', $unknown);
    if (count($rows) !== count($expected)) $errors[] = $base . ' => jumlah baris ' . count($rows) . ', scene tur ' . count($expected);

    foreach ($rows as $i => $row) {
        $label = $base . ' baris ' . ($i + 2) . ' [' . $row['sceneid'] . ']';
        foreach (['display', 'place', 'shortdesc', 'description', 'highlights', 'suggested'] as $field) {
            if (trim((string)$row[$field]) === '') $errors[] = $label . ' => kolom ' . $field . ' kosong';
        }
        $questions = json_decode((string)$row['suggested'], true);
        if (!is_array($questions) || count($questions) !== 3) {
            $errors[] = $label . ' => Suggested Questions harus JSON array berisi 3 item';
            continue;
        }
        foreach ($questions as $qIndex => $question) {
            if (!is_array($question) || trim((string)($question['q'] ?? '')) === '') $errors[] = $label . ' => pertanyaan #' . ($qIndex + 1) . ' kosong/rusak';
            if (!in_array((string)($question['mode'] ?? ''), ['ai', 'fix'], true)) $errors[] = $label . ' => mode pertanyaan #' . ($qIndex + 1) . ' bukan ai/fix';
            if (($question['mode'] ?? '') === 'fix' && trim((string)($question['answer'] ?? '')) === '') $errors[] = $label . ' => jawaban fix #' . ($qIndex + 1) . ' kosong';
        }
    }

    [$updated, $count] = scene_apply_import($identity, $rows);
    if ($count !== count($expected)) $errors[] = $base . ' => importer hanya memperbarui ' . $count . '/' . count($expected) . ' scene';
    $plans[$slug] = compact('base', 'tour', 'identityFile', 'updated', 'count');
}

if (count($plans) !== $expectedFileCount) $errors[] = 'Pemetaan harus menghasilkan ' . $expectedFileCount . ' tur unik, ditemukan ' . count($plans) . '.';
if ($errors) fail(array_values(array_unique($errors)));

ksort($plans);
$apply = in_array('--apply', $argv, true);
echo ($apply ? 'APPLY' : 'DRY RUN') . ': ' . $expectedFileCount . ' CSV valid, ' . count($plans) . " tur unik\n";
$backupDir = TOURS_DIR . '/.scene-import-backup-' . date('Ymd-His');
foreach ($plans as $slug => $plan) {
    printf("%-32s %3d scene  %s\n", $slug, $plan['count'], $plan['base']);
    if (!$apply) continue;
    if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true) && !is_dir($backupDir)) throw new RuntimeException('Gagal membuat folder backup.');
    if (!copy($plan['identityFile'], $backupDir . '/' . $slug . '.json')) throw new RuntimeException('Gagal backup ' . $slug);
    $json = json_encode($plan['updated'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temp = $plan['identityFile'] . '.tmp';
    if (file_put_contents($temp, $json, LOCK_EX) === false || !rename($temp, $plan['identityFile'])) throw new RuntimeException('Gagal menulis ' . $plan['identityFile']);
}
if ($apply) echo 'SELESAI. Backup: ' . $backupDir . "\n";
