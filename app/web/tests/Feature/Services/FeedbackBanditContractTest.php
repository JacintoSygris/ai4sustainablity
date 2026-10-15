<?php

use App\Models\Characterization;
use App\Models\User;
use App\Services\ApiCharacterizationGateway;
use App\Services\LearningP6Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('preserves the private feedback basis without treating shadow actions as proposed topics', function () {
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    config([
        'services.characterization.api.base_url' => 'http://ai-service.test',
        'services.characterization.api.token' => null,
        'services.characterization.api.model_profile' => null,
        'services.characterization.prediction_mapping_path' => base_path('data/ar16_to_python_esrs_mapping.json'),
    ]);

    // Produced by the Python prediction serializer with synthetic model inputs.
    $response = json_decode(file_get_contents(base_path('tests/Fixtures/feedback-bandit-python-response.json')), true, 512, JSON_THROW_ON_ERROR)['shadow'];
    $key = 'esrs_e1_energy';
    $feedback = $response['mapping_metadata']['feedback_bandit'];
    // Match the Python wire response: Http::response(array) otherwise rewrites
    // integral floats (0.0) as integers before the gateway can read them.
    $wire = json_encode($response, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    Http::fake(['http://ai-service.test/predict' => Http::response($wire, 200, ['Content-Type' => 'application/json'])]);

    $characterization = Characterization::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => Characterization::STATUS_SUBMITTED,
        'nace_code' => 'A',
        'form_data' => [
            'company_profile' => ['company_name' => 'Synthetic contract fixture'],
            'operations' => [],
        ],
        'submitted_at' => now(),
    ]);

    $result = app(ApiCharacterizationGateway::class)->submit($characterization);

    expect($result['mapping_metadata']['python']['feedback_bandit'])->toBe($feedback);
    expect($result['candidate_topics'])->toBe([]);
    expect($result['raw_prediction'][$key])->toBe(0);
    $characterization->status = Characterization::STATUS_COMPLETED;
    $characterization->result_data = $result;
    $projection = (new LearningP6Snapshot)->project($characterization);
    expect($projection['serving_identity'])->toHaveKey('policy_sha256.feedback_bandit');
    expect($projection['serving_identity']['runtime_config']['policy_active']['feedback_bandit'])->toBeTrue();
    expect($projection['p6_snapshot']['model_profile'])->toBe($response['model_profile']);
});
