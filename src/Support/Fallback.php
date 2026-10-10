<?php

namespace JeffersonGoncalves\TelegramLogger\Support;

use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\TelegramLogger\Logger;
use Throwable;

/**
 * Where delivery failures go: the configured fallback channel, redacted, and
 * never a channel that itself reaches Telegram — a failure to deliver must not
 * turn into another delivery (a loop), nor leak the bot token.
 */
class Fallback
{
    /**
     * @param  array<string, mixed>  $config  package config (telegram-logger.*)
     */
    public static function report(array $config, string $message): void
    {
        $channel = $config['fallback_channel'] ?? null;

        if (! is_string($channel) || $channel === '' || self::reachesTelegram($channel)) {
            return;
        }

        try {
            Log::channel($channel)->warning(
                'Telegram logger delivery failed: '.Redactor::fromConfig($config)->scrubString($message),
            );
        } catch (Throwable) {
            // The fallback itself is broken — nothing left to tell.
        }
    }

    /**
     * @param  array<string, true>  $seen
     */
    private static function reachesTelegram(string $channel, array $seen = []): bool
    {
        $config = config("logging.channels.{$channel}");

        if (! is_array($config) || isset($seen[$channel])) {
            return false;
        }

        if (($config['via'] ?? null) === Logger::class) {
            return true;
        }

        if (($config['driver'] ?? null) !== 'stack') {
            return false;
        }

        foreach ((array) ($config['channels'] ?? []) as $inner) {
            if (is_string($inner) && self::reachesTelegram($inner, $seen + [$channel => true])) {
                return true;
            }
        }

        return false;
    }
}
