<?php
require_once __DIR__ . '/ai-core.php';

$js = (string) file_get_contents(dirname(__DIR__) . '/data/tour-data.js');
$json = trim((string) preg_replace('/^.*?\bwindow\.GoVirtualTourData\s*=\s*/s', '', $js));
$json = (string) preg_replace('/;\s*$/s', '', $json);
echo "JSON prefix: " . substr($json, 0, 100) . "\n";
echo "JSON length: " . strlen($json) . "\n";
$raw = json_decode($json, true);
echo "Decode error: " . json_last_error_msg() . "\n";
echo "Raw type: " . gettype($raw) . "\n";
if (is_array($raw)) {
    echo "Raw keys: " . implode(', ', array_keys($raw)) . "\n";
    echo "Scenes count: " . count($raw['scenes'] ?? []) . "\n";
    echo "Hotspots count: " . count($raw['hotspots'] ?? []) . "\n";
}

$tour = ai_canonical_tour(is_array($raw) ? $raw : []);
echo "Tour scenes: " . count($tour['scenes']) . "\n";
echo "Tour hotspots: " . count($tour['hotspots']) . "\n";

$graph = ai_graph($tour);
echo "Node51 neighbors: " . json_encode($graph['node51'] ?? []) . "\n";

$rec = ai_curated_recommend_destination('node51', $tour);
echo "Recommendation: ";
var_export($rec);
echo "\n";
