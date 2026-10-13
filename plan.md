# Plan: Analytics & History untuk AI Guide (deployed) yang tampil di AI Studio

## 1. Tujuan
Setiap AI Guide yang dipatch ke export Pano2VR dan dideploy di domain client
(mis. `semarang.kota.govirtual.id/<lokasi>`, `semarang.go.id`) mengirim log
penggunaan ke AI Studio, sehingga pemilik dapat melihat:

- Siapa bertanya apa, kapan, dari **source URL/domain** mana, dan dari **IP** mana.
- Pertanyaan **apa** dan jawaban **apa** (teks & suara).
- Analitik: volume, unik visitor, top questions, mix provider, latensi, tingkat gagal.
- Interaksi UI (ganti scene, klik rekomendasi, buka info objek, putar video360, mulai/stop voice).

## 2. Keputusan yang Disetujui
| Topik | Keputusan |
|---|---|
| Topologi | Deployment tur & Studio **beda server** -> butuh **collector pusat (phone-home)** |
| Hosting Studio | **Publik** (ada domain) sehingga server client bisa POST log |
| IP | **Simpan IP mentah** |
| Retensi | **90 hari** (otomatis) |
| Cakupan | **Chat teks + percakapan suara + interaksi UI** |
| Tampilan | **Hanya di AI Studio** |
| Penyimpanan | **SQLite** (skala kecil-menengah) |

## 3. Arsitektur
```
[Browser pengunjung @ domain client]
   guide.js  -> chat / voice / UI events
   clientMeta: host, pageUrl, referrer, language, screen, sessionId, visitorId
        |  HTTPS POST
        v
[App terpasang: <export>/govirtual-ai/api/]
   tour-chat.php  (teks)
   guide-tool.php (suara)
   track.php      (event UI)          <- BARU
   analytics-lib.php                  <- BARU
        |  1) SELALU tulis lokal  data/logs/analytics-YYYY-MM-DD.jsonl   (offline first)
        |  2) fire-and-forget cURL (timeout 2s) -> collector
        v
[Collector]  https://<studio-domain>/telemetry/ingest.php   (tanpa Basic Auth)
   validasi token + HMAC + timestamp + allowlist -> INSERT ke DB -> prune 90 hari
        v
[SQLite]  <hub>/storage/app.sqlite  -> tabel analytics_events  (WAL mode)
        v
[AI Studio]  ?a=analytics  (global & per-tour) - filter, kartu, tabel, ekspor CSV
```

Prinsip: **balasan ke pengunjung tidak pernah menunggu logging**; jika collector
mati, log lokal tetap tersimpan dan bisa disinkronkan ulang.

## 4. Komponen A - Agent di App Terpasang
Lokasi master: `C:\xampp\htdocs\govirtual-ai\govirtual-ai\`
(penyebaran via `_sync_runtime.php` ke 18 package + export).

### 4.1 `api/analytics-lib.php` (BARU)
- `gva_analytics_context(array $meta): void` - simpan konteks 1 request
  (tour, scene, channel, message, clientMeta, IP, UA, host, referrer) ke
  `$GLOBALS['__gva_analytics']`.
- `gva_analytics_emit(array $result): void` - dipakai menggantikan pola
  `echo json_encode($result...); exit;`; menyimpan
  `reply/provider/intent/facet/confidence/latency/error_code` lalu menulis +
  memforward sebelum `echo; exit`.
- `gva_analytics_write_jsonl(array $event): void` - append ke
  `data/logs/analytics-YYYY-MM-DD.jsonl` dengan `flock(LOCK_EX)`; fallback ke
  `sys_get_temp_dir()` bila folder tak writable.
- `gva_analytics_forward(array $event): void` - cURL POST JSON ke
  `ANALYTICS_INGEST_URL`, header auth (lihat bagian 7),
  `CURLOPT_TIMEOUT=2`, `CURLOPT_CONNECTTIMEOUT=1`, abaikan semua error.
- `gva_analytics_client_ip(): string` - `X-Forwarded-For` (ambil hop pertama
  valid) -> fallback `REMOTE_ADDR`.
- `gva_analytics_meta_from_input(array $input): array` - gabungkan `clientMeta`
  (dari JS) dengan `$_SERVER`.
- `gva_analytics_enabled(): bool` - cek `ANALYTICS_ENABLED` +
  `config/tour.json -> analytics.enabled`.

### 4.2 `api/tour-chat.php`
- `require` `analytics-lib.php`; panggil `gva_analytics_context()` setelah
  `$input` diparse dan `$intent`/`$place`/`$scene` diketahui.
- Ganti sekitar 11 titik `echo json_encode(...); exit;`
  (state-engine `:74`, curated `:101`, tips `:119`, public-service `:146`,
  greeting `:176`, offtopic `:199`, internal-knowledge `:206`, facility `:249`,
  provider `:577`, dan semua fallback di blok `:464-568`)
  menjadi `gva_analytics_emit($result);`.
- Simpan `message` (sudah <=240 char), `reply` (dipotong maks 2000 char),
  `provider`, `intent`, `facet`, `confidence`, `latency_ms`, `scene`,
  `channel='text'`.

### 4.3 `api/guide-tool.php`
- Instrumentasi op suara: `start_session`, `turn`/`process`, `end_session`,
  `live_token`, dan op lainnya.
- Simpan `channel='voice'`, transkrip user & pemandu (dipotong), provider/model,
  durasi sesi.
- Karena ada banyak `voice_reply_error()` + `echo`, gunakan
  `gva_analytics_emit()` di semua titik keluar (termasuk `voice_reply_error`).

### 4.4 `api/track.php` (BARU)
- Endpoint ringan untuk event UI dari browser:
  `POST {event, scene, target, value, meta, clientMeta}`.
- Tulis lokal + forward ke collector. **Klien tidak pernah memegang token.**
- Event yang dikirim:
  `guide_open`, `guide_close`, `scene_change`, `nav_click`, `rec_click`,
  `objinfo_open`, `video360_play`, `voice_start`, `voice_stop`, `cta_click`
  (opsional).

### 4.5 `frontend/guide.js`
- Tambah `clientMeta()`:
  `host = location.host`, `pageUrl = location.href`,
  `referrer = document.referrer`, `language = navigator.language`, `screen`,
  `timezone`, `sessionId` (sessionStorage), `visitorId` (localStorage UUID;
  fallback acak per sesi).
- Sisipkan `clientMeta` ke payload chat (di `tryPhp()`, sekitar `:19`) dan ke
  `voiceApiCall`.
- Tambah `gvaTrack(event, data)` (POST ke `track.php`, fire-and-forget) di hook:
  - buka/tutup guide (`setUIState`/`aiBtn` `:3818`, veil `:3822`),
  - ganti scene (`handleSceneChanged`, `goToSceneId` `:2451`),
  - klik rekomendasi/nav card (`:2980`),
  - buka objek info (`:3008`), putar video360,
  - mulai/stop voice (`voiceSetMode` `:1853`).

### 4.6 `api/config.php`
- Tambah default non-secret: `ANALYTICS_ENABLED=false`,
  `ANALYTICS_INGEST_URL=''`, `ANALYTICS_TOKEN=''`,
  `ANALYTICS_RETENTION_DAYS=90`.

## 5. Komponen B - Collector

### 5.1 `telemetry/ingest.php` (BARU, folder terpisah di docroot Studio)
- Folder ini **tidak** di bawah `.htaccess` Basic Auth hub; sediakan `.htaccess`
  sendiri berisi `Require all granted`.
- Langkah validasi:
  1. `POST` + `Content-Type: application/json`, body <= 32 KB.
  2. Header `Authorization: Bearer <token>` cocok dengan setting hub.
  3. `X-GVA-Signature = HMAC_SHA256(rawBody, token)` valid.
  4. `X-GVA-Time` selisih <= 300 detik (anti-replay).
  5. `tour_slug` ada di allowlist (dari tabel `tours`).
- Insert ke `analytics_events`; jalankan `prune` retensi.
- Respons `{"ok":true}`; selalu HTTP 200 agar agent tidak retry storm.

### 5.2 Penempatan & konfigurasi
- DB path mengarah ke `<hub>/storage/app.sqlite` (server yang sama dengan Studio).
- Token digenerate di hub (setting `ANALYTICS_TOKEN`) dan ditulis ke `.env` tiap
  tur via `env_render_from_db()`.

## 6. Skema Database (DDL)
```sql
CREATE TABLE IF NOT EXISTS analytics_events (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  created_at    TEXT NOT NULL,              -- ISO8601
  tour_slug     TEXT NOT NULL,
  tour_name     TEXT NOT NULL DEFAULT '',
  source_host   TEXT NOT NULL DEFAULT '',   -- domain halaman tur
  page_url      TEXT NOT NULL DEFAULT '',
  referrer      TEXT NOT NULL DEFAULT '',
  ip            TEXT NOT NULL DEFAULT '',
  user_agent    TEXT NOT NULL DEFAULT '',
  session_id    TEXT NOT NULL DEFAULT '',
  visitor_id    TEXT NOT NULL DEFAULT '',
  channel       TEXT NOT NULL DEFAULT 'text',   -- text|voice|ui
  event_type    TEXT NOT NULL DEFAULT '',       -- chat|voice_turn|scene_change|...
  message       TEXT NOT NULL DEFAULT '',
  reply         TEXT NOT NULL DEFAULT '',
  provider      TEXT NOT NULL DEFAULT '',
  intent        TEXT NOT NULL DEFAULT '',
  facet         TEXT NOT NULL DEFAULT '',
  confidence    REAL NOT NULL DEFAULT 0,
  latency_ms    INTEGER NOT NULL DEFAULT 0,
  scene_id      TEXT NOT NULL DEFAULT '',
  scene_name    TEXT NOT NULL DEFAULT '',
  language      TEXT NOT NULL DEFAULT '',
  error_code    TEXT NOT NULL DEFAULT '',
  flagged       INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ae_created  ON analytics_events(created_at);
CREATE INDEX IF NOT EXISTS idx_ae_tour     ON analytics_events(tour_slug);
CREATE INDEX IF NOT EXISTS idx_ae_source   ON analytics_events(source_host);
CREATE INDEX IF NOT EXISTS idx_ae_session  ON analytics_events(session_id);
CREATE INDEX IF NOT EXISTS idx_ae_provider ON analytics_events(provider);
```

Pragma saat buka DB: `journal_mode=WAL`, `busy_timeout=5000`, `synchronous=NORMAL`.

## 7. Kontrak API

### 7.1 Payload event (agent -> collector)
```json
{
  "schema": 1,
  "tour_slug": "tour-20260828100342",
  "tour_name": "lawang sewu",
  "created_at": "2026-09-14T22:10:00+07:00",
  "channel": "text",
  "event_type": "chat",
  "source_host": "semarang.kota.govirtual.id",
  "page_url": "https://semarang.kota.govirtual.id/lawang-sewu/",
  "referrer": "https://google.com/",
  "ip": "103.47.132.10",
  "user_agent": "Mozilla/5.0 ...",
  "session_id": "s_ab12",
  "visitor_id": "v_9f3c",
  "message": "Di mana toilet?",
  "reply": "Toilet berada di ...",
  "provider": "facility-registry",
  "intent": "FACILITY",
  "facet": "POSITION",
  "confidence": 0.95,
  "latency_ms": 120,
  "scene_id": "node39",
  "scene_name": "Pelataran Monumen",
  "language": "id",
  "error_code": ""
}
```

### 7.2 Header wajib
```
Authorization: Bearer <ANALYTICS_TOKEN>
X-GVA-Signature: <hmac_sha256(rawBody, token)>
X-GVA-Time: <unix_ts>
X-GVA-Tour: <tour_slug>
```

### 7.3 Collector -> agent
`200 {"ok":true}` (atau `200 {"ok":false,"reason":"..."}` tetap 200).

## 8. Komponen C - AI Studio

### 8.1 `index.php`
- Tambah tabel `analytics_events` (bagian 6) di inisialisasi skema.
- `env_render_from_db()` tambah: `ANALYTICS_ENABLED`, `ANALYTICS_INGEST_URL`,
  `ANALYTICS_TOKEN`, `ANALYTICS_RETENTION_DAYS=90`, `ANALYTICS_HASH_IP=false`
  (disiapkan; nilai false sesuai keputusan IP mentah).
- Route baru:
  - `?a=analytics` - dashboard global & per-tour (`&id=<tour>`).
  - `?a=analyticsExport` - CSV/JSON sesuai filter.
  - `?a=analyticsDetail&event=<id>` - JSON detail baris.
- Fungsi: `analytics_query(array $filters): array`, `analytics_summary()`,
  `analytics_prune()`, `analytics_csv()`.
- Akses: `require_admin()`.

### 8.2 `studio.php`
- Sidebar: entri `['slug'=>'analytics','label'=>'History & Analytics','href'=>'?a=analytics']`.
- Breadcrumb map: tambah `'analytics'`, `'analyticsExport'`.
- Tab "History" di halaman per-tour (`?a=analytics&id=<tid>`).

### 8.3 UI dashboard `?a=analytics`
- **Kartu ringkasan:** total sesi, total pertanyaan, unik visitor, avg latensi,
  % graceful/off-topic, mix provider.
- **Filter:** tur, rentang tanggal, `source_host`, IP, provider, intent, channel,
  kata kunci.
- **Tabel history:** waktu - IP - sumber - channel - pertanyaan - jawaban -
  provider - latensi (klik -> detail).
- **Top list:** pertanyaan terbanyak, domain sumber terbanyak, scene tersibuk,
  jawaban gagal.
- **Grafik:** bar CSS/inline-SVG (tanpa library eksternal): volume harian,
  mix provider, top source.
- **Ekspor** CSV/JSON mengikuti filter; **pagination** server-side.

## 9. Konfigurasi & `.env`
```
ANALYTICS_ENABLED=true
ANALYTICS_INGEST_URL=https://<studio-domain>/telemetry/ingest.php
ANALYTICS_TOKEN=<secret-32hex>
ANALYTICS_RETENTION_DAYS=90
ANALYTICS_HASH_IP=false
```

`config/tour.json` (per tur): `"analytics": { "enabled": true }`.

## 10. Keamanan & Privasi
- Collector diamankan via **token + HMAC + timestamp + allowlist**
  (bukan Basic Auth, tapi setara).
- Rate-limit sederhana per token/IP; batas ukuran body.
- Reply disimpan maks 2 KB; pesan sudah dibatasi 240 char.
- Tidak pernah mencatat API key/secret.
- Halaman analitik hanya untuk role `admin`.
- Sesuai keputusan: IP mentah disimpan, retensi otomatis 90 hari, ada opsi
  masking/hash di masa depan (`ANALYTICS_HASH_IP`).

## 11. Retensi & Housekeeping
- Lazy prune: probabilitas ~1% setiap ingest ->
  `DELETE FROM analytics_events WHERE created_at < datetime('now','-90 days')`.
- Opsional cron harian `telemetry/ingest.php?prune=1` (token-protected) untuk
  server yang mengizinkan cron.
- Rotasi JSONL harian; kompresi/hapus file lokal >90 hari via cron server client
  (opsional).

## 12. Fase & Urutan Pengerjaan
1. **Fase 1 - Plumbing data**
   a. `api/analytics-lib.php`, `api/track.php` (agent).
   b. Instrumentasi `tour-chat.php` + `guide-tool.php` (ganti emit).
   c. `clientMeta` + `gvaTrack` di `guide.js`.
   d. `telemetry/ingest.php` + tabel DB + `env_render_from_db()`.
   e. `_sync_runtime.php` + inject `.env` ke 18 tur.
2. **Fase 2 - Studio UI**
   a. Route `?a=analytics` + query/summary.
   b. Tabel + filter + pagination + detail.
   c. Sidebar/breadcrumb + tab per-tour.
   d. Ekspor CSV/JSON.
3. **Fase 3 - Lanjutan**
   a. Grafik & top questions.
   b. Funnel event UI.
   c. Prune terjadwal + opsi geo-IP/masking.

## 13. Rencana Pengujian
- **CLI:** panggil `tour-chat.php`/`guide-tool.php` lokal (stdin JSON) -> pastikan
  JSONL tertulis, payload forward terbentuk, signature valid.
- **End-to-end:** deploy 1 tur uji -> kirim chat + voice + event UI -> verifikasi
  `ingest.php` menyimpan; cek tampil di `?a=analytics`; uji token salah -> ditolak.
- **Resiliensi:** matikan collector -> reply tetap normal & JSONL tetap ada.
- **Retensi:** set `ANALYTICS_RETENTION_DAYS=0` -> baris lama terhapus.
- **Performa:** pastikan total latensi tambahan < 5 ms pada balasan
  (forward async).
- **Privasi:** pastikan tidak ada key/secret yang tersimpan.

## 14. Risiko & Mitigasi
| Risiko | Mitigasi |
|---|---|
| `data/logs/` tidak writable di hosting client | fallback ke `sys_get_temp_dir()`; beri peringatan di UI Deploy |
| Collector terblokir Basic Auth hub | taruh di folder `telemetry/` terpisah + `.htaccess` sendiri |
| SQLite lock (collector tulis vs Studio baca) | WAL + busy_timeout |
| `localStorage` diblokir browser | fallback visitorId acak per sesi |
| Volume suara besar | potong transkrip, rotasi file harian, retensi 90 hari |
| Spoofing log | HMAC + timestamp + allowlist |
| Kekhawatiran privasi IP | halaman admin-only, opsi hash/masking disiapkan |

## 15. Yang Masih Perlu Konfirmasi
1. **Domain publik Studio & path collector final** (untuk mengisi
   `ANALYTICS_INGEST_URL`).
2. **Geo-IP**: lewati dulu (rekomendasi) atau sediakan MaxMind nanti?
3. **Prune**: lazy saat ingest (rekomendasi) atau wajib cron harian?
4. **Daftar event UI**: apakah sudah cukup, atau ada tambahan (klik CTA/WhatsApp,
   unduh panduan)?

## 16. Catatan
- Diperkirakan total perubahan: sekitar 6 file agent (2 baru), 1 file collector
  baru, 2 file hub (`index.php`, `studio.php`), plus 1 tabel DB dan penambahan
  `.env`.
- Tidak ada perubahan pada hasil visual export Pano2VR; hanya penambahan patch
  `govirtual-ai/` dan endpoint telemetri.
