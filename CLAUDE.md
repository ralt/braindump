# Braindump — Developer Guide

## Project Overview

Symfony 7.4 / PHP 8.4 speech-to-text web app. Browser audio recording, OpenAI Whisper transcription via Symfony AI, PostgreSQL FTS, recording sharing, interactive AI terminal sessions (via pi.dev). Deployed on Upsun.

## Subagent Workflow (REQUIRED)

When spawning subagents for code changes, prefer `isolation: "worktree"` so each agent works on its own branch. If worktrees are unavailable, the agent should:

1. Clone the repo to a temp directory under `/tmp/braindump-agent-{task}/`
2. Create a feature branch (`git checkout -b feature/{task-name}`)
3. Make all changes and commit on that branch
4. Push the branch back to the original repo (`git push origin feature/{task-name}`)
5. The parent then merges the branch into `main`

Never have subagents modify the main working directory directly.

## CSS Guidelines (REQUIRED)

- **No inline styles.** Never use `style="..."` attributes in HTML/Twig templates. All styling must go in the `<style>` block in `base.html.twig` or in template-specific `{% block stylesheets %}` overrides.
- **No tables for layout.** `<table>` is only for actual tabular data (e.g., a list of recordings with columns). For page layout, use CSS Grid or Flexbox.
- **Use utility classes** defined in `base.html.twig` (`.flex`, `.gap-1`, `.mb-1`, `.text-muted`, etc.) or create new ones when a pattern repeats.
- **Design system colors:** Primary blue `#4361ee`, text `#111`, muted text `#6b7280`, borders `#e5e7eb`, light bg `#f3f4f6`.
- **Border-radius:** 8px for buttons/inputs, 12px for cards, 9999px for badges/pills.
- **Font sizes:** Use `rem` units. Body text `0.875rem`, headings use the h1/h2/h3 defaults in base.

## Key Architecture

- **FrankenPHP** as the web runtime on Upsun (via `runtime/frankenphp-symfony`)
- **Messenger transports:** `async` (transcription) and `ai-session` (one worker process per session) via Doctrine/PostgreSQL LISTEN/NOTIFY
- **One PHP process per AI session** — dispatched via Messenger, runs a `stream_select` loop with synchronous Mercure publishing. No Revolt event loop needed.
- **Mercure SSE** for real-time updates (transcription status, AI session output) and per-session input/close commands; same-origin path routing (`/.well-known/mercure`)
- **Per-user AI provider API key** stored encrypted via Vault KMS (prod) or plaintext (dev)
- **Audio files** on Upsun network-storage (shared between web + worker containers)
- **Upsun deploy:** `symfony-build` / `symfony-deploy` (installed via Symfony Cloud configurator)

## Commands

```bash
# Dev server
symfony server:start

# Local Mercure hub
./mercure run --config Caddyfile.mercure --adapter caddyfile

# Workers (--sleep=60 avoids poll spam on SQLite; not needed on Upsun where LISTEN/NOTIFY is used)
php bin/console messenger:consume async --time-limit=3600 --sleep=60

# AI session worker (one process per session, dispatched via Messenger)
php bin/console messenger:consume ai-session --time-limit=7200 --sleep=60

# Create user
php bin/console app:create-user <email> <password> <display-name> [--admin]

# Run tests
php bin/phpunit
```

## Environment Variables

See the Environment Variables table in `README.md` for the full list.
