<?php

namespace JeffersonGoncalves\TelegramLogger;

use Illuminate\Contracts\Events\Dispatcher;
use JeffersonGoncalves\TelegramLogger\Commands\TestCommand;
use JeffersonGoncalves\TelegramLogger\Support\RuntimeContext;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TelegramLoggerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-telegram-logger')
            ->hasConfigFile()
            ->hasCommand(TestCommand::class)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('jeffersongoncalves/laravel-telegram-logger');
            });
    }

    public function packageBooted(): void
    {
        RuntimeContext::listen($this->app->make(Dispatcher::class));
    }
}
