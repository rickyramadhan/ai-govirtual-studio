# AI Guide Studio

Virtual tour CMS with AI guide for 360° panorama tours. Build, manage, and deploy AI-powered tour guides using Gemini/Groq/OpenRouter.

## Stack

- **Backend**: PHP 8.1+ (single-file app, no framework)
- **Database**: SQLite (`storage/app.sqlite`)
- **AI Providers**: Gemini, OpenRouter (Groq, GPT-4o-mini, etc.)
- **Frontend**: Vanilla JS (`frontend/guide.js`) — client-side AI fallback
- **360° Tours**: krpano-compatible panorama XML

## Structure

```
index.php          — App entry + routes
studio.php         — UI shell (sidebar, topbar, design system)
_sync_runtime.php  — Sync runtime files to packages
smoke_test.php     — Health check

storage/
  tours.db         — Tour metadata (fallback DB)
  app.sqlite       — Main DB (tours, API keys, settings)
  tours/           — Per-tour data + deployable packages
    {slug}/
      parsed.json
      flow.json
      knowledge.json
      scene-identity.json
      curated-answers.json
      nearby.json
      visitor-tips.json
      recommended-questions.json
      package/govirtual-ai/   — Deployable package
        api/                  — AI backend (runtime)
          tour-chat.php       — Chat endpoint
          ai-core.php         — RAG, intent classification, response pipeline
          ai-provider.php     — Multi-provider AI (Gemini, OpenRouter)
          guide-policy.php    — System prompt builder
        frontend/             — Client-side
          guide.js            — Main chat UI
          guide.css
        data/                 — Per-tour data
        config/tour.json
  cities/           — City-level knowledge base
    {slug}/
      knowledge.json
      meta.json
      sources.json
```

## How It Works

### Tour Pipeline

1. **Import** — Parse krpano panorama XML → `parsed.json` (scenes, hotspots, objects, routes)
2. **Seed** — Generate `knowledge.json`, `scene-identity.json`, `curated-answers.json`, `flow.json`, `visitor-tips.json`, `nearby.json`, `recommended-questions.json`
3. **Build** — Assemble deployable package at `package/govirtual-ai/`
4. **Deploy** — Copy package to `export_path` (static file server or shared hosting)

### AI Chat Flow

```
User message → tour-chat.php
  ├─ Curated intent match? (G01-G19) → return curated template
  ├─ RAG retrieval → knowledge embeddings similarity search
  ├─ AI provider (Gemini/Groq/OpenRouter) → contextual reply
  └─ Enriched identity fallback → scene-identity description
```

- **Intent classification**: `ai_classify_intent()` — NEARBY_SEARCH, ENTITY_DESCRIPTION, NAVIGATION_REQUEST, etc.
- **RAG**: Embedding-based retrieval from tour knowledge + city knowledge
- **Multi-provider failover**: Primary → fallback → raw fallback
- **Curated answers**: Pre-written responses for common intents (bypasses AI cost)

### Key Features

| Feature | Description |
|---------|-------------|
| Curated intents | Pre-defined Q&A for common questions (G01-G19) |
| RAG knowledge | Embedding-based semantic search across tour + city knowledge |
| Multi-provider AI | Gemini primary, OpenRouter/Groq fallback |
| City knowledge | Centralized city info, synced to all tours in that city |
| Prompt versioning | `AI_PROMPT_VERSION` — system prompt with faceted responses |
| Runtime sync | One-command sync of API files to all deployed packages |
| API key management | UI for Gemini/OpenRouter keys with priority + test |

## Usage

### CLI

```bash
# Sync runtime to all packages
php _sync_runtime.php

# Smoke test
php smoke_test.php
```

### Web

Open in browser. Studio UI for managing tours, knowledge, API keys, deployment.

## Setup

1. Clone repo to web server (Apache/Nginx with PHP 8.1+)
2. Ensure `storage/` writable
3. Open in browser → DB auto-creates
4. Add API keys (Gemini, OpenRouter) via Studio → Settings
5. Import tour via krpano XML upload or existing parsed data
6. Deploy package to web-accessible path

## Security

- API keys stored in DB + `.env` files (gitignored via `**/.env`)
- `config.php` files contain placeholders; real keys from `.env` or DB
- Push protection removes secrets from git history

## Akun & keamanan (diperbarui Fase 0)

- **Tidak ada akun bawaan.** Buat admin pertama dari terminal:
  `php tools/user-admin.php create <username> admin` (juga `list` dan `passwd <username>`).
- Akun lama yang masih memakai password bawaan dipaksa ganti password saat login (halaman **Akun Saya**).
- Semua form POST wajib token CSRF (disisipkan otomatis oleh `layout()` di `studio.php`).
- Aksi yang mengubah data lewat URL (hapus, import, generate, deploy, sinkron runtime, hapus kota)
  hanya menampilkan **halaman konfirmasi** saat dibuka; eksekusi terjadi lewat POST.
- Skrip root `_*.php`, `import_webview.php`, dll. hanya bisa dijalankan dari CLI.
- `.htaccess` memblokir akses web ke `*.sqlite`, `*.log`, `*.bak`, `.env`.

## Keamanan data

- **Simpan = gabung, bukan tulis ulang.** Editor menggabungkan hasil form ke record lama berdasarkan `id`,
  sehingga field di luar form (response, sceneIds, terjemahan, items, dst.) tidak hilang.
- **Riwayat versi:** setiap penulisan file konten tour/kota menyalin versi lama ke
  `storage/tours/{slug}/.history/` (20 versi terakhir per file). Pulihkan dengan menyalin balik file-nya.
- **Trash:** menghapus tour/kota memindahkannya ke `storage/trash/` (bukan hapus permanen).
- Penulisan JSON bersifat atomik (file sementara + rename).
- Form yang melebihi `max_input_vars` ditolak (tidak lagi tersimpan terpotong); `.htaccess` menaikkannya ke 5000.
- Seed konten hanya membuat file yang belum ada; menimpa butuh konfirmasi ("Reset ke template…").
- Backup deploy dipangkas otomatis (simpan 3 terbaru). Pangkas backup lama: `php tools/prune-backups.php` (dry-run) lalu `--apply`.

## Pengujian

```bash
php smoke_test.php               # render ±27 halaman + pastikan aksi berbahaya hanya menampilkan konfirmasi
php tests/merge_edited_test.php  # unit test helper merge
php tests/integration_test.php   # alur simpan/CSRF/hapus/riwayat/seed/ganti password pada salinan terisolasi
```

Rencana lengkap & audit: `plans/AI-Studio-Audit-Roadmap.md`.
