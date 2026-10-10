<?php

use JeffersonGoncalves\TelegramLogger\Converters\RichRecordConverter;

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When disabled — or when the channel has no bot token or
    | chat id — the handler becomes a no-op and never tries to reach Telegram.
    | This is what keeps your local/testing environments quiet without
    | throwing exceptions.
    |
    */
    'enabled' => env('TELEGRAM_LOGGER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Sender name
    |--------------------------------------------------------------------------
    |
    | Shown in each message title. The avatar/name in the chat are the bot's
    | own (set them with @BotFather).
    |
    */
    'from' => [
        'name' => env('TELEGRAM_LOGGER_FROM_NAME', env('APP_NAME', 'Laravel Logger')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-level chats
    |--------------------------------------------------------------------------
    |
    | Optionally route specific levels to another chat (same bot). Any level
    | not listed here goes to the channel `chat_id` from config/logging.php.
    | A value can be a chat id, or ['chat_id' => ..., 'thread_id' => ...] to
    | target a forum topic.
    |
    */
    'chats' => [
        // 'EMERGENCY' => env('TELEGRAM_LOGGER_CHAT_ALERTS'),
        // 'CRITICAL'  => ['chat_id' => env('TELEGRAM_LOGGER_CHAT_ALERTS'), 'thread_id' => 42],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mentions
    |--------------------------------------------------------------------------
    |
    | Prepend a mention to the message for a given level so important errors
    | actually ping someone, e.g. '@devops_lead' (usernames, space separated).
    |
    */
    'mentions' => [
        // 'EMERGENCY' => '@devops_lead',
        // 'CRITICAL'  => '@devops_lead @oncall',
    ],

    /*
    |--------------------------------------------------------------------------
    | Silent levels
    |--------------------------------------------------------------------------
    |
    | Messages for these levels arrive without a sound/notification
    | (Telegram's disable_notification), so only real problems buzz phones.
    |
    */
    'silent' => ['DEBUG', 'INFO', 'NOTICE'],

    /*
    |--------------------------------------------------------------------------
    | Context redaction
    |--------------------------------------------------------------------------
    |
    | Case-insensitive key fragments whose values are masked before sending, so
    | secrets in the log context never leak into Telegram.
    |
    | `redact_value_patterns` additionally matches against scalar *values* (in
    | context, the message text and the stacktrace), catching secrets that leak
    | under an innocuous key such as `url` or `auth`. They are full PCRE regexes.
    | Leave the array empty to disable value-based redaction entirely.
    |
    */
    'redact' => [
        'password',
        'secret',
        'token',
        'authorization',
        'api_key',
        'apikey',
    ],
    'redact_placeholder' => '[REDACTED]',
    'redact_value_patterns' => [
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',          // Authorization: Bearer <token>
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', // JWT (header.payload.signature)
        '/Basic\s+[A-Za-z0-9+\/]{4,}={0,2}/i',         // Authorization: Basic <base64 user:pass>
        '/(?<safe>"(?:[^"]*)(?:authorization|token|password|secret|api_?key)(?:[^"]*)"\s*:\s*")(?:\\\\.|[^"\\\\])*(?=")/i', // "api_token":"..." inside serialized JSON text
        '/(?<safe>(?:authorization|token|password|secret|api_?key)=)[^&\s"\']+/i', // ?token=... in a logged URL
        '/(?<safe>[\w.-]*(?:authorization|token|password|secret|api_?key)[\w.-]*\'?\s*:\s*)(?:\'[^\']*\'|[^\s,;"\'}\[\]][^\s,;"\'}\]]*)/i', // password: hunter2 (messages, YAML, headers)
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime context
    |--------------------------------------------------------------------------
    |
    | Adds where the log came from as message sections: the HTTP request (method,
    | URL, route, already-resolved user id, IP), the queued job (name, queue,
    | attempts) or the artisan command. Values go through redaction too.
    |
    */
    'runtime_context' => env('TELEGRAM_LOGGER_RUNTIME_CONTEXT', true),

    /*
    | Your own fields, resolved at log time: an invokable class (resolved from
    | the container) returning ['Field name' => value|array, ...]. Each key
    | becomes a message section, redacted like the rest. Works with
    | runtime_context off too. e.g. App\Logging\TelegramContext::class
    */
    'context_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Fallback channel
    |--------------------------------------------------------------------------
    |
    | If delivery to Telegram throws, the error is swallowed (logging must never
    | break the app). Set a channel name here (e.g. 'single') to record those
    | failures somewhere instead of losing them silently. null = stay silent.
    |
    */
    'fallback_channel' => env('TELEGRAM_LOGGER_FALLBACK_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Converter
    |--------------------------------------------------------------------------
    |
    | Turns a Monolog record into a Telegram message payload. Ship a custom class
    | implementing JeffersonGoncalves\TelegramLogger\Converters\Converter to fully
    | control the message shape.
    |
    */
    'converter' => RichRecordConverter::class,

    /*
    |--------------------------------------------------------------------------
    | Stacktrace rendering
    |--------------------------------------------------------------------------
    | smart | full | none
    |
    | attach_stacktrace: when the exception doesn't fit in the message, also
    | send it in full (message, every frame, chained exceptions — redacted) as
    | a stacktrace.txt document replying to it. Ignored when `stacktrace` is
    | 'none'.
    */
    'stacktrace' => env('TELEGRAM_LOGGER_STACKTRACE', 'smart'),
    'attach_stacktrace' => env('TELEGRAM_LOGGER_ATTACH_STACKTRACE', true),

    /*
    |--------------------------------------------------------------------------
    | State store
    |--------------------------------------------------------------------------
    |
    | Cache store used for deduplication + rate-limit counters. null = default
    | store. Redis is strongly recommended in production (atomic increments).
    |
    */
    'store' => env('TELEGRAM_LOGGER_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Async delivery (queue)
    |--------------------------------------------------------------------------
    |
    | When enabled, delivery is pushed to a queued job so the request is never
    | blocked and Telegram 429s are retried with backoff. Set enabled=false to
    | send inline (best-effort, errors swallowed).
    |
    */
    'queue' => [
        'enabled' => env('TELEGRAM_LOGGER_QUEUE', true),
        'connection' => env('TELEGRAM_LOGGER_QUEUE_CONNECTION'),
        'queue' => env('TELEGRAM_LOGGER_QUEUE_NAME'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts (seconds)
    |--------------------------------------------------------------------------
    |
    | When Telegram is unreachable, queued delivery retries with backoff (10s,
    | 30s, 60s, 120s) and then gives up quietly — the failure goes only to the
    | fallback channel, never back to Telegram, with the bot token masked.
    |
    */
    'timeout' => (int) env('TELEGRAM_LOGGER_TIMEOUT', 10),
    'connect_timeout' => (int) env('TELEGRAM_LOGGER_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Error grouping (fingerprint)
    |--------------------------------------------------------------------------
    |
    | How two log records are considered "the same error" for deduplication.
    |
    |   message        -> message text only
    |   level_message  -> level + message text
    |   exception      -> exception class + file + line (falls back to message)
    |
    | normalize: replace digits/UUIDs/hashes in the message before hashing so
    | "User 123 not found" and "User 456 not found" collapse together.
    |
    | callback: a callable(\Monolog\LogRecord $record): string for full control.
    |
    */
    'grouping' => [
        'strategy' => env('TELEGRAM_LOGGER_GROUPING', 'exception'),
        'normalize' => env('TELEGRAM_LOGGER_GROUPING_NORMALIZE', true),
        'callback' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    |
    | Within `window` seconds, only the FIRST occurrence of a fingerprint is
    | sent. Repeats are counted silently. If `summary` is on, a follow-up
    | "occurred N times" message is delivered when the window closes.
    |
    */
    'deduplication' => [
        'enabled' => env('TELEGRAM_LOGGER_DEDUP', true),
        'window' => (int) env('TELEGRAM_LOGGER_DEDUP_WINDOW', 300),
        'summary' => env('TELEGRAM_LOGGER_DEDUP_SUMMARY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | global          -> hard cap on total messages across the whole app
    |                    (Telegram allows ~20 messages/minute in a group).
    | per_fingerprint -> cap on a single error signature (extra guard on top of
    |                    deduplication for long-lived/looping errors).
    |
    */
    'rate_limit' => [
        'enabled' => env('TELEGRAM_LOGGER_RATE_LIMIT', true),
        'global' => [
            'max' => (int) env('TELEGRAM_LOGGER_RATE_GLOBAL_MAX', 20),
            'per_seconds' => (int) env('TELEGRAM_LOGGER_RATE_GLOBAL_WINDOW', 60),
        ],
        'per_fingerprint' => [
            'max' => (int) env('TELEGRAM_LOGGER_RATE_FP_MAX', 1),
            'per_seconds' => (int) env('TELEGRAM_LOGGER_RATE_FP_WINDOW', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Level emojis
    |--------------------------------------------------------------------------
    */
    'emojis' => [
        'DEBUG' => '🐛',
        'INFO' => '💡',
        'NOTICE' => '📌',
        'WARNING' => '⚠️',
        'ERROR' => '🔥',
        'CRITICAL' => '💥',
        'ALERT' => '🚨',
        'EMERGENCY' => '💀',
    ],
];
