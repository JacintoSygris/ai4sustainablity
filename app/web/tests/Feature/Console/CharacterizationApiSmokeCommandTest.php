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
        'services.characterization.api.model_profile' => 'new_format_732_v1_gpt41',
        'services.characterization.prediction_mapping_path' => base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json'),
    ]);

    $mapping = json_decode(
        file_get_contents(base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json')),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $expectedMappedKeyCount = collect($mapping['keys'])
        ->where('status', 'approved')
        ->filter(fn (array $row) => is_array($row['ar16_topic_ids'] ?? null) && $row['ar16_topic_ids'] !== [])
        ->count();

    Http::fake([
        'http://ai-service.test/predict' => Http::response([
            'esrs' => [
                'esrs_e1_climate_change_adaptation' => 1,
                'esrs_e3_other_issues_related_to_esrs_e3' => 1,
                'esrs_unmapped_public_smoke_key' => 1,
                'esrs_s1_employment' => 0,
            ],
            'model_profile' => 'new_format_732_v1_gpt41',
            'model_key_count' => $mapping['model_key_count'],
            'mapped_key_count' => $expectedMappedKeyCount,
        ]),
    ]);

    $this->artisan('characterization:smoke-api-gateway', [
        '--base-url' => 'http://ai-service.test',
    ])
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('raw_prediction_count=4')
        ->expectsOutputToContain('candidate_count=1')
        ->expectsOutputToContain('review_required_count=2')
        ->expectsOutputToContain('review_required_has_e3_other=false')
        ->expectsOutputToContain('model_profile=new_format_732_v1_gpt41')
        ->expectsOutputToContain('model_key_count=102')
        ->expectsOutputToContain('mapped_key_count='.$expectedMappedKeyCount)
        ->expectsOutputToContain('payload_employees_total=150')
        ->expectsOutputToContain('payload_turnover_million_euro=6')
        ->expectsOutputToContain('summary=AI proposed 1 candidate ESRS topic. 2 predicted ESRS keys need manual review.')
        ->assertExitCode(0);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/predict'
            && $request['company_name'] === 'I4S API Smoke'
            && $request['sector_list'] === ['Agriculture']
            && $request['employees_total'] === 150
            && $request['annual_turnover_million_euro'] === 6.0
            && $request['stock_listed'] === false
            && $request['reporting_currency'] === 'EUR'
            && $request['model_profile'] === 'new_format_732_v1_gpt41';
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
