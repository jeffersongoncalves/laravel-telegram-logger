<?php

namespace JeffersonGoncalves\TelegramLogger\Converters;

use JeffersonGoncalves\TelegramLogger\Support\Redactor;
use Monolog\LogRecord;
use Throwable;

/**
 * Renders a record as an HTML message: title, message, then sections
 * (exception, runtime, stacktrace, context, extra) dropped from the end when
 * the text would exceed Telegram's limit.
 */
class RichRecordConverter implements Converter
{
    /** Telegram hard limit for a message text. */
    private const TEXT_MAX = 4096;

    /** Message characters shown before the sections. */
    private const MESSAGE_MAX = 1500;

    /** Characters of a single section body. */
    private const SECTION_MAX = 1000;

    /** Stacktrace characters shown inline. */
    private const TRACE_MAX = 1000;

    /**
     * In BYTES: the queued job is serialized, then JSON-encoded, which can
     * double escape-heavy text (`\` and newlines, everywhere in a trace). 64 KiB
     * x2 plus the message stays under SQS's 256 KiB message limit (Vapor).
     * ponytail: fixed cap; make it configurable if a real trace ever needs more.
     */
    private const ATTACHMENT_MAX_BYTES = 64 * 1024;

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
        $head = '<b>'.$this->e($this->title($record)).'</b>'
            // The message is raw user/exception text — redact before it ships.
            ."\n".$this->e($this->truncate($this->redactor->scrubString($record->message), self::MESSAGE_MAX));

        $foot = "\n\n<i>".$this->e($record->datetime->format('Y-m-d H:i:s T')).'</i>';

        $text = $head;
        foreach ($this->sections($record) as $section) {
            if (mb_strlen($text."\n\n".$section.$foot) > self::TEXT_MAX) {
                break;
            }
            $text .= "\n\n".$section;
        }

        return array_filter([
            'text' => $text.$foot,
            'files' => $this->attachments($record),
        ], fn ($v) => $v !== null);
    }

    public function title(LogRecord $record): string
    {
        $level = $record->level->getName();
        $emoji = $this->config['emojis'][$level] ?? '';

        return trim($emoji.' '.$level.' · '.($this->config['from']['name'] ?? 'Log'));
    }

    /**
     * The full exception as a .txt document when it can't fit in the message,
     * so nothing is lost to truncation.
     *
     * @return array<string, string>|null filename => contents
     */
    private function attachments(LogRecord $record): ?array
    {
        $exception = $record->context['exception'] ?? null;

        if (! $exception instanceof Throwable
            || ($this->config['stacktrace'] ?? 'smart') === 'none'
            || ($this->config['attach_stacktrace'] ?? true) !== true) {
            return null;
        }

        // Message, every frame (vendor included) and each chained previous exception.
        $full = $this->redactor->scrubString((string) $exception);

        if (mb_strlen($full) <= self::TRACE_MAX) {
            return null;
        }

        // Valid UTF-8 (invalid bytes would make the queue's json_encode fail and
        // lose the whole log), cut on a character boundary within the byte cap.
        $full = mb_scrub($full, 'UTF-8');

        if (strlen($full) > self::ATTACHMENT_MAX_BYTES) {
            $full = mb_strcut($full, 0, self::ATTACHMENT_MAX_BYTES - strlen('…'), 'UTF-8').'…';
        }

        return ['stacktrace.txt' => $full];
    }

    /**
     * Rendered sections, highest signal first (the length clamp drops the tail).
     *
     * @return list<string>
     */
    private function sections(LogRecord $record): array
    {
        $sections = [];

        $exception = $record->context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $sections[] = '<b>Exception</b>'."\n".'<code>'.$this->e($exception::class.' @ '.$exception->getFile().':'.$exception->getLine()).'</code>';
        }

        $runtime = $record->extra['runtime'] ?? [];
        foreach (is_array($runtime) ? $runtime : [] as $name => $data) {
            if (is_array($data) && $data !== []) {
                $sections[] = '<b>'.$this->e(ucfirst((string) $name)).'</b>'."\n".$this->lines($this->redactor->scrub($data));
            } elseif (is_scalar($data) && $data !== '') {
                $sections[] = '<b>'.$this->e(ucfirst((string) $name)).'</b> <code>'.$this->e($this->redactor->scrubString((string) $data)).'</code>';
            }
        }

        if ($exception instanceof Throwable) {
            $trace = $this->stacktrace($exception);
            if ($trace !== null) {
                $sections[] = '<b>Stacktrace</b>'."\n".'<pre>'.$this->e($trace).'</pre>';
            }
        }

        $context = array_filter($record->context, fn ($key) => $key !== 'exception', ARRAY_FILTER_USE_KEY);
        if ($context !== []) {
            $sections[] = '<b>Context</b>'."\n".$this->jsonBlock($context);
        }

        // Everything else in `extra`: Laravel's Context::add() data, Monolog processors.
        $extra = array_filter($record->extra, fn ($key) => $key !== 'runtime', ARRAY_FILTER_USE_KEY);
        if ($extra !== []) {
            $sections[] = '<b>Extra</b>'."\n".$this->jsonBlock($extra);
        }

        return $sections;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function jsonBlock(array $data): string
    {
        return '<pre><code class="language-json">'
            .$this->e($this->truncate($this->json($this->redactor->scrub($data)), self::SECTION_MAX))
            .'</code></pre>';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function lines(array $data): string
    {
        $lines = [];

        foreach ($data as $key => $value) {
            $value = is_scalar($value) ? (string) $value : $this->json((array) $value);
            $lines[] = '<b>'.$this->e((string) $key).':</b> <code>'.$this->e($this->truncate($value, 300)).'</code>';
        }

        return implode("\n", $lines);
    }

    private function stacktrace(Throwable $exception): ?string
    {
        $mode = $this->config['stacktrace'] ?? 'smart';

        if ($mode === 'none') {
            return null;
        }

        // The trace can embed argument values / paths with secrets — redact it.
        $trace = $this->redactor->scrubString($exception->getTraceAsString());

        if ($mode === 'smart') {
            // Drop vendor frames to keep the message focused and small.
            $trace = implode("\n", array_filter(
                explode("\n", $trace),
                fn ($line) => ! self::isVendorFrame($line),
            ));
        }

        return $this->truncate($trace, self::TRACE_MAX);
    }

    /** Either separator: frame paths are `\vendor\` on Windows, `/vendor/` elsewhere. */
    private static function isVendorFrame(string $line): bool
    {
        return preg_match('#[\\\\/]vendor[\\\\/]#', $line) === 1;
    }

    /** Escape for Telegram's HTML parse mode (only <, >, & and quotes matter). */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1).'…';
    }
}
