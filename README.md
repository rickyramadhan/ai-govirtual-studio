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