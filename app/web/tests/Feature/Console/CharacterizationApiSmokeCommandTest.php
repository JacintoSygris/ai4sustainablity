<?php

use App\Models\Characterization;
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
