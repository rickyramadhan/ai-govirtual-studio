<?php

declare(strict_types=1);

/**
 * Alur membuat tour baru: daftar → import pano → siapkan konten, dengan opsi mulai dari tour yang sudah ada.
 * Fungsi-fungsi ini dipakai bersama oleh form Tour Baru, Save As, dan Impor Massal.
 */

/** Baca pano.xml + skin.js dari folder export dan tulis parsed.json. Mengembalikan data scene. */
function tour_import_pano(array $tour): array
{
    $path = (string)$tour['export_path'];
    if (trim($path) === '') throw new RuntimeException('Folder export belum diisi.');
    $p = parse_pano($path);
    $p['skin'] = parse_skin($path);
    $p['_exportState'] = export_snapshot($path);
    write_json(tour_dir((string)$tour['slug']) . '/parsed.json', $p);
    return $p;
}

/** Berkas konten yang ikut diduplikasi saat memulai dari tour lain (tanpa parsed.json — itu milik export-nya). */
function clone_content_files(): array
{
    return ['curated-answers.json', 'curated-qa.json', 'knowledge.json', 'scene-identity.json', 'visitor-tips.json', 'nearby.json', 'recommended-questions.json', 'explore-menu-knowledge.json', 'flow.json', 'voice.json'];
}

/** Salin konten $orig ke tour baru; slug/nama lama diganti hanya pada nilai yang cocok utuh. Mengembalikan jumlah berkas. */
function clone_tour_content(array $orig, string $newName, string $newSlug, bool $copyParsed = false): int
{
    $src = tour_dir((string)$orig['slug']); $dst = tour_dir($newSlug);
    if (!is_dir($dst)) mkdir($dst, 0777, true);
    $n = 0;
    foreach (clone_content_files() as $f) {
        if (!is_file($src . '/' . $f)) continue;
        $data = rename_in_data(read_json($src . '/' . $f, []), (string)$orig['slug'], $newSlug, (string)$orig['name'], $newName);
        if (isset($data['tourId'])) $data['tourId'] = $newSlug;
        if (isset($data['tourName'])) $data['tourName'] = $newName;
        write_json($dst . '/' . $f, $data);
        $n++;
    }
    if ($copyParsed && is_file($src . '/parsed.json')) copy($src . '/parsed.json', $dst . '/parsed.json');
    return $n;
}

/** Slug unik: tambahkan -2, -3, … bila sudah dipakai. */
function unique_tour_slug(string $slug): string
{
    $base = $slug !== '' ? $slug : 'tour'; $try = $base; $i = 2;
    $q = db()->prepare('SELECT 1 FROM tours WHERE slug=?');
    while (true) { $q->execute([$try]); if (!$q->fetchColumn()) return $try; $try = $base . '-' . $i++; }
}

/**
 * Siapkan tour yang baru dibuat: salin konten dari tour lain (opsional), import pano bila folder export valid,
 * lalu buat section konten yang belum ada. Tidak pernah menimpa konten yang sudah ada.
 * Mengembalikan daftar langkah yang dilakukan dan peringatan.
 */
function prepare_new_tour(int $tourId, ?int $cloneFromId = null, bool $autoPrepare = true): array
{
    $tour = get_tour($tourId) ?? throw new RuntimeException('Tour tidak ditemukan.');
    $done = []; $warn = [];
    if ($cloneFromId) {
        $orig = get_tour($cloneFromId);
        if ($orig) { $n = clone_tour_content($orig, (string)$tour['name'], (string)$tour['slug']); $done[] = "konten disalin dari {$orig['name']} ($n berkas)"; }
        else $warn[] = 'Tour sumber untuk disalin tidak ditemukan.';
    }
    if ($autoPrepare && trim((string)$tour['export_path']) !== '') {
        try { $p = tour_import_pano($tour); $done[] = 'import pano: ' . count($p['scenes'] ?? []) . ' scene'; }
        catch (Throwable $e) { $warn[] = 'Import pano gagal: ' . $e->getMessage(); }
        if (is_file(tour_dir((string)$tour['slug']) . '/parsed.json')) {
            try { $r = seed_content($tour); $done[] = 'konten dibuat: ' . count($r['written']) . ' section' . ($r['skipped'] ? ' (' . count($r['skipped']) . ' sudah ada, dibiarkan)' : ''); }
            catch (Throwable $e) { $warn[] = 'Menyiapkan konten gagal: ' . $e->getMessage(); }
        }
    }
    return ['done' => $done, 'warnings' => $warn];
}

/** "kampung-jawi_2" → "Kampung Jawi 2" */
function prettify_folder_name(string $folder): string
{
    return ucwords(trim((string)preg_replace('/[\s_\-]+/', ' ', $folder)));
}

/** Halaman Impor Massal: daftar folder export yang belum terdaftar + proses pendaftaran sekaligus. */
function render_bulk_import(string $resultHtml = ''): void
{
    $cities = all_cities();
    $cands = export_candidates();
    $cityBySlug = []; foreach ($cities as $c) $cityBySlug[$c['slug']] = $c;
    $cityOpts = function (string $selSlug) use ($cities): string {
        $o = '<option value="">— pilih kota —</option>';
        foreach ($cities as $c) $o .= '<option value="' . (int)$c['id'] . '"' . ($c['slug'] === $selSlug ? ' selected' : '') . '>' . h($c['name']) . '</option>';
        return $o;
    };
    $rows = '';
    foreach ($cands as $i => $p) {
        $parts = explode('/', $p); $folder = (string)array_pop($parts); $parent = (string)array_pop($parts);
        $guessCity = isset($cityBySlug[city_slugify($parent)]) ? city_slugify($parent) : '';
        $rows .= '<tr style="border-top:1px solid var(--border)"><td style="padding:6px"><input type="checkbox" name="pick[]" value="' . $i . '" class="bulk-pick"></td>'
            . '<td><code style="font-size:12px">' . h($p) . '</code><input type="hidden" name="path_' . $i . '" value="' . h($p) . '"></td>'
            . '<td><input name="name_' . $i . '" value="' . h(prettify_folder_name($folder)) . '" style="min-width:180px"></td>'
            . '<td><select name="city_' . $i . '" class="bulk-city">' . $cityOpts($guessCity) . '</select></td></tr>';
    }
    $body = $resultHtml;
    $body .= '<div class="card"><h2 style="margin-top:0">Impor Massal</h2><p class="muted">Folder export Pano2VR di dalam htdocs yang sudah berisi <code>pano.xml</code> tetapi belum terdaftar sebagai tour. Pilih yang ingin didaftarkan; Studio akan membuat tour, mengimpor panorama, dan menyiapkan konten awal (tanpa menimpa apa pun).</p>';
    if (!$cands) $body .= '<div class="empty"><h3>Tidak ada folder baru</h3><p>Semua folder export di htdocs sudah terdaftar.</p></div></div>';
    else {
        $body .= '<form method="post" action="?a=bulkImport"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px">'
            . '<label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" id="bulkAll"> Pilih semua</label>'
            . '<span class="muted">Kota untuk semua yang dipilih:</span><select id="bulkCityAll"><option value="">—</option>' . $cityOpts('') . '</select>'
            . '<span class="muted" style="margin-left:auto">' . count($cands) . ' folder ditemukan</span></div>'
            . '<div style="max-height:56vh;overflow:auto"><table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr style="text-align:left;color:var(--text-muted)"><th></th><th>Folder</th><th>Nama tour</th><th>Kota</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<p style="margin:12px 0 0"><button class="btn btn-primary" type="submit">Daftarkan &amp; siapkan yang dipilih</button></p></form></div>'
            . '<script>(function(){var all=document.getElementById("bulkAll"),ca=document.getElementById("bulkCityAll");'
            . 'all.addEventListener("change",function(){document.querySelectorAll(".bulk-pick").forEach(function(c){c.checked=all.checked;});});'
            . 'ca.addEventListener("change",function(){if(!ca.value)return;document.querySelectorAll(".bulk-pick:checked").forEach(function(c){var r=c.closest("tr");r.querySelector(".bulk-city").value=ca.value;});});})();</script>';
    }
    layout('Impor Massal', $body);
}

/** Proses POST Impor Massal. Mengembalikan HTML ringkasan hasil. */
function process_bulk_import(): string
{
    set_time_limit(600);
    $picks = array_slice(array_map('intval', (array)($_POST['pick'] ?? [])), 0, 60);
    if (!$picks) return '<div class="card bad"><p>Tidak ada folder yang dipilih.</p></div>';
    $rows = ''; $ok = 0; $fail = 0;
    foreach ($picks as $i) {
        $path = trim((string)($_POST['path_' . $i] ?? '')); $name = trim((string)($_POST['name_' . $i] ?? '')); $cityId = (int)($_POST['city_' . $i] ?? 0);
        $status = ''; $good = false;
        try {
            if ($name === '') throw new RuntimeException('Nama kosong');
            if (!city_row_by_id($cityId)) throw new RuntimeException('Kota belum dipilih');
            if ($err = export_path_error($path)) throw new RuntimeException($err);
            $dupe = db()->prepare('SELECT name FROM tours WHERE REPLACE(export_path, "\\", "/") = ?'); $dupe->execute([rtrim(str_replace('\\', '/', $path), '/')]);
            if ($d = $dupe->fetchColumn()) throw new RuntimeException('Sudah terdaftar sebagai "' . $d . '"');
            $slug = unique_tour_slug(slugify($name));
            $id = save_tour(['name' => $name, 'slug' => $slug, 'city_id' => $cityId, 'category' => '', 'export_path' => $path]);
            $r = prepare_new_tour($id);
            $status = implode('; ', $r['done']) . ($r['warnings'] ? ' — ⚠ ' . implode('; ', $r['warnings']) : ''); $good = !$r['warnings'];
        } catch (Throwable $e) { $status = $e->getMessage(); }
        $good ? $ok++ : $fail++;
        $rows .= '<tr style="border-top:1px solid var(--border)"><td style="padding:6px">' . ($good ? '<span class="ok">✓</span>' : '<span class="bad">✗</span>') . '</td><td>' . h($name !== '' ? $name : $path) . '</td><td class="muted" style="font-size:12px">' . h($status) . '</td></tr>';
    }
    return '<div class="card"><h3 style="margin-top:0">Hasil impor: ' . $ok . ' berhasil' . ($fail ? ', ' . $fail . ' perlu dicek' : '') . '</h3><table style="width:100%;border-collapse:collapse;font-size:13px"><tbody>' . $rows . '</tbody></table><p style="margin:12px 0 0"><a class="btn btn-primary" href="?">Ke daftar proyek</a></p></div>';
}
