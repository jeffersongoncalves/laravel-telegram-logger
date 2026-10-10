<?php

namespace JeffersonGoncalves\TelegramLogger\Transport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Telegram Bot API client: sendMessage for the text, sendDocument for each
 * `files` entry (filename => contents), both to the same chat/topic.
 */
class TelegramBot
{
    public const API = 'https://api.telegram.org';

    /**
     * @param  array{token: string, chat_id: string, thread_id?: int}  $destination
     * @param  array<string, mixed>  $payload  `text` (HTML) and optional `files`
     */
    public function send(array $destination, array $payload): Response
    {
        $files = $payload['files'] ?? [];
        unset($payload['files']);

        $target = array_filter([
            'chat_id' => $destination['chat_id'],
            'message_thread_id' => $destination['thread_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->request()->asJson()->post($this->url($destination, 'sendMessage'), [
            ...$target,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            ...$payload,
        ]);

        if (! $response->successful() || ! is_array($files)) {
            return $response;
        }

        // The full stacktrace as a document, replying to the message it belongs to.
        $messageId = (int) $response->json('result.message_id');

        foreach ($files as $name => $contents) {
            $response = $this->request()
                ->attach('document', (string) $contents, (string) $name, ['Content-Type' => 'text/plain; charset=utf-8'])
                ->post($this->url($destination, 'sendDocument'), [
                    ...$target,
                    'reply_parameters' => json_encode(['message_id' => $messageId, 'allow_sending_without_reply' => true]),
                    'disable_notification' => 'true',
                ]);

            if (! $response->successful()) {
                return $response;
            }
        }

        return $response;
    }

    /**
     * @param  array{token: string}  $destination
     */
    private function url(array $destination, string $method): string
    {
        return self::API.'/bot'.$destination['token'].'/'.$method;
    }

    private function request(): PendingRequest
    {
        return Http::timeout((int) config('telegram-logger.timeout', 10))
            ->connectTimeout((int) config('telegram-logger.connect_timeout', 5));
    }
}
