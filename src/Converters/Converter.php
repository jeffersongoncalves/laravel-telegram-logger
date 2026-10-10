<?php

namespace JeffersonGoncalves\TelegramLogger\Converters;

use Monolog\LogRecord;

interface Converter
{
    /**
     * Build a Telegram sendMessage payload from a log record: `text` (HTML,
     * escaped, at most 4096 characters) plus any other sendMessage parameter.
     * An optional `files` key (filename => contents) is sent as documents.
     *
     * @return array<string, mixed>
     */
    public function convert(LogRecord $record): array;
}
