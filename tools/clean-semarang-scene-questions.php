<?php

declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$root = dirname(__DIR__);
$pdo = new PDO('sqlite:' . $root . '/storage/app.sqlite');
$st = $pdo->prepare("SELECT slug, name FROM tours WHERE lower(location)=? ORDER BY slug");
$st->execute(['semarang']);
$tours = $st->fetchAll(PDO::FETCH_ASSOC);
if (count($tours) !== 117) {
    fwrite(STDERR, 'ABORT: expected 117 Semarang tours, found ' . count($tours) . PHP_EOL);
    exit(1);
}

function text(mixed $value): string
{
    return trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
}

function normalized(string $value): string
{
    return mb_strtolower(preg_replace('/[^\pL\pN]+/u', '', $value) ?? '');
}

function strip_context_prefix(string $question, array $scene, string $tourName): string
{
    $names = array_filter([
        text($scene['displayName'] ?? ''),
        text($scene['placeName'] ?? ''),
    ]);
    foreach ($names as $name) {
        $quoted = preg_quote($name, '/');
        $question = preg_replace('/^Di\s+' . $quoted . '(?:\s*\([^)]*\))?\s*,\s*/iu', '', $question) ?? $question;
    }
    $display = preg_quote(text($scene['displayName'] ?? ''), '/');
    $tour = preg_quote(text($tourName), '/');
    if ($display !== '' && $tour !== '') {
        $question = preg_replace('/^Di\s+' . $display . '\s*\(\s*' . $tour . '\s*\)\s*,\s*/iu', '', $question) ?? $question;
    }
    return ucfirst(text($question));
}

function clean_answer(string $answer, array $scene, string $tourName): string
{
    $display = preg_quote(text($scene['displayName'] ?? ''), '/');
    $tour = preg_quote(text($tourName), '/');
    $patterns = [
        '/\s*Penjelasan ini berkaitan dengan\s+' . $display . '\s+di\s+' . $tour . '[^.]*\.?\s*$/iu',
        '/\s*Pada\s+' . $display . '\s+di\s+' . $tour . ',\s*(?:konteks yang tampak adalah|terlihat)\s+[^.]*\.?\s*$/iu',
    ];
    return text(preg_replace($patterns, '', $answer) ?? $answer);
}

function contextual_replacement(array $scene, int $index): array
{
    $name = text($scene['displayName'] ?? 'area ini');
    $short = text($scene['shortDescription'] ?? '');
    $description = text($scene['description'] ?? '');
    $highlights = array_values(array_filter(array_map('text', is_array($scene['highlights'] ?? null) ? $scene['highlights'] : [])));
    $facts = array_values(array_filter([$short, $description]));
    $fact = $facts[$index % max(1, count($facts))] ?? ('Area ini dikenal sebagai ' . $name . '.');
    $highlight = $highlights[$index % max(1, count($highlights))] ?? $name;
    $questions = [
        'Apa ciri utama ' . $name . '?',
        'Apa peran ' . $name . ' dalam kunjungan?',
        'Mengapa ' . lcfirst(rtrim($highlight, '.')) . ' menarik untuk diperhatikan?',
    ];
    $answers = [
        $short !== '' ? $short : $fact,
        $description !== '' ? $description : $fact,
        'Detail tersebut menjadi bagian penting dari ' . $name . '. ' . ($short !== '' ? $short : $fact),
    ];
    return ['q' => $questions[$index % 3], 'mode' => 'fix', 'answer' => text($answers[$index % 3])];
}

function is_generic(string $question): bool
{
    return (bool)preg_match('/^(Apa yang menarik di sini\?|Di mana saya sekarang\?|Ceritakan (?:area|tempat)\b)/iu', $question);
}

$stats = ['tours' => 0, 'scenes' => 0, 'questions' => 0, 'prefix' => 0, 'answers' => 0, 'generic' => 0, 'duplicate' => 0, 'changedFiles' => 0];
$changes = [];
$pending = [];

foreach ($tours as $tour) {
    $file = $root . '/storage/tours/' . $tour['slug'] . '/scene-identity.json';
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || !is_array($data['scenes'] ?? null)) {
        fwrite(STDERR, '[FAIL] invalid ' . $file . PHP_EOL);
        exit(1);
    }
    $fileChanged = false;
    foreach ($data['scenes'] as $sceneId => &$scene) {
        $stats['scenes']++;
        $items = is_array($scene['suggestedQuestions'] ?? null) ? $scene['suggestedQuestions'] : [];
        $seen = [];
        foreach ($items as $index => &$item) {
            $stats['questions']++;
            $object = is_array($item);
            $oldQuestion = text($object ? ($item['q'] ?? '') : $item);
            $question = strip_context_prefix($oldQuestion, $scene, (string)$tour['name']);
            if ($question !== $oldQuestion) $stats['prefix']++;
            $duplicate = isset($seen[normalized($question)]);
            $generic = is_generic($question);
            if ($duplicate || $generic || $question === '') {
                $replacement = contextual_replacement($scene, (int)$index);
                $question = $replacement['q'];
                $item = $replacement;
                $stats[$duplicate ? 'duplicate' : 'generic']++;
            } elseif ($object) {
                $item['q'] = $question;
                if (($item['mode'] ?? '') === 'fix') {
                    $oldAnswer = text($item['answer'] ?? '');
                    $item['answer'] = clean_answer($oldAnswer, $scene, (string)$tour['name']);
                    if ($item['answer'] !== $oldAnswer) {
                        $stats['answers']++;
                        $fileChanged = true;
                    }
                }
            } else {
                $item = $question;
            }
            $seen[normalized($question)] = true;
            if ($question !== $oldQuestion) {
                $fileChanged = true;
                if (count($changes) < 40) $changes[] = $tour['slug'] . '/' . $sceneId . ': ' . $oldQuestion . ' => ' . $question;
            }
        }
        unset($item);
        $scene['suggestedQuestions'] = $items;
    }
    unset($scene);
    $stats['tours']++;
    if ($fileChanged) {
        $stats['changedFiles']++;
        $pending[$file] = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}

foreach ($changes as $change) echo $change . PHP_EOL;
echo PHP_EOL . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (!$apply) {
    echo 'DRY RUN: no files changed. Use --apply after reviewing report.' . PHP_EOL;
    exit(0);
}

$backup = $root . '/storage/tours/.question-cleanup-backup-' . date('Ymd-His');
foreach ($pending as $file => $content) {
    $relative = substr($file, strlen($root . '/storage/tours/'));
    $backupFile = $backup . '/' . $relative;
    if (!is_dir(dirname($backupFile)) && !mkdir(dirname($backupFile), 0777, true) && !is_dir(dirname($backupFile))) throw new RuntimeException('Cannot create backup directory');
    if (!copy($file, $backupFile)) throw new RuntimeException('Backup failed: ' . $file);
}
foreach ($pending as $file => $content) {
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $file)) throw new RuntimeException('Write failed: ' . $file);
}
echo 'APPLIED: ' . count($pending) . ' files; backup=' . $backup . PHP_EOL;
