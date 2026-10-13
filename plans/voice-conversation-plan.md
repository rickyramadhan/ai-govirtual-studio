# Rencana mode percakapan suara AI Guide

## Keputusan dan batas audit

Target disepakati: browser HP dan desktop, hosting PHP biasa, API berbayar diperbolehkan, prioritas percakapan natural. Mode teks tetap tersedia. Belum ada perubahan kode runtime.

Audit statis mencakup build Studio dan sampel paket Balaikota Semarang. Belum mencakup pengujian browser, deployment produksi, perbandingan seluruh paket, atau pembacaan runtime master di proyek tetangga. Kesamaan versi master dan export wajib diperiksa sebelum coding. Dokumentasi provider terbaru dan harga belum diverifikasi langsung.

## Temuan kode

| Temuan | Dampak dan keputusan |
|---|---|
| [Mikrofon](../storage/tours/balaikota-semarang/package/govirtual-ai/frontend/guide.js:1789) memakai Web Speech API dan jeda 2.500 ms | Ini input teks lewat suara, bukan sesi audio dua arah. Pertahankan sebagai dikte opsional mode teks; jangan jadikan fondasi mode ngobrol. |
| [Pembacaan jawaban](../storage/tours/balaikota-semarang/package/govirtual-ai/frontend/guide.js:1504) membaca isi panel; [presentasi navigasi](../storage/tours/balaikota-semarang/package/govirtual-ai/frontend/guide.js:1607) menambahkan deskripsi tujuan | Jawaban suara mudah terlalu panjang. Mode ngobrol menghasilkan audio singkat tersendiri, bukan membaca kartu. |
| [Backend TTS](../storage/tours/balaikota-semarang/package/govirtual-ai/api/tts.php:29) menjalankan Python dengan lokasi executable Windows dan menghasilkan berkas audio sebelum dikirim | Tidak portabel sebagai prasyarat shared hosting. Mode realtime tidak bergantung pada backend ini. |
| [Pengiriman chat](../storage/tours/balaikota-semarang/package/govirtual-ai/frontend/guide.js:1648) menggabungkan jawaban kurasi, logika lokal, jaringan, UI, dan aksi | Pisahkan hanya titik integrasi yang diperlukan. Jangan salin keseluruhan alur menjadi mesin voice kedua. |
| [Eksekusi aksi](../storage/tours/balaikota-semarang/package/govirtual-ai/frontend/guide.js:564) sudah memeriksa scene dan menjalankan navigasi | Gunakan executor yang sama dengan tambahan pemeriksaan giliran, duplikasi, pembatalan, dan konfirmasi hasil perpindahan. |
| [Backend chat](../storage/tours/balaikota-semarang/package/govirtual-ai/api/tour-chat.php:50) sudah memiliki resolusi deterministik dan retrieval | Gunakan data dan resolver ini sebagai dasar tool voice. Jangan memanggil model teks lalu meminta model audio mengulang seluruh jawabannya pada setiap giliran. |
| [Validasi chat](../storage/tours/balaikota-semarang/package/govirtual-ai/api/tour-chat.php:29) membatasi 240 karakter, sedangkan [input UI](../storage/tours/balaikota-semarang/package/govirtual-ai/frontend/guide.js:1875) 500 | Selaraskan batas. Voice perlu batas payload dan panjang ujaran eksplisit; jangan memotong perintah diam-diam. |
| [CORS chat](../storage/tours/balaikota-semarang/package/govirtual-ai/api/tour-chat.php:10) terbuka | Jangan menyalin pola ini ke penerbit sesi berbayar. CORS bukan autentikasi atau pembatas biaya. Audit pengamanan endpoint secara terpisah sebelum publikasi. |
| [Build](../index.php:771) menyalin runtime master dan menulis kredensial server; [sinkronisasi](../index.php:801) melewati konfigurasi serta data tur | Edit master sebagai sumber, lalu build paket pilot. Pengaturan voice perlu jalur build/migrasi sendiri, tidak cukup sync runtime. |

## Arsitektur utama

Keputusan provider ditunda sampai perbandingan dan uji audio selesai. Dua kandidat: OpenAI Realtime melalui WebRTC dan Gemini Live melalui WebSocket browser langsung. Ini baseline arsitektur berdasarkan kemampuan yang diketahui, bukan konfirmasi dokumentasi terbaru September 2026. PHP melayani permintaan HTTP singkat untuk pembukaan sesi, validasi tool, dan lifecycle aplikasi. Audio tidak diproksikan melalui PHP. Tidak menambah Node.js, Python, database vektor, server WebSocket sendiri, atau framework frontend.

Model realtime memahami audio masuk dan menghasilkan audio keluar. Transkrip dipakai untuk tampilan, riwayat, dan pemeriksaan, bukan satu-satunya penggerak percakapan. Deteksi akhir giliran dan interupsi memakai kemampuan provider yang sudah diverifikasi.

Alur:
1. Pengunjung memilih mode Suara dan menyetujui akses mikrofon.
2. PHP memvalidasi tur, izin sesi, kuota, dan konfigurasi sebelum membuka sesi provider.
3. Browser membangun koneksi WebRTC dengan otorisasi sementara atau negosiasi sesi melalui backend sesuai API provider yang berlaku. Kunci permanen tidak pernah dikirim ke browser.
4. Audio dua arah berjalan langsung. Kanal event membawa status bicara, transkrip, dan permintaan tool.
5. Pertanyaan tentang tur dan permintaan aksi mengambil fakta atau target tervalidasi dari PHP.
6. Browser menjalankan aksi yang disetujui melalui executor tur lama, lalu melaporkan hasil nyata kepada percakapan.
7. Saat keluar mode suara, browser mematikan track mikrofon, audio, koneksi, dan pekerjaan tertunda. Server menutup sesi provider bila API mendukungnya serta mengakhiri sesi aplikasi.

Untuk Gemini Live, ganti jalur media WebRTC pada alur di atas dengan WebSocket langsung, pemrosesan audio browser, dan antrean playback. Kanal audio dan event mengikuti protokol Live. Otentikasi sementara harus terkonfirmasi untuk model serta versi API yang dipilih. WebSocket keluar dari browser tidak berarti hosting PHP harus menjalankan server WebSocket.

Jangan membangun multi-provider voice pada versi pertama. Bandingkan dua prototipe terbatas, lalu integrasikan satu pemenang. Pipeline streaming STT, model teks, dan TTS adalah alternatif kontrol tinggi, tetapi menambah sinkronisasi dan bukan pilihan awal kebutuhan ini.

## Perbandingan OpenAI Realtime dan Gemini Live

### Status bukti

Perbandingan ini memakai pengetahuan teknis tentang kedua API, bukan hasil pengambilan dokumentasi terbaru. Upaya riset tidak dapat berjalan karena sesi tidak menyediakan eksekusi perintah atau akses web. Tidak ada model, harga, batas durasi, atau ketentuan privasi September 2026 yang sudah diverifikasi. Angka biaya sengaja tidak dicantumkan.

### Matriks teknis untuk AI Guide

| Aspek | OpenAI Realtime | Gemini Live | Implikasi proyek |
|---|---|---|---|
| Percakapan audio native | Keluarga model realtime mendukung audio masuk/keluar | Pilih varian Live native audio, bukan menyamakan semua model Gemini | Keduanya kandidat percakapan langsung, bukan hanya dikte dan pembacaan teks |
| Transport browser | Jalur WebRTC tersedia pada API yang dikenal; WebSocket juga dikenal | Jalur Live dikenal memakai WebSocket bidireksional; WebRTC melalui integrasi pihak ketiga tidak otomatis fitur native Google | Untuk versi pertama, hindari relay atau vendor transport tambahan |
| Pengolahan audio | WebRTC menangani transport media, codec yang dinegosiasikan, dan buffering media | Browser perlu menyiapkan chunk audio sesuai format provider, konversi sample rate bila perlu, antrean, serta penjadwalan playback | OpenAI cenderung lebih sedikit kode media browser; kualitas model belum tentu lebih baik |
| Format audio | Jalur WebRTC memakai media track; format jalur WebSocket berbeda dan jangan dicampur | Jalur Live umumnya memakai PCM; format, sample rate, kanal, dan ukuran chunk harus diverifikasi per versi | Tidak cukup merekam berkas WebM lalu mengirimnya sebagai stream sembarang |
| Otorisasi browser | Otorisasi sementara atau negosiasi sesi lewat backend sesuai API | Token ephemeral tersedia pada jalur Live yang dikenal; dukungan versi dan model perlu dicek | PHP menyimpan kunci permanen dan menerbitkan akses terbatas; browser tidak menerima kunci utama |
| Akhir giliran | Deteksi aktivitas suara dan opsi pengaturan giliran tersedia pada API yang dikenal | Deteksi aktivitas dan pengaturan aktivitas/giliran tersedia pada Live yang dikenal | Uji jeda alami bahasa Indonesia; jangan sekadar mengganti timer 2,5 detik dengan timer lebih pendek |
| Interupsi | Event interupsi harus diselaraskan dengan penghentian respons dan riwayat audio; sebagian perilaku bergantung transport | Event interupsi harus menghentikan antrean audio browser dan membatalkan pekerjaan relevan | Keduanya butuh koordinasi aplikasi; fitur provider bukan jaminan interupsi rapi |
| Tool dan navigasi | Mendukung pemanggilan fungsi/tool | Mendukung pemanggilan fungsi/tool | Keduanya bisa meminta navigasi; server menentukan target sah, browser menjalankan aksi |
| Pembatalan tool | Semantik pembatalan dan respons asinkron perlu diperiksa pada model/API | Semantik pembatalan dan respons asinkron perlu diperiksa pada model/API | Tidak boleh menganggap penghentian audio otomatis membatalkan HTTP atau perpindahan scene |
| Konteks percakapan | Konteks sesi dan pembaruan instruksi/event | Konteks sesi, pembaruan konten, serta mekanisme pengelolaan sesi sesuai API | Simpan konteks tur milik aplikasi agar pindah mode tidak bergantung pada memori provider saja |
| Putus dan lanjut sesi | Jangan menganggap koneksi baru otomatis mempertahankan sesi lama | Live memiliki mekanisme resumption pada API yang dikenal; ketersediaan dan batas perlu dicek | Siapkan ringkasan aplikasi dan deteksi duplikasi pada kedua kandidat |
| Hosting PHP biasa | Cocok untuk sesi HTTP pendek + media browser langsung | Cocok untuk token HTTP pendek + WebSocket browser langsung | Tidak perlu server persisten jika kontrol yang dibutuhkan didukung API langsung |
| Jaringan buruk | Media WebRTC dirancang untuk realtime; tetap dapat diblokir jaringan tertentu | Audio lewat koneksi WebSocket berurutan dapat tertahan saat retransmisi jaringan | Ukur di jaringan seluler; jangan hanya demo Wi-Fi kantor |
| Bahasa Indonesia | Naturalitas, aksen, nama tempat, dan jeda belum diukur | Naturalitas, aksen, nama tempat, dan jeda belum diukur | Tidak ada pemenang berdasarkan merek atau suara demo berbahasa Inggris |
| Integrasi provider lama | Perlu konfigurasi voice terpisah dari routing model teks | Kredensial Google mungkin bisa dikelola lewat UI yang sama, tetapi hak akses dan dukungan Live tetap berbeda | Model chat Gemini yang sudah berjalan tidak membuktikan Live siap; kedua kandidat butuh modul sesi baru |
| Privasi | Tinjau kebijakan API, retensi, pengecualian monitoring, dan kelayakan kontrol data | Bedakan Gemini Developer API berbayar, layanan gratis, dan Vertex AI; ketentuannya tidak boleh disamakan | Akun berbayar saja bukan bukti zero retention; persetujuan pengguna tetap diperlukan |
| Biaya | Tarif model audio masuk/keluar, teks, cache, dan layanan tambahan | Tarif model audio masuk/keluar, teks, cache, dan layanan tambahan | Jangan membandingkan harga token teks dengan token audio atau menganggap satu selalu lebih murah |
| Stabilitas produksi | Periksa status model, deprecation, wilayah, serta limit akun | Periksa status model native audio, preview/stable, wilayah, serta limit akun | Utamakan model yang tersedia dan bisa dipakai produksi pada akun deployment |

### Modul tambahan yang membedakan

**OpenAI:** pengelola koneksi WebRTC, media track, elemen playback, dan kanal event. Tetap perlu penanganan izin, echo cancellation, interupsi, tool, serta cleanup.

**Gemini:** pengelola koneksi WebSocket, pemrosesan audio lewat Web Audio, konversi format/sample rate sesuai kontrak, chunking, antrean playback dengan batas memori, pembersihan antrean ketika disela, serta resumption bila tersedia. Gunakan pemrosesan audio browser modern, bukan loop berat di thread UI. Audio masuk dan keluar dapat memiliki sample rate berbeda; jangan menetapkan angka sebelum dokumentasi model dipastikan.

Kedua jalur memakai kontrak tool PHP, data kurasi, UI mode, dan executor tur yang sama. Jangan membuat dua basis pengetahuan atau dua mesin navigasi.

### Biaya: cara membandingkan yang adil

1. Tetapkan model spesifik, status layanan berbayar, mata uang, dan tanggal tarif resmi untuk masing-masing kandidat.
2. Jalankan skenario yang sama: 5 menit sesi, 2 menit pengunjung berbicara, 2 menit AI berbicara, 1 menit jeda, 8 giliran, 2 lookup fakta, dan 2 navigasi. Ini beban benchmark, bukan perkiraan durasi pengerjaan.
3. Catat penggunaan provider aktual: audio masuk/keluar, teks konteks, cache bila ada, transkripsi tambahan, dan model kedua bila benar-benar dipanggil. Jangan mengasumsikan semua waktu diam gratis.
4. Hitung biaya tiap modalitas sebagai jumlah token terukur dibagi satu juta, dikalikan tarif modalitas. Tambahkan biaya lain yang berlaku. Satuan token antarprovider tidak diasumsikan setara.
5. Laporkan biaya per sesi berhasil dan per menit sesi, persentase gagal, serta biaya reconnect/interupsi. Proyeksikan 100 dan 1.000 sesi dengan campuran penggunaan yang sama.
6. Periksa apakah batas proyek merupakan hard cap atau hanya peringatan. Batas laju request, token ephemeral kedaluwarsa, dan timer UI bukan pengganti batas belanja aktif yang dapat ditegakkan.

Penghematan Gemini masih hipotesis sampai angka terukur tersedia. Kemudahan WebRTC OpenAI juga tidak boleh menutupi biaya penggunaan yang melampaui anggaran.

### Uji naturalitas dan pemilihan

Gunakan satu tur, data identik, kebijakan jawaban identik, dan 20 skenario yang sama. Libatkan minimal tiga penilai berbahasa Indonesia; sembunyikan nama provider saat penilaian audio jika memungkinkan. Sertakan nama lokal, bicara pelan/cepat, jeda berpikir, koreksi tujuan, negasi, noise, pertanyaan lanjutan, serta interupsi saat tool berjalan. Ulangi pada Safari iPhone, Chrome Android, dan desktop, dengan jaringan baik serta jaringan seluler yang lebih lemah.

Bobot keputusan yang diusulkan:
- Naturalitas percakapan Indonesia: 30%.
- Ketepatan tool, navigasi, dan grounding: 25%.
- Latensi dan kualitas interupsi: 20%.
- Keandalan browser, jaringan, dan kelanjutan sesi: 15%.
- Biaya terukur: 10%.

Keamanan, perlindungan kunci, isolasi tur, kecocokan hosting, dan kontrol penggunaan adalah syarat wajib, bukan skor yang dapat dikompensasi oleh suara bagus. Tidak boleh ada aksi lintas tur, navigasi dari giliran batal, atau klaim tiba sebelum scene berubah pada skenario uji. Ukur latensi median dan persentil 95 terpisah untuk percakapan biasa dan respons bertool.

**Aturan pilih:** jika hasil kualitas dan keandalan setara, prioritaskan OpenAI untuk mengurangi kode media browser. Pilih Gemini jika kualitas Indonesia atau biaya terukur lebih baik secara berarti dan pengolahan audio browser tetap memenuhi uji mobile/interupsi. Jika kontrol biaya aman memerlukan server persisten pada kandidat tertentu, nyatakan konflik dengan batas hosting sebelum memilihnya. Tidak ada pemilihan final pada tahap dokumen ini.

### Sumber resmi untuk verifikasi berikutnya

Tautan berikut adalah titik awal riset, belum diakses dalam sesi ini; bukan bukti fitur atau tarif terkini:
- OpenAI Realtime: https://platform.openai.com/docs/guides/realtime
- OpenAI pricing: https://openai.com/api/pricing/
- OpenAI API data controls: https://platform.openai.com/docs/guides/your-data
- Gemini Live: https://ai.google.dev/gemini-api/docs/live
- Gemini pricing: https://ai.google.dev/gemini-api/docs/pricing
- Gemini API terms: https://ai.google.dev/gemini-api/terms

Sebelum coding, catat sumber dan tanggal untuk model yang dipilih, transport, token sementara, format audio, deteksi giliran, pembatalan tool, durasi/resumption, penutupan sesi server-side, tarif, serta retensi data. Jangan mengisi kekosongan dokumentasi dengan asumsi produksi.

## Modul minimum dan tanggung jawab

1. **UI mode suara**: pemilih Teks/Suara, mulai, mute mikrofon, berhenti bicara, akhiri, transkrip opsional, status koneksi dan error. Panorama tetap terlihat. Kontrol keyboard, label aksesibel, fokus, dan pemberitahuan status wajib ada.
2. **Pengelola sesi browser**: satu stream mikrofon, WebRTC, audio playback, kanal event, timeout, reconnect terbatas, cleanup. Status: nonaktif, meminta izin, menghubungkan, mendengarkan, memproses, berbicara, terputus. Mendengarkan tetap dimungkinkan saat AI berbicara untuk interupsi.
3. **Adapter konteks dan aksi**: scene aktif, arah pandang bila relevan, tujuan sebelumnya, pilihan klarifikasi, riwayat ringkas, versi scene dan giliran. Memakai navigasi lama, bukan executor baru.
4. **API PHP sesi dan tool**: kredensial server, pembatasan penggunaan, konteks resmi, validasi aksi, penyimpanan minimal sesi anonim. Gunakan penyimpanan ringan yang tersedia pada host; penyimpanan Studio tidak diasumsikan ikut terdeploy.
5. **Pengaturan Studio**: aktif per tur, provider/model/suara yang didukung, bahasa awal Indonesia, batas sesi dan penggunaan, domain deployment, uji koneksi. Rahasia dipisahkan dari konfigurasi publik.

Bentuk file minimum saat implementasi: satu modul browser voice dan satu endpoint PHP voice dengan operasi terbatas; ubah UI, kebijakan, resolver, dan builder yang sudah ada seperlunya. Nama dan pemecahan akhir ditentukan setelah master diperiksa. Tidak perlu kelas provider generik atau layanan terpisah untuk satu provider.

## Kontrak API dan tool

Satu endpoint voice dapat melayani operasi berikut dengan validasi metode, tipe data, ukuran payload, serta error konsisten:

| Operasi | Masukan | Hasil |
|---|---|---|
| Buka sesi | Identitas tur, scene awal, bahasa, konteks ringkas, bukti sesi anonim | ID sesi, otorisasi sementara/hasil negosiasi, masa berlaku, kemampuan yang diizinkan |
| Jalankan tool | ID sesi, ID panggilan tool, ID giliran, versi scene, nama tool dan argumen | Fakta bersumber, pilihan klarifikasi, atau aksi scene yang sudah disetujui |
| Laporkan hasil aksi | ID aksi dan giliran, scene aktual atau alasan kegagalan | Status untuk percakapan dan pembaruan konteks |
| Akhiri sesi | ID sesi | Status penutupan dan pencatatan penggunaan yang tersedia |

Mulai dengan dua tool domain: pencarian informasi tur dan resolusi navigasi. Hasil navigasi menyatakan tujuan tunggal, perlu klarifikasi, atau tidak tersedia. Fakta dan daftar scene berasal dari paket server, bukan dianggap sah hanya karena dikirim browser. Hasil lookup membawa sumber agar kartu teks dapat menampilkan detail tanpa dibacakan semua.

Tool bukan izin menjalankan kode. Tolak nama aksi di luar daftar izin, scene lintas tur, argumen tak sesuai skema, URL sembarang, giliran usang, dan ID panggilan yang sudah diproses. Balasan atau transkrip biasa tidak boleh langsung memindahkan panorama. Instruksi model membantu grounding tetapi bukan batas keamanan; PHP dan executor tetap memvalidasi.

## Perilaku percakapan

Contoh: pengunjung berkata Saya mau ke toilet. Model meminta resolusi navigasi; server mencari fasilitas yang tersedia. Jika tujuan tunggal valid, browser langsung mulai pindah tanpa menunggu audio selesai. AI boleh berkata Baik, saya arahkan ke toilet. AI baru menyatakan sudah sampai setelah scene aktual cocok.

Jika beberapa tujuan sama-sama masuk akal, tanya Toilet dekat lobi atau halaman? Jawaban Yang dekat lobi memakai pilihan tersimpan. Jika toilet tidak tersedia di panorama, jelaskan batas itu; jangan pindah ke scene sembarang. Terdekat hanya diklaim jika data graf atau jarak mendukung. Navigasi virtual bukan petunjuk berjalan fisik atau rute aksesibel yang belum diverifikasi.

Interupsi: saat pengunjung menyela, hentikan playback dan generasi lama; sinkronkan bagian jawaban yang benar-benar terdengar sesuai event provider. Abaikan hasil tool/giliran lama yang belum dieksekusi. Jika perpindahan sudah terjadi, jangan pura-pura belum terjadi; gunakan lokasi aktual saat memproses koreksi. Jeda berpikir singkat tidak otomatis dianggap akhir kalimat. Noise dan audio AI sendiri tidak boleh memicu navigasi.

Mode suara tidak mengaktifkan TTS panel atau pengenal dikte lama secara bersamaan. Terapkan echo cancellation dan noise suppression jika browser mendukung. Kelola musik latar selama percakapan agar tidak muncul-hilang tiap giliran, lalu pulihkan hanya bila sebelumnya diputar pengguna.

Saat berpindah Teks/Suara, bawa riwayat relevan dan scene aktual, bukan seluruh histori tanpa batas. Tandai jawaban terinterupsi. Saat browser masuk latar belakang, koneksi putus, atau mikrofon ditolak, tampilkan kondisi nyata dan akses mode teks. Jangan membuka mikrofon kembali tanpa tindakan pengguna bila sesi sudah diakhiri.

## Persiapan hosting, keamanan, dan biaya

- HTTPS dengan sertifikat valid, PHP/cURL dan koneksi HTTPS keluar ke provider. Audio WebRTC langsung tidak memerlukan proses PHP panjang. Uji jaringan seluler dan jaringan yang memblokir WebRTC; fallback ke teks bila koneksi gagal.
- Jika tur di-embed, periksa izin mikrofon/autoplay pada iframe dan kebijakan browser. Mulai audio dari tindakan pengguna. Uji Safari iPhone, Chrome Android, serta browser desktop sasaran.
- Kunci provider khusus deployment/proyek, disimpan server-side; bila tetap berada dalam direktori web, akses HTTP ke file rahasia wajib ditolak dan diuji. Paket berisi rahasia hanya untuk penerima deployment tepercaya, bukan unduhan publik.
- Sesi anonim bertanda tangan, batas IP dan sesi, pembatasan sesi paralel, kuota per tur/proyek, dan penolakan penerbitan sesi baru saat batas tercapai. Tambahkan proteksi bot hanya bila diperlukan, bukan memaksa login pengunjung.
- Masa berlaku token pembukaan tidak sama dengan durasi sesi aktif. Timer browser bukan batas biaya keras. Verifikasi batas provider dan kemampuan menutup sesi dari server. Jika provider tidak menyediakan kontrol keras tanpa koneksi server persisten, dokumentasikan batasnya dan wajibkan batas tingkat akun/proyek sebelum peluncuran publik; jangan menjanjikan plafon per sesi yang tidak dapat ditegakkan.
- Persetujuan mikrofon menjelaskan audio dikirim ke provider. Jangan menyimpan audio mentah atau transkrip secara default. Log ID, durasi, latensi, error, dan penggunaan seperlunya; rahasia serta audio tidak masuk log. Verifikasi retensi provider dan kebijakan privasi.
- Biaya dihitung dari token audio masuk/keluar, konteks teks, tool/model tambahan bila dipakai, dan jumlah sesi bersamaan. Ukur skenario pilot lalu proyeksikan berdasarkan kunjungan; jangan menetapkan tarif per menit tanpa data provider dan pengukuran. Jawaban ringkas, timeout idle, dan tutup sesi membantu menekan penggunaan.

## Tahapan implementasi dan penerimaan

1. Periksa master terhadap paket export dan verifikasi dokumentasi provider: model, suara Indonesia, WebRTC, interupsi, tool, sesi sementara, penggunaan, serta kontrol biaya. Pilih satu provider setelah uji pembanding singkat.
2. Buat pilot satu tur dengan audio dua arah, interupsi, dan cleanup pada browser HP serta desktop; belum deploy massal.
3. Tambahkan API sesi aman, batas penggunaan, privasi, serta penyimpanan sesi minimal. Uji kegagalan sebelum menerima trafik publik.
4. Hubungkan resolver navigasi dan retrieval lama sebagai tool; pastikan kurasi tetap berlaku, konteks scene akurat, aksi satu kali, dan jawaban suara tidak memerlukan model teks kedua secara rutin.
5. Tambahkan UI dua mode, transkrip opsional, audio arbitration, perpindahan konteks, klarifikasi, dan fallback teks.
6. Tambahkan pengaturan Studio dan propagasi build/deploy. Flag suara default mati. Pertahankan data per tur dan sediakan rollback paket pilot sebelum aktivasi bertahap.
7. Tinggalkan satu pemeriksaan otomatis runnable tanpa framework baru untuk validasi tool, scene lintas tur, duplikasi, giliran usang, dan pemrosesan pembatalan. Lengkapi matriks uji manual audio/browser.
8. Uji toilet tunggal, tujuan ambigu, tujuan tak tersedia, negasi saya tidak mau ke toilet, koreksi eh ke lobi saja, jawaban lanjut yang tadi, pergantian scene manual, noise, izin ditolak, token kedaluwarsa, kuota habis, provider gagal, dan reconnect. Periksa tidak ada navigasi ganda atau aksi lama setelah interupsi.
9. Ukur median dan persentil 95 dari akhir ujaran ke audio pertama, tool ke awal navigasi, serta interupsi ke audio berhenti. Sasaran awal pilot pada jaringan baik: median audio pertama maksimal 1,5 detik untuk percakapan ringan, audio terhenti maksimal 300 ms setelah deteksi interupsi. Ini target uji, bukan jaminan provider; laporkan latensi pertanyaan bertool terpisah.
10. Terima pilot hanya jika mikrofon benar-benar mati saat keluar, teks tidak regresi, fakta tur tetap bersumber, klaim tiba sesuai scene nyata, secret tidak bocor, batas penggunaan teruji, dan data dua tur tidak bercampur setelah rebuild.

## Di luar versi pertama

Tidak ada wake word, voice cloning, rekaman percakapan permanen, avatar lip-sync, server audio sendiri, atau multi-provider otomatis. Tambahkan hanya setelah pilot membuktikan kebutuhan. Mode suara memperbaiki cara berinteraksi; tidak memperluas otoritas AI atau mengizinkan tujuan navigasi karangan.
