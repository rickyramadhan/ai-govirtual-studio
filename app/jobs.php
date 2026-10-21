<?php

declare(strict_types=1);

/**
 * Antrian pekerjaan massal (Generate / Deploy / Resync) untuk banyak tour sekaligus.
 * Request web hanya memasukkan pekerjaan ke antrian lalu menjalankan worker CLI di latar belakang;
 * halaman memantau progres lewat polling. Worker memproses berurutan dan memakai kunci file agar tidak ganda.
 */

const JOB_TYPES = [
    'generate' => 'Generate package',
    'deploy' => 'Generate + Deploy',
    'resync' => 'Import ulang + Generate + Deploy',
];

function jobs_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch TEXT NOT NULL,
        type TEXT NOT NULL,
        tour_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT "pending",
        message TEXT NOT NULL DEFAULT "",
        created_by TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL,
        started_at TEXT,
        finished_at TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_batch ON jobs(batch)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status)');
}

/** Masukkan satu pekerjaan per tour. Mengembalikan id batch. */
function job_enqueue(string $type, array $tourIds, string $by = ''): string
{
    if (!isset(JOB_TYPES[$type])) throw new RuntimeException('Jenis pekerjaan tidak dikenal.');
    $tourIds = array_values(array_unique(array_map('intval', $tourIds)));
    if (!$tourIds) throw new RuntimeException('Pilih minimal satu tour.');
    $batch = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $ins = db()->prepare('INSERT INTO jobs(batch,type,tour_id,status,created_by,created_at) VALUES(?,?,?,?,?,?)');
    foreach ($tourIds as $id) if (get_tour($id)) $ins->execute([$batch, $type, $id, 'pending', $by, date('c')]);
    return $batch;
}

/** Jalankan satu pekerjaan; melempar exception bila gagal. Mengembalikan pesan ringkas. */
function job_execute(array $job): string
{
    $tour = get_tour((int)$job['tour_id']);
    if (!$tour) throw new RuntimeException('Tour sudah tidak ada.');
    switch ($job['type']) {
        case 'generate':
            $r = build_files($tour);
            return 'package dibuat (' . count($r['files']) . ' berkas)';
        case 'deploy':
            build_files($tour);
            deploy_package($tour);
            return 'package dibuat & terpasang';
        case 'resync':
            $p = tour_import_pano($tour);
            build_files($tour);
            deploy_package($tour);
            return 'import ' . count($p['scenes'] ?? []) . ' scene, package dibuat & terpasang';
    }
    throw new RuntimeException('Jenis pekerjaan tidak dikenal.');
}

/** Proses semua pekerjaan pending (satu worker pada satu waktu). Mengembalikan jumlah yang diproses. */
function job_worker_run(int $maxSeconds = 3300): int
{
    $lock = fopen(APP_ROOT . '/storage/jobs.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0; // worker lain sedang berjalan
    $t0 = time(); $n = 0;
    // pekerjaan yang tertinggal "running" dari worker yang mati dianggap gagal
    db()->exec("UPDATE jobs SET status='failed', message='Dihentikan sebelum selesai (worker berhenti)', finished_at='" . date('c') . "' WHERE status='running'");
    while (time() - $t0 < $maxSeconds) {
        $job = db()->query("SELECT * FROM jobs WHERE status='pending' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$job) break;
        db()->prepare("UPDATE jobs SET status='running', started_at=? WHERE id=?")->execute([date('c'), $job['id']]);
        try { $msg = job_execute($job); $status = 'done'; }
        catch (Throwable $e) { $msg = $e->getMessage(); $status = 'failed'; }
        db()->prepare('UPDATE jobs SET status=?, message=?, finished_at=? WHERE id=?')->execute([$status, mb_substr($msg, 0, 500), date('c'), $job['id']]);
        $n++;
    }
    flock($lock, LOCK_UN); fclose($lock);
    return $n;
}

/** Cari php.exe CLI (di bawah Apache, PHP_BINARY adalah httpd.exe). */
function php_cli_path(): string
{
    $cands = [trim(setting_get('php_cli', '')), PHP_BINARY, 'C:/xampp/php/php.exe', PHP_BINDIR . '/php.exe', PHP_BINDIR . '/php'];
    foreach ($cands as $c) if ($c !== '' && is_file($c) && preg_match('/php(\.exe)?$/i', $c)) return str_replace('\\', '/', $c);
    return 'php';
}

/** Jalankan worker di latar belakang (tidak menunggu). */
function job_spawn_worker(): void
{
    $cli = php_cli_path(); $script = APP_ROOT . '/tools/worker.php';
    if (PHP_OS_FAMILY === 'Windows') pclose(popen('start /B "" "' . $cli . '" "' . $script . '" > NUL 2>&1', 'r'));
    else exec(escapeshellarg($cli) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
}

function job_batch_summary(string $batch): array
{
    $rows = db()->prepare('SELECT j.*, t.name AS tour_name FROM jobs j LEFT JOIN tours t ON t.id=j.tour_id WHERE j.batch=? ORDER BY j.id');
    $rows->execute([$batch]);
    $jobs = $rows->fetchAll(PDO::FETCH_ASSOC);
    $c = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];
    foreach ($jobs as $j) $c[$j['status']] = ($c[$j['status']] ?? 0) + 1;
    return ['batch' => $batch, 'jobs' => $jobs, 'counts' => $c, 'total' => count($jobs), 'finished' => $c['pending'] + $c['running'] === 0 && count($jobs) > 0];
}

function render_jobs_page(string $batch = ''): void
{
    $tours = all_tours(); $cities = all_cities();
    $recent = db()->query('SELECT batch, type, MIN(created_at) AS at, COUNT(*) AS n, SUM(status="done") AS ok, SUM(status="failed") AS bad, SUM(status IN ("pending","running")) AS active FROM jobs GROUP BY batch ORDER BY MIN(id) DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);

    $cityOpts = '<option value="">Semua kota</option>';
    foreach ($cities as $c) if ($c['tour_count'] > 0) $cityOpts .= '<option value="' . h($c['slug']) . '">' . h($c['name']) . ' (' . $c['tour_count'] . ')</option>';
    $typeOpts = ''; foreach (JOB_TYPES as $k => $label) $typeOpts .= '<option value="' . $k . '">' . h($label) . '</option>';
    $rows = '';
    foreach ($tours as $t) {
        $rows .= '<tr data-city="' . h(tour_city_slug($t)) . '" data-name="' . h(strtolower((string)$t['name'])) . '" style="border-top:1px solid var(--border)"><td style="padding:5px"><input type="checkbox" name="tour[]" value="' . (int)$t['id'] . '" class="jb-pick"></td><td>' . h((string)$t['name']) . '</td><td class="muted" style="font-size:12px">' . h((string)$t['location']) . '</td><td class="muted" style="font-size:12px">' . (trim((string)$t['export_path']) === '' ? 'belum ada export' : '') . '</td></tr>';
    }

    $body = '';
    if ($batch !== '') {
        $body .= '<div class="card" id="jbBatch" data-batch="' . h($batch) . '"><h3 style="margin-top:0">Progres batch <code>' . h($batch) . '</code></h3><div class="progress-wrap" style="margin:6px 0"><div class="progress-bar" id="jbBar" style="transform:scaleX(0);transform-origin:left;height:8px;background:var(--primary);border-radius:4px;transition:transform .3s"></div></div><p class="muted" id="jbText">Menunggu worker…</p><table style="width:100%;border-collapse:collapse;font-size:13px"><tbody id="jbRows"></tbody></table></div>';
    }
    $body .= '<div class="card"><h2 style="margin-top:0">Antrian Publikasi</h2><p class="muted">Jalankan Generate/Deploy untuk banyak tour sekaligus. Pekerjaan berjalan di latar belakang, jadi halaman ini boleh ditutup; progres bisa dilihat lagi dari riwayat di bawah.</p>'
        . '<form method="post" action="?a=jobEnqueue" id="jbForm"><div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:10px">'
        . '<div><label for="jbType" style="font-size:12px">Aksi</label><select name="type" id="jbType">' . $typeOpts . '</select></div>'
        . '<div><label for="jbCity" style="font-size:12px">Saring kota</label><select id="jbCity">' . $cityOpts . '</select></div>'
        . '<div><label for="jbQ" style="font-size:12px">Cari</label><input type="search" id="jbQ" placeholder="nama tour…"></div>'
        . '<label style="display:flex;gap:6px;align-items:center;margin:0 0 8px"><input type="checkbox" id="jbAll"> Pilih semua yang tampil</label>'
        . '<span class="muted" id="jbCount" style="margin:0 0 8px"></span></div>'
        . '<div style="max-height:46vh;overflow:auto"><table style="width:100%;border-collapse:collapse;font-size:13px"><tbody>' . $rows . '</tbody></table></div>'
        . '<p style="margin:12px 0 0"><button class="btn btn-primary" type="submit" id="jbGo">Masukkan ke antrian</button></p></form></div>';
    if ($recent) {
        $body .= '<div class="card"><h3 style="margin-top:0">Batch terakhir</h3><table style="width:100%;border-collapse:collapse;font-size:13px"><tbody>';
        foreach ($recent as $r) $body .= '<tr style="border-top:1px solid var(--border)"><td style="padding:6px"><a href="?a=jobs&batch=' . h($r['batch']) . '"><code>' . h($r['batch']) . '</code></a></td><td>' . h(JOB_TYPES[$r['type']] ?? $r['type']) . '</td><td>' . (int)$r['n'] . ' tour</td><td>' . ((int)$r['active'] > 0 ? '<span class="warn">berjalan</span>' : ((int)$r['bad'] > 0 ? '<span class="bad">' . (int)$r['bad'] . ' gagal</span>' : '<span class="ok">selesai</span>')) . '</td></tr>';
        $body .= '</tbody></table></div>';
    }
    $body .= '<script>(function(){'
        . 'var city=document.getElementById("jbCity"),q=document.getElementById("jbQ"),all=document.getElementById("jbAll"),cnt=document.getElementById("jbCount"),form=document.getElementById("jbForm"),type=document.getElementById("jbType");'
        . 'var rows=[].slice.call(document.querySelectorAll("tr[data-city]"));'
        . 'function vis(){var c=city.value,s=q.value.trim().toLowerCase();rows.forEach(function(r){var ok=(!c||r.dataset.city===c)&&(!s||r.dataset.name.indexOf(s)>-1);r.style.display=ok?"":"none";});upd();}'
        . 'function upd(){var n=rows.filter(function(r){return r.style.display!=="none"&&r.querySelector(".jb-pick").checked;}).length;cnt.textContent=n+" tour dipilih";}'
        . 'city.addEventListener("change",vis);q.addEventListener("input",vis);'
        . 'all.addEventListener("change",function(){rows.forEach(function(r){if(r.style.display!=="none")r.querySelector(".jb-pick").checked=all.checked;});upd();});'
        . 'form.addEventListener("change",upd);upd();'
        . 'form.addEventListener("submit",function(e){var n=rows.filter(function(r){return r.querySelector(".jb-pick").checked;}).length;if(!n){e.preventDefault();alert("Pilih minimal satu tour.");return;}'
        . 'var t=type.value;if(t!=="generate"&&!confirm((t==="resync"?"Import ulang pano, generate, lalu DEPLOY ":"Generate lalu DEPLOY ")+n+" tour ke lokasi publik? Package lama dicadangkan otomatis."))e.preventDefault();});'
        . 'var bx=document.getElementById("jbBatch");if(bx){var bar=document.getElementById("jbBar"),tx=document.getElementById("jbText"),tb=document.getElementById("jbRows"),lab={pending:"menunggu",running:"berjalan",done:"selesai",failed:"gagal"};'
        . 'function esc(s){var d=document.createElement("div");d.textContent=s==null?"":String(s);return d.innerHTML;}'
        . 'function poll(){fetch("?a=jobsStatus&batch="+encodeURIComponent(bx.dataset.batch),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){'
        . 'var done=d.counts.done+d.counts.failed;bar.style.transform="scaleX("+(d.total?done/d.total:0)+")";tx.textContent=done+" / "+d.total+" selesai"+(d.counts.failed?" · "+d.counts.failed+" gagal":"")+(d.finished?" — selesai":"");'
        . 'tb.innerHTML=d.jobs.map(function(j){var c=j.status==="done"?"ok":(j.status==="failed"?"bad":(j.status==="running"?"warn":"muted"));return "<tr style=\"border-top:1px solid var(--border)\"><td style=\"padding:5px\">"+esc(j.tour_name)+"</td><td class=\""+c+"\">"+lab[j.status]+"</td><td class=\"muted\" style=\"font-size:12px\">"+esc(j.message)+"</td></tr>";}).join("");'
        . 'if(!d.finished)setTimeout(poll,1500);}).catch(function(){setTimeout(poll,3000);});}poll();}'
        . '})();</script>';
    layout('Antrian Publikasi', $body);
}
