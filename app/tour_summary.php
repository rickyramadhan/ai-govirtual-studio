<?php

declare(strict_types=1);

/**
 * Ringkasan tour (?a=view). Dirancang agar pengguna langsung tahu: sudah sampai mana, apa langkah berikutnya,
 * dan apa yang perlu diperbaiki. Istilah teknis (package, export, runtime, JSON) dijelaskan dengan bahasa biasa.
 */

/** Label ramah untuk langkah checklist (kunci = key dari studio_tour_stats). */
function summary_step_copy(string $key): array
{
    return match ($key) {
        'import' => ['Impor panorama', 'Scene dan hotspot dibaca dari file pano.'],
        'scenes' => ['Beri nama scene', 'Setiap titik panorama punya nama yang dikenal pengunjung.'],
        'knowledge' => ['Lengkapi pengetahuan', 'Isi jawaban yang boleh diberikan AI.'],
        'questions' => ['Atur pertanyaan', 'Pertanyaan rekomendasi dan intent untuk pengunjung.'],
        'deploy' => ['Publikasikan', 'Pasang AI guide ke situs publik.'],
        default => [$key, ''],
    };
}

/** Label ramah untuk nama file konten. */
function summary_file_label(string $file): string
{
    return match ($file) {
        'knowledge' => 'Pengetahuan AI',
        'curated-answers' => 'Pertanyaan & intent',
        'curated-qa' => 'Tanya jawab terkurasi',
        'scene-identity' => 'Nama & deskripsi scene',
        'recommended-questions' => 'Pertanyaan rekomendasi',
        'visitor-tips' => 'Tips pengunjung',
        'nearby' => 'Tempat sekitar',
        'flow' => 'Alur perjalanan',
        'voice' => 'Percakapan suara',
        'parsed' => 'Data panorama',
        default => $file,
    };
}

function render_tour_summary(array $t, array $s, ?array $parsed, string $error = ''): void
{
    $tid = (int)$t['id'];
    $dir = tour_dir((string)$t['slug']);
    $hasScenes = $parsed && !empty($parsed['scenes']);
    $sceneCount = $hasScenes ? count($parsed['scenes']) : 0;
    $exportChanged = detect_export_changes($parsed ?? [], (string)$t['export_path']);
    $body = $error;

    if (isset($_GET['created'])) $body .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Tour dibuat' . ((string)$_GET['created'] !== '' ? ': ' . h((string)$_GET['created']) : '') . '</p></div>';
    if (isset($_GET['resynced'])) $body .= '<div class="card" style="border-color:var(--success)"><p class="ok">✓ Impor ulang selesai. Paket dibuat dan dipasang kembali.</p></div>';

    // ---- 1. Header: nama, lokasi, dan SATU ajakan utama ----
    $statusMap = [
        'deployed' => ['Sudah dipublikasikan', 'var(--success)'],
        'ready' => ['Siap dipublikasikan', 'var(--info)'],
        'needs' => ['Perlu dilengkapi', 'var(--warning)'],
        'draft' => ['Baru dimulai', 'var(--text-muted)'],
    ];
    [$statusLabel, $statusColor] = $statusMap[$s['status']] ?? $statusMap['draft'];
    $next = $s['nextStep'];
    if ($next) {
        [$nextTitle] = summary_step_copy((string)$next['key']);
        $primary = '<a class="btn btn-primary" href="' . h((string)$next['act']) . '">Lanjutkan: ' . h($nextTitle) . ' →</a>';
    } elseif ($s['status'] === 'deployed') {
        $primary = '<a class="btn btn-ghost" href="?a=publish&id=' . $tid . '">Lihat status publikasi</a>';
    } else {
        $primary = '<a class="btn btn-primary" href="?a=publish&id=' . $tid . '">Publikasikan →</a>';
    }

    $body .= '<div class="sum-head">'
        . '<div style="min-width:0"><div class="sum-kicker">' . h((string)$t['location']) . ($t['category'] !== '' ? ' · ' . h((string)$t['category']) : '') . '</div>'
        . '<h1 style="margin:4px 0 6px">' . h((string)$t['name']) . '</h1>'
        . '<div class="muted" style="font-size:12.5px">Folder situs: <code>' . h((string)$t['export_path'] ?: 'belum diisi') . '</code></div></div>'
        . '<div class="sum-actions"><a class="btn btn-ghost btn-sm" href="?a=edit&id=' . $tid . '">Pengaturan</a>' . $primary . '</div>'
        . '</div>';

    // ---- 2. Peringatan (hanya bila perlu) ----
    if ($exportChanged) {
        $body .= '<div class="sum-alert is-warn"><div><b>Panorama di folder situs berubah</b><div class="muted" style="font-size:12.5px">File ' . h(implode(', ', $exportChanged)) . ' berbeda dari saat terakhir diimpor. Impor ulang agar scene dan AI guide sesuai dengan panorama terbaru.</div></div>'
            . '<form method="post" action="?a=resync&id=' . $tid . '" onsubmit="return confirm(\'Impor ulang panorama, buat paket, lalu perbarui situs? Cadangan paket lama dibuat otomatis.\')"><button class="btn btn-warning btn-sm" type="submit">Impor ulang</button></form></div>';
    }

    // ---- 3. Status & langkah: kiri (progres), kanan (perlu diperbaiki) ----
    $steps = '';
    foreach ($s['steps'] as $st) {
        [$title, $desc] = summary_step_copy((string)$st['key']);
        $done = (bool)$st['done'];
        $steps .= '<li class="sum-step' . ($done ? ' is-done' : '') . '"><span class="sum-dot">' . ($done ? '✓' : '') . '</span>'
            . '<div style="flex:1;min-width:0"><b>' . h($title) . '</b><div class="muted" style="font-size:12px">' . h($desc) . '</div></div>'
            . ($done ? '' : '<a class="btn btn-ghost btn-sm" href="' . h((string)$st['act']) . '">Buka</a>') . '</li>';
    }
    $issues = '';
    foreach (array_slice((array)$s['issues'], 0, 6) as $iss) {
        $issues .= '<li class="sum-issue">' . h((string)$iss) . '</li>';
    }
    $placeholderCount = 0;
    if ($hasScenes) foreach ($parsed['scenes'] as $sc) { $nm = (string)($sc['displayName'] ?? $sc['name'] ?? $sc['id']); if ($nm === (string)$sc['id'] || preg_match('/^node\d+$/i', $nm)) $placeholderCount++; }
    if ($placeholderCount > 0) $issues = '<li class="sum-issue">' . $placeholderCount . ' scene masih memakai kode (node…), belum punya nama. <a href="?a=scenes&id=' . $tid . '">Beri nama →</a></li>' . $issues;

    $body .= '<div class="sum-grid">'
        . '<section class="card sum-panel"><div class="sum-status"><div class="sum-pct">' . (int)$s['ready'] . '%</div><div><div class="sum-state" style="color:' . $statusColor . '">● ' . h($statusLabel) . '</div><div class="muted" style="font-size:12.5px">' . (int)$s['doneSteps'] . ' dari ' . (int)$s['totalSteps'] . ' langkah selesai</div></div></div>'
        . '<div class="sum-bar"><span style="width:' . (int)$s['ready'] . '%"></span></div>'
        . '<h3 class="sum-h">Langkah</h3><ol class="sum-steps">' . $steps . '</ol></section>'
        . '<section class="card sum-panel"><h3 class="sum-h">Perlu diperbaiki</h3>'
        . ($issues !== '' ? '<ul class="sum-issues">' . $issues . '</ul>' : '<p class="muted" style="margin:0">Tidak ada yang perlu diperbaiki. ✓</p>')
        . '</section></div>';

    // ---- 4. Isi konten: ringkasan per jenis, bukan istilah teknis ----
    $cards = [
        ['Scene', $s['scenes'], $s['siScenes'] . ' punya nama & deskripsi', '?a=scenes&id=' . $tid, 'Kelola scene'],
        ['Pengetahuan AI', $s['knowledgeTotal'], $s['knowledgeDone'] . ' sudah berisi jawaban', '?a=knowledge&id=' . $tid, 'Kelola pengetahuan'],
        ['Pertanyaan & intent', $s['intents'], 'Pertanyaan yang dikenali AI', '?a=curated&id=' . $tid, 'Kelola pertanyaan'],
        ['Pertanyaan rekomendasi', $s['recTotal'], 'Saran yang tampil ke pengunjung', '?a=prompts&id=' . $tid, 'Kelola saran'],
        ['Tips pengunjung', $s['tipsTotal'], 'Informasi praktis', '?a=tips&id=' . $tid, 'Kelola tips'],
        ['Tempat sekitar', $s['nearbyTotal'], 'Lokasi terdekat', '?a=nearby&id=' . $tid, 'Kelola tempat'],
    ];
    $tiles = '';
    foreach ($cards as [$label, $count, $sub, $href, $cta]) {
        $tiles .= '<a class="sum-tile" href="' . h($href) . '"><div class="sum-tile-n">' . (int)$count . '</div><div class="sum-tile-l">' . h($label) . '</div><div class="muted" style="font-size:12px">' . h($sub) . '</div><span class="sum-tile-cta">' . h($cta) . ' →</span></a>';
    }
    $body .= '<section class="card" style="margin-top:var(--space-4)"><div class="sum-h-row"><h3 class="sum-h" style="margin:0">Isi tour</h3><span class="muted" style="font-size:12.5px">Klik untuk mengedit</span></div><div class="sum-tiles">' . $tiles . '</div></section>';

    // ---- 5. Aktivitas + lanjutan (duplikat, JSON mentah) ----
    $acts = [];
    if (is_dir($dir)) foreach (glob($dir . '/*.json') ?: [] as $f) $acts[] = ['t' => filemtime($f), 'tx' => 'Diperbarui: ' . summary_file_label(basename($f, '.json'))];
    if ($s['hasPackage']) $acts[] = ['t' => filemtime($dir . '/package/govirtual-ai') ?: 0, 'tx' => 'Paket AI guide dibuat'];
    if ($s['deployed']) $acts[] = ['t' => filemtime($t['export_path'] . '/govirtual-ai') ?: 0, 'tx' => 'Dipublikasikan ke situs'];
    usort($acts, fn($a, $b) => $b['t'] <=> $a['t']);
    $actHtml = '';
    foreach (array_slice($acts, 0, 6) as $a) $actHtml .= '<li><span>' . h($a['tx']) . '</span><span class="muted">' . ($a['t'] ? h(date('d M H:i', (int)$a['t'])) : '') . '</span></li>';

    $body .= '<div class="sum-grid" style="margin-top:var(--space-4)"><section class="card sum-panel"><h3 class="sum-h">Aktivitas terbaru</h3>'
        . ($actHtml !== '' ? '<ul class="sum-acts">' . $actHtml . '</ul>' : '<p class="muted" style="margin:0">Belum ada aktivitas.</p>')
        . '</section>'
        . '<section class="card sum-panel"><h3 class="sum-h">Tindakan lain</h3><div class="sum-other">'
        . '<a class="btn btn-ghost" href="?a=import&id=' . $tid . '">Impor ulang panorama</a>'
        . '<a class="btn btn-ghost" href="?a=generate&id=' . $tid . '">Buat paket saja (tanpa publikasi)</a>'
        . '<a class="btn btn-ghost" href="?a=view&id=' . $tid . '#saveas">Duplikat untuk lokasi lain</a>'
        . '<a class="btn btn-ghost" href="?a=test&id=' . $tid . '">Uji AI guide</a>'
        . '</div><p class="muted" style="font-size:12px;margin:12px 0 0">“Paket” adalah berkas AI guide yang siap dipasang. “Publikasi” memasang paket itu ke situs.</p></section></div>';

    // ---- 6. Lanjutan: duplikat & JSON mentah (disembunyikan secara default) ----
    $body .= '<details class="card" id="saveas" style="margin-top:var(--space-4)"><summary class="sum-adv"><b>Duplikat untuk lokasi baru</b><span class="muted" style="font-size:12px">— salin isi konten tour ini sebagai titik awal</span></summary>'
        . '<form method="post" action="?a=saveAs&id=' . $tid . '" class="sum-form" style="margin-top:12px">'
        . '<div class="sum-form-grid"><div><label for="sa1">Nama lokasi baru</label><input id="sa1" name="new_name" required placeholder="mis. Kota Lama"></div>'
        . '<div><label for="sa2">Alamat (otomatis)</label><input id="sa2" name="new_slug" placeholder="kota-lama"></div>'
        . '<div><label for="sa3">Kota</label><input id="sa3" name="new_location" value="' . h((string)$t['location']) . '"></div>'
        . '<div><label for="sa4">Folder situs baru (opsional)</label><input id="sa4" name="new_export" placeholder="C:/xampp/htdocs/…"></div></div>'
        . '<label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="copy_parsed" value="1" style="width:auto;margin:0"> Salin juga data panorama (hanya jika folder panoramanya sama)</label>'
        . '<p class="muted" style="font-size:12px;margin:10px 0">Yang disalin: pengetahuan, pertanyaan, scene, tips, dan tempat sekitar. Panorama dan gambar tidak ikut.</p>'
        . '<button class="btn btn-primary" type="submit">Buat duplikat</button></form></details>';

    $body .= '<details class="card" style="margin-top:var(--space-3)"><summary class="sum-adv"><b>Berkas konten</b><span class="muted" style="font-size:12px">— untuk admin / pengembang</span></summary><div style="margin-top:12px;display:grid;gap:8px">';
    foreach (content_files() as $name => $label) {
        $body .= '<div class="sum-file"><div><b>' . h(summary_file_label((string)$name)) . '</b><div class="muted" style="font-size:12px"><code>storage/tours/' . h((string)$t['slug']) . '/' . h((string)$name) . '.json</code></div></div>'
            . '<a class="btn btn-ghost btn-sm" href="' . h(summary_file_link((string)$name, $tid)) . '">Buka</a></div>';
        if ((current_user()['role'] ?? '') === 'admin') {
            $content = json_encode(read_json($dir . '/' . $name . '.json', []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $body .= '<details class="sum-raw"><summary>Edit JSON mentah</summary><form method="post" action="?a=saveContent&id=' . $tid . '&name=' . h((string)$name) . '">' . rev_field($dir . '/' . $name . '.json')
                . '<textarea name="content" rows="8" class="mono" style="font-size:12px">' . h($content ?: '{}') . '</textarea><p><button class="btn btn-ghost btn-sm" type="submit">Simpan JSON</button></p></form></details>';
        }
    }
    $body .= '</div></details>';

    $body .= '<style>'
        . '.sum-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:var(--space-4)}'
        . '.sum-kicker{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--ai-accent);font-weight:600}'
        . '.sum-actions{display:flex;gap:8px;flex-wrap:wrap}'
        . '.sum-alert{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;border-radius:14px;padding:14px 16px;margin-bottom:var(--space-4);border:1px solid rgba(245,158,11,.35);background:rgba(245,158,11,.07)}'
        . '.sum-grid{display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)}'
        . '.sum-panel{margin:0}.sum-h{margin:0 0 10px;font-size:14px}.sum-h-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px}'
        . '.sum-status{display:flex;align-items:center;gap:14px}.sum-pct{font-family:var(--font-display);font-size:44px;font-weight:700;letter-spacing:-.03em;line-height:1}'
        . '.sum-state{font-weight:600;font-size:14px}'
        . '.sum-bar{height:8px;border-radius:999px;background:var(--surface-3);overflow:hidden;margin:12px 0 14px}.sum-bar span{display:block;height:100%;background:linear-gradient(90deg,#e2c28a,#c9a96e);border-radius:999px;transition:width .9s cubic-bezier(.2,.8,.2,1)}'
        . '.sum-steps{list-style:none;margin:0;padding:0;display:grid;gap:6px}'
        . '.sum-step{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;border:1px solid var(--border)}'
        . '.sum-step.is-done{opacity:.7}.sum-step.is-done .sum-dot{background:var(--success);color:#052e16}'
        . '.sum-dot{width:22px;height:22px;border-radius:999px;border:1px solid var(--border-strong);display:grid;place-items:center;font-size:12px;font-weight:700;flex-shrink:0}'
        . '.sum-issues{list-style:none;margin:0;padding:0;display:grid;gap:8px}'
        . '.sum-issue{padding:10px 12px;border-radius:10px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.22);font-size:13px;line-height:1.5}'
        . '.sum-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}'
        . '.sum-tile{display:flex;flex-direction:column;gap:4px;padding:14px;border-radius:14px;border:1px solid var(--border);background:var(--surface-2);text-decoration:none;color:inherit;transition:transform .25s cubic-bezier(.2,.8,.2,1),border-color .25s}'
        . '.sum-tile:hover{transform:translateY(-3px);border-color:rgba(201,169,110,.35)}'
        . '.sum-tile-n{font-family:var(--font-display);font-size:28px;font-weight:700;letter-spacing:-.02em}'
        . '.sum-tile-l{font-weight:600;font-size:13px}'
        . '.sum-tile-cta{margin-top:auto;font-size:12px;color:var(--ai-accent);font-weight:600;padding-top:6px}'
        . '.sum-acts{list-style:none;margin:0;padding:0}.sum-acts li{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-top:1px solid var(--border);font-size:13px}'
        . '.sum-other{display:flex;gap:8px;flex-wrap:wrap}'
        . '.sum-adv{cursor:pointer;display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;list-style:none}'
        . '.sum-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px}'
        . '.sum-file{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--border)}'
        . '.sum-raw{margin-top:6px}.sum-raw summary{cursor:pointer;font-size:12px;color:var(--text-muted)}'
        . '@media(max-width:900px){.sum-grid{grid-template-columns:1fr}}'
        . '</style>';

    layout('Ringkasan · ' . $t['name'], $body);
}

/** Tautan "Buka" untuk setiap berkas konten: ke halaman editornya bila ada. */
function summary_file_link(string $name, int $tid): string
{
    return match ($name) {
        'knowledge' => '?a=knowledge&id=' . $tid,
        'curated-answers' => '?a=curated&id=' . $tid,
        'curated-qa' => '?a=curatedqa&id=' . $tid,
        'scene-identity' => '?a=scenes&id=' . $tid,
        'recommended-questions' => '?a=prompts&id=' . $tid,
        'visitor-tips' => '?a=tips&id=' . $tid,
        'nearby' => '?a=nearby&id=' . $tid,
        'flow' => '?a=flow&id=' . $tid,
        'voice' => '?a=voice&id=' . $tid,
        default => '?a=view&id=' . $tid,
    };
}
