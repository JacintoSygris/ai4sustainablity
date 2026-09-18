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
        ->assertJsonPath('data.sections.double_materiality_guide.status', 'missing')
        ->assertJsonPath('data.sections.materiality_confirmation.status', 'ready')
        ->assertJsonPath('data.sections.materiality_confirmation.is_confirmed', true)
        ->assertJsonPath('data.sections.materiality_confirmation.confirmation_status', 'confirmed')
        ->assertJsonPath('data.sections.esrs_datapoints.status', 'ready')
        ->assertJsonPath('data.sections.datapoint_responses.status', 'in_progress')
        ->assertJsonPath('data.sections.final_report_generation.status', 'blocked')
        ->assertJsonPath('data.sections.final_report_generation.reason_code', 'report_package_prerequisites_incomplete')
        ->assertJsonPath('data.downloads.report_package_html.endpoint', '/api/report/package')
        ->assertJsonPath('data.downloads.report_package_html.status', 'incomplete')
        ->assertJsonPath('data.downloads.evidence_bundle_json.endpoint', '/api/report/evidence-bundle')
        ->assertJsonPath('data.downloads.evidence_bundle_json.status', 'incomplete')
        ->assertJsonPath('data.downloads.p8_decision_sheet.endpoint', '/api/materiality-confirmation/decision-sheet')
        ->assertJsonPath('data.downloads.p9_responses_csv.endpoint', '/api/esrs-datapoints/responses/export.csv')
        ->assertJsonPath('data.downloads.p9_datapoints_csv.endpoint', '/api/esrs-datapoints/export.csv')
        ->assertJsonPath('data.downloads.characterization_summary_pdf.endpoint', '/characterization/summary?format=pdf')
        ->assertJsonPath('data.limitations.0.key', 'report_package_scope');

    expect($response->json('data.sections.esrs_datapoints.total_datapoint_count'))->toBeGreaterThan(0);
    expect($response->json('data.sections.datapoint_responses.response_count'))->toBe(2);
    expect($response->json('data.sections.datapoint_responses.completed_count'))->toBe(1);
    expect($response->json('data.next_actions'))->toContain('/api/esrs-datapoints/responses');
});

it('blocks direct report package downloads until report inputs are ready', function () {
    reportReadyCharacterization($this->user, $this->e2Topic);

    $this->actingAs($this->user)
        ->get('/api/report/package')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'report_package_blocked')
        ->assertJsonPath('data.status', 'incomplete')
        ->assertJsonPath('data.downloads.report_package_html.status', 'incomplete')
        ->assertJsonPath('data.next_actions.0', '/api/double-materiality-guide');

    $this->actingAs($this->user)
        ->getJson('/api/report/evidence-bundle')
        ->assertStatus(409)
        ->assertJsonPath('data.type', 'report_package_blocked')
        ->assertJsonPath('data.downloads.evidence_bundle_json.status', 'incomplete');
});

it('reflects the stored double materiality guide status in report readiness', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.double_materiality_guide.status', 'missing');

    $formData = $characterization->form_data;
    $formData['double_materiality_process'] = [
        'checklist' => [
            'identified_stakeholders' => true,
            'assessed_impacts' => false,
            'assessed_financial_effects' => false,
            'reached_conclusions' => false,
        ],
        'acta' => [
            'completed_on' => null,
            'method' => null,
            'participants' => null,
        ],
        'updated_at' => now()->toJSON(),
    ];
    $characterization->forceFill(['form_data' => $formData])->save();

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.double_materiality_guide.status', 'in_progress');

    $formData['double_materiality_process']['checklist'] = [
        'identified_stakeholders' => true,
        'assessed_impacts' => true,
        'assessed_financial_effects' => true,
        'reached_conclusions' => true,
    ];
    $characterization->forceFill(['form_data' => $formData])->save();

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.double_materiality_guide.status', 'ready');
});

it('exposes stale materiality confirmation in report readiness without changing section status', function () {
    $s1Topic = EsrsTopic::where('esrs_code', 'S1')->firstOrFail();
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);
    $formData = $characterization->form_data;
    $formData['materiality_confirmation']['p6_snapshot'] = [
        'topic_ids' => [$this->e2Topic->id],
        'captured_at' => now()->subDay()->toJSON(),
    ];

    $characterization->forceFill([
        'esrs_topic_ids' => [$this->e2Topic->id, $s1Topic->id],
        'form_data' => $formData,
    ])->save();

    $response = $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.materiality_confirmation.status', 'ready')
        ->assertJsonPath('data.sections.materiality_confirmation.is_stale', true);

    expect(collect($response->json('data.limitations'))->firstWhere('key', 'materiality_confirmation_stale'))
        ->toMatchArray([
            'key' => 'materiality_confirmation_stale',
            'message' => 'The final materiality confirmation predates the latest proposal changes. Re-confirm in step 4.',
        ]);

    $formData['materiality_confirmation']['p6_snapshot']['topic_ids'] = [$this->e2Topic->id, $s1Topic->id];
    $characterization->forceFill(['form_data' => $formData])->save();

    $fresh = $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.materiality_confirmation.status', 'ready')
        ->assertJsonPath('data.sections.materiality_confirmation.is_stale', false);

    expect(collect($fresh->json('data.limitations'))->pluck('key')->all())
        ->not->toContain('materiality_confirmation_stale');
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
        ->assertJsonPath('data.limitations.0.key', 'report_package_scope');

    expect($response->json('data.datapoints.total_datapoint_count'))->toBeGreaterThan(0);
    expect($response->json('data.datapoints.blocks'))->not->toBeEmpty();
});

it('distinguishes scoping-only report coverage mode', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.coverage_mode', 'scoping_only')
        ->assertJsonPath('data.sections.esrs_datapoints.coverage_mode', 'scoping_only');

    $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.coverage_mode', 'scoping_only')
        ->assertJsonPath('data.datapoints.coverage_mode', 'scoping_only');

    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved test AR16 matter to DR map',
            'status' => 'approved',
        ],
        'mappings' => [
            [
                'ar16_topic_id' => $this->e2Topic->id,
                'esrs_code' => $this->e2Topic->esrs_code,
                'disclosure_requirements' => ['E2.IRO-1'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);

    $this->actingAs($characterization->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.coverage_mode', 'full')
        ->assertJsonPath('data.sections.esrs_datapoints.coverage_mode', 'full');

    $this->actingAs($characterization->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.coverage_mode', 'full')
        ->assertJsonPath('data.datapoints.coverage_mode', 'full');

    @unlink($mappingPath);
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

    completeDoubleMaterialityProcess($p8User->characterization()->firstOrFail());

    $this->actingAs($p8User)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.next_actions.0', '/api/materiality-confirmation');

    $characterization = reportReadyCharacterization(User::factory()->create(), $this->e2Topic);
    completeDoubleMaterialityProcess($characterization);

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
        ->assertJsonPath('data.status', 'incomplete')
        ->assertJsonPath('data.sections.double_materiality_guide.status', 'missing')
        ->assertJsonPath('data.sections.datapoint_responses.status', 'complete')
        ->assertJsonPath('data.sections.datapoint_responses.response_count', count($datapointIds))
        ->assertJsonPath('data.sections.datapoint_responses.completed_count', 0)
        ->assertJsonPath('data.sections.datapoint_responses.not_applicable_count', count($datapointIds))
        ->assertJsonPath('data.sections.datapoint_responses.completion_ratio', 1);

    $report = $this->actingAs($this->user)
        ->getJson('/api/report')
        ->json('data');

    expect($report['next_actions'])->toContain('/api/double-materiality-guide');

    completeDoubleMaterialityProcess($characterization);

    $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.generation_status', 'report_preparation_package_ready')
        ->assertJsonPath('data.readiness_status', 'ready')
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
    completeDoubleMaterialityProcess($readyCharacterization);

    $this->actingAs($readyUser)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.downloads.report_package_html.status', 'ready')
        ->assertJsonPath('data.downloads.evidence_bundle_json.status', 'ready')
        ->assertJsonPath('data.downloads.p8_decision_sheet.status', 'ready')
        ->assertJsonPath('data.downloads.p9_responses_csv.status', 'ready')
        ->assertJsonPath('data.downloads.p9_datapoints_csv.status', 'ready')
        ->assertJsonPath('data.downloads.characterization_summary_pdf.status', 'ready');
});

it('generates a self-contained report package and evidence bundle when report inputs are ready', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);
    configureApprovedReportDrMap($this->e2Topic);
    completeDoubleMaterialityProcess($characterization);
    completeReportDatapointResponses($characterization);

    $readiness = $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.sections.final_report_generation.status', 'ready')
        ->assertJsonPath('data.downloads.report_package_html.endpoint', '/api/report/package')
        ->assertJsonPath('data.downloads.report_package_html.status', 'ready')
        ->assertJsonPath('data.downloads.evidence_bundle_json.endpoint', '/api/report/evidence-bundle')
        ->assertJsonPath('data.downloads.evidence_bundle_json.status', 'ready')
        ->json('data');

    expect(collect($readiness['limitations'])->pluck('key')->all())
        ->toContain('report_package_scope')
        ->not->toContain('final_report_generation_pending');

    $html = $this->actingAs($this->user)
        ->get('/api/report/package')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('Paquete de preparación ESRS 2023', false)
        ->assertSee('Entidad Demo', false)
        ->assertSee('No sustituye la presentación oficial', false)
        ->getContent();

    expect($html)
        ->toContain('<!doctype html>')
        ->not->toContain('<script src=')
        ->not->toContain('<link href=');

    $this->actingAs($this->user)
        ->getJson('/api/report/evidence-bundle')
        ->assertOk()
        ->assertJsonPath('data.type', 'report_evidence_bundle')
        ->assertJsonPath('data.version', 'v0')
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.bundle.company.name', 'Entidad Demo')
        ->assertJsonPath('data.bundle.readiness.status', 'ready')
        ->assertJsonPath('data.traceability.ar16_to_dr_mapping.status', 'loaded')
        ->assertJsonPath('data.traceability.source_endpoints.report_package', '/api/report/package');
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

function fullyReadyReportCharacterization(User $user, EsrsTopic $topic): Characterization
{
    $characterization = reportReadyCharacterization($user, $topic);
    configureApprovedReportDrMap($topic);
    completeDoubleMaterialityProcess($characterization);
    completeReportDatapointResponses($characterization);

    return $characterization->fresh();
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

function configureApprovedReportDrMap(EsrsTopic $topic): void
{
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved test AR16 matter to DR map',
            'status' => 'approved',
        ],
        'mappings' => [
            [
                'ar16_topic_id' => $topic->id,
                'esrs_code' => $topic->esrs_code,
                'disclosure_requirements' => ['E2.IRO-1'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);
}

function completeReportDatapointResponses(Characterization $characterization): void
{
    $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
    $datapointIds = reportDatapointIds($corpus);

    $responses = collect($datapointIds)
        ->mapWithKeys(fn (string $id): array => [
            $id => [
                'datapoint_id' => $id,
                'status' => 'completed',
                'value' => "Prepared response for {$id}.",
                'evidence_reference' => "Evidence pack {$id}",
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
}

function completeDoubleMaterialityProcess(Characterization $characterization): void
{
    $formData = $characterization->form_data;
    $formData['double_materiality_process'] = [
        'checklist' => [
            'identified_stakeholders' => true,
            'assessed_impacts' => true,
            'assessed_financial_effects' => true,
            'reached_conclusions' => true,
        ],
        'acta' => [
            'completed_on' => now()->toDateString(),
            'method' => 'Internal ADM review',
            'participants' => 'Sustainability lead; Finance lead',
        ],
        'updated_at' => now()->toJSON(),
    ];

    $characterization->forceFill(['form_data' => $formData])->save();
}
