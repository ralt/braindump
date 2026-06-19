# Braindump — Developer Guide

## Project Overview

Symfony 8.1 / PHP 8.4 speech-to-text web app. Browser audio recording, OpenAI Whisper transcription via Symfony AI, PostgreSQL FTS, and an inline AI rewriting chat (per-user provider keys). Deployed on Symfony Cloud.

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

- **FrankenPHP** as the web runtime on Symfony Cloud (via `runtime/frankenphp-symfony`)
- **Messenger transport:** `async` (transcription jobs) — Doctrine transport storing jobs durably in a database table. On PostgreSQL, `use_notify` lets LISTEN/NOTIFY wake the worker the instant a job lands; the worker still polls the table as a fallback (NOTIFY isn't persistent), which is what keeps a job enqueued mid-deploy from being lost.
- **AI rewriting chat runs inline in the HTTP request** — the request persists the user message, calls the AI provider, and streams reply deltas to a per-session Mercure topic; `ignore_user_abort(true)` keeps the assistant message persisting even if the browser disconnects mid-stream. No dedicated worker or Messenger transport.
- **Mercure SSE** for real-time updates (transcription status and AI chat reply streaming); same-origin path routing (`/.well-known/mercure`)
- **Per-user AI provider API key** stored encrypted at rest — Vault KMS on Symfony Cloud (prod), libsodium keyed from `APP_SECRET` self-hosted/dev
- **Audio files** on Symfony Cloud network-storage (shared between web + worker containers)
- **Symfony Cloud deploy:** `symfony-build` / `symfony-deploy` (installed via Symfony Cloud configurator)

## Commands

```bash
# Dev server
symfony server:start

# Local Mercure hub
./mercure run --config Caddyfile.mercure --adapter caddyfile

# Workers (--sleep=60 avoids poll spam on SQLite; not needed on Symfony Cloud where LISTEN/NOTIFY is used)
php bin/console messenger:consume async --time-limit=3600 --sleep=60

# Create user
php bin/console app:create-user <email> <password> <display-name> [--admin]

# Run tests
php bin/phpunit
```

## Environment Variables

See the Environment Variables table in `README.md` for the full list.
