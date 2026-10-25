<?php

declare(strict_types=1);

/**
 * Sumber data pengetahuan kota. Tiga jenis sumber: Website (URL), File (PDF/Word/TXT/…), dan Teks tempel.
 * Setiap sumber dipecah menjadi topik (knowledge entries). Topik menyimpan nama sumbernya, sehingga
 * menghapus atau mengambil ulang sumber bisa menemukan topik yang dibuat darinya.
 */

/** Nama sumber yang dipakai sebagai kunci di topik (URL, nama file asli, atau judul teks). */
function city_source_key(array $s): string
{
    return match ((string)($s['type'] ?? '')) {
        'url' => (string)($s['url'] ?? ''),
        'file' => (string)($s['original'] ?? $s['file'] ?? ''),
        default => (string)($s['title'] ?? ''),
    };
}

/** Jumlah topik di knowledge kota yang berasal dari sumber ini. */
function city_source_topic_count(array $kb, string $key): int
{
    $n = 0;
    foreach ((array)($kb['entries'] ?? []) as $e) {
        if (is_array($e) && (string)($e['source'] ?? '') === $key && (string)($e['provenance'] ?? '') === 'ingested') $n++;
    }
    return $n;
}

/** Hapus semua topik yang berasal dari sumber ini. Mengembalikan jumlah yang dihapus. */
function city_remove_source_topics(string $slug, string $key): int
{
    $kb = city_knowledge($slug);
    $before = count($kb['entries'] ?? []);
    $kb['entries'] = array_values(array_filter((array)($kb['entries'] ?? []), function ($e) use ($key) {
        return !(is_array($e) && (string)($e['source'] ?? '') === $key && (string)($e['provenance'] ?? '') === 'ingested');
    }));
    $removed = $before - count($kb['entries']);
    if ($removed > 0) city_save_knowledge($slug, $kb);
    return $removed;
}

/** Tambah sumber teks tempel. Mengembalikan jumlah topik yang dibuat. */
function city_source_add_text(string $slug, string $title, string $text): int
{
    $title = trim($title) !== '' ? trim($title) : 'Teks tempel ' . date('d M Y H:i');
    if (trim($text) === '') throw new RuntimeException('Teks kosong.');
    $added = city_ingest_text($slug, $text, $title, 'text');
    $src = city_sources($slug);
    $src['sources'][] = ['type' => 'text', 'title' => $title, 'added' => $added, 'at' => date('c')];
    city_save_sources($slug, $src);
    return $added;
}

/** Ambil ulang sumber website: hapus topik lama dari URL itu, lalu ambil dan pecah lagi. */
function city_source_refresh_url(string $slug, int $idx): array
{
    $src = city_sources($slug);
    $s = $src['sources'][$idx] ?? null;
    if (!$s || ($s['type'] ?? '') !== 'url') throw new RuntimeException('Sumber ini tidak bisa diambil ulang.');
    $res = city_fetch_url((string)$s['url']);
    if (!$res['ok']) throw new RuntimeException($res['error']);
    city_remove_source_topics($slug, (string)$s['url']);
    $added = city_ingest_text($slug, $res['text'], (string)$s['url'], 'url');
    $src = city_sources($slug);
    $src['sources'][$idx]['added'] = $added;
    $src['sources'][$idx]['at'] = date('c');
    city_save_sources($slug, $src);
    return ['added' => $added];
}

/** Hapus sumber beserta topik dan berkas mentahnya. Mengembalikan jumlah topik yang ikut dihapus. */
function city_source_delete(string $slug, int $idx): int
{
    $src = city_sources($slug);
    if (!isset($src['sources'][$idx])) throw new RuntimeException('Sumber tidak ditemukan.');
    $s = $src['sources'][$idx];
    $key = city_source_key($s);
    $removedTopics = city_remove_source_topics($slug, $key);
    unset($src['sources'][$idx]);
    $src['sources'] = array_values($src['sources']);
    city_save_sources($slug, $src);
    if (!empty($s['file']) && is_file(city_raw_dir($slug) . '/' . $s['file'])) @unlink(city_raw_dir($slug) . '/' . $s['file']);
    return $removedTopics;
}

function city_source_type_label(string $type): array
{
    return match ($type) {
        'url' => ['Website', 'var(--info)', '🌐'],
        'file' => ['File', 'var(--success)', '📄'],
        'text' => ['Teks tempel', 'var(--ai-accent)', '✏️'],
        default => [ucfirst($type), 'var(--text-secondary)', '•'],
    };
}

function render_city_sources(string $slug, string $msg = ''): void
{
    $cityRow = city_row_by_slug($slug);
    $name = (string)(read_json(city_dir($slug) . '/meta.json', [])['name'] ?? ($cityRow['name'] ?? ucwords(str_replace('-', ' ', $slug))));
    $src = city_sources($slug);
    $kb = city_knowledge($slug);
    $sources = array_values((array)($src['sources'] ?? []));
    $totalTopics = 0;
    foreach ($sources as $s) $totalTopics += city_source_topic_count($kb, city_source_key($s));

    $html = $msg;
    // ---- Header: jelaskan konsep sumber ----
    $html .= '<div class="card" style="margin-bottom:var(--space-4)">'
        . '<a class="muted" href="?a=city&city=' . h($slug) . '" style="font-size:12px;text-decoration:none">← Pengetahuan ' . h($name) . '</a>'
        . '<h1 style="margin:6px 0 4px">Sumber data · ' . h($name) . '</h1>'
        . '<p class="muted" style="margin:0 0 12px;max-width:76ch">Sumber adalah <b>bahan mentah</b>: halaman website, file, atau teks. Sistem memecahnya menjadi <b>topik</b> di halaman Pengetahuan kota. Anda bisa menambah sumber sebanyak apa pun, lalu meninjau atau menghapus topiknya.</p>'
        . '<div style="display:flex;gap:8px;flex-wrap:wrap"><span class="dv-pill">' . count($sources) . ' sumber</span><span class="dv-pill">' . $totalTopics . ' topik dari sumber</span></div>'
        . '</div>';

    // ---- Tambah sumber: tiga jenis, masing-masing dengan penjelasan singkat ----
    $html .= '<h2 style="margin:0 0 10px">Tambah sumber</h2>'
        . '<div class="src-grid">'
        // Website
        . '<form class="card src-card" method="post" action="?a=citySourceUrl">'
        . '<input type="hidden" name="city" value="' . h($slug) . '">'
        . '<div class="src-ic">🌐</div><h3>Website</h3>'
        . '<p class="muted src-help">Tempel alamat halaman. Sistem mengambil teksnya. Halaman yang dilindungi anti-bot tidak bisa diambil; gunakan File atau Teks.</p>'
        . '<label for="srcUrl">Alamat halaman</label><input id="srcUrl" name="url" placeholder="https://contoh.id/halaman" required>'
        . '<button class="btn btn-primary" type="submit">Ambil dari website</button></form>'
        // File
        . '<form class="card src-card" method="post" action="?a=citySourceFile" enctype="multipart/form-data">'
        . '<input type="hidden" name="city" value="' . h($slug) . '">'
        . '<div class="src-ic">📄</div><h3>File</h3>'
        . '<p class="muted src-help">PDF, Word (.docx), TXT, Markdown, CSV, atau HTML. Teks yang bisa dibaca akan diambil.</p>'
        . '<label for="srcFile">Pilih file</label><input id="srcFile" type="file" name="file" required accept=".txt,.md,.csv,.html,.htm,.docx,.doc,.pdf">'
        . '<button class="btn btn-primary" type="submit">Unggah & proses</button></form>'
        // Teks
        . '<form class="card src-card" method="post" action="?a=citySourceText">'
        . '<input type="hidden" name="city" value="' . h($slug) . '">'
        . '<div class="src-ic">✏️</div><h3>Teks tempel</h3>'
        . '<p class="muted src-help">Tempel teks langsung, misalnya dari brosur, catatan, atau pesan. Beri nama agar mudah dikenali.</p>'
        . '<label for="srcTitle">Nama sumber</label><input id="srcTitle" name="title" placeholder="mis. Brosur Lawang Sewu 2026">'
        . '<label for="srcText">Isi teks</label><textarea id="srcText" name="text" rows="5" required placeholder="Tempel teks di sini…"></textarea>'
        . '<button class="btn btn-primary" type="submit">Proses teks</button></form>'
        . '</div>';

    // ---- Daftar sumber yang sudah ada ----
    $html .= '<div class="card" style="margin-top:var(--space-4)"><div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap"><h2 style="margin:0">Sumber yang sudah ditambahkan <span class="muted" style="font-weight:400">(' . count($sources) . ')</span></h2>'
        . '<a class="btn btn-ghost btn-sm" href="?a=city&city=' . h($slug) . '">Lihat topik hasilnya →</a></div>';
    if (!$sources) {
        $html .= '<div class="empty" style="padding:28px 12px"><h3 style="margin:0 0 6px">Belum ada sumber</h3><p class="muted" style="margin:0">Tambahkan satu sumber dari kartu di atas untuk mulai mengisi pengetahuan kota.</p></div>';
    } else {
        $html .= '<div style="margin-top:6px">';
        foreach ($sources as $idx => $s) {
            [$label, $color, $icon] = city_source_type_label((string)($s['type'] ?? ''));
            $key = city_source_key($s);
            $topics = city_source_topic_count($kb, $key);
            $at = (string)($s['at'] ?? '');
            $atTxt = $at !== '' && strtotime($at) ? date('d M Y H:i', strtotime($at)) : '—';
            $nameHtml = ($s['type'] ?? '') === 'url'
                ? '<a href="' . h((string)$s['url']) . '" target="_blank" rel="noopener" style="word-break:break-all">' . h((string)$s['url']) . '</a>'
                : '<span style="word-break:break-all">' . h($key) . '</span>';
            $html .= '<div class="src-row">'
                . '<div style="min-width:0;flex:1"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><span class="dv-pill" style="color:' . $color . '">' . $icon . ' ' . h($label) . '</span><strong style="min-width:0">' . $nameHtml . '</strong></div>'
                . '<div class="muted" style="font-size:12px;margin-top:4px">' . $topics . ' topik · ditambahkan ' . h($atTxt) . '</div></div>'
                . '<div style="display:flex;gap:8px;flex-shrink:0">'
                . (($s['type'] ?? '') === 'url' ? '<form method="post" action="?a=citySourceRefresh"><input type="hidden" name="city" value="' . h($slug) . '"><input type="hidden" name="idx" value="' . (int)$idx . '"><button class="btn btn-ghost btn-sm" type="submit" title="Ambil ulang halaman dan perbarui topiknya">Ambil ulang</button></form>' : '')
                . '<form method="post" action="?a=citySourceDelete" onsubmit="return confirm(\'Hapus sumber ini beserta ' . $topics . ' topik yang dibuat darinya?\')"><input type="hidden" name="city" value="' . h($slug) . '"><input type="hidden" name="idx" value="' . (int)$idx . '"><button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)">Hapus</button></form>'
                . '</div></div>';
        }
        $html .= '</div>';
    }
    $html .= '<p class="muted" style="font-size:12px;margin:12px 0 0">Setiap perubahan langsung dikirim ke semua tour di kota ini.</p></div>';

    $html .= '<style>'
        . '.src-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}'
        . '.src-card{display:flex;flex-direction:column;gap:8px;margin:0;transition:transform .25s cubic-bezier(.2,.8,.2,1),border-color .25s}'
        . '.src-card:hover{transform:translateY(-3px);border-color:rgba(201,169,110,.35)}'
        . '.src-ic{font-size:22px;width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:rgba(201,169,110,.1);border:1px solid rgba(201,169,110,.2)}'
        . '.src-card h3{margin:0}.src-help{font-size:12.5px;margin:0 0 4px;line-height:1.5}.src-card .btn{margin-top:auto}'
        . '.src-row{display:flex;justify-content:space-between;gap:14px;align-items:center;padding:12px 0;border-top:1px solid var(--border);flex-wrap:wrap}'
        . '</style>';

    layout('Sumber data · ' . $name, $html);
}
