// Tes browser (Edge headless via DevTools Protocol) pada SALINAN terisolasi Studio. Data asli tidak disentuh.
// Jalankan: node tests/browser_test.mjs      (butuh Node 22+, Microsoft Edge, PHP di C:\xampp\php)
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHP = 'C:/xampp/php/php.exe';
const EDGE = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find((p) => fs.existsSync(p));
if (!EDGE) { console.error('Edge tidak ditemukan'); process.exit(2); }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let failed = 0;
const check = (name, ok, extra = '') => { console.log((ok ? 'PASS ' : 'FAIL ') + name + (ok || !extra ? '' : '  -> ' + extra)); if (!ok) failed++; };

// ---------- salinan terisolasi + fixture ----------
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'hubbrowser-'));
for (const f of ['index.php', 'studio.php', 'qa_import.php', 'scene_import.php']) fs.copyFileSync(path.join(root, f), path.join(tmp, f));
fs.mkdirSync(path.join(tmp, 'app')); for (const f of fs.readdirSync(path.join(root, 'app'))) fs.copyFileSync(path.join(root, 'app', f), path.join(tmp, 'app', f));
const tdir = path.join(tmp, 'storage/tours/demo'); fs.mkdirSync(tdir, { recursive: true });
const entries = Array.from({ length: 14 }, (_, i) => ({ id: 'k' + i, title: i === 5 ? 'Sejarah Gedung' : 'Entri ' + i, aliases: [], type: 'information', content: 'Isi entri nomor ' + i + (i === 5 ? ' tentang sejarah' : ''), tags: ['x'], sceneIds: [] }));
fs.writeFileSync(path.join(tdir, 'knowledge.json'), JSON.stringify({ entries }));
fs.writeFileSync(path.join(tdir, 'parsed.json'), JSON.stringify({ scenes: [{ id: 'node1', name: 'Lobi', displayName: 'Lobi' }, { id: 'node2', name: 'Taman', displayName: 'Taman' }], hotspots: [], objects: [], skin: {} }));
fs.writeFileSync(path.join(tmp, 'seed.php'), `<?php define('STUDIO_ROUTE_RUNNER',true); ob_start(); chdir(__DIR__); $_SERVER['REQUEST_METHOD']='GET'; $_GET=['a'=>'__x']; require __DIR__.'/index.php'; ob_end_clean();
db()->prepare('INSERT INTO users(username,password_hash,role,created_at) VALUES(?,?,?,?)')->execute(['tester', password_hash('Kata-Sandi-123', PASSWORD_DEFAULT), 'admin', date('c')]);
save_tour(['name'=>'Demo Tour','slug'=>'demo','new_city'=>'Kota Uji','category'=>'Pariwisata','export_path'=>'']);`);
const seed = spawnSync(PHP, [path.join(tmp, 'seed.php')], { encoding: 'utf8' });
if (seed.status !== 0) { console.error(seed.stdout, seed.stderr); process.exit(2); }

// ---------- server PHP + Edge ----------
const port = 8800 + Math.floor(Math.random() * 100), dport = 9700 + Math.floor(Math.random() * 100);
const server = spawn(PHP, ['-S', `127.0.0.1:${port}`, '-t', tmp], { stdio: 'ignore' });
const prof = path.join(os.tmpdir(), 'hubbrowser-prof-' + dport);
const edge = spawn(EDGE, ['--headless=new', '--disable-gpu', '--no-first-run', '--disable-backgrounding-occluded-windows', '--disable-renderer-backgrounding', `--user-data-dir=${prof}`, `--remote-debugging-port=${dport}`, '--window-size=1366,900', 'about:blank'], { stdio: 'ignore' });
async function cleanup() { try { ws.close(); } catch {} edge.kill(); server.kill(); await sleep(600); for (const d of [tmp, prof]) try { fs.rmSync(d, { recursive: true, force: true }); } catch {} }

let ws, id = 0; const pend = new Map();
async function connect() {
  for (let i = 0; i < 60; i++) { try { const t = (await (await fetch(`http://127.0.0.1:${dport}/json`)).json()).find((x) => x.type === 'page'); if (t) { ws = new WebSocket(t.webSocketDebuggerUrl); await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; }); ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pend.has(d.id)) { pend.get(d.id)(d); pend.delete(d.id); } }; return; } } catch {} await sleep(250); }
  throw new Error('Edge tidak merespons');
}
const send = (method, params = {}) => new Promise((r) => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (r.result?.exceptionDetails) return { __error: r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text }; return r.result?.result?.value; };
async function shot(name) { if (!process.env.SHOT_DIR) return; fs.mkdirSync(process.env.SHOT_DIR, { recursive: true }); const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(process.env.SHOT_DIR, name + '.png'), Buffer.from(r.result.data, 'base64')); }
async function goto(p) { await send('Page.navigate', { url: `http://127.0.0.1:${port}/${p}` }); await sleep(1200); }
async function waitFor(expr, ms = 8000) { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (await ev(expr)) return true; await sleep(150); } return false; }

try {
  await connect();
  await send('Page.enable'); await send('Runtime.enable'); await send('Emulation.setFocusEmulationEnabled', { enabled: true });
  const jsErrors = []; ws.addEventListener('message', (m) => { const d = JSON.parse(m.data); if (d.method === 'Runtime.exceptionThrown') jsErrors.push(d.params.exceptionDetails.exception?.description?.split('\n')[0] || d.params.exceptionDetails.text); });

  // login lewat form sungguhan
  await goto('?a=login');
  await ev(`(document.getElementById('login_user').value='tester', document.getElementById('login_pass').value='Kata-Sandi-123', document.querySelector('form[action="?a=login"]').submit(), true)`);
  await sleep(1500);
  check('login: berhasil masuk ke dashboard', await ev(`!!document.getElementById('studio-sidebar') && location.search===''`));

  // dashboard
  await shot('1-dashboard');
  check('dashboard: toolbar filter server-side tampil', await ev(`!!document.querySelector('form.dv-bar select[name=city]') && !!document.querySelector('.tour-card')`));

  // sidebar bergrup
  await goto('?a=knowledge&id=1');
  const labels = await ev(`[...document.querySelectorAll('.sbnav-label')].map(e=>e.textContent.trim())`);
  check('sidebar: dikelompokkan Tour/Konten/Percakapan/Navigasi/Rilis', ['Tour', 'Konten', 'Percakapan', 'Navigasi', 'Rilis'].every((l) => labels.includes(l)), JSON.stringify(labels));

  // daftar yang bisa dicari & dilipat
  check('knowledge: toolbar cari muncul untuk 14 item', await ev(`!!document.querySelector('.list-tools input[type=search]')`));
  check('knowledge: >10 item otomatis terlipat', (await ev(`document.querySelectorAll('.item-card.is-collapsed').length`)) === 14);
  await ev(`(()=>{const i=document.querySelector('.list-tools input[type=search]'); i.value='sejarah'; i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  check('knowledge: pencarian "sejarah" hanya menyisakan 1 item', (await ev(`[...document.querySelectorAll('.item-card')].filter(c=>c.style.display!=='none').length`)) === 1);
  check('knowledge: item yang cocok otomatis terbuka', await ev(`!document.querySelector('.item-card:not([style*="none"])').classList.contains('is-collapsed')`));
  await ev(`(()=>{const i=document.querySelector('.list-tools input[type=search]'); i.value=''; i.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  check('knowledge: kosongkan pencarian menampilkan semua lagi', (await ev(`[...document.querySelectorAll('.item-card')].filter(c=>c.style.display!=='none').length`)) === 14);
  await ev(`document.querySelector('.item-card > :first-child').click()`);
  await shot('2-knowledge-terlipat');
  check('knowledge: klik judul membuka item', await ev(`!document.querySelector('.item-card').classList.contains('is-collapsed')`));

  // Simpan ≠ Publikasi
  check('editor: tombol Generate/Deploy diganti tautan ke Publikasi', await ev(`!document.querySelector('button[value=save_deploy]') && !document.querySelector('button[value=save_generate]') && !!document.querySelector('a[href="?a=publish&id=1"].btn')`));
  check('editor: tombol Simpan tetap ada', await ev(`!!document.querySelector('button[name=do][value=save]')`));

  // simpan -> toast, URL bersih, loading
  await ev(`(()=>{const t=document.querySelector('textarea[name=entry_5_content]'); t.value='Isi yang sudah diubah'; t.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  await ev(`document.querySelector('button[name=do][value=save]').click()`);
  await sleep(1800);
  check('simpan: data tersimpan di server', JSON.parse(fs.readFileSync(path.join(tdir, 'knowledge.json'), 'utf8')).entries[5].content === 'Isi yang sudah diubah');
  check('simpan: notifikasi sukses tampil sebagai toast', await waitFor(`!!document.querySelector('.toast-container .toast-success')`, 3000));
  check('simpan: kartu flash lama dihapus dari halaman', await ev(`!document.querySelector('.card[style*="--success"]')`));
  check('simpan: parameter ?saved dibersihkan dari URL', await ev(`!/saved=/.test(location.search)`));
  check('simpan: field yang tidak ada di form (tags) tidak hilang', JSON.parse(fs.readFileSync(path.join(tdir, 'knowledge.json'), 'utf8')).entries[5].tags?.[0] === 'x');

  // drawer Uji Chat
  check('drawer: tombol Uji Chat ada di halaman tour', await ev(`!!document.getElementById('cdFab')`));
  await ev(`document.getElementById('cdFab').click()`);
  check('drawer: panel terbuka', await ev(`!document.getElementById('cdPanel').hidden`));
  await ev(`(()=>{const q=document.getElementById('cdQ'); q.value='Halo'; })()`);
  await shot('3-drawer');
  check('drawer: daftar scene dimuat dari server', await waitFor(`document.querySelectorAll('#cdScene option').length===2 && document.querySelector('#cdScene option').textContent.includes('Lobi')`));
  await ev(`document.getElementById('cdClose').click()`);
  check('drawer: tutup dengan tombol ×', await ev(`document.getElementById('cdPanel').hidden`));

  // halaman Uji tidak punya drawer ganda
  await goto('?a=test&id=1');
  check('uji: halaman Uji tidak menampilkan drawer ganda', await ev(`!document.getElementById('cdFab')`));

  // dashboard tidak punya drawer
  await goto('');
  check('dashboard: tidak ada drawer (bukan halaman tour)', await ev(`!document.getElementById('cdFab')`));

  check('tanpa error JavaScript di semua halaman', jsErrors.length === 0, jsErrors.slice(0, 3).join(' | '));
} catch (e) { console.log('ERROR tes:', e.message); failed++; }
await cleanup();
console.log(failed ? `\n${failed} GAGAL` : '\nSemua lulus');
process.exit(failed ? 1 : 0);
