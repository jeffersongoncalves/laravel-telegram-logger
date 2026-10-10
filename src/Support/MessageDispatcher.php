<?php

namespace JeffersonGoncalves\TelegramLogger\Support;

use JeffersonGoncalves\TelegramLogger\Jobs\SendTelegramMessage;
use JeffersonGoncalves\TelegramLogger\Jobs\TelegramJob;
use JeffersonGoncalves\TelegramLogger\Transport\TelegramBot;

/**
 * Routes outbound messages either through the queue (default) or inline.
 */
class MessageDispatcher
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @param  array{token: string, chat_id: string, thread_id?: int}  $destination
     * @param  array<string, mixed>  $payload
     */
    public function send(array $destination, array $payload): void
    {
        if (($this->config['queue']['enabled'] ?? true) === true) {
            $this->queue(new SendTelegramMessage($destination, $payload));

            return;
        }

        // Inline: surface a failed response as an exception so it reaches the
        // fallback channel — no queue/retry here to report it any other way.
        app(TelegramBot::class)->send($destination, $payload)->throw();
    }

    public function queue(TelegramJob $job): void
    {
        if ($connection = $this->config['queue']['connection'] ?? null) {
            $job->onConnection($connection);
        }

        if ($queue = $this->config['queue']['queue'] ?? null) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }
}
