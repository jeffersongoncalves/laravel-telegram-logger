<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\TelegramLogger\Converters\SimpleRecordConverter;
use JeffersonGoncalves\TelegramLogger\Jobs\SendDeduplicationSummary;
use JeffersonGoncalves\TelegramLogger\Jobs\SendTelegramMessage;

beforeEach(function () {
    // Send inline so we can assert the actual Bot API calls.
    config()->set('telegram-logger.queue.enabled', false);
    config()->set('telegram-logger.deduplication.summary', false);
    Cache::flush();
    Http::fake();
});

it('sends a log record to the telegram chat', function () {
    Log::channel('telegram')->error('Something broke');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/bot'.BOT_TOKEN.'/sendMessage'
        && $request['chat_id'] === '-1001234567890'
        && str_contains($request['text'], 'Something broke'));
});

it('does nothing and never throws when the bot token or chat id is empty', function (string $key) {
    config()->set("logging.channels.telegram.{$key}", '');

    Log::channel('telegram')->error('Not configured');

    Http::assertNothingSent();
})->with(['token', 'chat_id']);

it('does nothing when disabled', function () {
    config()->set('telegram-logger.enabled', false);

    Log::channel('telegram')->error('Disabled');

    Http::assertNothingSent();
});

it('deduplicates repeated identical errors within the window', function () {
    Log::channel('telegram')->error('Repeated error');
    Log::channel('telegram')->error('Repeated error');
    Log::channel('telegram')->error('Repeated error');

    Http::assertSentCount(1);
});

it('groups numerically-different messages via normalization', function () {
    config()->set('telegram-logger.grouping.strategy', 'message');

    Log::channel('telegram')->error('User 123 not found');
    Log::channel('telegram')->error('User 456 not found');

    Http::assertSentCount(1);
});

it('sends distinct errors separately', function () {
    config()->set('telegram-logger.grouping.strategy', 'message');
    config()->set('telegram-logger.grouping.normalize', false);

    Log::channel('telegram')->error('First problem');
    Log::channel('telegram')->error('Second problem');

    Http::assertSentCount(2);
});

it('enforces the global rate limit', function () {
    config()->set('telegram-logger.deduplication.enabled', false);
    config()->set('telegram-logger.rate_limit.per_fingerprint.max', 0);
    config()->set('telegram-logger.rate_limit.global.max', 2);

    Log::channel('telegram')->error('a');
    Log::channel('telegram')->error('b');
    Log::channel('telegram')->error('c');

    Http::assertSentCount(2);
});

it('respects the configured log level', function () {
    config()->set('logging.channels.telegram.level', 'error');

    Log::channel('telegram')->info('ignored');
    Log::channel('telegram')->error('captured');

    Http::assertSentCount(1);
});

it('dispatches a queued job when queue delivery is enabled', function () {
    config()->set('telegram-logger.queue.enabled', true);
    Bus::fake();

    Log::channel('telegram')->error('queued error');

    Bus::assertDispatched(SendTelegramMessage::class, fn (SendTelegramMessage $job) => $job->destination === DESTINATION);
});

it('enforces the per-fingerprint rate limit independently of dedup', function () {
    config()->set('telegram-logger.deduplication.enabled', false);
    config()->set('telegram-logger.rate_limit.global.max', 100);
    config()->set('telegram-logger.rate_limit.per_fingerprint.max', 1);
    config()->set('telegram-logger.grouping.strategy', 'message');
    config()->set('telegram-logger.grouping.normalize', false);

    Log::channel('telegram')->error('looping error');
    Log::channel('telegram')->error('looping error');

    Http::assertSentCount(1);
});

it('routes a level to its dedicated chat', function () {
    config()->set('telegram-logger.chats.ERROR', '-100999');

    Log::channel('telegram')->error('routed');

    Http::assertSent(fn (Request $request) => $request['chat_id'] === '-100999' && ! isset($request['message_thread_id']));
});

it('routes a level to a forum topic', function () {
    config()->set('telegram-logger.chats.ERROR', ['chat_id' => '-100999', 'thread_id' => 7]);

    Log::channel('telegram')->error('routed to topic');

    Http::assertSent(fn (Request $request) => $request['chat_id'] === '-100999' && $request['message_thread_id'] === 7);
});

it('uses the channel thread id for the default chat', function () {
    config()->set('logging.channels.telegram.thread_id', 12);

    Log::channel('telegram')->error('topic');

    Http::assertSent(fn (Request $request) => $request['message_thread_id'] === 12);
});

it('prepends the escaped mention for a configured level', function () {
    config()->set('telegram-logger.mentions.ERROR', '@devops_lead <x>');

    Log::channel('telegram')->error('ping me');

    Http::assertSent(fn (Request $request) => str_starts_with($request['text'], "@devops_lead &lt;x&gt;\n"));
});

it('sends quiet levels without a notification', function () {
    config()->set('telegram-logger.grouping.normalize', false);

    Log::channel('telegram')->info('just info');
    Log::channel('telegram')->error('real problem');

    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'just info') && $request['disable_notification'] === true);
    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'real problem') && ! isset($request['disable_notification']));
});

it('falls back to the rich converter when the configured converter is invalid', function () {
    config()->set('telegram-logger.converter', 'This\\Class\\Does\\Not\\Exist');

    Log::channel('telegram')->error('invalid converter');

    Http::assertSent(fn (Request $request) => str_contains($request['text'], '<b>'));
});

it('records the failure to the fallback channel when delivery throws', function () {
    $path = tempnam(sys_get_temp_dir(), 'telegram-fallback-').'.log';

    config()->set('logging.channels.fallbacktest', [
        'driver' => 'single',
        'path' => $path,
        'level' => 'debug',
    ]);
    config()->set('telegram-logger.fallback_channel', 'fallbacktest');

    Http::fake(function () {
        throw new RuntimeException('network down for https://api.telegram.org/bot'.BOT_TOKEN.'/sendMessage');
    });

    Log::channel('telegram')->error('boom');

    expect(file_get_contents($path))
        ->toContain('Telegram logger delivery failed: network down')
        ->toContain('api.telegram.org/bot[REDACTED]/sendMessage')
        ->not->toContain('AAsEcReT-ToKeN');

    @unlink($path);
});

it('does not recurse when the fallback channel is the telegram channel itself', function () {
    // A failed send routed back into the same channel must hit the recursion
    // guard (self::$handling) and return, not loop forever.
    config()->set('telegram-logger.fallback_channel', 'telegram');

    Http::fake(function () {
        throw new RuntimeException('network down');
    });

    Log::channel('telegram')->error('boom');
})->throwsNoExceptions();

it('schedules the dedup summary with a delay equal to the window', function () {
    config()->set('telegram-logger.queue.enabled', true);
    config()->set('telegram-logger.deduplication.enabled', true);
    config()->set('telegram-logger.deduplication.summary', true);
    config()->set('telegram-logger.deduplication.window', 123);
    Bus::fake();

    Log::channel('telegram')->error('delayed summary');

    Bus::assertDispatched(
        SendDeduplicationSummary::class,
        fn (SendDeduplicationSummary $job) => $job->delay === 123 && $job->destination === DESTINATION,
    );
});

it('redacts sensitive context keys before sending', function () {
    Log::channel('telegram')->error('login attempt', [
        'user' => 'alice',
        'password' => 'hunter2',
    ]);

    Http::assertSent(function (Request $request) {
        $body = $request->body();

        return str_contains($body, '[REDACTED]') && ! str_contains($body, 'hunter2');
    });
});

it('can use the simple converter', function () {
    config()->set('telegram-logger.converter', SimpleRecordConverter::class);

    Log::channel('telegram')->error('plain message');

    Http::assertSent(fn (Request $request) => str_contains($request['text'], 'plain message'));
});

it('never throws when the transport fails and stays silent without a fallback', function () {
    Http::fake(function () {
        throw new RuntimeException('network down');
    });

    Log::channel('telegram')->error('boom');
})->throwsNoExceptions();
