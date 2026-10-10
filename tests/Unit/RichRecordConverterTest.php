<?php

use JeffersonGoncalves\TelegramLogger\Converters\RichRecordConverter;
use JeffersonGoncalves\TelegramLogger\Jobs\SendTelegramMessage;

/** Body of a "<b>Name</b>\n<pre>...</pre>" section, or '' when absent. */
function section(string $text, string $name): string
{
    return preg_match('#<b>'.preg_quote($name, '#').'</b>\n<pre>(?:<code[^>]*>)?(.*?)(?:</code>)?</pre>#s', $text, $m) ? $m[1] : '';
}

it('keeps the text within the 4096 character limit', function () {
    $converter = new RichRecordConverter(config('telegram-logger'));

    $payload = $converter->convert(record(str_repeat('M', 9000), ['blob' => str_repeat('A', 20000)]));

    expect(mb_strlen($payload['text']))->toBeLessThanOrEqual(4096);
});

it('renders title, message, context and timestamp as HTML', function () {
    $converter = new RichRecordConverter(config('telegram-logger'));

    $text = $converter->convert(record('small', ['order' => 7]))['text'];

    expect($text)
        ->toStartWith('<b>🔥 ERROR · ')
        ->toContain("</b>\nsmall")
        ->toContain('<b>Context</b>')
        ->toContain('"order": 7')
        ->toMatch('#\n\n<i>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} .+</i>$#');
});

it('escapes HTML everywhere so Telegram never rejects the message', function () {
    $converter = new RichRecordConverter(config('telegram-logger'));

    $text = $converter->convert(record('a <b> & c', ['html' => '</pre><script>']))['text'];

    expect($text)
        ->toContain('a &lt;b&gt; &amp; c')
        ->toContain('&lt;/pre&gt;&lt;script&gt;')
        ->not->toContain('<script>');
});

it('truncates a large context section but keeps it', function () {
    $converter = new RichRecordConverter(config('telegram-logger'));

    $context = section($converter->convert(record('mid', ['blob' => str_repeat('A', 2000)]))['text'], 'Context');

    expect($context)->not->toBe('')
        ->and(mb_strlen(html_entity_decode($context)))->toBeLessThanOrEqual(1000);
});

it('keeps accented characters readable in the context section', function () {
    $converter = new RichRecordConverter(config('telegram-logger'));

    $context = section($converter->convert(record('pedido', ['user' => 'Não logado', 'bad' => "a\xB1b"]))['text'], 'Context');

    expect(html_entity_decode($context))
        ->toContain('Não logado')
        ->not->toContain('\\u00')
        ->toContain('"bad": "a�b"');
});

it('redacts secret value patterns in the message', function () {
    $config = config('telegram-logger');
    $config['redact_value_patterns'] = ['/Bearer\s+[A-Za-z0-9._-]+/i'];

    $text = (new RichRecordConverter($config))->convert(record('auth failed Bearer abc.def.ghi'))['text'];

    expect($text)->toContain('[REDACTED]')->not->toContain('abc.def.ghi');
});

it('includes a stacktrace when the mode is full', function () {
    $config = config('telegram-logger');
    $config['stacktrace'] = 'full';

    $text = (new RichRecordConverter($config))->convert(record('boom', ['exception' => new RuntimeException('x')]))['text'];

    expect($text)->toContain('<b>Stacktrace</b>');
});

it('omits the stacktrace when the mode is none', function () {
    $config = config('telegram-logger');
    $config['stacktrace'] = 'none';

    $text = (new RichRecordConverter($config))->convert(record('boom', ['exception' => new RuntimeException('x')]))['text'];

    expect($text)->not->toContain('<b>Stacktrace</b>')->toContain('<b>Exception</b>');
});

it('attaches the full redacted exception when it does not fit in the message', function () {
    $config = config('telegram-logger');
    $config['redact_value_patterns'] = ['/SHHSECRET/'];

    $converter = new RichRecordConverter($config);

    $throw = function (string $token) {
        throw new RuntimeException('boom', previous: new LogicException('root cause'));
    };

    try {
        $throw('SHHSECRET');
    } catch (RuntimeException $e) {
        $payload = $converter->convert(record('boom', ['exception' => $e]));
    }

    $file = $payload['files']['stacktrace.txt'] ?? '';

    expect(mb_strlen($file))->toBeGreaterThan(1000)
        ->and($file)
        ->toContain('RuntimeException: boom')
        ->toContain('LogicException: root cause')
        ->toContain('phpunit') // vendor frame: smart mode trims the message, never the file
        ->not->toContain('SHHSECRET');
});

it('redacts a colon-delimited secret in a chained exception before attaching it', function () {
    $converter = new RichRecordConverter(config('telegram-logger'));

    $e = new RuntimeException('boom', previous: new LogicException('connect failed, password: hunter2'));

    $file = $converter->convert(record('boom', ['exception' => $e]))['files']['stacktrace.txt'] ?? '';

    expect($file)->toContain('password: [REDACTED]')->not->toContain('hunter2');
});

it('keeps the queued job under the 256 KiB SQS limit whatever the exception text', function (string $message) {
    $converter = new RichRecordConverter(config('telegram-logger'));

    $payload = $converter->convert(record($message, ['exception' => new RuntimeException($message)]));

    // Same envelope Laravel builds: serialized job inside a JSON payload.
    $queued = json_encode(
        ['data' => ['command' => serialize(new SendTelegramMessage(DESTINATION, $payload))]],
        JSON_UNESCAPED_UNICODE,
    );

    expect($queued)->not->toBeFalse()
        ->and(strlen($queued))->toBeLessThan(256 * 1024)
        ->and(mb_check_encoding($payload['files']['stacktrace.txt'], 'UTF-8'))->toBeTrue();
})->with([
    '4-byte characters' => [str_repeat('😀', 80_000)],
    'escape-heavy (backslashes, newlines)' => [str_repeat("\\\n", 100_000)],
    'invalid UTF-8' => [str_repeat("\xB1", 100_000)],
]);

it('attaches nothing when disabled, in none mode or without an exception', function (array $overrides, array $context) {
    $converter = new RichRecordConverter(array_replace(config('telegram-logger'), $overrides));

    $payload = $converter->convert(record('boom', $context));

    expect($payload)->not->toHaveKey('files');
})->with([
    'attach disabled' => [['attach_stacktrace' => false], ['exception' => new RuntimeException('x')]],
    'stacktrace none' => [['stacktrace' => 'none'], ['exception' => new RuntimeException('x')]],
    'no exception' => [[], ['order' => 7]],
]);

it('drops vendor frames from the stacktrace in smart mode', function () {
    $config = config('telegram-logger');
    $config['stacktrace'] = 'smart';

    $text = (new RichRecordConverter($config))->convert(record('boom', ['exception' => new RuntimeException('x')]))['text'];

    // The real trace comes from Pest itself, so it's full of vendor frames in
    // whatever separator this OS uses — both must be filtered.
    expect(section($text, 'Stacktrace'))->not->toBe('')
        ->not->toContain('/vendor/')
        ->not->toContain('\\vendor\\');
});

it('recognises vendor frames on Linux and Windows paths', function (string $line, bool $vendor) {
    $isVendorFrame = new ReflectionMethod(RichRecordConverter::class, 'isVendorFrame');

    expect($isVendorFrame->invoke(null, $line))->toBe($vendor);
})->with([
    'linux vendor' => ['#3 /var/www/app/vendor/laravel/framework/src/Foo.php(12): bar()', true],
    'windows vendor' => ['#3 C:\\www\\app\\vendor\\laravel\\framework\\src\\Foo.php(12): bar()', true],
    'linux app' => ['#1 /var/www/app/app/Http/Controllers/OrderController.php(40): charge()', false],
    'windows app' => ['#1 C:\\www\\app\\app\\Http\\Controllers\\OrderController.php(40): charge()', false],
    'vendor as a name, not a directory' => ['#2 /var/www/app/app/Models/Vendor.php(9): save()', false],
]);

it('redacts secret value patterns in the stacktrace', function () {
    $config = config('telegram-logger');
    $config['stacktrace'] = 'full';
    // Short token: getTraceAsString() truncates string args to 15 chars.
    $config['redact_value_patterns'] = ['/SHHSECRET/'];

    $converter = new RichRecordConverter($config);

    // Force the secret to appear in the trace via the call args.
    $throw = function (string $token) {
        throw new RuntimeException('boom');
    };

    try {
        $throw('SHHSECRET');
    } catch (RuntimeException $e) {
        $payload = $converter->convert(record('boom', ['exception' => $e]));
    }

    expect(section($payload['text'], 'Stacktrace'))->not->toBe('')->not->toContain('SHHSECRET');
});
