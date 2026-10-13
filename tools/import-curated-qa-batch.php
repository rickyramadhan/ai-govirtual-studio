<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/qa_import.php';

const QA_SOURCE_DIR = 'C:/Users/Ricky/Downloads/drive-download-20260929T143058Z-1-001';
const QA_DB = __DIR__ . '/../storage/app.sqlite';
const QA_TOURS = __DIR__ . '/../storage/tours';

function norm(string $s): string {
    $s = strtolower(pathinfo($s, PATHINFO_FILENAME));
    $s = preg_replace('/^(split_80_(latest_locations|new_locations|varied_files)_qna_(80|variatif_80)_|faq_virtual_tour_|q\s*&\s*a\s*|curated-qa-)/i', '', $s) ?? $s;
    $s = preg_replace('/(_50_faq_complete|_50_faq_final|_50_faq|_complete|_final|_semarang|_50)$/i', '', $s) ?? $s;
    return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
}

$aliases = [
    'agrowisatacepoko'=>'agrocepoko','agrowisatapurwosari'=>'agropurwosari','asemskemkohliem'=>'asemasemkohliem',
    'balikota'=>'balaikota-semarang','balaikotasemarang'=>'balaikota-semarang','gedungbalaikota'=>'balaikota-semarang',
    'bandaraahmadyani'=>'bandara-ahmad-yani','bandengjuwanaelrina'=>'bandengjuwana','binusuniversity'=>'binus','bsbunikasoegijapranata'=>'bsbcity',
    'candigolfcountryclub'=>'candigolf','ciputramall'=>'ciputramall','desawisatanongkosawit'=>'nongkosawit',
    'dharmaboutiqueroastery'=>'dharmaboutique','gedunggubernurjawatengah'=>'gedunggubernur','goakreo'=>'goakreo',
    'gortrilombajuang'=>'gortlj','hotelroomsinc'=>'hotelrooms','kampungdjadoel'=>'kampungbatik',
    'kampungjajanpasarbangetayukulon'=>'kampungjajanpasar','kampungtematikdungtungkul'=>'dungtungkul',
    'kawasanolahragajatidiri'=>'kawasanolahragajatidiri','kawasankampungmelayu'=>'kampungmelayu',
    'kawasanindustriewijayakusuma'=>'kiw','klentengkwewiekiong'=>'klenteng','koetakoeta'=>'koetatoea',
    'koenokoeni'=>'koenakoeni','mallciputra'=>'ciputramall','marinaconventioncenter'=>'mcc','masjidagungjawatengah'=>'masjid-agung-jawa-tengah','masjidagungjawatengah50'=>'masjid-agung-jawa-tengah','museummandalabhakti'=>'mandhalabakti',
    'pasarjohar'=>'pasarjohar','pelabuhantanjungmas'=>'pelabuhantanjungemas','politekniknegerisemarangpolines'=>'polines',
    'polluxmallparagon'=>'polluxmall','ptkawasanindustriewijayakusuma'=>'kiw','pusatoleholehjalanpandanaran'=>'pusatoleholeh',
    'pusatoleholehjlnpanadaran'=>'pusatoleholeh','queenscitymall'=>'queencity','queencitymall'=>'queencity',
    'poldajateng'=>'polda','rsbhayangkaraakpol'=>'rsbhayangkara','rscolumbiaasia'=>'rscolumbiaasia','rsherminabanyumanik'=>'rshermina',
    'rsroemanimuhammadiyah'=>'rsroemani','rsstelisabeth'=>'rselisabeth','rstugu rsuddradhyatma'=>'rsud-tugu',
    'rstugursuddradhyatma'=>'rsud-tugu','rsudkrmtwongsonegoro'=>'rswongsonegoro','rsupdrkariadi'=>'rskariadi',
    'sam po kongsemarang'=>'sam-poo-kong','sampoekong'=>'sam-poo-kong','sampookong'=>'sam-poo-kong',
    'sandibuanafarm'=>'tour-20260828090023','semarangroyalegolf'=>'semarangroyalegolf','sentrapengasapanikan'=>'pengasapanikan',
    'semestaboardingschool'=>'semesta','smpdominikussavio'=>'smpdomsav','stasiunponcol'=>'poncol','stasiuntawang'=>'stasiun-tawang',
    'tahupetisyudhistira'=>'tahupetis','tamanindonesiakaya'=>'indonesiakaya','tentremmall'=>'malltentrem','terminalmangkangdanmpp'=>'mpp','universitasdiannuswantoroudinus'=>'udinus',
    'universitasdiannuswantoroudinus'=>'udinus','universitasmaritimamniunimaramni'=>'unimaramni',
    'universitasmuhammadiyahsemarangunimus'=>'unimus','universitasnegerisemarangunnes'=>'universitas-negeri-semarang',
    'universitaspgrisemarangupgris'=>'upgris','universitassemarangusm'=>'universitassemarang',
    'universitasstikubankunisbank'=>'unisbank','universitaswidyahusada'=>'uwhsemarang','universitaswidyahusadasemarang'=>'uwhsemarang','unikasoegijapranata'=>'unika',
    'akhtaracoffeeandskyview'=>'akhtara','muladidomeundip'=>'muladidome','nirwanastable'=>'nirwana','pecinansemarang'=>'kawasanpecinan',
    'ptkawasanindustriewijayakusuma'=>'kiw','sampookongsemarang'=>'sam-poo-kong','susurmangrovedesatapak'=>'susurmangrove','unnessemarang'=>'universitas-negeri-semarang',
    'tinjomoyoadventurepark'=>'tinjomoyo','wingkobabadcapkeretaapi'=>'wingkobabad','wisatatamanlele'=>'tamanlele','ziebatik'=>'zie-batik',
];
$aliases = array_combine(array_map('norm', array_keys($aliases)), array_values($aliases));
$aliases['ptkawasanindustriewijayakusuma'] = 'kiw';

$db = new PDO('sqlite:' . QA_DB);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$tours = $db->query('SELECT id,slug,name,export_path FROM tours ORDER BY id')->fetchAll();
$byNorm = [];
foreach ($tours as $tour) {
    foreach ([norm($tour['slug']), norm($tour['name']), norm(basename($tour['export_path']))] as $key) if ($key !== '') $byNorm[$key][$tour['slug']] = $tour;
}

$files = glob(QA_SOURCE_DIR . '/*.xlsx') ?: [];
$mapped = []; $errors = [];
foreach ($files as $file) {
    $key = norm(basename($file));
    $slug = str_contains($key, 'kawasanindustriwijayakusuma') ? 'kiw' : ($aliases[$key] ?? null);
    $candidates = $slug ? array_values(array_filter($tours, fn($t) => $t['slug'] === $slug)) : array_values($byNorm[$key] ?? []);
    if (count($candidates) !== 1) {
        $errors[] = basename($file) . ' => ' . ($candidates ? 'AMBIGU' : 'TIDAK COCOK') . ' [' . $key . ']';
        continue;
    }
    $tour = $candidates[0];
    if ((int)$tour['id'] <= 22) continue; // 15 lokasi awal: IDs 1,2,10..22.
    try { $items = qa_parse_file_to_items($file, basename($file)); }
    catch (Throwable $e) { $errors[] = basename($file) . ' => PARSE: ' . $e->getMessage(); continue; }
    $mapped[$tour['slug']][] = ['file'=>$file, 'items'=>$items, 'tour'=>$tour];
}

if ($errors) {
    fwrite(STDERR, "Impor dibatalkan; pemetaan belum aman:\n- " . implode("\n- ", $errors) . "\n");
    exit(2);
}

$selected = [];
foreach ($mapped as $slug => $sets) {
    usort($sets, fn($a,$b) => count($b['items']) <=> count($a['items']) ?: strcmp(basename($b['file']), basename($a['file'])));
    $selected[$slug] = $sets[0];
    if (count($sets) > 1 && count($sets[0]['items']) === count($sets[1]['items'])) {
        fwrite(STDERR, "Impor dibatalkan; duplikat setara untuk $slug: " . implode(', ', array_map(fn($x)=>basename($x['file']), $sets)) . "\n");
        exit(3);
    }
}

$apply = in_array('--apply', $argv, true);
echo ($apply ? 'APPLY' : 'DRY RUN') . ': ' . count($selected) . " lokasi\n";
foreach ($selected as $slug => $set) {
    $tour = $set['tour']; $items = $set['items'];
    printf("%-32s %3d  %s\n", $slug, count($items), basename($set['file']));
    if (!$apply) continue;
    $data = ['version'=>'1.0','tourId'=>$slug,'description'=>'Curated Q&A database - team-editable. Matched BEFORE AI pipeline.','items'=>$items];
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n";
    $targets = [QA_TOURS . '/' . $slug . '/curated-qa.json', QA_TOURS . '/' . $slug . '/package/govirtual-ai/data/curated-qa.json'];
    $export = rtrim($tour['export_path'], '/\\') . '/govirtual-ai/data/curated-qa.json';
    if (is_dir(dirname($export))) $targets[] = $export;
    foreach ($targets as $target) {
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
        if (file_put_contents($target, $json, LOCK_EX) === false) throw new RuntimeException('Gagal menulis ' . $target);
    }
}
