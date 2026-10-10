<?php

namespace JeffersonGoncalves\TelegramLogger\Jobs;

use JeffersonGoncalves\TelegramLogger\Support\Deduplicator;
use JeffersonGoncalves\TelegramLogger\Support\MessageDispatcher;

/**
 * Runs when a dedup window closes. If the error fired more than once, it posts a
 * single roll-up ("occurred N times") instead of N separate notifications.
 */
class SendDeduplicationSummary extends TelegramJob
{
    /**
     * @param  array{token: string, chat_id: string, thread_id?: int}  $destination
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public array $destination,
        public string $fingerprint,
        public string $title,
        public array $config,
    ) {}

    public function handle(): void
    {
        // Build from the carried config: Deduplicator requires `array $config`,
        // which the container cannot autowire on the queue worker.
        $deduplicator = new Deduplicator($this->config);

        $occurrences = $deduplicator->occurrences($this->fingerprint);
        $deduplicator->forget($this->fingerprint);

        if ($occurrences < 2) {
            return; // No repeats — the first message already said it all.
        }

        $window = (int) ($this->config['deduplication']['window'] ?? 300);
        $title = htmlspecialchars($this->title, ENT_NOQUOTES | ENT_SUBSTITUTE);

        $payload = [
            'text' => "🔁 <b>{$title}</b>\nRepeated <b>{$occurrences}×</b> within the last {$window}s (grouped).",
            'disable_notification' => true,
        ];

        // Hand off instead of sending here: the counter is already forgotten (it
        // opens the next window), so a retry of THIS job would find nothing to
        // report. SendTelegramMessage carries the built payload through its own
        // retries/backoff.
        (new MessageDispatcher($this->config))->queue(new SendTelegramMessage($this->destination, $payload));
    }
}
