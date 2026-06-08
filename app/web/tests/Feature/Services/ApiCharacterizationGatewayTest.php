<?php

use App\Models\Characterization;
use App\Models\User;
use App\Services\ApiCharacterizationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
});

it('submits normalized company data to the Python predict endpoint', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => 'test-token',
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response([
            'esrs' => [
                'esrs_e1_adaptation_to_climate_change' => 1,
                'esrs_e2_pollution' => 1,
                'esrs_unknown_key' => 1,
            ],
        ]),
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => User::factory()->create(['name' => 'Fallback User'])->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Example Company',
                'headquarters_country' => 'Spain',
                'num_subsidiaries_countries' => 3,
                'stock_listed' => true,
                'reporting_currency' => 'EUR',
            ],
            'operations' => [
                'regions' => ['eu', 'latin_america'],
                'employee_count' => 150,
                'revenue' => 2500000,
            ],
        ],
        'submitted_at' => now(),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit($characterization);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/predict'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['company_name'] === 'Example Company'
            && $request['sector_list'] === ['Agriculture']
            && $request['headquarters_country'] === 'Spain'
            && $request['num_subsidiaries_countries'] === 3
            && $request['employees_total'] === 150
            && $request['annual_turnover_million_euro'] === 2.5
            && $request['stock_listed'] === true
            && $request['reporting_currency'] === 'EUR';
    });

    expect($result['status'])->toBe('completed');
    expect($result['raw_prediction'])->toHaveKey('esrs_e1_adaptation_to_climate_change', 1);
    expect($result['candidate_topics'])->toHaveCount(1);
    expect($result['candidate_topics'][0])->toMatchArray([
        'ar16_topic_id' => 1,
        'python_esrs_keys' => ['esrs_e1_adaptation_to_climate_change'],
        'score_source' => 'python_predict',
        'suggested' => true,
    ]);
    expect($result['summary'])->toBe('AI proposed 1 candidate ESRS topic. 1 predicted ESRS key needs manual review.');
});

it('derives numeric prediction payload values from SME size ranges', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response(['esrs' => []]),
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Range Company',
                'headquarters_country' => 'Spain',
                'num_subsidiaries_countries' => 0,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
            ],
            'operations' => [
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
        ],
        'submitted_at' => now(),
    ]);

    app(ApiCharacterizationGateway::class)->submit($characterization);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/predict'
            && $request['company_name'] === 'Range Company'
            && $request['employees_total'] === 150
            && $request['annual_turnover_million_euro'] === 6.0;
    });
});

it('maps current CNAE 2025 section-prefixed codes to local Python sector labels', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response(['esrs' => []]),
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'K62',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Software Company',
            ],
        ],
        'submitted_at' => now(),
    ]);

    app(ApiCharacterizationGateway::class)->submit($characterization);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/predict'
            && $request['company_name'] === 'Software Company'
            && $request['sector_list'] === ['Information technology'];
    });
});

it('surfaces positive prediction keys that need manual review', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response([
            'esrs' => [
                'esrs_e2_pollution' => 1,
                'esrs_e3_other' => 1,
                'esrs_unknown_key' => 1,
            ],
        ]),
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Review Company',
                'headquarters_country' => 'Spain',
                'num_subsidiaries_countries' => 0,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
            ],
        ],
        'submitted_at' => now(),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit($characterization);

    expect($result['candidate_topics'])->toHaveCount(0);
    expect($result['review_required_prediction_keys'])->toBe([
        'esrs_e3_other',
        'esrs_unknown_key',
    ]);
    expect($result['summary'])->toContain('2 predicted ESRS keys need manual review.');

    $mapping = json_decode(file_get_contents(base_path('data/ar16_to_python_esrs_mapping.json')), true);

    expect(data_get($mapping, 'python_key_statuses.esrs_e3_other'))->toBe('review_only');
});

it('keeps positive keys from non-approved mapping rows in manual review', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-python-map-');

    file_put_contents($mappingPath, json_encode([
        'candidate_topics' => [
            [
                'ar16_topic_id' => 2,
                'web_esrs' => 'E2',
                'web_label_en' => 'Pollution',
                'mapping_status' => 'needs_review',
                'python_esrs_keys' => ['esrs_e2_pollution'],
            ],
        ],
        'python_key_statuses' => [
            'esrs_e2_pollution' => 'needs_review',
        ],
    ], JSON_THROW_ON_ERROR));

    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
        'services.characterization.prediction_mapping_path' => $mappingPath,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response([
            'esrs' => [
                'esrs_e2_pollution' => 1,
            ],
        ]),
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Review Mapping Company',
            ],
        ],
        'submitted_at' => now(),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit($characterization);

    expect($result['candidate_topics'])->toBe([]);
    expect($result['review_required_prediction_keys'])->toBe(['esrs_e2_pollution']);

    @unlink($mappingPath);
});

it('uses safe defaults when older characterizations are missing profile fields', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response(['esrs' => []]),
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => User::factory()->create(['name' => 'User Company'])->id,
        'nace_code' => null,
        'form_data' => [
            'operations' => [
                'employee_count' => null,
                'revenue' => null,
            ],
        ],
    ]);

    app(ApiCharacterizationGateway::class)->submit($characterization);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/predict'
            && ! $request->hasHeader('Authorization')
            && $request['company_name'] === 'User Company'
            && $request['sector_list'] === []
            && $request['headquarters_country'] === 'Spain'
            && $request['num_subsidiaries_countries'] === 0
            && $request['employees_total'] === 0
            && $request['annual_turnover_million_euro'] === 0.0
            && $request['stock_listed'] === false
            && $request['reporting_currency'] === 'EUR';
    });
});
