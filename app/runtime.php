<?php

declare(strict_types=1);

/**
 * Runtime AI Guide (SOURCE_AI) — sidik jari versi dan status per tour.
 * Master ada di luar repo, jadi kita tidak bisa mengandalkan git untuk tahu versi mana yang terpasang;
 * sebagai gantinya kita bandingkan hash file runtime di master dengan yang terpasang di folder export tour.
 */

/** File runtime yang disalin sync_runtime_all ke tiap tour (cukup yang menentukan perilaku). */
function runtime_tracked_files(): array
{
    return ['frontend/guide.js', 'frontend/guide.css', 'api/ai-core.php', 'api/ai-provider.php', 'api/guide-policy.php', 'api/tour-chat.php'];
}

/** Hash tiap file runtime di master + sidik jari gabungan 8 karakter. */
function runtime_fingerprint(): array
{
    static $fp = null;
    if ($fp !== null) return $fp;
    $files = [];
    foreach (runtime_tracked_files() as $f) $files[$f] = file_hash_cached(SOURCE_AI . '/' . $f);
    $fp = ['files' => $files, 'id' => substr(md5(implode('|', $files)), 0, 8), 'available' => (bool)array_filter($files)];
    return $fp;
}

/**
 * Status runtime terpasang untuk satu tour:
 *  - 'none'     : belum di-deploy (tidak ada folder govirtual-ai di export)
 *  - 'current'  : semua file runtime sama dengan master
 *  - 'outdated' : ada file yang berbeda (lihat 'diff')
 */
function tour_runtime_status(array $tour): array
{
    $fp = runtime_fingerprint();
    $export = rtrim(str_replace('\\', '/', (string)$tour['export_path']), '/');
    if ($export === '' || !is_dir($export . '/govirtual-ai')) return ['state' => 'none', 'diff' => []];
    if (!$fp['available']) return ['state' => 'unknown', 'diff' => []];
    $diff = [];
    foreach ($fp['files'] as $f => $masterHash) {
        if ($masterHash === '') continue;
        if (file_hash_cached($export . '/govirtual-ai/' . $f) !== $masterHash) $diff[] = $f;
    }
    return ['state' => $diff ? 'outdated' : 'current', 'diff' => $diff];
}

/** URL API pusat untuk uji cepat bila sebuah tour belum di-deploy. Dapat diubah lewat setting central_api_url. */
function central_api_url(): string
{
    $v = trim(setting_get('central_api_url', ''));
    return $v !== '' ? $v : 'http://localhost/govirtual-ai/govirtual-ai/api/tour-chat.php';
}
