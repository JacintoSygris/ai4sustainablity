<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->e1Topic = EsrsTopic::where('esrs_code', 'E1')->firstOrFail();
    $this->e2Topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
    $this->s1Topic = EsrsTopic::where('esrs_code', 'S1')->firstOrFail();
    $this->s2Topic = EsrsTopic::where('esrs_code', 'S2')->firstOrFail();
});

it('requires authentication for the ESRS datapoint corpus', function () {
    $this->getJson('/api/esrs-datapoints')
        ->assertUnauthorized();
});

it('requires authentication for the ESRS datapoint CSV export', function () {
    $this->getJson('/api/esrs-datapoints/export.csv')
        ->assertUnauthorized();
});

it('requires authentication for ESRS datapoint responses', function () {
    $this->getJson('/api/esrs-datapoints/responses')
        ->assertUnauthorized();

    $this->putJson('/api/esrs-datapoints/responses', ['responses' => []])
        ->assertUnauthorized();

    $this->getJson('/api/esrs-datapoints/responses/export.csv')
        ->assertUnauthorized();
});

it('returns null when the current user has no characterization', function () {
    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('returns null response state when the current user has no characterization', function () {
    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('returns not found when exporting datapoints without a characterization', function () {
    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/export.csv')
        ->assertNotFound()
        ->assertJsonPath('message', 'No characterization found.');

    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses/export.csv')
        ->assertNotFound()
        ->assertJsonPath('message', 'No characterization found.');
});

it('stores frontend ESRS datapoint responses for the current corpus', function () {
    $mappingPath = configureApprovedMatterDrMap([
        [
            'ar16_topic_id' => $this->e2Topic->id,
            'esrs_code' => 'E2',
            'disclosure_requirements' => ['E2.IRO-1'],
        ],
        [
            'ar16_topic_id' => $this->s1Topic->id,
            'esrs_code' => 'S1',
            'disclosure_requirements' => ['S1.SBM-3'],
        ],
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            ],
        ],
    ]);

    $payload = [
        'responses' => [
            [
                'datapoint_id' => 'BP-1_01',
                'status' => 'draft',
                'value' => 'Prepared on a consolidated basis.',
                'evidence_reference' => 'Finance pack 2025',
            ],
            [
                'datapoint_id' => 'E2.IRO-1_01',
                'status' => 'completed',
                'value' => 'Pollution IRO screening completed.',
                'note' => 'Reviewed with operations lead.',
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', $payload)
        ->assertOk()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.summary.response_count', 2)
        ->assertJsonPath('data.summary.completed_count', 1)
        ->assertJsonPath('data.summary.draft_count', 1)
        ->assertJsonPath('data.summary.completion_status', 'in_progress')
        ->assertJsonPath('data.responses.BP-1_01.status', 'draft');

    expect($response->json('data.responses')['E2.IRO-1_01']['status'])
        ->toBe('completed');

    $this->assertDatabaseHas('characterizations', [
        'id' => $characterization->id,
    ]);

    expect($characterization->fresh()->form_data['esrs_datapoint_responses']['responses']['E2.IRO-1_01']['value'])
        ->toBe('Pollution IRO screening completed.');

    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses')
        ->assertOk()
        ->assertJsonPath('data.summary.response_count', 2)
        ->assertJsonPath('data.responses.BP-1_01.evidence_reference', 'Finance pack 2025');

    @unlink($mappingPath);
});

it('rejects duplicate canonical datapoint response ids after trimming', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'responses' => [
                [
                    'datapoint_id' => 'BP-1_01',
                    'status' => 'draft',
                    'value' => 'First row.',
                ],
                [
                    'datapoint_id' => ' BP-1_01 ',
                    'status' => 'completed',
                    'value' => 'Whitespace duplicate row.',
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'responses.0.datapoint_id',
            'responses.1.datapoint_id',
        ]);
});

it('accepts trimmed response ids and explicit full-replacement clear semantics', function () {
    $mappingPath = configureApprovedMatterDrMap([
        [
            'ar16_topic_id' => $this->e2Topic->id,
            'esrs_code' => 'E2',
            'disclosure_requirements' => ['E2.IRO-1'],
        ],
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'responses' => [
                [
                    'datapoint_id' => ' BP-1_01 ',
                    'status' => 'draft',
                    'value' => 'Prepared on a consolidated basis.',
                    'evidence_reference' => 'Finance pack 2025',
                    'note' => 'Initial note.',
                ],
                [
                    'datapoint_id' => 'E2.IRO-1_01',
                    'status' => 'completed',
                    'value' => 'Pollution IRO screening completed.',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.summary.response_count', 2)
        ->assertJsonPath('data.responses.BP-1_01.value', 'Prepared on a consolidated basis.');

    $replacement = $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'responses' => [
                [
                    'datapoint_id' => 'BP-1_01',
                    'status' => 'draft',
                    'value' => '',
                    'evidence_reference' => null,
                    'note' => '',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.summary.response_count', 1)
        ->assertJsonMissingPath('data.responses.E2.IRO-1_01')
        ->assertJsonMissingPath('data.responses.BP-1_01.value')
        ->assertJsonMissingPath('data.responses.BP-1_01.evidence_reference')
        ->assertJsonMissingPath('data.responses.BP-1_01.note');

    expect(array_keys($replacement->json('data.responses')))->toBe(['BP-1_01']);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', ['responses' => []])
        ->assertOk()
        ->assertJsonPath('data.summary.response_count', 0)
        ->assertJsonPath('data.summary.completion_status', 'not_started')
        ->assertJsonPath('data.responses', []);

    expect($characterization->fresh()->form_data['esrs_datapoint_responses']['responses'])
        ->toBe([]);

    @unlink($mappingPath);
});

it('rejects responses for datapoints outside the current corpus', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'responses' => [
                [
                    'datapoint_id' => 'S1.SBM-3_01',
                    'status' => 'completed',
                    'value' => 'Should not be accepted for an E2-only corpus.',
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('responses');
});

it('filters stale response ids from response state csv exports and report handoff', function () {
    $mappingPath = configureApprovedMatterDrMap([
        [
            'ar16_topic_id' => $this->e2Topic->id,
            'esrs_code' => 'E2',
            'disclosure_requirements' => ['E2.IRO-1'],
        ],
    ]);

    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$this->e2Topic->id],
        'submitted_at' => now()->subDay(),
        'completed_at' => now(),
        'form_data' => [
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
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id],
                'confirmed_at' => now()->toJSON(),
            ],
            'esrs_datapoint_responses' => [
                'schema_version' => 'v0',
                'updated_at' => now()->toJSON(),
                'responses' => [
                    'BP-1_01' => [
                        'datapoint_id' => 'BP-1_01',
                        'status' => 'completed',
                        'value' => 'Baseline response.',
                        'updated_at' => now()->toJSON(),
                    ],
                    'E2.IRO-1_01' => [
                        'datapoint_id' => 'E2.IRO-1_01',
                        'status' => 'draft',
                        'value' => 'Current E2 response.',
                        'updated_at' => now()->toJSON(),
                    ],
                    'S1.SBM-3_01' => [
                        'datapoint_id' => 'S1.SBM-3_01',
                        'status' => 'completed',
                        'value' => 'Stale S1 response.',
                        'updated_at' => now()->toJSON(),
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses')
        ->assertOk()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.summary.response_count', 2)
        ->assertJsonPath('data.summary.completed_count', 1)
        ->assertJsonMissingPath('data.responses.S1.SBM-3_01');

    $responseRows = csvRows($this->actingAs($this->user)
        ->get('/api/esrs-datapoints/responses/export.csv')
        ->assertOk()
        ->getContent());

    $responseHeader = array_shift($responseRows);
    $datapointIdColumn = array_search('datapoint_id', $responseHeader, true);

    expect(collect($responseRows)->pluck($datapointIdColumn)->all())
        ->toContain('BP-1_01', 'E2.IRO-1_01')
        ->not->toContain('S1.SBM-3_01');

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.datapoint_responses.response_count', 2)
        ->assertJsonPath('data.sections.datapoint_responses.completed_count', 1);

    $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.datapoints.response_count', 2)
        ->assertJsonPath('data.datapoints.completed_count', 1);

    @unlink($mappingPath);
});

it('downloads frontend ESRS datapoint responses with corpus context as csv', function () {
    $mappingPath = configureApprovedMatterDrMap([
        [
            'ar16_topic_id' => $this->e2Topic->id,
            'esrs_code' => 'E2',
            'disclosure_requirements' => ['E2.IRO-1'],
        ],
    ]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'responses' => [
                [
                    'datapoint_id' => 'BP-1_01',
                    'status' => 'draft',
                    'value' => "Prepared on a consolidated basis, with commas\nand a \"quoted\" note.",
                    'evidence_reference' => 'Finance pack 2025',
                ],
                [
                    'datapoint_id' => 'E2.IRO-1_01',
                    'status' => 'completed',
                    'value' => 'Pollution IRO screening completed.',
                    'note' => 'Reviewed with operations lead.',
                ],
            ],
        ])
        ->assertOk();

    $response = $this->actingAs($this->user)
        ->get('/api/esrs-datapoints/responses/export.csv')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('content-disposition', 'attachment; filename=esrs-datapoint-responses.csv');

    $rows = csvRows($response->getContent());
    $header = array_shift($rows);
    $datapointIdColumn = array_search('datapoint_id', $header, true);
    $responseValueColumn = array_search('response_value', $header, true);

    expect($header)->toBe([
        'block_key',
        'disclosure_requirement_key',
        'datapoint_id',
        'standard',
        'dr',
        'name',
        'applicability_reason_code',
        'applicability_reason',
        'applicability_mapping_basis',
        'applicability_limitations',
        'response_status',
        'response_value',
        'evidence_reference',
        'note',
        'response_updated_at',
    ]);

    $corpusCount = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->json('data.summary.total_datapoint_count');

    expect($rows)->toHaveCount($corpusCount);
    expect(collect($rows)->every(fn (array $row): bool => count($row) === count($header)))->toBeTrue();

    $rowsByDatapoint = collect($rows)->keyBy($datapointIdColumn);

    expect($rowsByDatapoint->keys()->all())
        ->toContain('BP-1_01', 'E2.IRO-1_01');
    expect($rowsByDatapoint->get('BP-1_01')[$responseValueColumn])
        ->toBe("Prepared on a consolidated basis, with commas\nand a \"quoted\" note.");
    expect($rowsByDatapoint->get('E2.IRO-1_01'))
        ->toContain('completed', 'Reviewed with operations lead.');

    @unlink($mappingPath);
});

it('fails closed without an approved AR16 matter to Disclosure Requirement map', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
                'e1_not_material_explanation' => 'Climate impacts are below the documented ADM threshold.',
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.material_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.activated_esrs_standards', ['E2', 'S1'])
        ->assertJsonPath('data.generation.source_name', 'EFRAG IG 3 List of ESRS Data Points')
        ->assertJsonPath('data.generation.mapping_granularity', 'disclosure_requirement_mapping_required')
        ->assertJsonPath('data.generation.matter_to_dr_mapping_status', 'pending')
        ->assertJsonPath('data.generation.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.matter_mapping.status', 'pending')
        ->assertJsonPath('data.matter_mapping.scope', 'ar16_matter_to_disclosure_requirement')
        ->assertJsonPath('data.matter_mapping.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.matter_mapping.current_filter', 'topical_blocked_until_dr_mapping')
        ->assertJsonPath('data.phase_in_assessment.status', 'eligible_less_than_750')
        ->assertJsonPath('data.phase_in_assessment.employee_count.source', 'employee_count_range')
        ->assertJsonPath('data.phase_in_assessment.employee_count.range', '50_249')
        ->assertJsonPath('data.phase_in_assessment.employee_count.estimate', 150)
        ->assertJsonPath('data.phase_in_assessment.employee_count.less_than_750', true)
        ->assertJsonPath('data.blocks.always_required.standards', ['ESRS 2'])
        ->assertJsonPath('data.blocks.topical.standards', ['E2', 'S1'])
        ->assertJsonPath('data.blocks.topical.applies', false)
        ->assertJsonPath('data.blocks.topical.datapoint_count', 0)
        ->assertJsonPath('data.blocks.e1_not_material_explanation.applies', true)
        ->assertJsonPath('data.completion_plan.strategy', 'baseline_then_material_topics')
        ->assertJsonPath('data.completion_plan.phases.0.key', 'always_required')
        ->assertJsonPath('data.completion_plan.phases.1.key', 'topical')
        ->assertJsonPath('data.completion_plan.phases.1.status', 'blocked')
        ->assertJsonPath('data.completion_plan.phases.1.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.completion_plan.phases.2.key', 'minimum_disclosure_requirements')
        ->assertJsonPath('data.completion_plan.phases.3.key', 'e1_not_material_explanation')
        ->assertJsonPath('data.completion_plan.phases.3.status', 'satisfied')
        ->assertJsonPath('data.blocks.e1_not_material_explanation.explanation', 'Climate impacts are below the documented ADM threshold.');

    expect($response->json('data.summary.always_required_datapoint_count'))->toBeGreaterThan(0);
    expect($response->json('data.summary.topical_datapoint_count'))->toBe(0);
    expect($response->json('data.summary.total_datapoint_count'))->toBeGreaterThan(0);
    expect($response->json('data.summary.total_datapoint_count'))->toBeLessThan(300);
    expect($response->json('data.phase_in_assessment.counts.less_than_750_relief_datapoint_count'))->toBeGreaterThan(0);
    expect($response->json('data.phase_in_assessment.counts.applicable_phase_in_datapoint_count'))->toBeGreaterThan(0);
    expect($response->json('data.generation.limitations.0'))
        ->toContain('topical datapoints are not included');

    expect(collect($response->json('data.blocks.always_required.datapoints'))->pluck('id'))
        ->toContain('BP-1_01');
    expect(collect($response->json('data.blocks.always_required.disclosure_requirements'))->pluck('key'))
        ->toContain('BP-1');
    expect(collect($response->json('data.blocks.topical.datapoints'))->pluck('id'))
        ->not->toContain('E2.IRO-1_01', 'S1.SBM-3_01');

    expect($response->json('data.completion_plan.phases.0.datapoint_count'))
        ->toBe($response->json('data.summary.always_required_datapoint_count'));
    expect($response->json('data.completion_plan.phases.1.standards'))
        ->toBe(['E2', 'S1']);

    $matterMappingTopics = collect($response->json('data.matter_mapping.material_topics'));

    expect($matterMappingTopics->pluck('topic_id'))->toContain($this->e2Topic->id, $this->s1Topic->id);
    expect($matterMappingTopics->pluck('mapping_status')->unique()->values()->all())
        ->toBe(['pending_explicit_dr_mapping']);

    $e2MatterMapping = $matterMappingTopics->firstWhere('topic_id', $this->e2Topic->id);

    expect($e2MatterMapping['current_filter'])->toBe('topical_blocked_until_dr_mapping');
    expect($e2MatterMapping['standard_level_disclosure_requirement_count'])->toBe(0);
    expect($e2MatterMapping['standard_level_datapoint_count'])->toBe(0);

    $topicalDisclosureRequirements = collect($response->json('data.blocks.topical.disclosure_requirements'));
    $alwaysRequiredDatapoints = collect($response->json('data.blocks.always_required.datapoints'))->keyBy('id');
    $topicalDatapoints = collect($response->json('data.blocks.topical.datapoints'))->keyBy('id');

    expect($alwaysRequiredDatapoints->get('BP-1_01')['applicability'])->toMatchArray([
        'block_key' => 'always_required',
        'reason_code' => 'always_required_esrs_2',
        'source_chain' => [
            'source_dataset' => 'EFRAG IG 3 List of ESRS Data Points',
            'esrs_standard' => 'ESRS 2',
            'disclosure_requirement' => 'BP-1',
            'datapoint_id' => 'BP-1_01',
        ],
    ]);

    expect($topicalDisclosureRequirements->pluck('key'))
        ->toBeEmpty();
});

it('uses an approved AR16 matter to Disclosure Requirement map when configured', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');
    $unselectedE2Topic = EsrsTopic::where('esrs_code', 'E2')
        ->whereKeyNot($this->e2Topic->id)
        ->firstOrFail();

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved test AR16 matter to DR map',
            'status' => 'approved',
        ],
        'mappings' => [
            [
                'ar16_topic_id' => $this->e2Topic->id,
                'esrs_code' => 'E2',
                'disclosure_requirements' => ['E2.IRO-1'],
            ],
            [
                'ar16_topic_id' => $this->s1Topic->id,
                'esrs_code' => 'S1',
                'disclosure_requirements' => ['S1.SBM-3'],
            ],
            [
                'ar16_topic_id' => $unselectedE2Topic->id,
                'esrs_code' => 'E2',
                'disclosure_requirements' => ['E2-1'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.mapping_granularity', 'disclosure_requirement_level')
        ->assertJsonPath('data.generation.matter_to_dr_mapping_status', 'loaded')
        ->assertJsonPath('data.generation.coverage_status', 'dr_level')
        ->assertJsonPath('data.matter_mapping.status', 'loaded')
        ->assertJsonPath('data.matter_mapping.coverage_status', 'dr_level')
        ->assertJsonPath('data.blocks.topical.standards', ['E2', 'S1'])
        ->assertJsonPath('data.completion_plan.phases.1.coverage_status', 'dr_level');

    $topicalDisclosureRequirements = collect($response->json('data.blocks.topical.disclosure_requirements'));

    expect($topicalDisclosureRequirements->pluck('key')->all())
        ->toBe(['E2.IRO-1', 'S1.SBM-3']);

    expect(collect($response->json('data.blocks.topical.datapoints'))->pluck('id'))
        ->toContain('E2.IRO-1_01', 'S1.SBM-3_01')
        ->not->toContain('E2.MDR-P_01-06');

    $topicalDatapoints = collect($response->json('data.blocks.topical.datapoints'))->keyBy('id');

    expect($topicalDatapoints->get('E2.IRO-1_01')['applicability']['mapping_basis'])
        ->toBe('mapped_disclosure_requirements');

    $matterMappingTopics = collect($response->json('data.matter_mapping.material_topics'));

    expect($matterMappingTopics->pluck('mapping_status')->unique()->values()->all())
        ->toBe(['mapped_to_disclosure_requirements']);
    expect($matterMappingTopics->firstWhere('topic_id', $this->e2Topic->id)['mapped_disclosure_requirement_keys'])
        ->toBe(['E2.IRO-1']);
    expect($matterMappingTopics->firstWhere('topic_id', $this->e2Topic->id)['mapped_datapoint_count'])
        ->toBe(3);

    @unlink($mappingPath);
});

it('filters DR-level topical datapoints by selected ESRS and DR pairs', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved test AR16 matter to DR map with S2 collision',
            'status' => 'approved',
        ],
        'mappings' => [
            [
                'ar16_topic_id' => $this->s1Topic->id,
                'esrs_code' => 'S1',
                'disclosure_requirements' => ['S1.SBM-3'],
            ],
            [
                'ar16_topic_id' => $this->s2Topic->id,
                'esrs_code' => 'S2',
                'disclosure_requirements' => ['S2-1'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->s1Topic->id, $this->s2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->s1Topic->id, $this->s2Topic->id],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.mapping_granularity', 'disclosure_requirement_level')
        ->assertJsonPath('data.generation.coverage_status', 'dr_level');

    $topicalDatapoints = collect($response->json('data.blocks.topical.datapoints'));

    expect($topicalDatapoints->pluck('id')->all())
        ->toContain('S1.SBM-3_01', 'S2-1_01')
        ->not->toContain('S2-1_11');

    $mappedDatapointCount = collect($response->json('data.matter_mapping.material_topics'))
        ->sum('mapped_datapoint_count');

    expect($response->json('data.blocks.topical.datapoint_count'))
        ->toBe($mappedDatapointCount);

    @unlink($mappingPath);
});

it('fails closed when an approved matter map duplicates a selected topic', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved test AR16 matter to DR map with duplicate topic',
            'status' => 'approved',
        ],
        'mappings' => [
            [
                'ar16_topic_id' => $this->e2Topic->id,
                'esrs_code' => 'E2',
                'disclosure_requirements' => ['E2.IRO-1'],
            ],
            [
                'ar16_topic_id' => $this->e2Topic->id,
                'esrs_code' => 'E2',
                'disclosure_requirements' => ['E2-1'],
            ],
            [
                'ar16_topic_id' => $this->s1Topic->id,
                'esrs_code' => 'S1',
                'disclosure_requirements' => ['S1.SBM-3'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.mapping_granularity', 'disclosure_requirement_mapping_required')
        ->assertJsonPath('data.generation.matter_to_dr_mapping_status', 'partial')
        ->assertJsonPath('data.generation.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.matter_mapping.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.blocks.topical.datapoint_count', 0);

    expect($response->json('data.generation.limitations.0'))
        ->toContain('duplicate');

    @unlink($mappingPath);
});

it('fails closed when a full approved AR16 matter map has invalid DR keys', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved test AR16 matter to DR map with invalid keys',
            'status' => 'approved',
        ],
        'mappings' => [
            [
                'ar16_topic_id' => $this->e2Topic->id,
                'esrs_code' => 'S1',
                'disclosure_requirements' => ['S1.SBM-3'],
            ],
            [
                'ar16_topic_id' => $this->s1Topic->id,
                'esrs_code' => 'S1',
                'disclosure_requirements' => ['S1.SBM-3'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.mapping_granularity', 'disclosure_requirement_mapping_required')
        ->assertJsonPath('data.generation.matter_to_dr_mapping_status', 'partial')
        ->assertJsonPath('data.generation.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.matter_mapping.status', 'partial')
        ->assertJsonPath('data.matter_mapping.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.completion_plan.phases.1.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.blocks.topical.datapoint_count', 0);

    expect($response->json('data.generation.limitations.0'))
        ->toContain('missing or invalid');
    expect(collect($response->json('data.blocks.topical.disclosure_requirements'))->pluck('key'))
        ->toBeEmpty();

    $matterMappingTopics = collect($response->json('data.matter_mapping.material_topics'));

    expect($matterMappingTopics->pluck('mapping_status')->unique()->values()->all())
        ->toBe(['pending_explicit_dr_mapping']);
    expect($matterMappingTopics->pluck('current_filter')->unique()->values()->all())
        ->toBe(['topical_blocked_until_dr_mapping']);

    @unlink($mappingPath);
});

it('does not advertise per-topic DR filtering while an approved matter map is partial', function () {
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved partial test AR16 matter to DR map',
            'status' => 'approved',
        ],
        'mappings' => [
            [
                'ar16_topic_id' => $this->e2Topic->id,
                'esrs_code' => 'E2',
                'disclosure_requirements' => ['E2.IRO-1'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.mapping_granularity', 'disclosure_requirement_mapping_required')
        ->assertJsonPath('data.generation.matter_to_dr_mapping_status', 'partial')
        ->assertJsonPath('data.matter_mapping.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.blocks.topical.datapoint_count', 0);

    $matterMappingTopics = collect($response->json('data.matter_mapping.material_topics'));

    expect($matterMappingTopics->pluck('mapping_status')->unique()->values()->all())
        ->toBe(['pending_explicit_dr_mapping']);
    expect($matterMappingTopics->pluck('current_filter')->unique()->values()->all())
        ->toBe(['topical_blocked_until_dr_mapping']);
    expect(collect($response->json('data.blocks.topical.disclosure_requirements'))->pluck('key'))
        ->toBeEmpty();

    @unlink($mappingPath);
});

it('downloads the deterministic datapoint corpus as csv', function () {
    $mappingPath = configureApprovedMatterDrMap([
        [
            'ar16_topic_id' => $this->e2Topic->id,
            'esrs_code' => 'E2',
            'disclosure_requirements' => ['E2.IRO-1'],
        ],
        [
            'ar16_topic_id' => $this->s1Topic->id,
            'esrs_code' => 'S1',
            'disclosure_requirements' => ['S1.SBM-3'],
        ],
    ]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
                'e1_not_material_explanation' => 'Climate impacts are below the documented ADM threshold.',
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->get('/api/esrs-datapoints/export.csv')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('content-disposition', 'attachment; filename=esrs-datapoints.csv');

    $rows = csvRows($response->getContent());
    $header = array_shift($rows);
    $datapointIdColumn = array_search('datapoint_id', $header, true);

    expect($header)->toBe([
        'block_key',
        'block_title',
        'disclosure_requirement_key',
        'datapoint_id',
        'standard',
        'dr',
        'paragraph',
        'related_ar',
        'name',
        'data_type',
        'conditional_or_alternative',
        'may_disclose',
        'appendix_b',
        'phase_in_less_than_750',
        'phase_in_all_undertakings',
    ]);

    $corpusCount = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->json('data.summary.total_datapoint_count');

    expect($rows)->toHaveCount($corpusCount);
    expect(collect($rows)->every(fn (array $row): bool => count($row) === count($header)))->toBeTrue();
    expect(collect($rows)->pluck($datapointIdColumn)->all())
        ->toContain('BP-1_01', 'E2.IRO-1_01', 'S1.SBM-3_01');

    @unlink($mappingPath);
});

function configureApprovedMatterDrMap(array $mappings): string
{
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode([
        'version' => 'v0',
        'source' => [
            'name' => 'Approved test AR16 matter to DR map',
            'status' => 'approved',
        ],
        'mappings' => $mappings,
    ], JSON_THROW_ON_ERROR));

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);

    return $mappingPath;
}

function csvRows(string $csv): array
{
    $handle = fopen('php://temp', 'r+');

    fwrite($handle, $csv);
    rewind($handle);

    $rows = [];

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}
