<?php
/** Build Semarang ZIP manifest from UNIQUE inventory and WebView link-prefix evidence. */
declare(strict_types=1);

$inventory = __DIR__ . '/semarang-zip-inventory.csv';
$dump = 'C:/xampp/htdocs/semarang/webview/semarang-260828-fix.sql';
$output = __DIR__ . '/semarang-zip-tours.json';

function tuples(string $sql, string $table): array
{
    $rows = [];
    $needle = 'INSERT INTO `' . $table . '`';
    for ($at = 0; ($at = strpos($sql, $needle, $at)) !== false; $at++) {
        $at = strpos($sql, 'VALUES', $at + strlen($needle));
        if ($at === false) break;
        $at += 6; $tuple = ''; $depth = 0; $quoted = false; $escaped = false;
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
            if ($char === ')' && $depth && --$depth === 0) { $rows[] = str_getcsv($tuple, ',', "'", '\\'); $tuple = ''; continue; }
            if ($char === ';' && $depth === 0) break;
            if ($depth) $tuple .= $char;
        }
    }
    return $rows;
}

function key_name(string $name): string
{
    $name = strtolower(preg_replace('/-26\d{4}$/', '', pathinfo($name, PATHINFO_FILENAME)));
    return preg_replace('/[^a-z0-9]/', '', $name);
}

function display_name(string $folder): string
{
    $name = preg_replace('/-26\d{4}$/', '', $folder);
    return ucwords(trim(preg_replace('/[-_]+/', ' ', $name)));
}

// Explicit aliases matched against db_items_2 location rows and db_items_jlj links.
$aliases = [
    'firdausfatimahzahra' => 39,
    'gedunggubernur' => 98,
    'gortlj' => 94,
    'hotelrooms' => 78,
    'kampungbatik' => 7,
    'kampungjajanpasar' => 114,
    'kawasanolahragajatidiri' => 93,
    'kawasanpecinan' => 26,
    'kiw' => 73,
    'klenteng' => 40,
    'loempialien' => 2228,
    'malltentrem' => 107,
    'mandhalabakti' => 8,
    'muladidome' => 80,
    'pelabuhantanjungemas' => 90,
    'pestakeboenresto' => 113,
    'politeknikbumiakpelni' => 49,
    'queencity' => 108,
    'rsbhayangkara' => 68,
    'rshermina' => 66,
    'semarangroyalegolf' => 24,
    'semesta' => 52,
    'smashvillebadmintoncenter' => 101,
    'tamanbudayaradensaleh' => 3,
    'tamanlele' => 12,
    'tamantirtoagung' => 21,
    'terminalpetikemas' => 400,
    'upgris' => 46,
];

// Same 17 scenes/hotspots as rstelogorejo; only older JPG tile encoding differs.
$semanticDuplicates = ['rstelogorejo2' => 'rstelogorejo'];

$sql = file_get_contents($dump);
if ($sql === false) throw new RuntimeException('dump gagal dibaca');
$links = [];
foreach (tuples($sql, 'db_items_jlj') as $row) {
    if (count($row) !== 17 || trim($row[8]) !== 'id') continue;
    $link = trim($row[6]);
    if (!preg_match('~^([^/]+)/index\.html(?:#|$)~i', $link, $match)) continue;
    $links[key_name($match[1])][(int)$row[12]] = true;
}

$csv = fopen($inventory, 'rb');
if ($csv === false) throw new RuntimeException('inventory gagal dibuka');
fgetcsv($csv);
$tours = []; $unmatched = []; $ambiguous = [];
while (($row = fgetcsv($csv)) !== false) {
    if (count($row) < 2 || $row[1] !== 'UNIQUE') continue;
    $folder = pathinfo($row[0], PATHINFO_FILENAME);
    $key = key_name($folder);
    if (isset($semanticDuplicates[$key])) continue;
    $parents = isset($aliases[$key]) ? [$aliases[$key]] : array_keys($links[$key] ?? []);
    if (!$parents) { $unmatched[] = $folder; continue; }
    if (count($parents) !== 1) { $ambiguous[$folder] = $parents; continue; }
    $tours[] = [
        'folder' => $folder,
        'slug' => strtolower(preg_replace('/[^a-z0-9]+/', '-', preg_replace('/-26\d{4}$/', '', $folder))),
        'name' => display_name($folder),
        'category' => 'Pariwisata',
        'parentId' => $parents[0],
    ];
}
fclose($csv);

$manifest = ['exportRoot' => 'C:/xampp/htdocs/semarang', 'webviewDump' => $dump, 'tours' => $tours];
file_put_contents($output, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo 'matched=' . count($tours) . ' unmatched=' . count($unmatched) . ' ambiguous=' . count($ambiguous) . "\n";
if ($unmatched) {
    echo 'UNMATCHED ' . json_encode($unmatched, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    foreach ($unmatched as $folder) {
        $key = key_name($folder);
        $candidates = [];
        foreach ($links as $linkKey => $parents) {
            $distance = levenshtein($key, $linkKey);
            if (str_contains($key, $linkKey) || str_contains($linkKey, $key)) $distance -= 5;
            $candidates[] = [$distance, $linkKey, array_keys($parents)];
        }
        usort($candidates, fn(array $a, array $b): int => $a[0] <=> $b[0]);
        echo 'CANDIDATE ' . $folder . ' ' . json_encode(array_slice($candidates, 0, 3), JSON_UNESCAPED_SLASHES) . "\n";
    }
}
if ($ambiguous) echo 'AMBIGUOUS ' . json_encode($ambiguous, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if ($unmatched || $ambiguous) exit(2);
