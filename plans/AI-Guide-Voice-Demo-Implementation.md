# Laporan Implementasi — Mode Suara Demo (Tanpa API key berbayar)

Versi: 1.0 — 9 September 2026
Produk: AI Guide Studio + runtime AI Guide hasil export
Pilot: **Lawang Sewu** (`tour-20260828100342`)
Rujukan: `plans/AI-Guide-Voice-Mode-Plan-v2.md`, `plans/AI-Guide-Voice-Audit-Source.md`

---

## 1. Ringkasan

Mode **Suara Demo** browser diimplementasikan penuh: pengguna bisa memilih Teks atau
Suara, memulai sesi percakapan suara, berbicara berulang tanpa tombol kirim, mendapat
balasan lisan singkat, dan meminta navigasi ke tempat yang sudah terpetakan. Semua
fakta memakai knowledge/curated/retrieval deterministik — **tidak ada panggilan
provider berbayar** dan **tidak ada API key voice**. Kerangka adapter (start/stop/
setMuted/interrupt/updateContext + event) dipisahkan dari UI/konteks/tool sehingga
adapter realtime (OpenAI Realtime WebRTC) dapat ditambahkan nanti tanpa membangun
ulang. Untuk realtime yang belum dikonfigurasi, UI menampilkan `not_configured`;
tidak ada token placeholder dan tidak ada koneksi provider yang dibuka.

## 2. Fitur yang berfungsi

- **Pemilih Teks/Suara** pada layar pembuka: "Tanya lewat teks" dan "Mulai ngobrol (DEMO)".
- **Panel "Mode Suara — Demo"** gelap dengan aksen emas; panorama tetap terlihat;
  panel desktop ringkas, mobile dapat menyesuaikan.
- **Status berbasis kejadian nyata**: `requesting → listening → processing → speaking`
  + `muted`, `error`, `not_configured`, `ended`. Waveform memakai sinyal mikrofon asli
  bila tersedia; jika tidak, status teks (bukan waveform palsu).
- **Kontrol**: Bisukan mikrofon, Hentikan jawaban, Riwayat, Akhiri percakapan.
- **Perilaku minimum**: mulai → izin → berbicara → transkrip final diproses sekali →
  lookup/navigasi → balasan singkat dibacakan → mendengarkan lagi selama sesi aktif.
- **Navigasi tervalidasi**: "ke galeri museum kereta api", "ke foodcourt",
  "ke monumen kereta api" → eksekusi via executor ber-guard (satu navigasi aktif,
  dedup, revision, timeout). "ke toilet" (belum terpetakan) → `unavailable`, jujur.
- **Klarifikasi** untuk tujuan ambigu dengan pilihan yang bisa dipilih.
- **Negasi** ("saya tidak mau ke toilet") tidak memicu navigasi.
- **Label TTS existing** diganti menjadi "Bacakan jawaban" agar beda dari status voice.
- **Capability detection**: tanpa SpeechRecognition/speechSynthesis, tombol voice
  dinonaktifkan dengan penjelasan; izin ditolak → petunjuk teks, tanpa restart loop.
- **Mode teks existing tidak diubah** alurnya (perilaku `tour-chat.php` dipertahankan).

## 3. File/komponen yang diubah

### Runtime master (`C:/xampp/htdocs/govirtual-ai/govirtual-ai`) — identik dengan package & staging

| File | Perubahan |
|---|---|
| `frontend/guide.js` | Nav state machine (scene_revision, single nav lock, action/turn id, ledger dedup, timeout jujur); voice adapter demo (SpeechRecognition + speechSynthesis); UI voice panel + mode selector; guard CLOSED-voice; test hook `__GVA_NAV_TEST__`; label TTS "Bacakan jawaban" |
| `frontend/guide.css` | Styles voice panel, mode selector, choices, history, status (dark + gold) |
| `api/guide-tool.php` | **BARU** — endpoint tool kontrak bersama: `start_session`, `end_session`, `lookup_tour_information`, `resolve_navigation`, `report_action_result`; kebijakan demo server-side (TANPA provider); session store file JSON locked + cleanup; CORS terbatas |
| `api/ai-core.php` | `ai_load_place_map()` — adapter: turunkan places/scenes dari `scene-identity.json` bila place-map tidak punya `places` (perbaiki resolver Lawang Sewu) |
| `api/config.php` | Hapus kunci hardcoded (fallback kosong seperti config.example.php) |
| `ai-client.js` | Hapus default API key browser; fail fast bila tanpa key |
| `config/tour.json` | Tambah `configSchemaVersion:2`, `runtimeVersion`, `voice:{mode:demo,...}` |
| `.htaccess` | Blok `api/cache/` (session voice + cache embedding) dari web |

### Studio (`C:/xampp/htdocs/ai-guide-hub`)

| File | Perubahan |
|---|---|
| `index.php` | Path deploy guard (normalisasi Windows, case-insensitive, cek target final bukan export root; tolak target==master / overlap / rekursif); `copy_runtime()` guard; `PACKAGE_CONFIG_SCHEMA_VERSION=2`, `RUNTIME_VERSION`; `tour_voice_settings()` + default `off`; `build_files()` menulis `voice` + versi ke config; halaman `voice` + `saveVoice`; `validate_package_export()` dipanggil saat deploy (skema, kapabilitas, isolasi tur, tanpa kunci di bundle); `saveVoice` masuk guard `require_editor` |
| `studio.php` | Nav sidebar + breadcrumb untuk "Percakapan Suara" |
| `.gitignore` | Tambah `/voice-checkpoint/` (checkpoint lokal) |

### Pilot Lawang Sewu

- `storage/tours/tour-20260828100342/voice.json` — mode `demo` (sumber konfigurasi per tur)
- `storage/tours/tour-20260828100342/package/govirtual-ai/*` — identik master
- `storage/tours/tour-20260828100342/staging-voice/` — **direktori pilot terpisah**:
  paket lengkap + `index.html` dengan mock viewer Pano2VR untuk uji tanpa panorama asli
- Checkpoint dapat dipulihkan: `C:/xampp/htdocs/ai-guide-hub/.voice-checkpoint/`

## 4. Lokasi & cara menjalankan pilot

1. **Studio** (browser): buka `ai-guide-hub` → pilih Lawang Sewu → "Percakapan Suara" →
   pilih `Demo browser` → Simpan → Generate. (Default pilot sudah `demo`.)
2. **Harness staging** (tanpa panorama): jalankan PHP server di
   `storage/tours/tour-20260828100342/staging-voice/`
   (mis. `php -S 127.0.0.1:8843 -t staging-voice`), buka `index.html`, klik AI Guide →
   "Mulai ngobrol". Browser Chrome/Edge dengan mikrofon dan izin HTTPS/localhost.
3. **Deployment aktif** (Lawang Sewu di `C:/xampp/htdocs/govirtual-ai/`): jalankan
   `?a=generate` lalu `?a=deploy` dari Studio — guard deploy memblokir bila target
   menimpa master (untuk Lawang Sewu, export = parent master, jadi deploy ke
   `govirtual-ai/` akan ditolak oleh guard; distribusi dilakukan lewat build ke
   package/staging, bukan menimpa SOURCE_AI).

## 5. Hasil pengujian nyata

Semua suite dijalankan terhadap source nyata (bukan hanya inspeksi):

- **PHP resolver**: place-map adapter menghasilkan 29 places/35 scenes; "galeri museum
  kereta api"→node71, "monumen kereta api"→node64; "toilet"→NULL. PASS.
- **PHP tool end-to-end** (`guide-tool.php`): start_session ok (demo), lookup found/
  not_found, nav ready/already_there/unavailable. PASS.
- **PHP suite 17 poin**: 22/22 PASS (resolver, clarify+lanjutan, unavailable, negasi,
  lintas-tur ditolak, demo tanpa provider, input panjang tidak dipotong, export
  validator, isolasi tur, tanpa kunci di bundle, text mode tidak regresi, guard
  master). 
- **Node browser** (`guide.js` nyata via jsdom): mode selector, panel aktif, recognition
  start, navigasi ke node71, negasi, lookup, cleanup setelah akhiri, question bar teks
  tetap ada. PASS.
- **Node nav state machine** (via test hook): revision naik saat scene berubah, stale
  context ditolak, duplicate action ditolak, call conflict ditolak, unknown scene,
  already_there, navigation_busy, correction mengganti pending, arrival resolve. PASS.
- **Guard deploy**: target Lawang Sewu==master ditolak; target non-overlap diizinkan.
- **Export validator**: lolos paket demo; menolak kunci hardcoded, realtime tanpa
  config, cross-tour.
- Lint: semua PHP (`index.php`, `studio.php`, `guide-tool.php`, `ai-core.php`,
  `config.php`) dan JS (`guide.js`, `ai-client.js`) bersih.
- Headless Chrome: staging harness boots tanpa error konsol; AI button, mode selector,
  voice panel ter-render.

### Belum diuji (status jujur — perlu pengujian manual perangkat)

- **Mikrofon nyata / SpeechRecognition / speechSynthesis di perangkat fisik** belum
  dijalankan di lingkungan ini (headless). Langkah manual: buka staging harness di
  Chrome desktop dengan mikrofon, klik "Mulai ngobrol", ucapkan "ke galeri museum
  kereta api", periksa jawaban lisan + navigasi. Uji juga di Chrome Android / Safari
  iPhone (Web Speech API tidak tersedia di semua browser — tombol akan dinonaktifkan).
- Kualitas audio, echo, jeda, dan naturalitas suara.
- Voice list browser (suara Indonesia) — bergantung OS/browser; fallback netral.

## 6. Perbaikan resolver/data yang dilakukan

- **`ai_load_place_map()`**: kini menurunkan `places` + `scenes` dari
  `scene-identity.json` (29 tempat dengan alias/sceneIds/variantGroup) bila
  `place-map.json` tidak menyediakan `places` (kasus Lawang Sewu & Balaikota). Tanpa
  ini, `ai_resolve_destination()` selalu null karena iterasi `$map['places']` kosong.
- **placeId reconciliation**: scene→place diambil dari `sceneIds` per place
  (autoritatif), mengatasi mismatch `placeId` scene vs key dict tempat (mis.
  `galeri-museum-kereta-api` vs `galeri-museum-kereta`).
- **Tidak mengarang fasilitas**: tidak ada registry toilet/musala — "ke toilet"
  menjadi `unavailable` sesuai plan. Data fixture QA tidak dimasukkan ke knowledge.

## 7. Bukti jalur demo tidak memanggil provider berbayar

- `guide-tool.php` **tidak** memuat/memanggil `ai_provider_*` (diuji regex pada source)
  dan tidak berisi URL provider (generativelanguage/openrouter/groq).
- Jalur voice browser (B3) hanya memanggil `api/guide-tool.php` (start_session/
  lookup/resolve_navigation/report/end) — tidak memanggil `tour-chat.php` (satu-satunya
  yang bisa memanggil provider).
- Kebijakan demo ditegakkan server-side: `start_session` menolak bila mode `off`;
  mode `realtime` tanpa `realtime_configured` → `NOT_CONFIGURED`; browser tidak bisa
  mengubah kebijakan.
- Tidak ada token sementara/placeholder dan tidak ada koneksi provider dibuka.

## 8. Keterbatasan demo vs realtime

- Bukan speech-to-speech native; transkrip → resolver deterministik. Tidak mengklaim
  setara ChatGPT Voice, semantic VAD, atau interupsi suara saat AI berbicara
  (baseline bergantian). Interupsi hanya lewat tombol "Hentikan jawaban".
- Browser dapat memakai layanan pengenalan/suara jarak jauh walau tanpa key aplikasi;
  tidak dijanjikan offline penuh.
- Transkrip input adalah representasi bantu, bukan salinan sempurna audio.
- Sesi tersimpan di file JSON pilot (locking + cleanup TTL), bukan database/penyimpanan
  kuat; hard cap aktif per sesi belum dapat dijamin tanpa scheduler/API penutupan.
- `tts.php` (Python Windows) tidak dipakai oleh voice; tetap ada untuk fitur TTS lama.

## 9. Kebutuhan konfigurasi realtime (langkah berikutnya)

1. Sediakan API key provider voice (OpenAI Realtime WebRTC / Gemini Live) server-side.
2. Tambah adapter realtime pada kerangka voice (start/stop/setMuted/interrupt/
   updateContext + event) memakai audio native → tool call langsung, memakai
   `validate_package_export` + executor yang sama (bukan lewat transkrip demo).
3. Isi `voice.json`: mode `realtime`, `realtime_configured:true`, `allowedOrigin`.
4. Implementasikan endpoint PHP `start_session`/`get_token` negosiasi (client_secrets
   atau call), CORS terbatas, kuota, dan verifikasi penutupan sesi aktif.
5. Set `realtime_configured` hanya setelah endpoint teruji; tanpa itu UI menampilkan
   `not_configured`.

## 10. Cara rollback

- **Checkpoint lokal** (belum di-commit): `C:/xampp/htdocs/ai-guide-hub/.voice-checkpoint/`
  berisi salinan master (`guide.js`, `guide.css`, `ai-client.js`, `api/*`, `config/tour.json`)
  dan hub (`index.php`, `.gitignore`) serta `pilot-package` sebelum perubahan.
- Restore: salin kembali file dari checkpoint ke `C:/xampp/htdocs/govirtual-ai/govirtual-ai/`
  dan `storage/tours/tour-20260828100342/package/govirtual-ai/`; hapus `api/guide-tool.php`.
- Konfigurasi: hapus/set `voice.mode=off` di `voice.json` / `config/tour.json` untuk
  nonaktifkan voice tanpa menyentuh mode teks.
- Tidak ada deployment produksi massal yang dijalankan; tidak ada sinkronisasi massal;
  tidak ada rewrite riwayat Git.

## 11. Tindakan kredensial yang harus dilakukan pemilik akun

- Kunci provider yang pernah tertanam di `api/config.php` dan `ai-client.js` (serta
  salinan package yang ter-track di git) **telah terekspos** dan **wajib dirotasi**
  pada akun Gemini/OpenRouter. Nilai tidak ditampilkan dalam laporan ini.
- `.gitignore` mengecualikan `.env`, tetapi `api/config.php` lama ter-commit. Setelah
  rotasi, pastikan config baru tidak memuat kunci; jika perlu, bersihkan dari git
  history (di luar lingkup implementasi ini — tidak dijalankan).
- Tinjau `.env` deployment dan Studio (`api_keys` tabel SQLite) agar tetap berisi
  kunci baru yang valid.

## 12. Catatan keamanan/opsional yang tersisa

- `tour-chat.php` tetap CORS `*` dan tanpa auth (mode teks existing). Untuk produksi
  berbayar, selaraskan origin + rate limit. Endpoint voice baru sudah membatasi origin.
- Verifikasi penutupan sesi aktif tanpa browser (cron/API call) sebelum mengklaim
  hard cap per sesi.