<?php

namespace JeffersonGoncalves\TelegramLogger\Support;

/**
 * Where a message goes: bot token + chat id (+ optional forum topic), from a
 * logging channel config layered over the package config.
 */
final class Destination
{
    /**
     * A per-level chat override (`chats.LEVEL`, a chat id or [chat_id, thread_id])
     * wins over the channel's chat_id/thread_id. Null when disabled or when the
     * token or chat id is missing — the "silent no-op" case.
     *
     * @param  array<string, mixed>  $config
     * @return array{token: string, chat_id: string, thread_id?: int}|null
     */
    public static function resolve(array $config, ?string $level = null): ?array
    {
        if (($config['enabled'] ?? true) !== true) {
            return null;
        }

        $chat = $level !== null ? ($config['chats'][$level] ?? null) : null;

        if (is_array($chat)) {
            [$chatId, $threadId] = [$chat['chat_id'] ?? null, $chat['thread_id'] ?? null];
        } else {
            // A plain per-level chat id is a different chat: the channel's topic doesn't apply.
            [$chatId, $threadId] = [$chat ?? $config['chat_id'] ?? null, $chat === null ? ($config['thread_id'] ?? null) : null];
        }

        $token = $config['token'] ?? null;

        if (! is_string($token) || trim($token) === '' || ! (is_string($chatId) || is_int($chatId)) || trim((string) $chatId) === '') {
            return null;
        }

        $destination = ['token' => trim($token), 'chat_id' => trim((string) $chatId)];

        // env() gives strings; the Bot API documents message_thread_id as an integer.
        if (is_string($threadId) && ctype_digit(trim($threadId))) {
            $threadId = (int) trim($threadId);
        }

        if (is_int($threadId)) {
            $destination['thread_id'] = $threadId;
        }

        return $destination;
    }
}
