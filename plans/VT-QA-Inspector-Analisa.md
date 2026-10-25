# VT QA Inspector: Analisa Kelayakan Sistem QA Otomatis Virtual Tour

23 Oktober 2026 · Pilot: VT Semarang (117 tour) · Untuk: tim produksi VT & atasan

---

## 0. Kesimpulan Singkat

**Bisa diterapkan, dan sangat layak dibuat.** Ada tiga alasannya.

1. **Produk VT kita sangat terstruktur.** Semua tour Semarang dibuat dengan **Pano2VR 7.1**.
   - Isi tour ada di `pano.xml` (scene, hotspot, audio, GPS).
   - Tampilan ada di `skin.js`, dan setiap tombol punya nama tetap (`Button skip`, `Sound`, `Horizontal Language`, `popup_url-informasi`, dll).
   - Player bisa dikendalikan lewat JavaScript (`pano.getNodeIds()`, `pano.openNext()`, `getVariableValue('lang')`).
   - Artinya sebuah robot bisa membuka tour, menekan setiap tombol, mengunjungi setiap scene, dan mencatat hasilnya **tanpa campur tangan manusia**.
2. **Fondasinya sudah ada di Studio.** Kita sudah punya tes browser otomatis (Edge headless via DevTools Protocol di `tests/browser_test.mjs`) dan koneksi ke penyedia AI (Gemini/OpenRouter). Jadi tidak mulai dari nol.
3. **Sudah terbukti menemukan masalah nyata.** Dalam analisa awal ini, hanya dengan membaca file (belum membuka browser), kita sudah menemukan pola anomali di 117 tour Semarang (bagian 1).

Untuk target **50 lokasi dalam 3 minggu** (Badung, Papua), QA manual tidak akan cukup.
Satu QA yang mengklik semua tombol, scene, dan 4 bahasa di satu tour bisa butuh 1–2 jam, sementara robot butuh beberapa menit per tour dan bisa jalan bersamaan di malam hari.
**QA otomatis bukan tambahan, melainkan syarat agar target itu realistis.**

---

## 1. Temuan Awal dari 117 Tour Semarang (Tanpa Membuka Browser)

Ini hasil pemindaian file di `C:\xampp\htdocs\semarang\` dalam beberapa menit. Inilah gambaran yang akan didapat atasan dari sistem ini.

| # | Temuan | Skala | Dampak |
|---|---|---|---|
| T1 | `<title>` halaman kosong | **117 dari 117 tour** | Tab browser tanpa judul, SEO buruk |
| T2 | `og:title` / `og:description` kosong | **117 dari 117 tour** | Saat link dibagikan ke WhatsApp/Facebook, pratinjaunya kosong |
| T3 | Scene tanpa hotspot yang mengarah ke sana | **68 scene di 40 tour** (Lawang Sewu: 1) | Bisa jadi scene "yatim" yang tidak bisa dicapai pengunjung. Perlu dicek di browser, mungkin bisa dicapai lewat menu/peta |
| T4 | Judul scene di `pano.xml` kosong | **±97% scene** (2.526 dari 2.607 entri) | Judul scene penting untuk thumbnail, share, dan AI Guide. Perlu dicek apakah judul diisi dari tempat lain |
| T5 | Versi Pano2VR campur | **46 tour 7.1.11**, **71 tour 7.1.12** | Perilaku player bisa berbeda antar-tour |
| T6 | Tiga keluarga template skin | ±170 elemen (template standar), ±245–278 elemen (template baru), **outlier 149 & 152 elemen** (`rscolumbiaasia`, `pelabuhantanjungemas`) | Outlier kemungkinan kehilangan fitur yang dimiliki tour lain |
| T7 | `gginfo.json` mencantumkan `local.js` yang tidak ada | 2 tour (`lawang-sewu`, `rsud-tugu`) | Referensi file hilang |
| T8 | `tour-data.js` dimuat dua kali di `index.html` | 1 tour (Lawang Sewu) | Muatan dobel, berisiko konflik |
| T9 | File cadangan `skin.js.*.bak` di folder publik | **135 file di 15 tour** (Lawang Sewu: 9 file, ±11 MB) | Bisa diunduh publik, membuang ruang |
| T10 | Folder `.ai-generator-backup` di folder publik | **117 tour, total ±19 GB** | Bisa diakses publik, memakan ruang hosting dalam jumlah besar |
| T11 | Popup informasi bergantung pada server luar `webview-govirtual.id/semarang-v2` | Lawang Sewu saja ±52 URL × 4 bahasa | Jika server ini lambat/mati, semua popup info rusak. Harus dicek otomatis |
| ✅ | Hotspot yang mengarah ke scene yang tidak ada | **0 dari 2.490 scene** | Bagus. Struktur navigasi antar-scene sehat |

> Catatan: T3 dan T4 sengaja ditandai "perlu dicek". Inilah alasan sistem butuh **dua lapis**:
> pemeriksaan file (cepat, tapi bisa salah paham konteks) dan pemeriksaan di browser + AI (lebih lambat, tapi melihat apa yang benar-benar dilihat pengunjung).

---

## 2. Apa yang Dicek: Peta Komponen VT

Dari `skin.js` Lawang Sewu (278 elemen) dan `pano.xml`, komponen produk VT kita adalah:

| Area | Komponen (nama elemen di skin) | Yang dicek |
|---|---|---|
| **Intro** | `2-Video Teaser`, `video - desktop`, `video - mobile` | Video ada, bisa diputar, durasi wajar, tidak hitam, versi mobile ada |
| | `Button skip`, `Skip Mobile` | Muncul, bisa diklik, setelah diklik video berhenti & tour tampil |
| | Video on/off, `Mnegulang` (ulang) | Toggle berfungsi, status tersimpan |
| **Sambutan** | `welcome-container`, `Welcome-txt`, `close-welcome-text` | Muncul, teks sesuai bahasa, bisa ditutup, tidak terpotong |
| **Audio** | `Enable Audio?`, `Sound`, `Sound desktop`, musik latar | Prompt audio muncul, toggle benar-benar mematikan suara, file audio ada & tidak hening |
| **Bahasa** | `Horizontal Language`, `Language Active ...`, variabel `lang` | Setiap bahasa (ID, EN, ZH, AR) mengganti teks, audio narasi, dan URL popup. Arab rata kanan (RTL) |
| **Menu** | `Bottom Menu`, `Top ... Menu`, `Menu`, `Informasi`, kategori, Jelajah, Layanan Publik | Setiap tombol membuka panel yang benar dan bisa ditutup (`btn-close-*`) |
| **Popup iframe** | `popup_url-menu/-informasi/-discover/-public`, `url_popup-*` | URL memberi respons 200, konten tidak kosong, tidak ada error di dalam iframe, tombol tutup berfungsi |
| **Hotspot** | `hotspot_darat_project` (navigasi), `panah_vb`, `hotspot_oi_polygon` (info objek), `drone_icon_project`, `door_icon_project`, `360_video_experience_project` | Setiap hotspot mengarah ke scene/URL yang valid, label tidak typo, posisi tidak di langit/lantai yang aneh, info objek sesuai objeknya |
| **Scene** | `<panorama>` di `pano.xml` | Semua tile ada, gambar tidak hitam/blur, horizon lurus, sudut awal tidak menghadap dinding, GPS terisi |
| **Fitur khusus** | `drone-btn`, `night btn`, `Map`/`map-container` (Leaflet + Mapbox), `Chatbot` (iframe Chatbase), gyro | Tombol ada & berfungsi, token Mapbox valid, chatbot termuat |
| **AI Guide** | `govirtual-ai/` (guide.js, config, data) | Widget muncul, `tourId` cocok, katalog ada, menjawab pertanyaan uji dengan benar |
| **Halaman** | `index.html`, `gginfo.json` | Judul, OG/preview share, tidak ada file 404, tidak ada error JavaScript di console, waktu muat |

---

## 3. Arsitektur Sistem yang Diusulkan

```
           ┌─────────────────────────────────────────────────────────────┐
           │                 VT QA INSPECTOR (modul Studio)               │
           └─────────────────────────────────────────────────────────────┘
  Sumber tour: folder export lokal  ·  URL live (semarang.govirtual.id/...)  ·  ZIP dari tim
                                      │
      ┌───────────────┬───────────────┼────────────────┬─────────────────┐
      ▼               ▼               ▼                ▼                 ▼
 [L1] Statis    [L2] Robot       [L3] Cek Konten   [L4] Analisa AI    [L5] Kewajaran
 baca file      browser          & tautan          (visi + teks)      (bandingkan
 pano.xml,      klik semua       HTTP semua URL,   screenshot scene   dengan
 skin.js,       tombol, buka     audio/video       & UI dinilai AI,   117 tour lain
 index.html     semua scene,     tidak rusak/      terjemahan,        & spesifikasi
                desktop+mobile   hening            info objek         wilayah)
      └───────────────┴───────────────┴────────────────┴─────────────────┘
                                      │
                         ┌────────────▼────────────┐
                         │ Skor & Laporan per tour  │  → Dashboard Studio, PDF untuk atasan,
                         │ Blocker/Major/Minor/Info │    daftar tugas tim, gerbang sebelum publish
                         └──────────────────────────┘
```

### L1: Pemeriksaan Statis (detik per tour)
Membaca file tanpa membuka browser:
- Semua file yang dirujuk (`pano.xml`, `skin.js`, `gginfo.json`, `index.html`) benar-benar ada: tile, audio, video, gambar.
- **Kelengkapan tile** per scene: jumlah tile sesuai level resolusinya.
- Graf navigasi: scene yatim, hotspot ke scene yang tidak ada, scene awal valid.
- Data scene: judul, GPS, tag, audio narasi per bahasa (`*-IND.mp3`, `*-ENG.mp3`, `*-CHN.mp3`, `*-ARB.mp3`).
- Halaman: `<title>`, OG, include ganda, versi Pano2VR, keluarga template skin.
- Kebersihan: file `.bak`, backup, dan file besar yang tidak perlu di folder publik.
- Paket AI Guide: `tourId`, katalog, `langMap`, file data lengkap.

### L2: Robot Browser (menit per tour)
Membuka tour di Edge/Chrome headless, **di ukuran desktop dan HP**, lalu menjalankan skenario seperti pengunjung sungguhan:
1. Muat halaman, catat waktu muat, **error JavaScript**, dan **request gagal (404/500)**.
2. Video intro: muncul? berputar? (cek `currentTime` bertambah) → klik **Skip** → video berhenti dan tour tampil?
3. Prompt audio → **toggle suara**: cek elemen audio benar-benar `paused`/`muted`.
4. Teks sambutan muncul dan bisa ditutup.
5. **Ganti setiap bahasa**: teks, audio narasi, dan URL popup ikut berubah?
6. **Buka setiap menu & popup** → iframe termuat → screenshot → tutup.
7. **Kunjungi setiap scene** (`pano.openNext`) → tunggu tile termuat → screenshot 360 (beberapa arah) → catat hotspot yang tampil.
8. Klik hotspot info → popup benar, sesuai objek dan bahasa.
9. Drone, mode malam, peta, chatbot, gyro (simulasi).
10. AI Guide: buka widget, kirim 3 pertanyaan uji, catat jawaban.

Robot memakai **nama elemen skin (`ggId`)** untuk menemukan tombol. Karena tiap wilayah bisa punya template berbeda,
dipakai **Profil Skin**: file kecil yang memetakan "fitur → nama elemen".

```json
{ "profil": "semarang-template-baru",
  "skip":     ["Button skip", "Skip Mobile"],
  "audio":    ["Sound", "Sound desktop"],
  "bahasa":   { "elemen": "Horizontal Language", "variabel": "lang", "nilai": ["id","en","zh","sa"] },
  "popup":    ["popup_url-menu", "popup_url-informasi", "popup_url-discover", "popup_url-public"],
  "wajib":    ["intro_video", "skip", "welcome", "audio_toggle", "4_bahasa", "drone", "night", "map", "ai_guide"] }
```

Untuk Badung atau Papua dengan konsep berbeda, cukup buat profil baru sekali, lalu semua 50 lokasinya dites dengan profil itu.
Profil awal bahkan bisa **dibuat otomatis**: sistem membaca `skin.js` dan AI mengusulkan pemetaan, lalu tim tinggal mengonfirmasi.

### L3: Cek Konten & Tautan
- Setiap URL iframe/popup (contoh `objinfo2.php?info=1198&lang=en`) dicek: respons 200, halaman tidak kosong, dan tidak berisi pesan error.
- Audio: durasi > 0, **tidak hening total** (dianalisis dengan ffmpeg), volume tidak ekstrem.
- Video: bisa didekode, resolusi dan durasi wajar, ada versi mobile.
- Layanan luar: token Mapbox valid, Chatbase termuat, server webview hidup.

### L4: Analisa AI (bagian "canggih")
AI dipakai untuk hal-hal yang **tidak bisa dinilai dengan aturan pasti**:

| Yang dianalisa AI | Input | Contoh temuan |
|---|---|---|
| **Kualitas panorama** | Screenshot tiap scene | "Scene 12 gelap/blur", "tripod terlihat di bawah", "sambungan stitching terlihat", "horizon miring", "sudut awal menghadap dinding" |
| **Tampilan UI** | Screenshot tiap menu & ukuran layar | "Teks sambutan terpotong di HP", "tombol Skip tertutup logo", "popup kosong putih" |
| **Kesesuaian bahasa** | Teks per bahasa | "Saat bahasa Inggris dipilih, teks sambutan masih Bahasa Indonesia", "terjemahan Mandarin tidak wajar" |
| **Kesesuaian info objek** | Screenshot hotspot + isi popup | "Hotspot 'Gedung B' membuka info Gedung C" |
| **Kesesuaian judul/konten** | Gambar scene + judul + knowledge | "Scene berjudul 'Lobby' tapi gambarnya taman" |
| **Ejaan & penamaan** | Label hotspot, judul | "'Menu Tezt' (typo)", "'Mnegulang' (seharusnya 'Mengulang')", "'Chatbott'" |
| **Jawaban AI Guide** | Jawaban atas pertanyaan uji | "AI menjawab jam buka tanpa saran cek ulang", "AI menyebut lokasi di luar wilayah" |
| **Ringkasan untuk atasan** | Semua hasil | Ringkasan per tour dalam bahasa sederhana + prioritas perbaikan |

Contoh typo di atas **benar-benar ada** di nama elemen skin Lawang Sewu. Nama elemen tidak terlihat pengunjung,
tapi pola yang sama bisa muncul di label yang terlihat, dan itulah yang akan dicari AI.

**Prinsip penting:** AI tidak menjadi hakim tunggal.
- Pemeriksaan pasti (file ada/tidak, tombol berfungsi/tidak) menghasilkan **LULUS/GAGAL**.
- AI menghasilkan **"Perlu dicek"** beserta tingkat keyakinan dan screenshot sebagai bukti.
- Tim mengonfirmasi atau menolak. Masukan ini disimpan agar temuan palsu berkurang dari waktu ke waktu.

### L5: Analisa "Kewajaran" Produk
Menjawab pertanyaan: *apakah tour ini wajar dibanding standar produk kita?*

1. **Dibanding spesifikasi wilayah ("Definition of Done").** Contoh spesifikasi Semarang: intro video + skip, 4 bahasa,
   audio narasi tiap scene, drone, mode malam, peta, AI Guide, info objek. Hasilnya daftar **"mana yang belum ada"** per tour.
2. **Dibanding mayoritas tour lain (baseline otomatis dari 117 tour).** Contoh statistik saat ini:
   jumlah scene 5–48 (median 22), hotspot per scene ±2–3, elemen skin ±171 atau ±250.
   Tour yang jauh menyimpang ditandai, misalnya:
   - "Tour ini hanya 5 scene, padahal mayoritas 15–35."
   - "Tour ini tidak punya hotspot info sama sekali."
   - "Skin tour ini 149 elemen, kemungkinan template lama atau ada fitur yang hilang."
3. **Dibanding dirinya sendiri sebelumnya (regresi).** Setelah tour diedit, sistem membandingkan dengan hasil tes terakhir:
   "Kemarin 4 bahasa berfungsi, sekarang bahasa Arab rusak."

---

## 4. Hasil yang Dilihat Atasan & Tim

### Dashboard (di Studio)
```
 WILAYAH: SEMARANG · 117 tour · Tes terakhir: tadi malam 02:00
 ┌──────────────┬──────┬─────────┬───────┬───────┬──────────────────────────┐
 │ Tour         │ Skor │ Blocker │ Major │ Minor │ Status                   │
 ├──────────────┼──────┼─────────┼───────┼───────┼──────────────────────────┤
 │ Lawang Sewu  │  86  │    0    │   2   │   7   │ 🟡 Layak, ada perbaikan   │
 │ Kota Lama    │  91  │    0    │   1   │   4   │ 🟢 Siap                   │
 │ RS Columbia  │  58  │    2    │   5   │   9   │ 🔴 Belum layak publish    │
 └──────────────┴──────┴─────────┴───────┴───────┴──────────────────────────┘
 Matriks fitur: [Intro ✓] [Skip ✓] [4 Bahasa ✗ AR] [Audio ✓] [Drone ✓] [Malam –] [AI ✓]
```
*(Angka di atas ilustrasi.)*

- **Skor 0–100 per tour**, dengan warna (🟢 Siap / 🟡 Layak dengan catatan / 🔴 Belum layak).
- **Matriks fitur per wilayah**: tour × fitur, sehingga langsung terlihat "mana yang belum ada".
- **Daftar temuan** dengan screenshot bukti, tingkat keparahan, langkah reproduksi, dan saran perbaikan.
- **Penanggung jawab & status** per temuan (baru → dikerjakan → selesai → diverifikasi ulang otomatis).
- **Laporan PDF** per wilayah untuk atasan atau klien.
- **Gerbang publish**: tour dengan Blocker tidak bisa dipublikasikan dari Studio.

### Tingkat keparahan
| Level | Contoh |
|---|---|
| **Blocker** | Tour tidak termuat, scene hitam, tombol Skip tidak berfungsi, error JS yang menghentikan skin |
| **Major** | Satu bahasa tidak berfungsi, popup info kosong, audio narasi hilang, scene tidak bisa dicapai |
| **Minor** | Typo label, judul/OG kosong, teks sedikit terpotong di HP, file `.bak` di folder publik |
| **Info** | Saran: horizon sedikit miring, sudut awal kurang menarik |

---

## 5. Bagaimana Ini Membantu Target 50 Lokasi dalam 3 Minggu

| Tanpa QA Inspector | Dengan QA Inspector |
|---|---|
| QA manual 1–2 jam per tour → 50 tour = 50–100 jam kerja QA | Robot beberapa menit per tour, jalan paralel di malam hari |
| Masalah ditemukan klien setelah tayang | Masalah ditemukan sebelum publish (gerbang publish) |
| Atasan tidak tahu progres sebenarnya | Dashboard: berapa tour siap, apa yang kurang, siapa mengerjakan |
| Tiap QA mengecek dengan cara berbeda | Checklist sama untuk semua tour, sesuai spesifikasi wilayah |
| Perbaikan bisa merusak hal lain tanpa ketahuan | Tes ulang otomatis menangkap regresi |

**Alur kerja saat produksi cepat:**
1. Tim produksi export tour dari Pano2VR ke folder/staging.
2. Inspector **otomatis berjalan** (memantau folder atau tombol "Tes sekarang").
3. Dalam beberapa menit, temuan muncul di dashboard dan dikirim ke penanggung jawab.
4. Tim memperbaiki di Pano2VR → export ulang → tes ulang otomatis.
5. Tour hijau → boleh publish. Malam hari semua tour live dites ulang untuk menangkap link luar yang mati.

**Bonus: perbaikan otomatis untuk masalah sepele (dengan persetujuan).**
Mengisi `<title>` dan OG dari data tour, menghapus file `.bak` dan backup dari folder publik, menghapus include ganda.
Perbaikan yang menyentuh isi tour (Pano2VR) tetap dilakukan manusia.

---

## 6. Batasan yang Perlu Diketahui (Jujur)

| Batasan | Penjelasan | Cara mengatasi |
|---|---|---|
| Browser headless ≠ iPhone asli | Chrome/Edge headless bisa meniru ukuran HP, tapi bukan Safari iOS. Perilaku video/audio di iOS bisa berbeda | Tes otomatis untuk semua tour + **cek manual sampel di HP asli** (misal 1 dari 10 tour, plus semua yang berisiko) |
| Autoplay video/audio | Browser memblokir autoplay tanpa klik pengguna | Robot menjalankan browser dengan izin autoplay **dan** menguji alur klik seperti pengguna |
| "Enak didengar" sulit dinilai mesin | Robot bisa mendeteksi audio hilang/hening/terpotong, tapi bukan kualitas narasi | Penilaian kualitas oleh manusia untuk sampel |
| AI bisa salah | AI vision bisa menandai hal yang sebenarnya wajar | AI hanya memberi "Perlu dicek" + bukti, manusia memutuskan, masukan disimpan |
| Template skin berubah per wilayah | Nama tombol bisa berbeda | Profil Skin per wilayah, dibuat sekali (bisa dibantu AI) |
| Konten di dalam iframe server lain | Robot bisa memuat & screenshot iframe, tapi tidak bisa memperbaiki isinya | Temuan dikirim ke pengelola webview |
| Biaya AI | 50 tour × ±25 scene × beberapa screenshot = ribuan gambar per wilayah | Pakai model visi yang murah untuk penyaringan, model kuat hanya untuk yang mencurigakan; AI hanya jalan saat tour berubah |
| Pano2VR versi baru | API player bisa berubah | Robot dites ulang saat versi Pano2VR di-upgrade |

---

## 7. Urutan Pembangunan yang Disarankan

Disusun dari yang **paling cepat memberi manfaat**, agar sudah berguna sebelum produksi Badung/Papua dimulai.

1. **Inspector Statis (L1) + laporan sederhana** untuk 117 tour Semarang. Paling cepat dibuat, dan langsung memberi daftar temuan seperti bagian 1.
2. **Profil Skin Semarang + Robot Browser (L2)** untuk alur utama: muat, intro, skip, audio, bahasa, menu, popup, semua scene.
3. **Cek tautan & media (L3)**, termasuk server webview dan audio hening.
4. **Dashboard di Studio + gerbang publish + baseline kewajaran (L5).**
5. **Analisa AI (L4)**: kualitas panorama, UI, bahasa, info objek, ringkasan untuk atasan.
6. **Profil Skin Badung/Papua** begitu template wilayah tersebut siap, lalu tes semua lokasi.

Langkah 1–2 adalah inti yang paling dibutuhkan untuk produksi cepat. Langkah 5 adalah yang membuat sistem ini "canggih" di mata atasan.

---

## 8. Yang Perlu Dikonfirmasi dari Tim

1. Apakah semua wilayah baru (Badung, Papua) juga dibuat dengan **Pano2VR**? Sistem ini paling akurat untuk Pano2VR, dan alat lain butuh adapter terpisah.
2. Siapa pemegang **template skin** per wilayah, dan apakah ada daftar fitur wajib (spesifikasi) yang bisa dijadikan "Definition of Done"?
3. Tes dilakukan di **folder export lokal**, **server staging**, atau **URL live**? Disarankan staging sebelum publish dan URL live setiap malam.
4. Apakah temuan T1–T11 untuk Semarang mau langsung diperbaiki (terutama judul/OG kosong, file `.bak` dan backup di folder publik)?
