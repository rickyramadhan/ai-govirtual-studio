<?php

declare(strict_types=1);

/**
 * Scene Editor (?a=scenes), versi yang lebih mudah dipahami:
 * - ringkasan kemajuan dan filter "perlu diisi" di atas,
 * - setiap scene: ringkasan status dulu, form dibuka saat diperlukan,
 * - form dibagi per bagian (Nama, Deskripsi, Pertanyaan saran),
 * - tombol simpan dijelaskan (simpan saja vs. paket vs. publikasi).
 * Nama field dan fungsi JS dipertahankan agar saveScenes, genDesc, dan testAI tetap bekerja.
 */

function scene_state(string $sid, array $parsedScene, array $si, array $objTitles): array
{
    $sc = $si['scenes'][$sid] ?? null;
    $display = (string)($parsedScene['displayName'] ?? $sid);
    $place = $display;
    if ($sc) { $display = (string)($sc['displayName'] ?? $display); $place = (string)($sc['placeName'] ?? $display); }
    $needsName = $display === $sid || str_starts_with($display, 'node') || trim($display) === '';
    $desc = trim((string)($sc['shortDescription'] ?? '')) . trim((string)($sc['description'] ?? ''));
    $hasDesc = $desc !== '';
    $rk = (array)($sc['relatedKnowledgeIds'] ?? []);
    return ['sc' => $sc, 'display' => $display, 'place' => $place, 'needsName' => $needsName, 'hasDesc' => $hasDesc, 'objects' => count($rk), 'objNames' => array_map(fn($r) => $objTitles[(string)$r] ?? (string)$r, $rk)];
}

function render_scene_page(array $tour, array $parsed, array $si, array $knowledge, string $msg = ''): void
{
    $tid = (int)$tour['id'];
    $objTitles = [];
    foreach ((array)($knowledge['entries'] ?? []) as $e) $objTitles[(string)($e['id'] ?? '')] = (string)($e['title'] ?? $e['id'] ?? '');
    $scenes = (array)($parsed['scenes'] ?? []);
    $total = count($scenes);
    $states = [];
    $cNeed = 0; $cDesc = 0; $cObj = 0; $cDone = 0;
    foreach ($scenes as $sc0) {
        $sid = (string)$sc0['id'];
        $st = scene_state($sid, $sc0, $si, $objTitles);
        $states[$sid] = $st;
        if ($st['needsName']) $cNeed++;
        if (!$st['hasDesc']) $cDesc++;
        if ($st['objects'] > 0) $cObj++;
        if (!$st['needsName'] && $st['hasDesc']) $cDone++;
    }
    $pct = $total > 0 ? (int)round($cDone / $total * 100) : 0;
    $webUrl = export_web_path((string)$tour['export_path']);

    $html = $msg;
    // ---- Header: jelaskan apa itu scene, dan kemajuannya ----
    $html .= '<div class="card" style="margin-bottom:var(--space-4)">'
        . '<a class="muted" href="?a=view&id=' . $tid . '" style="font-size:12px;text-decoration:none">← ' . h((string)$tour['name']) . '</a>'
        . '<h1 style="margin:6px 0 4px">Scene · ' . h((string)$tour['name']) . '</h1>'
        . '<p class="muted" style="margin:0 0 14px;max-width:76ch">Setiap <b>scene</b> adalah satu titik panorama. Isi <b>nama</b> dan <b>deskripsi</b> agar AI guide menyebut tempat itu dengan benar saat pengunjung berada di sana.</p>'
        . '<div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">'
        . '<div style="flex:1;min-width:240px"><div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:6px"><span>' . h(i18n_fmt('{d} dari {t} scene siap', ['d' => $cDone, 't' => $total])) . '</span><b>' . $pct . '%</b></div>'
        . '<div style="height:8px;border-radius:999px;background:var(--surface-3);overflow:hidden"><div style="height:100%;width:' . $pct . '%;background:linear-gradient(90deg,#e2c28a,#c9a96e);border-radius:999px;transition:width .8s cubic-bezier(.2,.8,.2,1)"></div></div></div>'
        . '<div style="display:flex;gap:8px;flex-wrap:wrap"><span class="dv-pill" style="color:var(--warning)">' . $cNeed . ' belum punya nama</span><span class="dv-pill">' . $cDesc . ' belum ada deskripsi</span><span class="dv-pill">' . $cObj . ' ada objek info</span></div>'
        . '</div>'
        . '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">'
        . '<a class="btn btn-ghost btn-sm" href="?a=panoview&id=' . $tid . '&scene=' . urlencode((string)($scenes[0]['id'] ?? '')) . '" target="_blank" rel="noopener">👁 Pratinjau tur</a>'
        . ($webUrl !== '' ? '<a class="btn btn-ghost btn-sm" href="' . h($webUrl) . '/index.html" target="_blank" rel="noopener">Buka tur publik ↗</a>' : '')
        . '</div></div>';

    // ---- Bilah filter: dua baris yang jelas (filter & cari · lompat & buka/tutup) ----
    $jump = '<option value="">Lompat ke scene…</option>';
    foreach ($scenes as $i => $sc0) {
        $sid = (string)$sc0['id']; $st = $states[$sid];
        $jump .= '<option value="' . h($sid) . '">' . ($i + 1) . '. ' . h($st['needsName'] ? 'Belum bernama (' . $sid . ')' : $st['display']) . '</option>';
    }
    $html .= '<div class="card scn-bar">'
        . '<div class="scn-bar-row">'
        . '<div class="scn-tabs" role="tablist" aria-label="Filter scene">'
        . '<button type="button" class="scn-tab is-on" data-filter="all">Semua <span>' . $total . '</span></button>'
        . '<button type="button" class="scn-tab" data-filter="need">Belum bernama <span>' . $cNeed . '</span></button>'
        . '<button type="button" class="scn-tab" data-filter="nodesc">Tanpa deskripsi <span>' . $cDesc . '</span></button>'
        . '<button type="button" class="scn-tab" data-filter="obj">Ada objek info <span>' . $cObj . '</span></button>'
        . '</div>'
        . '<input type="search" id="scnSearch" class="scn-search" placeholder="Cari nama atau ID scene…" aria-label="Cari scene">'
        . '</div>'
        . '<div class="scn-bar-row scn-bar-sub">'
        . '<select class="scn-jump" onchange="jumpScene(this.value);this.value=\'\'" aria-label="Lompat ke scene">' . $jump . '</select>'
        . '<div class="scn-actions"><button type="button" class="scn-link" onclick="toggleAll(true)">Buka semua</button><span aria-hidden="true">·</span><button type="button" class="scn-link" onclick="toggleAll(false)">Tutup semua</button></div>'
        . '</div></div>';
    // ---- Impor/ekspor massal: opsional, disembunyikan secara default ----
    $html .= '<details class="card" style="margin-bottom:var(--space-3)"><summary style="cursor:pointer;font-weight:600">Impor / ekspor massal <span class="muted" style="font-weight:400;font-size:12px">(opsional — untuk mengisi banyak scene sekaligus lewat Excel)</span></summary>'
        . '<p class="muted" style="margin:10px 0 10px">Unduh template berisi data semua scene, edit di Excel atau CSV, lalu unggah kembali. Jangan ubah kolom Scene ID dan baris header pertama. Sel kosong tidak akan menimpa data.</p>'
        . '<p style="margin:0 0 12px;display:flex;gap:8px;flex-wrap:wrap"><a class="btn" href="?a=exportScenes&id=' . $tid . '&fmt=xlsx">⬇ Template Excel (dengan gambar)</a><a class="btn" href="?a=exportScenes&id=' . $tid . '&fmt=csv">⬇ Template CSV</a></p>'
        . '<form method="post" action="?a=importScenes&id=' . $tid . '" enctype="multipart/form-data">' . rev_field(tour_dir((string)$tour['slug']) . '/scene-identity.json')
        . '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><input type="file" name="scene_file" accept=".xlsx,.csv" required style="flex:1;min-width:220px"><button type="submit" class="btn btn-primary">Unggah & perbarui massal</button></div></form></details>';

    // ---- Form utama ----
    $html .= '<form method="post" action="?a=saveScenes&id=' . $tid . '" id="sceneForm">' . rev_field(tour_dir((string)$tour['slug']) . '/scene-identity.json');
    $html .= '<div id="scnList" style="display:grid;gap:12px">';
    foreach ($scenes as $sc0) {
        $sid = (string)$sc0['id']; $st = $states[$sid]; $sc = $st['sc'];
        $display = $st['display'];
        $thumb = '?a=thumb&id=' . $tid . '&scene=' . urlencode($sid);
        $state = ($st['needsName'] ? 'need ' : '') . (!$st['hasDesc'] ? 'nodesc ' : '') . ($st['objects'] > 0 ? 'obj ' : '');
        $search = strtolower($display . ' ' . $sid . ' ' . (string)($sc['placeName'] ?? ''));
        $chips = ($st['needsName'] ? '<span class="scn-chip is-warn">Belum bernama</span>' : '')
            . (!$st['hasDesc'] ? '<span class="scn-chip">Tanpa deskripsi</span>' : '')
            . ($st['objects'] > 0 ? '<span class="scn-chip is-ok">' . $st['objects'] . ' objek info</span>' : '')
            . (!$st['needsName'] && $st['hasDesc'] ? '<span class="scn-chip is-ok">Lengkap</span>' : '');

        $html .= '<details class="scene-card scn-row" id="scene-' . h($sid) . '" data-sid="' . h($sid) . '" data-state="' . h(trim($state)) . '" data-search="' . h($search) . '">'
            . '<summary class="scn-sum">'
            . '<img src="' . h($thumb) . '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'" class="scn-thumb">'
            . '<span class="scn-name"><b>' . h($st['needsName'] ? 'Scene tanpa nama' : $display) . '</b><small>' . h($sid) . '</small></span>'
            . '<span class="scn-chips">' . $chips . '</span>'
            . '<span class="scn-caret" aria-hidden="true">▾</span></summary>';

        // Isi scene (dibuka saat diklik)
        $html .= '<div class="scn-body"><div class="scn-grid">'
            // kiri: pratinjau & info teknis
            . '<aside class="scn-side">'
            . '<a href="?a=panoview&id=' . $tid . '&scene=' . urlencode($sid) . '" target="_blank" rel="noopener" aria-label="Pratinjau ' . h($display) . '"><img src="' . h($thumb) . '" alt="Thumbnail ' . h($display) . '" loading="lazy" onerror="this.style.display=\'none\'" class="scn-big"></a>'
            . '<div class="muted" style="font-size:12px;margin-top:8px;line-height:1.6">ID: <code>' . h($sid) . '</code>' . (!empty($sc['customNodeId']) ? '<br>Kode: <code>' . h((string)$sc['customNodeId']) . '</code>' : '') . '</div>'
            . ($st['objects'] > 0 ? '<div class="muted" style="font-size:12px;margin-top:8px">Objek info di sini:<br>' . implode('<br>', array_map(fn($n) => '• ' . h((string)$n), $st['objNames'])) . '</div>' : '<div class="muted" style="font-size:12px;margin-top:8px">Tidak ada objek info di scene ini.</div>')
            . '</aside>'
            // kanan: form dalam bagian-bagian
            . '<div class="scn-form">'
            // Bagian 1: nama
            . '<section class="scn-sec"><h4>1 · Nama</h4>'
            . '<label for="d' . h($sid) . '">Nama tampilan' . ($st['needsName'] ? ' <span class="scn-warn">— masih kode, ganti dengan nama yang dikenal pengunjung</span>' : '') . '</label>'
            . '<input id="d' . h($sid) . '" name="scene_' . $sid . '_displayName" value="' . h($display) . '" placeholder="' . h($sid) . '">'
            . '<label for="p' . h($sid) . '" style="margin-top:10px">Nama tempat untuk AI <span class="muted" style="font-size:12px">(dipakai dalam kalimat “Anda berada di …”)</span></label>'
            . '<input id="p' . h($sid) . '" name="scene_' . $sid . '_placeName" value="' . h($st['place']) . '">'
            . '</section>'
            // Bagian 2: deskripsi
            . '<section class="scn-sec"><h4>2 · Deskripsi</h4>'
            . '<label for="s' . h($sid) . '">Ringkasan singkat <span class="muted" style="font-size:12px">(satu kalimat untuk daftar)</span> <button type="button" class="btn btn-ghost btn-sm" onclick="genDesc(\'' . h($sid) . '\',\'shortDescription\',this)">✨ Tulis dengan AI</button></label>'
            . '<textarea id="s' . h($sid) . '" name="scene_' . $sid . '_shortDescription" rows="2">' . h((string)($sc['shortDescription'] ?? '')) . '</textarea>'
            . '<label for="f' . h($sid) . '" style="margin-top:10px">Deskripsi lengkap <span class="muted" style="font-size:12px">(yang dibaca AI guide)</span> <button type="button" class="btn btn-ghost btn-sm" onclick="genDesc(\'' . h($sid) . '\',\'description\',this)">✨ Tulis dengan AI</button></label>'
            . '<textarea id="f' . h($sid) . '" name="scene_' . $sid . '_description" rows="4">' . h((string)($sc['description'] ?? '')) . '</textarea>'
            . '<label for="h' . h($sid) . '" style="margin-top:10px">Poin penting <span class="muted" style="font-size:12px">(satu per baris, opsional)</span></label>'
            . '<textarea id="h' . h($sid) . '" name="scene_' . $sid . '_highlights" rows="2">' . h(implode("\n", (array)($sc['highlights'] ?? []))) . '</textarea>'
            . '</section>'
            // Bagian 3: pertanyaan saran
            . '<section class="scn-sec"><h4>3 · Pertanyaan saran</h4>'
            . '<p class="muted" style="font-size:12px;margin:0 0 8px;line-height:1.5">Pertanyaan yang muncul sebagai saran saat pengunjung ada di scene ini. <b>Dijawab AI</b>: AI menjawab dari deskripsi di atas. <b>Jawaban tetap</b>: jawaban persis seperti yang Anda tulis. Kosongkan yang tidak dipakai.</p>';
        $sqArr = [];
        foreach ((array)($sc['suggestedQuestions'] ?? []) as $sq) {
            if (is_string($sq)) $sqArr[] = ['q' => $sq, 'mode' => 'ai', 'answer' => ''];
            else $sqArr[] = ['q' => (string)($sq['q'] ?? ''), 'mode' => (string)($sq['mode'] ?? 'ai'), 'answer' => (string)($sq['answer'] ?? '')];
        }
        for ($k = 0; $k < 3; $k++) {
            $item = $sqArr[$k] ?? ['q' => '', 'mode' => 'ai', 'answer' => ''];
            $html .= '<div class="scn-q">'
                . '<div style="display:grid;grid-template-columns:1fr 150px;gap:8px">'
                . '<input name="scene_' . $sid . '_sq_' . $k . '_q" value="' . h($item['q']) . '" placeholder="Pertanyaan ' . ($k + 1) . ', mis. Apa yang menarik di sini?" aria-label="Pertanyaan ' . ($k + 1) . '">'
                . '<select name="scene_' . $sid . '_sq_' . $k . '_mode" aria-label="Cara menjawab">'
                . '<option value="ai"' . ($item['mode'] === 'ai' ? ' selected' : '') . '>Dijawab AI</option>'
                . '<option value="fix"' . ($item['mode'] === 'fix' ? ' selected' : '') . '>Jawaban tetap</option></select></div>'
                . '<textarea name="scene_' . $sid . '_sq_' . $k . '_answer" rows="2" placeholder="Jawaban tetap (hanya dipakai jika cara menjawabnya “Jawaban tetap”)" style="margin-top:6px">' . h($item['answer']) . '</textarea>'
                . '<div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap"><button type="button" class="btn btn-ghost btn-sm" onclick="testAI(\'' . h($sid) . '\',' . $k . ')">▶ Coba jawaban AI</button></div>'
                . '<div id="sq-test-' . h($sid) . '-' . $k . '" class="scn-test" style="display:none"></div>'
                . '</div>';
        }
        $html .= '</section></div></div></div></details>';
    }
    $html .= '</div>';

    // ---- Footer: jelaskan tiap tombol ----
    $html .= '<div class="card scn-foot">'
        . '<div class="scn-foot-help" style="flex:1;min-width:220px"><b>Simpan perubahan</b><div class="muted" style="font-size:12px">Hanya menyimpan data scene. Belum tampil ke pengunjung.</div></div>'
        . '<button type="submit" name="do" value="save" class="btn btn-primary">Simpan perubahan</button>'
        . '<button type="submit" name="do" value="save_generate" class="btn btn-ghost" title="Membangun ulang paket AI Guide dari data ini. Belum mengubah situs publik.">Simpan & buat paket</button>'
        . '<button type="submit" name="do" value="save_deploy" class="btn btn-ghost" style="color:var(--warning)" title="Memperbarui situs yang sudah live" onclick="return confirm(\'Simpan, buat paket, lalu perbarui situs publik? Perubahan langsung tampil ke pengunjung.\')">Simpan & publikasikan</button>'
        . '</div>';
    $html .= '</form>';

    $html .= '<style>'
        . '.scn-bar{padding:10px 12px;margin-bottom:var(--space-3);display:flex;flex-direction:column;gap:8px}.scn-bar-row{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.scn-bar-sub{padding-top:8px;border-top:1px solid var(--border)}.scn-search{width:280px;max-width:100%}.scn-jump{flex:1;min-width:220px;max-width:460px;margin:0}.scn-actions{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-muted)}.scn-link{border:0;background:none;color:var(--ai-accent);font-weight:600;font-size:12.5px;cursor:pointer;padding:4px 2px}.scn-link:hover{text-decoration:underline}.scn-tabs{display:flex;gap:4px;padding:4px;border-radius:999px;background:var(--surface-2);border:1px solid var(--border);flex-wrap:wrap}'
        . '.scn-tab{border:0;background:transparent;color:var(--text-secondary);padding:7px 12px;border-radius:999px;font-size:12.5px;cursor:pointer;font-weight:600}'
        . '.scn-tab span{opacity:.65;font-weight:500;margin-left:4px}'
        . '.scn-tab.is-on{background:linear-gradient(135deg,#e2c28a,#c9a96e);color:#1a1409}'
        . '.scn-sum{list-style:none;cursor:pointer;display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--surface-1);border:1px solid var(--border);border-radius:14px;transition:border-color .2s,transform .2s}'
        . '.scn-sum::-webkit-details-marker{display:none}'
        . '.scn-row:hover .scn-sum{border-color:rgba(201,169,110,.35)}'
        . '.scn-row[open] .scn-sum{border-color:rgba(201,169,110,.4);border-radius:14px 14px 0 0}'
        . '.scn-thumb{width:64px;height:40px;object-fit:cover;border-radius:8px;border:1px solid var(--border);background:var(--code-bg);flex-shrink:0}'
        . '.scn-name{flex:1;min-width:0;display:flex;flex-direction:column}.scn-name b{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.scn-name small{color:var(--text-muted);font-size:11px}'
        . '.scn-chips{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}'
        . '.scn-chip{font-size:11px;padding:3px 9px;border-radius:999px;border:1px solid var(--border);color:var(--text-secondary);white-space:nowrap}'
        . '.scn-chip.is-warn{color:var(--warning);border-color:rgba(245,158,11,.35);background:rgba(245,158,11,.08)}'
        . '.scn-chip.is-ok{color:var(--success);border-color:rgba(34,197,94,.3)}'
        . '.scn-caret{color:var(--text-muted);transition:transform .25s}.scn-row[open] .scn-caret{transform:rotate(180deg)}'
        . '.scn-body{border:1px solid var(--border);border-top:0;border-radius:0 0 14px 14px;background:var(--surface-1);padding:12px 14px;animation:shellRise .35s cubic-bezier(.2,.8,.2,1) both}'
        . '.scn-grid{display:grid;grid-template-columns:260px 1fr;gap:18px;align-items:start}'
        . '.scn-big{width:100%;aspect-ratio:16/10;object-fit:cover;border-radius:12px;border:1px solid var(--border);background:var(--code-bg)}'
        . '.scn-sec{padding:10px 0 4px;border-top:1px solid var(--border)}.scn-sec:first-child{border-top:0;padding-top:0}'
        . '.scn-sec h4{margin:0 0 10px;font-size:13px;letter-spacing:.02em;color:var(--ai-accent)}'
        . '.scn-warn{color:var(--warning);font-size:12px;font-weight:500}'
        . '.scn-q{padding:10px 0;border-top:1px dashed var(--border)}'
        . '.scn-test{margin-top:8px;background:var(--code-bg);border:1px solid var(--border);border-radius:10px;padding:10px;font-size:12.5px;white-space:pre-wrap;line-height:1.6}'
        . '.scn-foot{position:sticky;bottom:0;z-index:5;display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:var(--surface-2);margin:var(--space-3) 0 0;padding:8px 12px;border-radius:12px}.scn-foot .btn{padding:7px 14px;font-size:12.5px}.scn-foot-help{display:none}'
        . '.scene-card[hidden]{display:none}'
        . '@media(max-width:900px){.scn-grid{grid-template-columns:1fr}.scn-chips{display:none}}'
        . '</style>';

    $html .= '<script>' . str_replace('__TID__', (string)$tid, scene_page_script($tid)) . '</script>';
    layout('Scene · ' . $tour['name'], $html);
}

function scene_page_script(int $tid): string
{
    return <<<'JS'
function testAI(sid,k){
  var qEl=document.querySelector('[name="scene_'+sid+'_sq_'+k+'_q"]');
  var mEl=document.querySelector('[name="scene_'+sid+'_sq_'+k+'_mode"]');
  var box=document.getElementById('sq-test-'+sid+'-'+k);
  var q=qEl?qEl.value.trim():'';
  var mode=mEl?mEl.value:'ai';
  if(!box){ alert('Kotak hasil tidak ditemukan.'); return; }
  box.style.display='block';
  if(mode==='fix'){ box.textContent='Cara menjawab: Jawaban tetap. Jawaban yang ditampilkan adalah yang Anda tulis di kolom jawaban (AI tidak dipanggil).'; return; }
  if(!q){ box.textContent='Pertanyaan masih kosong. Isi dulu kolom pertanyaannya.'; return; }
  box.textContent='Memanggil AI…';
  fetch('?a=testAnswer&id=__TID__&scene='+encodeURIComponent(sid)+'&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(d){
    if(d && d.ok) box.textContent=(d.reply||'(jawaban kosong)');
    else box.textContent='Gagal: '+(d&&d.error?d.error:'tidak diketahui');
  }).catch(function(e){ box.textContent='Gagal menghubungi server: '+e; });
}
function jumpScene(sid){ if(!sid) return; var d=document.getElementById('scene-'+sid); if(d){ d.open=true; d.scrollIntoView({behavior:'smooth',block:'start'}); } }
function toggleAll(open){ document.querySelectorAll('.scene-card').forEach(function(d){ d.open=open; }); }
function genDesc(sid,field,btn){
  var ta=document.querySelector('[name="scene_'+sid+'_'+field+'"]');
  if(!ta){ alert('Kolom tidak ditemukan.'); return; }
  var prev=btn.textContent; btn.disabled=true; btn.textContent='Menulis…';
  fetch('?a=genDesc&id=__TID__&scene='+encodeURIComponent(sid)+'&field='+field,{credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(d){
    btn.disabled=false; btn.textContent=prev;
    if(d && d.ok){ ta.value=(d.reply||'').trim(); }
    else alert('Gagal: '+(d&&d.error?d.error:'tidak diketahui'));
  }).catch(function(e){ btn.disabled=false; btn.textContent=prev; alert('Gagal: '+e); });
}
(function(){
  var tabs=document.querySelectorAll('.scn-tab'), rows=[].slice.call(document.querySelectorAll('.scn-row')), q=document.getElementById('scnSearch'), f='all';
  function apply(){
    var s=(q.value||'').trim().toLowerCase();
    rows.forEach(function(r){
      var st=(' '+(r.getAttribute('data-state')||'')+' ');
      var okF = f==='all' || (f==='need'&&st.indexOf(' need ')>-1) || (f==='nodesc'&&st.indexOf(' nodesc ')>-1) || (f==='obj'&&st.indexOf(' obj ')>-1);
      var okQ = !s || (r.getAttribute('data-search')||'').indexOf(s)>-1;
      r.hidden = !(okF && okQ);
    });
  }
  tabs.forEach(function(t){ t.addEventListener('click',function(){ tabs.forEach(function(x){x.classList.remove('is-on');}); t.classList.add('is-on'); f=t.getAttribute('data-filter'); apply(); }); });
  q.addEventListener('input',apply);
  // Buka otomatis scene pertama yang perlu diisi (tidak membuka semuanya).
  var first=rows.filter(function(r){ return (r.getAttribute('data-state')||'').indexOf('need')>-1; })[0];
  if(first && location.hash==='') { /* tetap tertutup agar halaman ringkas; ringkasan sudah menunjukkan jumlahnya */ }
})();
JS;
}
