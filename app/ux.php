<?php

declare(strict_types=1);

/**
 * Peningkatan UX lintas halaman: bar simpan yang jelas (Simpan ≠ Publikasi), toast, indikator loading,
 * penjaga perubahan belum tersimpan, daftar yang bisa dicari/dilipat, dan drawer Uji Chat di setiap halaman tour.
 * Semuanya progressive enhancement: tanpa JS, form tetap bekerja seperti sebelumnya.
 */

/**
 * Editor lama menampilkan tiga aksi sekaligus (Simpan / +Generate / +Deploy). Simpan tetap satu-satunya aksi di
 * editor; Generate & Deploy dipindahkan ke halaman Publikasi (ada status, konfirmasi, dan backup di sana).
 */
function studio_simplify_save_bars(string $body, int $tid): string
{
    if ($tid <= 0 || strpos($body, 'scn-foot') !== false) return $body; // Scene Editor punya footer sendiri
    $link = '<a class="btn btn-ghost" href="?a=publish&id=' . $tid . '" title="Generate &amp; deploy dilakukan di halaman Publikasi">Lanjut ke Publikasi →</a>';
    $body = (string)preg_replace('/<button\b[^>]*\bvalue=["\']?save_generate["\']?[^>]*>.*?<\/button>/s', $link, $body);
    $body = (string)preg_replace('/<button\b[^>]*\bvalue=["\']?save_deploy["\']?[^>]*>.*?<\/button>\s*/s', '', $body);
    return $body;
}

/** Drawer "Uji Chat": tombol melayang + panel; daftar scene dimuat saat pertama dibuka (?a=sceneList). */
function studio_chat_drawer(int $tid): string
{
    if ($tid <= 0) return '';
    return '<button type="button" class="chatdock-fab" id="cdFab" aria-expanded="false" aria-controls="cdPanel">' . studio_icon('questions', 16) . '<span>Uji Chat</span></button>'
        . '<aside class="chatdock" id="cdPanel" aria-label="Uji AI Guide" hidden>'
        . '<header><b>Uji AI Guide</b><button type="button" class="icon-btn" id="cdClose" aria-label=T("Tutup")>×</button></header>'
        . '<label for="cdScene" class="muted" style="font-size:12px">Posisi pengunjung</label><select id="cdScene"><option>Memuat scene…</option></select>'
        . '<div class="chatdock-log" id="cdLog" aria-live="polite"><p class="muted" style="font-size:12px">Tanyakan sesuatu seperti pengunjung. Jawaban datang dari backend yang sama dengan produksi.</p></div>'
        . '<form id="cdForm" class="chatdock-form" onsubmit="return false"><input id="cdQ" placeholder=T("Ketik pertanyaan…") autocomplete="off"><button class="btn btn-primary btn-sm" id="cdSend" type="submit">Kirim</button></form>'
        . '</aside>'
        . '<script>window.__chatDockTour=' . $tid . ';</script>';
}

function studio_ux_css(): string
{
    return <<<'CSS'
.item-card.is-collapsed>:not(:first-child){display:none}
.item-card.is-deleted{opacity:.5}
.item-card.is-deleted strong{text-decoration:line-through}
.item-card>:first-child{cursor:pointer}
.item-sum{color:var(--text-muted);font-size:12px;font-weight:400;margin-left:8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;max-width:52ch;vertical-align:bottom}
.list-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;position:sticky;top:0;z-index:6;background:var(--surface-1)}
.list-tools input[type=search]{flex:1;min-width:200px;max-width:380px}
.toast-container{top:72px;bottom:auto;right:var(--space-5)}
.tour-card-body>span{display:flex;align-items:center;gap:6px}
.btn.is-loading{opacity:.7;pointer-events:none}
.btn.is-loading::after{content:"";display:inline-block;width:12px;height:12px;margin-left:8px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:spin .7s linear infinite;vertical-align:-2px}
@keyframes spin{to{transform:rotate(360deg)}}
.chatdock-fab{position:fixed;right:20px;bottom:20px;z-index:80;display:flex;gap:8px;align-items:center;background:var(--primary);color:#fff;border:0;border-radius:999px;padding:11px 18px;font:600 13px var(--font);cursor:pointer;box-shadow:var(--shadow-2)}
.chatdock-fab:hover{background:var(--primary-hover)}
.chatdock{position:fixed;right:20px;bottom:76px;z-index:81;width:min(400px,calc(100vw - 32px));max-height:min(620px,calc(100vh - 110px));display:flex;flex-direction:column;gap:8px;background:var(--surface-1);border:1px solid var(--border-strong);border-radius:var(--radius);padding:14px;box-shadow:var(--shadow-2)}
.chatdock[hidden]{display:none}
.chatdock header{display:flex;justify-content:space-between;align-items:center}
.chatdock-log{flex:1;min-height:140px;overflow:auto;border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px;font-size:13px}
.chatdock-log .q{font-weight:600;margin-top:8px}
.chatdock-log .a{white-space:pre-wrap;margin-top:2px}
.chatdock-log .m{color:var(--text-muted);font-size:11px;margin-top:3px}
.chatdock-form{display:flex;gap:8px}
.chatdock-form input{flex:1}
@media (max-width:768px){.chatdock-fab span{display:none}}
CSS;
}

function studio_ux_js(): string
{
    return <<<'JS'
(function(){
/* ---------- Toast ---------- */
var box=document.querySelector('.toast-container');
if(!box){box=document.createElement('div');box.className='toast-container';box.setAttribute('role','status');box.setAttribute('aria-live','polite');document.body.appendChild(box);}
function T(s){return (window.__T&&window.__T[s])||s;}
window.studioToast=function(msg,type,ms){
  var t=document.createElement('div');t.className='toast toast-'+(type||'success');t.textContent=msg;box.appendChild(t);
  setTimeout(function(){t.style.opacity='0';t.style.transition='opacity .3s';setTimeout(function(){t.remove();},320);},ms||4200);
};
// Kartu flash sukses dari server -> toast (kartu error tetap tampil karena perlu dibaca).
document.querySelectorAll('.card[style*="--success"]').forEach(function(c){
  var txt=c.textContent.replace(/\s+/g,' ').trim();
  if(txt){window.studioToast(txt.replace(/^✓\s*/,'✓ '),'success',5200);c.remove();}
});
// Buang ?saved=… dari URL agar refresh tidak mengulang notifikasi.
try{var u=new URL(location.href);var ch=false;['saved','generated','deployed','imported','restored','resynced','seeded','kept','created'].forEach(function(k){if(u.searchParams.has(k)){u.searchParams.delete(k);ch=true;}});if(ch)history.replaceState(null,'',u.pathname+u.search+u.hash);}catch(e){}

/* ---------- Loading + cegah kirim ganda ---------- */
document.querySelectorAll('form[method="post"]').forEach(function(f){
  f.addEventListener('submit',function(e){
    if(e.defaultPrevented) return; // mis. konfirmasi dibatalkan: jangan kunci tombol
    if(f.dataset.sending==='1'){e.preventDefault();return;}
    f.dataset.sending='1';
    var sub=e.submitter||f.querySelector('button[type=submit]');
    // disable setelah data form terbentuk, agar name/value tombol yang diklik tetap terkirim.
    setTimeout(function(){f.querySelectorAll('button[type=submit]').forEach(function(b){b.disabled=true;});if(sub)sub.classList.add('is-loading');},0);
  });
});
window.addEventListener('pageshow',function(ev){if(ev.persisted){document.querySelectorAll('form[data-sending]').forEach(function(f){delete f.dataset.sending;f.querySelectorAll('button[type=submit]').forEach(function(b){b.disabled=false;b.classList.remove('is-loading');});});}});

/* ---------- Peringatan perubahan belum tersimpan (hanya form editor yang punya tombol "do") ---------- */
document.querySelectorAll('form[method="post"]').forEach(function(f){
  if(!f.querySelector('button[name="do"]')) return;
  var dirty=false;
  f.addEventListener('input',function(e){if(e.target&&e.target.name)dirty=true;});
  f.addEventListener('change',function(){dirty=true;});
  f.addEventListener('submit',function(){dirty=false;});
  window.addEventListener('beforeunload',function(e){if(dirty){e.preventDefault();e.returnValue='';}});
});

/* ---------- Daftar yang bisa dicari & dilipat (Knowledge, Q&A, Intent, Tips, Nearby) ---------- */
document.querySelectorAll('form[method="post"]').forEach(function(f){
  var cards=[].slice.call(f.querySelectorAll('.card')).filter(function(c){return c.querySelector('input[type=checkbox][name^="del_"]');});
  if(cards.length<3) return;
  var NEW=/judul baru|topik baru|tempat baru|entry baru|_NEW\b/i;
  function textOf(c){var s=(c.querySelector('strong')||{textContent:''}).textContent+' ';c.querySelectorAll('input[type=text],input:not([type]),textarea').forEach(function(i){s+=' '+i.value;});return s.toLowerCase();}
  cards.forEach(function(c){
    c.classList.add('item-card');
    var head=c.firstElementChild,strong=c.querySelector('strong');
    var sum=document.createElement('span');sum.className='item-sum';
    function refresh(){var ta=c.querySelector('textarea');var v=((ta&&ta.value)||'').replace(/\s+/g,' ').trim();sum.textContent=v?'— '+v.slice(0,110):'';}
    refresh();if(strong&&strong.parentNode)strong.parentNode.insertBefore(sum,strong.nextSibling);
    c.addEventListener('input',refresh);
    var del=c.querySelector('input[type=checkbox][name^="del_"]');
    del.addEventListener('change',function(){c.classList.toggle('is-deleted',del.checked);});
    head.addEventListener('click',function(e){if(e.target.closest('label,input,button,a'))return;c.classList.toggle('is-collapsed');});
  });
  var collapse=cards.length>10;
  cards.forEach(function(c){var isNew=NEW.test(textOf(c));c.classList.toggle('is-collapsed',collapse&&!isNew);});
  var firstNew=cards.filter(function(c){return NEW.test(textOf(c));}).pop();
  var tools=document.createElement('div');tools.className='card list-tools';
  tools.innerHTML='<input type="search" placeholder="'+T("Cari di")+' '+cards.length+' '+T("item")+'…" aria-label="'+T("Cari item")+'"><span class="muted" data-count style="font-size:12px"></span><button type="button" class="btn btn-ghost btn-sm" data-act="open">'+T("Buka semua")+'</button><button type="button" class="btn btn-ghost btn-sm" data-act="close">'+T("Tutup semua")+'</button>';
  cards[0].parentNode.insertBefore(tools,cards[0]);
  var inp=tools.querySelector('input'),cnt=tools.querySelector('[data-count]');
  function apply(){var q=inp.value.trim().toLowerCase(),n=0;cards.forEach(function(c){var ok=!q||textOf(c).indexOf(q)>-1;c.style.display=ok?'':'none';if(ok){n++;if(q)c.classList.remove('is-collapsed');}});cnt.textContent=q?n+' dari '+cards.length+' '+T('cocok'):cards.length+' '+T('item');}
  inp.addEventListener('input',apply);apply();
  tools.addEventListener('click',function(e){var a=e.target.getAttribute&&e.target.getAttribute('data-act');if(!a)return;cards.forEach(function(c){if(c.style.display!=='none')c.classList.toggle('is-collapsed',a==='close');});});
  if(firstNew){setTimeout(function(){firstNew.scrollIntoView({block:'center'});},120);}
});

/* ---------- Drawer Uji Chat ---------- */
var fab=document.getElementById('cdFab'),panel=document.getElementById('cdPanel');
if(fab&&panel){
  var tid=window.__chatDockTour,sel=document.getElementById('cdScene'),log=document.getElementById('cdLog'),q=document.getElementById('cdQ'),send=document.getElementById('cdSend'),loaded=false;
  function esc(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;}
  function toggle(open){panel.hidden=!open;fab.setAttribute('aria-expanded',open?'true':'false');if(open){if(!loaded)loadScenes();q.focus();}}
  function loadScenes(){loaded=true;fetch('?a=sceneList&id='+tid,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
    sel.innerHTML='';(d.scenes||[]).forEach(function(s){var o=document.createElement('option');o.value=s.id;o.textContent=s.label;sel.appendChild(o);});
    if(!sel.options.length){var o=document.createElement('option');o.textContent='Belum ada scene (import dulu)';sel.appendChild(o);}
    var want=new URL(location.href).searchParams.get('scene');if(want)sel.value=want;
  }).catch(function(){sel.innerHTML='<option>'+T('Gagal memuat scene')+'</option>';});}
  function ask(text){text=(text||'').trim();if(!text||!sel.value)return;send.disabled=true;var t0=Date.now();
    var wrap=document.createElement('div');wrap.innerHTML='<div class="q">'+esc(text)+'</div><div class="a muted">AI sedang menjawab…</div>';log.appendChild(wrap);log.scrollTop=log.scrollHeight;
    fetch('?a=testAnswer&id='+tid+'&scene='+encodeURIComponent(sel.value)+'&q='+encodeURIComponent(text),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      var a=wrap.querySelector('.a');
      if(d.ok){a.classList.remove('muted');a.textContent=d.reply;var m=document.createElement('div');m.className='m';var parts=[(d.provider||'?'),(Date.now()-t0)+' ms'];if(d.deployed===false)parts.push('server pusat (belum di-deploy)');if(d.meta)Object.keys(d.meta).forEach(function(k){parts.push(k+': '+(typeof d.meta[k]==='object'?JSON.stringify(d.meta[k]):d.meta[k]));});m.textContent=parts.join(' · ');wrap.appendChild(m);}
      else{a.textContent=T('Gagal:')+' '+(d.error||T('tidak diketahui'));}
    }).catch(function(e){wrap.querySelector('.a').textContent=T('Gagal menghubungi server:')+' '+e.message;}).finally(function(){send.disabled=false;q.value='';q.focus();log.scrollTop=log.scrollHeight;});}
  fab.addEventListener('click',function(){toggle(panel.hidden);});
  document.getElementById('cdClose').addEventListener('click',function(){toggle(false);});
  document.getElementById('cdForm').addEventListener('submit',function(){ask(q.value);});
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!panel.hidden)toggle(false);});
}
})();
JS;
}
