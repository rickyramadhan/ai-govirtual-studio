<?php

declare(strict_types=1);

/** Form tambah/ubah tour. Error ditampilkan di form (bukan di bawah dashboard) dan nilai yang diketik dipertahankan. */
function render_tour_form(array $t, string $error = ''): void
{
    $isEdit = (int)($t['id'] ?? 0) > 0;
    $cities = all_cities();
    $selectedCity = (int)($t['city_id'] ?? 0);
    if ($selectedCity === 0 && !empty($t['location'])) {
        $row = city_row_by_slug(city_slugify((string)$t['location']));
        if ($row) $selectedCity = (int)$row['id'];
    }

    $catsByCity = [];
    foreach ($cities as $c) {
        $row = city_row_by_id((int)$c['id']);
        $catsByCity[(int)$c['id']] = city_categories($row);
    }
    $catsByCity[0] = city_default_categories();

    $cityOpts = '<option value="">— pilih kota —</option>';
    foreach ($cities as $c) {
        $label = $c['name'] . ($c['province'] !== '' ? ' (' . $c['province'] . ')' : '') . ' · ' . $c['tour_count'] . ' tour';
        $cityOpts .= '<option value="' . (int)$c['id'] . '"' . ($selectedCity === (int)$c['id'] ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $cityOpts .= '<option value="new"' . (!$cities || ($selectedCity === 0 && !empty($t['new_city'])) ? ' selected' : '') . '>➕ Kota baru…</option>';

    $curCats = $catsByCity[$selectedCity] ?? $catsByCity[0];
    $catOpts = '<option value="">— pilih kategori —</option>';
    $cat = (string)($t['category'] ?? '');
    if ($cat !== '' && !in_array($cat, $curCats, true)) $curCats[] = $cat;
    foreach ($curCats as $c) $catOpts .= '<option value="' . h($c) . '"' . ($cat === $c ? ' selected' : '') . '>' . h($c) . '</option>';

    // Hanya untuk tour baru: mulai dari tour lain + otomatis import & siapkan konten.
    $newOnly = '';
    if (!$isEdit) {
        $cloneOpts = '<option value="">Kosong (mulai dari nol)</option>';
        foreach (all_tours() as $o) $cloneOpts .= '<option value="' . (int)$o['id'] . '"' . ((int)($t['clone_from'] ?? 0) === (int)$o['id'] ? ' selected' : '') . '>' . h($o['name'] . ' — ' . $o['location']) . '</option>';
        $newOnly = '<label for="tour_clone">Mulai dari <span class="muted" style="font-size:12px">(salin konten tour lain sebagai titik awal)</span></label><select id="tour_clone" name="clone_from">' . $cloneOpts . '</select>'
            . '<label style="display:flex;gap:8px;align-items:center;margin-top:6px"><input type="checkbox" name="auto_prepare" value="1" checked> Otomatis import pano &amp; siapkan konten awal bila folder export diisi</label>';
    }
    $cand = '';
    foreach (export_candidates() as $p) $cand .= '<option value="' . h($p) . '">';

    $body = $error
        . '<form class="card" method="post" action="?a=save" id="tourForm" style="max-width:720px;display:grid;gap:6px">'
        . '<h2 style="margin:0 0 6px">' . ($isEdit ? 'Pengaturan tour' : 'Tour baru') . '</h2>'
        . '<input type="hidden" name="id" value="' . (int)($t['id'] ?? 0) . '">'
        . '<label for="tour_name">Nama tour</label><input id="tour_name" name="name" value="' . h((string)($t['name'] ?? '')) . '" required autocomplete="off" placeholder="mis. Lawang Sewu">'
        . '<label for="tour_slug">Slug <span class="muted" style="font-size:12px">(otomatis dari nama, boleh diubah)</span></label><input id="tour_slug" name="slug" value="' . h((string)($t['slug'] ?? '')) . '" autocomplete="off" data-touched="' . ($isEdit ? '1' : '0') . '">'
        . '<label for="tour_city">Kota / wilayah</label><select id="tour_city" name="city_id">' . $cityOpts . '</select>'
        . '<input id="tour_new_city" name="new_city" value="' . h((string)($t['new_city'] ?? '')) . '" placeholder="Nama kota baru, mis. Denpasar" autocomplete="off" style="display:none">'
        . '<label for="tour_category">Kategori</label><select id="tour_category" name="category">' . $catOpts . '</select>'
        . '<label for="tour_export">Folder export Pano2VR <span class="muted" style="font-size:12px">(harus di dalam htdocs dan berisi pano.xml)</span></label>'
        . '<input id="tour_export" name="export_path" list="exportCandidates" value="' . h((string)($t['export_path'] ?? '')) . '" placeholder="C:/xampp/htdocs/semarang/nama-tour" autocomplete="off"><datalist id="exportCandidates">' . $cand . '</datalist>'
        . $newOnly
        . '<p class="muted" style="font-size:12px;margin:0">Folder yang belum terdaftar dan berisi pano.xml muncul sebagai saran saat Anda mengetik.</p>'
        . '<p style="margin:8px 0 0;display:flex;gap:8px"><button class="btn btn-primary" type="submit">' . ($isEdit ? 'Simpan perubahan' : 'Buat tour') . '</button><a class="btn btn-ghost" href="' . ($isEdit ? '?a=view&id=' . (int)$t['id'] : '?') . '">Batal</a></p>'
        . '</form>'
        . '<script>(function(){'
        . 'var cats=' . json_encode($catsByCity, JSON_UNESCAPED_UNICODE) . ';'
        . 'var name=document.getElementById("tour_name"),slug=document.getElementById("tour_slug"),city=document.getElementById("tour_city"),nc=document.getElementById("tour_new_city"),cat=document.getElementById("tour_category");'
        . 'function slugify(s){return s.toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");}'
        . 'slug.addEventListener("input",function(){slug.dataset.touched="1";});'
        . 'name.addEventListener("input",function(){if(slug.dataset.touched!=="1")slug.value=slugify(name.value);});'
        . 'function syncCity(){var isNew=city.value==="new";nc.style.display=isNew?"":"none";nc.required=isNew;'
        . 'var list=cats[isNew||!city.value?0:city.value]||cats[0],cur=cat.value;cat.innerHTML="<option value=\"\">— pilih kategori —</option>";'
        . 'list.forEach(function(c){var o=document.createElement("option");o.value=c;o.textContent=c;if(c===cur)o.selected=true;cat.appendChild(o);});'
        . 'if(cur&&list.indexOf(cur)===-1){var o2=document.createElement("option");o2.value=cur;o2.textContent=cur;o2.selected=true;cat.appendChild(o2);}}'
        . 'city.addEventListener("change",syncCity);syncCity();'
        . '})();</script>';

    layout($isEdit ? 'Pengaturan tour' : 'Tour baru', $body);
}
