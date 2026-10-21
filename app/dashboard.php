<?php

declare(strict_types=1);

require_once __DIR__ . '/dashboard_view.php';

/**
 * Dashboard proyek. Filter, urutan, dan paginasi dilakukan di server (bukan menyembunyikan kartu lewat JS),
 * statistik berasal dari cache (app/stats.php), jadi tetap cepat walau tour-nya ratusan.
 */

function dashboard_query(array $base, array $override = []): string
{
    $q = array_merge($base, $override);
    foreach ($q as $k => $v) if ($v === '' || $v === null || ($k === 'page' && (int)$v <= 1) || ($k === 'st' && $v === 'all') || ($k === 'sort' && $v === 'updated')) unset($q[$k]);
    return '?' . http_build_query($q);
}

function render_dashboard(string $error = ''): void
{
    $perPage = 24;
    $q = trim((string)($_GET['q'] ?? ''));
    $st = (string)($_GET['st'] ?? 'all');
    $cityF = (string)($_GET['city'] ?? '');
    $catF = (string)($_GET['cat'] ?? '');
    $sort = in_array($_GET['sort'] ?? '', ['updated', 'name', 'progress'], true) ? (string)$_GET['sort'] : 'updated';
    $page = max(1, (int)($_GET['page'] ?? 1));
    if (!in_array($st, ['all', 'draft', 'needs', 'ready', 'deployed'], true)) $st = 'all';

    $tours = all_tours();
    $stats = []; $counts = ['draft' => 0, 'needs' => 0, 'ready' => 0, 'deployed' => 0];
    $sceneTotal = 0; $knowTotal = 0; $cityCount = []; $catCount = []; $changedTours = 0; $rtOutdated = 0; $rtByTour = [];
    foreach ($tours as $t) {
        $s = tour_stats_cached($t);
        $s['exportChanged'] = tour_export_changes($t, $s);
        $stats[(int)$t['id']] = $s;
        $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1;
        $sceneTotal += (int)$s['scenes']; $knowTotal += (int)$s['knowledgeTotal'];
        $cs = tour_city_slug($t); $cityCount[$cs] = ($cityCount[$cs] ?? 0) + 1;
        $c = trim((string)$t['category']); if ($c !== '') $catCount[$c] = ($catCount[$c] ?? 0) + 1;
        if ($s['exportChanged']) $changedTours++;
        $rt = tour_runtime_status($t); $rtByTour[(int)$t['id']] = $rt;
        if ($rt['state'] === 'outdated') $rtOutdated++;
    }
    $total = count($tours);

    // ---- filter ----
    $visible = array_values(array_filter($tours, function ($t) use ($stats, $q, $st, $cityF, $catF) {
        $s = $stats[(int)$t['id']];
        if ($st !== 'all' && $s['status'] !== $st) return false;
        if ($cityF !== '' && tour_city_slug($t) !== $cityF) return false;
        if ($catF !== '' && trim((string)$t['category']) !== $catF) return false;
        if ($q !== '' && stripos((string)$t['name'] . ' ' . (string)$t['slug'] . ' ' . (string)$t['location'], $q) === false) return false;
        return true;
    }));
    if ($sort === 'name') usort($visible, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
    elseif ($sort === 'progress') usort($visible, fn($a, $b) => ($stats[(int)$a['id']]['ready'] <=> $stats[(int)$b['id']]['ready']) ?: strcasecmp((string)$a['name'], (string)$b['name']));
    $matched = count($visible);
    $pages = max(1, (int)ceil($matched / $perPage));
    $page = min($page, $pages);
    $pageTours = array_slice($visible, ($page - 1) * $perPage, $perPage);

    $base = ['q' => $q, 'st' => $st, 'city' => $cityF, 'cat' => $catF, 'sort' => $sort];

    $filtering = ($q !== '' || $st !== 'all' || $cityF !== '' || $catF !== '');
    $base = ['q' => $q, 'st' => $st, 'city' => $cityF, 'cat' => $catF, 'sort' => $sort];

    // ---- perlu perhatian (hanya tampilan awal tanpa filter) ----
    $attention = [];
    if (!$filtering && $page === 1) {
        foreach ($tours as $t) {
            $s = $stats[(int)$t['id']]; $id = (int)$t['id'];
            if ($s['exportChanged']) $attention[] = ['name' => (string)$t['name'], 'loc' => (string)$t['location'], 'why' => 'File export berubah (' . implode(', ', $s['exportChanged']) . ')', 'href' => '?a=publish&id=' . $id, 'cta' => 'Periksa'];
            elseif ($s['status'] === 'needs') $attention[] = ['name' => (string)$t['name'], 'loc' => (string)$t['location'], 'why' => $s['nextStep'] ? 'berikutnya: ' . $s['nextStep']['label'] : 'kurasi belum lengkap', 'href' => $s['nextStep']['act'] ?? ('?a=view&id=' . $id), 'cta' => 'Lanjutkan'];
        }
    }

    // ---- filter & tab ----
    $statusLabels = ['all' => 'Semua', 'draft' => 'Draf', 'needs' => 'Perlu perhatian', 'ready' => 'Siap', 'deployed' => 'Terpublikasi'];
    $tabs = [];
    foreach ($statusLabels as $k => $label) $tabs[] = ['label' => $label, 'n' => $k === 'all' ? $total : ($counts[$k] ?? 0), 'on' => $st === $k, 'href' => dashboard_query($base, ['st' => $k, 'page' => 1])];
    $cityOpts = '<option value="">Semua kota</option>';
    foreach (all_cities() as $c) if (($cityCount[$c['slug']] ?? 0) > 0) $cityOpts .= '<option value="' . h($c['slug']) . '"' . ($cityF === $c['slug'] ? ' selected' : '') . '>' . h($c['name']) . ' (' . $cityCount[$c['slug']] . ')</option>';
    $catOpts = '<option value="">Semua kategori</option>';
    ksort($catCount);
    foreach ($catCount as $c => $n) $catOpts .= '<option value="' . h($c) . '"' . ($catF === $c ? ' selected' : '') . '>' . h($c) . ' (' . $n . ')</option>';
    $sortOpts = '';
    foreach (['updated' => 'Terbaru diubah', 'name' => 'Nama A–Z', 'progress' => 'Paling belum lengkap'] as $k => $label) $sortOpts .= '<option value="' . $k . '"' . ($sort === $k ? ' selected' : '') . '>' . h($label) . '</option>';

    // ---- kartu ----
    $cards = []; $i = 0;
    foreach ($pageTours as $t) {
        $s = $stats[(int)$t['id']]; $id = (int)$t['id'];
        $continue = '?a=scenes&id=' . $id; $cta = 'Lanjutkan';
        if (!$s['hasParsed']) { $continue = '?a=import&id=' . $id; $cta = 'Impor pano'; }
        elseif ($s['status'] === 'needs') $continue = '?a=knowledge&id=' . $id;
        elseif ($s['status'] === 'ready') { $continue = '?a=publish&id=' . $id; $cta = 'Publikasikan'; }
        $rt = $rtByTour[$id] ?? ['state' => 'none', 'diff' => []];
        $exportWarn = ($rt['state'] === 'outdated' ? '<span class="export-changed-chip" title="Runtime terpasang berbeda dari master">runtime lama</span>' : '')
            . ($s['exportChanged'] ? '<span class="export-changed-chip" title="Berubah: ' . h(implode(', ', $s['exportChanged'])) . '">🔄</span>' : '');
        $cards[] = ['id' => $id, 'i' => $i++, 'name' => (string)$t['name'], 'loc' => (string)$t['location'], 'cat' => (string)$t['category'], 's' => $s, 'continue' => $continue, 'cta' => $cta, 'exportWarn' => $exportWarn, 'hue' => dash_city_hue(tour_city_slug($t))];
    }
    $empty = $total === 0 ? 'none' : ($matched === 0 ? 'nomatch' : '');

    // ---- paginasi ----
    $pager = '';
    if ($pages > 1) {
        $pager = '<nav class="pager" aria-label="Halaman">';
        $link = fn(int $p, string $label, bool $on = false, bool $disabled = false) => $disabled ? '<span class="pager-i is-disabled">' . $label . '</span>' : '<a class="pager-i' . ($on ? ' is-on' : '') . '" href="' . h(dashboard_query($base, ['page' => $p])) . '"' . ($on ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        $pager .= $link($page - 1, '‹ Sebelumnya', false, $page <= 1);
        $from = max(1, $page - 2); $to = min($pages, $page + 2);
        if ($from > 1) $pager .= $link(1, '1') . ($from > 2 ? '<span class="pager-gap">…</span>' : '');
        for ($p = $from; $p <= $to; $p++) $pager .= $link($p, (string)$p, $p === $page);
        if ($to < $pages) $pager .= ($to < $pages - 1 ? '<span class="pager-gap">…</span>' : '') . $link($pages, (string)$pages);
        $pager .= $link($page + 1, 'Berikutnya ›', false, $page >= $pages);
        $pager .= '<span class="muted" style="margin-left:auto;font-size:12px">' . ((($page - 1) * $perPage) + 1) . '–' . min($matched, $page * $perPage) . ' dari ' . $matched . '</span></nav>';
    }

    $ready = ($counts['ready'] ?? 0) + ($counts['deployed'] ?? 0);
    $stats = [
        dash_stat('Total tour', (string)$total, count($cityCount) . ' kota', 0, '', 'rgba(201,169,110,.16)'),
        dash_stat('Siap / publik', (string)$ready, 'status siap atau terpublikasi', 1, 'is-good', 'rgba(34,197,94,.14)'),
        dash_stat('Perlu perhatian', (string)($counts['needs'] ?? 0), $changedTours > 0 ? $changedTours . ' export berubah' : 'kurasi belum lengkap', 2, ($counts['needs'] ?? 0) > 0 ? 'is-warn' : '', 'rgba(245,158,11,.14)'),
        dash_stat('Total scene', (string)$sceneTotal, 'panorama ter-parsing', 3, '', 'rgba(201,169,110,.12)'),
        dash_stat('Entri pengetahuan', (string)$knowTotal, 'konten jawaban AI', 4, '', 'rgba(201,169,110,.12)'),
    ];
    $kicker = 'AI Guide Studio · ' . date('d M Y');
    $body = dashboard_body([
        'kicker' => $kicker, 'title' => 'Selamat datang kembali', 'sub' => 'Kelola, kurasi, dan publikasikan AI Guide untuk setiap lokasi. ' . ($counts['needs'] > 0 ? $counts['needs'] . ' tour menunggu perhatian Anda.' : 'Semua tour dalam kondisi baik.'),
        'stats' => $stats, 'attention' => $attention, 'tabs' => $tabs, 'cityOpts' => $cityOpts, 'catOpts' => $catOpts, 'sortOpts' => $sortOpts,
        'q' => $q, 'st' => $st, 'filtering' => $filtering, 'cards' => $cards, 'empty' => $empty, 'pager' => $pager, 'error' => $error,
    ]);
    layout('Proyek', $body);
}
