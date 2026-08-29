<?php
/**
 * _deploy_all.php — Full build + deploy for all tours.
 * Copies fresh runtime (guide.js, ai-core.php, ai-provider.php) from SOURCE_AI,
 * writes all data files, then pushes to each export path.
 *
 * Usage:
 *   c:\xampp\php\php.exe _deploy_all.php
 */

declare(strict_types=1);

// Capture index.php web output (dashboard HTML) — suppress in CLI
ob_start();
require_once __DIR__ . '/index.php';
ob_end_clean();

$pdo = new PDO('sqlite:' . __DIR__ . '/storage/app.sqlite');
$st = $pdo->query("SELECT id, slug, name, export_path FROM tours WHERE export_path != '' ORDER BY slug");
$tours = $st->fetchAll(PDO::FETCH_ASSOC);

echo "=== FULL BUILD + DEPLOY ===\n";
echo "Tours: " . count($tours) . "\n";
echo "SOURCE_AI: " . SOURCE_AI . "\n";
echo "============================\n\n";

$ok = 0; $fail = 0;

foreach ($tours as $t) {
    $slug = $t['slug'];
    $name = $t['name'];
    echo "[$slug] $name... ";

    try {
        $tour = get_tour((int)$t['id']);
        if (!$tour) { echo "FAIL: get_tour\n"; $fail++; continue; }

        // Build package (fresh runtime + all data files)
        $r = build_files($tour);
        echo "build:" . count($r['files']) . "f ";

        // Deploy to export path
        $backup = deploy_package($tour);
        echo "deploy:ok ";

        $ok++;
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\n=== DONE: $ok OK, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);