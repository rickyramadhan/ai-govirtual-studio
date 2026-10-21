<?php

declare(strict_types=1);

/**
 * Tampilan dashboard Proyek (versi visual): hero, statistik yang bergerak, kartu dengan sorotan kursor,
 * cincin progres animatif, dan animasi masuk berurutan. Semua animasi menghormati prefers-reduced-motion.
 * Hanya menyentuh Studio; AI Guide (runtime) tidak diubah.
 */

/** Warna aksen kecil per kota (hue stabil dari slug), dibuat hangat & redup agar tetap sejalan dengan palet Studio. */
function dash_city_hue(string $slug): int
{
    return (int)(hexdec(substr(md5($slug !== '' ? $slug : 'x'), 0, 6)) % 360);
}

function dash_ring(int $pct, int $size = 52): string
{
    $pct = max(0, min(100, $pct));
    $r = ($size - 8) / 2; $c = 2 * M_PI * $r; $off = $c * (1 - $pct / 100);
    $tone = $pct >= 80 ? 'var(--success)' : ($pct >= 40 ? 'var(--ai-accent)' : 'var(--danger)');
    return '<svg class="ring" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="Kelengkapan ' . $pct . ' persen">'
        . '<circle cx="' . $size / 2 . '" cy="' . $size / 2 . '" r="' . $r . '" fill="none" stroke="var(--surface-3)" stroke-width="5"/>'
        . '<circle class="ring-fg" cx="' . $size / 2 . '" cy="' . $size / 2 . '" r="' . $r . '" fill="none" stroke="' . $tone . '" stroke-width="5" stroke-linecap="round" stroke-dasharray="' . round($c, 2) . '" stroke-dashoffset="' . round($c, 2) . '" data-target="' . round($off, 2) . '" transform="rotate(-90 ' . $size / 2 . ' ' . $size / 2 . ')"/>'
        . '<text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" class="ring-txt">' . $pct . '%</text></svg>';
}

function dashboard_style(): string
{
    return <<<'CSS'
.dv{position:relative}
.dv-hero{position:relative;overflow:hidden;border-radius:22px;padding:30px 32px;margin-bottom:var(--space-5);background:linear-gradient(135deg,#25221d 0%,#17161a 55%,#121315 100%);border:1px solid rgba(201,169,110,.18);box-shadow:0 18px 50px rgba(0,0,0,.35)}
.dv-hero::before,.dv-hero::after{content:"";position:absolute;border-radius:50%;filter:blur(60px);opacity:.5;pointer-events:none;animation:drift 14s ease-in-out infinite alternate}
.dv-hero::before{width:340px;height:340px;background:radial-gradient(circle,rgba(201,169,110,.45),transparent 70%);top:-120px;right:-80px}
.dv-hero::after{width:260px;height:260px;background:radial-gradient(circle,rgba(160,120,70,.35),transparent 70%);bottom:-110px;left:20%;animation-delay:-6s}
@keyframes drift{from{transform:translate(0,0) scale(1)}to{transform:translate(-40px,25px) scale(1.12)}}
.dv-hero>*{position:relative;z-index:1}
.dv-kicker{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--ai-accent);font-weight:600}
.dv-title{font-family:var(--font-display);font-size:44px;line-height:1.05;margin:6px 0 4px;font-weight:700;letter-spacing:-.01em}
.dv-sub{color:var(--text-secondary);font-size:14px;margin:0 0 22px;max-width:60ch}
.dv-actions{display:flex;gap:10px;flex-wrap:wrap}
.dv-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:999px;font-weight:600;font-size:13px;text-decoration:none;border:1px solid var(--border-strong);color:var(--text-primary);background:rgba(255,255,255,.03);transition:transform .25s cubic-bezier(.2,.8,.2,1),background .25s,border-color .25s,box-shadow .25s}
.dv-btn:hover{transform:translateY(-2px);border-color:var(--ai-accent);background:rgba(201,169,110,.08)}
.dv-btn-gold{background:linear-gradient(135deg,#e2c28a,#c9a96e);color:#1a1409;border-color:transparent;box-shadow:0 8px 22px rgba(201,169,110,.25)}
.dv-btn-gold:hover{background:linear-gradient(135deg,#ecd29f,#d4b27a);box-shadow:0 12px 30px rgba(201,169,110,.38)}
.dv-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:var(--space-5)}
.dv-stat{position:relative;border-radius:18px;padding:18px 18px 16px;background:var(--surface-1);border:1px solid var(--border);overflow:hidden;opacity:0;transform:translateY(12px);animation:rise .6s cubic-bezier(.2,.8,.2,1) forwards;animation-delay:calc(var(--i,0) * 90ms + 120ms)}
.dv-stat::after{content:"";position:absolute;inset:auto -20% -60% -20%;height:120%;background:radial-gradient(closest-side,var(--tint,rgba(201,169,110,.14)),transparent);pointer-events:none}
.dv-stat-num{font-family:var(--font-display);font-size:38px;font-weight:700;line-height:1;font-variant-numeric:tabular-nums;color:var(--text-primary)}
.dv-stat-lbl{font-size:12px;color:var(--text-secondary);margin-top:8px;font-weight:600;letter-spacing:.02em}
.dv-stat-sub{font-size:11.5px;color:var(--text-muted);margin-top:2px}
.dv-stat.is-warn .dv-stat-num{color:var(--warning)}
.dv-stat.is-good .dv-stat-num{color:var(--success)}
@keyframes rise{to{opacity:1;transform:translateY(0)}}
.dv-attn{border-radius:18px;padding:18px 20px;margin-bottom:var(--space-5);background:linear-gradient(180deg,rgba(245,158,11,.07),rgba(245,158,11,.02));border:1px solid rgba(245,158,11,.22);opacity:0;animation:rise .6s cubic-bezier(.2,.8,.2,1) .35s forwards}
.dv-attn h3{margin:0 0 10px;font-size:14px;display:flex;align-items:center;gap:10px;font-weight:700}
.dv-attn-n{display:inline-grid;place-items:center;min-width:24px;height:24px;border-radius:999px;background:var(--warning);color:#1a1409;font-size:12px;font-weight:800;padding:0 7px}
.dv-attn-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid rgba(245,158,11,.12);transition:transform .25s cubic-bezier(.2,.8,.2,1)}
.dv-attn-row:hover{transform:translateX(4px)}
.dv-attn-row b{font-weight:600}
.dv-attn-row small{color:var(--text-muted);font-size:12px;margin-left:8px}
.dv-bar{position:sticky;top:0;z-index:20;display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 0 14px;background:linear-gradient(180deg,var(--bg-app) 70%,transparent)}
.dv-search{flex:1;min-width:220px;max-width:340px;position:relative}
.dv-search input{width:100%;padding-left:40px;border-radius:999px;background:var(--surface-1);transition:box-shadow .3s,border-color .3s}
.dv-search input:focus{box-shadow:0 0 0 4px rgba(201,169,110,.18);border-color:var(--ai-accent)}
.dv-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none}
.dv-bar select.dv-select{width:auto;max-width:210px;flex:0 0 auto;border-radius:999px;background-color:var(--surface-1);padding:9px 36px 9px 14px;font-size:13px;border:1px solid var(--border)}
.dv-bar .dv-search{display:block;position:relative;max-width:340px;flex:1 1 220px}
.dv-bar .dv-search input[type=search]{width:100%;margin:0}
.dv-bar .dv-search>svg{display:none}
.dv-bar .dv-btn{flex:0 0 auto}
.dv-tabs{display:inline-flex;gap:4px;padding:4px;border-radius:999px;background:var(--surface-1);border:1px solid var(--border);position:relative;flex-wrap:wrap}
.dv-tab{position:relative;padding:7px 14px;border-radius:999px;font-size:12.5px;color:var(--text-secondary);text-decoration:none;transition:color .25s,background .25s;z-index:1}
.dv-tab:hover{color:var(--text-primary)}
.dv-tab.is-on{color:#1a1409;font-weight:700;background:linear-gradient(135deg,#e2c28a,#c9a96e);box-shadow:0 4px 14px rgba(201,169,110,.28);animation:pop .35s cubic-bezier(.2,.9,.3,1.3)}
@keyframes pop{0%{transform:scale(.92)}100%{transform:scale(1)}}
.dv-tab .n{opacity:.7;font-weight:500}
.dv-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.dv-card{position:relative;border-radius:20px;background:var(--surface-1);border:1px solid var(--border);overflow:hidden;opacity:0;transform:translateY(16px) scale(.98);animation:rise .65s cubic-bezier(.2,.8,.2,1) forwards;animation-delay:calc(var(--i,0) * 55ms + 200ms);transition:transform .35s cubic-bezier(.2,.8,.2,1),border-color .35s,box-shadow .35s;isolation:isolate}
.dv-card::before{content:"";position:absolute;inset:0;background:radial-gradient(260px circle at var(--mx,50%) var(--my,0%),rgba(201,169,110,.16),transparent 60%);opacity:0;transition:opacity .35s;pointer-events:none;z-index:0}
.dv-card:hover{transform:translateY(-5px);border-color:rgba(201,169,110,.35);box-shadow:0 22px 44px rgba(0,0,0,.4)}
.dv-card:hover::before{opacity:1}
.dv-cover{height:76px;position:relative;background:linear-gradient(135deg,hsl(var(--h) 26% 22%),hsl(calc(var(--h) + 30) 22% 14%));overflow:hidden}
.dv-cover::after{content:"";position:absolute;inset:0;background:repeating-linear-gradient(115deg,rgba(255,255,255,.035) 0 1px,transparent 1px 14px);}
.dv-cover-ic{position:absolute;right:16px;bottom:-14px;width:54px;height:54px;border-radius:16px;display:grid;place-items:center;background:var(--surface-1);border:1px solid var(--border-strong);box-shadow:0 10px 24px rgba(0,0,0,.35);z-index:2;color:var(--ai-accent);font-family:var(--font-display);font-size:22px;font-weight:700}
.dv-body{position:relative;z-index:1;padding:20px 20px 18px}
.dv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.dv-name{margin:0;font-size:17px;font-weight:700;line-height:1.25}
.dv-name a{color:inherit;text-decoration:none}
.dv-name a:hover{color:var(--ai-accent)}
.dv-meta{font-size:12px;color:var(--text-muted);margin-top:5px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.dv-pill{padding:2px 9px;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid var(--border)}
.dv-chips{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 16px;font-size:12px;color:var(--text-secondary)}
.dv-chips span{display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.03);border:1px solid var(--border)}
.dv-foot{display:flex;justify-content:space-between;align-items:center;gap:10px}
.dv-next{font-size:12px;color:var(--text-muted);line-height:1.35;max-width:24ch}
.dv-next b{color:var(--text-secondary);font-weight:600}
.dv-go{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:999px;background:linear-gradient(135deg,#e2c28a,#c9a96e);color:#1a1409;font-weight:700;font-size:12.5px;text-decoration:none;transition:transform .25s cubic-bezier(.2,.8,.2,1),box-shadow .25s;box-shadow:0 6px 16px rgba(201,169,110,.22)}
.dv-go:hover{transform:translateX(3px);box-shadow:0 10px 24px rgba(201,169,110,.34)}
.dv-go svg{transition:transform .3s}
.dv-go:hover svg{transform:translateX(3px)}
.dv-dot{width:8px;height:8px;border-radius:50%;display:inline-block;background:var(--text-muted)}
.dv-dot.is-needs{background:var(--warning);animation:beat 1.6s ease-in-out infinite}
.dv-dot.is-ready{background:var(--success)}
.dv-dot.is-deployed{background:var(--info)}
@keyframes beat{0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.5)}50%{box-shadow:0 0 0 7px rgba(245,158,11,0)}}
.ring{flex:none;overflow:visible}
.ring-txt{font-size:11px;font-weight:700;fill:var(--text-primary);font-variant-numeric:tabular-nums}
.ring-fg{transition:stroke-dashoffset 1.1s cubic-bezier(.2,.8,.2,1);transition-delay:calc(var(--i,0) * 55ms + 400ms)}
.dv-empty{grid-column:1/-1;text-align:center;padding:50px 20px;border:1px dashed var(--border-strong);border-radius:20px;color:var(--text-secondary)}
.dv-empty h3{color:var(--text-primary);margin:12px 0 6px;font-family:var(--font-display);font-size:24px}
.dv-pager{margin-top:24px}
@media (max-width:768px){.dv-title{font-size:32px}.dv-hero{padding:22px 20px}.dv-grid{grid-template-columns:1fr}}
@media (prefers-reduced-motion:reduce){
  .dv-stat,.dv-attn,.dv-card,.dv-hero::before,.dv-hero::after{animation:none!important;opacity:1!important;transform:none!important}
  .ring-fg{transition:none!important}
  .dv-dot.is-needs{animation:none}
}
CSS;
}

function dashboard_script(): string
{
    return <<<'JS'
(function(){
  var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // angka yang bergerak naik
  document.querySelectorAll('[data-count]').forEach(function(el){
    var to=parseFloat(el.getAttribute('data-count'))||0;
    if(reduce||to===0){el.textContent=to;return;}
    var t0=null,dur=1100+Math.min(600,to*8);
    function step(ts){if(!t0)t0=ts;var p=Math.min(1,(ts-t0)/dur),e=1-Math.pow(1-p,3);el.textContent=Math.round(to*e);if(p<1)requestAnimationFrame(step);}
    setTimeout(function(){requestAnimationFrame(step);},150);
  });
  // cincin progres: isi dari 0 ke nilai sebenarnya setelah kartu muncul
  document.querySelectorAll('.ring-fg').forEach(function(c){
    var target=c.getAttribute('data-target');
    if(reduce){c.style.strokeDashoffset=target;return;}
    requestAnimationFrame(function(){requestAnimationFrame(function(){c.style.strokeDashoffset=target;});});
  });
  // sorotan kursor: posisi relatif kartu dipakai untuk radial glow
  if(!reduce){
    document.querySelectorAll('.dv-card').forEach(function(card){
      card.addEventListener('pointermove',function(e){var r=card.getBoundingClientRect();card.style.setProperty('--mx',(e.clientX-r.left)+'px');card.style.setProperty('--my',(e.clientY-r.top)+'px');});
    });
  }
  // pencarian langsung (debounce), kirim via form GET agar tetap bisa dibagikan URL-nya
  var form=document.getElementById('dvForm'),q=document.getElementById('projSearch');
  if(form&&q){var t;q.addEventListener('input',function(){clearTimeout(t);t=setTimeout(function(){form.requestSubmit?form.requestSubmit():form.submit();},420);});}
})();
JS;
}

/** Badge status kecil berwarna dengan titik berkedip untuk "perlu perhatian". */
function dash_status_dot(string $status): string
{
    $label = ['draft' => 'Draf', 'needs' => 'Perlu perhatian', 'ready' => 'Siap', 'deployed' => 'Terpublikasi', 'error' => 'Error'][$status] ?? 'Draf';
    return '<span class="dv-pill" style="display:inline-flex;gap:7px;align-items:center"><span class="dv-dot is-' . h($status) . '"></span>' . h($label) . '</span>';
}

function dash_stat(string $label, string $value, string $sub, int $i, string $cls = '', string $tint = ''): string
{
    $num = is_numeric($value) ? '<span data-count="' . h($value) . '">0</span>' : h($value);
    return '<div class="dv-stat ' . $cls . '" style="--i:' . $i . ';' . ($tint !== '' ? '--tint:' . $tint . ';' : '') . '"><div class="dv-stat-num">' . $num . '</div><div class="dv-stat-lbl">' . h($label) . '</div><div class="dv-stat-sub">' . h($sub) . '</div></div>';
}

/** Dipanggil dari render_dashboard setelah semua data siap. Mengembalikan HTML body lengkap. */
function dashboard_body(array $p): string
{
    $tabs = '';
    foreach ($p['tabs'] as $t) $tabs .= '<a class="dv-tab' . ($t['on'] ? ' is-on' : '') . '" href="' . h($t['href']) . '">' . h($t['label']) . ' <span class="n">' . (int)$t['n'] . '</span></a>';

    $attention = '';
    if ($p['attention']) {
        $rows = '';
        foreach (array_slice($p['attention'], 0, 6) as $it) $rows .= '<div class="dv-attn-row"><span><b>' . h($it['name']) . '</b><small>' . h($it['loc']) . ' · ' . h($it['why']) . '</small></span><a class="dv-go" href="' . h($it['href']) . '" style="padding:7px 12px;font-size:12px">' . h($it['cta']) . ' →</a></div>';
        $more = count($p['attention']) > 6 ? '<p class="dv-meta" style="margin:8px 0 0">dan ' . (count($p['attention']) - 6) . ' lainnya — pakai filter “Perlu perhatian”.</p>' : '';
        $attention = '<div class="dv-attn"><h3><span class="dv-attn-n">' . count($p['attention']) . '</span> Perlu perhatian sekarang</h3>' . $rows . $more . '</div>';
    }

    $cards = '';
    if ($p['empty'] === 'none') {
        $cards = '<div class="dv-empty"><div style="font-size:42px">🏛️</div><h3>Belum ada proyek</h3><p>Mulai dengan satu tour: impor panorama, kurasi pengetahuan, lalu publikasikan.</p><a class="dv-btn dv-btn-gold" href="?a=new">+ Tour baru</a></div>';
    } elseif ($p['empty'] === 'nomatch') {
        $cards = '<div class="dv-empty"><h3>Tidak ada yang cocok</h3><p>Coba kata kunci lain atau reset filter.</p><a class="dv-btn" href="?">Reset filter</a></div>';
    } else {
        foreach ($p['cards'] as $c) {
            $s = $c['s']; $id = (int)$c['id']; $i = (int)$c['i'];
            $cards .= '<article class="dv-card tour-card" style="--i:' . $i . ';--h:' . $c['hue'] . '" data-status="' . h($s['status']) . '">'
                . '<div class="dv-cover"><div class="dv-cover-ic">' . h(mb_strtoupper(mb_substr((string)$c['name'], 0, 1))) . '</div></div>'
                . '<div class="dv-body"><div class="dv-head"><div style="min-width:0"><h3 class="dv-name"><a href="?a=view&id=' . $id . '">' . h($c['name']) . '</a></h3>'
                . '<div class="dv-meta">' . dash_status_dot($s['status']) . '<span>' . h($c['loc']) . '</span>' . ($c['cat'] !== '' ? '<span class="dv-pill">' . h($c['cat']) . '</span>' : '') . ($c['exportWarn'] ?? '') . '</div></div>'
                . dash_ring((int)$s['ready'], 52) . '</div>'
                . '<div class="dv-chips">' . ($s['hasParsed'] ? '<span>' . studio_icon('scenes', 13) . (int)$s['scenes'] . ' scene</span>' : '<span>belum diimpor</span>')
                . ((int)$s['intents'] > 0 ? '<span>' . studio_icon('questions', 13) . (int)$s['intents'] . ' intent</span>' : '')
                . ((int)$s['knowledgeTotal'] > 0 ? '<span>' . studio_icon('knowledge', 13) . (int)$s['knowledgeTotal'] . ' pengetahuan</span>' : '') . '</div>'
                . '<div class="dv-foot"><div class="dv-next">' . (int)$s['doneSteps'] . '/' . (int)$s['totalSteps'] . ' langkah' . ($s['nextStep'] ? '<br><b>Berikutnya:</b> ' . h((string)$s['nextStep']['label']) : ' · lengkap') . '</div>'
                . '<a class="dv-go" href="' . h($c['continue']) . '">' . h($c['cta']) . '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg></a></div></div></article>';
        }
    }

    $pager = $p['pager'];
    return '<style>' . dashboard_style() . '</style>'
        . '<div class="dv">'
        . '<section class="dv-hero"><div class="dv-kicker">' . h($p['kicker']) . '</div><h1 class="dv-title">' . h($p['title']) . '</h1><p class="dv-sub">' . h($p['sub']) . '</p>'
        . '<div class="dv-actions"><a class="dv-btn dv-btn-gold" href="?a=new">+ Tour baru</a><a class="dv-btn" href="?a=bulkImport">Impor massal</a><a class="dv-btn" href="?a=jobs">Antrian publikasi</a><a class="dv-btn" href="?a=cities">Kota &amp; pengetahuan</a></div></section>'
        . '<div class="dv-stats">' . implode('', $p['stats']) . '</div>'
        . $attention
        . '<form method="get" action="" id="dvForm" class="dv-bar" role="search">'
        . '<div class="dv-search">' . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        . '<input type="search" id="projSearch" name="q" value="' . h($p['q']) . '" placeholder="Cari tour, slug, atau kota…" aria-label="Cari tour"></div>'
        . '<select class="dv-select" name="city" aria-label="Kota" onchange="this.form.submit()">' . $p['cityOpts'] . '</select>'
        . '<select class="dv-select" name="cat" aria-label="Kategori" onchange="this.form.submit()">' . $p['catOpts'] . '</select>'
        . '<select class="dv-select" name="sort" aria-label="Urutkan" onchange="this.form.submit()">' . $p['sortOpts'] . '</select>'
        . ($p['st'] !== 'all' ? '<input type="hidden" name="st" value="' . h($p['st']) . '">' : '')
        . '<div class="dv-tabs" role="tablist">' . $tabs . '</div>'
        . ($p['filtering'] ? '<a class="dv-btn" href="?">Reset</a>' : '')
        . '</form>'
        . '<div class="dv-grid">' . $cards . '</div>'
        . ($pager !== '' ? '<div class="dv-pager">' . $pager . '</div>' : '')
        . $p['error']
        . '</div>'
        . '<script>' . dashboard_script() . '</script>';
}
