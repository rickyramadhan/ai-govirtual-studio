<?php
declare(strict_types=1);

$names = [
    'balaikota-semarang' => 'Balai Kota Semarang',
    'firdausfatimahzahra' => 'Firdaus Fatimah Zahra',
    'gamacandiresto' => 'Gama Candi Resto',
    'gedunggubernur' => 'Gedung Gubernur Jawa Tengah',
    'gerejakatedral' => 'Gereja Katedral Semarang',
    'goakreo' => 'Goa Kreo',
    'gortlj' => 'GOR Tri Lomba Juang',
    'hotelpadma' => 'Hotel Padma Semarang',
    'hotelrooms' => 'Hotel Rooms Inc Semarang',
    'hoteltentrem' => 'Hotel Tentrem Semarang',
    'immoderma' => 'Immoderma',
    'indonesiakaya' => 'Taman Indonesia Kaya',
    'kampungbatik' => 'Kampung Batik',
    'kampungbustaman' => 'Kampung Bustaman',
    'kampungjajanpasar' => 'Kampung Jajan Pasar',
    'kampungjawi' => 'Kampung Jawi',
    'kampunglaut' => 'Kampung Laut',
    'kampungmelayu' => 'Kampung Melayu',
    'kawasanolahragajatidiri' => 'Kawasan Olahraga Jatidiri',
    'kawasanpecinan' => 'Kawasan Pecinan',
    'kebunkramas' => 'Kebun Kramas',
    'kepalamanyungbufat' => 'Kepala Manyung Bu Fat',
    'kiw' => 'Kawasan Industri Wijayakusuma',
    'koenakoeni' => 'Koenokoeni Cafe Gallery',
    'koetatoea' => 'Koeta Toea',
    'loempialien' => 'Loempia Lien',
    'malltentrem' => 'Tentrem Mall Semarang',
    'mandhalabakti' => 'Museum Mandala Bhakti',
    'masjidbaiturrahman' => 'Masjid Baiturrahman Semarang',
    'masjidkauman' => 'Masjid Kauman Semarang',
    'mcc' => 'Marina Convention Center',
    'mpp' => 'Mal Pelayanan Publik Semarang',
    'muladidome' => 'Muladi Dome',
    'museumranggawarsita' => 'Museum Ranggawarsita',
    'pantaimarina' => 'Pantai Marina',
    'pantaitirang' => 'Pantai Tirang',
    'pasarjohar' => 'Pasar Johar',
    'pelabuhantanjungemas' => 'Pelabuhan Tanjung Emas',
    'pengasapanikan' => 'Sentra Pengasapan Ikan',
    'pestakeboenresto' => 'Pesta Keboen Restoran',
    'pojcity' => 'POJ City',
    'polda' => 'Polda Jawa Tengah',
    'polines' => 'Politeknik Negeri Semarang',
    'politeknikbumiakpelni' => 'Politeknik Bumi Akpelni',
    'polluxmall' => 'Pollux Mall Paragon',
    'poncol' => 'Stasiun Semarang Poncol',
    'pusatoleholeh' => 'Pusat Oleh-Oleh Semarang',
    'queencity' => 'Queen City Mall',
    'rsbhayangkara' => 'RS Bhayangkara Akpol Semarang',
    'rscolumbiaasia' => 'RS Columbia Asia Semarang',
    'rselisabeth' => 'RS St. Elisabeth Semarang',
    'rshermina' => 'RS Hermina Banyumanik',
    'rskariadi' => 'RSUP Dr. Kariadi Semarang',
    'rsroemani' => 'RS Roemani Muhammadiyah',
    'rstelogorejo' => 'Siloam Hospitals Semarang',
    'rsunimus' => 'RS UNIMUS',
    'rswongsonegoro' => 'RSUD K.R.M.T. Wongsonegoro',
    'sccr' => 'Semarang Contemporary Art Gallery',
    'semarangroyalegolf' => 'Semarang Royale Golf',
    'semarangzoo' => 'Semarang Zoo',
    'simpanglima' => 'Simpang Lima',
    'smashvillebadmintoncenter' => 'Smashville Badminton Center',
    'smpdomsav' => 'SMP Domenico Savio',
    'sotobangkong' => 'Soto Bangkong',
    'susurmangrove' => 'Susur Mangrove',
    'tahupetis' => 'Tahu Petis',
    'tamanbudayaradensaleh' => 'Taman Budaya Raden Saleh',
    'tamanlele' => 'Kampung Wisata Taman Lele',
    'tamantirtoagung' => 'Taman Tirto Agung',
    'terminalpetikemas' => 'Terminal Petikemas Semarang',
    'theparkmall' => 'The Park Mall Semarang',
    'tokooen' => 'Toko Oen',
    'tour-20260828100342' => 'Lawang Sewu',
    'udinus' => 'Universitas Dian Nuswantoro',
    'uinwalisongo' => 'UIN Walisongo Semarang',
    'unika' => 'Unika Soegijapranata',
    'unimaramni' => 'Universitas Maritim AMNI',
    'unimus' => 'Universitas Muhammadiyah Semarang',
    'unisbank' => 'Universitas Stikubank Semarang',
    'universitassemarang' => 'Universitas Semarang',
    'upgris' => 'Universitas PGRI Semarang',
    'uwhsemarang' => 'Universitas Wahid Hasyim Semarang',
    'viharawatugong' => 'Vihara Buddhagaya Watugong',
    'wingkobabad' => 'Wingko Babad',
];

$dbFile = dirname(__DIR__) . '/storage/app.sqlite';
$db = new PDO('sqlite:' . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$apply = in_array('--apply', $argv, true);
$select = $db->prepare("SELECT name FROM tours WHERE slug = ? AND lower(location) = 'semarang'");
$update = $db->prepare("UPDATE tours SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE slug = ? AND lower(location) = 'semarang'");
$changes = [];
foreach ($names as $slug => $name) {
    $select->execute([$slug]);
    $old = $select->fetchColumn();
    if ($old === false) throw new RuntimeException("Tur tidak ditemukan: $slug");
    if ($old === $name) continue;
    $changes[] = [$slug, $old, $name];
}
printf("Mode: %s\nChanges: %d\n", $apply ? 'APPLY' : 'DRY RUN', count($changes));
foreach ($changes as [$slug, $old, $name]) printf("%-30s %s => %s\n", $slug, $old, $name);
if (!$apply || !$changes) exit(0);
$backup = $dbFile . '.bak-tour-names-' . date('Ymd-His');
if (!copy($dbFile, $backup)) throw new RuntimeException('Backup database gagal.');
$db->beginTransaction();
try {
    foreach ($changes as [$slug, $old, $name]) $update->execute([$name, $slug]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
echo "Backup: $backup\nUpdated: " . count($changes) . "\n";
