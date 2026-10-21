# AI Studio — Audit Menyeluruh & Roadmap Jangka Panjang

Tanggal audit: 2026-10-20 · Cakupan: `index.php`, `studio.php`, runtime `govirtual-ai`, `storage/`, `tools/`, skrip root.
Kondisi saat ini: 120 tour (117 Semarang, 3 Lampung Selatan), 3 kota, 1 versi runtime.

---

## 1. Ringkasan Eksekutif

Fondasi AI-nya sudah matang (RAG, curated intent, multi-provider failover, voice, city knowledge).
Masalah utamanya ada di **"rumah"-nya**, yaitu Studio sebagai aplikasi pengelola:

1. **Belum ada konsep Wilayah.** Tour → kota hanya dicocokkan lewat teks bebas `location`, dan banyak default yang di-hardcode ke Semarang/Lawang Sewu.
2. **UI tumbuh tanpa arsitektur.** Ada 10+ editor terpisah, menu yang mati, campur bahasa Inggris/Indonesia, dan JSON mentah ditampilkan ke editor. Akibatnya terasa acak-acakan.
3. **Ada bug yang diam-diam menghapus data saat Simpan.** Ini paling mendesak.
4. **Proses membuat AI guide baru masih manual dan panjang**, sekitar 15 navigasi, 25+ klik dan 150+ field. Banyak langkah bergantung pada skrip CLI yang khusus Semarang.
5. **Tidak ada sistem desain per wilayah.** Semua 120 tour memakai CSS dan avatar yang identik, dan setiap package menyalin ±14 MB runtime (total 1,7 GB).
6. **Tidak ada analytics.** Rencana di `plan.md` belum pernah diimplementasi, jadi kita tidak tahu apa yang ditanyakan pengunjung.

---

## 2. Temuan Audit

### 2.1 Bug kritis (data hilang / fitur rusak) — sudah diverifikasi di kode

| # | Masalah | Lokasi |
|---|---|---|
| B1 | Textarea **Instructions kota berada di luar `<form>`**, sehingga setiap kali knowledge kota disimpan, instructions terhapus | `index.php:2759-2760`, `citySave` 2650 |
| B2 | Simpan Knowledge me-reset `sceneIds`, `tags`, dan menimpa terjemahan EN dengan teks ID | `index.php:2286` |
| B3 | Simpan Curated Intent membuang field `response` & `requiresState` | `index.php:1987` |
| B4 | Simpan Tips dan Nearby membuang field di luar yang ada di form | `saveTips`, `saveNearby` |
| B5 | `max_input_vars=1000` (default). Scene Editor mengirim ±13 field per scene, jadi tour dengan 75+ scene akan **kehilangan data diam-diam** | `php.ini:424` |
| B6 | Membuka halaman Rekomendasi otomatis menulis ulang "Sangga"→"Sandi"; membuka dashboard menulis `parsed.json` | `index.php:1957`, `2918` |
| B7 | Save As melakukan `str_replace` buta pada seluruh JSON dan tidak menyalin flow/curated-qa/voice/overrides | `index.php:1680` |

### 2.2 Menu / link mati

- **"Uji AI Guide"** (`?a=test`) tidak punya handler, sehingga jatuh ke dashboard.
- **"Publikasi"**, tombol Deploy di kartu, dan checklist memakai GET ke `deploy`, padahal handlernya hanya menerima POST, sehingga juga jatuh ke dashboard.
- **"Duplicate (Save As)"** di menu kartu mengalami hal yang sama.
- Pemilih project selalu membuka Scenes, apa pun halaman yang diminta.
- "Buka pano asli" memakai path disk `C:/xampp/...` sebagai URL.
- **Tips** dan **Nearby** tidak ada di sidebar.

### 2.3 Keamanan

- **Akun default ada di kode:** `admin/admin123`, `admin2/admin12345`, `qa/qa12345` (`index.php:188-191`).
- **Endpoint tanpa login:** `testAnswer` dan `genDesc` (memakan kuota AI), `thumb`, `panoview`.
- **CSRF** hanya ada di halaman Keys. Delete tour, delete kota, generate, import dan syncRuntime memakai **GET**.
- Skrip `_sync_runtime.php`, `_ai_health_check.php` dan `import_webview.php` bisa dipicu lewat browser karena tidak ada guard CLI.
- **`.env` berisi semua API key disalin ke ±240 lokasi**, termasuk web root setiap export.
- `export_path` tidak divalidasi, sehingga editor bisa mengarahkan deploy ke folder mana saja.
- Backup deploy menumpuk di web root (`.ai-generator-backup/`) tanpa pembatasan (Lawang Sewu saja 137 MB).

### 2.4 UI/UX

- **Design system di `studio.php` sebenarnya bagus** (token warna, komponen, toast, empty state, a11y dasar), tetapi `index.php` mengabaikannya: ada 170+ inline style, komponen toast tidak pernah dipakai, dan ikon emoji bercampur dengan SVG.
- **Sidebar:** di level atas hampir kosong. Di dalam tour ada 10 item datar tanpa grup, tidak ada pengganti tour (tour switcher), dan breadcrumb tidak memuat nama tour.
- **Bahasa campur:** "Save / Simpan", "Needs Attention / Perlu Perhatian", "Navigation Flow / Alur Navigasi".
- **Istilah developer muncul ke editor:** "State 1/State 2", "Label 0", ID intent, "Edit JSON mentah", schema version.
- **Feedback lemah:** pesan sukses lewat `?saved=1`, error tampil di bawah 120 kartu, tidak ada loading untuk Generate/Deploy, tidak ada peringatan perubahan belum disimpan, dan konfirmasi deploy tidak konsisten.
- **Pola editor list:** semua item ditampilkan sekaligus, ID/Tipe diketik bebas, "+ Tambah" me-reload halaman, tidak ada pencarian.
- **Simpan bercampur dengan Publikasi:** setiap editor punya Simpan / Simpan+Generate / Simpan+Deploy.
- **Tidak siap skala:**
  - Dashboard membaca ±1.200 file dan melakukan 360 hash per load, tanpa paginasi.
  - Filter lokasi diambil dari teks bebas.
  - Tidak ada aksi massal.

### 2.5 Arsitektur & Runtime

- **Runtime master berada di luar repo** (`C:/xampp/htdocs/govirtual-ai/govirtual-ai`) dengan 64 perubahan belum di-commit, sehingga kode yang dikirim ke klien tidak terversi.
- **Package per tour ±14 MB, dan 97% di antaranya duplikat.** `avatar-cepet.png` saja 11,9 MB × 120.
- **Hardcode Semarang:**
  - `guide.js` memuat 23 referensi Semarang/Lawang Sewu.
  - Fallback webview/catalog di `city_webview_config()`.
  - Daftar kategori, enrichment objek, dan fallback testAnswer.
- **Katalog salah tempat:** katalog semua wilayah ikut ke semua tour (tour Semarang membawa `lampung-selatan-destinations.json`).
- **`tools/` berisi 66 skrip:**
  - ±20 skrip one-off Semarang.
  - ±20 skrip yang menambal export secara langsung, sehingga terjadi drift tersembunyi dan patch bisa hilang saat sync.
  - Sisanya sebenarnya fitur yang belum masuk Studio (batch import, verifikasi, bulk Q&A).
- **Bulk deploy hanya lewat CLI** (`_deploy_all.php`), di-hardcode `location='semarang'` dan `expected=117`.
- **Root repo kotor:**
  - ngrok log 1,7 MB, `m[1])`, `index.php.bak`, tiga salinan `public-guide*.js`.
  - Skrip `_test_*`, `runner*`, `mutation_test` bercampur dengan file aplikasi.
- `db()` menjalankan ulang migrasi di setiap pemanggilan, dan penulisan JSON tidak atomik.

### 2.6 Fitur yang belum ada (untuk hub multi-wilayah)

| Fitur | Status |
|---|---|
| Entitas Wilayah/Kota + relasi ke tour | ❌ |
| Wizard tour baru (import → seed → isi → uji → publikasi) | ❌ |
| Template/starter pack dari Semarang & clone tanpa kehilangan data | ⚠️ Save As tersedia tetapi membuang data |
| Preview / test chat di Studio | ❌ (menu mati) |
| Bulk generate/deploy per wilayah + antrian progres | ❌ (CLI saja) |
| Riwayat versi konten & rollback deploy | ❌ (backup ada, tombol restore tidak ada) |
| Tema/desain per wilayah | ❌ |
| Chat log & analytics, pertanyaan yang gagal dijawab | ❌ |
| Status "perlu deploy ulang" (konten berubah setelah deploy) | ❌ |
| Manajemen user & role per wilayah, audit log | ❌ |
| Halaman health deployment (verifikasi semua export) | ❌ (skrip tools) |

---

## 3. Visi Arsitektur Target

### 3.1 Hierarki data

```
Workspace
 └─ Wilayah (Provinsi/Area)      → tema, persona default, provider default, kategori
     └─ Kota/Kabupaten           → city knowledge, instructions, sumber, katalog webview
         └─ Tour                 → scene, knowledge, Q&A, flow, voice, override tema
```

Tema dan persona di-*merge* berlapis saat build dengan urutan **Wilayah → Kota → Tour override**.
Desain tiap daerah bisa berbeda "sedikit" tanpa menyalin kode.

### 3.2 Runtime

- Runtime dipindah ke repo (`runtime/`) dan diberi versi rilis (`2.2.0`, `2.3.0`, …).
- Setiap tour mencatat `runtime_version`. Studio menampilkan tour yang tertinggal versi.
- Package tour hanya berisi `data/`, `config/` dan `theme.css` (±0,5 MB). Runtime dan aset dipasang sekali per host/wilayah.
- Patch export Pano2VR (video, teaser, mobile, popup) dijadikan satu langkah **post-processor** terversi di deploy, bukan skrip lepas.
- API key tidak lagi disalin ke setiap export. Pilihannya proxy AI terpusat, atau `.env` per deployment dengan key yang di-scope.

### 3.3 Struktur kode (refactor bertahap, bukan rewrite)

Tidak perlu langsung pindah framework. Pecah `index.php` secara bertahap:

```
app/
  bootstrap.php      (db, auth, csrf, helpers)
  repos/             (TourRepo, RegionRepo, ContentRepo — baca/tulis JSON atomik + merge)
  services/          (Importer, Seeder, Builder, Deployer, ThemeBuilder, Analytics)
  controllers/       (satu file per area: tours, content, regions, release, system)
  views/             (template PHP, bukan string concatenation)
public/assets/       (studio.css, studio.js — JS keluar dari string PHP)
runtime/             (govirtual-ai master, terversi)
tools/               (CLI generik: --region=…)  + tools/archive/semarang-2026/
tests/
```

Prinsip simpan data: setiap editor **merge ke record lama** (bukan membangun ulang), dan setiap tulis dilakukan secara atomik (tulis ke file temp, lalu rename).

---

## 4. Rancangan UI/UX Baru

### 4.1 Prinsip

1. **Satu bahasa (Indonesia)** dengan glosarium istilah tetap.
2. **Simpan ≠ Publikasi.** Editor hanya menyimpan draf. Publikasi dilakukan di satu tempat, dengan diff, progres dan backup.
3. **Master-detail.** Daftar item yang bisa dicari di kiri, form satu item di kanan, dan simpan per item. Ini sekaligus mengatasi masalah `max_input_vars`.
4. **Preview Chat selalu tersedia** sebagai drawer kanan, dan menampilkan sumber jawaban (Q&A kurasi / knowledge / LLM).
5. **Field berbasis pilihan, bukan ketik bebas** (tipe, kategori, scene, wilayah). JSON mentah hanya muncul di tab "Developer" untuk admin.
6. **Aksi destruktif** memakai POST + CSRF, modal konfirmasi, dan mengetik nama untuk menghapus.
7. Gunakan komponen design system yang sudah ada dan hapus inline style.

### 4.2 Navigasi

```
Level atas                    Di dalam tour
───────────                   ────────────────────────
Beranda                       [▾ Ganti tour: Lawang Sewu]
Wilayah                       Ringkasan
  Semarang (117)              KONTEN
  Lampung Selatan (3)           Scene · Pengetahuan · Tips · Sekitar
  + Wilayah baru              PERCAKAPAN
Semua Tour                      Pertanyaan Rekomendasi · Intent · Q&A Kurasi · Suara
Publikasi & Antrian           NAVIGASI
Analytics                       Alur Scene
Sistem                        RILIS
  Provider AI · Kesehatan AI    Uji Chat · Publikasi · Riwayat
  Pengguna · Runtime          Pengaturan
```

Contoh breadcrumb: `Wilayah / Semarang / Lawang Sewu / Pengetahuan`

### 4.3 Wireframe

**Beranda**
```
+----------+--------------------------------------------------------------+
| AI Guide | Beranda                          [Cari tour/wilayah... ⌘K][+]|
| Studio   +--------------------------------------------------------------+
|          | [120 tour] [87 live] [21 perlu perhatian] [AI: Gemini ● OK]  |
| Beranda  +--------------------------------------------------------------+
| Wilayah  | +---------------+ +---------------+ +------------------+     |
| Tour     | | Semarang      | | Lampung Sel.  | | + Wilayah baru   |     |
| Publikasi| | 117 tour      | | 3 tour        | |  dari template   |     |
| Analytics| | ██████░ 72%   | | ██░░░ 30%     | |  Semarang        |     |
| Sistem   | +---------------+ +---------------+ +------------------+     |
|          | Perlu perhatian (21)              [Wilayah ▾][Status ▾]      |
|          | ! Lawang Sewu   Semarang  export berubah      [Sinkronkan]   |
|          | ! Kota Lama     Semarang  12 scene tanpa nama [Perbaiki]     |
|          | ! Kalianda      Lampung   belum dipublikasi   [Publikasi]    |
+----------+--------------------------------------------------------------+
```

**Workspace tour**
```
+----------+--------------------------------------+-----------------+
|[Lawang ▾]| Semarang / Lawang Sewu / Pengetahuan | Uji Chat    [×] |
| Ringkasan| ● Draf tersimpan 10:42  [Publikasi ▸]| Scene [Lobby ▾] |
| KONTEN   +--------------------------------------+-----------------|
|  Scene   | [Cari...]  [+ Entri]| Judul [_______]| > Jam buka?     |
|  Pengeta.| ● Sejarah gedung  ✓ | Alias [chip][+]| ● 08.00–21.00   |
|  Tips    | ● Jam buka        ✓ | Tipe  [Info ▾] |   sumber: Q&A #4|
|  Sekitar | ○ Arsitektur      ! | Scene [Lobby×] |                 |
| PERCAKAP.|                     | Isi  [editor ] |                 |
|  ...     |                     | [Hapus][Simpan]| [ketik...]  [>] |
+----------+---------------------+----------------+-----------------+
```

**Wizard tour baru**
```
 ① Wilayah ─ ② Sumber ─ ③ Scene ─ ④ Konten ─ ⑤ Uji ─ ⑥ Publikasi
+---------------------------------------------------------------------+
| Wilayah [Bali ▾]  Kota [Denpasar ▾]  Kategori [Wisata ▾]            |
| Nama [Pura Tanah Lot]   Slug [pura-tanah-lot] (otomatis)            |
| Folder export [pilih dari daftar folder pano yang terdeteksi ▾]     |
|   ✓ pano.xml  ✓ skin.js  ✓ index.html  → 32 scene, 140 hotspot      |
| Mulai dari: (•) Template wilayah  ( ) Duplikat tour [Kota Lama ▾]   |
|                                            [Kembali] [Lanjut ›]     |
+---------------------------------------------------------------------+
```
Langkah ③–④ memakai AI untuk mengisi otomatis: nama scene, deskripsi, pertanyaan rekomendasi, dan knowledge awal dari city knowledge. Pengguna tinggal meninjau.

### 4.4 Desain AI guide per wilayah

Halaman **Wilayah → Tampilan** berisi:
- **Warna:** aksen, permukaan, teks.
- **Font:** dipilih dari daftar.
- **Radius dan gaya kartu.**
- **Avatar:** upload, otomatis dikonversi ke WebP.
- **Persona:** nama pemandu, salam pembuka, gaya bahasa.
- **Suara:** voice default.

Halaman ini dilengkapi **pratinjau langsung** widget guide di sampingnya. Setiap tour bisa meng-override sebagian.
Saat build, sistem menghasilkan `theme.css` yang hanya berisi variabel `:root`; `guide.css` tetap sama untuk semua.

---

## 5. Roadmap Bertahap

### Fase 0 — Stabilisasi (±1 minggu) · *wajib dikerjakan dulu*
- [ ] Perbaiki B1–B7 (simpan dengan merge, form instructions kota, hapus auto-rewrite saat GET).
- [ ] Naikkan `max_input_vars` (sementara), lalu ubah ke simpan per scene.
- [ ] Perbaiki link mati (`test`, `deploy`, `saveAs`, pemilih project), dan tambahkan Tips/Nearby ke sidebar.
- [ ] CSRF untuk semua POST; ubah delete/generate/import/syncRuntime menjadi POST.
- [ ] Wajib login untuk `testAnswer`/`genDesc`; tambahkan guard CLI di skrip `_*.php`.
- [ ] Ganti password default, hapus seed user hardcode, dan buat halaman ganti password.
- [ ] Bersihkan root:
  - hapus log, `.bak`, salinan `guide.js`, `m[1])`;
  - pindahkan tes ke `tests/`;
  - arsipkan tools Semarang.
- [ ] Batasi retensi backup deploy (simpan 3 terakhir).

### Fase 1 — Fondasi multi-wilayah (±2 minggu)
- [ ] Tabel `regions` dan `cities`, kolom `tours.city_id`; migrasi dari `location`.
- [ ] Pindahkan semua hardcode Semarang ke konfigurasi wilayah: webview, kategori, katalog, enrichment, dan regex di `guide.js`.
- [ ] Runtime masuk repo dan diberi versi; simpan `runtime_version` per tour.
- [ ] Katalog hanya disalin ke tour di wilayah yang sesuai.
- [ ] Cache statistik tour; dashboard dengan paginasi dan filter di server.

### Fase 2 — Restrukturisasi UI (±3 minggu)
- [ ] Pecah `index.php` menjadi controllers/views/services (§3.3); pindahkan JS/CSS ke `assets/`.
- [ ] IA dan sidebar baru, tour switcher, breadcrumb lengkap, satu bahasa.
- [ ] Editor master-detail untuk Knowledge, Q&A, Intent, Tips, Sekitar, Scene.
- [ ] Drawer **Uji Chat** dengan info sumber jawaban.
- [ ] Halaman **Publikasi** terpisah: diff perubahan, progres, riwayat dan rollback.
- [ ] Toast, loading state, dan peringatan perubahan belum disimpan.

### Fase 3 — Bikin AI guide jadi mudah (±2–3 minggu)
- [ ] Wizard tour baru (§4.3) dengan deteksi folder export otomatis.
- [ ] **Template Wilayah** dari Semarang: struktur intent, Q&A umum, tips standar, prompt, alur, yang disalin bersih ke wilayah baru.
- [ ] Clone tour tanpa kehilangan data.
- [ ] Batch import ZIP per wilayah (generalisasi `import-new-semarang-tours.php`).
- [ ] Bulk generate/deploy/sinkron per wilayah, dengan antrian di background dan progres.
- [ ] Pengisian otomatis dengan AI: nama scene, deskripsi, Q&A dari knowledge.

### Fase 4 — Desain per wilayah (±2 minggu)
- [ ] Token tema dengan pewarisan Wilayah → Kota → Tour, dan `theme.css` hasil build.
- [ ] Halaman Tampilan dengan pratinjau langsung, persona, salam, dan avatar (WebP).
- [ ] Runtime dan aset bersama per host, sehingga package menyusut dari 14 MB ke ±0,5 MB.
- [ ] Post-processor export terversi menggantikan skrip `fix-*`/`apply-*`.

### Fase 5 — Operasional & insight (berkelanjutan)
- [ ] Chat log dan analytics (menjalankan rencana `plan.md`): volume, pertanyaan teratas, latensi, provider.
- [ ] **"Pertanyaan tak terjawab" → jadikan Q&A kurasi dalam 1 klik.**
- [ ] Halaman kesehatan deployment untuk semua export, dengan alert saat health check gagal.
- [ ] Manajemen user, role per wilayah (Admin / Editor wilayah / QA), dan audit log.
- [ ] Set tes regresi Q&A per tour yang dijalankan sebelum publikasi.

---

## 6. Keputusan yang Perlu dari Pemilik

1. **Hierarki wilayah:** cukup *Kota/Kabupaten → Tour*, atau perlu *Provinsi → Kota/Kab → Tour*?
2. **Hosting tour:** apakah semua wilayah di-host di server yang sama (runtime bisa dipakai bersama), atau tiap klien/daerah punya server sendiri?
3. **Pengguna Studio:** hanya Anda, atau nanti ada tim/klien per daerah? Ini menentukan apakah role per wilayah perlu di Fase 2 atau 5.
4. **Studio online:** apakah akan dipasang publik (diperlukan untuk analytics phone-home)?
5. **Pendekatan kode:** refactor bertahap dari PHP sekarang (disarankan), atau rewrite ke framework (Laravel dan sejenisnya)?
