<?php

use App\Services\Contracts\CharacterizationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fails fast for unknown characterization gateway drivers', function () {
    config(['services.characterization.driver' => 'typo']);
    app()->forgetInstance(CharacterizationGateway::class);

    expect(fn () => app(CharacterizationGateway::class))
        ->toThrow(InvalidArgumentException::class, 'Unsupported characterization gateway driver [typo].');
});
