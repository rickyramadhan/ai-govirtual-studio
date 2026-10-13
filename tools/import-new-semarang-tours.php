<?php
/** Batch import, seed, build, deploy, and verify Semarang Pano2VR exports. */
declare(strict_types=1);

ob_start();
require_once dirname(__DIR__) . '/index.php';
ob_end_clean();

$manifestArg = array_values(array_filter(array_slice($argv ?? [], 1), fn(string $arg): bool => !str_starts_with($arg, '--')));
$manifestPath = $manifestArg[0] ?? (__DIR__ . '/semarang-tours.json');
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$tours = $manifest['tours'] ?? [];
if (!$tours) throw new RuntimeException('manifest tidak memiliki tour');
$exportRoot = rtrim((string)($manifest['exportRoot'] ?? 'C:/xampp/htdocs/semarang'), '/\\');

function sql_tuples(string $sql, string $table): array
{
    $rows = [];
    $needle = 'INSERT INTO `' . $table . '`';
    for ($at = 0; ($at = strpos($sql, $needle, $at)) !== false; $at++) {
        $at = strpos($sql, 'VALUES', $at + strlen($needle));
        if ($at === false) break;
        $at += 6;
        $tuple = '';
        $depth = 0;
        $quoted = false;
        $escaped = false;
        for ($n = strlen($sql); $at < $n; $at++) {
            $char = $sql[$at];
            if ($quoted) {
                $tuple .= $char;
                if ($escaped) $escaped = false;
                elseif ($char === '\\') $escaped = true;
                elseif ($char === "'") $quoted = false;
                continue;
            }
            if ($char === "'") { $quoted = true; if ($depth) $tuple .= $char; continue; }
            if ($char === '(') { if ($depth++) $tuple .= $char; continue; }
            if ($char === ')' && $depth && --$depth === 0) {
                $rows[] = str_getcsv($tuple, ',', "'", '\\');
                $tuple = '';
                continue;
            }
            if ($char === ';' && $depth === 0) break;
            if ($depth) $tuple .= $char;
        }
    }
    return $rows;
}

function webview_objects(string $dumpPath): array
{
    $sql = file_get_contents($dumpPath);
    if ($sql === false) throw new RuntimeException('dump WebView gagal dibaca');
    $byParent = [];
    foreach (sql_tuples($sql, 'db_items_jlj') as $row) {
        if (count($row) !== 17 || trim($row[8]) !== 'id') continue;
        $parent = (int)$row[12];
        $link = trim($row[6]);
        $fragment = str_contains($link, '#') ? rawurldecode(substr($link, strpos($link, '#') + 1)) : '';
        $byParent[$parent][] = [
            'infoId' => (int)$row[9],
            'label' => trim($row[1]),
            'description' => trim($row[3]) ?: trim($row[4]),
            'fragment' => strtolower(trim($fragment)),
            'link' => $link,
        ];
    }
    return $byParent;
}

function map_webview_objects(array $objects, array $scenes): array
{
    $aliases = [];
    foreach ($scenes as $scene) {
        foreach (array_unique(array_filter(array_merge(
            [(string)($scene['customNodeId'] ?? '')],
            $scene['aliases'] ?? []
        ))) as $alias) {
            $aliases[strtolower(rawurldecode($alias))] = (string)$scene['id'];
            $aliases[preg_replace('/[^a-z0-9]/', '', strtolower(rawurldecode($alias)))] = (string)$scene['id'];
        }
    }
    $mapped = [];
    foreach ($objects as $object) {
        $fragment = $object['fragment'];
        $sceneId = $fragment === '' ? '' : ($aliases[$fragment] ?? $aliases[preg_replace('/[^a-z0-9]/', '', $fragment)] ?? '');
        $object['sceneId'] = $sceneId;
        $mapped[] = $object;
    }
    return $mapped;
}

if (in_array('--discover', $argv ?? [], true)) {
    $known = array_column($tours, 'folder');
    $found = [];
    foreach (new DirectoryIterator($exportRoot) as $entry) {
        if (!$entry->isDir() || $entry->isDot()) continue;
        $folder = $entry->getFilename();
        $path = $entry->getPathname();
        if (in_array($folder, $known, true) || !is_file($path . '/index.html') || !is_file($path . '/pano.xml')) continue;
        $found[] = ['folder' => $folder, 'slug' => slugify($folder), 'name' => ucwords(str_replace(['-', '_'], ' ', $folder))];
    }
    echo json_encode($found, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$objectsByParent = webview_objects((string)$manifest['webviewDump']);
$auditOnly = in_array('--audit', $argv ?? [], true);
$ok = 0;
$failed = [];

foreach ($tours as $entry) {
    foreach (['folder', 'slug', 'name', 'category', 'parentId'] as $key) {
        if (!array_key_exists($key, $entry)) throw new RuntimeException("manifest tour kehilangan $key");
    }
    $folder = (string)$entry['folder'];
    $slug = (string)$entry['slug'];
    $name = (string)$entry['name'];
    $category = (string)$entry['category'];
    $parentId = (int)$entry['parentId'];
    $exportPath = $exportRoot . '/' . $folder;
    echo "[$slug] $name: ";

    try {
        if (!is_file($exportPath . '/index.html') || !is_file($exportPath . '/pano.xml')) {
            throw new RuntimeException('index.html atau pano.xml tidak ditemukan');
        }

        $tour = null;
        if (!$auditOnly) {
            $lookup = db();
            $find = $lookup->prepare('SELECT id FROM tours WHERE slug=?');
            $find->execute([$slug]);
            $id = (int)($find->fetchColumn() ?: 0);
            $find = null;
            $lookup = null;
            $id = save_tour([
                'id' => $id,
                'slug' => $slug,
                'name' => $name,
                'location' => 'Semarang',
                'category' => $category,
                'export_path' => $exportPath,
            ]);
            $tour = get_tour($id);
            if (!$tour) throw new RuntimeException('record tour gagal dibaca');
        }

        $parsed = parse_pano($exportPath);
        $parsed['skin'] = parse_skin($exportPath);
        $parsed['objects'] = map_webview_objects($objectsByParent[$parentId] ?? [], $parsed['scenes'] ?? []);
        $parsed['_exportState'] = export_snapshot($exportPath);
        $mappedCount = count(array_filter($parsed['objects'], fn(array $o): bool => $o['sceneId'] !== ''));
        $emptyCount = count(array_filter($parsed['objects'], fn(array $o): bool => $o['fragment'] === ''));
        $unmatchedCount = count($parsed['objects']) - $mappedCount - $emptyCount;
        echo count($parsed['objects']) . " objek ($mappedCount mapped, $emptyCount hash kosong, $unmatchedCount unmatched)";
        if ($auditOnly) { echo "\n"; $ok++; continue; }
        write_json(tour_dir($slug) . '/parsed.json', $parsed);
        seed_content($tour);
        $built = build_files($tour);
        deploy_package($tour);

        $html = (string)file_get_contents($exportPath . '/index.html');
        $checks = [
            'scene' => count($parsed['scenes'] ?? []) > 0,
            'object' => count($parsed['objects'] ?? []) > 0,
            'package' => is_file($exportPath . '/govirtual-ai/config/tour.json'),
            'frontend' => is_file($exportPath . '/govirtual-ai/frontend/guide.js'),
            'style' => is_file($exportPath . '/govirtual-ai/frontend/guide.css'),
            'data' => is_file($exportPath . '/govirtual-ai/data/tour-data.js'),
            'api' => is_file($exportPath . '/govirtual-ai/api/tour-chat.php'),
            'snippet' => substr_count($html, '<!-- GOVIRTUAL AI START -->') === 1,
        ];
        foreach ($checks as $check => $passed) {
            if (!$passed) throw new RuntimeException("verifikasi $check gagal");
        }

        echo ', ' . count($parsed['scenes'] ?? []) . ' scene, ' . count($built['files'] ?? []) . " file, OK\n";
        $ok++;
    } catch (Throwable $e) {
        $failed[$slug] = $e->getMessage();
        echo 'GAGAL: ' . $e->getMessage() . "\n";
    }
}

echo "\nHasil: $ok/" . count($tours) . " lokasi berhasil.\n";
if ($failed) {
    echo json_encode($failed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit(1);
}
