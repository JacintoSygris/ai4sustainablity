<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Services\EsrsDatapointCorpusBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->e2Topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('requires authentication for report readiness', function () {
    $this->getJson('/api/report')
        ->assertUnauthorized();
});

it('returns null report readiness when the current user has no characterization', function () {
    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('requires authentication for report draft data', function () {
    $this->getJson('/api/report/draft')
        ->assertUnauthorized();
});

it('returns null report draft data when the current user has no characterization', function () {
    $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('returns report package readiness and download endpoints for the separate frontend', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);

    $response = $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.type', 'report_package_readiness')
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.status', 'incomplete')
        ->assertJsonPath('data.sections.characterization.status', 'ready')
        ->assertJsonPath('data.sections.materiality_proposal.status', 'ready')
        ->assertJsonPath('data.sections.double_materiality_guide.status', 'ready')
        ->assertJsonPath('data.sections.materiality_confirmation.status', 'ready')
        ->assertJsonPath('data.sections.materiality_confirmation.is_confirmed', true)
        ->assertJsonPath('data.sections.materiality_confirmation.confirmation_status', 'confirmed')
        ->assertJsonPath('data.sections.esrs_datapoints.status', 'ready')
        ->assertJsonPath('data.sections.datapoint_responses.status', 'in_progress')
        ->assertJsonPath('data.sections.final_report_generation.status', 'not_implemented')
        ->assertJsonPath('data.downloads.p8_decision_sheet.endpoint', '/api/materiality-confirmation/decision-sheet')
        ->assertJsonPath('data.downloads.p9_responses_csv.endpoint', '/api/esrs-datapoints/responses/export.csv')
        ->assertJsonPath('data.downloads.p9_datapoints_csv.endpoint', '/api/esrs-datapoints/export.csv')
        ->assertJsonPath('data.downloads.characterization_summary_pdf.endpoint', '/characterization/summary?format=pdf')
        ->assertJsonPath('data.limitations.0.key', 'final_report_generation_pending');

    expect($response->json('data.sections.esrs_datapoints.total_datapoint_count'))->toBeGreaterThan(0);
    expect($response->json('data.sections.datapoint_responses.response_count'))->toBe(2);
    expect($response->json('data.sections.datapoint_responses.completed_count'))->toBe(1);
    expect($response->json('data.next_actions'))->toContain('/api/esrs-datapoints/responses');
});

it('returns frontend-renderable report draft data from the current workflow state', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);

    $response = $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.type', 'report_draft')
        ->assertJsonPath('data.version', 'v0')
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.generation_status', 'frontend_rendered_draft')
        ->assertJsonPath('data.readiness_status', 'incomplete')
        ->assertJsonPath('data.company.name', 'Entidad Demo')
        ->assertJsonPath('data.company.nace_code', 'A')
        ->assertJsonPath('data.company.reporting_year', 2025)
        ->assertJsonPath('data.materiality.proposed_topic_count', 1)
        ->assertJsonPath('data.materiality.is_confirmed', true)
        ->assertJsonPath('data.materiality.confirmation_status', 'confirmed')
        ->assertJsonPath('data.materiality.confirmed_topic_count', 1)
        ->assertJsonPath('data.datapoints.response_status', 'in_progress')
        ->assertJsonPath('data.datapoints.response_count', 2)
        ->assertJsonPath('data.datapoints.completed_count', 1)
        ->assertJsonPath('data.exports.report_readiness.endpoint', '/api/report')
        ->assertJsonPath('data.limitations.0.key', 'final_report_generation_pending');

    expect($response->json('data.datapoints.total_datapoint_count'))->toBeGreaterThan(0);
    expect($response->json('data.datapoints.blocks'))->not->toBeEmpty();
});

it('does not treat null proposal topic ids as report-ready P6 materiality', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => null,
        'submitted_at' => now()->subDay(),
        'completed_at' => now(),
        'form_data' => reportBaselineFormData(),
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'incomplete')
        ->assertJsonPath('data.sections.materiality_proposal.status', 'missing')
        ->assertJsonPath('data.sections.materiality_proposal.topic_count', 0)
        ->assertJsonPath('data.next_actions.0', '/api/materiality-proposal');
});

it('ignores malformed stored datapoint response rows in report readiness and draft', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);
    $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
    $datapointIds = reportDatapointIds($corpus);

    $formData = $characterization->form_data;
    $formData['esrs_datapoint_responses'] = [
        'schema_version' => 'v0',
        'updated_at' => now()->toJSON(),
        'responses' => [
            $datapointIds[0] => 'malformed row',
            $datapointIds[1] => null,
            $datapointIds[2] => [
                'datapoint_id' => $datapointIds[2],
                'status' => 'completed',
                'updated_at' => now()->toJSON(),
            ],
        ],
    ];

    $characterization->forceFill(['form_data' => $formData])->save();

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.datapoint_responses.response_count', 1)
        ->assertJsonPath('data.sections.datapoint_responses.completed_count', 1);

    $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.datapoints.response_count', 1)
        ->assertJsonPath('data.datapoints.completed_count', 1);
});

it('returns next actions for the actual incomplete report blocker', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_DRAFT,
        'nace_code' => null,
        'esrs_topic_ids' => [],
        'form_data' => [],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.next_actions.0', '/api/characterization');

    $p6User = User::factory()->create();
    Characterization::factory()->create([
        'user_id' => $p6User->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [],
        'form_data' => reportBaselineFormData(),
    ]);

    $this->actingAs($p6User)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.next_actions.0', '/api/materiality-proposal');

    $p8User = User::factory()->create();
    Characterization::factory()->create([
        'user_id' => $p8User->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => reportBaselineFormData(),
    ]);

    $this->actingAs($p8User)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.next_actions.0', '/api/materiality-confirmation');

    $characterization = reportReadyCharacterization(User::factory()->create(), $this->e2Topic);

    $this->actingAs($characterization->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.next_actions.0', '/api/esrs-datapoints/responses');
});

it('treats not applicable datapoint responses as report-ready decisions', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);
    $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
    $datapointIds = reportDatapointIds($corpus);
    $responses = collect($datapointIds)
        ->mapWithKeys(fn (string $id): array => [
            $id => [
                'datapoint_id' => $id,
                'status' => 'not_applicable',
                'updated_at' => now()->toJSON(),
            ],
        ])
        ->all();

    $formData = $characterization->form_data;
    $formData['esrs_datapoint_responses'] = [
        'schema_version' => 'v0',
        'updated_at' => now()->toJSON(),
        'responses' => $responses,
    ];

    $characterization->forceFill(['form_data' => $formData])->save();

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'generation_pending')
        ->assertJsonPath('data.sections.datapoint_responses.status', 'complete')
        ->assertJsonPath('data.sections.datapoint_responses.response_count', count($datapointIds))
        ->assertJsonPath('data.sections.datapoint_responses.completed_count', 0)
        ->assertJsonPath('data.sections.datapoint_responses.not_applicable_count', count($datapointIds))
        ->assertJsonPath('data.sections.datapoint_responses.completion_ratio', 1);

    $report = $this->actingAs($this->user)
        ->getJson('/api/report')
        ->json('data');

    expect($report['next_actions'])->toBe(['/api/report/draft']);

    $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.readiness_status', 'generation_pending')
        ->assertJsonPath('data.datapoints.response_status', 'complete')
        ->assertJsonPath('data.datapoints.response_count', count($datapointIds))
        ->assertJsonPath('data.datapoints.completed_count', 0)
        ->assertJsonPath('data.datapoints.not_applicable_count', count($datapointIds))
        ->assertJsonPath('data.datapoints.completion_ratio', 1);
});

it('exposes report download readiness metadata', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => reportBaselineFormData(),
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.downloads.p8_decision_sheet.status', 'blocked')
        ->assertJsonPath('data.downloads.p8_decision_sheet.depends_on.0', 'materiality_confirmation')
        ->assertJsonPath('data.downloads.p9_responses_csv.status', 'incomplete')
        ->assertJsonPath('data.downloads.p9_datapoints_csv.status', 'ready')
        ->assertJsonPath('data.downloads.characterization_summary_pdf.status', 'ready');

    $readyUser = User::factory()->create();
    $readyCharacterization = reportReadyCharacterization($readyUser, $this->e2Topic);
    $corpus = app(EsrsDatapointCorpusBuilder::class)->build($readyCharacterization);
    $datapointIds = reportDatapointIds($corpus);
    $responses = collect($datapointIds)
        ->mapWithKeys(fn (string $id): array => [
            $id => [
                'datapoint_id' => $id,
                'status' => 'completed',
                'updated_at' => now()->toJSON(),
            ],
        ])
        ->all();

    $formData = $readyCharacterization->form_data;
    $formData['esrs_datapoint_responses'] = [
        'schema_version' => 'v0',
        'updated_at' => now()->toJSON(),
        'responses' => $responses,
    ];
    $readyCharacterization->forceFill(['form_data' => $formData])->save();

    $this->actingAs($readyUser)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'generation_pending')
        ->assertJsonPath('data.downloads.p8_decision_sheet.status', 'ready')
        ->assertJsonPath('data.downloads.p9_responses_csv.status', 'ready')
        ->assertJsonPath('data.downloads.p9_datapoints_csv.status', 'ready')
        ->assertJsonPath('data.downloads.characterization_summary_pdf.status', 'ready');
});

function reportBaselineFormData(): array
{
    return [
        'company_profile' => [
            'company_name' => 'Entidad Demo',
            'reporting_year' => 2025,
            'product_service_type' => 'software_digital_services',
        ],
        'operations' => [
            'employee_count_range' => '50_249',
            'revenue_range' => '2m_to_10m',
            'regions' => ['eu'],
        ],
    ];
}

function reportReadyCharacterization(User $user, EsrsTopic $topic): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$topic->id],
        'submitted_at' => now()->subDay(),
        'completed_at' => now(),
        'form_data' => [
            ...reportBaselineFormData(),
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$topic->id],
                'confirmed_at' => now()->toJSON(),
            ],
            'esrs_datapoint_responses' => [
                'responses' => [
                    'BP-1_01' => [
                        'status' => 'completed',
                        'value' => 'Prepared on a consolidated basis.',
                        'updated_at' => now()->toJSON(),
                    ],
                    'BP-1_02' => [
                        'status' => 'draft',
                        'value' => 'Disclosure boundary review started.',
                        'updated_at' => now()->toJSON(),
                    ],
                ],
            ],
        ],
    ]);
}

function reportDatapointIds(array $corpus): array
{
    return collect($corpus['blocks'] ?? [])
        ->flatMap(fn (array $block): array => $block['datapoints'] ?? [])
        ->pluck('id')
        ->filter()
        ->values()
        ->all();
}
