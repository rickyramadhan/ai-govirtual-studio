<?php

declare(strict_types=1);

/**
 * Model kota/wilayah (tabel `cities`). Tour memegang `city_id`; kolom teks `tours.location` tetap
 * disinkronkan dengan nama kota supaya kode lama yang membacanya tetap bekerja.
 */

/** Kategori bawaan bila sebuah kota belum punya daftar sendiri. */
function city_default_categories(): array
{
    return ['Pariwisata', 'Agama', 'Pendidikan', 'Kesehatan', 'Investasi', 'Mice', 'Restoran', 'Infrastruktur', 'Pusat Perbelanjaan'];
}

function city_row_by_slug(string $slug): ?array
{
    $s = db()->prepare('SELECT * FROM cities WHERE slug=?');
    $s->execute([$slug]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function city_row_by_id(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM cities WHERE id=?');
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Cari kota berdasarkan nama/slug; buat bila belum ada. Mengembalikan baris kota. */
function city_ensure(string $name, string $slug = ''): array
{
    $name = trim($name);
    if ($name === '') throw new RuntimeException('Nama kota wajib diisi.');
    $slug = $slug !== '' ? city_slugify($slug) : city_slugify($name);
    $row = city_row_by_slug($slug);
    if ($row) return $row;
    $now = date('c');
    db()->prepare('INSERT INTO cities(slug,name,created_at,updated_at) VALUES(?,?,?,?)')->execute([$slug, $name, $now, $now]);
    return city_row_by_slug($slug) ?? throw new RuntimeException('Gagal membuat kota.');
}

/** Pastikan folder knowledge kota ada (dibuat saat dibutuhkan, bukan sebagai efek samping membuka halaman daftar). */
function city_ensure_files(string $slug, string $name = ''): void
{
    $dir = city_dir($slug);
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (!is_file($dir . '/meta.json')) write_json($dir . '/meta.json', ['name' => $name !== '' ? $name : ucwords(str_replace('-', ' ', $slug)), 'updatedAt' => date('c')]);
    if (!is_file($dir . '/knowledge.json')) write_json($dir . '/knowledge.json', ['version' => 1, 'city' => $slug, 'entries' => []]);
    if (!is_file($dir . '/sources.json')) write_json($dir . '/sources.json', ['sources' => []]);
}

/** Slug kota milik sebuah tour (via city_id; cadangan: slug dari teks location). */
function tour_city_slug(array $tour): string
{
    static $byId = [];
    $cid = (int)($tour['city_id'] ?? 0);
    if ($cid > 0) {
        if (!isset($byId[$cid])) $byId[$cid] = (string)((city_row_by_id($cid)['slug'] ?? '') ?: '');
        if ($byId[$cid] !== '') return $byId[$cid];
    }
    return city_slugify((string)($tour['location'] ?? ''));
}

/** Daftar kategori untuk sebuah kota (satu per baris di kolom `categories`), atau bawaan. */
function city_categories(?array $cityRow): array
{
    $raw = trim((string)($cityRow['categories'] ?? ''));
    if ($raw === '') return city_default_categories();
    $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n|,/', $raw) ?: []))));
    return $list ?: city_default_categories();
}

/**
 * Folder export Pano2VR di bawah htdocs yang belum terdaftar sebagai tour (punya pano.xml).
 * Memindai dua tingkat: htdocs/<kota-atau-proyek>/<folder>. Dipakai sebagai saran, bukan sumber kebenaran.
 */
function export_candidates(int $limit = 400): array
{
    $htdocs = str_replace('\\', '/', dirname(APP_ROOT));
    $registered = [];
    foreach (db()->query('SELECT export_path FROM tours')->fetchAll(PDO::FETCH_COLUMN) as $p) $registered[rtrim(str_replace('\\', '/', (string)$p), '/')] = true;
    $skip = ['ai-guide-hub' => 1, 'xampp' => 1, 'node_modules' => 1, 'img' => 1, 'dashboard' => 1, 'webview' => 1, 'assets' => 1];
    $out = [];
    foreach (scandir($htdocs) ?: [] as $a) {
        if ($a[0] === '.' || isset($skip[$a]) || !is_dir("$htdocs/$a")) continue;
        foreach (scandir("$htdocs/$a") ?: [] as $b) {
            if ($b[0] === '.' || !is_dir("$htdocs/$a/$b")) continue;
            $p = "$htdocs/$a/$b";
            if (is_file("$p/pano.xml") && !isset($registered[$p])) $out[] = $p;
            if (count($out) >= $limit) return $out;
        }
    }
    sort($out);
    return $out;
}

/**
 * Validasi folder export: harus berupa folder yang ada di bawah htdocs (cegah deploy ke lokasi sembarang)
 * dan memuat pano.xml. Path kosong diperbolehkan (tour belum punya export). Mengembalikan pesan error atau null.
 */
function export_path_error(string $path): ?string
{
    $path = rtrim(str_replace('\\', '/', trim($path)), '/');
    if ($path === '') return null;
    $real = realpath($path);
    if ($real === false || !is_dir($real)) return 'Folder export tidak ditemukan: ' . $path;
    $real = rtrim(str_replace('\\', '/', $real), '/');
    $htdocs = rtrim(str_replace('\\', '/', (string)realpath(dirname(APP_ROOT))), '/');
    if ($htdocs === '' || !str_starts_with(strtolower($real) . '/', strtolower($htdocs) . '/')) return 'Folder export harus berada di dalam htdocs (' . $htdocs . ').';
    $self = rtrim(str_replace('\\', '/', (string)realpath(APP_ROOT)), '/');
    if (strtolower($real) === strtolower($self) || str_starts_with(strtolower($real) . '/', strtolower($self) . '/')) return 'Folder export tidak boleh berada di dalam folder Studio.';
    if (!is_file($real . '/pano.xml')) return 'pano.xml tidak ada di folder itu. Pastikan ini folder hasil export Pano2VR.';
    return null;
}
