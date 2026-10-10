<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reports success when Telegram accepts the test message', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->artisan('telegram-logger:test')->assertExitCode(0);

    Http::assertSentCount(1);
});

it('sends a sample exception with the stacktrace attached', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->artisan('telegram-logger:test', ['--exception' => true])
        ->expectsOutputToContain('stacktrace.txt attached')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendMessage')
        && str_contains($request['text'], 'Telegram Logger test exception'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendDocument')
        && $request->hasFile('document', null, 'stacktrace.txt'));
});

it('fails when the channel has no chat id', function () {
    config()->set('logging.channels.telegram.chat_id', '');

    $this->artisan('telegram-logger:test')->assertExitCode(1);
});

it('fails with Telegram\'s description when it rejects the test message', function () {
    Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

    $this->artisan('telegram-logger:test')
        ->expectsOutputToContain('Unauthorized')
        ->assertExitCode(1);
});
