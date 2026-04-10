# Braindump

A multi-user speech-to-text transcription web application built with Symfony. Record audio in your browser, transcribe it via OpenAI Whisper, search across all your transcriptions, share them with others, and start interactive Claude Code sessions to work with the content.

## Features

- **Audio Recording** — Record directly from the browser with microphone selection. Up to 25MB per recording (the OpenAI Whisper file size limit).
- **Automatic Transcription** — Audio is transcribed in the background via OpenAI Whisper through Symfony AI.
- **Full-Text Search** — PostgreSQL full-text search across titles and transcriptions, with configurable OpenSearch backend.
- **Sharing** — Share recordings with other users by email, with view or edit permissions (Google Docs-style).
- **Claude Code Sessions** — Start an interactive Claude terminal session from your browser that reads your transcription. Each user provides their own Anthropic API key.
- **Admin Back-Office** — EasyAdmin dashboard for user management.

## Infrastructure Architecture

### Why workers for transcription

Transcription requests to OpenAI with large audio files can take a very long time — minutes, not milliseconds. Running these in the HTTP request path would mean the user stares at a loading spinner for the entire duration, and the web server worker is occupied the whole time. Instead, the web request simply enqueues a job and returns immediately. A dedicated Symfony Messenger worker picks up the job, calls OpenAI, and updates the database when done. The user sees the status change in real-time via Mercure SSE.

This separation means the web application stays responsive regardless of how many transcriptions are queued, and if a transcription fails, it can be retried without user intervention.

### Why Mercure for real-time updates

Claude Code sessions are long-lived and interactive — a user might keep a session open for 30 minutes while they work through ideas with Claude. Traditional PHP-FPM ties up one worker per open connection, and with limited workers, even a handful of concurrent sessions would starve the web application of capacity.

Mercure (via Server-Sent Events) handles persistent connections with async I/O, so hundreds of concurrent sessions don't exhaust PHP workers. The web application publishes events to the Mercure hub, which efficiently fans them out to connected browsers.

On Upsun, Mercure runs as a managed service on a dedicated subdomain, keeping it fully decoupled from the PHP-FPM application.

### Why network storage for audio files

Audio files need to be accessible by both the web application (which receives the upload) and the transcription worker (which reads the file to send to OpenAI). On Upsun, the web container and worker containers are separate processes that don't share a filesystem. The `network-storage` service provides a shared mount that both can access, solving this cleanly without needing to store files in the database or an external object store.

### Why PostgreSQL LISTEN/NOTIFY for the message queue

Symfony Messenger needs a transport for async messages. The simplest option is Doctrine transport with PostgreSQL, which reuses the existing database — no additional service to provision, configure, or pay for. PostgreSQL's LISTEN/NOTIFY mechanism provides efficient push-based message delivery (the worker wakes up immediately when a message arrives, rather than polling on a timer). For the throughput this application needs, it's more than sufficient. RabbitMQ or Redis can be swapped in later if needed by changing a single DSN.

## Local Development Setup

### Prerequisites

- PHP 8.4 with extensions: pdo_pgsql, sodium, intl, mbstring, xml
- Composer 2
- PostgreSQL 16
- Symfony CLI (optional, for `symfony server:start`)

### Installation

```bash
git clone <repo-url>
cd braindump
composer install
```

### Configuration

Copy `.env` to `.env.local` and configure:

```bash
# Database
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/braindump?serverVersion=16&charset=utf8"

# OpenAI (for transcription)
OPENAI_API_KEY=sk-...

# Mercure (for local dev, use the Symfony CLI built-in hub)
MERCURE_URL=http://localhost:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://localhost:3000/.well-known/mercure
MERCURE_JWT_SECRET=your-secret-here

# Search (default: postgres)
SEARCH_PROVIDER=postgres
```

### Database Setup

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

### Create an Admin User

```bash
php bin/console app:create-user  # or create via EasyAdmin after first login
```

Or use EasyAdmin at `/admin` (you'll need to insert an admin user directly into the database for the first time).

### Running

```bash
# Web server
symfony server:start

# Transcription worker
php bin/console messenger:consume async --time-limit=3600

# Claude session worker
php bin/console messenger:consume claude --time-limit=3600
```

## Environment Variables

| Variable | Description | Default |
|---|---|---|
| `DATABASE_URL` | PostgreSQL connection string | — |
| `OPENAI_API_KEY` | OpenAI API key for Whisper transcription | — |
| `MERCURE_URL` | Mercure hub URL (server-side) | — |
| `MERCURE_PUBLIC_URL` | Mercure hub URL (browser-side) | — |
| `MERCURE_JWT_SECRET` | JWT secret for Mercure | — |
| `SEARCH_PROVIDER` | Search backend: `postgres` or `opensearch` | `postgres` |
| `OPENSEARCH_URL` | OpenSearch/Elasticsearch URL | — |
| `OPENSEARCH_INDEX` | OpenSearch index name | `braindump_recordings` |
| `APP_SECRET` | Symfony app secret (used to encrypt API keys) | — |

## Deployment on Upsun

The `.upsun/config.yaml` defines the full deployment:

- **Web application**: PHP 8.4 with PHP-FPM
- **Transcription worker**: Consumes the `async` Messenger transport
- **Claude worker**: Consumes the `claude` Messenger transport
- **PostgreSQL 16**: Primary database
- **Mercure**: Managed real-time hub
- **Network storage**: Shared audio file storage

```bash
upsun project:create
upsun push
```

Set environment variables via `upsun variable:create`.

## Tech Stack

- **Symfony 7.4** with PHP 8.4
- **Symfony AI** (OpenAI Whisper) for speech-to-text
- **Symfony Messenger** for async job processing
- **Mercure** for real-time SSE
- **EasyAdmin** for back-office
- **PostgreSQL** with full-text search
- **Stimulus + Turbo** for frontend interactivity
- **xterm.js** for browser-based terminal (Claude sessions)
- **Upsun** (formerly Platform.sh) for deployment
