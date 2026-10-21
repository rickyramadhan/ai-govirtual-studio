<?php
declare(strict_types=1);

// Tes integrasi alur tulis Studio pada SALINAN terisolasi (data asli tidak disentuh).
// Jalankan: php tests/integration_test.php   (exit code 1 bila ada yang gagal)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$php = PHP_BINARY;
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/hubtest-' . bin2hex(random_bytes(4));
mkdir($tmp . '/storage/tours/demo', 0777, true);
mkdir($tmp . '/storage/cities/kotatest', 0777, true);
mkdir($tmp . '/sessions', 0777, true);
foreach (['index.php', 'studio.php', 'qa_import.php', 'scene_import.php'] as $f) copy("$root/$f", "$tmp/$f");
mkdir("$tmp/app", 0777, true);
mkdir("$tmp/tools", 0777, true);
copy("$root/tools/worker.php", "$tmp/tools/worker.php");
foreach (glob("$root/app/*.php") ?: [] as $f) copy($f, "$tmp/app/" . basename($f));

$fail = 0;
function check(string $name, bool $ok, string $extra = ''): void { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $extra === '' ? '' : "  -> $extra") . "\n"; if (!$ok) $fail++; }
function req(array $spec, array $phpArgs = []): string {
    global $php, $tmp, $root;
    clearstatcache(); // request dijalankan di proses lain; jangan pakai cache stat basi
    $cmd = escapeshellarg($php) . ' ' . implode(' ', array_map('escapeshellarg', $phpArgs)) . ' ' . escapeshellarg("$root/tests/_request.php") . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg(base64_encode(json_encode($spec)));
    $out = (string)shell_exec($cmd . ' 2>&1');
    clearstatcache();
    return $out;
}
function post(array $get, array $post, array $session = [], bool $csrf = true): string {
    if ($csrf) $post['csrf_token'] = 'TESTTOKEN';
    $s = ['user' => ['id' => 1, 'username' => 'tester', 'role' => 'admin']] + ['x' => 1];
    return req(['get' => $get, 'post' => $post, 'session' => array_merge(['user' => ['id' => 1, 'username' => 'tester', 'role' => 'admin']], $session)]);
}
function getp(array $get, array $session = []): string {
    return req(['get' => $get, 'session' => array_merge(['user' => ['id' => 1, 'username' => 'tester', 'role' => 'admin']], $session)]);
}
function jr(string $rel): array { global $tmp; clearstatcache(); return json_decode((string)@file_get_contents("$tmp/$rel"), true) ?: []; }
function jw(string $rel, array $d): void { global $tmp; $p = "$tmp/$rel"; @mkdir(dirname($p), 0777, true); file_put_contents($p, json_encode($d, JSON_UNESCAPED_UNICODE)); }

// Inisialisasi DB lewat satu request, lalu isi fixture.
getp(['a' => 'list']);
$pdo = new PDO('sqlite:' . $tmp . '/storage/app.sqlite');
$now = date('c');
$pdo->prepare('INSERT INTO tours(slug,name,location,category,export_path,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute(['demo', 'Demo Tour', 'Kotatest', '', '', $now, $now]);
$pdo->prepare('INSERT INTO users(username,password_hash,role,created_at) VALUES(?,?,?,?)')->execute(['tester', password_hash('Lama-Pass-123', PASSWORD_DEFAULT), 'admin', $now]);
$pdo->prepare('INSERT INTO users(username,password_hash,role,created_at) VALUES(?,?,?,?)')->execute(['lawas', password_hash('admin123', PASSWORD_DEFAULT), 'admin', $now]);

// ---------- A. Knowledge ----------
jw('storage/tours/demo/knowledge.json', ['entries' => [
    ['id' => 'k1', 'title' => 'Sejarah', 'aliases' => ['hist'], 'type' => 'history', 'content' => 'Lama', 'tags' => ['auto', 'sejarah'], 'sceneIds' => ['node1', 'node2'], 'categories' => ['sejarah'], 'provenance' => 'import', 'translations' => ['id' => ['title' => 'Sejarah', 'content' => 'Lama'], 'en' => ['title' => 'History', 'content' => 'Old']]],
    ['id' => 'k2', 'title' => 'Hapus Saya', 'content' => 'x'],
]]);
post(['a' => 'saveKnowledge', 'id' => 1], ['entry_count' => 2, 'entry_0_id' => 'k1', 'entry_0_title' => 'Sejarah Baru', 'entry_0_aliases' => 'hist, lama', 'entry_0_type' => 'history', 'entry_0_content' => 'Isi baru', 'del_1' => '1', 'entry_1_id' => 'k2']);
$k = jr('storage/tours/demo/knowledge.json')['entries'] ?? [];
check('knowledge: entry yang dihapus hilang', count($k) === 1);
check('knowledge: judul/isi yang diedit tersimpan', ($k[0]['title'] ?? '') === 'Sejarah Baru' && ($k[0]['content'] ?? '') === 'Isi baru');
check('knowledge: sceneIds dipertahankan', ($k[0]['sceneIds'] ?? []) === ['node1', 'node2']);
check('knowledge: tags dipertahankan', ($k[0]['tags'] ?? []) === ['auto', 'sejarah']);
check('knowledge: terjemahan EN tidak ditimpa', ($k[0]['translations']['en']['title'] ?? '') === 'History');
check('knowledge: terjemahan ID ikut diperbarui', ($k[0]['translations']['id']['content'] ?? '') === 'Isi baru');
post(['a' => 'saveKnowledge', 'id' => 1], ['entry_count' => 1, 'entry_0_id' => 'k1', 'entry_0_title' => 'Sejarah Baru', 'entry_0_content' => 'Isi baru', 'add_entry' => '1']);
check('knowledge: tombol tambah entry menambah 1 entry', count(jr('storage/tours/demo/knowledge.json')['entries'] ?? []) === 2);

// ---------- B. Curated intents ----------
jw('storage/tours/demo/curated-answers.json', ['intents' => [['id' => 'G01', 'category' => 'a', 'aliases' => ['x'], 'keywords' => [], 'negativePatterns' => [], 'dynamic' => false, 'action' => 'nav', 'response' => 'JAWABAN', 'requiresState' => 'S1']]]);
post(['a' => 'saveCurated', 'id' => 1], ['intent_count' => 1, 'intent_0_id' => 'G01', 'intent_0_category' => 'b', 'intent_0_aliases' => 'x, y']);
$i = jr('storage/tours/demo/curated-answers.json')['intents'][0] ?? [];
check('curated: kategori/alias yang diedit tersimpan', ($i['category'] ?? '') === 'b' && ($i['aliases'] ?? []) === ['x', 'y']);
check('curated: response & requiresState dipertahankan', ($i['response'] ?? '') === 'JAWABAN' && ($i['requiresState'] ?? '') === 'S1');
check('curated: action & dynamic lama tidak di-reset', ($i['action'] ?? null) === 'nav' && ($i['dynamic'] ?? null) === false);

// ---------- C. Tips ----------
jw('storage/tours/demo/visitor-tips.json', ['topics' => [['id' => 't1', 'title' => 'Parkir', 'content' => 'A', 'category' => 'umum', 'aliases' => [], 'items' => [['label' => 'Biaya', 'detail' => '5rb'], ['label' => 'Lokasi', 'detail' => 'Depan']]]]]);
post(['a' => 'saveTips', 'id' => 1], ['topic_count' => 1, 'topic_0_id' => 't1', 'topic_0_title' => 'Parkir', 'topic_0_category' => 'umum', 'topic_0_content' => 'B']);
$t = jr('storage/tours/demo/visitor-tips.json')['topics'][0] ?? [];
check('tips: lebih dari satu item dipertahankan', count($t['items'] ?? []) === 2 && ($t['items'][0]['label'] ?? '') === 'Biaya');
check('tips: konten yang diedit tersimpan', ($t['content'] ?? '') === 'B');
jw('storage/tours/demo/visitor-tips.json', ['topics' => [['id' => 't2', 'title' => 'X', 'content' => 'lama', 'category' => 'u', 'aliases' => [], 'items' => [['label' => 'Info', 'detail' => 'lama']]]]]);
post(['a' => 'saveTips', 'id' => 1], ['topic_count' => 1, 'topic_0_id' => 't2', 'topic_0_title' => 'X', 'topic_0_category' => 'u', 'topic_0_content' => 'baru']);
check('tips: item "Info" tunggal ikut disegarkan', (jr('storage/tours/demo/visitor-tips.json')['topics'][0]['items'][0]['detail'] ?? '') === 'baru');

// ---------- D. Nearby ----------
jw('storage/tours/demo/nearby.json', ['items' => [['id' => 'n1', 'name' => 'Cafe', 'category' => 'kuliner', 'address' => '', 'distance' => '1km', 'travelTime' => '', 'aliases' => [], 'lat' => -6.9, 'lng' => 110.4]]]);
post(['a' => 'saveNearby', 'id' => 1], ['item_count' => 1, 'item_0_id' => 'n1', 'item_0_name' => 'Cafe Baru', 'item_0_category' => 'kuliner', 'item_0_distance' => '2km']);
$n = jr('storage/tours/demo/nearby.json')['items'][0] ?? [];
check('nearby: field yang diedit tersimpan', ($n['name'] ?? '') === 'Cafe Baru' && ($n['distance'] ?? '') === '2km');
check('nearby: field di luar form (lat/lng) dipertahankan', ($n['lat'] ?? null) === -6.9 && ($n['lng'] ?? null) === 110.4);

// ---------- E. City ----------
jw('storage/cities/kotatest/meta.json', ['name' => 'Kota Test', 'instructions' => 'ATURAN PENTING']);
jw('storage/cities/kotatest/knowledge.json', ['entries' => [['id' => 'c1', 'title' => 'Alun-alun', 'content' => 'Lama', 'source' => 'situs-resmi', 'url' => 'https://contoh.id', 'translations' => ['en' => ['title' => 'Square', 'content' => 'Old']]]]]);
$page = getp(['a' => 'city', 'city' => 'kotatest']);
$fp = strpos($page, '<form method="post" action="?a=citySave">'); $ip = strpos($page, 'name="instructions"'); $fe = strrpos($page, '</form>');
check('city: textarea instructions berada DI DALAM form', $fp !== false && $ip !== false && $fp < $ip && $ip < $fe);
post(['a' => 'citySave'], ['city' => 'kotatest', 'name' => 'Kota Test', 'entry_count' => 1, 'entry_0_id' => 'c1', 'entry_0_title' => 'Alun-alun', 'entry_0_content' => 'Baru']);
check('city: instructions tidak terhapus bila tidak dikirim', (jr('storage/cities/kotatest/meta.json')['instructions'] ?? '') === 'ATURAN PENTING');
$c = jr('storage/cities/kotatest/knowledge.json')['entries'][0] ?? [];
check('city: source/url hasil impor dipertahankan', ($c['source'] ?? '') === 'situs-resmi' && ($c['url'] ?? '') === 'https://contoh.id');
check('city: terjemahan EN dipertahankan', ($c['translations']['en']['title'] ?? '') === 'Square');
post(['a' => 'citySave'], ['city' => 'kotatest', 'name' => 'Kota Test', 'instructions' => 'ATURAN BARU', 'entry_count' => 0]);
check('city: instructions baru tersimpan', (jr('storage/cities/kotatest/meta.json')['instructions'] ?? '') === 'ATURAN BARU');

// ---------- F. CSRF ----------
jw('storage/tours/demo/nearby.json', ['items' => [['id' => 'n1', 'name' => 'Asli']]]);
$out = post(['a' => 'saveNearby', 'id' => 1], ['item_count' => 1, 'item_0_id' => 'n1', 'item_0_name' => 'DIUBAH-TANPA-TOKEN'], [], false);
check('csrf: POST tanpa token ditolak', str_contains($out, 'Token keamanan tidak valid'));
check('csrf: data tidak berubah saat ditolak', (jr('storage/tours/demo/nearby.json')['items'][0]['name'] ?? '') === 'Asli');
$out = req(['get' => ['a' => 'saveNearby', 'id' => 1], 'post' => ['csrf_token' => 'SALAH', 'item_count' => 1, 'item_0_id' => 'n1', 'item_0_name' => 'X'], 'session' => ['user' => ['id' => 1, 'username' => 'tester', 'role' => 'admin']]]);
check('csrf: token salah ditolak', str_contains($out, 'Token keamanan tidak valid'));

// ---------- R. Riwayat versi & tulis atomik ----------
$hist = glob("$tmp/storage/tours/demo/.history/knowledge-*.json") ?: [];
check('riwayat: versi lama knowledge tersimpan di .history', count($hist) >= 1);
$oldest = $hist ? json_decode((string)file_get_contents($hist[0]), true) : [];
check('riwayat: snapshot berisi isi SEBELUM disimpan', ($oldest['entries'][0]['content'] ?? '') === 'Lama');
check('tulis atomik: tidak ada file .tmp tersisa', !(glob("$tmp/storage/tours/demo/*.tmp-*") ?: []));
$snapCount = static fn(): int => count(glob("$tmp/storage/tours/demo/.history/nearby-*.json") ?: []);
$nSame = ['item_count' => 1, 'item_0_id' => 'n1', 'item_0_name' => 'Asli'];
post(['a' => 'saveNearby', 'id' => 1], $nSame);          // pertama: struktur record berubah (field kosong ditambahkan)
$afterFirst = $snapCount();
post(['a' => 'saveNearby', 'id' => 1], $nSame);          // kedua: persis sama
check('riwayat: simpan pertama yang mengubah isi membuat snapshot', $afterFirst >= 1);
check('riwayat: simpan identik tidak membuat snapshot baru', $snapCount() === $afterFirst, "sebelum=$afterFirst sesudah=" . $snapCount());

// ---------- R2. Halaman Riwayat & pemulihan ----------
$hp = getp(['a' => 'history', 'id' => 1]);
check('riwayat-ui: halaman menampilkan daftar versi & tombol Pulihkan', str_contains($hp, 'Pulihkan') && str_contains($hp, 'Pengetahuan AI'));
$snapName = basename($hist[0] ?? '');
$beforeRestore = jr('storage/tours/demo/knowledge.json')['entries'][0]['content'] ?? '';
post(['a' => 'restoreHistory', 'id' => 1], ['snapshot' => '../../../../etc/passwd']);
check('riwayat-pulih: nama file berisi path traversal ditolak', (jr('storage/tours/demo/knowledge.json')['entries'][0]['content'] ?? '') === $beforeRestore);
post(['a' => 'restoreHistory', 'id' => 1], ['snapshot' => $snapName]);
check('riwayat-pulih: versi lama dipulihkan (isi kembali ke "Lama")', (jr('storage/tours/demo/knowledge.json')['entries'][0]['content'] ?? '') === 'Lama');
check('riwayat-pulih: versi yang tertimpa ikut tercatat di riwayat', count(glob("$tmp/storage/tours/demo/.history/knowledge-*.json") ?: []) > count($hist));
post(['a' => 'restoreHistory', 'id' => 1], ['snapshot' => 'knowledge-20990101-000000-000.json']);
check('riwayat-pulih: versi yang tidak ada ditolak tanpa mengubah data', (jr('storage/tours/demo/knowledge.json')['entries'][0]['content'] ?? '') === 'Lama');
// Kembalikan state yang diharapkan tes berikutnya (judul "Sejarah Baru").
post(['a' => 'saveKnowledge', 'id' => 1], ['entry_count' => 2, 'entry_0_id' => 'k1', 'entry_0_title' => 'Sejarah Baru', 'entry_0_aliases' => 'hist, lama', 'entry_0_type' => 'history', 'entry_0_content' => 'Isi baru', 'entry_1_id' => 'x', 'entry_1_title' => 'Sisa', 'entry_1_content' => 'x']);

// ---------- S. Seed non-destruktif ----------
jw('storage/tours/demo/parsed.json', ['scenes' => [['id' => 'node1', 'name' => 'Lobi', 'displayName' => 'Lobi', 'aliases' => [], 'customNodeId' => '']], 'hotspots' => [], 'objects' => [], 'skin' => []]);
post(['a' => 'seedContent', 'id' => 1], []);
$k = jr('storage/tours/demo/knowledge.json')['entries'] ?? [];
check('seed: konten yang sudah ada TIDAK ditimpa', ($k[0]['title'] ?? '') === 'Sejarah Baru');
check('seed: file yang belum ada dibuat', is_file("$tmp/storage/tours/demo/scene-identity.json") && is_file("$tmp/storage/tours/demo/recommended-questions.json"));
$out = getp(['a' => 'seedContent', 'id' => 1]);
check('seed: reset ke template butuh konfirmasi (GET tidak menimpa)', str_contains($out, 'Timpa dengan template') && (jr('storage/tours/demo/knowledge.json')['entries'][0]['title'] ?? '') === 'Sejarah Baru');
post(['a' => 'seedContent', 'id' => 1], ['overwrite' => '1']);
check('seed: dengan overwrite, konten ditimpa template', (jr('storage/tours/demo/knowledge.json')['entries'][0]['title'] ?? '') !== 'Sejarah Baru');
$snap = glob("$tmp/storage/tours/demo/.history/knowledge-*.json") ?: []; $found = false;
foreach ($snap as $sf) if ((json_decode((string)file_get_contents($sf), true)['entries'][0]['title'] ?? '') === 'Sejarah Baru') $found = true;
check('seed: versi sebelum ditimpa masih ada di riwayat', $found);

// ---------- G. Hapus (konfirmasi + trash) ----------
$out = getp(['a' => 'delete', 'id' => 1]);
check('delete: GET hanya menampilkan konfirmasi', str_contains($out, 'confirm_name') && is_dir("$tmp/storage/tours/demo"));
post(['a' => 'delete', 'id' => 1], ['confirm_name' => 'salah']);
check('delete: nama konfirmasi salah -> tidak terhapus', is_dir("$tmp/storage/tours/demo") && (int)$pdo->query('SELECT COUNT(*) FROM tours')->fetchColumn() === 1);
post(['a' => 'delete', 'id' => 1], ['confirm_name' => 'Demo Tour']);
$trashed = glob("$tmp/storage/trash/tour-demo-*") ?: [];
check('delete: nama benar -> folder pindah ke trash', !is_dir("$tmp/storage/tours/demo") && count($trashed) === 1 && is_file($trashed[0] . '/_trashed.json'),
    'asal-masih-ada=' . (is_dir("$tmp/storage/tours/demo") ? 'ya[' . implode(',', array_map('basename', glob("$tmp/storage/tours/demo/*") ?: [])) . ']' : 'tidak') . ' jumlah-trash=' . count($trashed) . ' berkas=' . implode(',', array_map('basename', glob(($trashed[0] ?? "$tmp/none") . '/*') ?: [])));
check('delete: baris DB terhapus', (int)$pdo->query('SELECT COUNT(*) FROM tours')->fetchColumn() === 0);
check('delete: isi tour masih ada di trash (bisa dipulihkan)', is_file($trashed[0] . '/knowledge.json'));

// ---------- H. Akun / password ----------
$qa = function (string $cur, string $new, string $conf) use ($pdo): string {
    return post(['a' => 'saveAccount'], ['current_password' => $cur, 'new_password' => $new, 'confirm_password' => $conf]);
};
$hash0 = (string)$pdo->query("SELECT password_hash FROM users WHERE username='tester'")->fetchColumn();
$o = $qa('salah-total-1', 'Password-Baru-99', 'Password-Baru-99');
check('akun: password saat ini salah ditolak', str_contains($o, 'Password saat ini salah'));
$o = $qa('Lama-Pass-123', 'pendek1', 'pendek1');
check('akun: password < 10 karakter ditolak', str_contains($o, 'minimal 10'));
$o = $qa('Lama-Pass-123', 'admin12345', 'admin12345');
check('akun: password bawaan lama ditolak', str_contains($o, 'terlalu umum'));
$o = $qa('Lama-Pass-123', 'Password-Baru-99', 'Beda-Banget-11');
check('akun: konfirmasi tidak sama ditolak', str_contains($o, 'Konfirmasi password tidak sama'));
check('akun: hash tidak berubah selama semua penolakan', (string)$pdo->query("SELECT password_hash FROM users WHERE username='tester'")->fetchColumn() === $hash0);
$qa('Lama-Pass-123', 'Password-Baru-99', 'Password-Baru-99');
$h1 = (string)$pdo->query("SELECT password_hash FROM users WHERE username='tester'")->fetchColumn();
check('akun: ganti password valid berhasil', password_verify('Password-Baru-99', $h1) && !password_verify('Lama-Pass-123', $h1));

// ---------- K. Kota & validasi folder export (Fase 1) ----------
$pdo->prepare('INSERT INTO tours(slug,name,location,category,export_path,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute(['lama', 'Tour Lama', 'Kota Lama Teks', '', '', $now, $now]);
getp(['a' => 'list']); // satu request memicu db_migrate yang menghubungkan tour tanpa city_id ke kota
$cityOfLama = $pdo->query("SELECT c.slug, c.name FROM tours t JOIN cities c ON c.id=t.city_id WHERE t.slug='lama'")->fetch(PDO::FETCH_ASSOC);
check('kota: tour yang dimasukkan lewat SQL (tanpa city_id) dihubungkan otomatis ke kota', ($cityOfLama['slug'] ?? '') === 'kota-lama-teks' && ($cityOfLama['name'] ?? '') === 'Kota Lama Teks');
$expDir = sys_get_temp_dir() . '/hubexp-' . bin2hex(random_bytes(3)); mkdir($expDir, 0777, true); file_put_contents("$expDir/pano.xml", '<x/>');
$noPano = sys_get_temp_dir() . '/hubexp-nopano-' . bin2hex(random_bytes(3)); mkdir($noPano, 0777, true);
$newTour = ['id' => 0, 'name' => 'Tour Dua', 'slug' => 'tour-dua', 'new_city' => 'Kota Baru', 'category' => 'Pariwisata'];
$o = post(['a' => 'save'], $newTour + ['export_path' => 'C:/Windows']);
check('export: folder di luar htdocs ditolak, form menampilkan pesan', str_contains($o, 'harus berada di dalam htdocs') && (int)$pdo->query("SELECT COUNT(*) FROM tours WHERE slug='tour-dua'")->fetchColumn() === 0);
$o = post(['a' => 'save'], $newTour + ['export_path' => $expDir . '/tidak-ada']);
check('export: folder yang tidak ada ditolak', str_contains($o, 'tidak ditemukan'));
$o = post(['a' => 'save'], $newTour + ['export_path' => $noPano]);
check('export: folder tanpa pano.xml ditolak', str_contains($o, 'pano.xml tidak ada'));
check('export: nilai yang diketik dipertahankan di form saat error', str_contains($o, 'value="Tour Dua"') && str_contains($o, 'value="tour-dua"'));
post(['a' => 'save'], $newTour + ['export_path' => $expDir]);
$row = $pdo->query("SELECT t.location, c.slug, c.name FROM tours t JOIN cities c ON c.id=t.city_id WHERE t.slug='tour-dua'")->fetch(PDO::FETCH_ASSOC);
check('kota: tour baru dengan "Kota baru" membuat kota & menautkannya', ($row['slug'] ?? '') === 'kota-baru' && ($row['location'] ?? '') === 'Kota Baru');
$o = post(['a' => 'save'], ['id' => 0, 'name' => 'Dobel', 'slug' => 'tour-dua', 'new_city' => 'Kota Baru', 'export_path' => '']);
check('slug: slug yang sudah dipakai ditolak dengan pesan ramah', str_contains($o, 'Slug sudah dipakai'));
$o = post(['a' => 'save'], ['id' => 0, 'name' => 'Tanpa Kota', 'slug' => 'tanpa-kota', 'export_path' => '']);
check('kota: tour tanpa kota ditolak', str_contains($o, 'Kota wajib dipilih'));
$tid2 = (int)$pdo->query("SELECT id FROM tours WHERE slug='tour-dua'")->fetchColumn();
$o = post(['a' => 'save'], ['id' => $tid2, 'name' => 'Tour Dua Ubah', 'slug' => 'tour-dua', 'city_id' => (int)$pdo->query("SELECT id FROM cities WHERE slug='kotatest'")->fetchColumn(), 'export_path' => $expDir]);
$loc = $pdo->query("SELECT location FROM tours WHERE id=$tid2")->fetchColumn();
check('kota: memindahkan tour ke kota lain menyinkronkan teks location', $loc === 'Kotatest');
$o = getp(['a' => 'new']);
check('form tour: halaman memuat pilihan kota & saran export', str_contains($o, 'id="tour_city"') && str_contains($o, 'exportCandidates') && str_contains($o, 'Kota baru'));
@unlink("$expDir/pano.xml"); @rmdir($expDir); @rmdir($noPano);

// ---------- L. Dashboard: filter, pencarian, paginasi di server ----------
$cid = (int)$pdo->query("SELECT id FROM cities WHERE slug='kotatest'")->fetchColumn();
$ins = $pdo->prepare('INSERT INTO tours(slug,name,location,city_id,category,export_path,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
for ($i = 1; $i <= 30; $i++) $ins->execute(['bulk-' . $i, 'Bulk Tour ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'Kotatest', $cid, $i % 2 ? 'Pariwisata' : 'Agama', '', $now, $now]);
$d1 = getp(['a' => 'list']);
check('dashboard: paginasi tampil bila tour > 24 per halaman', str_contains($d1, 'class="pager"') && str_contains($d1, 'Berikutnya'));
$cards1 = substr_count($d1, '<article class="dv-card tour-card"');
check('dashboard: halaman 1 hanya berisi 24 kartu', $cards1 === 24, "kartu=$cards1");
$d2 = getp(['a' => 'list', 'page' => 2]);
check('dashboard: halaman 2 berisi sisanya', substr_count($d2, '<article class="dv-card tour-card"') === (int)$pdo->query('SELECT COUNT(*) FROM tours')->fetchColumn() - 24);
$d3 = getp(['a' => 'list', 'q' => 'Bulk Tour 07']);
check('dashboard: pencarian server-side hanya memunculkan yang cocok', substr_count($d3, '<article class="dv-card tour-card"') === 1 && str_contains($d3, 'Bulk Tour 07'));
$d4 = getp(['a' => 'list', 'cat' => 'Agama', 'city' => 'kotatest']);
check('dashboard: filter kategori+kota bekerja (15 tour Agama)', substr_count($d4, '<article class="dv-card tour-card"') === 15, 'kartu=' . substr_count($d4, '<article class="dv-card tour-card"'));
$d5 = getp(['a' => 'list', 'q' => 'tidak-ada-yang-cocok-xyz']);
check('dashboard: hasil kosong menampilkan pesan, bukan halaman kosong', str_contains($d5, 'Tidak ada yang cocok'));
$d6 = getp(['a' => 'list', 'sort' => 'name']);
check('dashboard: urut nama A-Z menaruh "Bulk Tour 01" lebih dulu dari "Tour Dua"', strpos($d6, 'Bulk Tour 01') !== false && strpos($d6, 'Bulk Tour 01') < strpos($d6, 'Tour Dua') || !str_contains($d6, 'Tour Dua'));
$d7 = getp(['a' => 'list', 'page' => 999]);
check('dashboard: nomor halaman berlebih dibatasi ke halaman terakhir', str_contains($d7, 'tour-card'));
$pdo->exec("DELETE FROM tours WHERE slug LIKE 'bulk-%'");

// ---------- M. Wizard tour baru & Impor Massal (Fase 3) ----------
$panoXml = '<tour start="node1"><panorama id="node1"><userdata title="Gerbang Depan" customnodeid="gerbang"/><view><start pan="0" tilt="0" fov="70"/></view><hotspots><hotspot url="{node2}" title="Taman" pan="10" tilt="0" id="h1"/></hotspots></panorama><panorama id="node2"><userdata title="Taman"/><view/><hotspots/></panorama></tour>';
$mkExport = function (string $parent, string $name) use ($panoXml): string { $d = sys_get_temp_dir() . "/$parent/$name"; mkdir($d, 0777, true); file_put_contents("$d/pano.xml", $panoXml); file_put_contents("$d/skin.js", '// skin'); file_put_contents("$d/index.html", '<html><body></body></html>'); return str_replace(chr(92), '/', $d); };
$wizParent = 'hubwiz-' . bin2hex(random_bytes(3));
$e1 = $mkExport($wizParent, 'gedung-utama');
// sumber untuk "mulai dari": tour dengan knowledge sendiri
jw('storage/tours/lama/knowledge.json', ['entries' => [['id' => 'k1', 'title' => 'Sejarah Tour Lama', 'content' => 'Tour Lama dibangun 1900.']]]);
$o = post(['a' => 'save'], ['id' => 0, 'name' => 'Gedung Utama', 'slug' => 'gedung-utama', 'new_city' => 'Kota Wizard', 'category' => 'Pariwisata', 'export_path' => $e1, 'clone_from' => (int)$pdo->query("SELECT id FROM tours WHERE slug='lama'")->fetchColumn(), 'auto_prepare' => '1']);
clearstatcache();
$parsed = jr('storage/tours/gedung-utama/parsed.json');
check('wizard: pano otomatis diimpor (2 scene)', count($parsed['scenes'] ?? []) === 2 && ($parsed['scenes'][0]['name'] ?? '') === 'Gerbang Depan');
check('wizard: konten awal dibuat (curated, scene-identity, tips, nearby, rekomendasi)', is_file("$tmp/storage/tours/gedung-utama/curated-answers.json") && is_file("$tmp/storage/tours/gedung-utama/scene-identity.json") && is_file("$tmp/storage/tours/gedung-utama/visitor-tips.json") && is_file("$tmp/storage/tours/gedung-utama/recommended-questions.json"));
$kn = jr('storage/tours/gedung-utama/knowledge.json');
check('wizard: knowledge disalin dari tour sumber dan TIDAK ditimpa seed', ($kn['entries'][0]['title'] ?? '') === 'Sejarah Gedung Utama');
check('wizard: nama tour sumber di isi salinan diganti ke nama baru', str_contains(json_encode($kn), 'Gedung Utama') && !str_contains(json_encode($kn), 'Tour Lama dibangun'));
$kota = $pdo->query("SELECT c.slug FROM tours t JOIN cities c ON c.id=t.city_id WHERE t.slug='gedung-utama'")->fetchColumn();
check('wizard: kota baru dibuat dan ditautkan', $kota === 'kota-wizard');
$v = getp(['a' => 'view', 'id' => (int)$pdo->query("SELECT id FROM tours WHERE slug='gedung-utama'")->fetchColumn(), 'created' => 'import pano: 2 scene']);
check('wizard: halaman Ringkasan menampilkan hasil pembuatan', str_contains($v, 'Tour dibuat') && str_contains($v, 'import pano: 2 scene'));

// tanpa auto_prepare: hanya terdaftar
$e2 = $mkExport($wizParent, 'tanpa-auto');
post(['a' => 'save'], ['id' => 0, 'name' => 'Tanpa Auto', 'slug' => 'tanpa-auto', 'new_city' => 'Kota Wizard', 'category' => '', 'export_path' => $e2]);
check('wizard: tanpa centang auto-prepare, pano tidak diimpor', !is_file("$tmp/storage/tours/tanpa-auto/parsed.json"));

// Impor Massal
$e3 = $mkExport($wizParent, 'ruang-seminar'); $e4 = $mkExport($wizParent, 'aula-besar');
$list = getp(['a' => 'bulkImport']);
preg_match_all('/name="path_(\d+)" value="([^"]+)"/', $list, $mm, PREG_SET_ORDER); $idx = [];
foreach ($mm as $m) $idx[html_entity_decode($m[2])] = (int)$m[1];
check('impor massal: folder baru berpon pano.xml muncul di daftar', isset($idx[$e3]) && isset($idx[$e4]));
check('impor massal: folder yang sudah terdaftar tidak muncul lagi', !isset($idx[$e1]) && !isset($idx[$e2]));
$cityWiz = (int)$pdo->query("SELECT id FROM cities WHERE slug='kota-wizard'")->fetchColumn();
$res = post(['a' => 'bulkImport'], ['pick' => [$idx[$e3], $idx[$e4]], 'path_' . $idx[$e3] => $e3, 'name_' . $idx[$e3] => 'Ruang Seminar', 'city_' . $idx[$e3] => $cityWiz, 'path_' . $idx[$e4] => $e4, 'name_' . $idx[$e4] => 'Aula Besar', 'city_' . $idx[$e4] => 0]);
check('impor massal: yang valid berhasil didaftarkan & diimpor', is_file("$tmp/storage/tours/ruang-seminar/parsed.json") && (int)$pdo->query("SELECT COUNT(*) FROM tours WHERE slug='ruang-seminar'")->fetchColumn() === 1);
check('impor massal: baris tanpa kota gagal dengan pesan jelas, tidak terdaftar', str_contains($res, 'Kota belum dipilih') && (int)$pdo->query("SELECT COUNT(*) FROM tours WHERE slug='aula-besar'")->fetchColumn() === 0);
$res2 = post(['a' => 'bulkImport'], ['pick' => [$idx[$e3]], 'path_' . $idx[$e3] => $e3, 'name_' . $idx[$e3] => 'Ruang Seminar Lagi', 'city_' . $idx[$e3] => $cityWiz]);
check('impor massal: folder yang sama tidak bisa didaftarkan dua kali', str_contains($res2, 'Sudah terdaftar') && (int)$pdo->query("SELECT COUNT(*) FROM tours WHERE export_path LIKE '%ruang-seminar'")->fetchColumn() === 1);

// Save As lewat fungsi bersama
$srcId = (int)$pdo->query("SELECT id FROM tours WHERE slug='gedung-utama'")->fetchColumn();
post(['a' => 'saveAs', 'id' => $srcId], ['new_name' => 'Gedung Kedua', 'new_slug' => 'gedung-kedua', 'new_export' => '']);
check('saveAs: konten disalin ke tour baru, nama diganti', str_contains(json_encode(jr('storage/tours/gedung-kedua/knowledge.json')), 'Gedung Kedua'));
check('saveAs: tour baru ikut kota tour asal', $pdo->query("SELECT c.slug FROM tours t JOIN cities c ON c.id=t.city_id WHERE t.slug='gedung-kedua'")->fetchColumn() === 'kota-wizard');
$o = post(['a' => 'saveAs', 'id' => $srcId], ['new_name' => 'Dobel', 'new_slug' => 'gedung-kedua', 'new_export' => '']);
check('saveAs: slug yang sudah dipakai ditolak', (int)$pdo->query("SELECT COUNT(*) FROM tours WHERE slug='gedung-kedua'")->fetchColumn() === 1);
// Antrian pekerjaan (worker CLI sungguhan)
$gid = (int)$pdo->query("SELECT id FROM tours WHERE slug='gedung-utama'")->fetchColumn(); $tid2 = (int)$pdo->query("SELECT id FROM tours WHERE slug='tanpa-auto'")->fetchColumn();
$jp = getp(['a' => 'jobs']);
check('antrian: halaman memuat form & daftar tour', str_contains($jp, 'Antrian Publikasi') && str_contains($jp, 'name="tour[]"'));
post(['a' => 'jobEnqueue'], ['type' => 'deploy', 'tour' => [$gid, $tid2]]);
$batch = (string)$pdo->query('SELECT batch FROM jobs ORDER BY id DESC LIMIT 1')->fetchColumn();
check('antrian: 2 pekerjaan masuk dengan status pending', (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE batch='$batch'")->fetchColumn() === 2);
shell_exec(escapeshellarg($php) . ' ' . escapeshellarg("$tmp/tools/worker.php") . ' 2>&1');
clearstatcache();
$st = $pdo->query("SELECT t.slug, j.status, j.message FROM jobs j JOIN tours t ON t.id=j.tour_id WHERE j.batch='$batch'")->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
check('antrian: tour valid selesai (done)', ($st['gedung-utama']['status'] ?? '') === 'done', json_encode($st['gedung-utama'] ?? null));
check('antrian: deploy benar-benar memasang govirtual-ai ke folder export', is_dir("$e1/govirtual-ai") && str_contains((string)file_get_contents("$e1/index.html"), 'GOVIRTUAL AI START'));
check('antrian: tour yang tidak bisa diproses gagal dengan pesan, tidak menghentikan antrian', ($st['tanpa-auto']['status'] ?? '') === 'failed' && ($st['tanpa-auto']['message'] ?? '') !== '', json_encode($st['tanpa-auto'] ?? null));
$js = json_decode(getp(['a' => 'jobsStatus', 'batch' => $batch]), true);
check('antrian: endpoint status melaporkan total, selesai, dan gagal', ($js['total'] ?? 0) === 2 && ($js['counts']['done'] ?? 0) === 1 && ($js['counts']['failed'] ?? 0) === 1 && ($js['finished'] ?? false) === true, json_encode($js['counts'] ?? null));
$o = post(['a' => 'jobEnqueue'], ['type' => 'hapus-semua', 'tour' => [$gid]]);
check('antrian: jenis aksi yang tidak dikenal ditolak', (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE type='hapus-semua'")->fetchColumn() === 0);
foreach ([$e1, $e2, $e3, $e4] as $d) { @unlink("$d/pano.xml"); @unlink("$d/skin.js"); @unlink("$d/index.html"); @rmdir($d); } @rmdir(sys_get_temp_dir() . "/$wizParent");

// ---------- N. Tema per kota (tampilan bawaan TIDAK boleh berubah) ----------
$gtid = (int)$pdo->query("SELECT id FROM tours WHERE slug='gedung-utama'")->fetchColumn();
post(['a' => 'generate', 'id' => $gtid], []);
$themeFile = "$tmp/storage/tours/gedung-utama/package/govirtual-ai/config/theme.css";
check('tema: tanpa tema, theme.css hanya komentar (tidak ada variabel)', is_file($themeFile) && !str_contains((string)file_get_contents($themeFile), '--ai-') && str_contains((string)file_get_contents($themeFile), 'bawaan'));
$snip = (string)@file_get_contents("$tmp/storage/tours/gedung-utama/package/govirtual-ai/install-snippet.html");
check('tema: tanpa tema, snippet index.html tidak memuat link theme.css (tampilan lama utuh)', $snip !== '' && !str_contains($snip, 'theme.css'));
$cw = (int)$pdo->query("SELECT id FROM cities WHERE slug='kota-wizard'")->fetchColumn();
$o = post(['a' => 'saveTheme', 'city' => 'kota-wizard'], ['do' => 'save', 'accent' => '#2a9d8f', 'surface' => '#1e1b17', 'font_body' => 'sora', 'font_display' => 'playfair', 'radius' => '16']);
$saved = json_decode((string)$pdo->query("SELECT theme FROM cities WHERE id=$cw")->fetchColumn(), true);
check('tema: kota hanya menyimpan nilai yang berbeda dari bawaan', $saved === ['accent' => '#2a9d8f', 'font_display' => 'playfair'], json_encode($saved));
post(['a' => 'generate', 'id' => $gtid], []);
$css = (string)file_get_contents($themeFile);
check('tema: theme.css memuat aksen & turunannya', str_contains($css, '--ai-accent: #2a9d8f') && str_contains($css, '--ai-accent-rgb: 42, 157, 143') && str_contains($css, '--ai-on-accent'));
check('tema: font non-bawaan ditambahkan via @import, bawaan tidak', str_contains($css, 'Playfair+Display') && !str_contains($css, 'family=Sora'));
check('tema: nilai yang sama dengan bawaan (surface, radius) tidak ikut ditimpa', !str_contains($css, '--ai-glass-bg') && !str_contains($css, '--ai-radius'));
$snip = (string)file_get_contents("$tmp/storage/tours/gedung-utama/package/govirtual-ai/install-snippet.html");
check('tema: snippet memuat link theme.css setelah guide.css', preg_match('/guide\.css.*theme\.css/s', $snip) === 1);
post(['a' => 'saveTheme', 'id' => $gtid], ['do' => 'save', 'override' => '1', 'accent' => '#2a9d8f', 'surface' => '#101820', 'font_body' => 'sora', 'font_display' => 'playfair', 'radius' => '16']);
$ov = jr('storage/tours/gedung-utama/theme.json');
check('tema: override tour hanya menyimpan yang berbeda dari kota (surface)', ($ov['overrides'] ?? null) === ['surface' => '#101820'], json_encode($ov));
post(['a' => 'saveTheme', 'city' => 'kota-wizard'], ['do' => 'save', 'accent' => '#e76f51', 'surface' => '#1e1b17', 'font_body' => 'sora', 'font_display' => 'playfair', 'radius' => '16']);
post(['a' => 'generate', 'id' => $gtid], []);
$css = (string)file_get_contents($themeFile);
check('tema: tour mewarisi aksen baru kota sekaligus mempertahankan override-nya', str_contains($css, '--ai-accent: #e76f51') && str_contains($css, '--ai-glass-bg'));
$bad = json_decode(getp(['a' => 'themeCss', 'accent' => 'red;}body{display:none', 'surface' => 'url(javascript:x)', 'font_body' => 'comic;}', 'radius' => '9999']), true);
check('tema: nilai CSS berbahaya ditolak (tidak ada injeksi)', ($bad['css'] ?? '') !== '' && !str_contains($bad['css'], 'display:none') && !str_contains($bad['css'], 'javascript') && !str_contains($bad['css'], 'comic'));
$low = json_decode(getp(['a' => 'themeCss', 'accent' => '#202020', 'surface' => '#1e1b17']), true);
check('tema: peringatan kontras untuk aksen yang gelap di panel gelap', ($low['contrast'] ?? 99) < 3);
post(['a' => 'saveTheme', 'city' => 'kota-wizard'], ['do' => 'reset']);
post(['a' => 'saveTheme', 'id' => $gtid], ['do' => 'reset']);
post(['a' => 'generate', 'id' => $gtid], []);
check('tema: reset mengembalikan ke bawaan (kolom kosong, theme.css komentar, tanpa link)', (string)$pdo->query("SELECT theme FROM cities WHERE id=$cw")->fetchColumn() === '' && !str_contains((string)file_get_contents($themeFile), '--ai-') && !str_contains((string)file_get_contents("$tmp/storage/tours/gedung-utama/package/govirtual-ai/install-snippet.html"), 'theme.css'));
$tp = getp(['a' => 'theme', 'city' => 'kota-wizard']);
check('tema: halaman Tema kota menampilkan pratinjau & tombol reset', str_contains($tp, 'themePreview') && str_contains($tp, 'Reset ke bawaan'));

// ---------- O. Pengguna, cakupan kota, jejak audit ----------
$ctestId = (int)$pdo->query("SELECT id FROM cities WHERE slug='kotatest'")->fetchColumn();
$o = post(['a' => 'userCreate'], ['username' => 'ed1', 'password' => 'pendek', 'role' => 'editor']);
check('pengguna: password lemah ditolak saat membuat akun', str_contains($o, 'minimal 10') && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE username='ed1'")->fetchColumn() === 0);
post(['a' => 'userCreate'], ['username' => 'ed1', 'password' => 'Sandi-Editor-77', 'role' => 'editor', 'cities' => [$ctestId]]);
$ed = $pdo->query("SELECT * FROM users WHERE username='ed1'")->fetch(PDO::FETCH_ASSOC);
check('pengguna: editor dibuat, wajib ganti password, cakupan tersimpan', $ed && (int)$ed['must_change'] === 1 && $ed['role'] === 'editor' && (int)$pdo->query('SELECT COUNT(*) FROM user_cities WHERE user_id=' . (int)$ed['id'])->fetchColumn() === 1);
$edSess = ['user' => ['id' => (int)$ed['id'], 'username' => 'ed1', 'role' => 'editor']];
$dash = getp(['a' => 'list'], $edSess);
check('cakupan: dashboard editor hanya menampilkan tour di kotanya', str_contains($dash, 'Tour Dua') && !str_contains($dash, 'Gedung Utama') && !str_contains($dash, 'Ruang Seminar'));
$out = getp(['a' => 'view', 'id' => $gtid], $edSess);
check('cakupan: membuka tour di kota lain ditolak', str_contains($out, 'di luar kota yang boleh Anda kelola') && !str_contains($out, '<div class="ov-head">'));
$before = (string)$pdo->query("SELECT name FROM tours WHERE id=$gtid")->fetchColumn();
req(['get' => ['a' => 'save'], 'post' => ['csrf_token' => 'TESTTOKEN', 'id' => $gtid, 'name' => 'DIRETAS', 'slug' => 'gedung-utama', 'city_id' => $cw, 'export_path' => ''], 'session' => $edSess]);
check('cakupan: menyimpan tour di kota lain ditolak dan tidak mengubah data', (string)$pdo->query("SELECT name FROM tours WHERE id=$gtid")->fetchColumn() === $before);
req(['get' => ['a' => 'save'], 'post' => ['csrf_token' => 'TESTTOKEN', 'id' => 0, 'name' => 'Selundupan', 'slug' => 'selundupan', 'city_id' => $cw, 'export_path' => ''], 'session' => $edSess]);
check('cakupan: membuat tour di kota luar cakupan ditolak', (int)$pdo->query("SELECT COUNT(*) FROM tours WHERE slug='selundupan'")->fetchColumn() === 0);
$out = getp(['a' => 'city', 'city' => 'kota-wizard'], $edSess);
check('cakupan: membuka kota di luar cakupan ditolak', str_contains($out, 'di luar cakupan'));
$out = getp(['a' => 'view', 'id' => (int)$pdo->query("SELECT id FROM tours WHERE slug='tour-dua'")->fetchColumn()], $edSess);
check('cakupan: tour di kota sendiri tetap bisa dibuka', str_contains($out, '<div class="ov-head">'));
$out = getp(['a' => 'users'], $edSess);
check('peran: editor tidak boleh membuka halaman Pengguna', str_contains($out, '403') || str_contains($out, 'Akses ditolak'));
$out = getp(['a' => 'audit'], ['user' => ['id' => 99, 'username' => 'q', 'role' => 'qa']]);
check('peran: QA tidak boleh membuka Jejak Audit', str_contains($out, '403') || str_contains($out, 'Akses ditolak'));
$o = post(['a' => 'userUpdate'], ['uid' => 1, 'role' => 'editor', 'active' => '1']);
check('pengguna: admin tidak bisa menurunkan perannya sendiri', str_contains($o, 'akun Anda sendiri') && $pdo->query('SELECT role FROM users WHERE id=1')->fetchColumn() === 'admin');
$pdo->exec("UPDATE users SET active=0 WHERE username='ed1'");
$o = req(['get' => ['a' => 'login'], 'post' => ['username' => 'ed1', 'password' => 'Sandi-Editor-77'], 'session' => []]);
check('login: akun nonaktif ditolak dengan pesan jelas', str_contains($o, 'dinonaktifkan'));
$pdo->exec("UPDATE users SET active=1 WHERE username='ed1'");
$aud = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='saveKnowledge' AND tour_id=1")->fetchColumn();
check('audit: aksi tulis tercatat dengan pengguna & tour', $aud >= 1 && (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE username='tester'")->fetchColumn() > 5);
check('audit: isi form dan password TIDAK tersimpan di log', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%Sandi-Editor-77%' OR detail LIKE '%Isi baru%'")->fetchColumn() === 0);
check('audit: login ditolak & pembuatan pengguna tercatat', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('loginDitolak','userCreate')")->fetchColumn() >= 2);
check('audit: halaman audit menampilkan catatan & filter', str_contains(getp(['a' => 'audit']), 'saveKnowledge') && str_contains(getp(['a' => 'audit', 'user' => 'tester']), 'Jejak Audit'));

// ---------- J. Wajib ganti password bawaan ----------
$out = getp(['a' => 'list'], ['must_change_pw' => true]);
check('password bawaan: halaman lain dialihkan (tidak merender dashboard)', trim($out) === '');
$out = getp(['a' => 'account'], ['must_change_pw' => true]);
check('password bawaan: halaman Akun tetap bisa dibuka & menampilkan peringatan', str_contains($out, 'masih memakai password bawaan'));
$out = getp(['a' => 'list'], ['must_change_pw' => false]);
check('password normal: dashboard tampil', str_contains($out, 'studio-sidebar'));
$out = getp(['a' => 'account'], []);
check('akun: link nama pengguna ke halaman Akun ada di topbar', str_contains($out, 'href="?a=account"'));

// ---------- I. Form terpotong max_input_vars ----------
$many = ['item_count' => 1, 'item_0_id' => 'n1', 'item_0_name' => 'LOLOS']; for ($j = 0; $j < 40; $j++) $many["pad_$j"] = 'x';
$out = req(['get' => ['a' => 'saveNearby', 'id' => 1], 'post' => $many + ['csrf_token' => 'TESTTOKEN'], 'session' => ['user' => ['id' => 1, 'username' => 'tester', 'role' => 'admin']]], ['-d', 'max_input_vars=30']);
check('max_input_vars: form yang melebihi batas ditolak', str_contains($out, 'Form terlalu besar'));

// bersihkan
function rrmdir(string $d): void { foreach (scandir($d) ?: [] as $f) { if ($f === '.' || $f === '..') continue; $p = "$d/$f"; is_dir($p) ? rrmdir($p) : @unlink($p); } @rmdir($d); }
unset($pdo); rrmdir($tmp);
echo $fail ? "\n$fail GAGAL\n" : "\nSemua lulus\n";
exit($fail ? 1 : 0);
