<?php
// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Route smoke test — subprocess per route via runner.php + JSON argv (robust on Windows).
$php = 'C:\\xampp\\php\\php.exe';
$routes = [
    'list' => ['get' => [], 'markers' => ['city-card']],
    'list-kota' => ['get' => ['a' => 'list', 'city' => 'semarang'], 'markers' => ['tour-grid', 'dv-bar']],
    'view' => ['get' => ['a' => 'view', 'id' => 1], 'markers' => ['ready-ring', 'issue-item', 'checklist', 'coverage-item', 'scene-chips', 'activity-item']],
    'edit' => ['get' => ['a' => 'edit', 'id' => 1], 'markers' => []],
    'new' => ['get' => ['a' => 'new'], 'markers' => []],
    'scenes' => ['get' => ['a' => 'scenes', 'id' => 1], 'markers' => []],
    'curated' => ['get' => ['a' => 'curated', 'id' => 1], 'markers' => []],
    'knowledge' => ['get' => ['a' => 'knowledge', 'id' => 1], 'markers' => []],
    'tips' => ['get' => ['a' => 'tips', 'id' => 1], 'markers' => []],
    'nearby' => ['get' => ['a' => 'nearby', 'id' => 1], 'markers' => []],
    'prompts' => ['get' => ['a' => 'prompts', 'id' => 1], 'markers' => []],
    'keys' => ['get' => ['a' => 'keys'], 'markers' => []],
    'cities' => ['get' => ['a' => 'cities'], 'markers' => ['tour-card', 'tour-grid']],
    'cityNew' => ['get' => ['a' => 'cityNew'], 'markers' => []],
    'flow' => ['get' => ['a' => 'flow', 'id' => 1], 'markers' => []],
    'voice' => ['get' => ['a' => 'voice', 'id' => 1], 'markers' => []],
    'aihealth' => ['get' => ['a' => 'aihealth'], 'markers' => []],
    'health' => ['get' => ['a' => 'health'], 'markers' => ['Kesehatan AI', 'Cek semua lokasi']],
    'account' => ['get' => ['a' => 'account'], 'markers' => ['Password saat ini', 'saveAccount']],
    'publish' => ['get' => ['a' => 'publish', 'id' => 1], 'markers' => ['1. Konten tour', '2. Package', '3. Terpasang']],
    'history' => ['get' => ['a' => 'history', 'id' => 1], 'markers' => ['Riwayat Perubahan']],
    'jobs' => ['get' => ['a' => 'jobs'], 'markers' => ['Antrian Publikasi', 'jbForm']],
    'bulkImport' => ['get' => ['a' => 'bulkImport'], 'markers' => ['Impor Massal']],
    'test' => ['get' => ['a' => 'test', 'id' => 1], 'markers' => ['t_scene', 't_form', 'testAnswer']],
    // Aksi yang mengubah data harus MENAMPILKAN konfirmasi saat GET, bukan langsung dieksekusi.
    'confirm:delete' => ['get' => ['a' => 'delete', 'id' => 1], 'markers' => ['confirm_name', 'Hapus project']],
    'confirm:import' => ['get' => ['a' => 'import', 'id' => 1], 'markers' => ['Import sekarang']],
    'confirm:generate' => ['get' => ['a' => 'generate', 'id' => 1], 'markers' => ['Generate package']],
    'confirm:deploy' => ['get' => ['a' => 'deploy', 'id' => 1], 'markers' => ['Deploy sekarang']],
    'confirm:syncRuntime' => ['get' => ['a' => 'syncRuntime'], 'markers' => ['Sinkronkan semua']],
    'confirm:cityDelete' => ['get' => ['a' => 'cityDelete', 'city' => 'semarang'], 'markers' => ['confirm_name', 'Hapus kota']],
    // Setiap form POST harus membawa token CSRF (disisipkan layout()).
    'csrf:knowledge' => ['get' => ['a' => 'knowledge', 'id' => 1], 'markers' => ['name="csrf_token"']],
    'csrf:city' => ['get' => ['a' => 'city', 'city' => 'semarang'], 'markers' => ['name="csrf_token"', 'name="instructions"']],
];

$fails = 0;
foreach ($routes as $name => $r) {
    unset($out);
    $out = [];
    $cmd = $php . ' runner.php ' . base64_encode(json_encode($r['get']));
    exec($cmd, $out, $rc);
    $line = trim(implode("\n", $out));
    $parts = explode('|', $line);
    $ok = (($parts[0] ?? '') === 'OK');
    $missing = [];
    if ($ok && !empty($r['markers'])) {
        unset($out2);
        $out2 = [];
        $cmd2 = $php . ' runner.php ' . base64_encode(json_encode($r['get'])) . ' body';
        exec($cmd2, $out2, $rc2);
        $h = implode("\n", $out2);
        foreach ($r['markers'] as $m) if (strpos($h, $m) === false) $missing[] = $m;
        if ($missing) $ok = false;
    }
    if (!$ok) $fails++;
    $mark = $missing ? ' MISSING:' . implode(',', $missing) : '';
    echo str_pad($name, 12) . ': ' . ($ok ? 'OK ' . ($parts[2] ?? '') . ' ' . ($parts[1] ?? '') . 'b' : 'FAIL ' . $line) . $mark . chr(10);
}
echo 'FAILS: ' . $fails . chr(10);
