<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JeffersonGoncalves\TelegramLogger\Transport\TelegramBot;

it('sends an HTML message to the chat through sendMessage', function () {
    Http::fake();

    (new TelegramBot)->send(DESTINATION, ['text' => '<b>hi</b>']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/bot'.BOT_TOKEN.'/sendMessage'
        && $request->isJson()
        && $request['chat_id'] === '-1001234567890'
        && $request['text'] === '<b>hi</b>'
        && $request['parse_mode'] === 'HTML'
        && ! isset($request['message_thread_id']));
});

it('targets the forum topic when a thread id is set', function () {
    Http::fake();

    (new TelegramBot)->send([...DESTINATION, 'thread_id' => 42], ['text' => 'hi']);

    Http::assertSent(fn (Request $request) => $request['message_thread_id'] === 42);
});

it('sends files as documents replying to the message', function () {
    Http::fake(['*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);

    (new TelegramBot)->send(DESTINATION, ['text' => 'Não', 'files' => ['stacktrace.txt' => 'full trace']]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sendDocument')
        && $request->isMultipart()
        && $request->hasFile('document', 'full trace', 'stacktrace.txt')
        && str_contains(collect($request->data())->firstWhere('name', 'reply_parameters')['contents'] ?? '', '"message_id":77'));
});

it('skips the documents when the message itself failed', function () {
    Http::fake(['*' => Http::response(['ok' => false], 400)]);

    $response = (new TelegramBot)->send(DESTINATION, ['text' => 'x', 'files' => ['stacktrace.txt' => 'trace']]);

    expect($response->status())->toBe(400);
    Http::assertSentCount(1);
});
