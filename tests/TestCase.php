<?php

namespace JeffersonGoncalves\TelegramLogger\Tests;

use JeffersonGoncalves\TelegramLogger\Logger;
use JeffersonGoncalves\TelegramLogger\TelegramLoggerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TelegramLoggerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');

        $app['config']->set('logging.channels.telegram', [
            'driver' => 'custom',
            'via' => Logger::class,
            'level' => 'debug',
            'token' => '123456789:AAsEcReT-ToKeN_abcdefghijklmnopqrstuv',
            'chat_id' => '-1001234567890',
        ]);
    }
}
