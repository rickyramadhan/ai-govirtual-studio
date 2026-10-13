<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../../semarang');
$db = new PDO('sqlite:' . __DIR__ . '/../storage/app.sqlite');
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$tours = $db->query("SELECT id, slug, name, export_path FROM tours ORDER BY id")->fetchAll();
$rows = [];

function local_refs(string $html): array
{
    preg_match_all('/(?:src|href)=["\']([^"\'#?]+)(?:[?#][^"\']*)?["\']/i', $html, $matches);
    return array_values(array_unique(array_filter($matches[1], static function (string $ref): bool {
        return !preg_match('~^(?:https?:)?//|^(?:data|mailto|tel|javascript):~i', $ref);
    })));
}

function http_probe(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT_MS => 5000,
        CURLOPT_NOBODY => false,
        CURLOPT_RANGE => '0-65535',
        CURLOPT_USERAGENT => 'SemarangLoadAudit/1.0',
    ]);
    curl_exec($ch);
    $error = curl_error($ch);
    $result = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'ttfbMs' => (int) round((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000),
        'totalMs' => (int) round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
        'error' => $error,
    ];
    curl_close($ch);
    return $result;
}

foreach ($tours as $tour) {
    $export = realpath((string) $tour['export_path']);
    if (!$export || !$root || !str_starts_with(strtolower(str_replace('\\', '/', $export)), strtolower(str_replace('\\', '/', $root)) . '/')) {
        continue;
    }

    $folder = basename($export);
    $index = $export . '/index.html';
    $missing = [];
    $large = [];
    $syntax = true;
    $htmlBytes = is_file($index) ? filesize($index) : 0;

    if (!is_file($index)) {
        $missing[] = 'index.html';
    } else {
        $html = (string) file_get_contents($index);
        foreach (local_refs($html) as $ref) {
            $path = realpath($export . '/' . str_replace('/', DIRECTORY_SEPARATOR, $ref));
            if (!$path || !is_file($path)) {
                $missing[] = $ref;
            }
        }
    }

    foreach (['pano2vr_player.js', 'skin.js', 'pano.xml'] as $required) {
        if (!is_file($export . '/' . $required)) $missing[] = $required;
    }

    foreach ([$export . '/skin.js', $export . '/govirtual-ai/frontend/guide.js'] as $js) {
        if (!is_file($js)) continue;
        $command = 'node --check ' . escapeshellarg($js) . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        if ($code !== 0) $syntax = false;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($export, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (in_array($ext, ['mp4', 'webm', 'mov', 'jpg', 'jpeg', 'png', 'webp'], true) && $file->getSize() > 20 * 1024 * 1024) {
            $large[] = ['file' => str_replace('\\', '/', substr($file->getPathname(), strlen($export) + 1)), 'mb' => round($file->getSize() / 1048576, 1)];
        }
    }

    $url = 'http://localhost/semarang/' . rawurlencode($folder) . '/';
    $http = http_probe($url);
    $issues = [];
    if (!in_array($http['status'], [200, 206], true)) $issues[] = 'HTTP_' . $http['status'];
    if ($http['ttfbMs'] > 500) $issues[] = 'SLOW_TTFB';
    if ($http['totalMs'] > 1500) $issues[] = 'SLOW_RESPONSE';
    if ($missing) $issues[] = 'MISSING_ASSET';
    if (!$syntax) $issues[] = 'JS_SYNTAX';
    if ($large) $issues[] = 'LARGE_MEDIA';

    $rows[] = [
        'id' => (int) $tour['id'], 'slug' => $tour['slug'], 'folder' => $folder, 'url' => $url,
        'http' => $http, 'htmlBytes' => $htmlBytes, 'missing' => $missing,
        'largeMedia' => $large, 'syntaxOk' => $syntax, 'issues' => $issues,
    ];
    echo sprintf("%3d %-28s HTTP=%3d TTFB=%4dms TOTAL=%4dms issues=%s\n", $tour['id'], $tour['slug'], $http['status'], $http['ttfbMs'], $http['totalMs'], $issues ? implode(',', $issues) : 'OK');
}

$summary = [
    'generatedAt' => date(DATE_ATOM),
    'count' => count($rows),
    'ok' => count(array_filter($rows, static fn(array $row): bool => !$row['issues'])),
    'failed' => count(array_filter($rows, static fn(array $row): bool => (bool) $row['issues'])),
    'rows' => $rows,
];
file_put_contents(__DIR__ . '/../storage/semarang-load-audit.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "SUMMARY count={$summary['count']} ok={$summary['ok']} failed={$summary['failed']}\n";
