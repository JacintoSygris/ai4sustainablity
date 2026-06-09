<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        App\Console\Commands\SmokeCharacterizationApiGatewayCommand::class,
    ])
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'private-dev-user' => App\Http\Middleware\AuthenticatePrivateDevUser::class,
        ]);

        $middleware->prependToPriorityList(
            before: Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: App\Http\Middleware\AuthenticatePrivateDevUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withProviders([
        App\Providers\CharacterizationServiceProvider::class,
    ])
    ->create();
