## Laravel Telegram Logger

### Overview
Laravel Telegram Logger is a Monolog channel that sends application logs to a Telegram chat through the Bot API. It is built for production: it deduplicates repeated errors, groups them by a configurable fingerprint, rate-limits delivery, sends asynchronously through a queued job, and silently no-ops when no bot token or chat id is configured.

**Namespace:** `JeffersonGoncalves\TelegramLogger`
**Service Provider:** `TelegramLoggerServiceProvider` (auto-discovered)

### Key Concepts
- **No-op safety:** If the channel `token` or `chat_id` is empty, or `enabled` is false, the handler does nothing and never throws — local/testing environments stay quiet.
- **Deduplication:** Repeats of the same fingerprint inside `deduplication.window` collapse to a single message, then an optional "Repeated N×" summary job fires when the window closes.
- **Grouping/fingerprint:** `grouping.strategy` = `message` | `level_message` | `exception` | a callback. `grouping.normalize` collapses ids/UUIDs/hashes.
- **Rate limiting:** Two tiers — `rate_limit.global` (default 20/min) and `rate_limit.per_fingerprint` — backed by the configured cache `store`.
- **Async delivery:** A queued `SendTelegramMessage` job honours Telegram's 429 `parameters.retry_after`; 4xx fails fast and reports Telegram's description to `fallback_channel`.
- **Routing:** `chats` sends a level to another chat or forum topic; `mentions` prepends `@username`s; `silent` levels go out with `disable_notification`.
- **Redaction:** Context keys matching `redact` and values matching `redact_value_patterns` are masked; bot tokens are always masked.

### Setup
Add a custom channel in `config/logging.php`:

@verbatim
<code-snippet name="logging-channel" lang="php">
'telegram' => [
    'driver'    => 'custom',
    'via'       => \JeffersonGoncalves\TelegramLogger\Logger::class,
    'level'     => env('LOG_TELEGRAM_LEVEL', 'error'),
    'token'     => env('LOG_TELEGRAM_BOT_TOKEN'),
    'chat_id'   => env('LOG_TELEGRAM_CHAT_ID'),
    'thread_id' => env('LOG_TELEGRAM_THREAD_ID'), // optional forum topic
],
</code-snippet>
@endverbatim

### Usage

@verbatim
<code-snippet name="logging" lang="php">
use Illuminate\Support\Facades\Log;

Log::channel('telegram')->error('Payment failed', ['order_id' => 4711]);

// Verify the bot and chat from the CLI:
// php artisan telegram-logger:test --channel=telegram
</code-snippet>
@endverbatim

### Architecture
- `Logger` — the channel factory referenced by `via`; builds a Monolog logger with `TelegramHandler`.
- `Handlers\TelegramHandler` — orchestrates: resolve destination → fingerprint → dedup → rate-limit → convert → dispatch. Wrapped in a recursion guard and try/catch (optionally logs failures to `fallback_channel`).
- `Support\Destination` (token + chat + topic) / `Fingerprinter` / `Deduplicator` / `TelegramRateLimiter` / `Redactor` / `MessageDispatcher`.
- `Converters\RichRecordConverter` (HTML with sections) and `SimpleRecordConverter` (one line); implement `Converter` (returns `text` + optional `files`) for custom output.
- `Jobs\SendTelegramMessage` and `Jobs\SendDeduplicationSummary`.
- `Transport\TelegramBot` — `sendMessage` + `sendDocument` for the stacktrace.

### Conventions
- State (dedup + rate-limit counters) lives in the cache store named by config `store`; use Redis in production.
- The handler never throws; failures are swallowed or sent to `fallback_channel`.
- Message text is HTML: escape anything user-provided with `htmlspecialchars` (`ENT_NOQUOTES`).
- Config file: `config/telegram-logger.php` (publish with `php artisan telegram-logger:install`).
