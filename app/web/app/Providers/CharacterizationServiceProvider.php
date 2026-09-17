<?php

namespace App\Providers;

use App\Services\ApiCharacterizationGateway;
use App\Services\Contracts\CharacterizationGateway;
use App\Services\MockCharacterizationGateway;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class CharacterizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CharacterizationGateway::class, function ($app) {
            $driver = config('services.characterization.driver', 'mock');

            return match ($driver) {
                'mock' => $app->make(MockCharacterizationGateway::class),
                'api' => $app->make(ApiCharacterizationGateway::class),
                default => throw new InvalidArgumentException("Unsupported characterization gateway driver [{$driver}]."),
            };
        });
    }
}
