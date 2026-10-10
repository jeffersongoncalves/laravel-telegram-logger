<div class="filament-hidden">

![Laravel Telegram Logger](https://raw.githubusercontent.com/jeffersongoncalves/laravel-telegram-logger/main/art/jeffersongoncalves-laravel-telegram-logger.png)

</div>

# Laravel Telegram Logger

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-FFDD00?style=flat-square&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/jeffersongoncalves)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-telegram-logger.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-telegram-logger)
[![Tests](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-telegram-logger/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-telegram-logger/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-telegram-logger.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-telegram-logger)
[![License](https://img.shields.io/github/license/jeffersongoncalves/laravel-telegram-logger?style=flat-square)](LICENSE.md)

Send Laravel logs to a Telegram chat — built for production. A Monolog channel with **deduplication**, **configurable error grouping**, **rate limiting**, **async delivery** with 429 backoff, **forum topics**, **silent levels** and a **graceful no-op** when the bot isn't configured.

The sibling of [laravel-discord-logger](https://github.com/jeffersongoncalves/laravel-discord-logger): same pipeline, Telegram Bot API transport.

## Installation

```bash
composer require jeffersongoncalves/laravel-telegram-logger
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag="laravel-telegram-logger-config"
```

## Create the bot

1. Talk to [@BotFather](https://t.me/BotFather), send `/newbot` and copy the token (`123456789:AA...`).
2. Add the bot to the group or channel that should receive the logs (in a channel, as an admin that can post).
3. Get the chat id: send any message in the group, then open `https://api.telegram.org/bot<TOKEN>/getUpdates` and read `message.chat.id` (groups are negative, e.g. `-1001234567890`). For a private chat with the bot, it's your user id.
4. Using a supergroup with **topics**? The topic id is `message.message_thread_id` in the same response.

## Configuration

Add a channel to `config/logging.php`:

```php
'telegram' => [
    'driver'    => 'custom',
    'via'       => \JeffersonGoncalves\TelegramLogger\Logger::class,
    'level'     => env('LOG_TELEGRAM_LEVEL', 'error'),
    'token'     => env('LOG_TELEGRAM_BOT_TOKEN'),
    'chat_id'   => env('LOG_TELEGRAM_CHAT_ID'),
    'thread_id' => env('LOG_TELEGRAM_THREAD_ID'), // optional forum topic
],
```

Stack it onto your default channel so errors fan out:

```php
'stack' => [
    'driver'   => 'stack',
    'channels' => ['single', 'telegram'],
    'ignore_exceptions' => false,
],
```

Set the bot (leave empty in local/testing — nothing will be sent, nothing will break):

```dotenv
LOG_TELEGRAM_BOT_TOKEN=123456789:AA...
LOG_TELEGRAM_CHAT_ID=-1001234567890
```

Check it:

```bash
php artisan telegram-logger:test
```

## How it works

### Message format

Each log becomes one HTML message: the level and app name, the (redacted) message, then sections — **Exception**, **Request / Livewire / Job / Command** (where it came from), **Stacktrace**, **Context** and **Extra** — and the time. Everything is HTML-escaped. When the text would pass Telegram's 4096-character limit, sections are dropped from the end, so the message always goes through.

### Error grouping (fingerprint)

`config/telegram-logger.php` → `grouping.strategy`:

- `message` — group by message text
- `level_message` — group by level + message
- `exception` *(default)* — group by exception class + file + line
- a `callback` `fn (\Monolog\LogRecord $record): string` for full control

With `grouping.normalize` on, volatile tokens (numbers, UUIDs, hashes) are stripped before hashing, so `User 123 not found` and `User 456 not found` collapse together.

### Deduplication

Within `deduplication.window` seconds, only the **first** occurrence of a fingerprint is sent. Repeats are counted silently. When the window closes, a single summary (`🔁 Repeated N×`) is delivered — turn it off with `deduplication.summary => false`.

### Rate limiting

- `rate_limit.global` — hard cap on total messages app-wide (default 20/minute, Telegram's limit for a group)
- `rate_limit.per_fingerprint` — extra guard against a single looping error

### Async delivery

Delivery runs through a queued job by default (`queue.enabled`). Telegram 429s are retried after `parameters.retry_after`; an unreachable Telegram (DNS/connect/timeout) or a 5xx is retried with backoff (10s, 30s, 60s, 120s); 4xx errors (bad token, chat not found, bot removed from the chat) fail fast and Telegram's description is written to the fallback channel. After the last attempt the job gives up quietly — the delivery job never throws, so a Telegram outage can't produce Telegram messages about itself. Tune `timeout` / `connect_timeout` (seconds). Set `queue.enabled => false` to send inline (best-effort, errors swallowed).

> **This means a queue worker must be running** (`php artisan queue:work`, Horizon, or supervisor) for `Log::error(...)` calls to actually reach Telegram. `telegram-logger:test` sends inline and bypasses the queue, so it succeeds even with no worker running — don't use it alone to confirm real logging works. If you don't run a worker, set `TELEGRAM_LOGGER_QUEUE=false`.

### State store

Dedup + rate-limit counters live in the cache store named by `store` (null = default). **Use Redis in production** for atomic counters.

### Per-level chats, mentions and silent levels

Route a level to another chat (same bot) or topic, ping someone when it matters, and keep low levels from buzzing phones:

```php
'chats' => [
    'EMERGENCY' => env('TELEGRAM_LOGGER_CHAT_ALERTS'),
    'CRITICAL'  => ['chat_id' => env('TELEGRAM_LOGGER_CHAT_ALERTS'), 'thread_id' => 42],
],

'mentions' => [
    'EMERGENCY' => '@devops_lead @oncall',
],

// Sent with disable_notification (no sound)
'silent' => ['DEBUG', 'INFO', 'NOTICE'],
```

A plain per-level chat id doesn't inherit the channel's `thread_id`; use the array form to target a topic.

### Runtime context

Each message says where it came from (turn off with `TELEGRAM_LOGGER_RUNTIME_CONTEXT=false`):

- **Request** — method, URL, route name, user id (only if already resolved — logging never triggers a user lookup) and IP
- **Livewire** — on a Livewire update request (which always hits `/livewire/update`), the component name(s) it targeted and the page path, read from the component snapshots
- **Job** — class, queue, connection, attempt and id of the queued job being processed, including when the log is the job's own failure
- **Command** — the artisan command name (never its arguments, which may carry secrets), for console logs outside a job

To add your own sections — the tenant, the user kept in the session... — point `context_resolver` in `config/telegram-logger.php` to an invokable class. It runs at log time; each key becomes a section (a scalar, or an array shown as `key: value` lines), redacted like the rest. It works with `runtime_context` off too, and a resolver that throws only costs its own sections:

```php
// config/telegram-logger.php
'context_resolver' => App\Logging\TelegramContext::class,

// app/Logging/TelegramContext.php
class TelegramContext
{
    public function __invoke(): array
    {
        if (! session('logged_in')) {
            return ['User' => 'Guest'];
        }

        return [
            'Company' => session('company.name'),
            'User' => ['id' => session('user_id'), 'name' => session('user_name')],
        ];
    }
}
```

Anything in the record's `extra` — e.g. data added with Laravel's `Context::add()`, or Monolog processors — is shown in an **Extra** section. All of it goes through redaction, so `?token=...` in a URL is masked.

### Full stacktrace document

The message shows at most 1000 characters of stacktrace (vendor frames dropped in `smart` mode). When the exception is longer, the whole thing — message, every frame and each chained previous exception, redacted — is also sent as a `stacktrace.txt` document replying to the message, so nothing is lost to truncation. Disable with `TELEGRAM_LOGGER_ATTACH_STACKTRACE=false`; it's also skipped when `stacktrace` is `none`. The file is capped at 64 KiB to keep the queued job under SQS's 256 KiB limit.

### Context redaction

Sensitive data is masked before it ever reaches Telegram, via two complementary strategies:

- **Key matching** (`redact`) — case-insensitive key fragments whose values are masked. Defaults cover `password`, `secret`, `token`, `authorization`, `api_key`, `apikey`. Scrubbing recurses into nested arrays **and** the public properties of objects carried in the context.
- **Value patterns** (`redact_value_patterns`) — PCRE regexes matched against scalar *values*, so a secret leaking under an innocuous key (e.g. `url`, `auth`) — or inside the message text and the stacktrace — is still caught. Defaults cover `Bearer` tokens, JWTs, `Basic` credentials, sensitive `"key":"value"` pairs inside serialized JSON text, and `?token=...`-style query strings; leave the array empty to disable value-based redaction. A pattern may declare a named `(?<safe>...)` group to keep that part of the match in the output (e.g. the key name) — any other capture group is ignored, so it can't leak part of the secret back out.

### Fallback channel & safety

The handler **never throws** and is guarded against logging-while-logging recursion. If delivery fails (inline, or after the queued job's last retry), the error is swallowed; set `fallback_channel` (e.g. `'single'`) to record those failures instead of losing them. The fallback message is redacted, and a fallback channel that reaches Telegram (the Telegram channel itself, or a stack containing it) is ignored so a failure can't loop.

Bot tokens (`<bot id>:<secret>`, alone or inside `api.telegram.org/bot.../` URLs) are **always** masked by the redactor — in fallback messages, `telegram-logger:test` output and anything sent to Telegram — independently of `redact_value_patterns`.

## Troubleshooting

**`telegram-logger:test` works, but `Log::error(...)` never shows up in Telegram.**

The test command sends inline. Real log calls go through a queued job by default (see [Async delivery](#async-delivery)), so nothing is delivered until a queue worker processes it. Run a worker (`php artisan queue:work`, or Horizon/supervisor in production), **or** set `TELEGRAM_LOGGER_QUEUE=false`. On a fresh Laravel install `QUEUE_CONNECTION` defaults to `database`, but no worker runs automatically — jobs just sit pending in the `jobs` table.

Also check the channel's `level` in `config/logging.php` — if it's above the level you're logging at (e.g. `level => 'error'` while calling `Log::info(...)`), Monolog filters the record before it reaches the handler.

**`Bad Request: chat not found` / `Forbidden: bot was kicked`.** The bot must be a member of the chat (an admin that can post, in a channel), and group ids are negative. `telegram-logger:test` prints Telegram's description.

## Commands

```bash
# Publish the config + optionally star the repo
php artisan telegram-logger:install

# Send a test message to verify the bot and chat
php artisan telegram-logger:test --channel=telegram

# Send a sample exception through the real converter (message + stacktrace.txt)
php artisan telegram-logger:test --exception
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Jefferson Gonçalves](https://github.com/jeffersongoncalves)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). See [License File](LICENSE.md).
