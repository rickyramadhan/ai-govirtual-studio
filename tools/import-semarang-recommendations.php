<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/qa_import.php';

$sourceDir = 'C:/Users/Ricky/Downloads/rekomendasi';
$apply = in_array('--apply', $argv, true);
$aliases = [
    'agrowisatacepoko' => 'agrocepoko',
    'agrowisatukebunkramas' => 'kebunkramas',
    'agrowisatapurwosari' => 'agropurwosari',
    'angkringankampungjawi' => 'kampungjawi',
    'bandarajendralahmadyani' => 'bandara-ahmad-yani',
    'bsbunika' => 'unika',
    'candigolfcountryclub' => 'candigolf',
    'desawisatanongkosawit' => 'nongkosawit',
    'gedungbalaikotasemarang' => 'balaikota-semarang',
    'gedunggubernurjawatengah' => 'gedunggubernur',
    'poldajateng' => 'polda',
    'rsbhayangkaraakpol' => 'rsbhayangkara',
    'rstugu' => 'rsud-tugu',
    'semestaboardingschool' => 'semesta',
    'stasiunponcol' => 'poncol',
    'kampungdjadoelkampungbatik' => 'kampungbatik',
    'kampungjajanpasarbangetayukulon' => 'kampungjajanpasar',
    'kampungtematikdungtungkul' => 'dungtungkul',
    'kampungwisatatamanlele' => 'tamanlele',
    'kawasankampungmelayu' => 'kampungmelayu',
    'kawasanolahragajatidiri' => 'kawasanolahragajatidiri',
    'agrowisatakebunkramas' => 'kebunkramas',
    'hotelroomsinc' => 'hotelrooms',
    'susurmangrovedesatapak' => 'susurmangrove',
    'tentremmall' => 'malltentrem',
    'kiwsemarang' => 'kiw',
    'marinaconventioncenter' => 'mcc',
    'muladidomeundip' => 'muladidome',
    'museumperjuanganmandalabakti' => 'mandhalabakti',
    'pecinansemarang' => 'kawasanpecinan',
    'pusatoleholehjlnpanadaran' => 'pusatoleholeh',
    'queenscitymall' => 'queencity',
    'sandibuanafarm' => 'tour-20260828090023',
    'sentrapengasapanikan' => 'pengasapanikan',
    'susurmangrovedesatapakan' => 'susurmangrove',
    'tahupetisyudhistira' => 'tahupetis',
    'terminalmangkangdanmpp' => 'mpp',
    'tinjomoyoadventurepark' => 'tinjomoyo',
    'universitaswidyahusadasemarang' => 'uwhsemarang',
    'wingkobabadcapkeretaapi' => 'wingkobabad',
];
$skip = [
    'bsbcity' => 'workbook kosong',
    'kiwsemarang' => 'workbook kosong',
    'pojcity' => 'workbook kosong',
    'bsbunika' => 'pakai Question_UNIKA.xlsx untuk tur unika',
];

function key_name(string $value): string {
    $value = preg_replace('/^(downloads_)?question_/i', '', pathinfo($value, PATHINFO_FILENAME));
    $value = preg_replace('/\s*\(\d+\)$/', '', (string)$value);
    return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)$value));
}

if (!is_dir($sourceDir)) throw new RuntimeException("Folder tidak ditemukan: $sourceDir");
$db = new PDO('sqlite:' . dirname(__DIR__) . '/storage/app.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tours = $db->query("SELECT id, slug, name FROM tours WHERE lower(location)='semarang'")->fetchAll(PDO::FETCH_ASSOC);
$byKey = [];
foreach ($tours as $tour) {
    foreach (array_unique([key_name($tour['name']), key_name($tour['slug'])]) as $key) $byKey[$key][] = $tour['slug'];
}

$files = glob($sourceDir . '/*.{xlsx,csv}', GLOB_BRACE) ?: [];
if (!$files) throw new RuntimeException('Tidak ada file XLSX/CSV.');
$imports = $errors = [];
foreach ($files as $file) {
    $key = key_name(basename($file));
    if (isset($skip[$key])) {
        printf("SKIP: %s (%s)\n", basename($file), $skip[$key]);
        continue;
    }
    $slugs = isset($aliases[$key]) ? [$aliases[$key]] : array_values(array_unique($byKey[$key] ?? []));
    if (count($slugs) !== 1) {
        $errors[] = basename($file) . ': ' . ($slugs ? 'ambigu: ' . implode(', ', $slugs) : 'tur tidak cocok');
        continue;
    }
    try {
        $parsed = prompt_parse_file($file, basename($file));
        if (count($parsed['themes']) !== 6 || count($parsed['starters']) !== 3) {
            throw new RuntimeException('harus berisi 6 themes dan 3 starters');
        }
        $slug = $slugs[0];
        $fingerprint = hash('sha256', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (isset($imports[$slug]) && $imports[$slug]['fingerprint'] !== $fingerprint) {
            throw new RuntimeException('duplikat berbeda dengan ' . basename($imports[$slug]['file']));
        }
        $imports[$slug] = compact('file', 'parsed', 'fingerprint');
    } catch (Throwable $e) {
        $errors[] = basename($file) . ': ' . $e->getMessage();
    }
}

printf("Mode: %s\nFiles: %d\nMatched tours: %d\nErrors: %d\n", $apply ? 'APPLY' : 'DRY RUN', count($files), count($imports), count($errors));
foreach ($imports as $slug => $item) printf("%-30s %s\n", $slug, basename($item['file']));
foreach ($errors as $error) echo "ERROR: $error\n";
if ($errors) exit(1);
if (!$apply) exit(0);

$backupDir = dirname(__DIR__) . '/storage/backups/recommended-questions-' . date('Ymd-His');
if (!mkdir($backupDir, 0775, true) && !is_dir($backupDir)) throw new RuntimeException('Folder backup gagal dibuat.');
foreach ($imports as $slug => $item) {
    $target = dirname(__DIR__) . "/storage/tours/$slug/recommended-questions.json";
    if (!is_file($target)) throw new RuntimeException("Target tidak ditemukan: $slug");
    if (!copy($target, "$backupDir/$slug.json")) throw new RuntimeException("Backup gagal: $slug");
}
foreach ($imports as $slug => $item) {
    $target = dirname(__DIR__) . "/storage/tours/$slug/recommended-questions.json";
    $json = json_decode((string)file_get_contents($target), true, 512, JSON_THROW_ON_ERROR);
    $json['state1']['themes'] = $item['parsed']['themes'];
    $json['state1']['starters'] = $item['parsed']['starters'];
    $json['updatedAt'] = date('c');
    $encoded = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if (file_put_contents($target, $encoded, LOCK_EX) === false) throw new RuntimeException("Tulis gagal: $slug");
}
echo "Backup: $backupDir\nUpdated: " . count($imports) . "\n";
