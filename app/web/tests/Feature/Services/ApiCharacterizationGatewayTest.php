<?php

use App\Models\Characterization;
use App\Models\User;
use App\Services\ApiCharacterizationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
        'http://ai-service.test/predict' => Http::response('{"esrs":{}}', 200, [
            'Content-Type' => 'application/json',
        ]),
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

it('adds new-format crosswalk fields from P5 characterization data', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response('{"esrs":{}}', 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Agri SME',
                'headquarters_country' => 'Spain',
                'product_service_type' => 'agrifood',
            ],
            'operations' => [
                'regions' => ['eu', 'latin_america'],
                'employee_count_range' => '10_49',
                'revenue_range' => 'lte_2m',
            ],
        ],
        'submitted_at' => now(),
    ]);

    app(ApiCharacterizationGateway::class)->submit($characterization);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/predict'
            && $request['products_services'] === ['A', 'C']
            && $request['subsidiaries_regions'] === ['EU', 'LATAM'];
    });
});

it('can request a configured AI model profile and stores response metadata', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
        'services.characterization.api.model_profile' => 'legacy_v0',
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response([
            'esrs' => [
                'esrs_e1_adaptation_to_climate_change' => 1,
            ],
            'model_profile' => 'legacy_v0',
            'model_key_count' => 96,
            'mapped_key_count' => 92,
            'feature_metadata' => [
                'derived_fields' => [],
                'defaulted_fields' => [],
                'missing_required_fields' => [],
            ],
            'mapping_metadata' => [
                'mapping_status' => 'external_laravel_mapping',
            ],
            'evidence_refs' => [],
        ]),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization());

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ai-service.test/predict'
            && $request['model_profile'] === 'legacy_v0';
    });

    expect($result['model_profile'])->toBe('legacy_v0');
    expect($result['model_key_count'])->toBe(96);
    expect($result['mapped_key_count'])->toBe(92);
    expect($result['feature_metadata'])->toBe([
        'derived_fields' => [],
        'defaulted_fields' => [],
        'missing_required_fields' => [],
    ]);
    expect($result['mapping_metadata']['python']['mapping_status'])->toBe('external_laravel_mapping');
    expect($result['mapping_metadata']['laravel']['mapping_version'])->toBe('v0');
    expect($result['mapping_metadata']['laravel']['mapping_status'])->toBe('runtime-approved-for-candidate-suggestions');
    expect($result['mapping_metadata']['laravel']['mapping_key_count'])->toBeGreaterThan(90);
    expect($result['evidence_refs'])->toBe([]);
});

it('keeps new-format draft mapping inventory positives in manual review', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-new-format-map-');

    file_put_contents($mappingPath, json_encode([
        'schema_version' => '1.0',
        'mapping_version' => 'new_format_732_v1',
        'status' => 'draft-review-required',
        'model_key_count' => 102,
        'keys' => [
            [
                'python_esrs_key' => 'esrs_e1_summary',
                'status' => 'review_only',
                'ar16_topic_ids' => [],
            ],
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
                'esrs_e1_summary' => 1,
            ],
            'model_profile' => 'new_format_732_v1_gpt41',
            'model_key_count' => 102,
            'mapped_key_count' => 0,
        ]),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization());

    expect($result['candidate_topics'])->toBe([]);
    expect($result['review_required_prediction_keys'])->toBe(['esrs_e1_summary']);
    expect($result['mapping_metadata']['laravel']['mapping_version'])->toBe('new_format_732_v1');
    expect($result['mapping_metadata']['laravel']['mapping_status'])->toBe('draft-review-required');
    expect($result['mapping_metadata']['laravel']['mapping_key_count'])->toBe(0);
    expect($result['mapping_metadata']['laravel']['mapping_model_key_count'])->toBe(102);

    @unlink($mappingPath);
});

it('keeps approved new-format mapping rows without AR16 topics in manual review', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-new-format-map-');

    file_put_contents($mappingPath, json_encode([
        'schema_version' => '1.0',
        'mapping_version' => 'new_format_732_v1',
        'status' => 'draft-review-required',
        'model_key_count' => 102,
        'keys' => [
            [
                'python_esrs_key' => 'esrs_e1_summary',
                'status' => 'approved',
                'ar16_topic_ids' => [],
            ],
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
                'esrs_e1_summary' => 1,
            ],
            'model_profile' => 'new_format_732_v1_gpt41',
            'model_key_count' => 102,
            'mapped_key_count' => 0,
        ]),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization());

    expect($result['candidate_topics'])->toBe([]);
    expect($result['review_required_prediction_keys'])->toBe(['esrs_e1_summary']);
    expect($result['mapping_metadata']['laravel']['mapping_key_count'])->toBe(0);

    @unlink($mappingPath);
});

it('creates candidate topics from approved new-format mapping rows', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-new-format-map-');

    file_put_contents($mappingPath, json_encode([
        'schema_version' => '1.0',
        'mapping_version' => 'new_format_732_v1',
        'status' => 'runtime-approved-for-candidate-suggestions',
        'model_key_count' => 102,
        'keys' => [
            [
                'python_esrs_key' => 'esrs_e1_climate_change_adaptation',
                'status' => 'approved',
                'ar16_topic_ids' => [1],
                'web_esrs' => 'E1',
                'web_label_en' => 'Adaptation to climate change',
            ],
            [
                'python_esrs_key' => 'esrs_e1_summary',
                'status' => 'aggregate_only',
                'ar16_topic_ids' => [],
            ],
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
                'esrs_e1_climate_change_adaptation' => 1,
                'esrs_e1_summary' => 1,
            ],
            'model_profile' => 'new_format_732_v1_gpt41',
            'model_key_count' => 102,
            'mapped_key_count' => 0,
        ]),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization());

    expect($result['candidate_topics'])->toHaveCount(1);
    expect($result['candidate_topics'][0])->toMatchArray([
        'ar16_topic_id' => 1,
        'web_esrs' => 'E1',
        'web_label_en' => 'Adaptation to climate change',
        'python_esrs_keys' => ['esrs_e1_climate_change_adaptation'],
        'score_source' => 'python_predict',
        'suggested' => true,
    ]);
    expect($result['review_required_prediction_keys'])->toBe(['esrs_e1_summary']);
    expect($result['mapped_key_count'])->toBe(1);
    expect($result['mapping_metadata']['laravel']['mapping_status'])->toBe('runtime-approved-for-candidate-suggestions');
    expect($result['mapping_metadata']['laravel']['mapping_key_count'])->toBe(1);

    @unlink($mappingPath);
});

it('maps current CNAE 2025 section-prefixed codes to local Python sector labels', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response('{"esrs":{}}', 200, [
            'Content-Type' => 'application/json',
        ]),
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
        'http://ai-service.test/predict' => Http::response('{"esrs":{}}', 200, [
            'Content-Type' => 'application/json',
        ]),
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

it('keeps an empty esrs object as a valid zero candidate prediction', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response('{"esrs":{}}', 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization());

    expect($result['status'])->toBe('completed');
    expect($result['candidate_topics'])->toBe([]);
    expect($result['review_required_prediction_keys'])->toBe([]);
    expect($result['summary'])->toBe('AI proposed 0 candidate ESRS topics.');
});

it('rejects predict responses without an esrs object', function (array|string $body) {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response($body),
    ]);

    expect(fn () => app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization()))
        ->toThrow(RuntimeException::class, 'expected JSON object with an esrs object');
})->with([
    'missing esrs' => [['status' => 'ok']],
    'scalar esrs' => [['esrs' => 'not-an-object']],
    'array esrs' => ['{"esrs":[]}'],
    'non json body' => ['<html>wrong service</html>'],
]);

it('rejects non binary esrs prediction values', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response([
            'esrs' => [
                'esrs_e1_adaptation_to_climate_change' => 2,
            ],
        ]),
    ]);

    expect(fn () => app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization()))
        ->toThrow(RuntimeException::class, 'expected every esrs value to be 0 or 1');
});

it('uses json detail when reporting predict http failures', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => Http::response(['detail' => 'model unavailable'], 503),
    ]);

    expect(fn () => app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization()))
        ->toThrow(RuntimeException::class, 'External characterization API responded with an error (HTTP 503): model unavailable');
});

it('reports connection failures with the configured ai service url', function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
    ]);

    Http::fake([
        'http://ai-service.test/predict' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    expect(fn () => app(ApiCharacterizationGateway::class)->submit(apiGatewayCharacterization()))
        ->toThrow(RuntimeException::class, 'AI prediction service is unreachable or timed out at http://ai-service.test.');
});

function apiGatewayCharacterization(array $overrides = []): Characterization
{
    return Characterization::factory()->create(array_replace_recursive([
        'user_id' => User::factory()->create(['name' => 'Gateway Test Company'])->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Gateway Test Company',
                'headquarters_country' => 'Spain',
                'num_subsidiaries_countries' => 0,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
            ],
            'operations' => [
                'employee_count' => 10,
                'revenue' => 1000000,
            ],
        ],
        'submitted_at' => now(),
    ], $overrides));
}
