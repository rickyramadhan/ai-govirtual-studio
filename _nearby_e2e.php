<?php
// End-to-end: POST "hotel dekat sini" ke master tour-chat.php, pastikan provider AI dipanggil (bukan curated).
$payload = [
    'message' => 'hotel dekat sini',
    'currentScene' => 's1',
    'tourContext' => [
        'scenes' => [
            ['id' => 's1', 'name' => 'Gereja Santo Yusuf', 'displayName' => 'Gereja Santo Yusuf', 'placeId' => 'p1'],
        ],
        'hotspots' => [],
        'routes' => [],
        'video360' => [],
    ],
    'conversationState' => [],
    'history' => [],
];
$ctx = ['QUIET' => '1'];
$json = json_encode($payload);
// pipe stdin
$spec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$php = 'C:\\xampp\\php\\php.exe';
$proc = proc_open([$php, 'C:/xampp/htdocs/govirtual-ai/govirtual-ai/api/tour-chat.php'], $spec, $pipes, 'C:/xampp/htdocs/govirtual-ai/govirtual-ai/api');
fwrite($pipes[0], $json);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
proc_close($proc);
echo "STDERR: " . trim($err) . PHP_EOL;
$d = json_decode($out, true);
echo "PROVIDER: " . ($d['provider'] ?? '(none)') . PHP_EOL;
echo "INTENT:   " . ($d['intent'] ?? '(none)') . PHP_EOL;
echo "REPLY:    " . mb_substr((string)($d['reply'] ?? ''), 0, 400) . PHP_EOL;
