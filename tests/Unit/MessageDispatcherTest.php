<?php

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use JeffersonGoncalves\TelegramLogger\Jobs\SendTelegramMessage;
use JeffersonGoncalves\TelegramLogger\Support\MessageDispatcher;

it('queues delivery by default', function () {
    Bus::fake();

    $dispatcher = new MessageDispatcher([]);
    $dispatcher->send(DESTINATION, ['text' => 'hi']);

    Bus::assertDispatched(SendTelegramMessage::class);
});

it('sends inline when queueing is disabled', function () {
    Http::fake();

    $dispatcher = new MessageDispatcher(['queue' => ['enabled' => false]]);
    $dispatcher->send(DESTINATION, ['text' => 'hi']);

    Http::assertSentCount(1);
});

it('throws when an inline send fails, so the caller can report it', function () {
    Http::fake(['*' => Http::response('bad', 400)]);

    $dispatcher = new MessageDispatcher(['queue' => ['enabled' => false]]);

    expect(fn () => $dispatcher->send(DESTINATION, ['text' => 'hi']))
        ->toThrow(RequestException::class);
});

it('dispatches onto the configured connection and queue', function () {
    Bus::fake();

    $dispatcher = new MessageDispatcher(['queue' => [
        'enabled' => true,
        'connection' => 'redis',
        'queue' => 'telegram-alerts',
    ]]);
    $dispatcher->send(DESTINATION, ['text' => 'hi']);

    Bus::assertDispatched(SendTelegramMessage::class, function (SendTelegramMessage $job) {
        return $job->connection === 'redis' && $job->queue === 'telegram-alerts';
    });
});

it('queues a pre-built job as-is via queue()', function () {
    Bus::fake();

    $dispatcher = new MessageDispatcher([]);
    $job = new SendTelegramMessage(DESTINATION, ['text' => 'hi']);

    $dispatcher->queue($job);

    Bus::assertDispatched(SendTelegramMessage::class);
});
