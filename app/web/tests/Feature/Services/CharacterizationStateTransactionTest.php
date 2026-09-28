<?php

use App\Models\Characterization;
use App\Models\User;
use App\Services\CharacterizationStateTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

uses(RefreshDatabase::class);

it('mutates the latest locked form data without erasing sibling workflow state', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create([
        'user_id' => $user->id,
        'form_data' => [
            'materiality_confirmation' => ['revision' => 4, 'confirmed_topic_ids' => [1]],
            'esrs_datapoint_responses' => ['revision' => 7, 'responses' => []],
        ],
    ]);

    app(CharacterizationStateTransaction::class)->run(
        $characterization->id,
        function (Characterization $locked): void {
            $formData = $locked->form_data ?? [];
            Arr::set($formData, 'double_materiality_process.updated_at', '2026-09-23T20:00:00Z');
            $locked->forceFill(['form_data' => $formData])->save();
        },
    );

    $characterization->refresh();

    expect(data_get($characterization->form_data, 'materiality_confirmation.revision'))->toBe(4)
        ->and(data_get($characterization->form_data, 'esrs_datapoint_responses.revision'))->toBe(7)
        ->and(data_get($characterization->form_data, 'double_materiality_process.updated_at'))
        ->toBe('2026-09-23T20:00:00Z');
});

it('routes every persistent form data writer through the shared state transaction', function () {
    $writers = [
        app_path('Services/CharacterizationRecorder.php'),
        app_path('Jobs/SubmitCharacterizationJob.php'),
        app_path('Http/Controllers/Api/DoubleMaterialityGuideController.php'),
        app_path('Http/Controllers/Api/CharacterizationDocumentController.php'),
        app_path('Http/Controllers/Api/MaterialityProposalController.php'),
        app_path('Http/Controllers/Api/MaterialityConfirmationController.php'),
        app_path('Http/Controllers/Api/EsrsDatapointController.php'),
    ];

    foreach ($writers as $writer) {
        expect(file_get_contents($writer))->toContain('CharacterizationStateTransaction');
    }
});
