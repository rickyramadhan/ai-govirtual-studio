<?php

declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$root = dirname(__DIR__);
$source = 'C:/Users/Ricky/Downloads/Semarang_Revisi_80_Lokasi_CSV/csv';
$protectedManifest = $root . '/tools/semarang-discovered.json';

function compact_text(mixed $value): string
{
    return trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
}

function key_name(string $value): string
{
    $value = strtolower(pathinfo($value, PATHINFO_FILENAME));
    $value = preg_replace('/^scenes-|(?:[-_](?:updated|revisi|unique))+$/i', '', $value) ?? $value;
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function sentence(string $value): string
{
    $value = compact_text($value);
    return $value === '' ? '' : rtrim($value, ". \t\n\r\0\x0B") . '.';
}

function local_facts(string $root, string $slug): array
{
    $facts = [];
    $knowledge = json_decode((string)@file_get_contents($root . '/storage/tours/' . $slug . '/knowledge.json'), true);
    foreach (($knowledge['entries'] ?? []) as $entry) {
        if (str_starts_with((string)($entry['id'] ?? ''), 'seed:')) continue;
        $content = compact_text($entry['translations']['id']['content'] ?? $entry['content'] ?? '');
        if (mb_strlen($content) >= 40 && !preg_match('/perlu diisi|template starter|pusat pengalaman virtual/iu', $content)) $facts[] = $content;
    }
    $parsed = json_decode((string)@file_get_contents($root . '/storage/tours/' . $slug . '/parsed.json'), true);
    foreach (($parsed['objects'] ?? []) as $object) {
        $content = compact_text($object['description'] ?? '');
        if (mb_strlen($content) >= 40) $facts[] = $content;
    }
    return array_values(array_unique($facts));
}

function guide_questions(array $scene, array $facts, int $sceneIndex): array
{
    $name = compact_text($scene['displayName'] ?? $scene['rawName'] ?? 'panorama ini');
    $place = compact_text($scene['placeName'] ?? 'tempat ini');
    $short = sentence(compact_text($scene['shortDescription'] ?? ''));
    $description = sentence(compact_text($scene['description'] ?? ''));
    $highlights = array_values(array_unique(array_filter(array_map('compact_text', is_array($scene['highlights'] ?? null) ? $scene['highlights'] : []))));
    $first = $highlights[0] ?? 'tata ruang';
    $second = $highlights[1] ?? 'suasana kawasan';
    $sceneFact = $short !== '' ? $short : ($description !== '' ? $description : sentence($name . ' merupakan bagian dari ' . $place));
    $placeFact = $facts ? sentence($facts[$sceneIndex % count($facts)]) : $sceneFact;
    $context = compact_text($sceneFact . ' ' . ($description !== $sceneFact ? $description . ' ' : '') . $placeFact);

    $sets = [
        [['Apa fungsi utama ' . $name . ' di kawasan ini?', $sceneFact], ['Bagian mana yang paling membantu memahami fungsi ' . $name . '?', $first . ' dan ' . $second . ' memperjelas susunan serta penggunaan ruang. ' . $sceneFact], ['Apa fakta penting tentang ' . $place . ' yang berkaitan dengan sudut ini?', $placeFact]],
        [['Mengapa ' . $name . ' menjadi bagian penting dari ' . $place . '?', $context], ['Apa hubungan ' . $first . ' dengan aktivitas di ' . $name . '?', sentence($first . ' menjadi unsur yang berkaitan langsung dengan aktivitas pada area ini') . ' ' . $sceneFact], ['Apa yang membedakan ' . $name . ' dari bagian lain lokasi?', sentence('Sudut ini secara khusus menampilkan ' . $first . ' dan ' . $second) . ' ' . $sceneFact]],
        [['Apa latar tempat yang membantu memahami ' . $name . '?', $placeFact], ['Bagaimana susunan ruang di ' . $name . ' mendukung kegiatannya?', sentence('Susunan ' . $first . ' bersama ' . $second . ' menunjukkan cara area ini digunakan') . ' ' . $sceneFact], ['Detail apa yang sebaiknya tidak terlewat di ' . $name . '?', sentence($first . ' menjadi detail utama, sedangkan ' . $second . ' melengkapi konteksnya') . ' ' . $sceneFact]],
        [['Apa peran ' . $name . ' dalam pengalaman pengunjung di ' . $place . '?', $context], ['Mengapa ' . $first . ' penting pada ' . $name . '?', sentence($first . ' membantu menjelaskan identitas dan fungsi ' . $name) . ' ' . $sceneFact], ['Fakta lokal apa yang berkaitan langsung dengan ' . $name . '?', $placeFact]],
        [['Bagaimana ' . $name . ' menunjukkan karakter ' . $place . '?', $context], ['Apa yang dapat dipelajari dari hubungan ' . $first . ' dan ' . $second . ' di ' . $name . '?', sentence('Keduanya memperlihatkan hubungan antara bentuk ruang, fungsi, dan aktivitas pengunjung') . ' ' . $sceneFact], ['Detail mana yang paling informatif untuk mengenali ' . $name . '?', sentence($first . ' paling langsung membantu mengenali area ini') . ' ' . $sceneFact]],
        [['Mengapa pengunjung perlu mengenal ' . $name . '?', $context], ['Aktivitas apa yang didukung oleh susunan ' . $name . '?', sentence('Keberadaan ' . $first . ' dan ' . $second . ' memberi petunjuk tentang kegiatan yang berlangsung di sini') . ' ' . $sceneFact], ['Apa konteks ' . $place . ' yang membuat ' . $name . ' lebih bermakna?', $placeFact]],
    ];
    $selected = $sets[$sceneIndex % count($sets)];
    return array_map(static fn(array $item): array => ['q' => $item[0], 'mode' => 'fix', 'answer' => compact_text($item[1])], $selected);
}

$files = glob($source . '/*.csv') ?: [];
if (count($files) !== 80) throw new RuntimeException('Expected 80 CSV files, found ' . count($files));

$pdo = new PDO('sqlite:' . $root . '/storage/app.sqlite');
$tours = $pdo->query('SELECT slug, name, export_path FROM tours ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$index = [];
foreach ($tours as $tour) {
    foreach (array_unique([key_name($tour['slug']), key_name($tour['name']), key_name(basename($tour['export_path']))]) as $key) {
        if ($key !== '') $index[$key][$tour['slug']] = $tour;
    }
}

$protected = json_decode((string)file_get_contents($protectedManifest), true, 512, JSON_THROW_ON_ERROR);
$protectedSlugs = array_fill_keys(array_column($protected, 'slug'), true);
$protectedBefore = [];
foreach (array_keys($protectedSlugs) as $slug) {
    $path = $root . '/storage/tours/' . $slug . '/scene-identity.json';
    if (is_file($path)) $protectedBefore[$slug] = hash_file('sha256', $path);
}

$targets = [];
foreach ($files as $csv) {
    $matches = array_values($index[key_name(basename($csv))] ?? []);
    if (count($matches) !== 1) throw new RuntimeException('Ambiguous or missing tour for ' . basename($csv));
    $slug = $matches[0]['slug'];
    if (isset($protectedSlugs[$slug])) throw new RuntimeException('Protected tour entered target set: ' . $slug);
    $targets[$slug] = true;
}
if (count($targets) !== 80) throw new RuntimeException('Expected 80 unique targets, found ' . count($targets));

$pending = [];
$sceneCount = 0;
$questionCount = 0;
foreach (array_keys($targets) as $slug) {
    $path = $root . '/storage/tours/' . $slug . '/scene-identity.json';
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data['scenes'] ?? null)) throw new RuntimeException('Invalid scenes: ' . $slug);
    $facts = local_facts($root, $slug);
    $sceneIndex = 0;
    foreach ($data['scenes'] as &$scene) {
        $scene['suggestedQuestions'] = guide_questions($scene, $facts, $sceneIndex++);
        $sceneCount++;
        $questionCount += 3;
    }
    unset($scene);
    $pending[$path] = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}

printf("%s: %d target tours, %d scenes, %d questions; %d protected tours\n", $apply ? 'APPLY' : 'DRY RUN', count($targets), $sceneCount, $questionCount, count($protectedSlugs));
if (!$apply) exit(0);

$backup = $root . '/storage/tours/.guide-question-backup-' . date('Ymd-His');
foreach ($pending as $path => $content) {
    $slug = basename(dirname($path));
    if (!is_dir($backup) && !mkdir($backup, 0777, true) && !is_dir($backup)) throw new RuntimeException('Cannot create backup');
    if (!copy($path, $backup . '/' . $slug . '.json')) throw new RuntimeException('Backup failed: ' . $slug);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $path)) throw new RuntimeException('Write failed: ' . $slug);
}
foreach ($protectedBefore as $slug => $hash) {
    $path = $root . '/storage/tours/' . $slug . '/scene-identity.json';
    if (!hash_equals($hash, hash_file('sha256', $path))) throw new RuntimeException('Protected tour changed: ' . $slug);
}

echo 'APPLIED. Backup: ' . $backup . PHP_EOL;
