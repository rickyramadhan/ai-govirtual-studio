# Laporan Audit Source — Mode Percakapan Suara (Tahap A)

Versi: 1.0 — 9 September 2026
Produk: AI Guide runtime master + Studio (ai-guide-hub)
Pilot: **Lawang Sewu** (slug `tour-20260828100342`, bukan Balaikota)
Status: AUDIT SOURCE SELESAI. Belum ada perubahan kode. Melanjutkan ke Tahap B.

> Lampiran dokumen: `plans/AI-Guide-Voice-Mode-Plan-v2.md`. Laporan ini menjawab
> sepuluh pertanyaan teknis Bagian 23 plan dan menetapkan baseline audit untuk
> pengembangan Tahap B–F. Seluruh path/baris mengacu pada source yang benar-benar
> dibaca pada 9 September 2026, bukan pada laporan lama.

---

## 1. Identitas master, package, dan deployment

### Temuan utama: untuk pilot Lawang Sewu, master = package = deployment

| Peran | Lokasi | Keterangan |
|---|---|---|
| Runtime master (`SOURCE_AI`) | `C:/xampp/htdocs/govirtual-ai/govirtual-ai` | konstanta `index.php:8` |
| Package pilot | `storage/tours/tour-20260828100342/package/govirtual-ai` | byte-identik dengan master |
| Deployment aktif | `C:/xampp/htdocs/govirtual-ai/` (parent) | `export_path` tour = `C:/xampp/htdocs/govirtual-ai` |

Hasil verifikasi hash (MD5) untuk semua runtime file `frontend/guide.js`,
`guide.css`, `ai-client.js`, `api/*.php`, `api/tts.php`, `api/config.php`:
**SAME** antara master dan package. Seluruh data `data/*.json` + `config/tour.json`
Lawang Sewu juga **SAME** terhadap master. Artinya:

- **Master, package pilot, dan deployment Lawang Sewu adalah direktori yang sama.**
- Konsekuensi: perubahan runtime yang disinkronkan ke package akan langsung
  memengaruhi deployment Lawang Sewu yang aktif (`C:/xampp/htdocs/govirtual-ai/`).
- `deploy_package()` menolak deploy **ke** SOURCE_AI (index.php:1050-1053), tetapi
  `export_path` Lawang Sewu adalah **parent** SOURCE_AI, sehingga deploy tetap
  diizinkan dan akan menimpa SOURCE_AI sendiri.

### Git & versi

- Master adalah repo git sendiri dengan HEAD `ae1e1df` ("feat: add AI virtual tour guide system").
- Working tree master **tidak bersih**: banyak file tracking lama muncul sebagai
  *deleted* di root repo (mis. `api/ai-core.php`, `data/*.json`) karena struktur
  runtime dipindah ke subfolder `govirtual-ai/`. Ini **bukan** kehilangan file;
  `govirtual-ai/govirtual-ai/` berisi runtime aktual dan tidak berubah.
- Repo `ai-guide-hub` (hub/Studio) terpisah. Sebagian besar package tour ter-*track*,
  sebagian belum. Belum ada tag versi runtime; versi hanya ada di dalam JSON
  (`knowledge.json version:2`, `scene-identity.json version:"1.1-enriched"`, dll).

**Implikasi:** sebelum distribusi (Tahap E), perlu menetapkan versi runtime & skema
konfigurasi yang eksplisit (plan §17), karena saat ini belum ada.

---

## 2. Jawaban sepuluh pertanyaan teknis (plan §23)

### Q1. Di mana runtime master yang benar dan hubungan dengan paket pilot?
`C:/xampp/htdocs/govirtual-ai/govirtual-ai` (index.php:8). Untuk Lawang Sewu, package
dan deployment sama dengan master (lihat §1). Sinkronisasi: `copy_runtime()` (index.php:771)
dan `sync_runtime_all()` (index.php:801) menyalin runtime ke semua package + export,
**men-skip `config/` dan `data/`** (index.php:804). `sync_runtime_all()` juga menulis
`.env` baru per target dari DB hub (index.php:833).

### Q2. Event viewer untuk scene loaded / gagal / navigasi manual?
Viewer = **Pano2VR player** (`window.pano`, `window.skin`), bukan krpano.

| Event/kapabilitas | Ada? | Implementasi |
|---|---|---|
| Load scene | Ya | `navigate(scene)`/`goToSceneId(id)` → `window.pano.openNext('{id}', pan/tilt/fov)` (guide.js:516-517) |
| Scene changed | Ya (polling) | `handleSceneChanged(ns)` (2529) dipanggil oleh `startScenePolling()` (2547, interval **1800 ms**) dan hook `window.pano.on('openNext')` (2562) yang menunggu `getCurrentNode()` berubah (poll 80 ms, timeout 2 s) |
| Scene loaded (konfirmasi tiba) | Terbatas | Tidak ada callback `loaded`; hanya perubahan `getCurrentNode()` |
| Navigasi gagal | **Tidak ada** | `openNext` fire-and-forget; kegagalan hanya dapat disimpulkan dari timeout 2 s watcher (2574-2576) |
| Navigasi manual pengguna | Ya (tidak dibedakan) | Poller & hook `openNext` menangkapnya, tetapi tidak bisa membedakan manual vs programatik |
| Cancel scene load | **Tidak ada** | Tidak ada API cancel untuk `openNext` |

**Kesimpulan navigasi (penting untuk voice):** verifikasi "sudah sampai" saat ini
bergantung pada perubahan `getCurrentNode()` + polling 1800 ms / watcher 2 s, bukan
callback asli viewer. Untuk Tahap C perlu menambah sumber kebenaran scene aktif yang
lebih deterministik (mis. lapisan di `handleSceneChanged`) dan *coverage* dari data
fasilitas (lihat §4).

### Q3. Apakah executor mendukung cancel saat scene dimuat?
**Tidak.** Executor = `applyAction(result)` (guide.js:564-576). Menggerakkan
`navigate()`/`goToSceneId()` tanpa in-flight lock dan tanpa cancel. `activeRequest.abort()`
(582) hanya membatalkan fetch chat, bukan navigasi. Tidak ada flag "navigating now".
Dua navigasi cepat berurutan bisa memicu `openNext` ganda.

### Q4. Apakah resolver existing bisa dipanggil tanpa memanggil model teks?
**Ya, deterministik penuh di sisi server** (ai-core.php, tanpa provider):
- `ai_resolve_destination(query, place, tour)` — 1419-1450 (place-map → scene + BFS path)
- `ai_v2_recommend_destination(currentSceneId, tour)` — 1460-1483
- `ai_find_place_by_visitor_name(query)` — 955-968 (scene-identity places + aliases)
- `ai_flow_next(sceneId, tour)` — 105-123 (flow.json order)
- `ai_retrieve`/`ai_retrieve_v2` — 579-599 / 1342-1376 (RAG hybrid, tanpa model bila tanpa vektor)
- `ai_resolve_public_service` / `ai_search_public_services` — 1945-2089 / 1810-1922
- `ai_curated_response` — 266-343 (intents G01-G20)

Di sisi frontend juga ada resolver lokal deterministik: `findSceneMatches` (454),
`resolveSceneFromName` (596-608), `contextualSceneMatches` (484-488).

**Namun ada cacat data yang membuat `ai_resolve_destination` tidak berfungsi** (lihat §4):
`place-map.json` Lawang Sewu tidak memiliki array `places` yang diharapkan kode.

### Q5. Apakah tur punya registry fasilitas/alias atau perlu penambahan data?
**Belum ada.** Tidak ada registry fasilitas (toilet/musala/parkir/lobi/tiket), tidak ada
konsep coverage, tidak ada scene_revision. Yang ada hanya:
- `scene-identity.json` → `places` (29 tempat dengan `name`, `aliases`, `sceneIds`,
  `variantGroup`) — sumber resolusi nama terbaik saat ini.
- `nearby.json` (22 item jarak teks "100 meter" tanpa mapping scene) — off-site, bukan fasilitas.
- `public-services.json` (19 layanan kota, tanpa scene).
- Knowledge: 24 entri, 13 terikat scene (`sceneIds`), tanpa `verified_at` per entri.
- `curated-qa.json` **kosong** (`items: []`) untuk Lawang Sewu.
- Intents curated G12-G15 navigasi ada (G13/G14/G15 `action=go_to_scene`), tetapi
  mengandalkan place-map yang cacat (lihat §4).

**Kesimpulan:** data fasilitas/coverage/revision **wajib ditambah** sebelum Tahap C
(lihat rekomendasi §5). Ini sesuai plan §10.

### Q6. Penyimpanan sesi & scheduler pada hosting export?
- **Tidak ada** penyimpanan sesi server. `conversationState` + `history` dikirim
  bolak-balik oleh klien (tour-chat.php:38-42); server memperlakukannya sebagai opaque.
- Satu-satunya state file server: circuit-breaker provider
  `sys_get_temp_dir()/govirtual-ai-provider-state.json` (ai-provider.php:101-124)
  dan cache embedding `api/cache/ai/knowledge-embeddings.json` (ai-core.php:550-565).
- **Tidak ada rate limit, kuota, atau kontrol concurrency** pada endpoint mana pun.
- DB SQLite (`storage/app.sqlite`) hanya untuk Studio hub, **tidak tersedia di export**
  (paket export tidak membawa SQLite/db). Sesi voice pilot harus memakai file JSON
  dengan locking, atau SQLite jika deployment mendukung — belum ada keduanya.
- Hosting export saat ini = XAMPP lokal (`C:/xampp/htdocs/govirtual-ai/`), Apache + PHP
  CLI. Cron/scheduler belum dikonfigurasi.

### Q7. Apakah domain memasang tur melalui iframe, subfolder, atau lintas origin?
Deployment aktif adalah **subfolder langsung** (`https://<domain>/govirtual-ai/`
untuk paket; panorama di root `C:/xampp/htdocs/govirtual-ai/`). `frontend/guide.js`
menggunakan `basePath` relatif (`guide.js:18` → `api/tour-chat.php`). Ada `install-snippet.html`
untuk pemasangan via potongan HTML. Belum ada konfigurasi iframe lintas origin pada
deployment pilot; WebView/iframe perlu diuji di Tahap B/D sesuai matriks V28.

### Q8. Bagaimana API key disimpan dan dimasukkan ke package?
- Disimpan **plaintext** di tabel `api_keys` SQLite hub (index.php DDL).
- Dirender ke `.env` oleh `env_render_from_db()` (index.php:277-344), ditulis oleh
  `env_write_to()` (345-348) ke master, semua package, dan semua export
  (`env_sync_all()` 353-370; dipanggil dari `copy_runtime()` 794, `deploy_package()` 1093,
  `sync_runtime_all()` 833, dan setelah `saveKeys` 2076).
- **Masalah kritis:** `api/config.php` berisi **kunci nyata hardcoded** sebagai fallback
  (Gemini + OpenRouter, master baris 14 & 17), dan tidak dikecualikan dari
  `copy_runtime()`/`sync_runtime_all()`. Key yang sama juga menjadi **default browser**
  di `ai-client.js:18`. Artinya kredensial permanen **ikut ke dalam package dan repo git**
  (`.gitignore` hanya mengecualikan `.env`, bukan `api/config.php`).
- Tidak ada mekanisme ZIP dalam pipeline; "paket" adalah folder. (`govirtual-ai.zip`
  di root deployment dibuat manual oleh proses lain, 402 MB.)

**Untuk voice:** kredensial OpenAI Realtime **harus** disimpan server-side (endpoint
negosiasi/token sementara) dan **tidak boleh** masuk bundle JS/source map. Sebelum
Tahap B perlu memutuskan: strip kunci dari `api/config.php` atau pindahkan ke `.env`
sepenuhnya, dan tinjau kembali `.gitignore`.

### Q9. Model voice yang dapat diakses akun + verifikasi penutupan/usage?
Belum diverifikasi: tidak ada kredensial OpenAI Realtime pada proyek saat ini
(konfirmasi pengguna: **belum ada key**). Provider text existing: Gemini, OpenRouter
(Groq/GPT-4o-mini), Ollama. Tidak ada infrastruktur session/usage provider voice.
Verifikasi penutupan call, usage, dan model ID yang diizinkan akun adalah pekerjaan
Tahap B setelah key tersedia (plan §8, §18).

### Q10. Kebutuhan hard cap per sesi yang mengharuskan kontrol server tambahan?
Hosting pilot (XAMPP/Apache + PHP request-per-hit) belum punya proses server persisten,
scheduler, atau API penutupan call. Kebutuhan hard cap per sesi aktif **belum dapat
dipenuhi** tanpa mekanisme tambahan (cron dengan resolusi memadai, API provider
sideband, atau batas akun keras). Pilot harus dibatasi/berlabel privat sampai kontrol
ini terbukti (plan §18).

---

## 3. Backend chat: kontrak, keamanan, dan batas

### Kontrak `api/tour-chat.php`
- POST JSON saja; CORS **terbuka** `Access-Control-Allow-Origin: *` (baris 11, 18).
- Body: `message` (wajib, **≤ 240 char**), `aiRequestId`, `tourContext`,
  `currentScene`, `history` (≤12), `conversationState` (opaque), `viewContext`.
- **Tanpa autentikasi**, tanpa origin check, tanpa CSRF, tanpa rate limit.
- Respons: `reply, intent, confidence, entities, actions, action, scene, sources,
  recommendations, needs_clarification, clarification_options, conversation_update, ...`
- Alur: intent deterministik (CURRENT_LOCATION/NAVIGATION/ROUTE/FOLLOWUP) →
  curated → visitor tips → public service → internal-knowledge → provider.

### Batas teks tidak konsisten (konfirmasi temuan plan)
- Server `tour-chat.php:29` membatasi `message` ≤ 240 char.
- Frontend textarea `guide.js` menerima hingga 500 char (input `maxlength`).
- Plan meminta batas voice eksplisit tanpa pemotongan diam-diam — perlu kontrak ulang.

### Endpoint lain
- `api/tts.php` — konfirmasi: menjalankan Python edge-tts dengan path Windows
  `C:\Users\Ricky\AppData\Local\Programs\Python\Python312\python.exe` (baris 29),
  teks dibatasi 220 char, CORS terbuka. **Tidak portabel** untuk hosting PHP biasa;
  jangan dijadikan prasyarat realtime (sesuai plan).
- `api/ai-status.php` — echo health provider; mewarisi CORS global `.htaccess`.
- `api/test-rec.php` — skrip diagnostik CLI saja, tanpa HTTP surface.

### Keamanan (gabungan temuan frontend+backend)
1. Kunci permanen di `api/config.php` + `ai-client.js` (default browser) → **kebocoran kredensial aktif**.
2. CORS terbuka + tanpa auth di semua endpoint → abuse/kuras kuota berbayar.
3. `conversationState` diterima tanpa skema ketat (risiko rendah, tetap input tak tepercaya).
4. Log provider menyertakan `raw_content` dan detail debug saat `AI_DEBUG=true`.
5. `ai_validate_response` (690-780) memfilter reply, action allowlist
   (`AI_ALLOWED_ACTIONS` baris 68), rekomendasi, dan kebocoran kata-kata kunci/key.

---

## 4. Data Lawang Sewu: celah untuk tool voice (paling kritis)

### Cacat `place-map.json` — resolver navigasi deterministik mati
- `ai_load_place_map()` (ai-core.php:874-905) mengharapkan `places[]` dengan
  `placeDisplayName`, `aliases`, `variantGroup`. 
- Data aktual `data/place-map.json` Lawang Sewu **hanya berisi `scenes`** (35 baris,
  semua memetakan `sceneId → placeId: "tour-20260828100342"`, tanpa `places`).
- Akibat: `ai_resolve_destination()` (1428) mengiterasi `$map['places']` yang kosong →
  **selalu null**. Resolusi NAVIGATION_REQUEST deterministik & curated G13/G14/G15
  tidak berfungsi. `ai_curated_response` untuk G14/G15 mengembalikan null.
- Struktur tempat yang benar justru ada di `scene-identity.json → places`
  (29 tempat: `name`, `aliases`, `sceneIds`, `variantGroup`) — tapi kode tidak membacanya
  untuk navigasi (hanya `ai_find_place_by_visitor_name` yang memakainya).
- **Inkonsistensi nama:** 16 scene punya `placeId` yang tidak cocok dengan key dict
  `places` (mis. `galeri-museum-kereta-api` vs key `galeri-museum-kereta`).

### Tidak ada data fasilitas/coverage/revision
- Tidak ada registry fasilitas (toilet/musala/parkir/lobi/tiket).
- Tidak ada enum coverage (exact/nearby/information_only/unavailable).
- Tidak ada `scene_revision`/`verified_at`.
- "Ke toilet" saat ini hanya akan diklasifikasikan `FACILITY` (ai-core.php:1091) lalu
  diteruskan ke model atau template placeholder guide-policy.php:250
  ("Informasi fasilitas sedang saya pelajari lebih lanjut.") — **tidak ada data nav**.

### Yang tersedia & bisa dipakai
- 35 scene dengan `displayName` & `placeId` (scene-identity), 29 places + alias.
- Graph 77 hotspot (parsed.json) + BFS `ai_find_path`. Catatan: node54 terisolasi,
  subgraph malam (node50/52/62/63) tidak terjangkau dari awal hari (node51).
- `flow.json` order 35 node, `startScene=node39` (berbeda dengan parsed `startNode=node51`).
- Knowledge 24 entri (13 terikat scene) dengan source/provenance (`mappingConfidence`,
  `mappingEvidence`, `sourceType`).
- `nearby.json` 22 item (jarak teks, tanpa scene), `visitor-tips.json` 4 topik,
  `public-services.json` 19 layanan kota, `semarang-destinations.json` 116 destinasi
  kota **tanpa mapping scene**.
- `oi-positions.json` pan/tilt/fov untuk 10 scene (kamera/objek), objek 13 POI.

---

## 5. Rekomendasi jalur integrasi (untuk Tahap B–F)

Urutan diurutkan berdasarkan ketergantungan. Ini bukan eksekusi, hanya arah audit.

1. **Tahap B (sesi aman + audio):**
   - Bangun endpoint PHP `voice.php` dengan operasi `start_session`/`end_session`/
     `get_token` (OpenAI Realtime WebRTC: token sementara `/v1/realtime/client_secrets`),
     CORS dibatasi, origin + metode + kuota + concurrency divalidasi.
   - Kredensial OpenAI **hanya** server-side; jangan sentuh `ai-client.js`/`config.php`.
   - Pindahkan kunci dari `api/config.php` ke `.env` dan perbaiki `.gitignore`
     (prasyarat keamanan sebelum provider nyata).
   - Modul browser voice: izin mikrofon, WebRTC, interupsi, cleanup, mute/end,
     matikan dikte (`stopSpeech`/`stopDiscSpeech`) dan TTS (`ttsStop`, `_ttsSeq`).
   - Jangan ulangi pola `activeRequest.abort()` tunggal untuk permintaan chat vs tool voice.

2. **Tahap C (tool & konteks) — prasyarat data:**
   - **Perbaiki `place-map.json`** (atau ubah `ai_load_place_map`/`ai_resolve_destination`
     agar membaca `scene-identity.json → places` sebagai sumber tempat + `sceneIds`).
   - Tambah registry fasilitas per tur dengan alias, `coverage`, `destination_scene_id`,
     `source_ids`, `verified_at` (plan §10) untuk Lawang Sewu — setidaknya toilet,
     pintu masuk/keluar, galeri, hall, foodcourt, toko souvenir.
   - Tambah `scene_revision` pada kontrak sesi (nilai dapat dimulai 1 dan dinaikkan
     saat `handleSceneChanged`).
   - Tool server: `lookup_tour_information` (pakai `ai_retrieve`/`ai_retrieve_v2` +
     `ai_get_enriched_description` + curated + public services, tanpa model wajib);
     `resolve_navigation` (pakai resolver place yang sudah diperbaiki + BFS + coverage;
     kembalikan `ready`/`clarify`/`already_there`/`unavailable`).
   - Ledger aksi + idempotensi (call ID → hash argumen → hasil; action ID sekali mulai).
   - Interupsi: hentikan audio lama, AbortController untuk HTTP, buang hasil usang
     bila turn/revision tidak cocok; jangan mengirim aksi kedua selama navigasi berjalan.

3. **Tahap D (UI percakapan):**
   - Tombol "Mulai ngobrol" + "Tanya lewat teks" pada layar pembuka.
   - Panel kanan menjadi kartu ringkas; `ttsStop()`/`ttsDuckMusic(true)` untuk feedback
     mikrofon; label TTS existing "Bacakan jawaban".
   - Konteks bersama teks↔suara via `conversationState` + `visitedSceneIds` yang sudah ada.

4. **Tahap E (Studio & export):**
   - Tambah halaman per-tur "Percakapan Suara" mengikuti pola `prompts`/`savePrompts`
     (index.php:1703/1670) + JSON per-tur yang ditulis `build_files()` ke package
     `config/` (bukan lewat `sync_runtime_all()` yang men-skip `config/`).
   - Tambah `configSchemaVersion` pada `config/tour.json`; migrasi konfigurasi terpisah
     dari sinkronisasi runtime; default voice nonaktif bila skema tidak ada.
   - Validator export: periksa versi konfigurasi, kapabilitas, dan pastikan tidak ada
     kredensial permanen di bundle.

5. **Tahap F (QA & rollout):**
   - Gunakan matriks V01–V30 plan. Perangkat inti Chrome Android / Safari iPhone / desktop.
   - Uji interupsi dan VAD Indonesia (semantic_vad `eagerness:auto` vs server_vad).
   - Benchmark biaya sesi 5 menit (plan §18) setelah key tersedia.
   - Konfirmasi penutupan sesi aktif tanpa browser (cron/call API) sebelum klaim hard cap.

---

## 6. Risiko & blocker yang dicatat saat audit

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| R1 | Kredensial permanen di `api/config.php` + `ai-client.js` + repo git | Kebocoran; penyalahgunaan kuota berbayar | Pindah ke `.env`, perbaiki `.gitignore`, rotasi key |
| R2 | CORS terbuka + tanpa auth/rate limit di semua endpoint | Abuse; sesi voice bisa dikuras | Endpoint voice: origin/method/kuota/concurrency; selaraskan kontrak chat |
| R3 | `place-map.json` cacat → resolver navigasi deterministik mati | `resolve_navigation` tidak dapat dipakai tanpa perbaikan | Perbaiki data/loader; jadikan scene-identity `places` sumber tempat |
| R4 | Tidak ada data fasilitas/coverage/revision | Tool voice tidak punya fakta navigasi sah | Tambah registry fasilitas pilot + kontrak revision |
| R5 | Viewer tanpa callback loaded/failed + tanpa cancel | Verifikasi "sudah sampai" & interupsi navigasi tidak deterministik | Lapisan scene-state sendiri (event/polling) + timeout + coverage |
| R6 | Tidak ada penyimpanan sesi/scheduler server pada hosting | Hard cap sesi aktif tidak dapat dijamin | Pilot privat; batasi; evaluasi cron/API sideband |
| R7 | `tts.php` bergantung Python Windows + path lokal | Tidak portabel | Jangan jadikan prasyarat realtime; gunakan provider audio langsung |
| R8 | Batas teks inkonsisten (240 vs 500) | Input voice/teks dapat ditolak/terpotong | Kontrak batas eksplisit, tolak dengan error jelas |
| R9 | `sync_runtime_all()` men-skip `config/` & `data/` | Konfigurasi voice tidak ikut sync runtime | Konfigurasi voice ikut `build_files()` + migrasi terpisah |
| R10 | Tidak ada config schema version & export validator | Rollback/migrasi tidak teruji | Tambah versi skema + validator di Tahap E |
| R11 | Belum ada key OpenAI Realtime | Tahap B-C live tidak dapat diuji | Kerjakan kerangka + endpoint dengan token placeholder; uji saat key tersedia |
| R12 | Data kurasi Lawang Sewu (curated-qa) kosong | Tool lookup kurang sumber | Tambah curated QA/fasilitas sesuai plan §10 |

---

## 7. Baseline terverifikasi untuk tahap berikutnya

- Runtime master: `C:/xampp/htdocs/govirtual-ai/govirtual-ai` (HEAD `ae1e1df`, dirty tree).
- Pilot Lawang Sewu: `storage/tours/tour-20260828100342/` (package identik master).
- Deployment aktif: `C:/xampp/htdocs/govirtual-ai/` (Pano2VR, index.html + `govirtual-ai/`).
- Scene: 35 (node39–node73). Tempat: 29 (`scene-identity.json places`). Graph: 77 hotspot.
- Chat backend: `tour-chat.php` (240 char, CORS `*`, tanpa auth). TTS: `tts.php` (Python Windows).
- Studio: `index.php` + `studio.php`; SQLite `storage/app.sqlite`; `.env` dari DB hub;
  sync runtime men-skip `config/`/`data/`; tidak ada per-tur settings page untuk voice.
- Tindakan keamanan minimum sebelum provider nyata: R1–R2.

Dokumen ini menjadi dasar Tahap B. Setelah kredensial OpenAI Realtime tersedia,
lanjut implementasi endpoint voice + modul browser (Tahap B), lalu perbaikan data
dan tool navigasi (Tahap C).