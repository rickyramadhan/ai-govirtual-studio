<?php
/**
 * _sync_runtime.php — Sync runtime-only changes from SOURCE_AI to all packages + deployed locations.
 * Skips per-tour data (data/, config/). Only touches frontend/guide.js, frontend/guide.css,
 * api/*, ai-client.js, .htaccess, etc.
 *
 * Usage:
 *   c:\xampp\php\php.exe _sync_runtime.php
 */

declare(strict_types=1);

// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

ob_start();
require_once __DIR__ . '/index.php';
ob_end_clean();

echo "=== RUNTIME SYNC ===\n";
echo "SOURCE_AI: " . SOURCE_AI . "\n";
echo "====================\n\n";

try {
    $r = sync_runtime_all();
    $ok = 0; $fail = 0;
    foreach ($r as $slug => $x) {
        $mark = $x['note'] === 'ok' ? 'OK' : 'SKIP';
        printf("  [%s] %s — %s (%d file × %d target)\n", $mark, $slug, $x['note'], $x['copied'], $x['targets']);
        if ($x['note'] === 'ok') $ok++; else $fail++;
    }
    echo "\n=== DONE: $ok OK, $fail SKIP ===\n";
    exit($ok > 0 ? 0 : 1);
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}