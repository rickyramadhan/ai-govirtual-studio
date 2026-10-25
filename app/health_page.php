<?php

declare(strict_types=1);

/** Halaman Kesehatan AI: status per lokasi, dikelompokkan per kota, dengan tombol cek. */
function health_enqueue_scope(string $scope, string $ref, bool $ui): string
{
    $ids = [];
    if ($scope === 'all') $ids = array_map('intval', db()->query('SELECT id FROM tours')->fetchAll(PDO::FETCH_COLUMN));
    elseif ($scope === 'city') {
        $c = city_row_by_slug(city_slugify($ref));
        if (!$c) throw new RuntimeException('Kota tidak ditemukan.');
        $q = db()->prepare('SELECT id FROM tours WHERE city_id=?'); $q->execute([(int)$c['id']]); $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } else $ids = [(int)$ref];
    if (!$ids) throw new RuntimeException('Tidak ada lokasi yang bisa dicek.');
    return job_enqueue($ui ? 'healthui' : 'health', $ids, (string)(current_user()['username'] ?? ''));
}

function render_health_page(string $flash = '', string $cityScope = ''): void
{
    $latest = health_latest();
    $tours = all_tours();
    $cities = all_cities();
    if ($cityScope !== '') {
        $cities = array_values(array_filter($cities, fn($c) => $c['slug'] === $cityScope));
        $tours = array_values(array_filter($tours, fn($t) => tour_city_slug($t) === $cityScope));
        if (!$cities) { layout('Kesehatan AI', '<div class="card"><h2>Kota tidak ditemukan</h2><p><a href="?">← Kembali ke proyek</a></p></div>'); return; }
    }
    $tourByCity = [];
    foreach ($tours as $t) $tourByCity[tour_city_slug($t)][] = $t;

    $tot = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'none' => 0];
    foreach ($tours as $t) { $o = $latest[(int)$t['id']]['overall'] ?? 'none'; $tot[$o]++; }
    $lastAt = '';
    foreach ($latest as $d) if (($d['at'] ?? '') > $lastAt) $lastAt = (string)$d['at'];

    $chip = function (string $st): string {
        return match ($st) {
            'ok' => '<span class="hc hc-ok">Sehat</span>',
            'warn' => '<span class="hc hc-warn">Perlu dicek</span>',
            'fail' => '<span class="hc hc-fail">Bermasalah</span>',
            default => '<span class="hc hc-none">Belum dicek</span>',
        };
    };
    $checkNames = ['halaman' => 'Halaman', 'aset' => 'Aset', 'jawaban' => 'Jawaban AI', 'tampilan' => 'Tampilan'];

    $body = $flash !== '' ? '<div class="card" style="border-color:var(--success)"><p class="ok">✓ ' . h($flash) . '</p></div>' : '';
    $title = $cityScope !== '' ? 'Kesehatan AI · ' . ($cities[0]['name'] ?? $cityScope) : 'Kesehatan AI';
    $body .= '<div class="card" style="margin-bottom:var(--space-4)"><a class="muted" href="?a=list&city=' . h($cityScope) . '" style="font-size:12px;text-decoration:none">← Proyek ' . h($cities[0]['name'] ?? '') . '</a><h1 style="margin:6px 0 4px">' . h($title) . '</h1>'
        . '<p class="muted" style="margin:0 0 14px;max-width:76ch">Memastikan AI Guide di setiap lokasi berjalan: halaman terbuka, aset lengkap, AI menjawab pertanyaan nyata, dan tombol AI Guide tampil. Pemeriksaan memakai <b>alamat publik kota</b> (domain live) bila diisi, jika tidak memakai localhost.</p>'
        . '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">'
        . '<span class="dv-pill">' . count($tours) . ' lokasi</span><span class="hc hc-ok">' . $tot['ok'] . ' sehat</span><span class="hc hc-warn">' . $tot['warn'] . ' perlu dicek</span><span class="hc hc-fail">' . $tot['fail'] . ' bermasalah</span><span class="hc hc-none">' . $tot['none'] . ' belum dicek</span>'
        . '<span style="flex:1"></span>'
        . '<span class="muted" style="font-size:12px">Terakhir dicek: ' . ($lastAt !== '' ? h(date('d M Y H:i', strtotime($lastAt))) : 'belum pernah') . '</span></div>'
        . '<form method="post" action="?a=healthEnqueue" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px">'
        . '<input type="hidden" name="scope" value="' . ($cityScope !== '' ? 'city' : 'all') . '"><input type="hidden" name="ref" value="' . h($cityScope) . '">'
        . '<label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="ui" value="1" style="width:auto;margin:0"> Sertakan pemeriksaan tampilan (browser, lebih lama)</label>'
        . '<button class="btn btn-primary" type="submit">' . ($cityScope !== '' ? 'Cek semua lokasi di kota ini' : 'Cek semua lokasi') . '</button></form></div>';

    foreach ($cities as $c) {
        $list = $tourByCity[$c['slug']] ?? [];
        $base = (string)($c['public_base'] ?? '');
        $body .= '<section class="card" style="margin-bottom:var(--space-4)">'
            . '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px">'
            . '<div><h2 style="margin:0">' . h((string)$c['name']) . ' <span class="muted" style="font-weight:400;font-size:13px">· ' . count($list) . ' lokasi</span></h2>'
            . '<form method="post" action="?a=healthBase" style="display:flex;gap:6px;align-items:center;margin-top:6px"><input type="hidden" name="city" value="' . h((string)$c['slug']) . '">'
            . '<input name="base" value="' . h($base) . '" placeholder="https://' . h((string)$c['slug']) . '.govirtual.id" style="min-width:300px;font-size:12px" aria-label="Alamat publik ' . h((string)$c['name']) . '"><button class="btn btn-ghost btn-sm" type="submit">Simpan alamat</button></form></div>'
            . ($list ? '<form method="post" action="?a=healthEnqueue" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><input type="hidden" name="scope" value="city"><input type="hidden" name="ref" value="' . h((string)$c['slug']) . '">'
                . '<label style="display:flex;gap:6px;align-items:center;margin:0;font-size:12px"><input type="checkbox" name="ui" value="1" style="width:auto;margin:0"> tampilan</label><button class="btn btn-ghost btn-sm" type="submit">Cek kota ini</button></form>' : '')
            . '</div>';
        if (!$list) { $body .= '<p class="muted" style="margin:0">Belum ada lokasi di kota ini.</p></section>'; continue; }
        $body .= '<div style="overflow-x:auto"><table class="hc-table"><thead><tr><th>Lokasi</th><th>Status</th>';
        foreach ($checkNames as $k => $label) $body .= '<th>' . h($label) . '</th>';
        $body .= '<th>Alamat yang dicek</th><th></th></tr></thead><tbody>';
        foreach ($list as $t) {
            $id = (int)$t['id']; $d = $latest[$id] ?? null;
            [$url, $target] = health_base_url($t);
            $body .= '<tr><td><a href="?a=view&id=' . $id . '"><b>' . h((string)$t['name']) . '</b></a></td><td>' . $chip((string)($d['overall'] ?? 'none')) . '</td>';
            foreach ($checkNames as $k => $label) {
                $ck = $d['checks'][$k] ?? null;
                $body .= '<td>' . ($ck ? '<span class="hc-dot hc-' . h((string)$ck['status']) . '" title="' . h((string)$ck['detail']) . '"></span>' : '<span class="muted">—</span>') . '</td>';
            }
            $body .= '<td class="muted" style="font-size:12px;word-break:break-all">' . h($url) . ' <span class="dv-pill" style="font-size:10px">' . h($target === 'live' ? 'live' : 'localhost') . '</span></td>'
                . '<td><form method="post" action="?a=healthEnqueue" style="margin:0"><input type="hidden" name="scope" value="tour"><input type="hidden" name="ref" value="' . $id . '"><button class="btn btn-ghost btn-sm" type="submit">Cek</button></form></td></tr>';
            // detail hasil yang bermasalah
            foreach ((array)($d['checks'] ?? []) as $name => $ck) {
                if ($ck['status'] === 'ok') continue;
                $body .= '<tr class="hc-detail"><td colspan="' . (count($checkNames) + 4) . '"><span class="hc-dot hc-' . h((string)$ck['status']) . '"></span> <b>' . h($checkNames[$name] ?? $name) . ':</b> ' . h((string)$ck['detail']) . '</td></tr>';
            }
        }
        $body .= '</tbody></table></div></section>';
    }

    $body .= '<style>'
        . '.hc{font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:999px;border:1px solid var(--border)}'
        . '.hc-ok{color:var(--success);border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.08)}'
        . '.hc-warn{color:var(--warning);border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08)}'
        . '.hc-fail{color:var(--danger);border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.08)}'
        . '.hc-none{color:var(--text-muted)}'
        . '.hc-dot{display:inline-block;width:10px;height:10px;border-radius:999px;background:var(--surface-3)}'
        . '.hc-dot.hc-ok{background:var(--success)}.hc-dot.hc-warn{background:var(--warning)}.hc-dot.hc-fail{background:var(--danger)}'
        . '.hc-table{width:100%;border-collapse:collapse;font-size:13px}.hc-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);padding:8px}'
        . '.hc-table td{padding:9px 8px;border-top:1px solid var(--border);vertical-align:middle}'
        . '.hc-table tr.hc-detail td{background:var(--surface-2);font-size:12px;color:var(--text-secondary);padding:6px 8px 8px 30px;border-top:0}'
        . '</style>';
    layout('Kesehatan AI', $body);
}

/** Simpan alamat publik kota (domain live). */
function health_save_base(string $slug, string $base): void
{
    $base = rtrim(trim($base), '/');
    if ($base !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/[a-z0-9._~/-]*)?$#i', $base)) throw new RuntimeException('Alamat harus dimulai dengan http:// atau https:// (contoh: https://semarang.govirtual.id).');
    $c = city_row_by_slug($slug);
    if (!$c) throw new RuntimeException('Kota tidak ditemukan.');
    db()->prepare('UPDATE cities SET public_base=?, updated_at=? WHERE id=?')->execute([$base, date('c'), (int)$c['id']]);
    audit_write('healthBase', ['city' => $slug, 'detail' => $base !== '' ? $base : '(localhost)']);
}
