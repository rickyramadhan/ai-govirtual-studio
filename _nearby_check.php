<?php
// Hanya untuk CLI: jangan pernah dijalankan lewat web.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Verify: nearby queries no longer match curated G08_NEARBY (they go to AI provider).
require 'C:/xampp/htdocs/govirtual-ai/govirtual-ai/api/ai-core.php';

$queries = [
    'hotel dekat sini',
    'Sekitar Gereja Santo Yusuf hotel apa saja',
    'restoran dekat sini',
    'masjid terdekat',
    'tempat wisata sekitar sini',
    'Sejarah Gereja Santo Yusuf',
    'Jam buka museum ini',
];

foreach ($queries as $q) {
    $m = ai_curated_match($q, []);
    if ($m) {
        echo str_pad($q, 45) . ' => curated ' . $m['id'] . ' [' . $m['category'] . ']' . PHP_EOL;
    } else {
        $intent = ai_classify_intent($q);
        echo str_pad($q, 45) . ' => NO curated match, intent=' . $intent . ' (goes to AI provider)' . PHP_EOL;
    }
}
