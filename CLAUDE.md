# Braindump — Developer Guide

## Project Overview

Symfony 7.4 / PHP 8.4 speech-to-text web app. Browser audio recording, OpenAI Whisper transcription via Symfony AI, PostgreSQL FTS, recording sharing, interactive Claude Code terminal sessions. Deployed on Upsun.

## Subagent Workflow (REQUIRED)

When spawning subagents for code changes, you **MUST** use `isolation: "worktree"` so the agent works on an isolated git worktree with its own branch. The agent should:

1. Make all changes on the worktree branch
2. Commit the changes
3. The worktree branch can then be merged into `main` from the main repo

Never have subagents modify the main working directory directly.

## CSS Guidelines (REQUIRED)

- **No inline styles.** Never use `style="..."` attributes in HTML/Twig templates. All styling must go in the `<style>` block in `base.html.twig` or in template-specific `{% block stylesheets %}` overrides.
- **No tables for layout.** `<table>` is only for actual tabular data (e.g., a list of recordings with columns). For page layout, use CSS Grid or Flexbox.
- **Use utility classes** defined in `base.html.twig` (`.flex`, `.gap-1`, `.mb-1`, `.text-muted`, etc.) or create new ones when a pattern repeats.
- **Design system colors:** Primary blue `#4361ee`, text `#111`, muted text `#6b7280`, borders `#e5e7eb`, light bg `#f3f4f6`.
- **Border-radius:** 8px for buttons/inputs, 12px for cards, 9999px for badges/pills.
- **Font sizes:** Use `rem` units. Body text `0.875rem`, headings use the h1/h2/h3 defaults in base.

## Key Architecture

- **Messenger transports:** `async` (transcription), `claude` (sessions), both via Doctrine/PostgreSQL LISTEN/NOTIFY
- **Mercure SSE** for real-time updates (transcription status, Claude session output)
- **Per-user Anthropic API key** stored encrypted with sodium
- **Audio files** on Upsun network-storage (shared between web + worker containers)

## Commands

```bash
# Dev server
symfony server:start

# Workers
php bin/console messenger:consume async --time-limit=3600
php bin/console messenger:consume claude --time-limit=3600

# Create user
php bin/console app:create-user <email> <password> <display-name> [--admin]

# Run tests
php bin/phpunit
```

## Environment Variables

- `DATABASE_URL` — PostgreSQL connection (auto-set on Upsun via `.environment`)
- `OPENAI_WHISPER_API_KEY` — OpenAI API key for Whisper transcription
- `MERCURE_URL` / `MERCURE_PUBLIC_URL` / `MERCURE_JWT_SECRET` — Mercure hub
- `SEARCH_PROVIDER` — `postgres` (default) or `opensearch`
- `APP_SECRET` — Used to derive encryption key for user API keys
