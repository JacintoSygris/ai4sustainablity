<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
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
        'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'entity-1'],
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
                'facts' => [validFact(['value' => 'Pollution IRO screening completed.'])],
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

    expect($characterization->fresh()->form_data['esrs_datapoint_responses']['responses']['E2.IRO-1_01']['facts'][0]['value'])
        ->toBe('Pollution IRO screening completed.');

    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses')
        ->assertOk()
        ->assertJsonPath('data.summary.response_count', 2)
        ->assertJsonPath('data.responses.BP-1_01.evidence_reference', 'Finance pack 2025');

    @unlink($mappingPath);
});

it('stores valid monetary and percent facts with server derived concepts', function () {
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

    $payload = [
        'reporting_entity' => [
            'identifier_scheme' => 'https://example.test/entity-id',
            'identifier' => 'IA4S-001',
            'name' => 'Entidad Demo',
        ],
        'responses' => [[
            'datapoint_id' => 'BP-1_01',
            'status' => 'completed',
            'facts' => [
                validFact(['value_kind' => 'monetary', 'value' => '1234.50', 'decimals' => 2, 'unit' => ['measure' => 'iso4217:EUR']]),
                validFact(['value_kind' => 'percent', 'value' => '12.5', 'decimals' => 1, 'unit' => ['measure' => 'pure']]),
            ],
        ]],
    ];

    $response = $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', $payload)
        ->assertOk()
        ->assertJsonPath('data.schema_version', 'v1')
        ->assertJsonPath('data.reporting_entity.identifier', 'IA4S-001')
        ->assertJsonPath('data.responses.BP-1_01.concept.concept_id', 'esrs:BasisForPreparationOfSustainabilityStatement')
        ->assertJsonPath('data.summary.completed_count', 1)
        ->assertJsonPath('data.summary.facts_count', 2);

    expect($response->json('data.responses.BP-1_01.facts.0.value'))->toBe('1234.50');
    expect($response->json('data.responses.BP-1_01.facts.0.concept.concept_id'))->toBe('esrs:BasisForPreparationOfSustainabilityStatement');
});

it('rejects malformed numeric lexical values and missing fact context', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => ['materiality_confirmation' => ['confirmed_topic_ids' => [$this->e2Topic->id]]],
    ]);

    $base = [
        'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'id'],
        'responses' => [[
            'datapoint_id' => 'BP-1_01',
            'status' => 'completed',
            'facts' => [validFact(['value_kind' => 'monetary', 'value' => '1,234.50', 'decimals' => 2, 'unit' => ['measure' => 'iso4217:EUR']])],
        ]],
    ];

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', $base)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['responses.0.facts.0.value']);

    unset($base['responses'][0]['facts'][0]['context']);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', $base)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['responses.0.facts.0.context']);
});

it('rejects completed responses without facts and not applicable without evidence and reason', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => ['materiality_confirmation' => ['confirmed_topic_ids' => [$this->e2Topic->id]]],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'id'],
            'responses' => [['datapoint_id' => 'BP-1_01', 'status' => 'completed']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['responses.0.facts']);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'responses' => [['datapoint_id' => 'BP-1_01', 'status' => 'not_applicable']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['responses.0.note', 'responses.0.evidence_reference']);
});

it('ignores user supplied concept ids and exposes v0 compatibility without counting legacy completed as final', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => ['confirmed_topic_ids' => [$this->e2Topic->id]],
            'esrs_datapoint_responses' => [
                'schema_version' => 'v0',
                'updated_at' => now()->toJSON(),
                'responses' => [
                    'BP-1_01' => [
                        'datapoint_id' => 'BP-1_01',
                        'status' => 'completed',
                        'value' => 'Legacy text answer.',
                        'evidence_reference' => 'Legacy evidence',
                        'updated_at' => now()->toJSON(),
                    ],
                ],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses')
        ->assertOk()
        ->assertJsonPath('data.schema_version', 'v1')
        ->assertJsonPath('data.stored_schema_version', 'v0')
        ->assertJsonPath('data.responses.BP-1_01.legacy_value', 'Legacy text answer.')
        ->assertJsonPath('data.responses.BP-1_01.facts', [])
        ->assertJsonPath('data.responses.BP-1_01.fact_readiness.state', 'invalid_completed')
        ->assertJsonPath('data.summary.completed_count', 0)
        ->assertJsonPath('data.summary.invalid_completed_count', 1);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'id'],
            'responses' => [[
                'datapoint_id' => 'BP-1_01',
                'status' => 'completed',
                'facts' => [[...validFact(), 'concept' => ['concept_id' => 'esrs:Injected', 'taggable_state' => 'mapped']]],
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.responses.BP-1_01.facts.0.concept.concept_id', 'esrs:BasisForPreparationOfSustainabilityStatement');

    $stored = $characterization->fresh()->form_data['esrs_datapoint_responses'];
    expect($stored['schema_version'])->toBe('v1');
    expect($stored['responses']['BP-1_01'])->not->toHaveKey('concept');
    expect($stored['responses']['BP-1_01']['facts'][0])->not->toHaveKey('concept');
});

it('exports one response csv row per fact with appended v1 content and keeps blank fact rows', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => ['materiality_confirmation' => ['confirmed_topic_ids' => [$this->e2Topic->id]]],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'entity-1'],
            'responses' => [
                ['datapoint_id' => 'BP-1_01', 'status' => 'completed', 'facts' => [
                    validFact(['value_kind' => 'monetary', 'value' => '10.00', 'decimals' => 2, 'unit' => ['measure' => 'iso4217:EUR']]),
                    validFact(['value_kind' => 'percent', 'value' => '5', 'decimals' => 0, 'unit' => ['measure' => 'pure']]),
                ]],
                ['datapoint_id' => 'IRO-1_01', 'status' => 'draft', 'note' => 'No facts yet.'],
            ],
        ])
        ->assertOk();

    $rows = csvRows($this->actingAs($this->user)
        ->get('/api/esrs-datapoints/responses/export.csv')
        ->assertOk()
        ->getContent());
    $header = array_shift($rows);
    $byDatapoint = collect($rows)->groupBy(array_search('datapoint_id', $header, true));

    expect($header)->toContain('schema_version', 'reporting_entity_identifier', 'fact_id', 'fact_value_kind', 'fact_lexical_value', 'fact_unit', 'fact_dimensions', 'fact_evidence_reference');
    expect($byDatapoint->get('BP-1_01'))->toHaveCount(2);
    expect($byDatapoint->get('BP-1_01')->pluck(array_search('fact_lexical_value', $header, true))->all())->toBe(['10.00', '5']);
    expect($byDatapoint->get('IRO-1_01')->first()[array_search('fact_id', $header, true)])->toBe('');
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
            'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'entity-1'],
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
            'reporting_entity' => validReportingEntity(),
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
                    'facts' => [validFact(['value' => 'Pollution IRO screening completed.'])],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.summary.response_count', 2)
        ->assertJsonPath('data.responses.BP-1_01.value', 'Prepared on a consolidated basis.');

    $replacement = $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'entity-1'],
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

it('preserves orphaned datapoint responses across saves and reattaches them when the corpus grows', function () {
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
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'reporting_entity' => validReportingEntity(),
            'responses' => [
                [
                    'datapoint_id' => 'BP-1_01',
                    'status' => 'draft',
                    'value' => 'Baseline response.',
                ],
                [
                    'datapoint_id' => 'E2.IRO-1_01',
                    'status' => 'completed',
                    'facts' => [validFact(['value' => 'Pollution IRO screening completed.'])],
                    'evidence_reference' => 'E2 evidence pack',
                    'note' => 'Keep this if E2 leaves scope temporarily.',
                ],
            ],
        ])
        ->assertOk();

    $formData = $characterization->fresh()->form_data;
    $formData['materiality_confirmation']['confirmed_topic_ids'] = [$this->s1Topic->id];
    $characterization->forceFill(['form_data' => $formData])->save();

    $shrunken = $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'entity-1'],
            'responses' => [
                [
                    'datapoint_id' => 'BP-1_01',
                    'status' => 'draft',
                    'value' => 'Updated baseline response.',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.summary.response_count', 1)
        ->assertJsonPath('data.summary.completed_count', 0)
        ->assertJsonPath('data.summary.completion_ratio', 0)
        ->assertJsonPath('data.orphaned.count', 1);

    $orphanedResponse = $shrunken->json('data.orphaned.responses')['E2.IRO-1_01'];

    expect($orphanedResponse)->toMatchArray([
        'evidence_reference' => 'E2 evidence pack',
    ]);
    expect($orphanedResponse['facts'][0]['value'])
        ->toBe('Pollution IRO screening completed.');
    expect($shrunken->json('data.responses'))->not->toHaveKey('E2.IRO-1_01');

    expect($characterization->fresh()->form_data['esrs_datapoint_responses']['responses'])
        ->toHaveKey('E2.IRO-1_01');

    $formData = $characterization->fresh()->form_data;
    $formData['materiality_confirmation']['confirmed_topic_ids'] = [$this->e2Topic->id, $this->s1Topic->id];
    $characterization->forceFill(['form_data' => $formData])->save();

    $regrown = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses')
        ->assertOk()
        ->assertJsonPath('data.orphaned.count', 0)
        ->assertJsonPath('data.orphaned.responses', []);

    expect($regrown->json('data.responses')['E2.IRO-1_01'])->toMatchArray([
        'status' => 'completed',
        'note' => 'Keep this if E2 leaves scope temporarily.',
    ]);

    @unlink($mappingPath);
});

it('round-trips triage marks and persists rows with only triage set', function () {
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

    $response = $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'responses' => [
                [
                    'datapoint_id' => 'BP-1_01',
                    'status' => 'draft',
                    'triage' => 'need_to_find',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.responses.BP-1_01.status', 'draft')
        ->assertJsonPath('data.responses.BP-1_01.triage', 'need_to_find')
        ->assertJsonMissingPath('data.responses.BP-1_01.value');

    expect(array_keys($response->json('data.responses')))->toBe(['BP-1_01']);

    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses')
        ->assertOk()
        ->assertJsonPath('data.responses.BP-1_01.triage', 'need_to_find');
});

it('rejects unsupported datapoint response triage values', function () {
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
                    'triage' => 'maybe_later',
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['responses.0.triage']);
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

it('exposes orphaned response ids without counting them as live responses', function () {
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

    $responseState = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints/responses')
        ->assertOk()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.summary.response_count', 2)
        ->assertJsonPath('data.summary.completed_count', 0)
        ->assertJsonPath('data.summary.invalid_completed_count', 1)
        ->assertJsonPath('data.orphaned.count', 1);

    expect($responseState->json('data.orphaned.responses')['S1.SBM-3_01']['value'])
        ->toBe('Stale S1 response.');
    expect($responseState->json('data.responses'))->not->toHaveKey('S1.SBM-3_01');

    $responseRows = csvRows($this->actingAs($this->user)
        ->get('/api/esrs-datapoints/responses/export.csv')
        ->assertOk()
        ->getContent());

    $responseHeader = array_shift($responseRows);
    $datapointIdColumn = array_search('datapoint_id', $responseHeader, true);

    expect(collect($responseRows)->pluck($datapointIdColumn)->all())
        ->toContain('BP-1_01', 'E2.IRO-1_01')
        ->not->toContain('S1.SBM-3_01');

    $report = $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.datapoint_responses.response_count', 2)
        ->assertJsonPath('data.sections.datapoint_responses.completed_count', 0)
        ->assertJsonPath('data.sections.datapoint_responses.orphaned_response_count', 1);

    expect(collect($report->json('data.limitations'))->firstWhere('key', 'orphaned_datapoint_responses'))
        ->toMatchArray([
            'key' => 'orphaned_datapoint_responses',
            'message' => 'Some stored datapoint responses no longer match the current materiality scope. They are preserved and will reattach if the scope includes them again.',
        ]);

    $draft = $this->actingAs($this->user)
        ->getJson('/api/report/draft')
        ->assertOk()
        ->assertJsonPath('data.datapoints.response_count', 2)
        ->assertJsonPath('data.datapoints.completed_count', 0)
        ->assertJsonPath('data.datapoints.orphaned_response_count', 1);

    expect(collect($draft->json('data.limitations'))->pluck('key')->all())
        ->toContain('orphaned_datapoint_responses');

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
            'reporting_entity' => validReportingEntity(),
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
                    'facts' => [validFact(['value' => 'Pollution IRO screening completed.'])],
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

    expect(array_slice($header, 0, 17))->toBe([
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
        'default_selected',
        'selection_reasons',
        'response_status',
        'response_value',
        'evidence_reference',
        'note',
        'response_updated_at',
    ]);
    expect($header)->toContain('schema_version', 'fact_id', 'fact_value_kind', 'fact_lexical_value', 'fact_evidence_reference');

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
        'default_selected',
        'selection_reasons',
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

it('defaults voluntary and phase-in datapoints to unselected and bases completion on required datapoints', function () {
    $mappingPath = configureApprovedMatterDrMap([
        [
            'ar16_topic_id' => $this->s1Topic->id,
            'esrs_code' => 'S1',
            'disclosure_requirements' => ['S1.SBM-3'],
        ],
    ]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->s1Topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->s1Topic->id],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.phase_in_assessment.application.mode', 'first_report_assumed')
        ->assertJsonPath('data.phase_in_assessment.application.less_than_750_relief_applied', true);

    $datapoints = collect($response->json('data.blocks'))
        ->flatMap(fn (array $block) => $block['datapoints'] ?? [])
        ->keyBy('id');

    // Plain mandatory datapoint stays selected.
    expect($datapoints->get('BP-1_01')['selection'])->toMatchArray([
        'default_selected' => true,
        'reason_codes' => [],
    ]);

    // Voluntary ("may disclose") datapoints start unselected.
    expect($datapoints->get('BP-2_18')['selection']['default_selected'])->toBeFalse();
    expect($datapoints->get('BP-2_18')['selection']['reason_codes'])->toContain('voluntary_may_disclose');

    // <750 employees: size-dependent phase-in relief is applied.
    expect($datapoints->get('BP-2_21')['selection']['default_selected'])->toBeFalse();
    expect($datapoints->get('BP-2_21')['selection']['reason_codes'])->toContain('phase_in_less_than_750');
    expect($datapoints->get('S1.SBM-3_01')['selection']['default_selected'])->toBeFalse();
    expect($datapoints->get('S1.SBM-3_01')['selection']['reason_codes'])->toContain('phase_in_less_than_750');

    // First-report assumption: all-undertakings phase-in relief is applied too.
    expect($datapoints->get('SBM-1_07')['selection']['default_selected'])->toBeFalse();
    expect($datapoints->get('SBM-1_07')['selection']['reason_codes'])->toContain('phase_in_all_undertakings');

    $requiredCount = $response->json('data.summary.required_datapoint_count');
    $totalCount = $response->json('data.summary.total_datapoint_count');

    expect($requiredCount)->toBe($datapoints->filter(fn (array $datapoint) => $datapoint['selection']['default_selected'])->count());
    expect($requiredCount)->toBeGreaterThan(0);
    expect($requiredCount)->toBeLessThan($totalCount);
    expect($response->json('data.summary.default_unselected_datapoint_count'))
        ->toBe($totalCount - $requiredCount);

    // Completion is measured against required datapoints only; optional responses count separately.
    $responseState = $this->actingAs($this->user)
        ->putJson('/api/esrs-datapoints/responses', [
            'reporting_entity' => ['identifier_scheme' => 'scheme', 'identifier' => 'entity-1'],
            'responses' => [
                [
                    'datapoint_id' => 'BP-1_01',
                    'status' => 'completed',
                    'facts' => [validFact(['value' => 'Prepared on a consolidated basis.'])],
                ],
                [
                    'datapoint_id' => 'S1.SBM-3_01',
                    'status' => 'completed',
                    'facts' => [validFact(['value' => 'Optional deferred datapoint answered anyway.'])],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.summary.applicable_datapoint_count', $requiredCount)
        ->assertJsonPath('data.summary.response_count', 2)
        ->assertJsonPath('data.summary.completed_count', 2)
        ->assertJsonPath('data.summary.optional_response_count', 1)
        ->assertJsonPath('data.summary.completion_status', 'in_progress');

    expect($responseState->json('data.summary.completion_ratio'))
        ->toBe(round(1 / $requiredCount, 4));

    @unlink($mappingPath);
});

it('keeps less-than-750 phase-in datapoints required for larger undertakings', function () {
    $mappingPath = configureApprovedMatterDrMap([
        [
            'ar16_topic_id' => $this->s1Topic->id,
            'esrs_code' => 'S1',
            'disclosure_requirements' => ['S1.SBM-3'],
        ],
    ]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->s1Topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '1000_plus',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->s1Topic->id],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.phase_in_assessment.application.mode', 'first_report_assumed')
        ->assertJsonPath('data.phase_in_assessment.application.less_than_750_relief_applied', false);

    $datapoints = collect($response->json('data.blocks'))
        ->flatMap(fn (array $block) => $block['datapoints'] ?? [])
        ->keyBy('id');

    // Size-dependent relief does not apply at 1000+ employees.
    expect($datapoints->get('BP-2_21')['selection']['default_selected'])->toBeTrue();
    expect($datapoints->get('S1.SBM-3_01')['selection']['default_selected'])->toBeTrue();

    // Voluntary and all-undertakings phase-in stay unselected regardless of size.
    expect($datapoints->get('BP-2_18')['selection']['default_selected'])->toBeFalse();
    expect($datapoints->get('SBM-1_07')['selection']['default_selected'])->toBeFalse();
    expect($datapoints->get('SBM-1_07')['selection']['reason_codes'])->toContain('phase_in_all_undertakings');

    @unlink($mappingPath);
});

it('ships an approved canonical AR16 matter to DR mapping covering every selectable topic', function () {
    $canonicalPath = base_path('data/ar16_to_esrs_dr_mapping_esrs2023_v1.json');

    expect(is_file($canonicalPath))->toBeTrue();

    $payload = json_decode(file_get_contents($canonicalPath), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['version'])->toBe('esrs2023-ar16-dr-v1');
    expect($payload['source']['status'])->toBe('approved');
    expect(collect($payload['mappings'])->pluck('ar16_topic_id')->sort()->values()->all())
        ->toBe(EsrsTopic::orderBy('id')->pluck('id')->all());
    expect(collect($payload['mappings'])->where('needs_review', true))->toBeEmpty();

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $canonicalPath]);

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

    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.matter_to_dr_mapping_status', 'loaded')
        ->assertJsonPath('data.generation.coverage_status', 'dr_level')
        ->assertJsonPath('data.matter_mapping.current_filter', 'mapped_disclosure_requirements')
        ->assertJsonPath('data.blocks.topical.applies', true);
});

it('keeps P9 fail-closed when the canonical mapping is downgraded to draft', function () {
    $payload = json_decode(
        file_get_contents(base_path('data/ar16_to_esrs_dr_mapping_esrs2023_v1.json')),
        true,
        flags: JSON_THROW_ON_ERROR
    );

    $payload['source']['status'] = 'draft';

    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    file_put_contents($mappingPath, json_encode($payload, JSON_THROW_ON_ERROR));

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

    $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.matter_to_dr_mapping_status', 'pending')
        ->assertJsonPath('data.generation.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.matter_mapping.current_filter', 'topical_blocked_until_dr_mapping')
        ->assertJsonPath('data.blocks.topical.applies', false)
        ->assertJsonPath('data.blocks.topical.datapoint_count', 0);

    @unlink($mappingPath);
});

it('unlocks P9 at dr_level for every selectable AR16 topic once the canonical mapping is approved', function () {
    $mappingPath = configureApprovedCanonicalMatterDrMap();
    $allTopicIds = EsrsTopic::orderBy('id')->pluck('id')->all();

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => $allTopicIds,
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => $allTopicIds,
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.mapping_granularity', 'disclosure_requirement_level')
        ->assertJsonPath('data.generation.matter_to_dr_mapping_status', 'loaded')
        ->assertJsonPath('data.generation.coverage_status', 'dr_level')
        ->assertJsonPath('data.matter_mapping.coverage_status', 'dr_level')
        ->assertJsonPath('data.blocks.topical.applies', true)
        ->assertJsonPath('data.completion_plan.phases.1.status', 'ready');

    $matterMappingTopics = collect($response->json('data.matter_mapping.material_topics'));

    expect($matterMappingTopics)->toHaveCount(count($allTopicIds));
    expect($matterMappingTopics->pluck('mapping_status')->unique()->values()->all())
        ->toBe(['mapped_to_disclosure_requirements']);

    expect($response->json('data.summary.topical_datapoint_count'))->toBeGreaterThan(0);

    // The IG3 workbook carries one S1-sheet datapoint keyed under DR "S2-1"; the canonical
    // map never lists "S2-1" for S1 matters, so it must stay out even with all topics material.
    $s1DrKeys = collect($response->json('data.blocks.topical.datapoints'))
        ->filter(fn (array $datapoint) => $datapoint['standard'] === 'S1')
        ->pluck('dr')
        ->unique();

    expect($s1DrKeys->all())->not->toContain('S2-1');

    @unlink($mappingPath);
});

it('activates only mapped DR datapoints for a canonical matter, never the full standard', function () {
    $mappingPath = configureApprovedCanonicalMatterDrMap();
    $airPollutionTopic = EsrsTopic::where('esrs_code', 'E2')
        ->where('subtheme_en', 'air pollution')
        ->firstOrFail();

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$airPollutionTopic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$airPollutionTopic->id],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/esrs-datapoints')
        ->assertOk()
        ->assertJsonPath('data.generation.coverage_status', 'dr_level')
        ->assertJsonPath('data.activated_esrs_standards', ['E2']);

    $topicalDrKeys = collect($response->json('data.blocks.topical.disclosure_requirements'))->pluck('key');

    expect($topicalDrKeys->all())->toContain('E2.IRO-1', 'E2-4');
    // E2-5 (substances of concern) belongs to other E2 matters: air pollution must not pull it in.
    expect($topicalDrKeys->all())->not->toContain('E2-5');

    $allE2MaterialityDrKeys = collect(
        json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true)['datapoints']
    )
        ->filter(fn (array $datapoint) => $datapoint['esrs'] === 'E2'
            && $datapoint['inclusion_type'] === 'materiality_based')
        ->map(fn (array $datapoint) => trim((string) $datapoint['dr']))
        ->unique();

    expect($topicalDrKeys->count())->toBeLessThan($allE2MaterialityDrKeys->count());

    @unlink($mappingPath);
});

function configureApprovedCanonicalMatterDrMap(): string
{
    // Temp copy so tests can never mutate or unlink the canonical file.
    $mappingPath = tempnam(sys_get_temp_dir(), 'i4s-dr-map-');

    copy(base_path('data/ar16_to_esrs_dr_mapping_esrs2023_v1.json'), $mappingPath);

    config(['services.esrs_datapoints.matter_dr_mapping_path' => $mappingPath]);

    return $mappingPath;
}

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

function validFact(array $overrides = []): array
{
    return array_replace_recursive([
        'value_kind' => 'narrative',
        'value' => 'Prepared on a consolidated basis.',
        'decimals' => null,
        'unit' => null,
        'context' => [
            'period_type' => 'duration',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'instant_date' => null,
            'dimensions' => [
                ['axis' => 'esrs:ConsolidationAxis', 'member' => 'esrs:ConsolidatedMember'],
            ],
        ],
        'evidence_reference' => 'Evidence pack 2025',
    ], $overrides);
}

function validReportingEntity(array $overrides = []): array
{
    return array_replace([
        'identifier_scheme' => 'scheme',
        'identifier' => 'entity-1',
        'name' => 'Entidad Demo',
    ], $overrides);
}
