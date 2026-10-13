<?php

declare(strict_types=1);

$pdo = new PDO('sqlite:' . __DIR__ . '/../storage/app.sqlite');
$st = $pdo->prepare("SELECT slug, name, export_path FROM tours WHERE lower(location)=? ORDER BY slug");
$st->execute(['semarang']);
$tours = $st->fetchAll(PDO::FETCH_ASSOC);
if (count($tours) !== 117) {
    fwrite(STDERR, 'ABORT: expected 117 Semarang tours, found ' . count($tours) . PHP_EOL);
    exit(1);
}

$required = [
    'index.html',
    'pano.xml',
    'govirtual-ai/frontend/guide.js',
    'govirtual-ai/frontend/guide.css',
    'govirtual-ai/api/tour-chat.php',
    'govirtual-ai/config/tour.json',
    'govirtual-ai/data/tour-data.js',
    'govirtual-ai/data/scene-identity.json',
    'govirtual-ai/data/knowledge.json',
    'govirtual-ai/data/recommended-questions.json',
];
$ok = 0;
$fail = 0;
foreach ($tours as $tour) {
    $root = rtrim(str_replace('\\', '/', (string)$tour['export_path']), '/');
    $errors = [];
    foreach ($required as $relative) {
        if (!is_file($root . '/' . $relative) || filesize($root . '/' . $relative) === 0) $errors[] = 'missing/empty ' . $relative;
    }

    $html = (string)@file_get_contents($root . '/index.html');
    if (substr_count($html, '<!-- GOVIRTUAL AI START -->') !== 1 || substr_count($html, '<!-- GOVIRTUAL AI END -->') !== 1) $errors[] = 'AI snippet marker count invalid';
    foreach (['./govirtual-ai/frontend/guide.css', './govirtual-ai/data/tour-data.js', './govirtual-ai/frontend/guide.js'] as $asset) {
        if (!str_contains($html, $asset)) $errors[] = 'index missing ' . $asset;
    }

    $config = json_decode((string)@file_get_contents($root . '/govirtual-ai/config/tour.json'), true);
    $identity = json_decode((string)@file_get_contents($root . '/govirtual-ai/data/scene-identity.json'), true);
    $knowledge = json_decode((string)@file_get_contents($root . '/govirtual-ai/data/knowledge.json'), true);
    if (!is_array($config) || ($config['tourId'] ?? '') !== $tour['slug']) $errors[] = 'config tourId mismatch';
    if (!is_array($identity) || empty($identity['scenes'])) $errors[] = 'scene identity empty/invalid';
    if (!is_array($knowledge) || empty($knowledge['entries'])) $errors[] = 'knowledge empty/invalid';

    $tourData = (string)@file_get_contents($root . '/govirtual-ai/data/tour-data.js');
    if (!preg_match('/window\.GoVirtualTourData\s*=\s*(\{.*\});\s*$/s', $tourData, $m)) {
        $errors[] = 'tour-data.js invalid';
    } else {
        $data = json_decode($m[1], true);
        $scenes = is_array($data) ? ($data['scenes'] ?? []) : [];
        $prefix = is_array($data) ? (string)($data['thumbPrefix'] ?? '') : '';
        if (!$scenes) $errors[] = 'tour-data scenes empty';
        if ($prefix === '') $errors[] = 'thumbPrefix empty';
        $thumbs = $prefix === '' ? [] : (glob($root . '/images/' . $prefix . '*.webp') ?: []);
        if (!$thumbs) $errors[] = 'thumbnail files not found for prefix ' . $prefix;
        foreach ($scenes as $scene) {
            $id = (string)($scene['id'] ?? '');
            if ($id !== '' && !isset($identity['scenes'][$id])) {
                $errors[] = 'identity missing scene ' . $id;
                break;
            }
        }
        if (!$errors) {
            $sceneId = (string)($scenes[0]['id'] ?? '');
            $payload = json_encode([
                'aiRequestId' => 'verify-' . $tour['slug'],
                'message' => 'Saya sedang berada di mana?',
                'currentScene' => $sceneId,
                'history' => [],
                'conversationState' => [],
                'viewContext' => [],
                'tourContext' => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $command = 'C:\\xampp\\php\\php.exe ' . escapeshellarg($root . '/govirtual-ai/api/tour-chat.php');
            $pipes = [];
            $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root . '/govirtual-ai/api');
            if (!is_resource($process)) {
                $errors[] = 'AI endpoint process failed';
            } else {
                fwrite($pipes[0], $payload ?: '');
                fclose($pipes[0]);
                $response = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                $answer = json_decode((string)$response, true);
                if ($exit !== 0 || !is_array($answer) || trim((string)($answer['reply'] ?? '')) === '') {
                    $errors[] = 'AI answer failed' . ($stderr !== '' ? ': ' . trim($stderr) : '');
                }
            }
        }
    }

    if ($errors) {
        $fail++;
        echo '[FAIL] ' . $tour['slug'] . ': ' . implode('; ', array_unique($errors)) . PHP_EOL;
    } else {
        $ok++;
        echo '[OK] ' . $tour['slug'] . PHP_EOL;
    }
}

echo "RESULT: $ok OK, $fail FAIL" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
