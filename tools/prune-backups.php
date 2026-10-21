<?php
declare(strict_types=1);

// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Pangkas backup deploy lama di semua export (.ai-generator-backup/), sisakan N terbaru.
 * Default DRY-RUN: hanya menampilkan apa yang akan dihapus.
 *
 *   php tools/prune-backups.php                 (dry-run, keep=3)
 *   php tools/prune-backups.php --keep=5
 *   php tools/prune-backups.php --apply         (benar-benar menghapus)
 */

ob_start();
require_once dirname(__DIR__) . '/index.php';
ob_end_clean();

$keep = 3; $apply = false;
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--apply') $apply = true;
    elseif (preg_match('/^--keep=(\d+)$/', $a, $m)) $keep = max(1, (int)$m[1]);
}

$totalDirs = 0; $totalBytes = 0;
foreach (all_tours() as $t) {
    $export = rtrim(str_replace('\\', '/', (string)$t['export_path']), '/');
    if ($export === '') continue;
    $victims = prune_deploy_backups($export . '/.ai-generator-backup', $keep, true);
    if (!$victims) continue;
    $bytes = 0;
    foreach ($victims as $v) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($v, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $bytes += $f->getSize();
    }
    printf("%-34s %2d backup lama  %7.1f MB\n", $t['slug'], count($victims), $bytes / 1048576);
    $totalDirs += count($victims); $totalBytes += $bytes;
    if ($apply) prune_deploy_backups($export . '/.ai-generator-backup', $keep, false);
}
printf("\nTotal: %d backup, %.1f MB %s\n", $totalDirs, $totalBytes / 1048576, $apply ? 'DIHAPUS' : '(dry-run — tambahkan --apply untuk menghapus)');
