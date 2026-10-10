<?php

namespace JeffersonGoncalves\TelegramLogger\Converters;

use JeffersonGoncalves\TelegramLogger\Support\Redactor;
use Monolog\LogRecord;

/**
 * One-line message: emoji, level and the (redacted) log message.
 */
class SimpleRecordConverter implements Converter
{
    private Redactor $redactor;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config)
    {
        $this->redactor = Redactor::fromConfig($config);
    }

    public function convert(LogRecord $record): array
    {
        $level = $record->level->getName();
        $emoji = $this->config['emojis'][$level] ?? '';

        // The message is raw text — redact value patterns before it ships.
        $message = mb_substr($this->redactor->scrubString($record->message), 0, 4000);

        return [
            'text' => trim($emoji.' <b>'.$level.'</b> '.htmlspecialchars($message, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        ];
    }
}
