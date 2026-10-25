# Rencana Fitur Virtual Tour Kabupaten Badung, Bali

Versi 2 · 21 Oktober 2026 · Disusun untuk: atasan & klien (Pemkab Badung / Dinas Pariwisata)

> **Perubahan v2:** ditambahkan 5 fitur hasil diskusi tim (bagian 2.4–2.8): Tourism Guide (super app),
> Trip Wizard, "Beritahu Fakta Menarik", Direction interaktif via kamera, dan Discover destinasi Badung di luar lokasi.
> Direktori Layanan (2.2) dan AI Itinerary Planner (3.6) sekarang menjadi bagian dari fitur-fitur baru tersebut.

---

## 0. Ringkasan Eksekutif

Virtual tour Semarang (Lawang Sewu) sudah punya fondasi yang kuat: panorama 360°, mode drone/balkon,
mode malam, minimap, menu Layanan Publik / Informasi / Jelajah, karakter AI Guide (avatar + suara), dan Chatbot.
Untuk Badung, kita **tidak cukup hanya "menyalin Semarang"**. Badung adalah mesin pariwisata Bali
(bandara Ngurah Rai, Kuta, Seminyak, Canggu, Jimbaran, Nusa Dua, Uluwatu, GWK, Taman Ayun, Petang).
Pengunjungnya mayoritas wisatawan, banyak yang mancanegara, jadi VT Badung harus berfungsi sebagai:

1. **Etalase "wow"**: landmark 3D yang bisa diputar dan diklik per bagian, peta 3D seluruh Badung, dan drone flythrough.
2. **Alat bantu perjalanan**: direktori layanan terverifikasi, rute lari, itinerary AI, info keselamatan, kalender upacara.
3. **Bukti kejujuran destinasi**: video kreator yang sudah dikurasi "Real = Konten" (tempatnya memang sebagus di video).
4. **Sumber data untuk Pemkab**: dashboard analitik (apa yang dicari wisatawan, dari negara mana, layanan apa yang diklik).

Poin keempat yang biasanya paling menarik bagi klien pemerintah: VT berubah dari "brosur digital"
menjadi **alat kebijakan berbasis data**.

---

## 1. Audit Singkat: Apa yang Sudah Ada

| Area | Kondisi sekarang (Semarang / Studio) | Catatan untuk Badung |
|---|---|---|
| Panorama 360° (krpano) | Ada, multi-scene, hotspot | Perlu adegan pantai, pura, sunset, dan drone |
| Mode drone / balkon | Ada | Sangat cocok untuk tebing Uluwatu, GWK, dan pantai |
| Mode malam | Ada | Bagus untuk Kecak Uluwatu, Jimbaran seafood, Seminyak |
| Minimap | Ada | Perlu ditingkatkan ke peta 3D seluruh kabupaten |
| AI Guide (chat + voice + avatar) | Ada, basis pengetahuan per kota (`storage/cities/badung-bali/knowledge.json`, 11 entri) | Perlu diperkaya dan dihubungkan ke data fitur baru |
| Multi-bahasa | i18n EN sudah ada | Badung wajib ID, EN, ZH, JA, KO, RU, minimal |
| Layanan Publik / Informasi / Jelajah | Ada (menu) | Diisi ulang dengan konteks wisata Badung |
| Ikon belanja (tas) | Ada di pojok kanan atas | Bisa jadi etalase UMKM / produk desa wisata |
| Nearby, visitor tips, pertanyaan rekomendasi | Ada di pipeline Studio | Jadi fondasi Direktori Layanan |
| Analitik untuk klien | Belum ada | **Celah terbesar**, sekaligus nilai jual terbesar |
| Konten 3D objek | Belum ada | Pembeda utama ("wow factor") |
| Konten UGC / kreator | Belum ada | Perlu alur kurasi |

**Kesimpulan audit:** fondasi teknis (360 + AI) sudah matang. Yang belum ada adalah **lapisan data
wisata praktis**, **konten imersif generasi baru (3D)**, dan **lapisan analitik**.

---

## 2. Fitur Usulan Tim (Dipertajam)

Bagian 2.1–2.3 adalah usulan awal. Bagian 2.4–2.8 adalah hasil diskusi tim.

### 2.1 Badung Run Routes: Rute Lari & Jogging Terkurasi

**Ide:** wisatawan dan warga bisa memilih rute lari resmi yang aman dan indah di Badung, lengkap dengan
pratinjau 360° dan animasi rute 3D.

**Contoh rute awal (perlu disurvei ulang di lapangan):**
| Rute | Jarak perkiraan | Karakter | Waktu terbaik |
|---|---|---|---|
| Kuta – Legian – Seminyak (sepanjang pantai) | 5–8 km | Pasir/promenade, datar | Pagi, sebelum 07.30 |
| Nusa Dua Beach Promenade | 5–6 km | Paving, teduh, aman | Pagi / sore |
| Jimbaran Beach Run | 3–5 km | Pasir padat | Sunrise / sunset |
| Canggu: Batu Bolong – Echo Beach – Pererenan | 4–6 km | Campuran, sawah & pantai | Pagi |
| Lapangan Puspem Badung (Mangupraja Mandala) | Loop 1–3 km | Area kantor pemerintahan, ramai pelari lokal | Pagi / sore |
| Trail Badung Utara: Petang – Pelaga – Jembatan Tukad Bangkung | 8–15 km | Trail, menanjak, sejuk | Pagi |

**Isi per rute:**
- Peta garis rute + profil elevasi + jenis permukaan.
- Pratinjau 360° di titik start, tengah, dan finish (scene VT).
- **Animasi flyover 3D** rute (kamera terbang mengikuti jalur).
- Titik air minum, toilet, parkir, kafe, pos Balawista (penjaga pantai), titik putar balik.
- Tingkat kesulitan, estimasi waktu (pace 5/6/7 menit per km), keteduhan.
- **Data langsung:** cuaca, indeks UV, kualitas udara, jam sunrise/sunset, pasang-surut (penting untuk rute pantai).
- Peringatan: hari raya Nyepi (seluruh aktivitas luar ruang dilarang), upacara adat yang menutup jalan, ombak tinggi.
- Unduh **GPX** dan tombol "Buka di Strava / Garmin / Google Maps".
- Info komunitas lari lokal dan kalender event lari di Badung.
- AI Guide paham rute: *"Rekomendasikan rute 5 km dekat hotel saya di Seminyak yang teduh"*.

**Pengembangan lanjutan:** "Badung Run Challenge", lencana digital setelah menyelesaikan rute
(verifikasi lewat unggah GPX), bisa digandeng sponsor atau event resmi Pemkab.

### 2.2 Direktori Layanan Wisata Terverifikasi ("Badung Services")

> v2: direktori ini menjadi **mesin data** di balik menu Tourism Guide (2.4). Isinya tetap, tampilannya pindah ke super app.

**Ide:** satu tempat untuk mencari sewa mobil, sewa motor, pemandu lokal, hotel, dan layanan lain,
dengan **lencana Terverifikasi Pemkab**. Ini yang membedakan kita dari Google Maps: data resmi dan aman dari penipuan.

**Kategori:**
- Transportasi: sewa mobil (dengan/tanpa sopir), sewa motor, sewa sepeda, shuttle bandara, taksi resmi, fast boat (ke Nusa Penida, berangkat dari Badung/Sanur).
- Pemandu: pemandu wisata berlisensi (HPI), pemandu bahasa asing, pemandu trekking/budaya.
- Akomodasi: hotel, villa, homestay, desa wisata.
- Aktivitas: sekolah selancar, diving/snorkeling, spa, kelas memasak, pertunjukan (Kecak Uluwatu, dsb).
- Keuangan: **money changer resmi berizin** (sering jadi sumber penipuan wisatawan), ATM.
- Darurat & publik: rumah sakit/klinik, polisi pariwisata, imigrasi, konsulat, SPBU, toilet umum, parkir.

**Fitur per listing:**
- Lencana: ✅ Terverifikasi (izin usaha/NIB dicek Dinas), 🌐 bahasa yang dilayani, ♿ ramah disabilitas.
- Kisaran harga ("Sewa motor matic: ± Rp70–100 rb/hari", perlu dicek pengelola).
- Tombol **WhatsApp langsung** (dengan pesan template multi-bahasa), telepon, rute Google Maps.
- Tampil sebagai pin di peta dan di dalam panorama 360 (misal: hotspot "Sewa motor terdekat").
- Filter: jarak dari scene saat ini, harga, bahasa, rating, buka sekarang.
- Rating dan ulasan (dari Google Places atau ulasan internal yang dimoderasi).
- Panduan anti-penipuan: tarif wajar, cara memilih money changer resmi, aturan helm & SIM internasional.

**Integrasi AI Guide:** *"Carikan sewa motor dekat Kuta yang bisa antar ke hotel"* → AI menjawab
dari direktori resmi, bukan dari internet bebas.

**Model bisnis (opsional, untuk diskusi dengan klien):** listing dasar gratis untuk pelaku usaha terverifikasi,
listing unggulan berbayar; atau sepenuhnya program layanan publik Pemkab. Setiap klik tercatat, jadi
Pemkab bisa melaporkan "VT ini menghasilkan X kontak ke UMKM lokal".

### 2.3 "Real vs Konten": Video Kreator Terkurasi

**Ide:** menampilkan video TikTok, Instagram Reels, dan YouTube Shorts tentang Badung **yang sudah lolos kurasi**:
tempatnya memang sebagus di video, tanpa filter berlebihan atau sudut kamera yang menyesatkan.

**Cara tampil di VT:**
- **Hotspot kreator di dalam panorama:** ikon 📱 di titik video diambil. Klik, video muncul di samping panorama.
- **Mode "Reel vs Real" (fitur wow):** layar terbelah, video kreator di kiri dan panorama 360 di sudut yang sama di kanan.
  Pengunjung bisa membuktikan sendiri bahwa tempatnya memang sama bagusnya.
- **Lencana "Sesuai Kenyataan ✓"** dan skor kesesuaian.
- Galeri per destinasi, per tema (sunset, kuliner, pantai, budaya), dan per bahasa kreator.
- Tombol "Video lainnya dari kreator ini" + kredit akun (menguntungkan kreator, mereka jadi mau ikut).

**Alur kurasi di Studio (modul baru):**
1. **Masuk:** URL ditempel tim, diajukan kreator lewat formulir, atau ditemukan lewat hashtag (#BadungBali, #VisitBadung).
2. **Cek otomatis:** ambil metadata via oEmbed resmi (judul, akun, thumbnail), cek tautan masih aktif, deteksi bahasa.
3. **Cek manual oleh kurator (checklist):**
   - Lokasi benar di Badung dan cocok dengan scene VT.
   - Visual realistis: tidak ada filter warna ekstrem, AI edit, atau sudut yang menyembunyikan kondisi sebenarnya.
   - Info akurat (harga, aturan, jam buka). Konten tidak melanggar adat (misal pose tidak sopan di pura).
   - Tidak ada promosi ilegal, konten berbahaya (misal berdiri di tepi tebing), atau isu SARA.
4. **Skor kesesuaian** (1–5) + catatan, lalu dipasang ke scene/hotspot.
5. **Izin kreator** dicatat (persetujuan tampil + kredit).
6. **Kedaluwarsa:** ditinjau ulang tiap 6–12 bulan karena tempat bisa berubah. Tautan mati otomatis disembunyikan.

**Aturan hukum penting:** **embed saja, jangan unduh lalu unggah ulang**, untuk menghindari masalah hak cipta.
Gunakan embed resmi tiap platform.

**Pengembangan lanjutan:** "Badung Creator Partner", program kreator resmi dengan akses ke lokasi dan
lencana di VT. Pengunjung juga bisa memberi suara "Apakah tempat ini sesuai ekspektasimu?"

### 2.4 ⭐ Menu "Tourism Guide": Super App Wisatawan

**Ide:** satu menu yang terasa seperti aplikasi terpisah (layar penuh, bisa dibuka di atas panorama), berisi
**semua yang dibutuhkan wisatawan selama di Badung**. Pengunjung tidak perlu bolak-balik ke Google Maps,
aplikasi hotel, dan grup WhatsApp. AI Guide tersedia di dalamnya.

**Tampilan utama (beranda super app):**
```
┌──────────────────────────────────────────────┐
│ 📍 Kamu di: Pantai Kuta   ☀ 31°C  🌅 18:21    │
│ [ 🔍 Cari hotel, sewa motor, apotek...     ] │
├──────────────────────────────────────────────┤
│ 🏨 Menginap  🛵 Sewa Motor  🚗 Sewa Mobil     │
│ 🧭 Pemandu   🍽 Kuliner     🎟 Tiket & Aktivitas│
│ 💱 Uang      📶 SIM/eSIM    🏥 Darurat         │
│ ✈ Bandara    🕌 Ibadah      ••• Lainnya        │
├──────────────────────────────────────────────┤
│ TERDEKAT DARI SINI                           │
│ [Hotel A 350 m] [Sewa motor B 600 m] [...]   │
├──────────────────────────────────────────────┤
│ ✨ Trip Saya (dari Trip Wizard)  → 3 hari     │
├──────────────────────────────────────────────┤
│              ( ✦ Tanya AI Guide )            │
└──────────────────────────────────────────────┘
```

**Kategori layanan (dari usulan tim + tambahan):**
| Kelompok | Isi |
|---|---|
| **Menginap** | Hotel, resort, villa, homestay, hostel, desa wisata. Filter bintang, harga, area, ramah keluarga |
| **Transportasi** | Sewa motor, sewa mobil (lepas kunci / dengan sopir), shuttle bandara, taksi resmi, sewa sepeda, fast boat, info zona jemput transportasi online (beberapa kawasan punya aturan sendiri, perlu dicek) |
| **Pemandu & Aktivitas** | Pemandu berlisensi, surfing, diving, spa, kelas memasak, trekking, pertunjukan |
| **Tiket** | Tiket masuk destinasi (GWK, Uluwatu, dsb) via mitra resmi, jadwal Kecak |
| **Kuliner** | Restoran, warung lokal, seafood Jimbaran, filter halal / vegetarian / vegan |
| **Uang** | Money changer resmi, ATM, **kurs live & konverter mata uang** |
| **Konektivitas** *(tambahan)* | SIM card/eSIM, Wi-Fi publik, coworking (Canggu terkenal untuk digital nomad) |
| **Bandara & Dokumen** *(tambahan)* | Info Bandara Ngurah Rai, waktu tempuh ke tiap area, info visa & pungutan wisatawan asing (cek ulang regulasi), kantor imigrasi |
| **Kesehatan & Darurat** | RS, klinik, apotek 24 jam, polisi pariwisata, SOS, konsulat |
| **Ibadah** *(tambahan)* | Masjid, gereja, vihara, pura untuk bersembahyang |
| **Kebutuhan harian** *(tambahan)* | Laundry, minimarket, SPBU, toilet umum, parkir |
| **Bahasa** *(tambahan)* | Frasa cepat Bahasa Indonesia & Bali dengan audio ("Suksma" = terima kasih) |
| **Pengaduan Wisatawan** *(tambahan, bernilai untuk Pemkab)* | Lapor penipuan, pungli, kebersihan, kerusakan fasilitas, diteruskan ke dinas terkait |
| **Barang Hilang** *(tambahan)* | Kontak lost & found bandara, polisi, pengelola destinasi |

**Fitur "Terdekat":**
- Berdasarkan **lokasi GPS** pengunjung (jika diizinkan) **atau** berdasarkan scene 360 yang sedang dibuka.
  Contoh: membuka panorama Pantai Kuta, lalu menu Terdekat menampilkan hotel/sewa motor di sekitar Pantai Kuta.
- Urut berdasarkan jarak, waktu tempuh (jalan kaki/motor/mobil), dan status "buka sekarang".
- Tombol "Arahkan saya" langsung menyambung ke Direction interaktif (2.7).

**Halaman detail tiap listing:**
foto, panorama 360 (jika mitra punya), lencana terverifikasi, kisaran harga, bahasa yang dilayani, jam buka,
rating, WhatsApp/telepon, tombol **"Tanya AI tentang tempat ini"**, tombol **"Tambah ke Trip Saya"**.

**AI Guide di dalam super app (kontekstual):**
- AI tahu kamu sedang melihat kategori/listing apa.
  Contoh: dari halaman sewa motor, *"Apakah saya butuh SIM internasional?"* dijawab sesuai konteks.
- Bisa melakukan aksi: *"Carikan hotel keluarga di Nusa Dua di bawah 1,5 juta"* → AI menampilkan kartu listing yang bisa diklik, bukan hanya teks.
- AI hanya merekomendasikan listing dari data resmi, sehingga tidak mengarang nama usaha.

**"Trip Saya" (tambahan):** tempat menyimpan listing favorit dan hasil Trip Wizard (2.5). Tersimpan di perangkat
(tanpa login) dan bisa dibagikan via tautan/WhatsApp.

### 2.5 ⭐ Trip Wizard: Asisten Perencanaan Perjalanan

**Ide:** beberapa pertanyaan singkat dan menyenangkan (bukan formulir kaku). Di akhir, AI memberi
**rekomendasi personal**: sebaiknya menginap di area mana, berkunjung ke mana tiap hari, naik apa, dan apa yang perlu diperhatikan.

**Kapan muncul:**
- Saat pertama kali membuka VT: kartu sambutan "Rencanakan trip Bali-mu dalam 30 detik" (bisa dilewati).
- Dari menu Tourism Guide, tombol di AI Guide, dan tombol mengambang "✨ Rencanakan Trip".

**Daftar pertanyaan (bisa diatur di Studio):**
| # | Pertanyaan | Bentuk jawaban | Dipakai untuk |
|---|---|---|---|
| 1 | Kapan kamu ke Bali? | Pilih tanggal / bulan / "belum tahu" | Cek Nyepi, Galungan, musim hujan/kemarau, musim ramai |
| 2 | Berapa lama? | 1–2 hari · 3–4 · 5–7 · >1 minggu | Jumlah hari itinerary |
| 3 | Pergi dengan siapa? | Sendiri · pasangan · keluarga + anak · teman · rombongan · lansia | Gaya aktivitas & area menginap |
| 4 | Apa yang kamu suka? (boleh lebih dari satu) | Pantai · budaya & pura · kuliner · hiburan malam · wellness/spa · surfing · alam & desa · belanja · olahraga/lari | Pilihan destinasi |
| 5 | Sudah punya penginapan? | Sudah (pilih area/hotel) · belum | Jika belum, AI merekomendasikan area |
| 6 | Budget per malam? | Hemat · menengah · premium · mewah | Filter akomodasi |
| 7 | Mau keliling naik apa? | Sewa motor · mobil + sopir · taksi online · tur rombongan · belum tahu | Saran transportasi & estimasi waktu |
| 8 | Tiba lewat mana & jam berapa? | Bandara (jam) · pelabuhan · sudah di Bali | Rencana hari pertama |
| 9 | Ritme perjalanan? | Santai · seimbang · padat | Jumlah tempat per hari |
| 10 | Kebutuhan khusus? | Halal · vegetarian · kursi roda · bawa bayi · hindari keramaian | Filter & catatan |

Desain: satu pertanyaan per layar, tombol pilihan bergambar (bukan dropdown), bar progres, suara AI Guide
membacakan pertanyaan, maksimal ±60 detik. Pertanyaan 8–10 bisa dibuat opsional.

**Hasil akhir (dijawab AI, disajikan sebagai kartu, bukan teks panjang):**
1. **Rekomendasi area menginap + alasannya.** Contoh pengetahuan dasar yang diberikan ke AI (perlu divalidasi Dispar):
   | Area | Cocok untuk |
   |---|---|
   | Kuta / Tuban | Dekat bandara, budget, ramai, pertama kali ke Bali |
   | Legian / Seminyak | Kuliner, beach club, belanja, pasangan |
   | Canggu / Pererenan | Surfing, kafe, digital nomad, anak muda |
   | Jimbaran | Seafood sunset, keluarga, dekat GWK |
   | Nusa Dua | Resor, keluarga, tenang, MICE |
   | Uluwatu / Pecatu | Tebing, surfing, bulan madu, Kecak |
   | Petang / Badung Utara | Alam sejuk, desa wisata, trekking |
2. **Itinerary per hari:** urutan tempat dengan jam yang disarankan (misal Uluwatu sore hari untuk sunset + Kecak),
   estimasi waktu tempuh, dan tiap tempat bisa diklik untuk **langsung masuk ke panorama 360**.
3. **Saran transportasi:** contoh *"Rute ini cocok dengan mobil + sopir; motor tidak disarankan untuk keluarga dengan anak kecil."*
4. **Peringatan penting:** *"Tanggal kamu bertepatan dengan Nyepi, bandara tutup 24 jam"* dan musim hujan.
5. **Listing pilihan** dari Tourism Guide (hotel, sewa kendaraan, tiket) dengan tombol WhatsApp.
6. **Estimasi budget kasar** dan **daftar bawaan** (sarung untuk pura, sunblock, adaptor colokan).

**Setelah hasil keluar:**
- Edit lewat obrolan: *"Hari kedua buat lebih santai"*, *"ganti Seminyak dengan yang lebih sepi"*. AI memperbarui itinerary.
- Simpan ke Trip Saya, unduh PDF, kirim ke WhatsApp, buka sebagai rute Google Maps.
- Itinerary juga memberi data ke dashboard Pemkab (anonim): misal "40% pengunjung VT berencana menginap di Kuta Selatan".

**Catatan teknis:**
- Pertanyaan dan pilihan disimpan sebagai konfigurasi per kota (bisa dipakai kota lain).
- AI diminta mengeluarkan **format terstruktur** (area, hari, tempat, listing) yang divalidasi sistem:
  tempat dan listing harus ada di data kita. Jika AI gagal, ada **rekomendasi berbasis aturan** sebagai cadangan.
- Ini berbeda dari wizard pembuatan tour yang sudah ada di Studio. Yang ini wizard untuk pengunjung.

### 2.6 ⭐ Tombol "Beritahu Fakta Menarik"

**Ide:** di menu Informasi (dan di panel AI Guide) ada tombol **"✨ Beritahu Fakta Menarik"**. Saat ditekan,
muncul kartu "Tahukah kamu?" dengan animasi, dibacakan AI Guide, berisi fakta mengejutkan tentang **lokasi/scene yang sedang dibuka**.

**Contoh gaya (isi wajib diverifikasi sebelum tayang):**
- GWK: *"Patung ini termasuk yang tertinggi di dunia, dan pembangunannya memakan waktu lebih dari 20 tahun."*
- Uluwatu: *"Kera di sini dikenal 'menyandera' kacamata dan ponsel wisatawan, lalu mau menukarnya dengan makanan. Perilaku ini bahkan diteliti ilmuwan."*
- Taman Ayun: *"Pura ini termasuk bagian dari Warisan Budaya Dunia UNESCO karena terkait sistem pengairan Subak."*

**Aturan agar aman (penting, karena AI cenderung "mengarang" saat diminta sesuatu yang mengejutkan):**
- AI **tidak membuat fakta bebas**. AI mengambil dari **bank fakta terkurasi** per scene, lalu menyampaikannya dengan gaya menarik dan sesuai bahasa pengunjung.
- Alur di Studio: AI mengusulkan kandidat fakta dari knowledge dan sumber, kurator memeriksa dan menyetujui, lalu fakta tayang. Setiap fakta punya sumber.
- Fakta bertipe mitos/legenda diberi label **"Legenda"** agar tidak dianggap sejarah.
- Hindari fakta sensitif (tragedi, hal sakral) kecuali dengan narasi yang hormat.

**Detail interaksi:**
- Tidak mengulang fakta yang sama dalam satu sesi. Tombol "Satu lagi!" untuk fakta berikutnya.
- Kategori acak: sejarah, angka, alam/sains, budaya, legenda, "di balik layar".
- Tombol **Bagikan**: kartu fakta jadi gambar siap Instagram Story/WhatsApp (bingkai Badung + tautan VT).
- Tombol "Sumber" kecil untuk pengunjung yang ingin membaca lebih lanjut.
- Opsional: fakta muncul otomatis jika pengunjung diam lama di satu scene ("Psst… tahukah kamu?").
- Target konten: minimal 5 fakta per destinasi utama saat peluncuran.

### 2.7 Direction Interaktif via Kamera (AR Navigation)

**Ide:** pengunjung yang **sedang berada di lokasi** membuka kamera HP. Panah dan penanda arah muncul
di atas gambar kamera, mengarahkan ke tujuan (pintu masuk, toilet, panggung Kecak, parkir, hotel terdekat, atau spot foto).

**Mode yang diusulkan:**
| Mode | Cara kerja | Kegunaan |
|---|---|---|
| **A. Panah AR luar ruang** | Kamera + GPS + kompas HP. Panah 3D dan jarak ("120 m ke Panggung Kecak") ditumpuk di atas kamera | Di dalam kawasan GWK, Uluwatu, sepanjang pantai, ke listing Tourism Guide |
| **B. Label sekitar ("Lihat Sekeliling")** | Arahkan kamera ke sekeliling, label tempat mengambang sesuai arahnya ("Pura Uluwatu 300 m →") | Orientasi cepat, terasa futuristik |
| **C. Kenali dengan kamera** *(tambahan)* | Foto bangunan/patung, AI vision mengenali objek, lalu membuka kartu info + fakta menarik | Wisatawan bertanya "ini apa?" |
| **D. Panah arah di dalam panorama** *(tambahan, untuk pengunjung online)* | Di VT, pilih tujuan, lalu panah di panorama memandu berpindah scene demi scene | Pengunjung bisa "berlatih" rute sebelum datang |
| **Cadangan** | Peta 2D + panah kompas besar | Jika kamera/sensor tidak didukung |

**Realita teknis yang perlu disampaikan ke klien:**
- Akurasi GPS di HP sekitar **5–15 meter** di luar ruang, sehingga cocok untuk area terbuka/kawasan wisata
  dan kurang akurat di dalam gedung atau gang sempit. Untuk area tertutup, dipakai **titik QR/penanda** sebagai kalibrasi.
- Di iPhone, Safari tidak mendukung WebXR penuh. Solusinya: kamera + sensor orientasi biasa (berjalan di Android & iOS,
  memerlukan izin kamera, lokasi, dan gerak). Untuk akurasi tingkat tinggi (Visual Positioning, misal ARCore Geospatial),
  kemungkinan perlu aplikasi native, jadi menjadi pengembangan lanjutan.
- Keamanan: peringatan "jangan dipakai sambil berkendara" dan peringatan di area tebing/pantai.
- **MVP yang realistis:** satu kawasan (misal GWK atau Uluwatu) dengan 10–20 titik tujuan yang dipetakan manual, plus mode D di VT.
  Setelah terbukti, perluas ke kawasan lain dan ke listing Tourism Guide.

### 2.8 ⭐ Discover: Jelajahi Badung di Luar Lokasi Ini

**Masalah sekarang:** menu Jelajah/Discover hanya berisi daftar scene di **lokasi yang sedang dibuka**
(misal hanya scene-scene di dalam kawasan GWK).
**Ide v2:** Discover menjadi **pintu ke destinasi lain di Kabupaten Badung** di luar lokasi tersebut.
Pengunjung yang sedang menjelajah GWK, misalnya, diajak "melanjutkan perjalanan" ke Uluwatu, Pantai Melasti,
Jimbaran, Nusa Dua, hingga Badung Utara. **Cakupannya tetap Badung**, sehingga seluruh destinasi Badung saling mempromosikan.

**Komponen Discover:**
- **"Dari sini, lanjut ke…"**: rekomendasi destinasi Badung terdekat dari lokasi saat ini, lengkap dengan jarak & waktu tempuh.
  Contoh dari GWK: Pantai Melasti (±10 menit), Jimbaran (±15 menit), Pura Uluwatu (±20 menit), Nusa Dua (±20 menit). Perkiraan, perlu dicek.
- **Feed visual bergaya "stories"** (geser vertikal seperti TikTok): tiap kartu memutar pratinjau 360 atau drone
  destinasi Badung lain secara otomatis, dengan tombol "Masuk ke VT".
- **Jelajah per kecamatan:** Kuta Selatan · Kuta · Kuta Utara · Mengwi · Abiansemal · Petang. Tiap kecamatan punya kartu sampul
  dan daftar destinasinya (mendukung pemerataan kunjungan ke Badung tengah & utara).
- **Koleksi tematik se-Badung:** "Sunset Terbaik", "Pantai Tersembunyi", "Pura Wajib Dikunjungi", "Ramah Keluarga",
  "Kuliner Legendaris", "Surga Peselancar", "Sejuk di Badung Utara", "Spot Foto Instagramable".
- **Cincin jarak:** dari lokasi sekarang, kelompokkan "15 menit · 30 menit · 1 jam" (semua masih di Badung).
- **Rute tematik antar-lokasi:** misal "Satu hari di Kuta Selatan: GWK → Melasti → Uluwatu (sunset + Kecak) → Jimbaran (makan malam)".
- **"Mirip dengan yang kamu suka":** rekomendasi berdasarkan scene yang lama ditonton dan jawaban Trip Wizard.
- **Sedang tren:** berdasarkan data analitik dan video kreator terkurasi (lencana Reel vs Real).
- **Musiman:** "Sedang berlangsung di Badung: Melasti / festival / event bulan ini".
- **Tombol "Kejutkan aku 🎲"**: teleport acak ke destinasi Badung yang belum pernah dibuka.
- **Mode Peta:** Discover bisa dilihat sebagai peta (terhubung dengan Peta 3D Badung, 3.2).
- Setiap kartu: pratinjau 360, jarak & waktu tempuh, jam terbaik, harga tiket kisaran, tombol "Tambah ke Trip Saya",
  tombol "Fakta Menarik", dan tombol "Arahkan saya".

**Nilai untuk klien:** pengunjung yang datang karena satu destinasi populer (GWK, Kuta) ikut melihat destinasi Badung lain,
termasuk yang belum ramai. Data Discover (destinasi mana yang paling sering diklik, dari lokasi mana) masuk ke dashboard Pemkab.

---

## 3. Fitur Tambahan Rekomendasi (Untuk Efek "WOW")

### 3.1 ⭐ Landmark 3D Interaktif (Digital Twin per Objek)

Ini fitur yang paling "wow" saat demo.

**Konsep:** landmark ikonik dipindai menjadi model 3D yang bisa diputar, diperbesar, dan **diklik per bagian**.
Setiap bagian punya kartu info dan narasi AI.

**Kandidat landmark Badung:**
| Landmark | Lokasi | Objek yang bisa dianotasi |
|---|---|---|
| Patung Garuda Wisnu Kencana (GWK) | Ungasan/Jimbaran | Kepala Wisnu, Garuda, tinggi total, material, sejarah pembangunan |
| Pura Luhur Uluwatu* | Pecatu | Gapura candi bentar, tebing, panggung Kecak, kera |
| Pura Taman Ayun* (Warisan Dunia UNESCO, bagian Subak Bali) | Mengwi | Meru bertingkat, kolam, wantilan, struktur tri mandala |
| Monumen Tragedi Kemanusiaan (Ground Zero) | Kuta | Prasasti nama korban, makna simbolik |
| Jembatan Tukad Bangkung | Petang | Struktur jembatan, panorama lembah |
| Pura Sada Kapal* | Kapal, Mengwi | Prasada, arsitektur |
| Puspem Badung | Mangupraja Mandala | Ikon pemerintahan (cocok untuk klien) |

*Pura adalah tempat suci. **Wajib izin desa adat/pengempon pura**, dan area utama (utama mandala) sebaiknya
tidak dipindai atau tidak dipublikasikan. Ini perlu dikomunikasikan ke klien sejak awal.

**Fitur di dalam viewer 3D:**
- Klik bagian objek, kartu info muncul (nama, fungsi, ukuran, makna filosofis), dibacakan AI Guide.
- **Mode "Bongkar" (exploded view):** misal meru dipisahkan per tingkat, tiap tingkat dijelaskan maknanya.
- **Timeline pembangunan:** slider tahun (contoh GWK: perencanaan, pembangunan bertahap, hingga rampung).
- **Perbandingan skala:** siluet manusia, bus, atau Monas di samping GWK.
- **AR "Lihat di ruanganmu":** buka di HP, GWK mini muncul di meja (WebXR / iOS Quick Look). Sangat efektif saat presentasi.
- Tombol "Masuk ke 360°" untuk berpindah dari model 3D ke panorama di titik yang sama.

**Teknologi (realistis untuk tim kecil):**
- Pemindaian: foto drone + darat lalu **Gaussian Splatting** (tampilan fotorealistis, ringan di web) atau fotogrametri (mesh).
- Viewer: `<model-viewer>` (Google, mendukung AR) untuk mesh, atau viewer three.js khusus untuk splat.
- Optimasi: kompresi model (Draco/KTX2), muat bertahap. Target di bawah 15–25 MB per landmark agar lancar di HP.

### 3.2 ⭐ Peta 3D Seluruh Badung

- Peta 3D fotorealistik (Google Photorealistic 3D Tiles / CesiumJS; cakupan area Bali perlu dicek dulu).
- Semua scene VT, landmark 3D, rute lari, dan layanan direktori muncul sebagai pin di peta.
- **Mode "Terbang ke…":** kamera terbang dari bandara Ngurah Rai ke GWK, lalu Uluwatu, lalu Nusa Dua secara sinematik.
  Cocok sebagai intro di halaman depan.
- Lapisan peta yang bisa dinyalakan/dimatikan: pantai, pura, kuliner, rute lari, jalur evakuasi tsunami, desa wisata.
- Pembagian per kecamatan (Kuta Selatan, Kuta, Kuta Utara, Mengwi, Abiansemal, Petang) sesuai struktur administrasi Badung.

### 3.3 ⭐ Sunset & Golden Hour Finder

Badung terkenal dengan sunset (Kuta, Jimbaran, Uluwatu, Canggu).
- Hitung mundur ke sunset hari ini + jam golden hour.
- **Simulasi posisi matahari di panorama 360:** geser jam, langit dan arah matahari ikut berubah.
  Pengunjung tahu dari arah mana matahari terbenam di spot tertentu.
- Rekomendasi spot sunset terbaik hari ini berdasarkan cuaca/awan.
- Integrasi dengan kalender: jadwal tari Kecak sunset di Uluwatu (cek ulang ke pengelola).

### 3.4 Kalender Budaya, Upacara & Nyepi

Fitur ini sangat penting bagi wisatawan asing dan sering terlewat.
- Kalender Bali (pawukon/saka): Galungan, Kuningan, Nyepi, Melasti, Ogoh-ogoh, odalan pura besar.
- **Peringatan Nyepi:** hitung mundur, penjelasan aturan (bandara tutup, tidak boleh keluar hotel, lampu redup),
  saran persiapan. AI Guide otomatis menyebutkannya bila tanggal kunjungan bertepatan.
- Info penutupan jalan karena upacara (bila datanya tersedia dari desa adat/Dishub).
- Melasti di pantai dan ogoh-ogoh sebagai **konten 360 musiman** (ditambahkan saat acara berlangsung).

### 3.5 Panduan Etika & "Mode Pura"

- Saat masuk scene pura, muncul kartu etika: pakai kain/sarung dan selendang, perempuan haid tidak masuk,
  jangan memanjat/duduk di bangunan suci, jangan memotret orang sembahyang dari dekat.
- Panduan perilaku wisatawan mancanegara versi Pemprov Bali (Do & Don't), disajikan ramah dan multi-bahasa (cek ulang regulasi terbaru).
- **Info pungutan wisatawan asing** Bali dan cara membayarnya (nominal dan mekanisme dicek ulang ke sumber resmi).
- Kuis singkat "Siap berkunjung ke pura?" (gamifikasi, sekaligus edukasi).

### 3.6 AI Itinerary Planner

> v2: digabung ke dalam **Trip Wizard (2.5)**. Poin di bawah tetap berlaku sebagai mesin itinerary-nya.

- Wisatawan mengetik: *"3 hari di Badung, budget menengah, suka pantai dan kuliner, bawa anak"*.
- AI menyusun rencana per hari: urutan lokasi, estimasi waktu tempuh (lalu lintas Badung terkenal padat),
  jam terbaik (sunset, hindari macet), plus layanan dari direktori (sewa mobil, restoran).
- Setiap item bisa diklik untuk **langsung masuk ke panorama 360** lokasi tersebut.
- Ekspor ke PDF, WhatsApp, Google Maps (multi-stop), dan kalender.
- Data yang dipakai: scene VT, direktori, rute lari, kalender upacara. Semua data internal, sehingga jawaban akurat.

### 3.7 Info Keselamatan & Darurat

Badung punya garis pantai panjang dan kawasan rawan tsunami. Fitur ini menunjukkan VT yang bertanggung jawab.
- Tombol **SOS**: nomor darurat (112), polisi pariwisata, rumah sakit terdekat, konsulat.
- **Jalur evakuasi tsunami** dan titik kumpul (data BPBD Badung) di peta dan sebagai panah di dalam panorama pantai.
- Peringatan arus balik (rip current), arti bendera merah/kuning di pantai, lokasi pos Balawista.
- Info kesehatan: apotek 24 jam, klinik ramah wisatawan.

### 3.8 Lapisan Data Langsung (Live Layer)

- Cuaca, UV, kualitas udara, pasang-surut, **prakiraan ombak** (untuk peselancar di Kuta, Canggu, Uluwatu).
- Tingkat keramaian perkiraan per destinasi (dari data kunjungan atau pola waktu).
- CCTV publik bila tersedia dari Dishub/Diskominfo Badung (pilih titik yang aman privasi).
- Status lalu lintas menuju lokasi, waktu tempuh dari bandara.

### 3.9 "Dulu & Kini" (Time Travel)

- Slider foto lama vs panorama sekarang: Pantai Kuta tempo dulu, Legian, Jimbaran, bandara lama.
- Sumber foto arsip yang domain publik/berlisensi (arsip kolonial, Perpustakaan Nasional, koleksi keluarga dengan izin).
- Narasi AI: perubahan Badung dari desa nelayan menjadi pusat pariwisata dunia.

### 3.10 Soundscape & Narasi Imersif

- Suara ambien per scene: ombak, gamelan, kecak, burung di Petang, suara pasar.
- Audio spasial (suara ombak terdengar dari arah laut saat panorama diputar).
- Narasi AI Guide dengan suara dan bahasa sesuai pasar wisatawan Badung:
  **Indonesia, Inggris (termasuk aksen Australia), Mandarin, Jepang, Korea, Rusia, Prancis, Jerman, Hindi**.
- Avatar AI Guide berbusana adat Bali (pengganti kebaya merah Semarang), tetap konsisten dengan identitas Badung.

### 3.11 Badung Explorer Passport (Gamifikasi + Phygital)

- Pengunjung mengumpulkan **stempel digital** per destinasi (virtual: kunjungi scene; fisik: pindai QR di lokasi).
- Kuis singkat per landmark, hadiahnya lencana.
- Stempel lengkap ditukar voucher dari mitra direktori (diskon sewa motor, kopi Pelaga, dsb).
- Bagus untuk mendorong kunjungan ke **Badung Utara** (Petang, Abiansemal) yang belum seramai Kuta Selatan,
  sejalan dengan isu pemerataan pariwisata.

### 3.12 Desa Wisata & UMKM Spotlight

- Halaman khusus desa wisata Badung (misal Pelaga, Bongkasa Pertiwi, Munggu, Cemagi; daftar resmi dari Dispar).
- Tur 360 per desa + cerita warga + paket aktivitas (rafting, agrowisata kopi, membuat canang).
- Ikon tas belanja di VT jadi **etalase UMKM**: kerajinan, kopi, produk desa, dengan tautan ke WhatsApp/marketplace penjual.

### 3.13 MICE & Event Hub

Badung (terutama Nusa Dua) adalah pusat MICE nasional dan internasional.
- Tur 360 venue konvensi dan ballroom hotel, dengan info kapasitas, layout, dan kontak.
- Kalender event (konser, festival, lomba lari, konferensi).
- Mode "Untuk Penyelenggara Acara": paket venue + hotel + transportasi dari direktori.

### 3.14 Kartu Pos & Share Generator

- Tombol "Bagikan pemandangan ini": tangkap sudut panorama saat ini, beri bingkai khas Badung + caption AI multi-bahasa.
- Format siap pakai untuk Instagram Story, TikTok, dan WhatsApp, dengan tautan balik ke VT.
- **Penanda Spot Foto Terbaik** di dalam panorama, plus jam terbaik untuk memotretnya.
- Efek viral: setiap share adalah promosi gratis untuk Badung.

### 3.15 Mode Kiosk Bandara & Pusat Informasi

- Bandara I Gusti Ngurah Rai berada di Badung, jadi ini peluang besar.
- Mode kiosk layar sentuh/layar besar: antarmuka sederhana, otomatis reset, AI Guide berbicara.
- QR code di kiosk untuk melanjutkan di HP pengunjung (rencana perjalanan ikut terbawa).
- Bisa juga dipasang di hotel, pusat informasi turis, dan Puspem.

### 3.16 Mode VR & Aksesibilitas

- Mode VR headset (WebXR; krpano mendukung) untuk pameran dan travel fair (ITB Berlin, ATF, WTM).
- Aksesibilitas: info ramah kursi roda per destinasi, teks besar, kontras tinggi, subtitle untuk narasi, navigasi keyboard.
- Mode hemat data/offline (PWA) untuk wisatawan dengan sinyal lemah di Badung Utara.

### 3.17 ⭐ Dashboard Analitik untuk Pemkab (Nilai Jual ke Klien)

Ini yang paling meyakinkan pengambil keputusan.
- **Heatmap minat:** scene, landmark, dan hotspot paling banyak dilihat dan paling lama ditonton.
- **Apa yang ditanyakan wisatawan ke AI:** topik terbanyak, pertanyaan yang belum terjawab (jadi masukan kebijakan dan konten).
- Asal negara dan bahasa pengunjung, perangkat, jam akses.
- Konversi: klik ke layanan direktori, WhatsApp UMKM, unduhan rute lari, share.
- Performa video kreator (mana yang paling mendorong orang menjelajah).
- Laporan bulanan otomatis (PDF) untuk Bupati/Kadis.
- Semua anonim dan sesuai UU Pelindungan Data Pribadi (tanpa menyimpan identitas pengunjung).

---

## 4. Pemetaan ke Navigasi VT (Agar Tidak Ramai)

Navigasi bawah Semarang (Menu, Layanan Publik, Kategori, Informasi, Jelajah) dipertahankan polanya,
isinya disesuaikan:

| Menu | Isi untuk Badung |
|---|---|
| **Discover** (ganti "Jelajah", 2.8) | Destinasi Badung lain di luar lokasi ini: "Dari sini, lanjut ke…", per kecamatan, feed stories, koleksi tematik, cincin jarak, Peta 3D, Landmark 3D, Dulu & Kini, Passport, "Kejutkan aku" |
| **Kategori** | Pantai · Pura & Budaya · Kuliner · Alam & Desa Wisata · Belanja · MICE |
| **Tourism Guide** (ganti "Layanan Publik", 2.4) | Super app: menginap, transportasi, pemandu, tiket, uang, darurat, terdekat, Trip Saya, AI Guide |
| **Informasi** | **✨ Beritahu Fakta Menarik** (2.6), Kalender Budaya/Nyepi, Etika & Pungutan, Cuaca/Ombak/Sunset |
| **Aktif** (baru) | Rute Lari, Sunset Finder, Spot Foto |
| **Tombol mengambang** | ✨ Rencanakan Trip (Trip Wizard, 2.5) · 📷 Arahkan Saya (AR, 2.7) · Reel vs Real |
| **AI Guide / Chatbot** | Tetap, tetapi sekarang "tahu" semua data di atas dan bisa membuka menu-menu tersebut |

Agar tidak terlalu ramai di HP: navigasi bawah maksimal 5 ikon, tombol mengambang digabung menjadi satu tombol "✨"
yang membuka 3 pilihan.

---

## 5. Integrasi dengan Sistem Studio yang Ada

Agar tidak membangun dari nol, semua fitur baru menjadi **modul data per kota** di Studio, lalu otomatis dibaca AI Guide:

```
storage/cities/badung-bali/
  knowledge.json      (sudah ada; diperkaya)
  directory.json      Direktori layanan + status verifikasi
  routes.json         Rute lari (GPX, elevasi, titik fasilitas)
  creator-videos.json Video kurasi + skor + izin + tanggal tinjau ulang
  models3d.json       Landmark 3D + anotasi per bagian
  calendar.json       Kalender upacara/event
  safety.json         Darurat, evakuasi, pos Balawista
  trip-wizard.json    Pertanyaan, pilihan, & aturan rekomendasi area (Trip Wizard)
  facts.json          Bank fakta menarik per scene + sumber + status kurasi
  discover.json       Koleksi tematik, urutan, tautan ke tour/kota lain
  ar-waypoints.json   Titik tujuan AR per kawasan (koordinat, arah, QR kalibrasi)
```

- Setiap modul punya halaman edit di Studio (seperti modul tour sekarang), riwayat perubahan, dan status "perlu ditinjau ulang".
- AI Guide memakai semua data ini sebagai sumber jawaban (RAG), sehingga tidak mengarang info layanan atau harga.
- Modul bersifat generik, jadi bisa dipakai ulang untuk kota lain (Semarang, Jakarta, Labuan Bajo, dst).
  **Investasi Badung menjadi produk untuk semua klien berikutnya.**

---

## 6. Risiko & Mitigasi

| Risiko | Mitigasi |
|---|---|
| Hak cipta video kreator | Embed resmi saja, izin tertulis/digital, kredit jelas, mekanisme take-down |
| Kesakralan pura | Izin desa adat & PHDI, hindari utama mandala, konsultasi narasi dengan tokoh adat |
| Data direktori kedaluwarsa / penipuan | Verifikasi oleh Dispar, tinjau ulang berkala, tombol "laporkan" untuk pengunjung |
| Info harga/aturan berubah | AI selalu menyebut "cek ulang ke pengelola", data punya tanggal pembaruan |
| Model 3D berat di HP | Kompresi, muat bertahap, fallback gambar statis |
| Biaya API (AI, peta 3D, cuaca) | Cache agresif, kuota per hari, pilih penyedia dengan tier gratis untuk MVP |
| Privasi analitik | Anonim, tanpa data pribadi, kebijakan privasi jelas (UU PDP) |
| Ketergantungan data pihak lain (BPBD, Dishub) | Mulai dari data statis, integrasi langsung menyusul |

---

## 7. Skenario Demo 5 Menit untuk Atasan/Klien

0. **Trip Wizard (60 detik):** jawab 6 pertanyaan di depan atasan ("Desember, 4 hari, keluarga + anak, pantai & kuliner, belum punya hotel").
   AI menjawab: *"Menginaplah di Nusa Dua atau Jimbaran, karena…"* + itinerary 4 hari + hotel dan sewa mobil dengan sopir dari Tourism Guide.
1. **Intro sinematik:** kamera peta 3D terbang dari Bandara Ngurah Rai ke GWK (15 detik).
2. **Landmark 3D:** putar GWK, klik kepala Wisnu, kartu info muncul, AI Guide berbicara. Buka di HP, **GWK muncul di meja rapat via AR.**
3. **Masuk ke 360°** di plaza GWK, tekan **"Beritahu Fakta Menarik"**, lalu pindah ke tebing Uluwatu saat sunset (Sunset Finder menunjukkan arah matahari).
   Tunjukkan juga **panah arah** dari parkir ke panggung Kecak (versi AR dengan kamera HP, jika sudah ada).
4. **Reel vs Real:** video TikTok Pantai Melasti di kiri, panorama asli di kanan. *"Tempatnya memang sebagus ini."*
5. **Tanya AI:** *"Saya di Seminyak, cari sewa motor terpercaya dan rute lari pagi 5 km."*
   AI menjawab dengan listing terverifikasi (tombol WhatsApp) dan rute lari dengan animasi flyover.
6. **Penutup untuk Pemkab:** buka Dashboard Analitik dan tunjukkan heatmap minat, pertanyaan terbanyak wisatawan, dan klik ke UMKM.
   *"VT ini bukan hanya promosi, tapi alat pengambilan kebijakan pariwisata."*

---

## 8. Keputusan yang Perlu dari Atasan/Klien

1. Landmark 3D pertama: GWK (disarankan) atau yang lain? Siapa yang mengurus izin pengelola?
2. Direktori: gratis penuh (layanan publik) atau ada listing berbayar? Siapa yang memverifikasi (Dispar)?
3. Kreator: hanya kurasi video yang sudah ada, atau sekaligus meluncurkan program Creator Partner?
4. Bahasa prioritas selain ID & EN.
5. Akses data dari dinas terkait: BPBD (evakuasi), Dishub (CCTV/lalu lintas), Dispar (desa wisata, data kunjungan).
6. Discover: destinasi Badung mana saja yang ditampilkan lebih dulu, dan adakah yang ingin diprioritaskan Pemkab (misal Badung Utara)?
7. Trip Wizard: muncul otomatis saat pertama kali membuka VT, atau hanya lewat menu/tombol?
8. Direction AR: kawasan pilot pertama (GWK atau Uluwatu), dan apakah pengelola bisa membantu memetakan titik tujuan?
9. Tourism Guide: apakah fitur **Pengaduan Wisatawan** diteruskan ke dinas tertentu (siapa yang menindaklanjuti)?

---

*Catatan: nama tempat, jarak rute, regulasi, dan angka di dokumen ini adalah draf perencanaan dan perlu diverifikasi
ke sumber resmi/survei lapangan sebelum dipublikasikan.*
