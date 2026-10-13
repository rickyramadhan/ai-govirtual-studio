<?php
declare(strict_types=1);

$db = new PDO('sqlite:' . dirname(__DIR__) . '/storage/app.sqlite');
$slugs = $db->query("SELECT slug FROM tours WHERE export_path != '' AND lower(location) = 'semarang' ORDER BY slug")
    ->fetchAll(PDO::FETCH_COLUMN);
$errors = [];
$objects = 0;

foreach ($slugs as $slug) {
    $root = dirname(__DIR__) . '/storage/tours/' . $slug;
    $parsed = json_decode((string)@file_get_contents($root . '/parsed.json'), true);
    $knowledge = json_decode((string)@file_get_contents($root . '/knowledge.json'), true);
    $identity = json_decode((string)@file_get_contents($root . '/scene-identity.json'), true);
    $guide = (string)@file_get_contents($root . '/package/govirtual-ai/frontend/guide.js');
    $local = [];
    foreach (($parsed['objects'] ?? []) as $object) {
        if (isset($object['infoId'])) {
            $local[(string)$object['infoId']] = $object;
        }
    }
    $known = [];
    foreach (($knowledge['entries'] ?? []) as $entry) {
        if (preg_match('/objinfo2:(\d+)/', (string)($entry['id'] ?? ''), $match)) {
            $known[$match[1]] = true;
        }
    }
    $objects += count($local);
    if (!$local) $errors[] = "$slug: parsed object catalog empty";
    foreach (($identity['scenes'] ?? []) as $sceneId => $scene) {
        foreach (($scene['relatedKnowledgeIds'] ?? []) as $relatedId) {
            if (!preg_match('/objinfo2:(\d+)/', (string)$relatedId, $match)) continue;
            $object = $local[$match[1]] ?? null;
            if (!$object || (string)($object['sceneId'] ?? '') !== (string)$sceneId) {
                $errors[] = "$slug/$sceneId: related object {$match[1]} is not owned by this panorama";
            }
        }
    }
    if ($guide !== '') {
        if (str_contains($guide, 'proxyImg(') || str_contains($guide, "api/img-proxy.php?url=")) {
            $errors[] = "$slug: runtime still routes object cover through img-proxy";
        }
        if (!str_contains($guide, 'if (data && data.objects) data.objects.forEach')) {
            $errors[] = "$slug: complete object tab does not use parsed object catalog";
        }
        if (!str_contains($guide, "fetch(basePath + 'api/objinfo-cover.php?info='")) {
            $errors[] = "$slug: direct object cover resolver missing";
        }
        if (!str_contains($guide, "o.sceneId === sceneId") || !str_contains($guide, "!local[m[1]]")) {
            $errors[] = "$slug: active panorama object scope guard missing";
        }
    }
}

printf("Tours: %d\nObjects: %d\nErrors: %d\n", count($slugs), $objects, count($errors));
foreach ($errors as $error) echo "FAIL $error\n";
exit($errors ? 1 : 0);
