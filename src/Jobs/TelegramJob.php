<?php

namespace JeffersonGoncalves\TelegramLogger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JeffersonGoncalves\TelegramLogger\Support\Fallback;
use JeffersonGoncalves\TelegramLogger\Transport\TelegramBot;
use Throwable;

abstract class TelegramJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** Seconds before each retry while Telegram is unreachable or erroring. */
    protected const BACKOFF = [10, 30, 60, 120, 300];

    /**
     * Send and handle every outcome here. This never throws: the worker reports
     * a job's exception through the app's log stack — which usually includes the
     * Telegram channel — so a throw would post a message about failing to post
     * the previous one (a loop), with the bot token in it.
     *
     * @param  array{token: string, chat_id: string, thread_id?: int}  $destination
     * @param  array<string, mixed>  $payload
     */
    protected function deliver(TelegramBot $transport, array $destination, array $payload): void
    {
        try {
            $response = $transport->send($destination, $payload);
        } catch (Throwable $e) {
            // DNS/connect/timeout (ConnectionException) or anything unexpected.
            $this->retryOrGiveUp($e->getMessage());

            return;
        }

        if ($response->status() === 429) {
            // Telegram says how long to wait in parameters.retry_after.
            $retryAfter = $response->json('parameters.retry_after') ?: $response->header('Retry-After') ?: 2;

            $this->retryOrGiveUp('Telegram rate limit (HTTP 429)', max(1, (int) ceil((float) $retryAfter)));

            return;
        }

        if ($response->serverError()) {
            $this->retryOrGiveUp("Telegram responded with HTTP {$response->status()}");

            return;
        }

        // 4xx (bad token, chat not found, bot kicked) — retrying can't help, but
        // say why: Telegram's description is the only clue to the misconfiguration.
        if ($response->clientError()) {
            Fallback::report(
                (array) config('telegram-logger', []),
                "Telegram responded with HTTP {$response->status()}: ".($response->json('description') ?? 'no description'),
            );

            $this->fail();
        }
    }

    /**
     * Retry with backoff while attempts remain; after the last one, record the
     * failure where it cannot loop and drop the job — releasing past $tries
     * would make the worker throw MaxAttemptsExceededException instead.
     */
    private function retryOrGiveUp(string $reason, ?int $delay = null): void
    {
        $attempt = $this->attempts();

        if ($attempt < $this->tries) {
            $this->release($delay ?? static::BACKOFF[min($attempt, count(static::BACKOFF)) - 1]);

            return;
        }

        Fallback::report((array) config('telegram-logger', []), "{$reason} (gave up after {$attempt} attempts)");

        $this->delete();
    }
}
