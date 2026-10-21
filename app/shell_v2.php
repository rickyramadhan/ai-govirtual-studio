<?php

declare(strict_types=1);

/**
 * Lapisan gaya "v2" untuk seluruh halaman Studio: kartu, tombol, input, navigasi, dan topbar
 * dibuat selaras dengan dashboard (hangat, berlapis, animasi halus). Hanya mengubah tampilan;
 * markup dan perilaku halaman tetap sama. Menghormati prefers-reduced-motion.
 */

function shell_v2_css(): string
{
    return <<<'CSS'
/* ===== Shell v2: selaras dengan dashboard ===== */
body.app{background:radial-gradient(1200px 600px at 85% -10%,rgba(201,169,110,.07),transparent 60%),radial-gradient(900px 500px at -10% 110%,rgba(201,169,110,.05),transparent 60%),var(--bg-app)}
.sbar{background:linear-gradient(180deg,var(--bg-sidebar),#0f1013);box-shadow:inset -1px 0 0 rgba(255,255,255,.03)}
.sbnav-item{border-radius:12px;transition:background .25s,color .25s,transform .25s cubic-bezier(.2,.8,.2,1)}
.sbnav-item:hover{transform:translateX(3px)}
.sbnav-item.is-active{background:linear-gradient(90deg,rgba(201,169,110,.14),rgba(201,169,110,.03));border-color:rgba(201,169,110,.25);color:var(--text-primary)}
.sbnav-item.is-active::before{background:linear-gradient(180deg,#e2c28a,#c9a96e);box-shadow:0 0 12px rgba(201,169,110,.5)}
.sbnav-item.is-active .sbnav-ic{color:var(--ai-accent)}
.sbnav-label{letter-spacing:.14em}
.topbar{background:rgba(18,19,21,.78);backdrop-filter:blur(14px) saturate(140%);border-bottom:1px solid rgba(255,255,255,.05)}
.topbar-title{font-family:var(--font-display);font-size:17px;font-weight:700}
.card{background:linear-gradient(180deg,var(--surface-2),var(--surface-1));border:1px solid rgba(255,255,255,.06);border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.22)}
.app-scroll>.card{animation:shellRise .55s cubic-bezier(.2,.8,.2,1) both}
.card:hover{border-color:rgba(201,169,110,.2)}
.card h2,.card h3{letter-spacing:-.01em}
.card h2:first-child,.card h3:first-child{margin-top:0}
@keyframes shellRise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.app-scroll>.card:nth-child(2){animation-delay:.05s}
.app-scroll>.card:nth-child(3){animation-delay:.1s}
.app-scroll>.card:nth-child(n+4){animation-delay:.15s}
.btn{border-radius:999px;padding:10px 20px;letter-spacing:.01em;transition:transform .25s cubic-bezier(.2,.8,.2,1),background .25s,box-shadow .25s,border-color .25s,color .25s}
.btn:hover{transform:translateY(-1px)}
.btn:active{transform:translateY(0) scale(.98)}
.btn-primary{background:linear-gradient(135deg,#e2c28a,#c9a96e);color:#1a1409;box-shadow:0 8px 20px rgba(201,169,110,.22)}
.btn-primary:hover{background:linear-gradient(135deg,#ecd29f,#d4b27a);box-shadow:0 12px 28px rgba(201,169,110,.34);color:#1a1409}
.btn-ghost{background:rgba(255,255,255,.02);border-color:var(--border-strong)}
.btn-ghost:hover{border-color:var(--ai-accent);background:rgba(201,169,110,.07)}
.btn-success{background:linear-gradient(135deg,#34d399,#22c55e);color:#052e16}
.btn-warning{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#451a03}
input,textarea,select{border-radius:12px;border-color:var(--border);background-color:var(--surface-2);transition:border-color .25s,box-shadow .25s,background-color .25s}
input:focus,textarea:focus,select:focus{border-color:var(--ai-accent);box-shadow:0 0 0 4px rgba(201,169,110,.16);background-color:var(--surface-3)}
label{letter-spacing:.01em}
.badge{border-radius:999px;backdrop-filter:blur(4px)}
.metric-card{border-radius:18px;background:linear-gradient(180deg,var(--surface-2),var(--surface-1));transition:transform .3s cubic-bezier(.2,.8,.2,1),border-color .3s}
.metric-card:hover{transform:translateY(-3px);border-color:rgba(201,169,110,.25)}
.metric-val{font-family:var(--font-display);font-weight:700}
.filter-chip{transition:transform .25s cubic-bezier(.2,.8,.2,1),background .25s,color .25s,border-color .25s}
.filter-chip.is-on{background:linear-gradient(135deg,#e2c28a,#c9a96e);border-color:transparent;color:#1a1409;font-weight:700}
.tour-card{border-radius:20px;transition:transform .35s cubic-bezier(.2,.8,.2,1),box-shadow .35s,border-color .35s}
.tour-card:hover{transform:translateY(-4px);box-shadow:0 20px 40px rgba(0,0,0,.35);border-color:rgba(201,169,110,.3)}
table{border-radius:12px}
thead th{letter-spacing:.06em;text-transform:uppercase;font-size:11px}
tbody tr{transition:background .2s}
tbody tr:hover{background:rgba(201,169,110,.04)}
details.card>summary{transition:color .2s}
details[open]>summary{color:var(--ai-accent)}
.toast{border-radius:14px}
.toast-container{top:auto}
.sbar-toggle,.icon-btn{transition:transform .25s,background .25s}
.icon-btn:hover{transform:rotate(-8deg);background:rgba(201,169,110,.08)}
.app-content{scroll-behavior:smooth}
@media (prefers-reduced-motion:reduce){
  .app-scroll>.card{animation:none!important}
  .btn,.card,.metric-card,.tour-card,.sbnav-item,.filter-chip{transition:none!important}
}
CSS;
}
