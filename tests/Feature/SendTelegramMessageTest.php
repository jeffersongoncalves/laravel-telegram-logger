<?php

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JeffersonGoncalves\TelegramLogger\Jobs\SendTelegramMessage;
use JeffersonGoncalves\TelegramLogger\Logger;
use JeffersonGoncalves\TelegramLogger\Transport\TelegramBot;

function queuedAt(int $attempt): array
{
    $job = new SendTelegramMessage(DESTINATION, ['text' => 'x']);

    $queueJob = Mockery::mock(JobContract::class);
    $queueJob->allows(['attempts' => $attempt]);
    $job->setJob($queueJob);

    return [$job, $queueJob];
}

function telegramUnreachable(): void
{
    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Resolving timed out after 10001 milliseconds for https://api.telegram.org/bot'.BOT_TOKEN.'/sendMessage');
    });
}

function fallbackLog(): string
{
    $path = tempnam(sys_get_temp_dir(), 'telegram-fallback-').'.log';

    config()->set('logging.channels.fallbacktest', ['driver' => 'single', 'path' => $path, 'level' => 'debug']);
    config()->set('telegram-logger.fallback_channel', 'fallbacktest');

    return $path;
}

it('releases the job for parameters.retry_after on a 429', function () {
    Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 3]], 429)]);
    [$job, $queueJob] = queuedAt(1);

    $queueJob->shouldReceive('release')->once()->with(3);

    $job->handle(app(TelegramBot::class));
});

it('falls back to the Retry-After header on a 429', function () {
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '7'])]);
    [$job, $queueJob] = queuedAt(1);

    $queueJob->shouldReceive('release')->once()->with(7);

    $job->handle(app(TelegramBot::class));
});

it('fails fast on a 4xx and reports Telegram\'s description', function () {
    $path = fallbackLog();
    Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
    [$job, $queueJob] = queuedAt(1);

    $queueJob->shouldReceive('fail')->once();

    $job->handle(app(TelegramBot::class));

    expect(file_get_contents($path))->toContain('HTTP 400: Bad Request: chat not found');

    @unlink($path);
});

it('succeeds on a 2xx', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    (new SendTelegramMessage(DESTINATION, ['text' => 'x']))->handle(app(TelegramBot::class));

    Http::assertSentCount(1);
});

it('retries an unreachable Telegram with increasing backoff instead of throwing', function (int $attempt, int $delay) {
    telegramUnreachable();
    [$job, $queueJob] = queuedAt($attempt);

    $queueJob->shouldReceive('release')->once()->with($delay);

    $job->handle(app(TelegramBot::class));
})->with([
    'attempt 1' => [1, 10],
    'attempt 2' => [2, 30],
    'attempt 4' => [4, 120],
]);

it('retries a Telegram 5xx with backoff', function () {
    Http::fake(['*' => Http::response('', 502)]);
    [$job, $queueJob] = queuedAt(1);

    $queueJob->shouldReceive('release')->once()->with(10);

    $job->handle(app(TelegramBot::class));
});

it('gives up after the last attempt: fallback only, token masked, job deleted', function () {
    $path = fallbackLog();
    telegramUnreachable();
    [$job, $queueJob] = queuedAt(5);

    $queueJob->shouldReceive('delete')->once();
    $queueJob->shouldNotReceive('release');

    $job->handle(app(TelegramBot::class));

    expect(file_get_contents($path))
        ->toContain('Telegram logger delivery failed: cURL error 28')
        ->toContain('api.telegram.org/bot[REDACTED]/sendMessage')
        ->toContain('gave up after 5 attempts')
        ->not->toContain('AAsEcReT-ToKeN');

    @unlink($path);
});

it('gives up on a 429 at the last attempt instead of releasing past $tries', function () {
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '7'])]);
    [$job, $queueJob] = queuedAt(5);

    $queueJob->shouldReceive('delete')->once();
    $queueJob->shouldNotReceive('release');

    $job->handle(app(TelegramBot::class));
});

it('never writes the failure to a fallback that reaches Telegram', function (array $channel) {
    config()->set('logging.channels.loopy', $channel);
    config()->set('telegram-logger.fallback_channel', 'loopy');
    telegramUnreachable();
    Log::spy();

    [$job, $queueJob] = queuedAt(5);
    $queueJob->shouldReceive('delete')->once();

    $job->handle(app(TelegramBot::class));

    Log::shouldNotHaveReceived('channel');
})->with([
    'the telegram channel' => [['driver' => 'custom', 'via' => Logger::class, ...DESTINATION]],
    'a stack containing it' => [['driver' => 'stack', 'channels' => ['single', 'telegram']]],
]);
