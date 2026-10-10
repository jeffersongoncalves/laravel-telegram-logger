<?php

namespace JeffersonGoncalves\TelegramLogger\Jobs;

use JeffersonGoncalves\TelegramLogger\Transport\TelegramBot;

class SendTelegramMessage extends TelegramJob
{
    /**
     * @param  array{token: string, chat_id: string, thread_id?: int}  $destination
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $destination,
        public array $payload,
    ) {}

    public function handle(TelegramBot $transport): void
    {
        $this->deliver($transport, $this->destination, $this->payload);
    }
}
