<?php

namespace JeffersonGoncalves\TelegramLogger;

use JeffersonGoncalves\TelegramLogger\Handlers\TelegramHandler;
use Monolog\Logger as Monolog;

/**
 * Custom Laravel logging channel factory.
 *
 * config/logging.php:
 *
 *   'telegram' => [
 *       'driver' => 'custom',
 *       'via'    => \JeffersonGoncalves\TelegramLogger\Logger::class,
 *       'level'  => 'error',
 *       'token'     => env('LOG_TELEGRAM_BOT_TOKEN'),
 *       'chat_id'   => env('LOG_TELEGRAM_CHAT_ID'),
 *       'thread_id' => env('LOG_TELEGRAM_THREAD_ID'), // optional forum topic
 *   ],
 */
class Logger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Monolog
    {
        // Channel config (url, level, ...) is layered on top of the package config.
        $config = array_replace(
            (array) config('telegram-logger', []),
            $config,
        );

        return new Monolog('telegram', [new TelegramHandler($config)]);
    }
}
