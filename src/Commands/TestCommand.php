<?php

namespace JeffersonGoncalves\TelegramLogger\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use JeffersonGoncalves\TelegramLogger\Converters\RichRecordConverter;
use JeffersonGoncalves\TelegramLogger\Support\Destination;
use JeffersonGoncalves\TelegramLogger\Support\Redactor;
use JeffersonGoncalves\TelegramLogger\Transport\TelegramBot;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Throwable;

class TestCommand extends Command
{
    protected $signature = 'telegram-logger:test
        {--channel=telegram : The logging channel to read the bot token and chat id from}
        {--exception : Send a sample exception through the real converter (message + stacktrace.txt document)}';

    protected $description = 'Send a test message to the configured Telegram chat';

    public function handle(TelegramBot $transport): int
    {
        $channel = $this->option('channel');
        $channel = is_string($channel) ? $channel : 'telegram';
        // Channel only (not the package config): the test must reach Telegram
        // even with TELEGRAM_LOGGER_ENABLED=false.
        $destination = Destination::resolve((array) config("logging.channels.{$channel}", []));

        if ($destination === null) {
            $this->components->error("Set both token and chat_id on logging channel [{$channel}].");

            return self::FAILURE;
        }

        $payload = $this->option('exception')
            ? $this->exceptionPayload($channel)
            : ['text' => "✅ <b>Telegram Logger test</b>\nIf you can read this, your bot and chat are configured correctly."];

        try {
            $response = $transport->send($destination, $payload);
        } catch (Throwable $e) {
            // The message carries the API URL — never print the bot token.
            $this->components->error('Delivery failed: '.Redactor::fromConfig((array) config('telegram-logger', []))->scrubString($e->getMessage()));

            return self::FAILURE;
        }

        if ($response->successful()) {
            $this->components->info('Test message delivered to Telegram'.(isset($payload['files']) ? ', with stacktrace.txt attached.' : '.'));

            return self::SUCCESS;
        }

        $this->components->error("Telegram responded with HTTP {$response->status()}: ".($response->json('description') ?? 'no description'));

        return self::FAILURE;
    }

    /**
     * Same converter + config layering as a real log call (see Logger), minus
     * dedup/rate-limit/queue — so what arrives is what an actual error looks like.
     *
     * @return array<string, mixed>
     */
    private function exceptionPayload(string $channel): array
    {
        $config = array_replace(
            (array) config('telegram-logger', []),
            (array) config("logging.channels.{$channel}", []),
        );

        $message = 'Telegram Logger test exception';

        return (new RichRecordConverter($config))->convert(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: $channel,
            level: Level::Error,
            message: $message,
            context: ['exception' => new RuntimeException($message)],
        ));
    }
}
