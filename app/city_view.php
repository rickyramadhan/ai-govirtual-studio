<?php

declare(strict_types=1);

/**
 * Halaman Pengetahuan Kota (?a=city). Tata letak dibuat agar jelas: apa itu pengetahuan kota, siapa yang
 * memakainya, bagian aturan AI vs topik, sumber tiap topik, dan tombol simpan yang menjelaskan dampaknya.
 * Nama field form dipertahankan agar citySave tetap memproses data dengan benar.
 */

function city_source_badge(string $sourceType, string $source): string
{
    $map = [
        'manual' => ['Manual', 'var(--text-secondary)'],
        'web' => ['Dari web', 'var(--info)'],
        'url' => ['Dari web', 'var(--info)'],
        'file' => ['Dari file', 'var(--success)'],
        'ingested' => ['Dari sumber', 'var(--info)'],
    ];
    [$label, $color] = $map[strtolower($sourceType)] ?? [$sourceType !== '' ? ucfirst($sourceType) : 'Manual', 'var(--text-secondary)'];
    $title = $source !== '' ? ' title="Sumber: ' . h($source) . '"' : '';
    return '<span class="dv-pill" style="color:' . $color . '"' . $title . '>' . h($label) . '</span>';
}

function render_city_page(string $slug, string $msg = ''): void
{
    $kb = city_knowledge($slug);
    $cityRow = city_row_by_slug($slug);
    $meta = read_json(city_dir($slug) . '/meta.json', []);
    $name = (string)($meta['name'] ?? ($cityRow['name'] ?? ucwords(str_replace('-', ' ', $slug))));
    $entries = is_array($kb['entries'] ?? null) ? $kb['entries'] : [];
    $tourCount = $cityRow ? (int)db()->query('SELECT COUNT(*) FROM tours WHERE city_id=' . (int)$cityRow['id'])->fetchColumn() : 0;
    $sources = count(city_sources($slug)['sources'] ?? []);
    $updated = (string)($kb['updatedAt'] ?? $meta['updatedAt'] ?? '');
    $updatedTxt = $updated !== '' ? (string)(strtotime($updated) ? date('d M Y H:i', strtotime($updated)) : $updated) : 'belum pernah';
    $instructions = (string)($meta['instructions'] ?? '');

    $html = $msg;
    // ---- Header: jelaskan fungsi halaman dan ringkasannya ----
    $html .= '<div class="card" style="margin-bottom:var(--space-4)">'
        . '<a class="muted" href="?a=cities" style="font-size:12px;text-decoration:none">← Semua kota</a>'
        . '<h1 style="margin:6px 0 4px">Pengetahuan ' . h($name) . '</h1>'
        . '<p class="muted" style="margin:0 0 12px;max-width:72ch">Halaman ini berisi <b>fakta umum tentang kota</b> (sejarah, geografi, kategori, layanan) dan <b>aturan untuk AI</b>. Semua AI guide di tour-tour kota ini membacanya. Tidak ada yang perlu diisi per lokasi.</p>'
        . '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
        . '<span class="dv-pill">' . h(i18n_fmt('{n} topik', ['n' => count($entries)])) . '</span>'
        . '<span class="dv-pill">' . h(i18n_fmt('dipakai {n} tour', ['n' => $tourCount])) . '</span>'
        . '<span class="dv-pill">' . h(i18n_fmt('terakhir disimpan: {t}', ['t' => $updatedTxt])) . '</span>'
        . '<span class="dv-pill">' . h(i18n_fmt('{n} sumber', ['n' => $sources])) . '</span>'
        . '<span style="flex:1"></span>'
        . '<a class="btn btn-ghost btn-sm" href="?a=citySources&city=' . h($slug) . '">' . h(i18n_fmt('Sumber data ({n})', ['n' => $sources])) . '</a>'
        . '</div></div>';

    $html .= '<form method="post" action="?a=citySave" id="cityForm">' . rev_field(city_dir($slug) . '/knowledge.json');
    $html .= '<input type="hidden" name="city" value="' . h($slug) . '"><input type="hidden" name="name" value="' . h($name) . '">';

    // ---- Bagian 1: aturan untuk AI ----
    $html .= '<div class="card" style="border-color:rgba(201,169,110,.3)">'
        . '<div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap"><span class="dv-pill" style="font-weight:700">1</span><h2 style="margin:0">Aturan untuk AI</h2></div>'
        . '<p class="muted" style="margin:6px 0 10px;max-width:76ch">Instruksi ini dibaca AI sebelum menjawab pertanyaan di <b>semua tour ' . h($name) . '</b>. Tulis peran, batasan, dan gaya jawaban. Berlaku setelah disimpan.</p>'
        . '<textarea name="instructions" rows="8" placeholder="Contoh: Kamu adalah pemandu resmi ' . h($name) . '. Jawab singkat dalam Bahasa Indonesia. Jika tidak yakin, katakan tidak tahu dan sarankan petugas setempat." aria-label="Aturan untuk AI">' . h($instructions) . '</textarea>'
        . '<details style="margin-top:8px"><summary class="muted" style="cursor:pointer;font-size:12px">Lihat contoh aturan yang baik</summary>'
        . '<ul class="muted" style="font-size:12px;margin:8px 0 0 18px;line-height:1.6"><li>Peran: siapa AI ini (pemandu resmi, guru sejarah, dan sebagainya).</li><li>Batasan: hal yang tidak boleh dijawab (harga tiket pasti, jadwal yang belum dipastikan).</li><li>Gaya: panjang jawaban, bahasa, dan sapaan kepada pengunjung.</li></ul></details>'
        . '</div>';

    // ---- Bagian 2: topik pengetahuan ----
    $html .= '<div style="margin-top:var(--space-4)"><div class="card" style="margin-bottom:var(--space-3)">'
        . '<div style="display:flex;gap:10px;align-items:baseline;flex-wrap:wrap"><span class="dv-pill" style="font-weight:700">2</span><h2 style="margin:0">Topik pengetahuan <span class="muted" style="font-weight:400">(' . count($entries) . ')</span></h2></div>'
        . '<p class="muted" style="margin:6px 0 0;max-width:76ch">Setiap topik = satu bahasan. <b>Judul</b> singkat (2–5 kata) membantu AI mencocokkan pertanyaan pengunjung. <b>Isi</b> adalah jawaban yang boleh diberikan AI. Topik dari web atau file bisa diedit atau dihapus di sini.</p>'
        . '</div>';

    if (!$entries) {
        $html .= '<div class="card empty"><h3 style="margin:0 0 6px">Belum ada topik</h3><p class="muted" style="margin:0 0 12px">Tambahkan topik manual, atau ambil dari website dan file di halaman Sumber data.</p><button type="submit" name="add_entry" value="1" class="btn btn-primary">+ Tambah topik pertama</button></div>';
    }

    foreach ($entries as $idx => $e) {
        $title = (string)($e['title'] ?? $e['id'] ?? '');
        $aliases = implode(', ', (array)($e['aliases'] ?? []));
        $content = (string)($e['content'] ?? '');
        $sourceType = (string)($e['sourceType'] ?? ($e['provenance'] ?? 'manual'));
        $sourceName = (string)($e['source'] ?? '');
        $html .= '<div class="card" style="margin-bottom:var(--space-3)">'
            . '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">'
            . '<div style="min-width:0"><strong style="font-size:15px">' . h($title !== '' ? $title : '(tanpa judul)') . '</strong> ' . city_source_badge($sourceType, $sourceName) . '</div>'
            . '<label class="btn btn-ghost btn-sm" style="cursor:pointer;margin:0"><input type="checkbox" name="del_' . (int)$idx . '" value="1" style="width:auto;margin:0 6px 0 0"> Hapus topik</label>'
            . '</div>'
            . '<input type="hidden" name="entry_' . (int)$idx . '_id" value="' . h((string)($e['id'] ?? '')) . '">'
            . '<div style="display:grid;grid-template-columns:minmax(200px,1fr) minmax(200px,1fr);gap:12px;margin-top:10px">'
            . '<div><label for="e' . (int)$idx . 't">Judul topik</label><input id="e' . (int)$idx . 't" name="entry_' . (int)$idx . '_title" value="' . h($title) . '"></div>'
            . '<div><label for="e' . (int)$idx . 'a">Kata kunci lain <span class="muted" style="font-size:12px">(pisahkan dengan koma)</span></label><input id="e' . (int)$idx . 'a" name="entry_' . (int)$idx . '_aliases" value="' . h($aliases) . '" placeholder="mis. sejarah, asal-usul"></div>'
            . '</div>'
            . '<label for="e' . (int)$idx . 'c" style="margin-top:10px">Isi jawaban untuk AI</label>'
            . '<textarea id="e' . (int)$idx . 'c" name="entry_' . (int)$idx . '_content" rows="4">' . h($content) . '</textarea>'
            . '</div>';
    }
    $html .= '</div>';

    $html .= '<input type="hidden" name="entry_count" value="' . count($entries) . '">';

    // ---- Footer lengket: jelaskan apa yang terjadi saat disimpan ----
    $html .= '<div class="card" style="position:sticky;bottom:0;z-index:5;display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:var(--surface-2)">'
        . '<button type="submit" name="add_entry" value="1" class="btn btn-ghost">+ Tambah topik</button>'
        . '<button type="submit" class="btn btn-primary">' . h(i18n_fmt('Simpan & kirim ke {n} tour', ['n' => $tourCount])) . '</button>'
        . '<span class="muted" style="font-size:12px;margin-left:auto">Menyimpan memperbarui pengetahuan dan aturan AI di semua tour kota ini.</span>'
        . '</div>';
    $html .= '</form>';

    layout('Pengetahuan ' . $name, $html);
}
