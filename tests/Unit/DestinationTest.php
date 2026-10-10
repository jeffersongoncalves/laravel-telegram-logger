<?php

use JeffersonGoncalves\TelegramLogger\Support\Destination;

it('resolves the channel chat and topic', function () {
    expect(Destination::resolve(['token' => ' '.BOT_TOKEN.' ', 'chat_id' => -1001, 'thread_id' => '12']))
        ->toBe(['token' => BOT_TOKEN, 'chat_id' => '-1001', 'thread_id' => 12]);
});

it('lets a per-level chat id win without inheriting the channel topic', function () {
    $config = ['token' => BOT_TOKEN, 'chat_id' => '-1001', 'thread_id' => 12, 'chats' => ['CRITICAL' => '-2002']];

    expect(Destination::resolve($config, 'CRITICAL'))->toBe(['token' => BOT_TOKEN, 'chat_id' => '-2002'])
        ->and(Destination::resolve($config, 'ERROR'))->toBe(['token' => BOT_TOKEN, 'chat_id' => '-1001', 'thread_id' => 12]);
});

it('lets a per-level chat carry its own topic', function () {
    $config = ['token' => BOT_TOKEN, 'chat_id' => '-1001', 'chats' => ['ERROR' => ['chat_id' => '-2002', 'thread_id' => 7]]];

    expect(Destination::resolve($config, 'ERROR'))->toBe(['token' => BOT_TOKEN, 'chat_id' => '-2002', 'thread_id' => 7]);
});

it('returns null when disabled or missing the token or chat id', function (array $config) {
    expect(Destination::resolve($config))->toBeNull();
})->with([
    'disabled' => [['enabled' => false, 'token' => BOT_TOKEN, 'chat_id' => '1']],
    'no token' => [['token' => '', 'chat_id' => '1']],
    'no chat' => [['token' => BOT_TOKEN, 'chat_id' => ' ']],
    'nothing' => [[]],
]);

it('ignores a thread id that is not numeric', function () {
    expect(Destination::resolve(['token' => BOT_TOKEN, 'chat_id' => '1', 'thread_id' => 'general']))
        ->toBe(['token' => BOT_TOKEN, 'chat_id' => '1']);
});
