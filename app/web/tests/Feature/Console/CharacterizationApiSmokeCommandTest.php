<?php

use App\Models\Characterization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
});

it('smokes the python characterization api without changing persisted gateway config', function () {
    config([
        'services.characterization.driver' => 'mock',
        'services.characterization.api.base_url' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response([
            'esrs' => [
                'esrs_e1_adaptation_to_climate_change' => 1,
                'esrs_e3_other' => 1,
                'esrs_s1_employment' => 0,
            ],
            'model_profile' => 'legacy_v0',
            'model_key_count' => 96,
            'mapped_key_count' => 92,
        ]),
    ]);

    $this->artisan('characterization:smoke-api-gateway', [
        '--base-url' => 'http://ai-service.test',
    ])
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('raw_prediction_count=3')
        ->expectsOutputToContain('candidate_count=1')
        ->expectsOutputToContain('review_required_count=1')
        ->expectsOutputToContain('review_required_has_e3_other=true')
        ->expectsOutputToContain('model_profile=legacy_v0')
        ->expectsOutputToContain('model_key_count=96')
        ->expectsOutputToContain('mapped_key_count=92')
        ->expectsOutputToContain('payload_employees_total=150')
        ->expectsOutputToContain('payload_turnover_million_euro=6')
        ->assertExitCode(0);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/predict'
            && $request['company_name'] === 'I4S API Smoke'
            && $request['sector_list'] === ['Agriculture']
            && $request['employees_total'] === 150
            && $request['annual_turnover_million_euro'] === 6.0
            && $request['stock_listed'] === false
            && $request['reporting_currency'] === 'EUR';
    });

    expect(config('services.characterization.driver'))->toBe('mock');
    expect(Characterization::count())->toBe(0);
});

it('explains how to set the python api base url when smoke configuration is missing', function () {
    config([
        'services.characterization.api.base_url' => null,
    ]);

    Http::fake();

    $status = Artisan::call('characterization:smoke-api-gateway');
    $output = Artisan::output();

    expect($status)->toBe(1);
    expect($output)->toContain('missing_base_url=true');
    expect($output)->toContain('--base-url=http://127.0.0.1:8001');

    Http::assertNothingSent();
});

it('prints an actionable failure marker when the python gateway smoke fails', function () {
    config([
        'services.characterization.api.base_url' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response(['detail' => 'upstream failure'], 500),
    ]);

    $status = Artisan::call('characterization:smoke-api-gateway', [
        '--base-url' => 'http://ai-service.test',
    ]);
    $output = Artisan::output();

    expect($status)->toBe(1);
    expect($output)->toContain('smoke_failed=true');
    expect($output)->toContain('upstream failure');
});
