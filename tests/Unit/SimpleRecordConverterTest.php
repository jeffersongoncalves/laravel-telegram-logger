<?php

use JeffersonGoncalves\TelegramLogger\Converters\SimpleRecordConverter;
use Monolog\Level;
use Monolog\LogRecord;

function simpleRecord(string $message): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'telegram',
        level: Level::Error,
        message: $message,
    );
}

it('builds one HTML line with level and emoji', function () {
    $converter = new SimpleRecordConverter([
        'emojis' => ['ERROR' => '🔥'],
    ]);

    expect($converter->convert(simpleRecord('Something broke')))
        ->toBe(['text' => '🔥 <b>ERROR</b> Something broke']);
});

it('escapes HTML in the message', function () {
    $payload = (new SimpleRecordConverter([]))->convert(simpleRecord('a < b && <script>'));

    expect($payload['text'])->toBe('<b>ERROR</b> a &lt; b &amp;&amp; &lt;script&gt;');
});

it('keeps long messages within the Telegram limit', function () {
    $payload = (new SimpleRecordConverter([]))->convert(simpleRecord(str_repeat('a', 5000)));

    expect(mb_strlen($payload['text']))->toBeLessThanOrEqual(4096);
});

it('redacts secret values by pattern before sending', function () {
    $converter = new SimpleRecordConverter([
        'redact_value_patterns' => ['/Bearer\s+\S+/i'],
    ]);

    $payload = $converter->convert(simpleRecord('failed with Bearer abc.def.ghi'));

    expect($payload['text'])->toContain('[REDACTED]')
        ->and($payload['text'])->not->toContain('abc.def.ghi');
});
