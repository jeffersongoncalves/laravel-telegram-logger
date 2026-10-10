<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JeffersonGoncalves\TelegramLogger\Jobs\SendDeduplicationSummary;
use JeffersonGoncalves\TelegramLogger\Support\Deduplicator;

it('resolves and posts the roll-up through the container without binding errors', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $config = config('telegram-logger');
    $config['deduplication']['enabled'] = true;

    $fingerprint = 'dedup-fingerprint';

    // Prime two occurrences so the roll-up actually fires.
    $dedup = new Deduplicator($config);
    $dedup->decide($fingerprint);
    $dedup->decide($fingerprint);

    $job = new SendDeduplicationSummary(
        destination: DESTINATION,
        fingerprint: $fingerprint,
        title: 'Boom <script>',
        config: $config,
    );

    // app()->call mirrors how the queue worker invokes handle(): Deduplicator
    // requires `array $config` and used to throw BindingResolutionException here.
    app()->call([$job, 'handle']);

    Http::assertSent(fn (Request $request) => str_contains($request['text'], '<b>Boom &lt;script&gt;</b>')
        && str_contains($request['text'], 'Repeated <b>2×</b>')
        && $request['disable_notification'] === true);
});

it('skips the roll-up when the error did not repeat', function () {
    Http::fake();

    $config = config('telegram-logger');
    $config['deduplication']['enabled'] = true;

    $fingerprint = 'single-occurrence';
    (new Deduplicator($config))->decide($fingerprint); // count = 1

    $job = new SendDeduplicationSummary(
        destination: DESTINATION,
        fingerprint: $fingerprint,
        title: 'Boom',
        config: $config,
    );

    app()->call([$job, 'handle']);

    Http::assertNothingSent();
});
