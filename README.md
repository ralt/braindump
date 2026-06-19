# Braindump

Braindump is not a quick voice memo app. It's for the longer stuff — the 5-minute explanation of an architecture you're considering, the 15-minute walkthrough of a problem you're stuck on, the detailed brain dump you do when you need to get everything out of your head and into something searchable. The name is literal: dump your brain, then work with what comes out.

Record audio in your browser, get it transcribed **on-device** (a local Whisper model — no API key, nothing leaves your machine), search across all your transcriptions, and refine the text with an inline AI rewriting chat. Runs fully local out of the box; add hosted services (OpenAI, PostgreSQL, SSO…) only when you want them. Built with Symfony, deployed on Symfony Cloud.

![Inline rewriting chat — the transcript becomes the first user message, and the assistant streams its reply via Mercure.](docs/recording-ai-chat.png)

## Features

- **Audio Recording** — Record directly from the browser with microphone selection. Up to 25MB per recording (the OpenAI Whisper file size limit).
- **Automatic Transcription** — Audio is transcribed in the background. By default a local Whisper model (whisper.cpp) runs on-device with no API key; set `TRANSCRIBER=openai` to use the hosted OpenAI Whisper API instead. If the user left the title field blank, an LLM generates a short descriptive title when one is configured — otherwise the title falls back to the transcript's opening words. Status updates live via Mercure.
- **Full-Text Search** — PostgreSQL full-text search across titles and transcriptions, with configurable OpenSearch backend.
- **Rewriting Chat** — On the recording page, the transcript is fed to an AI assistant scoped to rewriting/editing. Stream replies appear inline, history persists across reloads, and there's a voice-input button (with mic device picker) for quick refinements. Supports multiple providers (Anthropic, OpenAI, Google, Groq, Mistral, DeepSeek, xAI, OpenRouter). Each user provides their own API key, encrypted at rest (libsodium keyed from `APP_SECRET` when self-hosted, Symfony Cloud Vault KMS in production).
- **Skills** — Reusable context documents (tone guidelines, writing rules, persona definitions, domain knowledge) you define once on `/skills` and toggle on per chat. Activated skills get concatenated into the system prompt for every assistant call in that conversation — invisible in the message stream, but they shape every reply. Per-user, with a `(session, skill)` join table tracking which are active in each conversation.
- **Enterprise-Ready Auth** — Form login with per-user roles and permissions, plus optional OIDC single sign-on for enterprise providers (enable with `OIDC_ENABLED=1`; adds a "Sign in with SSO" button alongside form login).
- **Admin Back-Office** — EasyAdmin dashboard for user management.

## Infrastructure Architecture

### Why workers for transcription

Transcription requests to OpenAI with large audio files can take a very long time — minutes, not milliseconds. Running these in the HTTP request path would mean the user stares at a loading spinner for the entire duration, and the web server worker is occupied the whole time. Instead, the web request simply enqueues a job and returns immediately. A dedicated Symfony Messenger worker picks up the job, calls OpenAI, and updates the database when done. The user sees the status change in real-time via Mercure SSE.

This separation means the web application stays responsive regardless of how many transcriptions are queued, and if a transcription fails, it can be retried without user intervention.

### Why Mercure for real-time updates

Two flows publish events that the browser needs to receive live: the transcription worker reporting status changes, and the rewriting chat streaming AI tokens to every open tab on the same recording. Traditional PHP-FPM ties up one worker per open SSE connection — with limited workers, a handful of users staring at a chat would starve the rest of the app.

Mercure handles persistent SSE connections with async I/O, so the PHP application only does the publishing (a fast HTTP call to the hub) and never holds the long-lived browser connection itself. On Symfony Cloud, Mercure runs as a managed service on a dedicated subdomain, fully decoupled from the PHP-FPM tier.

### Why network storage for audio files

Audio files need to be accessible by both the web application (which receives the upload) and the transcription worker (which reads the file to send to OpenAI). On Symfony Cloud, the web container and worker containers are separate processes that don't share a filesystem. The `network-storage` service provides a shared mount that both can access, solving this cleanly without needing to store files in the database or an external object store.

### Why Vault KMS for API key encryption

Each user provides their own AI provider API key for the rewriting chat. These keys are sensitive credentials that must be stored encrypted at rest. Self-hosted, the app seals them with libsodium using a key derived from `APP_SECRET`. On Symfony Cloud, it automatically upgrades to the managed Vault KMS service for transit encryption — the key never exists in plaintext in the database or in application config, and is managed by the platform, rotated independently, and never leaves the Vault service.

### Why PostgreSQL LISTEN/NOTIFY for the message queue

Symfony Messenger needs a transport for async messages. The simplest option is Doctrine transport with PostgreSQL, which reuses the existing database — no additional service to provision, configure, or pay for. PostgreSQL's LISTEN/NOTIFY mechanism provides efficient push-based message delivery (the worker wakes up immediately when a message arrives, rather than polling on a timer). For the throughput this application needs, it's more than sufficient. RabbitMQ or Redis can be swapped in later if needed by changing a single DSN.

## Try it in one command (Docker)

No PHP, no PostgreSQL, no API keys — just Docker.

```bash
git clone <repo-url>
cd braindump
docker compose up --build
```

Open <http://localhost:8000> and log in with the seeded demo account:

- **Email:** `admin@example.com`
- **Password:** `password`

That's the whole app — record audio, get it transcribed **on-device** (local Whisper, no key), search, and start a rewriting chat (the chat itself needs an AI provider key; see [Add services as you grow](#add-services-as-you-grow)). The first build compiles whisper.cpp and downloads a model, so it takes a few minutes; later starts are quick. Compose bundles PostgreSQL and Mercure automatically.

## Run it locally without Docker

Minimum to boot the app: **PHP 8.4 + Composer**. SQLite is the default database, so PostgreSQL is *not* required.

```bash
git clone <repo-url>
cd braindump
composer install
php bin/console doctrine:migrations:migrate
php bin/console app:create-user you@example.com password "You" --admin
symfony server:start                 # or: php -S localhost:8000 -t public
```

To enable transcription, install **ffmpeg** and **whisper.cpp** (both on your `PATH`; override with `FFMPEG_BINARY` / `WHISPER_CLI`), download a model, and run the worker plus the Mercure hub for live updates:

```bash
php bin/console app:whisper:download              # base.en (~140 MB); try tiny.en/small too
./mercure run --config Caddyfile.mercure --adapter caddyfile
php bin/console messenger:consume async           # transcription worker
```

Running the tests:

```bash
php bin/phpunit
```

## Add services as you grow

Braindump runs fully local by default. Every capability below is **opt-in** — enable it when you want it, in any order. Put secrets in `.env.local` (gitignored), never in `.env`, and restart the server (and any workers) after changing environment variables.

### Local Whisper → OpenAI Whisper API

Faster, more accurate transcription — at the cost of sending audio to OpenAI and needing an API key.

```bash
# .env.local
TRANSCRIBER=openai
OPENAI_API_KEY=sk-...
```

New recordings use the hosted API immediately; you no longer need the `whisper-cli`/`ffmpeg` binaries or a downloaded model.

### Heuristic titles → LLM titles & the AI rewriting chat

By default a recording with no title is named from the transcript's opening words, and the AI rewriting chat is unavailable.

- **LLM-generated titles** (and the CI failure analysis) — set `OPENAI_API_KEY`.
- **The AI rewriting chat** — each user adds their *own* provider key (Anthropic, OpenAI, …) under **Settings** in the app, so different users can bring different providers. There's no global switch.

### SQLite → PostgreSQL

A shared, concurrent, production-grade database. It also unlocks PostgreSQL full-text search and the LISTEN/NOTIFY message queue (no worker polling).

```bash
# .env.local
DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/braindump?serverVersion=16&charset=utf8"
```

Then create the schema:

```bash
php bin/console doctrine:migrations:migrate
```

The Docker setup already uses a bundled PostgreSQL. Existing SQLite data is **not** migrated automatically.

### Database search → OpenSearch

For large datasets, hand full-text search to OpenSearch/Elasticsearch instead of the database (the default `database` provider auto-detects SQLite vs PostgreSQL, so you don't need this until you outgrow it).

```bash
# .env.local
SEARCH_PROVIDER=opensearch
OPENSEARCH_URL=https://localhost:9200
OPENSEARCH_INDEX=braindump_recordings   # optional; this is the default
```

New recordings are indexed as they finish transcribing. To back-fill everything that already exists (recordings created before the switch), run:

```bash
php bin/console app:search:reindex
```

### Form login → OIDC single sign-on

Add "Sign in with SSO" (Okta, Microsoft Entra, Google Workspace, Keycloak — any OIDC provider) alongside the built-in form login, which stays available.

```bash
# .env.local
OIDC_ENABLED=1
OIDC_WELL_KNOWN_URL=https://your-idp.example.com/.well-known/openid-configuration
OIDC_CLIENT_ID=your-client-id
OIDC_CLIENT_SECRET=your-client-secret
```

### Local encryption → Vault KMS

Per-user provider API keys are always **encrypted at rest**. Out of the box they're sealed with libsodium using a key derived from `APP_SECRET` — so keep `APP_SECRET` stable and secret (rotating it makes stored keys unreadable; users just re-enter them). On **Symfony Cloud** the app automatically upgrades to the managed Vault KMS service instead: it switches on in the `prod` environment via the `vault_kms` relationship declared in `.upsun/config.yaml`, with no code or env change on your side. See [Deployment on Symfony Cloud](#deployment-on-symfony-cloud).

## Environment Variables

| Variable | Description | Default |
|---|---|---|
| `TRANSCRIBER` | Transcription backend: `local` (whisper.cpp, on-device) or `openai` (hosted API) | `local` |
| `WHISPER_CLI` | Path to the whisper.cpp CLI binary (used when `TRANSCRIBER=local`) | `whisper-cli` |
| `WHISPER_MODEL` | Path to the ggml model file | `var/whisper/ggml-base.en.bin` |
| `FFMPEG_BINARY` | Path to ffmpeg (transcodes recordings to 16 kHz WAV for whisper.cpp) | `ffmpeg` |
| `DATABASE_URL` | Database DSN (SQLite by default; set a `postgresql://…` URL to use Postgres) | SQLite (`var/data.db`) |
| `OPENAI_API_KEY` | **Optional.** Hosted Whisper (`TRANSCRIBER=openai`), LLM titles, AI chat, CI failure analysis | — |
| `MERCURE_URL` | Mercure hub URL (server-side) | — |
| `MERCURE_PUBLIC_URL` | Mercure hub URL (browser-side) | — |
| `MERCURE_JWT_SECRET` | JWT secret for Mercure | — |
| `SEARCH_PROVIDER` | Search backend: `database` (auto SQLite/Postgres) or `opensearch` | `database` |
| `OPENSEARCH_URL` | OpenSearch/Elasticsearch URL | — |
| `OPENSEARCH_INDEX` | OpenSearch index name | `braindump_recordings` |
| `OIDC_ENABLED` | Enable OIDC SSO (`1`/`0`) | `0` |
| `OIDC_WELL_KNOWN_URL` | OIDC provider discovery document (`…/.well-known/openid-configuration`) | — |
| `OIDC_CLIENT_ID` | OIDC client ID | — |
| `OIDC_CLIENT_SECRET` | OIDC client secret | — |
| `APP_SECRET` | Symfony app secret; also derives the key that encrypts per-user API keys at rest (when not on Vault KMS) | — |
| `UPSUN_API_TOKEN` | Symfony Cloud API token for CI automation (`app:ci-run`) | — |
| `CI_NOTIFICATION_EMAIL` | Recipient email for CI notifications | — |
| `CI_EMAIL_DOMAIN` | Optional domain for FROM address (`noreply@{domain}`). Falls back to `CI_NOTIFICATION_EMAIL` | — |
| `MAILER_DSN` | Mail transport (auto-set on Symfony Cloud from `PLATFORM_SMTP_HOST`) | `null://null` |

## Deployment on Symfony Cloud

The `.upsun/config.yaml` defines the full deployment:

- **Web application**: PHP 8.4 with PHP-FPM
- **Transcription worker**: Consumes the `async` Messenger transport — runs OpenAI Whisper transcription outside the HTTP request path
- **Weekly CI cron**: Runs `app:ci-run` — creates an Symfony Cloud environment, updates dependencies, runs `phpunit`. Auto-merges security fixes; sends an email with a merge link for non-security updates. On failure, sends the activity log to OpenAI for root-cause analysis and emails the results
- **PostgreSQL 16**: Primary database
- **Mercure**: Managed real-time hub (transcription status + chat streaming)
- **Network storage**: Shared audio file storage
- **Vault KMS**: Transit encryption for user API keys

```bash
upsun project:create
upsun push
```

Set environment variables via `upsun variable:create`.

## Tech Stack

- **Symfony** web framework
- **Symfony AI** (OpenAI Whisper) for speech-to-text
- **Symfony Messenger** for async transcription
- **Mercure** for real-time SSE (transcription status + AI reply streaming)
- **EasyAdmin** for back-office
- **PostgreSQL** with full-text search
- **Stimulus + Turbo** for frontend interactivity
- **marked + DOMPurify** for sanitized markdown rendering of AI replies
- **Provider SDKs**: direct calls to Anthropic Messages API + OpenAI-compatible chat completions (per-user encrypted keys)
- **Vault KMS** for API key encryption (transit encryption on Symfony Cloud)
- **Symfony Cloud** (formerly Platform.sh) for deployment
