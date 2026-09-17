<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $publicComposeFrontendUrl = env('PUBLIC_COMPOSE_FRONTEND_URL');

        if (is_string($publicComposeFrontendUrl) && filter_var($publicComposeFrontendUrl, FILTER_VALIDATE_URL) !== false) {
            $scheme = parse_url($publicComposeFrontendUrl, PHP_URL_SCHEME);

            if (in_array($scheme, ['http', 'https'], true)) {
                URL::forceRootUrl(rtrim($publicComposeFrontendUrl, '/'));
            }
        }

        Event::listen(function (SocialiteWasCalled $event): void {
            $event->extendSocialite('microsoft', MicrosoftProvider::class);
        });
    }
}
