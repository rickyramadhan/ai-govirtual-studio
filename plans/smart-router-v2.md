# Smart Router v2 — Sepintar ChatGPT, Tetap Bisa Menggerakkan Aplikasi

## Masalah
Router sekarang = regex (`voiceClassifyIntent` client + `ai_classify_intent` server). Regex cepat & gratis, tapi kaku — kasus "saya dari Jakarta, pengen ke Lawang Sewu naik apa" salah rute ke `resolve_navigation` → clarify "Maksud Anda yang mana?". Tiap pola baru = patch regex baru, tidak pernah selesai.

## Solusi: LLM sebagai router (function-calling style), regex hanya fast-path

Prinsip: **regex hanya menangani kasus yang 100% pasti; sisanya LLM putuskan**. LLM menerima daftar tool + konteks, mengembalikan JSON `{tool, args, reply}`. Server eksekusi tool. Ini pola yang sama dengan Gemini Live mode yang sudah jalan (function calling), dipindahkan ke mode voice standar + text.

```
User query
   │
   ├─ FAST-PATH regex (0ms, gratis) — hanya high-confidence:
   │    • negation ("jangan", "batal")          → handle lokal
   │    • "ke mana lagi / lanjut"               → resolve_navigation (next)
   │    • "drone"                               → resolve_navigation (drone)
   │    • follow-up pilihan klarifikasi ("yang pertama")
   │
   └─ LLM ROUTER (1 panggilan, ~0.5–1s):
        Prompt system: daftar tool + aturan + daftar tempat tur
        Output JSON ketat:
        {
          "tool": "resolve_navigation" | "lookup_tour_information" | "none",
          "destination_query": "...",   // utk navigation
          "reply": "..."                // utk none (obrolan umum)
        }
        Server validasi + eksekusi tool yang dipilih.
```

## Perubahan

### 1. Server — `guide-tool.php`
- Op baru `smart_route` (atau perluas `lookup_tour_information`): terima query + session, panggil `ai_provider_router()` sekali dengan prompt router (tool list + tempat list + riwayat), parse JSON.
- Hasil `resolve_navigation` → jalankan `voice_resolve_navigation()` (logika clarify/already_there/path tetap sama — sudah solid).
- Hasil `lookup_tour_information` → jalankan `voice_lookup()` (facts + LLM compose + web search fallback tetap sama).
- Hasil `none` → balas `reply` langsung.
- Guard: JSON tidak valid / tool tidak dikenal → fallback ke `ai_classify_intent()` regex lama (jangan pernah gagal total).

### 2. Client — `guide.js`
- `voiceClassifyIntent()`: hapus pola navigation yang ambigu (kata "ke" saja). Sisakan hanya fast-path di atas. Default → `smart` (kirim ke op smart_route).
- `voiceProcessTurn()`: op `smart_route` → dispatch hasil sesuai `tool` (nav → `voiceHandleNavResult`, lookup → `voiceHandleLookupResult`).
- Text mode (`tour-chat.php`): pola sama — `ai_classify_intent` regex hanya untuk deterministic fast-path (CURRENT_LOCATION, dll); sisanya satu panggilan LLM dengan tool schema.

### 3. Prompt router (ringkas, hemat token)
```
Kamu router asisten tur virtual. Pilih SATU tool:
- resolve_navigation: user minta DIBAWA/ANTAR pindah tempat sekarang
  ("antar saya ke galeri", "ke balkon dong")
- lookup_tour_information: pertanyaan informasi apa pun
  (fakta, harga, sejarah, cara ke sini dari luar kota, rekomendasi)
- none: sapaan/obrolan ringan → isi reply
Daftar tempat: {place list}
Riwayat: {recentMessages}
Balas HANYA JSON.
```

## Yang TIDAK berubah
- Eksekusi navigasi (clarify, path, ledger, nav card) — tetap deterministik.
- TTS pipeline, session, web search fallback.
- Gemini Live mode — sudah function calling native.

## Risiko & mitigasi
- Latency +0.5–1s per turn yang tadinya regex → mitigasi: fast-path regex untuk kasus jelas; TTS warmup server-side sudah menutup delay VO.
- LLM salah pilih tool → mitigasi: validasi server + fallback regex; prompt ketat dengan contoh.
- Kuota API → router pakai model murah/cepat (gemini-flash-lite / groq llama-3.1-8b), output ~30 token.

## Urutan implementasi
1. Server: op `smart_route` + prompt router + dispatch + fallback regex.
2. Client voice: `voiceClassifyIntent` dipangkas + dispatch `smart_route`.
3. Test: "naik apa dari Jakarta", "antar ke galeri", "ke mana lagi", "halo", "toilet di mana".
4. Deploy + verifikasi.
5. (Opsional lanjutan) Text mode pakai pola sama.
