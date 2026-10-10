<?php

namespace JeffersonGoncalves\TelegramLogger\Handlers;

use JeffersonGoncalves\TelegramLogger\Converters\Converter;
use JeffersonGoncalves\TelegramLogger\Converters\RichRecordConverter;
use JeffersonGoncalves\TelegramLogger\Jobs\SendDeduplicationSummary;
use JeffersonGoncalves\TelegramLogger\Support\Deduplicator;
use JeffersonGoncalves\TelegramLogger\Support\Destination;
use JeffersonGoncalves\TelegramLogger\Support\Fallback;
use JeffersonGoncalves\TelegramLogger\Support\Fingerprinter;
use JeffersonGoncalves\TelegramLogger\Support\MessageDispatcher;
use JeffersonGoncalves\TelegramLogger\Support\RuntimeContext;
use JeffersonGoncalves\TelegramLogger\Support\TelegramRateLimiter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Throwable;

class TelegramHandler extends AbstractProcessingHandler
{
    /** Guards against logging-while-logging recursion (a failed send that logs back here). */
    private static bool $handling = false;

    private Fingerprinter $fingerprinter;

    private Deduplicator $deduplicator;

    private TelegramRateLimiter $rateLimiter;

    private MessageDispatcher $dispatcher;

    private RuntimeContext $runtime;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config)
    {
        parent::__construct(
            Monolog::toMonologLevel($config['level'] ?? 'debug'),
            $config['bubble'] ?? true,
        );

        $this->fingerprinter = new Fingerprinter($config);
        $this->deduplicator = new Deduplicator($config);
        $this->rateLimiter = new TelegramRateLimiter($config);
        $this->dispatcher = new MessageDispatcher($config);
        $this->runtime = new RuntimeContext;
    }

    protected function write(LogRecord $record): void
    {
        // Logging is infrastructure — it must never throw back into the app, and
        // a failure here must never re-enter this handler.
        if (self::$handling) {
            return;
        }

        self::$handling = true;

        try {
            $this->process($record);
        } catch (Throwable $e) {
            $this->reportFailure($e);
        } finally {
            self::$handling = false;
        }
    }

    private function process(LogRecord $record): void
    {
        $level = $record->level->getName();
        $destination = Destination::resolve($this->config, $level);

        // Missing token/chat id or disabled => silent no-op (local & testing friendly).
        if ($destination === null) {
            return;
        }

        $fingerprint = $this->fingerprinter->fingerprint($record);

        $decision = $this->deduplicator->decide($fingerprint);
        if (! $decision->send) {
            return; // A repeat inside the dedup window — counted, not sent.
        }

        if (! $this->rateLimiter->allow($fingerprint)) {
            return; // Over the volume cap — drop.
        }

        // Captured here, synchronously, while the request/job is still live —
        // and only for records that will actually be sent.
        $runtime = [
            ...(($this->config['runtime_context'] ?? true) === true ? $this->runtime->capture() : []),
            ...$this->customContext(),
        ];

        if ($runtime !== []) {
            $record = $record->with(extra: [...$record->extra, 'runtime' => $runtime]);
        }

        $converter = $this->converter();
        $payload = $this->withSilence($this->withMentions($converter->convert($record), $level), $level);

        $this->dispatcher->send($destination, $payload);

        $this->scheduleSummary($destination, $record, $fingerprint, $converter);
    }

    /**
     * The app's own fields (tenant, logged-in user from the session...), from the
     * `context_resolver` invokable. A class name rather than a closure so the
     * config stays cacheable. A failing resolver costs its fields, never the log.
     *
     * @return array<string, mixed>
     */
    private function customContext(): array
    {
        $resolver = $this->config['context_resolver'] ?? null;

        if ($resolver === null) {
            return [];
        }

        try {
            if (is_string($resolver) && class_exists($resolver)) {
                $resolver = app($resolver);
            }

            $data = is_callable($resolver) ? $resolver() : null;
        } catch (Throwable) {
            return [];
        }

        return is_array($data) ? array_filter(
            $data,
            fn ($value, $key) => is_string($key) && (is_array($value) ? $value !== [] : is_scalar($value)),
            ARRAY_FILTER_USE_BOTH,
        ) : [];
    }

    /**
     * Prepend the level's mention (e.g. "@devops_lead") so critical errors
     * actually alert someone, not just appear in the chat.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withMentions(array $payload, string $level): array
    {
        $mention = $this->config['mentions'][$level] ?? null;

        if (! is_string($mention) || trim($mention) === '' || ! isset($payload['text'])) {
            return $payload;
        }

        $payload['text'] = htmlspecialchars(trim($mention), ENT_NOQUOTES | ENT_SUBSTITUTE)."\n".$payload['text'];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withSilence(array $payload, string $level): array
    {
        if (in_array($level, (array) ($this->config['silent'] ?? []), true)) {
            $payload['disable_notification'] = true;
        }

        return $payload;
    }

    /**
     * @param  array{token: string, chat_id: string, thread_id?: int}  $destination
     */
    private function scheduleSummary(array $destination, LogRecord $record, string $fingerprint, Converter $converter): void
    {
        if (($this->config['deduplication']['enabled'] ?? true) !== true) {
            return;
        }

        if (($this->config['deduplication']['summary'] ?? true) !== true) {
            return;
        }

        // The summary relies on a delayed job — only meaningful with a real queue.
        if (($this->config['queue']['enabled'] ?? true) !== true) {
            return;
        }

        $title = $converter instanceof RichRecordConverter
            ? $converter->title($record)
            : $record->level->getName();

        $job = new SendDeduplicationSummary(
            destination: $destination,
            fingerprint: $fingerprint,
            title: $title,
            config: $this->config,
        );

        $this->dispatcher->queue(
            $job->delay((int) ($this->config['deduplication']['window'] ?? 300)),
        );
    }

    private function converter(): Converter
    {
        $class = $this->config['converter'] ?? RichRecordConverter::class;

        if (! is_string($class) || ! is_a($class, Converter::class, true)) {
            $class = RichRecordConverter::class;
        }

        return new $class($this->config);
    }

    private function reportFailure(Throwable $e): void
    {
        // Redacted (the message can carry the API URL with the bot token), and
        // skipped when the fallback would route back into Telegram.
        Fallback::report($this->config, $e->getMessage());
    }
}
