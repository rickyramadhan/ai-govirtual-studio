<?php

declare(strict_types=1);

/*
 * AI Guide Studio — application shell & design system.
 * Loaded by index.php. Replaces layout_v1() with a persistent AppShell
 * (sidebar + topbar + content). Backend routes/actions unchanged.
 */

if (!defined('APP_ROOT')) return;

/* ------------------------------------------------------------------ */
/* Context helpers                                                     */
/* ------------------------------------------------------------------ */

/** Resolve which nav section is active from the query action. */
function studio_nav_active(string $action): string {
    $map = [
        'list' => 'overview', 'new' => 'overview', 'edit' => 'overview', 'view' => 'overview', 'save' => 'overview', 'saveAs' => 'overview', 'import' => 'overview', 'delete' => 'overview', 'seedContent' => 'overview', 'saveContent' => 'overview', 'resync' => 'overview',
        'scenes' => 'scenes', 'saveScenes' => 'scenes', 'exportScenes' => 'scenes', 'importScenes' => 'scenes',
        'knowledge' => 'knowledge', 'saveKnowledge' => 'knowledge',
        'curated' => 'questions', 'saveCurated' => 'questions',
        'curatedqa' => 'curatedqa', 'saveCuratedQA' => 'curatedqa', 'importCuratedQA' => 'curatedqa',
        'flow' => 'flow', 'saveFlow' => 'flow',
        'prompts' => 'recommendations', 'savePrompts' => 'recommendations', 'importPrompts' => 'recommendations',
        'voice' => 'voice', 'saveVoice' => 'voice',
        'tips' => 'tips', 'saveTips' => 'tips',
        'nearby' => 'nearby', 'saveNearby' => 'nearby',
        'playground' => 'playground', 'playAnswer' => 'playground',
        'test' => 'test', 'testAnswer' => 'test',
        'history' => 'history', 'restoreHistory' => 'history', 'bulkImport' => 'overview', 'jobs' => 'jobs', 'jobEnqueue' => 'jobs', 'jobsStatus' => 'jobs', 'health' => 'health', 'healthEnqueue' => 'health', 'healthBase' => 'health',
        'theme' => 'theme', 'saveTheme' => 'theme', 'themeCss' => 'theme', 'users' => 'users', 'userCreate' => 'users', 'userUpdate' => 'users', 'userReset' => 'users', 'audit' => 'audit',
        'publish' => 'deployment', 'deploy' => 'deployment', 'generate' => 'deployment', 'syncRuntime' => 'sync',
        'keys' => 'api', 'saveKeys' => 'api', 'testKey' => 'api',
        'aihealth' => 'aihealth', 'aihealthRun' => 'aihealth',
        'account' => 'account', 'saveAccount' => 'account',
        'panoview' => 'scenes', 'thumb' => 'scenes',
        'cities' => 'cities', 'cityNew' => 'cities', 'city' => 'cities', 'citySave' => 'cities', 'citySources' => 'cities', 'citySourceUrl' => 'cities', 'citySourceFile' => 'cities', 'citySourceDelete' => 'cities', 'cityDelete' => 'cities',
    ];
    return $map[$action] ?? 'overview';
}

/**
 * Breadcrumb trail for current action (satu bahasa: Indonesia).
 * Halaman milik sebuah tour otomatis disisipi nama tour oleh layout().
 */
function studio_breadcrumb(string $action): array {
    $P = ['label' => 'Proyek', 'href' => '?'];
    $C = ['label' => 'Pengetahuan Kota', 'href' => '?a=cities'];
    $map = [
        'list' => [['label' => 'Proyek', 'href' => null]],
        'new' => [$P, ['label' => 'Buat Tour', 'href' => null]],
        'edit' => [$P, ['label' => 'Pengaturan Tour', 'href' => null]],
        'view' => [$P, ['label' => 'Ringkasan', 'href' => null]],
        'scenes' => [$P, ['label' => 'Tur & Panorama', 'href' => null]],
        'saveScenes' => [$P, ['label' => 'Tur & Panorama', 'href' => null]],
        'knowledge' => [$P, ['label' => 'Pengetahuan AI', 'href' => null]],
        'curated' => [$P, ['label' => 'Pertanyaan & Intent', 'href' => null]],
        'curatedqa' => [$P, ['label' => 'Tanya Jawab Terkurasi', 'href' => null]],
        'flow' => [$P, ['label' => 'Alur Navigasi', 'href' => null]],
        'saveFlow' => [$P, ['label' => 'Alur Navigasi', 'href' => null]],
        'prompts' => [$P, ['label' => 'Rekomendasi Pertanyaan', 'href' => null]],
        'voice' => [$P, ['label' => 'Percakapan Suara', 'href' => null]],
        'saveVoice' => [$P, ['label' => 'Percakapan Suara', 'href' => null]],
        'tips' => [$P, ['label' => 'Tips Pengunjung', 'href' => null]],
        'nearby' => [$P, ['label' => 'Tempat Sekitar', 'href' => null]],
        'playground' => [$P, ['label' => 'Playground', 'href' => null]],
        'test' => [$P, ['label' => 'Uji AI Guide', 'href' => null]],
        'history' => [$P, ['label' => 'Riwayat Perubahan', 'href' => null]],
        'bulkImport' => [$P, ['label' => 'Impor Massal', 'href' => null]],
        'jobs' => [['label' => 'Antrian Publikasi', 'href' => null]],
        'theme' => [$P, ['label' => 'Tema AI Guide', 'href' => null]],
        'saveTheme' => [$P, ['label' => 'Tema AI Guide', 'href' => null]],
        'users' => [['label' => 'Pengguna', 'href' => null]],
        'userCreate' => [['label' => 'Pengguna', 'href' => null]],
        'userUpdate' => [['label' => 'Pengguna', 'href' => null]],
        'userReset' => [['label' => 'Pengguna', 'href' => null]],
        'audit' => [['label' => 'Jejak Audit', 'href' => null]],
        'restoreHistory' => [$P, ['label' => 'Riwayat Perubahan', 'href' => null]],
        'publish' => [$P, ['label' => 'Publikasi', 'href' => null]],
        'deploy' => [$P, ['label' => 'Publikasi', 'href' => null]],
        'generate' => [$P, ['label' => 'Publikasi', 'href' => null]],
        'import' => [$P, ['label' => 'Import Pano', 'href' => null]],
        'delete' => [$P, ['label' => 'Hapus Proyek', 'href' => null]],
        'cities' => [['label' => 'Pengetahuan Kota', 'href' => null]],
        'cityNew' => [$C, ['label' => 'Kota Baru', 'href' => null]],
        'city' => [$C, ['label' => 'Edit Pengetahuan', 'href' => null]],
        'citySources' => [$C, ['label' => 'Sumber Data', 'href' => null]],
        'cityDelete' => [$C, ['label' => 'Hapus Kota', 'href' => null]],
        'keys' => [['label' => 'Provider API', 'href' => null]],
        'saveKeys' => [['label' => 'Provider API', 'href' => null]],
        'aihealth' => [['label' => 'Kesehatan AI', 'href' => null]],
        'syncRuntime' => [['label' => 'Sinkron Runtime', 'href' => null]],
        'account' => [['label' => 'Akun Saya', 'href' => null]],
        'saveAccount' => [['label' => 'Akun Saya', 'href' => null]],
    ];
    return $map[$action] ?? [['label' => 'AI Guide Studio', 'href' => '?']];
}
/* ------------------------------------------------------------------ */
/* Small UI atoms                                                      */
/* ------------------------------------------------------------------ */

function studio_icon(string $name, int $size = 18): string {
    $icons = [
        'overview' => '<path d="M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z"/>',
        'scenes' => '<path d="M21 3H3v18h18zM9 17H7v-2h2zm4 0h-2v-2h2zm4 0h-2v-2h2zM9 13H7v-2h2zm4 0h-2v-2h2zm4 0h-2v-2h2zM9 9H7V7h2zm4 0h-2V7h2zm4 0h-2V7h2z"/>',
        'knowledge' => '<path d="M4 5a4 4 0 0 1 4-4h12v20H8a4 4 0 0 0-4 4zM12 5h4v2h-4zM12 9h4v2h-4z"/>',
        'questions' => '<path d="M4 3h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H8l-5 4V4a1 1 0 0 1 1-1zm7 4v2h2V7zm0 4v2h2v-2z"/>',
        'curatedqa' => '<path d="M5 4h14v13H9l-4 3V4zm3 4h8M8 12h5"/>',
        'recommendations' => '<path d="M12 2l2.9 5.9 6.6.9-4.8 4.6 1.2 6.5L12 17.2 6.1 20.9l1.2-6.5L2.5 9.8l6.6-.9z"/>',
        'playground' => '<path d="M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1h-9l-5 4v-4H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm6 3v7l5-3.5z"/>',
        'test' => '<path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 4a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 12 6zm2 12h-4v-2h1v-4h-1v-2h3v6h1z"/>',
        'deployment' => '<path d="M3 13h18v6H3zM12 3a4 4 0 0 0-4 4 3 3 0 0 0-1 5.84V11a5 5 0 0 1 10 0v1.84A3 3 0 0 0 16 7a4 4 0 0 0-4-4z"/>',
        'flow' => '<path d="M4 4h8a4 4 0 0 1 0 8H4zM12 12h8a4 4 0 0 1 0 8h-8zM4 12h3v8H4z"/>',
        'api' => '<path d="M7 3H3v4h4zM9 3v4h2v4H9v4H3v-4H1V3h6zM7 9H3v2h4zM21 13h-4v4h4zM23 13v4h-4v-4h4zM15 13v4h-4v-4h4zM17 9h4v4h-4z"/>',
        'settings' => '<path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6zm-6 0h2v-2h-2z"/>',
        'sync' => '<path d="M4 7h10v3l5-4-5-4v3H2v6h2zM20 17H10v-3l-5 4 5 4v-3h12v-6h-2z"/>',
        'logo' => '<path d="M12 2l3.2 6.5 7.1 1-5.1 5 1.2 7.1L12 18.9 5.6 21.6l1.2-7.1-5.1-5 7.1-1z"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'chevron' => '<path d="M9 18l6-6-6-6"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 2"/>',
        'jobs' => '<path d="M4 6h16M4 12h16M4 18h10M18 16l2 2 3-4"/>',
        'theme' => '<path d="M12 3a9 9 0 1 0 0 18c1.4 0 2-1 2-2 0-1.5-1.5-1.5-1.5-3 0-1 .8-1.7 2-1.7H17a4 4 0 0 0 4-4C21 6 17 3 12 3zM7.5 12a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM10 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM15 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>',
        'users' => '<path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM2 20a6 6 0 0 1 12 0M14 14a5 5 0 0 1 8 6"/>',
        'audit' => '<path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h7M9 17h7"/>',
        'tips' => '<path d="M9 21h6M12 3a6 6 0 0 0-3 11.2V17h6v-2.8A6 6 0 0 0 12 3z"/>',
        'nearby' => '<path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/>',
        'voice' => '<path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3zM5 11a7 7 0 0 0 14 0M12 18v3"/>',
        'cities' => '<path d="M3 21V9l6-3v15M9 21V4l8 3v14M3 21h18M12 10h2M12 14h2M6 12h1M6 16h1"/>',
        'aihealth' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'health' => '<path d="M12 21s-7-4.4-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.6-9 9-9 9z"/><path d="M7 12h2l1-2 2 4 1-2h3"/>',
        'account' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0"/>',
    ];
    $p = $icons[$name] ?? $icons['settings'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Render sidebar nav link. */
function studio_nav_item(string $slug, string $label, string $href, string $active, bool $disabled = false): string {
    $icon = studio_icon(str_starts_with($slug, 'city-') ? 'cities' : $slug);
    if (str_starts_with($slug, 'sub-')) {
        $on = $active === $slug;
        return '<a class="sbnav-item is-sub' . ($on ? ' is-active' : '') . '" href="' . h($href) . '"' . ($on ? ' aria-current="page"' : '') . '><span class="sbnav-tx">' . h($label) . '</span></a>';
    }
    if ($disabled) {
        return '<span class="sbnav-item is-disabled" aria-disabled="true" title="' . h($label) . '"><span class="sbnav-ic">' . $icon . '</span><span class="sbnav-tx">' . h($label) . '</span></span>';
    }
    $isActive = $active === $slug;
    $cls = $isActive ? 'class="sbnav-item is-active"' : 'class="sbnav-item"';
    return '<a ' . $cls . ' href="' . h($href) . '"' . ($isActive ? ' aria-current="page"' : '') . ' data-tip="' . h($label) . '"><span class="sbnav-ic">' . $icon . '</span><span class="sbnav-tx">' . h($label) . '</span></a>';
}

/** Satu kota di sidebar: titik warna, nama, dan jumlah lokasi. Sub-menu muncul bila kota sedang dibuka. */
function studio_city_block(array $c, string $active): string {
    $on = $active === $c['slug'];
    $html = '<a class="sbnav-item sbnav-city' . ($on ? ' is-active' : '') . '" href="' . h($c['href']) . '"' . ($on ? ' aria-current="page"' : '') . ' data-tip="' . h($c['label']) . '">'
        . '<span class="sbnav-dot" style="--h:' . (int)$c['hue'] . '"></span><span class="sbnav-tx">' . h($c['label']) . '</span><span class="sbnav-count">' . (int)$c['count'] . '</span></a>';
    foreach ($c['subs'] as $s) $html .= studio_nav_item($s['slug'], $s['label'], $s['href'], $active);
    return $html;
}

/* ------------------------------------------------------------------ */
/* App shell                                                           */
/* ------------------------------------------------------------------ */

function layout(string $title, string $body): void {
    $action = $_GET['a'] ?? 'list';
    $active = studio_nav_active($action);
    if (isset($_GET['city']) && in_array($action, ['list', 'health', 'city', 'citySources'], true)) {
        $cs = city_slugify((string)$_GET['city']);
        $active = match ($action) { 'health' => 'sub-health-' . $cs, 'city', 'citySources' => 'sub-city-' . $cs, default => 'sub-list-' . $cs };
    }
    $crumbs = studio_breadcrumb($action);
    $tourName = '';
    if (isset($_GET['id'])) {
        $t = function_exists('get_tour') ? get_tour((int)$_GET['id']) : null;
        if ($t) $tourName = $t['name'];
    }
    $pageTitle = $tourName !== '' ? $tourName : $title;
    $tid = (int)($_GET['id'] ?? 0);

    // Breadcrumb halaman tour: Proyek / <nama tour> / <halaman>, supaya selalu ada jalan kembali ke ringkasan tour.
    if ($tourName !== '' && count($crumbs) >= 2 && ($crumbs[0]['label'] ?? '') === 'Proyek' && $action !== 'view') {
        array_splice($crumbs, 1, 0, [['label' => $tourName, 'href' => '?a=view&id=' . $tid]]);
    }

    // Simpan ≠ Publikasi: editor hanya menyimpan; Generate/Deploy ada di halaman Publikasi.
    if (function_exists('studio_simplify_save_bars')) $body = studio_simplify_save_bars($body, $tid);

    // Setiap form POST otomatis membawa token CSRF (diverifikasi di index.php).
    if (function_exists('csrf_token')) {
        $csrf = '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
        $body = (string)preg_replace_callback('/<form\b[^>]*>/i', function (array $m) use ($csrf): string {
            if (!preg_match('/\bmethod\s*=\s*["\']?post\b/i', $m[0])) return $m[0];
            return $m[0] . $csrf;
        }, $body);
    }

    if ($tid === 0) {
        // Hierarki: Kota → Lokasi. Sidebar menampilkan kota; lokasinya dibuka dari halaman kota.
        $main = [['slug' => 'overview', 'label' => 'Semua kota', 'href' => '?'], ['slug' => 'playground', 'label' => 'Playground', 'href' => '?a=playground']];
        $curCity = isset($_GET['city']) ? city_slugify((string)$_GET['city']) : '';
        // Kota dengan lokasi tampil biasa; kota tanpa lokasi dilipat supaya daftar tidak panjang.
        $withTours = []; $noTours = [];
        foreach (all_cities() as $c) {
            $cs = (string)$c['slug'];
            $subs = [];
            if ($cs === $curCity) {
                $subs = [
                    ['slug' => 'sub-list-' . $cs, 'label' => 'Lokasi tur', 'href' => '?a=list&city=' . urlencode($cs)],
                    ['slug' => 'sub-health-' . $cs, 'label' => 'Kesehatan AI', 'href' => '?a=health&city=' . urlencode($cs)],
                    ['slug' => 'sub-city-' . $cs, 'label' => 'Pengetahuan kota', 'href' => '?a=city&city=' . urlencode($cs)],
                ];
            }
            $row = ['slug' => 'city-' . $cs, 'label' => (string)$c['name'], 'count' => (int)$c['tour_count'], 'href' => '?a=list&city=' . urlencode($cs), 'hue' => function_exists('dash_city_hue') ? dash_city_hue($cs) : 40, 'subs' => $subs];
            if ((int)$c['tour_count'] > 0) $withTours[] = $row; else $noTours[] = $row;
        }
        $main[] = ['heading' => 'Kota'];
        foreach ($withTours as $r) $main[] = ['city' => $r];
        if ($noTours) $main[] = ['more' => $noTours, 'curCity' => $curCity];
        $main[] = ['heading' => 'Lainnya'];
        $main[] = ['slug' => 'jobs', 'label' => 'Antrian Publikasi', 'href' => '?a=jobs'];
        $u = current_user();
        if ($u && ($u['role'] ?? '') === 'qa') {
            $main = [
                ['slug' => 'overview', 'label' => 'Proyek', 'href' => '?'],
            ];
        }
    } else {
        // Dikelompokkan menurut pekerjaan editor: isi → percakapan → alur → rilis.
        $main = [
            ['heading' => 'Tour'],
            ['slug' => 'overview', 'label' => 'Ringkasan', 'href' => '?a=view&id=' . $tid],
            ['slug' => 'scenes', 'label' => 'Tur & Panorama', 'href' => '?a=scenes&id=' . $tid],
            ['slug' => 'theme', 'label' => 'Tema AI Guide', 'href' => '?a=theme&id=' . $tid],
            ['heading' => 'Konten'],
            ['slug' => 'knowledge', 'label' => 'Pengetahuan AI', 'href' => '?a=knowledge&id=' . $tid],
            ['slug' => 'tips', 'label' => 'Tips Pengunjung', 'href' => '?a=tips&id=' . $tid],
            ['slug' => 'nearby', 'label' => 'Tempat Sekitar', 'href' => '?a=nearby&id=' . $tid],
            ['heading' => 'Percakapan'],
            ['slug' => 'questions', 'label' => 'Pertanyaan & Intent', 'href' => '?a=curated&id=' . $tid],
            ['slug' => 'curatedqa', 'label' => 'Tanya Jawab Terkurasi', 'href' => '?a=curatedqa&id=' . $tid],
            ['slug' => 'recommendations', 'label' => 'Rekomendasi', 'href' => '?a=prompts&id=' . $tid],
            ['slug' => 'voice', 'label' => 'Percakapan Suara', 'href' => '?a=voice&id=' . $tid],
            ['heading' => 'Navigasi'],
            ['slug' => 'flow', 'label' => 'Alur Navigasi', 'href' => '?a=flow&id=' . $tid],
            ['heading' => 'Rilis'],
            ['slug' => 'test', 'label' => 'Uji AI Guide', 'href' => '?a=test&id=' . $tid],
            ['slug' => 'deployment', 'label' => 'Publikasi', 'href' => '?a=publish&id=' . $tid],
            ['slug' => 'history', 'label' => 'Riwayat Perubahan', 'href' => '?a=history&id=' . $tid],
        ];
    }
    $bottom = [
        ['slug' => 'api', 'label' => 'Provider API', 'href' => '?a=keys'],
        ['slug' => 'aihealth', 'label' => 'Kesehatan Provider', 'href' => '?a=aihealth'],
        ['slug' => 'users', 'label' => 'Pengguna', 'href' => '?a=users'],
        ['slug' => 'audit', 'label' => 'Jejak Audit', 'href' => '?a=audit'],
    ];
    $u2 = current_user();
    if ($u2 && ($u2['role'] ?? '') === 'qa') {
        $bottom = []; // QA: pengetahuan kota dibuka dari sub-menu kota
    }
    // Playground ada di grup Studio saat daftar kota; di halaman tur ditaruh paling atas grup Manage (satu menu saja per halaman).
    if ($tid !== 0) array_unshift($bottom, ['slug' => 'playground', 'label' => 'Playground', 'href' => '?a=playground']);

    $navMain = '';
    foreach ($main as $n) {
        if (isset($n['heading'])) { $navMain .= '<p class="sbnav-label">' . h($n['heading']) . '</p>'; continue; }
        if (isset($n['city'])) { $navMain .= studio_city_block($n['city'], $active); continue; }
        if (isset($n['more'])) {
            $open = $n['curCity'] !== '' && in_array('city-' . $n['curCity'], array_column($n['more'], 'slug'), true); // buka otomatis bila kota aktif ada di dalamnya
            $inner = '';
            foreach ($n['more'] as $r) $inner .= studio_city_block($r, $active);
            $navMain .= '<details class="sbnav-more"' . ($open ? ' open' : '') . '><summary>Belum ada lokasi <span class="sbnav-count">' . count($n['more']) . '</span></summary>' . $inner . '</details>';
            continue;
        }
        $navMain .= studio_nav_item($n['slug'], $n['label'], $n['href'], $active, !empty($n['disabled']));
    }
    $navBottom = '';
    foreach ($bottom as $n) $navBottom .= studio_nav_item($n['slug'], $n['label'], $n['href'], $active);

    $crumbsHtml = '<nav class="crumbs" aria-label="Breadcrumb"><span class="crumb">' . studio_icon('logo', 14) . '</span>';
    foreach ($crumbs as $c) {
        $crumbsHtml .= '<span class="crumb-sep">/</span>';
        if ($c['href']) $crumbsHtml .= '<a class="crumb" href="' . h($c['href']) . '">' . h($c['label']) . '</a>';
        else $crumbsHtml .= '<span class="crumb is-current" aria-current="page">' . h($c['label']) . '</span>';
    }
    $crumbsHtml .= '</nav>';

    $cu = function_exists('current_user') ? current_user() : null;
    $userBadge = '';
    if ($cu) {
        $role = in_array(($cu['role'] ?? ''), ['admin', 'editor', 'qa'], true) ? (string)$cu['role'] : 'qa';
        $roleBadge = '<span class="user-role user-role-' . $role . '">' . $role . '</span>';
        $userBadge = '<div class="topbar-user" title="Masuk sebagai ' . h((string)($cu['username'] ?? '')) . '"><a class="topbar-user-name" href="?a=account" title="Akun saya &amp; ganti password"><span class="topbar-user-ic">' . studio_icon('account', 14) . '</span>' . h((string)($cu['username'] ?? '')) . '</a>' . $roleBadge . '<a class="topbar-logout" href="?a=logout">Keluar</a></div>';
    }
    $__page = '<!doctype html><html lang="' . current_lang() . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400..800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">'
        . '<title>' . h($title) . ' — AI Guide Studio</title>'
        . '<style>' . studio_css() . (function_exists('studio_ux_css') ? studio_ux_css() : '') . (function_exists('shell_v2_css') ? shell_v2_css() : '') . '</style>'
        . '</head><body class="app">'
        . '<a class="skip-link" href="#studio-main">Lewati ke konten</a>'
        . '<aside id="studio-sidebar" class="sbar" aria-label="Navigasi utama">'
        . '<div class="sbar-top"><a class="sbrand" href="?" aria-label="AI Guide Studio home">'
        . '<span class="sbrand-ic"><img class="sbrand-logo" src="assets/govirtual-logo.webp" alt="GoVirtual" height="26" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=\'https://govirtual.id/web/image/logo_wm.webp\';}else{this.style.display=\'none\';}"></span>'
        . '<span class="sbrand-tx">AI <b>Studio</b></span></a>'
        . '</div>'
        . '<nav class="sbnav">' . ($tid === 0 ? '<p class="sbnav-label">Studio</p>' : '') . $navMain
        . ($navBottom !== '' ? '<p class="sbnav-label sbnav-label-btm">Manage</p>' : '') . $navBottom . '</nav>'
        . '<div class="sbar-foot"><button type="button" class="sbar-toggle" data-toggle-sidebar aria-expanded="false" aria-label="Collapse sidebar">' . studio_icon('chevron', 16) . '<span>Collapse</span></button></div>'
        . '</aside>'
        . '<div class="sbar-backdrop" data-close-sidebar tabindex="-1"></div>'
        . '<div class="app-main">'
        . '<header class="topbar"><div class="topbar-left">'
        . '<button type="button" class="icon-btn topbar-burger" data-toggle-sidebar aria-controls="studio-sidebar" aria-expanded="false" aria-label="Buka navigasi">' . studio_icon('menu', 20) . '</button>'
        . $crumbsHtml . '</div>'
        . '<div class="topbar-right">' . lang_switch_html() . '<span class="topbar-title">' . h($pageTitle) . '</span>' . $userBadge . '</div>'
        . '</header>'
        . '<main id="studio-main" class="app-content"><div class="app-scroll">' . $body . '</div></main>'
        . '</div>'
        . ($tid > 0 && $action !== 'test' && function_exists('studio_chat_drawer') ? studio_chat_drawer($tid) : '')
        . i18n_js_tag()
        . '<script>' . studio_js() . '</script>'
        . (function_exists('studio_ux_js') ? '<script>' . studio_ux_js() . '</script>' : '')
        . '</body></html>';
    echo i18n_html($__page);
}

/* ------------------------------------------------------------------ */
/* CSS design tokens + shell styles                                    */
/* ------------------------------------------------------------------ */

function studio_css(): string {
    return <<<'CSS'
:root{
  --bg-app:#121315; --bg-sidebar:#15161a; --surface-1:#1a1b20; --surface-2:#202127; --surface-3:#26272e;
  --border:#33353d; --border-strong:#42444d;
  --text-primary:#f5f7fb; --text-secondary:#b4b6bd; --text-muted:#8a8d97;
  --primary:#c9a96e; --primary-hover:#d9b97f; --ai-accent:#c9a96e;
  --success:#22c55e; --warning:#f59e0b; --danger:#ef5350; --info:#38bdf8;
  --radius:14px; --radius-sm:10px; --radius-xs:8px;
  --space-1:4px; --space-2:8px; --space-3:12px; --space-4:16px; --space-5:24px; --space-6:32px; --space-7:40px;
  --sidebar-w:248px; --topbar-h:60px;
  --font:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
  --font-display:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
  --mono:"JetBrains Mono",Consolas,"Courier New",monospace;
  --shadow-1:0 1px 3px rgba(3,8,18,.45),0 1px 2px rgba(3,8,18,.3);
  --shadow-2:0 10px 32px rgba(3,8,18,.5),0 4px 12px rgba(3,8,18,.35);
  --focus-ring:0 0 0 3px rgba(201,169,110,.4);
  --ok-text:#052e16; --ok-bg:rgba(34,197,94,.15);
  --warn-text:#451a03; --warn-bg:rgba(245,158,11,.15);
  --danger-text:#fff; --danger-bg:rgba(239,83,80,.15);
  --info-bg:rgba(56,189,248,.15);
  --code-bg:#020617;
  --link-code:#93c5fd; --link-light:#86efac;
  --pill-bg:#334155; --pill-text:#94a3b8;
  --content-max:1280px;
}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;background:var(--bg-app);color:var(--text-primary);font-family:var(--font);font-size:14px;line-height:1.55;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;font-feature-settings:"cv11","ss01","ss03";font-optical-sizing:auto}
a{color:var(--primary);text-underline-offset:3px;text-decoration-thickness:1.2px}
a:hover{color:var(--primary-hover)}
a:focus-visible{outline:0;box-shadow:var(--focus-ring);border-radius:4px}
h1,h2,h3,h4,h5{line-height:1.2;margin:0 0 var(--space-3);font-weight:650;letter-spacing:-.02em}
h1{font-family:var(--font-display);font-size:clamp(24px,3vw,30px);font-weight:700;letter-spacing:-.028em;line-height:1.15}
h2{font-family:var(--font-display);font-size:clamp(17px,2vw,20px);font-weight:650;letter-spacing:-.02em;line-height:1.25}
h3{font-size:15.5px;font-weight:600;letter-spacing:-.015em}
h4{font-size:13.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--text-secondary)}
p{margin:0 0 var(--space-3)}
button{font-family:var(--font)}
img{max-width:100%;height:auto}
code,.mono{font-family:var(--mono);font-size:12px;background:var(--surface-2);border:1px solid var(--border);border-radius:6px;padding:2px 6px;word-break:break-all}
hr{border:0;border-top:1px solid var(--border);margin:var(--space-5) 0}

/* === Browser surfaces === */
::selection{background:rgba(201,169,110,.3);color:inherit}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--border-strong)}
*{scrollbar-color:var(--border) transparent;scrollbar-width:thin}
input,textarea,select,[contenteditable]{caret-color:var(--primary)}

/* === Status text colors === */
.ok{color:var(--success)}
.warn{color:var(--warning)}
.bad{color:var(--danger)}
.muted{color:var(--text-muted)}
.mono,.key-mon{font-family:var(--mono);font-size:12px;color:var(--link-code);word-break:break-all}

/* === Reduced motion — preserve feedback, reduce intensity === */
@media(prefers-reduced-motion:reduce){
  *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.12s!important}
  .progress-bar,.sbar,.sbar-backdrop,.sbnav-item,.btn,.card{transition-duration:.01ms!important}
}

/* === Skip link === */
.skip-link{position:fixed;top:-100%;left:var(--space-3);z-index:1000;background:var(--primary);color:#fff;padding:var(--space-2) var(--space-4);border-radius:var(--radius-sm);}
.skip-link:focus{top:var(--space-2)}

/* === App layout — transform-based, no layout thrash === */
.app{display:flex;min-height:100vh}
.sbar{position:fixed;top:0;left:0;z-index:800;width:var(--sidebar-w);height:100vh;display:flex;flex-direction:column;background:var(--bg-sidebar);border-right:1px solid var(--border);transition:transform .22s cubic-bezier(.32,.72,0,1),width .22s cubic-bezier(.32,.72,0,1);will-change:transform} /* impeccable-disable-line layout-transition -- sidebar shell, toggled once per session */
.sbar-top{display:flex;align-items:center;justify-content:space-between;padding:var(--space-4);height:var(--topbar-h);border-bottom:1px solid var(--border)}
.sbrand{display:flex;align-items:center;gap:var(--space-3);text-decoration:none;color:var(--text-primary);font-size:15.5px;font-weight:700;letter-spacing:-.02em}
.sbrand-ic{color:var(--ai-accent);flex-shrink:0;display:flex;align-items:center}
.sbrand-logo{height:28px;width:auto;display:block;object-fit:contain}
.sbrand-tx b{color:var(--ai-accent);font-weight:700}
.sbar-close{display:none}
.sbnav{flex:1;overflow-y:auto;padding:var(--space-2) var(--space-2) var(--space-4)}
.sbnav-label{font-size:10.5px;text-transform:uppercase;letter-spacing:.9px;color:var(--text-muted);padding:var(--space-3) var(--space-3) var(--space-2);margin:0;font-weight:600}
.sbnav-label-btm{margin-top:var(--space-3);border-top:1px solid var(--border);padding-top:var(--space-4)}
.sbnav-item{display:flex;align-items:center;gap:var(--space-3);padding:10px var(--space-3);border-radius:var(--radius-xs);color:var(--text-secondary);text-decoration:none;transition:background .14s ease,color .14s ease,transform .14s ease;position:relative;margin-bottom:2px;min-height:40px}
.sbnav-item.is-disabled{color:var(--text-muted);opacity:.55;cursor:not-allowed;font-style:italic}
.sbnav-item:hover{background:var(--surface-2);color:var(--text-primary);transform:translateX(1px)}
.sbnav-item:focus-visible{outline:0;box-shadow:var(--focus-ring)}
.sbnav-item.is-active{background:var(--surface-1);color:var(--text-primary);border:1px solid var(--border)}
.sbnav-item.is-active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:18px;background:var(--primary);border-radius:0 3px 3px 0}
.sbnav-ic{flex-shrink:0;width:20px;display:flex;align-items:center;justify-content:center}
.sbnav-tx{font-size:13.5px;font-weight:550}
.sbar-foot{flex-shrink:0;padding:var(--space-2) var(--space-3);border-top:1px solid var(--border)}
.sbar-toggle{display:flex;align-items:center;gap:var(--space-2);width:100%;background:0;border:0;color:var(--text-muted);padding:var(--space-2);border-radius:var(--radius-xs);cursor:pointer;font-size:12.5px;min-height:36px}
.sbar-toggle:hover{background:var(--surface-2);color:var(--text-secondary)}
.sbar-backdrop{display:none;position:fixed;inset:0;z-index:750;background:rgba(3,8,18,.55);backdrop-filter:blur(4px);opacity:0;transition:opacity .2s ease}
.app-main{flex:1;margin-left:var(--sidebar-w);display:flex;flex-direction:column;min-height:100vh;transition:margin-left .22s cubic-bezier(.32,.72,0,1)} /* impeccable-disable-line layout-transition -- sidebar offset, single shell transition */

/* === Topbar === */
.topbar{display:flex;align-items:center;justify-content:space-between;height:var(--topbar-h);padding:0 var(--space-5);border-bottom:1px solid var(--border);background:rgba(18,19,21,.88);backdrop-filter:blur(10px);flex-shrink:0;gap:var(--space-3);position:sticky;top:0;z-index:400}
.topbar-left{display:flex;align-items:center;gap:var(--space-3);min-width:0}
.topbar-right{display:flex;align-items:center;gap:var(--space-3);flex-shrink:0}
.lang-switch{display:inline-flex;border:1px solid var(--border);border-radius:999px;padding:2px;background:var(--surface-2)}
.lang-opt{font-size:11px;font-weight:700;letter-spacing:.06em;padding:4px 9px;border-radius:999px;color:var(--text-muted);text-decoration:none}
.lang-opt:hover{color:var(--text-primary)}
.lang-opt.is-on{background:linear-gradient(135deg,#e2c28a,#c9a96e);color:#1a1409}
.topbar-brand{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:inherit;padding-right:var(--space-3);margin-right:var(--space-1);border-right:1px solid var(--border)}
.topbar-brand-logo{height:26px;width:auto;display:block;object-fit:contain}
.topbar-brand-tx{display:flex;flex-direction:column;line-height:1.1}
.topbar-brand-tx b{font-family:var(--font-display);font-size:14px;font-weight:700;letter-spacing:.01em}
.topbar-brand-tx small{font-size:10px;text-transform:uppercase;letter-spacing:.14em;color:var(--ai-accent);font-weight:600}
.topbar-title{font-family:var(--font-display);font-size:16px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px;letter-spacing:-.015em}
.topbar-burger{display:none}
.topbar-user{display:inline-flex;align-items:center;gap:8px;font-size:12px;color:var(--text-secondary);background:var(--surface-2);border:1px solid var(--border);border-radius:999px;padding:4px 6px 4px 10px;white-space:nowrap}
.topbar-user-name,.topbar-user-ic{display:inline-flex;align-items:center;gap:5px}
a.topbar-user-name{color:inherit;text-decoration:none;border-radius:var(--radius-xs)}
a.topbar-user-name:hover{color:var(--text-primary);text-decoration:underline}
.user-role{font-size:10px;font-weight:700;line-height:1;padding:4px 7px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em}.user-role-admin{background:rgba(34,197,94,.15);color:var(--success)}.user-role-qa{background:rgba(201,169,110,.16);color:#c4b5fd}
.topbar-logout{color:var(--text-secondary);padding:5px 8px;border-radius:999px;text-decoration:none}.topbar-logout:hover{background:var(--surface-3);color:var(--text-primary)}
.login-wrap{max-width:380px;margin:8vh auto 0}
.login-wrap .card{padding:var(--space-5)}
.login-wrap h2{font-family:var(--font-display);margin-top:0}

/* === Breadcrumb === */
.crumbs{display:flex;align-items:center;gap:var(--space-1);color:var(--text-muted);font-size:13px;min-width:0;flex-wrap:wrap}
.crumb-sep{color:var(--border-strong);margin:0 1px}
.crumb{display:flex;align-items:center;gap:var(--space-1);color:var(--text-muted);text-decoration:none;white-space:nowrap}
.crumb:hover{color:var(--text-secondary)}
.crumb.is-current{color:var(--text-primary);font-weight:500}

/* === Content area === */
.app-content{flex:1;overflow-y:auto;position:relative}
.app-scroll{padding:var(--space-5) var(--space-6);max-width:var(--content-max);width:100%;margin:0 auto}

/* === Icon button === */
.icon-btn{display:inline-flex;align-items:center;justify-content:center;background:0;border:0;color:var(--text-secondary);cursor:pointer;border-radius:var(--radius-xs);width:40px;height:40px;min-width:40px;min-height:40px;padding:0;transition:background .14s ease,color .14s ease}
.icon-btn:hover{background:var(--surface-2);color:var(--text-primary)}
.icon-btn:focus-visible{outline:0;box-shadow:var(--focus-ring)}

/* === Status badges === */
.badge{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:999px;letter-spacing:.2px;white-space:nowrap;line-height:1.3}
.badge-draft{background:var(--surface-3);color:var(--text-muted)}
.badge-ready{background:rgba(34,197,94,.15);color:var(--success)}
.badge-needs{background:rgba(245,158,11,.15);color:var(--warning)}
.badge-deployed{background:rgba(56,189,248,.15);color:var(--info)}
.badge-error{background:rgba(239,83,80,.15);color:var(--danger)}

/* === Cards === */
.card{background:var(--surface-1);border:1px solid var(--border);border-radius:var(--radius);padding:var(--space-5);margin-bottom:var(--space-4);box-shadow:var(--shadow-1);transition:border-color .16s ease,transform .16s ease,box-shadow .16s ease}
.card-hover:hover{border-color:var(--border-strong);transform:translateY(-1px);box-shadow:var(--shadow-2)}
.card-compact{padding:var(--space-4)}
.card-flat{background:transparent;border:1px solid var(--border)}
.card-group{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:var(--space-4);margin-bottom:var(--space-4)}

/* === Metric cards === */
.metric{display:flex;flex-direction:column;gap:var(--space-1)}
.metric-val{font-size:28px;font-weight:700;line-height:1}
.metric-label{font-size:12.5px;color:var(--text-muted);font-weight:500}
.metric-sub{font-size:12px;color:var(--text-secondary);margin-top:1px}

/* === Buttons — 44px touch targets === */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:var(--space-2);padding:10px 18px;border-radius:var(--radius-xs);font-size:13.5px;font-weight:600;text-decoration:none;border:1px solid transparent;cursor:pointer;transition:background .14s ease,color .14s ease,border-color .14s ease,transform .14s ease,box-shadow .14s ease;white-space:nowrap;line-height:1.3;min-height:44px;letter-spacing:-.01em}
.btn:active{transform:translateY(1px)}
.btn:focus-visible{outline:0;box-shadow:var(--focus-ring)}
.btn-primary{background:var(--primary);color:#1a1409;font-weight:600;box-shadow:0 1px 2px rgba(201,169,110,.25)}
.btn-primary:hover{background:var(--primary-hover);color:inherit;transform:translateY(-1px);box-shadow:0 4px 12px rgba(201,169,110,.3)}
.btn-ai{background:var(--ai-accent);color:#fff;box-shadow:0 1px 2px rgba(201,169,110,.25)}
.btn-ai:hover{background:#7c3aed;transform:translateY(-1px);box-shadow:0 4px 12px rgba(201,169,110,.3)}
.btn-success{background:var(--success);color:#052e16}
.btn-success:hover{filter:brightness(1.05);transform:translateY(-1px)}
.btn-warning{background:var(--warning);color:#451a03}
.btn-warning:hover{filter:brightness(1.05);transform:translateY(-1px)}
.btn-danger{background:var(--danger);color:#fff}
.btn-danger:hover{filter:brightness(1.08)}
.btn-ghost{background:transparent;color:var(--text-secondary);border-color:var(--border)}
.btn-ghost:hover{background:var(--surface-2);color:var(--text-primary);border-color:var(--border-strong)}
.btn-sm{padding:8px 14px;font-size:12.5px;min-height:36px}
.btn-icon{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;min-width:40px;min-height:40px;border-radius:var(--radius-xs);padding:0;background:transparent;border:0;color:var(--text-secondary);cursor:pointer;transition:background .14s ease,color .14s ease}
.btn-icon:hover{background:var(--surface-2);color:var(--text-primary)}

/* === Forms — Impeccable polish === */
label{display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;letter-spacing:.02em;text-transform:uppercase}
label:has(+ input:required)::after,label:has(+ textarea:required)::after{content:" *";color:var(--danger);font-weight:700}
/* textual inputs — matches ALL <input> without type (index.php generates bare <input>) */
input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]),textarea,select{
  width:100%;background:var(--surface-2);color:var(--text-primary);
  border:1.5px solid var(--border);border-radius:var(--radius-xs);
  padding:11px 14px;font-size:13.5px;font-family:var(--font);font-weight:500;
  min-height:44px;line-height:1.45;
  transition:border-color .16s ease,box-shadow .16s ease,background .16s ease;
  box-shadow:inset 0 1px 2px rgba(10,10,12,.25);
}
input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button])::placeholder,textarea::placeholder{color:var(--text-muted);opacity:.9;font-weight:400}
input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):hover,textarea:hover,select:hover{border-color:var(--border-strong);background:rgba(32,33,39,.92)}
input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):focus,textarea:focus,select:focus{outline:0;border-color:var(--primary);box-shadow:var(--focus-ring),inset 0 1px 2px rgba(10,10,12,.2);background:var(--surface-1)}
input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):disabled,textarea:disabled,select:disabled{opacity:.55;cursor:not-allowed;background:var(--surface-3)}
input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):user-invalid,textarea:user-invalid,select:user-invalid{border-color:var(--danger);box-shadow:0 0 0 3px rgba(239,83,80,.15)}
input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):user-valid,textarea:user-valid{border-color:rgba(34,197,94,.35)}
textarea{padding:12px 14px;line-height:1.65;min-height:96px;resize:vertical}
select{
  cursor:pointer;appearance:none;
  background-image:linear-gradient(45deg,transparent 50%,var(--text-muted) 50%),linear-gradient(135deg,var(--text-muted) 50%,transparent 50%);
  background-position:calc(100% - 18px) calc(50% - 2px),calc(100% - 12px) calc(50% - 2px);
  background-size:6px 6px,6px 6px;background-repeat:no-repeat;padding-right:36px;
}
select:focus{background-image:linear-gradient(45deg,transparent 50%,var(--primary) 50%),linear-gradient(135deg,var(--primary) 50%,transparent 50%)}
input[type=checkbox],input[type=radio]{width:18px;height:18px;margin-right:var(--space-2);accent-color:var(--primary);vertical-align:middle;min-height:auto;box-shadow:none;cursor:pointer}
input[type=checkbox]:focus-visible,input[type=radio]:focus-visible{outline:0;box-shadow:var(--focus-ring)}
/* search variant — subtle pill */
input[type=search]{border-radius:999px;padding-left:40px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2374839a' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='M20 20L16 16'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:14px 50%;background-size:16px}
input[type=search]:focus{background-position:14px 50%}
.form-group{margin-bottom:var(--space-4)}
.form-group label{margin-bottom:8px}
.form-2col{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)}
.form-3col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:var(--space-4)}
.form-help{font-size:12px;color:var(--text-muted);margin-top:6px;line-height:1.5}
.form-help code{font-size:11.5px}
.form-error{font-size:12px;color:var(--danger);margin-top:6px;display:flex;align-items:center;gap:6px}
.field{margin-bottom:var(--space-4)}
.field label{margin-bottom:8px}
.input-group{display:flex;align-items:stretch}
.input-group input{flex:1;border-radius:var(--radius-xs) 0 0 var(--radius-xs)}
.input-group .btn{border-radius:0 var(--radius-xs) var(--radius-xs) 0;margin-left:-1.5px;min-height:44px}
.key-mon{font-family:var(--mono);font-size:12px;letter-spacing:.02em}
textarea.key-mon{font-size:11.5px;line-height:1.6}

/* === Chips === */
.chip{display:inline-flex;align-items:center;gap:4px;background:var(--surface-3);border:1px solid var(--border);border-radius:999px;padding:2px 10px;font-size:12px;font-weight:500;color:var(--text-secondary)}
.chip-rem{background:0;border:0;color:var(--text-muted);cursor:pointer;padding:0 2px;font-size:14px;line-height:1}
.chip-rem:hover{color:var(--danger)}

/* === Tables === */
.table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface-1)}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;padding:12px var(--space-3);border-bottom:1px solid var(--border);background:var(--surface-2);white-space:nowrap}
td{padding:12px var(--space-3);border-bottom:1px solid var(--border);font-size:13.5px;color:var(--text-secondary)}
tbody tr:last-child td{border-bottom:0}
tbody tr:hover td{background:var(--surface-2)}
tbody tr:focus-within td{background:rgba(201,169,110,.06)}

/* === Progress bar — transform-based (no layout thrash) === */
.progress-wrap{background:var(--surface-2);border-radius:999px;height:8px;overflow:hidden;position:relative}
.progress-bar{height:100%;border-radius:999px;background:var(--primary);transform-origin:left;transition:transform .4s cubic-bezier(.32,.72,0,1);will-change:transform;min-width:4px}
.progress-bar.success{background:var(--success)}
.progress-bar.warning{background:var(--warning)}
.progress-bar.danger{background:var(--danger)}

/* === Toast === */
.toast-container{position:fixed;bottom:var(--space-5);right:var(--space-5);z-index:900;display:flex;flex-direction:column;gap:var(--space-2)}
.toast{display:flex;align-items:center;gap:var(--space-3);padding:var(--space-3) var(--space-4);border-radius:var(--radius-sm);font-size:13px;font-weight:500;box-shadow:var(--shadow-2);animation:toast-in .2s ease}
.toast-success{background:var(--success);color:#052e16}
.toast-error{background:var(--danger);color:#fff}
.toast-info{background:var(--info);color:#0c4a6e}
@keyframes toast-in{from{opacity:0;transform:translateY(8px)}}

/* === Empty state === */
.empty{padding:var(--space-6);text-align:center;color:var(--text-muted)}
.empty-ic{margin-bottom:var(--space-3);opacity:.4}
.empty h3{color:var(--text-secondary);margin-bottom:var(--space-2)}
.empty p{font-size:13px;max-width:480px;margin:0 auto var(--space-4)}
.pick-tour-list{display:grid;gap:var(--space-2);max-width:480px;margin:0 auto var(--space-4);text-align:left}
.pick-tour{display:flex;align-items:center;justify-content:space-between;gap:var(--space-3);padding:10px var(--space-3);background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-xs);color:var(--text-primary);text-decoration:none;transition:background .14s ease,border-color .14s ease}
.pick-tour:hover{background:var(--surface-3);border-color:var(--border-strong)}
.pick-tour-name{font-weight:600;font-size:13.5px}
.pick-tour-loc{font-size:12px;color:var(--text-muted)}

/* === Sidebar collapsed — transform, not layout thrash === */
body.sbar-collapsed .sbar{width:68px;overflow:hidden}
body.sbar-collapsed .sbar .sbrand-tx,body.sbar-collapsed .sbar .sbnav-tx,body.sbar-collapsed .sbar .sbnav-label,body.sbar-collapsed .sbar .sbar-toggle span{display:none}
body.sbar-collapsed .sbar .sbnav-item{justify-content:center;padding:10px 8px}
body.sbar-collapsed .sbar .sbnav-item.is-active::before{display:none}
body.sbar-collapsed .sbar .sbnav-item.is-active{background:rgba(201,169,110,.12);border-color:transparent}
body.sbar-collapsed .sbar .sbnav-item.is-active .sbnav-ic{color:var(--primary)}
body.sbar-collapsed .sbar .sbar-toggle{justify-content:center}
body.sbar-collapsed .sbar .sbar-toggle svg{transform:rotate(180deg)}
body.sbar-collapsed .app-main{margin-left:68px}

/* === Responsive === */
@media(max-width:1024px){
  .app-scroll{padding:var(--space-4) var(--space-4)}
  .form-2col,.form-3col{grid-template-columns:1fr}
  .card-group{grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}
}
@media(max-width:768px){
  .sbar{transform:translateX(-100%)}
  .sbar-close{display:flex}
  .sbar-backdrop{display:block;opacity:0;pointer-events:none;transition:opacity .2s}
  .topbar-burger{display:flex}
  .app-main{margin-left:0}
  body.sbar-collapsed .sbar{width:var(--sidebar-w);transform:translateX(-100%)}
  body.sbar-collapsed .app-main{margin-left:0}
  body.sbar-open .sbar{transform:translateX(0)}
  body.sbar-open .sbar-backdrop{opacity:1;pointer-events:auto}
  body.sbar-collapsed.sbar-open .sbar{width:var(--sidebar-w);transform:translateX(0)}
  .app-scroll{padding:var(--space-3) var(--space-3)}
  .topbar{padding:0 var(--space-3)}
  .crumbs{font-size:12px}
  .topbar-title{max-width:120px;font-size:13px}
  h1{font-size:24px}
  h2{font-size:18px}
  .card-group{grid-template-columns:1fr}
}

/* === Dashboard (Projects) === */
.dash-head{display:flex;align-items:flex-end;justify-content:space-between;gap:var(--space-4);margin-bottom:var(--space-5);flex-wrap:wrap}
.dash-head h1{margin:0}
.dash-head p{margin:var(--space-2) 0 0;color:var(--text-muted);font-size:13px}
.dash-actions{display:flex;gap:var(--space-2);flex-wrap:wrap}
.metric-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:var(--space-4);margin-bottom:var(--space-5)}
.metric-card{background:var(--surface-1);border:1px solid var(--border);border-radius:var(--radius);padding:var(--space-4)}
.metric-val.metric-ready{color:var(--success)}
.metric-val.metric-warn{color:var(--warning)}
.toolbar{display:flex;align-items:center;gap:var(--space-3);margin-bottom:var(--space-4);flex-wrap:wrap}
.toolbar input[type=search]{max-width:280px}
.filters{display:flex;gap:var(--space-2);flex-wrap:wrap}
.filter-chip{border:1px solid var(--border);background:var(--surface-1);color:var(--text-secondary);border-radius:999px;padding:8px 16px;font-size:12.5px;cursor:pointer;font-weight:600;min-height:36px;transition:background .14s ease,color .14s ease,border-color .14s ease,transform .14s ease,box-shadow .14s ease}
.filter-chip:hover{border-color:var(--border-strong);color:var(--text-primary);transform:translateY(-1px)}
.filter-chip:focus-visible{outline:0;box-shadow:var(--focus-ring)}
.filter-chip.is-on{background:var(--primary);border-color:var(--primary);color:#fff;box-shadow:0 2px 8px rgba(201,169,110,.25)}
a.filter-chip{text-decoration:none;display:inline-block}
.pager{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:var(--space-5)}
.pager-i{border:1px solid var(--border);background:var(--surface-1);color:var(--text-secondary);border-radius:var(--radius-xs);padding:6px 12px;font-size:12.5px;text-decoration:none}
a.pager-i:hover{border-color:var(--border-strong);color:var(--text-primary)}
.pager-i.is-on{background:var(--primary);border-color:var(--primary);color:#fff}
.pager-i.is-disabled{opacity:.4}
.pager-gap{color:var(--text-muted);padding:0 2px}
.filter-sep{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);align-self:center}
.tour-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:var(--space-4)}
.tour-card{background:var(--surface-1);border:1px solid var(--border);border-radius:var(--radius);display:flex;flex-direction:column;transition:border-color .16s ease,transform .16s ease,box-shadow .16s ease;overflow:hidden}
.tour-card:hover{border-color:var(--border-strong);transform:translateY(-2px);box-shadow:var(--shadow-2)}
.tour-card:focus-within{border-color:var(--primary);box-shadow:var(--focus-ring)}
.tour-card-head{padding:var(--space-4) var(--space-4) 0;display:flex;justify-content:space-between;gap:var(--space-2);align-items:flex-start}
.tour-card-head h3{margin:0;font-size:16px}
.tour-card-head h3 a{color:var(--text-primary);text-decoration:none}
.tour-card-head h3 a:hover{color:var(--primary)}
.tour-card-loc{font-size:12.5px;color:var(--text-muted);margin-top:2px;display:flex;align-items:center;gap:6px}
.export-changed-chip{font-size:14px;cursor:help;margin-left:auto}
.tour-card-body{padding:var(--space-4);flex:1;display:flex;flex-direction:column;gap:var(--space-3)}
.tour-card-meta{display:flex;gap:var(--space-3);flex-wrap:wrap;font-size:12px;color:var(--text-secondary)}
.tour-card-meta span{display:inline-flex;align-items:center;gap:5px}
.tour-card-foot{padding:var(--space-3) var(--space-4);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:var(--space-2)}
.readiness-row{display:flex;align-items:center;gap:var(--space-3)}
.readiness-row .progress-wrap{flex:1}
.readiness-pct{font-size:14px;font-weight:700;min-width:38px;text-align:right}
.card-menu{position:relative}
.card-menu-btn{background:transparent;border:1px solid var(--border);color:var(--text-secondary);border-radius:var(--radius-xs);width:40px;height:40px;min-width:40px;min-height:40px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:16px;line-height:1;transition:all .14s ease}
.card-menu-btn:hover{background:var(--surface-2);color:var(--text-primary);border-color:var(--border-strong)}
.card-menu-btn:focus-visible{outline:0;box-shadow:var(--focus-ring)}
.card-menu-btn[aria-expanded="true"]{background:var(--surface-2);border-color:var(--border-strong);color:var(--text-primary)}
.card-menu-pop{position:absolute;right:0;top:44px;background:var(--surface-2);border:1px solid var(--border-strong);border-radius:var(--radius-xs);box-shadow:var(--shadow-2);min-width:220px;z-index:60;display:none;overflow:hidden}
.card-menu-pop.open{display:block;animation:menu-in .14s ease}
@keyframes menu-in{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
.card-menu-pop a,.card-menu-pop button{display:flex;align-items:center;gap:var(--space-2);width:100%;text-align:left;padding:10px var(--space-3);font-size:13px;color:var(--text-secondary);text-decoration:none;background:transparent;border:0;cursor:pointer;min-height:40px;transition:background .12s ease}
.card-menu-pop a:hover,.card-menu-pop button:hover{background:var(--surface-3);color:var(--text-primary)}
.card-menu-pop a:focus-visible,.card-menu-pop button:focus-visible{outline:0;background:var(--surface-3)}
.card-menu-pop .is-danger{color:var(--danger)}
.card-menu-pop .is-danger:hover{background:rgba(239,83,80,.1)}

/* === Project Overview === */
.ov-grid{display:grid;grid-template-columns:1fr 340px;gap:var(--space-4);align-items:start}
@media(max-width:1024px){.ov-grid{grid-template-columns:1fr}}
.ov-head{display:flex;align-items:flex-start;justify-content:space-between;gap:var(--space-4);flex-wrap:wrap;margin-bottom:var(--space-5)}
.ov-head h1{margin:0;font-size:24px}
.ov-sub{color:var(--text-muted);font-size:13px;margin-top:var(--space-1);word-break:break-all}
.ov-actions{display:flex;gap:var(--space-2);flex-wrap:wrap}
.ready-ring{display:flex;align-items:center;gap:var(--space-4)}
.ready-ring-num{font-size:40px;font-weight:800;line-height:1}
.issue-item{display:flex;gap:var(--space-3);padding:var(--space-2) 0;border-bottom:1px solid var(--border);align-items:center}
.issue-item:last-child{border-bottom:0}
.issue-ic{flex-shrink:0;color:var(--warning)}
.issue-tx{flex:1;min-width:0}
.issue-tx b{display:block;font-size:13px;color:var(--text-primary);font-weight:500}
.issue-act{flex-shrink:0}
.checklist{list-style:none;margin:0;padding:0}
.checklist li{display:flex;align-items:center;gap:var(--space-3);padding:var(--space-2) 0;border-bottom:1px solid var(--border);font-size:13.5px}
.checklist li:last-child{border-bottom:0}
.check-ic{width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.check-ok{background:rgba(34,197,94,.15);color:var(--success)}
.check-nd{background:var(--surface-3);color:var(--text-muted)}
.check-tx{flex:1;min-width:0}
.check-tx b{font-weight:500}
.check-tx small{display:block;color:var(--text-muted);font-size:12px}
.check-act{flex-shrink:0}
.scene-chips{display:flex;flex-wrap:wrap;gap:var(--space-2)}
.scene-chip{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:4px 10px;font-size:12px;color:var(--text-secondary);display:inline-flex;align-items:center;gap:6px}
.coverage-item{display:flex;align-items:center;gap:var(--space-3);padding:var(--space-2) 0;border-bottom:1px solid var(--border)}
.coverage-item:last-child{border-bottom:0}
.coverage-tx{flex:1;font-size:13px;color:var(--text-secondary);min-width:0}
.coverage-bar{width:110px;flex-shrink:0}
.activity-item{display:flex;gap:var(--space-3);padding:var(--space-2) 0;border-bottom:1px solid var(--border);align-items:flex-start}
.activity-item:last-child{border-bottom:0}
.activity-ic{flex-shrink:0;color:var(--text-muted);margin-top:2px}
.activity-tx{font-size:13px;color:var(--text-secondary)}
.activity-tx b{color:var(--text-primary);font-weight:600}
.activity-time{font-size:11.5px;color:var(--text-muted);margin-top:2px}
@media(max-width:768px){
  .ready-ring-num{font-size:32px}
  .topbar-title,.topbar-user-name,.user-role{display:none}
  .topbar-user{padding:3px}.topbar-logout{min-height:36px;display:inline-flex;align-items:center}
  .toolbar{align-items:stretch}.toolbar input[type=search]{max-width:none}.filters{overflow-x:auto;flex-wrap:nowrap;padding-bottom:var(--space-2)}.filter-chip,.filter-sep{flex:0 0 auto}
  .tour-grid{grid-template-columns:1fr}.tour-card-foot{align-items:flex-start;flex-direction:column}.tour-card-foot .btn{width:100%}
  .card{padding:var(--space-4);border-radius:var(--radius-sm)}
  form [style*="grid-template-columns"],form [style*="display:grid"]{grid-template-columns:1fr!important}
  form [style*="display:flex"],.card>[style*="display:flex"]{flex-wrap:wrap}
  .ov-actions{width:100%}.ov-actions .btn,.ov-actions form,.ov-actions form .btn{flex:1 1 150px}
}
CSS;
}

/* ------------------------------------------------------------------ */
/* JS — sidebar toggle, toast, confirm dialog                          */
/* ------------------------------------------------------------------ */

function studio_js(): string {
    return <<<'JS'
(function(){
var sbar=document.getElementById('studio-sidebar');
var toggle=document.querySelectorAll('[data-toggle-sidebar]');
var close=document.querySelectorAll('[data-close-sidebar]');
var body=document.body;
var mobileToggle=document.querySelector('.topbar-burger');
var lastFocus=null;

function isCollapsed(){return body.classList.contains('sbar-collapsed');}
function isOpen(){return body.classList.contains('sbar-open');}
function syncSidebarState(){
  var open=isOpen();
  if(mobileToggle){mobileToggle.setAttribute('aria-expanded',open?'true':'false');mobileToggle.setAttribute('aria-label',open?'Tutup navigasi':'Buka navigasi');}
}
function closeSidebar(){
  if(!isOpen()) return;
  body.classList.remove('sbar-open');syncSidebarState();
  if(lastFocus&&lastFocus.focus) lastFocus.focus();
}

toggle.forEach(function(el){
  el.addEventListener('click',function(e){
    e.stopPropagation();
    if(window.innerWidth<=768){
      lastFocus=document.activeElement;
      body.classList.toggle('sbar-open');
      body.classList.remove('sbar-collapsed');
      syncSidebarState();
      if(isOpen()){var first=sbar.querySelector('a,button');if(first) first.focus();}
    }else{
      body.classList.toggle('sbar-collapsed');
      body.classList.remove('sbar-open');
    }
  });
});

close.forEach(function(el){el.addEventListener('click',closeSidebar);});

// Escape closes mobile drawer and restores trigger focus.
document.addEventListener('keydown',function(e){
  if(e.key==='Escape' && isOpen()) closeSidebar();
  if(e.key==='Tab' && isOpen()){
    var focusable=sbar.querySelectorAll('a[href],button:not([disabled])');
    if(!focusable.length) return;
    var first=focusable[0],last=focusable[focusable.length-1];
    if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
    else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
  }
});

// Persist collapsed state
try{
  var val=localStorage.getItem('studio-sbar-collapsed');
  if(val==='true' && window.innerWidth>768) body.classList.add('sbar-collapsed');
}catch(e){}
var toggleBtn=document.querySelector('.sbar-toggle');
if(toggleBtn){
  toggleBtn.addEventListener('click',function(){
    try{localStorage.setItem('studio-sbar-collapsed',isCollapsed()?'true':'false');}catch(e){}
  });
}

// Tooltip on hover for collapsed sidebar
var items=sbar.querySelectorAll('[data-tip]');
items.forEach(function(el){
  el.addEventListener('mouseenter',function(){
    if(!isCollapsed()||window.innerWidth<=768) return;
    var tip=el.getAttribute('data-tip');
    if(!tip) return;
    var t=document.createElement('div');
    t.className='sbar-tooltip';
    t.textContent=tip;
    t.style.cssText='position:fixed;left:68px;top:'+(el.getBoundingClientRect().top+8)+'px;background:var(--surface-3);color:var(--text-primary);font-size:12.5px;padding:6px 12px;border-radius:6px;border:1px solid var(--border);z-index:900;white-space:nowrap;pointer-events:none';
    document.body.appendChild(t);
    el._tipEl=t;
  });
  el.addEventListener('mouseleave',function(){
    if(el._tipEl){el._tipEl.remove();el._tipEl=null;}
  });
});
})();
JS;
}

/* ------------------------------------------------------------------ */
/* Phase 2 — tour analytics, readiness, dashboard JS                   */
/* ------------------------------------------------------------------ */

/**
 * Single source of truth for tour readiness/analytics. Reads real stored
 * data (parsed.json + content JSONs). Never fabricates values.
 */
function studio_tour_stats(array $tour): array {
    $dir = tour_dir($tour['slug']);
    $parsed = read_json($dir . '/parsed.json');
    $scenes = count($parsed['scenes'] ?? []);
    $issues = $parsed ? validate_parsed($parsed) : [];

    $curated = read_json($dir . '/curated-answers.json');
    $intents = count($curated['intents'] ?? []);

    $knowledge = read_json($dir . '/knowledge.json');
    $entries = $knowledge['entries'] ?? [];
    $knowTotal = count($entries);
    $knowDone = 0;
    foreach ($entries as $e) {
        $c = trim((string)($e['content'] ?? ''));
        if ($c !== '' && !str_contains($c, 'Isi ') && !str_contains($c, 'Pusat pengalaman') && !str_contains($c, 'Template starter')) $knowDone++;
    }

    $si = read_json($dir . '/scene-identity.json');
    $siScenes = count($si['scenes'] ?? []);

    $tips = read_json($dir . '/visitor-tips.json');
    $tipsTotal = count($tips['topics'] ?? []);

    $nearby = read_json($dir . '/nearby.json');
    $nearbyTotal = count($nearby['items'] ?? []);

    $rec = read_json($dir . '/recommended-questions.json');
    $recTotal = count($rec['state1']['starters'] ?? []) + count($rec['state1']['themes'] ?? []);

    $hasPackage = is_dir($dir . '/package/govirtual-ai');
    $export = trim((string)$tour['export_path']);
    $exportDir = $export !== '' && is_dir($export);
    $deployed = $hasPackage && $exportDir && is_dir($export . '/govirtual-ai');

    $steps = [
        ['key' => 'import', 'label' => 'Import Tour', 'desc' => 'Panorama + hotspot ter-parsing',
            'done' => $scenes > 0, 'act' => '?a=import&id=' . (int)$tour['id'], 'actLabel' => 'Import'],
        ['key' => 'scenes', 'label' => 'Review Scenes', 'desc' => 'Nama panorama & identitas scene',
            'done' => $siScenes > 0 && $siScenes >= $scenes, 'act' => '?a=scenes&id=' . (int)$tour['id'], 'actLabel' => 'Review'],
        ['key' => 'knowledge', 'label' => 'Complete Knowledge', 'desc' => 'Isi konten jawaban AI',
            'done' => $knowTotal > 0 && $knowDone > 0 && $knowDone >= $knowTotal, 'act' => '?a=knowledge&id=' . (int)$tour['id'], 'actLabel' => 'Edit'],
        ['key' => 'questions', 'label' => 'Configure Questions', 'desc' => 'Intents + recommended questions',
            'done' => $intents > 0 && $recTotal > 0, 'act' => '?a=curated&id=' . (int)$tour['id'], 'actLabel' => 'Configure'],
        ['key' => 'deploy', 'label' => 'Generate & Deploy', 'desc' => 'Package terpasang ke export',
            'done' => $deployed, 'act' => '?a=publish&id=' . (int)$tour['id'], 'actLabel' => 'Deploy'],
    ];
    $doneCount = 0; foreach ($steps as $s) if ($s['done']) $doneCount++;
    $ready = (int)round($doneCount / count($steps) * 100);
    $nextStep = null;
    foreach ($steps as $st) if (!$st['done']) { $nextStep = $st; break; }

    $status = 'draft';
    if ($deployed) $status = 'deployed';
    elseif ($ready >= 80) $status = 'ready';
    elseif ($doneCount >= 1) $status = 'needs';
    if ($issues && $status === 'ready') $status = 'needs';

    return [
        'status' => $status, 'ready' => $ready, 'doneSteps' => $doneCount, 'totalSteps' => count($steps),
        'steps' => $steps, 'issues' => $issues,
        'scenes' => $scenes, 'hotspots' => count($parsed['hotspots'] ?? []),
        'intents' => $intents, 'knowledgeTotal' => $knowTotal, 'knowledgeDone' => $knowDone,
        'siScenes' => $siScenes, 'tipsTotal' => $tipsTotal, 'nearbyTotal' => $nearbyTotal, 'recTotal' => $recTotal,
        'hasParsed' => $scenes > 0, 'hasPackage' => $hasPackage, 'exportSet' => $export !== '', 'deployed' => $deployed,
        'nextStep' => $nextStep,
        'exportState' => $parsed['_exportState'] ?? null, // baseline hash untuk deteksi perubahan export (tour_export_changes)
    ];
}

/** Status badge HTML. */
function studio_badge(string $status): string {
    $map = [
        'draft' => ['badge-draft', 'Draft'],
        'ready' => ['badge-ready', 'Ready'],
        'needs' => ['badge-needs', 'Needs Attention'],
        'deployed' => ['badge-deployed', 'Deployed'],
        'error' => ['badge-error', 'Error'],
    ];
    [$cls, $label] = $map[$status] ?? $map['draft'];
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}

/** Readiness bar + percentage — transform-based (impeccable: avoid width animation). */
function studio_readiness(int $pct): string {
    $bar = $pct >= 80 ? 'success' : ($pct >= 40 ? 'warning' : 'danger');
    $scale = max(0, min(100, $pct)) / 100;
    return '<div class="readiness-row"><div class="progress-wrap" role="progressbar" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100" aria-label="Readiness ' . $pct . '%"><div class="progress-bar ' . $bar . '" style="transform:scaleX(' . $scale . ')"></div></div><span class="readiness-pct" aria-hidden="true">' . $pct . '%</span></div>';
}

/** Metric card. */
function studio_metric(string $label, string $val, string $sub = '', string $cls = ''): string {
    return '<div class="metric-card"><div class="metric"><div class="metric-val ' . $cls . '">' . $val . '</div><div class="metric-label">' . $label . '</div>' . ($sub !== '' ? '<div class="metric-sub">' . $sub . '</div>' : '') . '</div></div>';
}

/** Dashboard JS: search, filter, card menus. */
function studio_dash_js(): string {
    return <<<'JS'
(function(){
  // Filter, urutan, dan paginasi dilakukan di server (app/dashboard.php); JS hanya mengurus menu kartu.
  document.addEventListener('click',function(e){
    var btn=e.target.closest?e.target.closest('[data-menu-btn]'):null;
    var pop=e.target.closest?e.target.closest('.card-menu-pop'):null;
    document.querySelectorAll('.card-menu-pop.open').forEach(function(p){
      if(pop!==p && (!btn||p.parentElement!==btn.parentElement)){ p.classList.remove('open'); var b=p.parentElement.querySelector('[data-menu-btn]'); if(b) b.setAttribute('aria-expanded','false'); }
    });
    if(btn){
      var popup=btn.parentElement.querySelector('.card-menu-pop');
      if(popup){ var isOpen=popup.classList.toggle('open'); btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false'); if(isOpen) { var first=popup.querySelector('[role="menuitem"]'); if(first) first.focus(); } }
    }
  });
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){ document.querySelectorAll('.card-menu-pop.open').forEach(function(p){ p.classList.remove('open'); var b=p.parentElement.querySelector('[data-menu-btn]'); if(b) b.setAttribute('aria-expanded','false'); }); }
  });
  document.querySelectorAll('[data-del-url]').forEach(function(el){
    // Konfirmasi (ketik nama project) ditangani halaman ?a=delete; di sini cukup menuju ke sana.
    el.addEventListener('click',function(){ window.location.href=el.getAttribute('data-del-url'); });
  });
})();
JS;
}