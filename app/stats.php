<?php

declare(strict_types=1);

/**
 * Cache untuk dashboard. Kuncinya adalah mtime+ukuran file, jadi cache otomatis basi bila file berubah;
 * tidak ada invalidasi manual yang bisa terlupa. Hanya memengaruhi kecepatan, bukan hasil.
 */

/** Hash xxh3 sebuah file, dengan cache berdasarkan mtime+ukuran. Mengembalikan '' bila file tidak ada. */
function file_hash_cached(string $path): string
{
    static $rows = null;
    if (!is_file($path)) return '';
    if ($rows === null) {
        $rows = [];
        foreach (db()->query('SELECT path, mtime, size, md5 FROM file_hash_cache')->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[$r['path']] = $r;
    }
    $mt = (int)filemtime($path);
    $sz = (int)filesize($path);
    $key = str_replace('\\', '/', $path);
    if (isset($rows[$key]) && (int)$rows[$key]['mtime'] === $mt && (int)$rows[$key]['size'] === $sz) return (string)$rows[$key]['md5'];
    $hash = hash_file('xxh3', $path);
    db()->prepare('INSERT INTO file_hash_cache(path,mtime,size,md5) VALUES(?,?,?,?) ON CONFLICT(path) DO UPDATE SET mtime=excluded.mtime, size=excluded.size, md5=excluded.md5')->execute([$key, $mt, $sz, $hash]);
    $rows[$key] = ['path' => $key, 'mtime' => $mt, 'size' => $sz, 'md5' => $hash];
    return $hash;
}

/** Tanda tangan murah (hanya stat) dari semua file yang memengaruhi statistik sebuah tour. */
function tour_stats_signature(array $tour): string
{
    $dir = tour_dir((string)$tour['slug']);
    $parts = [(string)$tour['id'], (string)$tour['slug'], (string)$tour['export_path']];
    foreach (['parsed', 'curated-answers', 'knowledge', 'scene-identity', 'visitor-tips', 'nearby', 'recommended-questions'] as $f) {
        $p = "$dir/$f.json";
        $parts[] = is_file($p) ? filemtime($p) . '.' . filesize($p) : '-';
    }
    $export = trim((string)$tour['export_path']);
    $parts[] = is_dir("$dir/package/govirtual-ai") ? 'P1' : 'P0';
    $parts[] = ($export !== '' && is_dir($export)) ? 'E1' : 'E0';
    $parts[] = ($export !== '' && is_dir($export . '/govirtual-ai')) ? 'D1' : 'D0';
    return md5(implode('|', $parts));
}

/** Statistik tour (studio_tour_stats) dengan cache. $fresh=true memaksa hitung ulang. */
function tour_stats_cached(array $tour, bool $fresh = false): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        foreach (db()->query('SELECT tour_id, sig, json FROM tour_stats_cache')->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['tour_id']] = $r;
    }
    $id = (int)$tour['id'];
    $sig = tour_stats_signature($tour);
    if (!$fresh && isset($rows[$id]) && $rows[$id]['sig'] === $sig) {
        $d = json_decode((string)$rows[$id]['json'], true);
        if (is_array($d)) return $d;
    }
    $stats = studio_tour_stats($tour);
    $json = json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json !== false) {
        db()->prepare('INSERT INTO tour_stats_cache(tour_id,sig,json) VALUES(?,?,?) ON CONFLICT(tour_id) DO UPDATE SET sig=excluded.sig, json=excluded.json')->execute([$id, $sig, $json]);
        $rows[$id] = ['tour_id' => $id, 'sig' => $sig, 'json' => $json];
    }
    return $stats;
}

/**
 * File export (pano.xml/skin.js/index.html) yang berubah sejak baseline Import/Deploy terakhir.
 * Memakai baseline yang sudah ada di statistik ($stats['exportState']) dan hash ber-cache.
 */
function tour_export_changes(array $tour, array $stats): ?array
{
    $old = $stats['exportState'] ?? null;
    $export = rtrim(str_replace('\\', '/', (string)$tour['export_path']), '/');
    if (!is_array($old) || !$old || $export === '') return null;
    $changed = [];
    foreach (['pano.xml', 'skin.js', 'index.html'] as $f) {
        $oldVal = (string)($old[$f] ?? '');
        $curVal = file_hash_cached("$export/$f");
        if ($oldVal !== '' && $curVal !== '' && $oldVal !== $curVal) $changed[] = $f;
    }
    return $changed ?: null;
}
