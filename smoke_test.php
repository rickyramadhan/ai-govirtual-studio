<?php
// Route smoke test — subprocess per route via runner.php + JSON argv (robust on Windows).
$php = 'C:\\xampp\\php\\php.exe';
$routes = [
    'list' => ['get' => [], 'markers' => ['tour-grid', 'metric-grid', 'filter-chip']],
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
    'cities' => ['get' => ['a' => 'cities'], 'markers' => ['City Knowledge', 'tour-card', 'data-locfilter']],
    'cityNew' => ['get' => ['a' => 'cityNew'], 'markers' => []],
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
