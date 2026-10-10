# Changelog

All notable changes to `laravel-telegram-logger` will be documented in this file.

## 1.0.0 - 2026-10-10

First release. Send Laravel logs to a Telegram chat — the sibling of [laravel-discord-logger](https://github.com/jeffersongoncalves/laravel-discord-logger), same production pipeline over the Telegram Bot API.

- **Deduplication** with an `occurred N×` roll-up, **error grouping** (message / level+message / exception / callback) and **rate limiting** (global, default 20/min, + per error).
- **Async delivery** through a queued job: 429s wait `parameters.retry_after`, 5xx/unreachable retry with backoff, 4xx fail fast and report Telegram's description to the fallback channel.
- **HTML messages** with Exception / Request / Livewire / Job / Command / Stacktrace / Context / Extra sections, clamped to 4096 characters; the full stacktrace goes as a `stacktrace.txt` document replying to the message.
- **Routing**: per-level chats and forum topics, `@username` mentions, silent levels (`disable_notification`).
- **Redaction** of keys and value patterns; bot tokens always masked.
- **Graceful no-op** when the token or chat id is missing; `php artisan telegram-logger:test [--exception]`.

Requires PHP 8.2+ and Laravel 12.61+ / 13.
