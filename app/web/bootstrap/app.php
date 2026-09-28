<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([
        App\Console\Commands\PurgePendingCharacterizationDocumentsCommand::class,
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
        $middleware->trustHosts(
            at: static fn (): array => (bool) config('app.enforce_trusted_hosts', false)
                ? array_map(
                    static fn (string $host): string => '^'.preg_quote($host, '/').'$',
                    (array) config('app.trusted_hosts', []),
                )
                : [],
            subdomains: false,
        );

        $middleware->alias([
            'private-dev-user' => App\Http\Middleware\AuthenticatePrivateDevUser::class,
            'verified.required' => App\Http\Middleware\EnsureEmailVerifiedWhenRequired::class,
        ]);

        $middleware->web(prepend: [
            App\Http\Middleware\RemoveLegacyRememberCookie::class,
        ], append: [
            App\Http\Middleware\EnforceTrustedHost::class,
            App\Http\Middleware\EnsureCurrentAuthVersion::class,
            App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->prependToPriorityList(
            before: Illuminate\Cookie\Middleware\EncryptCookies::class,
            prepend: App\Http\Middleware\RemoveLegacyRememberCookie::class,
        );

        $middleware->prependToPriorityList(
            before: Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: App\Http\Middleware\AuthenticatePrivateDevUser::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'token',
            'cf-turnstile-response',
        ]);
    })
    ->withProviders([
        App\Providers\CharacterizationServiceProvider::class,
    ])
    ->create();
