<?php

use App\Models\Characterization;
use App\Services\ApiCharacterizationGateway;
use App\Support\CharacterizationOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
        'services.characterization.api.model_profile' => 'new_format_732_v1_gpt41',
        'services.characterization.prediction_mapping_path' => base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json'),
    ]);
    Http::preventStrayRequests();
    $this->predictionResponse = ['esrs' => (object) []];
    Http::fake(['http://ai-service.test/predict' => fn () => Http::response($this->predictionResponse)]);
});

function encodingCharacterization(string $nace, array $profile = [], array $operations = []): Characterization
{
    return new Characterization([
        'nace_code' => $nace,
        'form_data' => [
            'company_profile' => array_merge(['company_name' => 'Synthetic encoding test', 'headquarters_country' => 'Spain'], $profile),
            'operations' => $operations,
        ],
    ]);
}

it('preserves every CNAE 2025 section including unsupported model sections', function () {
    $catalogue = json_decode(file_get_contents(base_path('data/nace_codes.json')), true, 512, JSON_THROW_ON_ERROR);
    foreach ($catalogue as $entry) {
        $result = app(ApiCharacterizationGateway::class)->submit(encodingCharacterization($entry['code']));
        expect($result['request_payload']['sector_list'])->toBe([substr($entry['code'], 0, 1)]);
    }
});

it('maps bank and IT payloads into independent sector and product vocabularies', function (string $nace, string $type, string $sector, string $product) {
    app(ApiCharacterizationGateway::class)->submit(encodingCharacterization($nace, ['product_service_type' => $type]));
    Http::assertSent(fn ($request) => $request['sector_list'] === [$sector] && $request['products_services'] === [$product]);
})->with([
    'bank' => ['L64', 'finance_insurance', 'L', 'E'],
    'insurer' => ['L65', 'finance_insurance', 'L', 'E'],
    'IT' => ['K62', 'software_digital_services', 'K', 'F'],
    'publishing' => ['J58', 'software_digital_services', 'J', 'F'],
]);

it('covers every P5 product option and never falls back to the sector', function () {
    $mapping = json_decode(file_get_contents(base_path('data/p5-gpt41-feature-encoding-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($mapping['products_by_p5_type']))->toBe(array_keys(CharacterizationOptions::productServiceTypes()));
    foreach ($mapping['products_by_p5_type'] as $option => $codes) {
        $result = app(ApiCharacterizationGateway::class)->submit(encodingCharacterization('L64', ['product_service_type' => $option]));
        expect($result['request_payload']['products_services'] ?? [])->toBe($codes);
    }
});

it('covers all P5 region and employee ranges without inventing size or turnover precision', function () {
    foreach (CharacterizationOptions::employeeCountRangeEstimates() as $range => $employees) {
        $result = app(ApiCharacterizationGateway::class)->submit(encodingCharacterization('C10', [], [
            'employee_count_range' => $range,
            'revenue_range' => 'not_sure',
            'regions' => array_keys(CharacterizationOptions::regions()),
        ]));
        $payload = $result['request_payload'];
        expect($payload['employees_total'])->toBe($employees);
        expect($payload['annual_turnover_million_euro'])->toEqual(0);
        expect($payload['subsidiaries_regions'])->toBe(['EU', 'NA', 'LATAM', 'APAC', 'MENA', 'SSA']);
        expect($payload)->not->toHaveKey('company_size')->not->toHaveKey('juridic_form')->not->toHaveKey('products_services');
    }
});

it('preserves explicit degraded feature coverage from Python for the P6 warning', function () {
    $coverage = ['version' => 'p5-gpt41-encoding-v1', 'status' => 'degraded', 'unsupported_values' => ['sector_list' => ['Q']]];
    $this->predictionResponse = [
        'esrs' => (object) [],
        'feature_metadata' => ['derived_fields' => ['encoding_coverage' => $coverage], 'missing_required_fields' => ['sector_list']],
    ];
    $result = app(ApiCharacterizationGateway::class)->submit(encodingCharacterization('Q85'));
    expect($result['feature_metadata']['derived_fields']['encoding_coverage'])->toBe($coverage);
    expect($result['feature_metadata']['missing_required_fields'])->toBe(['sector_list']);
});
