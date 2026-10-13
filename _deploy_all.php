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

$apply = in_array('--apply', $argv, true);
$pdo = new PDO('sqlite:' . __DIR__ . '/storage/app.sqlite');
$st = $pdo->prepare("SELECT id, slug, name, export_path FROM tours WHERE export_path != '' AND lower(location) = ? ORDER BY slug");
$st->execute(['semarang']);
$tours = $st->fetchAll(PDO::FETCH_ASSOC);

if (count($tours) !== 117) {
    fwrite(STDERR, "ABORT: expected 117 Semarang tours, found " . count($tours) . "\n");
    exit(1);
}

echo $apply ? "=== SEMARANG BUILD + DEPLOY ===\n" : "=== SEMARANG PREFLIGHT ===\n";
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
        if (!$tour) throw new RuntimeException('get_tour gagal');
        $export = rtrim((string)$tour['export_path'], '/\\');
        foreach (['index.html', 'pano.xml'] as $required) {
            if (!is_file($export . '/' . $required)) throw new RuntimeException($required . ' tidak ditemukan');
        }
        if (!is_writable($export) || !is_writable($export . '/index.html')) throw new RuntimeException('export tidak writable');
        $parsed = read_json(tour_dir($slug) . '/parsed.json');
        $identity = read_json(tour_dir($slug) . '/scene-identity.json');
        $sceneIds = array_values(array_filter(array_map(fn(array $s): string => (string)($s['id'] ?? ''), $parsed['scenes'] ?? [])));
        if (!$sceneIds) throw new RuntimeException('parsed.json tidak punya scene');
        $identityIds = array_keys($identity['scenes'] ?? []);
        $missingIdentity = array_diff($sceneIds, $identityIds);
        if ($missingIdentity) throw new RuntimeException('scene-identity kurang ' . count($missingIdentity) . ' scene');
        if (!is_dir($export . '/images')) throw new RuntimeException('folder images tidak ditemukan');

        if ($apply) {
            $r = build_files($tour);
            echo "build:" . count($r['files']) . "f ";
            deploy_package($tour);
            echo "deploy:ok ";
        }
        $ok++;
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
        $fail++;
    }
}

echo "\n=== DONE: $ok OK, $fail FAIL ===\n";
exit($fail > 0 ? 1 : 0);