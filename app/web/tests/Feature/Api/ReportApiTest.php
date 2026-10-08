<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\EsrsDatapointCorpusBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FrontendCompatibilitySchema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->e2Topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('conforms to the bounded OpenAPI readiness and draft response schemas in both persisted locales', function (string $state) {
    config(['services.esrs_datapoints.matter_dr_mapping_path' => null]);
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);
    $mappingPath = null;
    try {
        if ($state !== 'incomplete') {
            configureApprovedReportDrMap($this->e2Topic);
            $mappingPath = config('services.esrs_datapoints.matter_dr_mapping_path');
            completeDoubleMaterialityProcess($characterization);
            completeReportDatapointResponses($characterization);
        } else {
            // Exercise all conditional limitation keys using persisted legacy data.
            $formData = $characterization->form_data;
            $otherTopic = EsrsTopic::whereKeyNot([$this->e2Topic->id])->firstOrFail();
            $formData['materiality_confirmation']['p6_snapshot'] = ['topic_ids' => [$otherTopic->id]];
            $formData['esrs_datapoint_responses']['responses']['obsolete_datapoint'] = ['status' => 'completed', 'value' => 'Historical user value'];
            $characterization->forceFill(['form_data' => $formData])->save();
        }
        if ($state === 'factual_ready') {
            $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
            foreach (reportDatapointIds($corpus) as $id) {
                ReportingFact::create([
                    'characterization_id' => $characterization->id,
                    'fact_id' => 'rf_contract_'.hash('sha256', $id),
                    'schema_version' => ReportingFact::SCHEMA_VERSION,
                    'profile_id' => ReportingFact::PROFILE_ID,
                    'datapoint_id' => $id,
                    'applicability' => 'applicable',
                    'value_type' => 'text',
                    'value' => ['text' => 'Synthetic reviewed user text'],
                    'dimensions' => [],
                    'language' => 'es',
                    'nil' => false,
                    'evidence_refs' => [['type' => 'note', 'value' => 'Synthetic contract fixture']],
                    'provenance' => 'api',
                    'approval_status' => 'reviewed',
                    'blocking_reasons' => [],
                ]);
            }
        }
        $before = $characterization->fresh()->getRawOriginal('form_data');
        $contract = new FrontendCompatibilitySchema();
        $this->actingAs($this->user)->withHeader('Accept-Language', 'en');
        $spanish = [];
        foreach (['es', 'en'] as $locale) {
            if ($locale === 'en') {
                $this->putJson('/api/locale', ['locale' => 'en'])->assertOk();
            }
            foreach (['/api/report', '/api/report/draft'] as $route) {
                $response = $this->getJson($route.'?locale='.($locale === 'es' ? 'en' : 'es'))
                    ->assertOk()->assertHeader('Content-Language', $locale)
                    ->assertJsonPath('data.locale', $locale)
                    ->assertJsonPath('data.workflow_status', $state === 'incomplete' ? 'incomplete' : 'ready')
                    ->assertJsonPath('data.workflow_complete', $state !== 'incomplete')
                    ->assertJsonPath('data.report_content_status', $state === 'factual_ready' ? 'ready' : 'incomplete')
                    ->assertJsonPath('data.report_content_ready', $state === 'factual_ready');
                expect($contract->responseErrors($route, $response->getContent()))->toBe([]);
                $response->assertJsonPath($route === '/api/report' ? 'data.status' : 'data.readiness_status', $state === 'factual_ready' ? 'ready' : 'incomplete');
                if ($route === '/api/report/draft') {
                    $response->assertJsonPath('data.generation_status', $state === 'incomplete' ? 'frontend_rendered_draft' : 'report_preparation_package_ready');
                    $response->assertJsonPath('data.company.name', 'Entidad Demo');
                }
                $data = $response->json('data');
                $keys = array_column($data['limitations'], 'key');
                expect($keys)->toContain('report_package_scope');
                if ($state === 'incomplete') {
                    expect($keys)->toContain('exact_ar16_matter_to_dr_mapping_pending', 'orphaned_datapoint_responses', 'materiality_confirmation_stale');
                }
                if ($locale === 'es') {
                    $spanish[$route] = $data;
                } else {
                    expect($data['limitations'][0]['message'])->not->toBe($spanish[$route]['limitations'][0]['message']);
                    expect($keys)->toBe(array_column($spanish[$route]['limitations'], 'key'));
                    foreach (['type', 'version', 'characterization_id', 'workflow_status', 'workflow_complete', 'report_content_status', 'report_content_ready', 'coverage_mode'] as $key) {
                        expect($data[$key])->toBe($spanish[$route][$key]);
                    }
                    if ($route === '/api/report/draft') {
                        expect($data['company'])->toBe($spanish[$route]['company']);
                    }
                }
                // Mutations of real emitted payloads prove required/type/enum checks are effective.
                foreach ([
                    function ($payload) { unset($payload->data->locale); },
                    fn ($payload) => $payload->data->locale = 'fr',
                    fn ($payload) => $payload->data->workflow_complete = 'true',
                    fn ($payload) => $payload->data->report_content_status = 'generation_pending',
                    fn ($payload) => $payload->data->limitations[0]->key = 'final_report_generation_pending',
                    fn ($payload) => $payload->data->limitations[0]->message = false,
                ] as $mutate) {
                    $payload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
                    $mutate($payload);
                    expect($contract->responseErrors($route, json_encode($payload, JSON_THROW_ON_ERROR)))->not->toBe([]);
                }
                $payload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
                if ($route === '/api/report') {
                    unset($payload->data->sections->report_content->required_count);
                } else {
                    $payload->data->generation_status = 'not_implemented';
                }
                expect($contract->responseErrors($route, json_encode($payload, JSON_THROW_ON_ERROR)))->not->toBe([]);
                if ($route === '/api/report/draft') {
                    foreach (['name', 'nace_code', 'status', 'reporting_year', 'product_service_type', 'employee_count_range', 'revenue_range', 'regions'] as $key) {
                        $payload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
                        unset($payload->data->company->{$key});
                        expect($contract->responseErrors($route, json_encode($payload, JSON_THROW_ON_ERROR)))->not->toBe([]);
                    }
                    foreach ([
                        fn ($payload) => $payload->data->company->name = false,
                        fn ($payload) => $payload->data->company->nace_code = 123,
                        fn ($payload) => $payload->data->company->status = 'ready',
                        fn ($payload) => $payload->data->company->status = null,
                        fn ($payload) => $payload->data->company->reporting_year = '2025',
                        fn ($payload) => $payload->data->company->product_service_type = 'invalid_product_service_type',
                        fn ($payload) => $payload->data->company->product_service_type = true,
                        fn ($payload) => $payload->data->company->employee_count_range = 'invalid_employee_count_range',
                        fn ($payload) => $payload->data->company->employee_count_range = 150,
                        fn ($payload) => $payload->data->company->revenue_range = 'invalid_revenue_range',
                        fn ($payload) => $payload->data->company->revenue_range = 6000000,
                        fn ($payload) => $payload->data->company->regions = 'eu',
                        fn ($payload) => $payload->data->company->regions = null,
                        fn ($payload) => $payload->data->company->regions = ['invalid_region'],
                        fn ($payload) => $payload->data->company->regions = [true],
                        fn ($payload) => $payload->data->company->extra = true,
                    ] as $mutate) {
                        $payload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
                        $mutate($payload);
                        expect($contract->responseErrors($route, json_encode($payload, JSON_THROW_ON_ERROR)))->not->toBe([]);
                    }
                }
            }
        }
        expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($before);
    } finally {
        if ($mappingPath !== null) {
            unlink($mappingPath);
        }
    }
})->with(['incomplete', 'workflow_complete', 'factual_ready']);

it('conforms to the bounded company schema for an empty draft in both persisted locales', function () {
    Characterization::factory()->create(['user_id' => $this->user->id]);
    $contract = new FrontendCompatibilitySchema();
    $this->actingAs($this->user)->withHeader('Accept-Language', 'en');

    foreach (['es', 'en'] as $locale) {
        if ($locale === 'en') {
            $this->putJson('/api/locale', ['locale' => 'en'])->assertOk();
        }
        $response = $this->getJson('/api/report/draft?locale='.($locale === 'es' ? 'en' : 'es'))
            ->assertOk()->assertHeader('Content-Language', $locale)
            ->assertJsonPath('data.locale', $locale)
            ->assertJsonPath('data.readiness_status', 'incomplete');
        expect($response->json('data.company'))->toBe([
            'name' => null,
            'nace_code' => null,
            'status' => Characterization::STATUS_DRAFT,
            'reporting_year' => null,
            'product_service_type' => null,
            'employee_count_range' => null,
            'revenue_range' => null,
            'regions' => [],
        ]);
        expect($contract->responseErrors('/api/report/draft', $response->getContent()))->toBe([]);
    }
});

it('conforms to the bounded OpenAPI null report envelopes in both locales', function () {
    $contract = new FrontendCompatibilitySchema();
    $this->actingAs($this->user)->withHeader('Accept-Language', 'en');
    foreach (['es', 'en'] as $locale) {
        if ($locale === 'en') {
            $this->putJson('/api/locale', ['locale' => 'en'])->assertOk();
        }
        foreach (['/api/report', '/api/report/draft'] as $route) {
            $response = $this->getJson($route)->assertOk()->assertHeader('Content-Language', $locale)->assertJsonPath('data', null);
            expect($contract->responseErrors($route, $response->getContent()))->toBe([]);
            expect($contract->responseErrors($route, '{}'))->not->toBe([]);
            expect($contract->responseErrors($route, '{"data":[]}'))->not->toBe([]);
        }
    }
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
        ->assertJsonPath('data.downloads.p9_responses_csv.endpoint', '/api/esrs-datapoints/responses/export.localized.csv')
        ->assertJsonPath('data.downloads.p9_datapoints_csv.endpoint', '/api/esrs-datapoints/export.localized.csv')
        ->assertJsonPath('data.downloads.characterization_summary_pdf.endpoint', '/characterization/summary?format=pdf')
        ->assertJsonPath('data.limitations.0.key', 'report_package_scope')
        ->assertJsonPath('data.limitations.0.message', 'El paquete permite preparar el informe NEIS 2023 y organizar sus evidencias. No sustituye la presentación oficial ni el aseguramiento, no acredita el cumplimiento de la Taxonomía de la UE y no genera de forma nativa documentos PDF ni formatos electrónicos regulatorios.');

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
            'message' => 'La confirmación final de materialidad es anterior a los últimos cambios de la propuesta. Vuelve a confirmarla en el paso 4.',
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

it('reports auditable AR16 leaves separately from grouped material themes', function () {
    $e1Topics = EsrsTopic::where('esrs_code', 'E1')->orderBy('id')->limit(2)->get();
    $s1Topic = EsrsTopic::where('esrs_code', 'S1')->firstOrFail();
    $confirmedTopicIds = [...$e1Topics->pluck('id')->all(), $s1Topic->id];
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);
    $formData = $characterization->form_data;
    $formData['materiality_confirmation']['confirmed_topic_ids'] = $confirmedTopicIds;
    $formData['materiality_confirmation']['p6_snapshot'] = [
        'topic_ids' => $confirmedTopicIds,
        'captured_at' => now()->toJSON(),
    ];

    $characterization->forceFill([
        'esrs_topic_ids' => $confirmedTopicIds,
        'form_data' => $formData,
    ])->save();

    $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.materiality.confirmed_topic_count', 3)
        ->assertJsonPath('data.materiality.confirmed_theme_count', 2)
        ->assertJsonPath('data.materiality.confirmed_themes.0.esrs_code', 'E1')
        ->assertJsonPath('data.materiality.confirmed_themes.0.label', 'Cambio climático')
        ->assertJsonPath('data.materiality.confirmed_themes.1.esrs_code', 'S1')
        ->assertJsonPath('data.materiality.confirmed_themes.1.label', 'Personal propio');
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

it('separates a completed workflow from factual report readiness', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);
    $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
    $datapointIds = reportDatapointIds($corpus);
    $responses = collect($datapointIds)
        ->mapWithKeys(fn (string $id, int $index): array => [
            $id => [
                'datapoint_id' => $id,
                'status' => $index < 10 ? 'completed' : 'not_applicable',
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
        ->assertJsonPath('data.sections.datapoint_responses.completed_count', 10)
        ->assertJsonPath('data.sections.datapoint_responses.not_applicable_count', count($datapointIds) - 10)
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
        ->assertJsonPath('data.readiness_status', 'incomplete')
        ->assertJsonPath('data.workflow_status', 'ready')
        ->assertJsonPath('data.workflow_complete', true)
        ->assertJsonPath('data.report_content_status', 'incomplete')
        ->assertJsonPath('data.report_content_ready', false)
        ->assertJsonPath('data.datapoints.response_status', 'complete')
        ->assertJsonPath('data.datapoints.response_count', count($datapointIds))
        ->assertJsonPath('data.datapoints.completed_count', 10)
        ->assertJsonPath('data.datapoints.not_applicable_count', count($datapointIds) - 10)
        ->assertJsonPath('data.datapoints.completion_ratio', 1);

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.status', 'incomplete')
        ->assertJsonPath('data.workflow_complete', true)
        ->assertJsonPath('data.report_content_ready', false)
        ->assertJsonPath('data.sections.final_report_generation.status', 'blocked')
        ->assertJsonPath('data.sections.final_report_generation.reason_code', 'no_persisted_facts')
        ->assertJsonPath('data.downloads.report_package_html.status', 'ready');

    ReportingFact::create([
        'characterization_id' => $characterization->id,
        'fact_id' => 'rf_partial_report_content',
        'schema_version' => ReportingFact::SCHEMA_VERSION,
        'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => $datapointIds[0],
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Hecho empresarial revisado para comprobar cobertura parcial.'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [],
        'language' => 'es',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Evidencia sintética de prueba.']],
        'provenance' => 'api',
        'approval_status' => 'reviewed',
        'blocking_reasons' => [],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.report_content.claimable_count', 1)
        ->assertJsonPath('data.sections.report_content.required_count', 10)
        ->assertJsonPath('data.sections.report_content.reason_code', 'completed_datapoint_facts_missing')
        ->assertJsonPath('data.sections.final_report_generation.reason_code', 'completed_datapoint_facts_missing');
});

it('uses the same effective required datapoints as P9 when deciding P10 workflow completion', function () {
    $characterization = reportReadyCharacterization($this->user, $this->e2Topic);
    completeDoubleMaterialityProcess($characterization);
    $corpus = app(EsrsDatapointCorpusBuilder::class)->build($characterization);
    $datapoints = collect($corpus['blocks'])
        ->flatMap(fn (array $block): array => $block['datapoints'] ?? []);
    $requiredIds = $datapoints
        ->filter(fn (array $datapoint): bool => (bool) data_get($datapoint, 'selection.default_selected', true))
        ->pluck('id')
        ->values();

    expect($requiredIds)->not->toBeEmpty()
        ->and($requiredIds->count())->toBeLessThan($datapoints->count());

    $formData = $characterization->form_data;
    $formData['esrs_datapoint_responses'] = [
        'schema_version' => 'v1',
        'revision' => 1,
        'updated_at' => now()->toJSON(),
        'responses' => $requiredIds->mapWithKeys(fn (string $id): array => [
            $id => [
                'datapoint_id' => $id,
                'status' => 'completed',
                'updated_at' => now()->toJSON(),
            ],
        ])->all(),
    ];
    $characterization->forceFill(['form_data' => $formData])->save();

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.workflow_complete', true)
        ->assertJsonPath('data.sections.datapoint_responses.status', 'complete')
        ->assertJsonPath('data.sections.datapoint_responses.effective_required_datapoint_count', $requiredIds->count())
        ->assertJsonPath('data.sections.datapoint_responses.completion_ratio', 1);
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
        ->assertJsonPath('data.status', 'incomplete')
        ->assertJsonPath('data.workflow_complete', true)
        ->assertJsonPath('data.report_content_ready', false)
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
        ->assertJsonPath('data.status', 'incomplete')
        ->assertJsonPath('data.workflow_complete', true)
        ->assertJsonPath('data.report_content_ready', false)
        ->assertJsonPath('data.sections.final_report_generation.status', 'blocked')
        ->assertJsonPath('data.sections.final_report_generation.reason_code', 'no_persisted_facts')
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
        ->assertSee('Paquete de preparación NEIS 2023', false)
        ->assertSee('Entidad Demo', false)
        ->assertSee('No sustituye la presentación oficial', false)
        ->getContent();

    expect($html)
        ->toContain('<!doctype html>')
        ->toContain('Datos normativos decididos')
        ->toContain('Cobertura de datos normativos')
        ->not->toContain('Datapoints')
        ->not->toContain('xHTML/iXBRL')
        ->not->toContain('<script src=')
        ->not->toContain('<link href=');

    $this->actingAs($this->user)
        ->getJson('/api/report/evidence-bundle')
        ->assertOk()
        ->assertJsonPath('data.type', 'report_evidence_bundle')
        ->assertJsonPath('data.version', 'v0')
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.bundle.company.name', 'Entidad Demo')
        ->assertJsonPath('data.bundle.readiness.status', 'incomplete')
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
