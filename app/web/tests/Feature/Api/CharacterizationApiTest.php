<?php

use App\Jobs\SubmitCharacterizationJob;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
});

function validApiCharacterizationPayload(int $topicId, array $overrides = []): array
{
    return array_replace_recursive([
        'step' => 'review',
        'nace_code' => 'A',
        'esrs_topic_ids' => [$topicId],
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad API',
                'headquarters_country' => 'Spain',
                'reporting_year' => 2025,
                'reporting_scope' => 'consolidated_group',
                'num_subsidiaries_countries' => 2,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
                'product_service_type' => 'software_digital_services',
            ],
            'operations' => [
                'regions' => ['eu'],
                'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
            'notes' => 'API ready',
        ],
    ], $overrides);
}

it('requires authentication for characterization api state', function () {
    $this->getJson('/api/characterization')
        ->assertUnauthorized();
});

it('returns progressive disclosure options for the frontend', function () {
    $this->actingAs($this->user)
        ->getJson('/api/characterization/options')
        ->assertOk()
        ->assertJsonPath('data.levels.core.required_fields.0', 'nace_code')
        ->assertJsonPath('data.levels.core.required_fields.1', 'form_data.company_profile.company_name')
        ->assertJsonPath('data.levels.core.required_fields.10', 'form_data.operations.value_chain')
        ->assertJsonPath('data.levels.core.draft_clearable_fields.1', 'esrs_topic_ids')
        ->assertJsonPath('data.levels.core.company_profile.headquarters_countries.Spain', 'España')
        ->assertJsonPath('data.levels.core.company_profile.reporting_scopes.consolidated_group', 'Grupo consolidado')
        ->assertJsonPath('data.levels.core.company_profile.reporting_currencies.EUR', 'EUR')
        ->assertJsonPath('data.levels.core.company_profile.product_service_types.software_digital_services', 'Software/SaaS/servicios digitales')
        ->assertJsonPath('data.levels.core.operations.regions.eu', 'Unión Europea')
        ->assertJsonPath('data.levels.core.operations.value_chain.direct_operations', 'Operaciones directas')
        ->assertJsonPath('data.levels.core.operations.employee_count_ranges.50_249', '50-249')
        ->assertJsonPath('data.levels.core.operations.revenue_ranges.2m_to_10m', 'EUR 2M-10M')
        ->assertJsonPath('data.levels.core.operations.numeric_estimates.employee_count.range_estimates.50_249', 150)
        ->assertJsonPath('data.levels.core.operations.numeric_estimates.revenue.range_estimates.2m_to_10m', 6000000)
        ->assertJsonPath('data.levels.csrd_orientation.enabled_field', 'form_data.csrd_orientation.enabled')
        ->assertJsonPath('data.levels.csrd_orientation.values.not_sure', 'No estoy seguro')
        ->assertJsonPath('data.levels.csrd_orientation.disclaimer', 'Orientación informativa únicamente. La determinación del alcance legal depende de la normativa vigente y de asesoramiento especialista.')
        ->assertJsonPath('data.levels.data_readiness.enabled_field', 'form_data.data_readiness.enabled')
        ->assertJsonPath('data.levels.data_readiness.items.energy', 'Consumo de energía')
        ->assertJsonPath('data.levels.data_readiness.sources.invoices', 'Facturas')
        ->assertJsonPath('data.levels.data_readiness.traceability_levels.high', 'Alta');
});

it('enforces one characterization per user at the database boundary', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
    ]);

    expect(fn () => Characterization::factory()->create([
        'user_id' => $this->user->id,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('exposes the owned characterization through the user relation', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $ownedCharacterization = $this->user->refresh()->characterization;

    expect($ownedCharacterization)->toBeInstanceOf(Characterization::class);
    expect($ownedCharacterization->id)->toBe($characterization->id);
});

it('does not expose another user characterization through the json api', function () {
    $otherUser = User::factory()->create();

    Characterization::factory()->create([
        'user_id' => $otherUser->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'B',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Other User Entity',
                'nace_code' => 'B',
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/characterization')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('stores the current user draft without mutating another user characterization', function () {
    Bus::fake();

    $otherUser = User::factory()->create();
    $otherCharacterization = Characterization::factory()->create([
        'user_id' => $otherUser->id,
        'status' => Characterization::STATUS_DRAFT,
        'nace_code' => 'B',
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Other User Entity',
                'nace_code' => 'B',
            ],
            'notes' => 'Other user notes',
        ],
    ]);

    $topicId = EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
            'form_data' => [
                'company_profile' => [
                    'company_name' => 'Current User Entity',
                ],
                'notes' => 'Current user notes',
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('data.form_data.company_profile.company_name', 'Current User Entity');

    expect(Characterization::where('user_id', $this->user->id)->count())->toBe(1);

    $otherCharacterization->refresh();

    expect($otherCharacterization->form_data['company_profile']['company_name'])->toBe('Other User Entity');
    expect($otherCharacterization->form_data['notes'])->toBe('Other user notes');
});

it('updates the same characterization row across repeated draft saves', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
            'form_data' => [
                'company_profile' => [
                    'company_name' => 'First Draft Entity',
                ],
                'notes' => 'First draft',
            ],
        ]))
        ->assertOk();

    $firstCharacterization = Characterization::where('user_id', $this->user->id)->firstOrFail();

    $this->actingAs($this->user)
        ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
            'form_data' => [
                'company_profile' => [
                    'company_name' => 'Second Draft Entity',
                ],
                'notes' => 'Second draft',
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('data.id', $firstCharacterization->id)
        ->assertJsonPath('data.form_data.company_profile.company_name', 'Second Draft Entity');

    expect(Characterization::where('user_id', $this->user->id)->count())->toBe(1);

    $current = Characterization::where('user_id', $this->user->id)->firstOrFail();

    expect($current->id)->toBe($firstCharacterization->id);
    expect($current->form_data['notes'])->toBe('Second draft');
});

it('recovers when a concurrent first draft insert wins the user unique key race', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;
    $userId = $this->user->id;
    $insertedConcurrentRow = false;

    Characterization::creating(function (Characterization $characterization) use ($userId, &$insertedConcurrentRow) {
        if ($insertedConcurrentRow || (int) $characterization->user_id !== (int) $userId) {
            return;
        }

        $insertedConcurrentRow = true;

        Characterization::withoutEvents(function () use ($userId) {
            Characterization::create([
                'user_id' => $userId,
                'status' => Characterization::STATUS_DRAFT,
                'nace_code' => null,
                'esrs_topic_ids' => [],
                'form_data' => [
                    'company_profile' => [
                        'company_name' => 'Concurrent Placeholder',
                    ],
                ],
            ]);
        });
    });

    try {
        $this->actingAs($this->user)
            ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
                'form_data' => [
                    'company_profile' => [
                        'company_name' => 'Recovered Draft Entity',
                    ],
                    'notes' => 'Recovered after unique race',
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.form_data.company_profile.company_name', 'Recovered Draft Entity');

        expect(Characterization::where('user_id', $this->user->id)->count())->toBe(1);

        $characterization = Characterization::where('user_id', $this->user->id)->firstOrFail();

        expect($characterization->form_data['notes'])->toBe('Recovered after unique race');
    } finally {
        Characterization::flushEventListeners();
    }
});

it('returns the current user characterization as json', function () {
    $lastAttemptedAt = now()->subMinutes(20)->startOfSecond();
    $nextRetryAt = now()->addMinutes(10)->startOfSecond();

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_WAITING,
        'nace_code' => 'A',
        'esrs_topic_ids' => [EsrsTopic::first()->id],
        'retry_count' => 2,
        'next_retry_at' => $nextRetryAt,
        'last_job_attempted_at' => $lastAttemptedAt,
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad API',
                'nace_code' => 'A',
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/characterization')
        ->assertOk()
        ->assertJsonPath('data.status', Characterization::STATUS_WAITING)
        ->assertJsonPath('data.nace_code', 'A')
        ->assertJsonPath('data.retry_count', 2)
        ->assertJsonPath('data.next_retry_at', $nextRetryAt->toJSON())
        ->assertJsonPath('data.last_job_attempted_at', $lastAttemptedAt->toJSON())
        ->assertJsonPath('data.form_data.company_profile.company_name', 'Entidad API');
});

it('stores a draft characterization through the json api', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
            'action' => 'save_draft',
        ]))
        ->assertOk()
        ->assertJsonPath('data.status', Characterization::STATUS_DRAFT)
        ->assertJsonPath('data.form_data.company_profile.company_name', 'Entidad API')
        ->assertJsonPath('data.form_data.operations.employee_count_range', '50_249');

    $characterization = Characterization::where('user_id', $this->user->id)->first();

    expect($characterization)->not->toBeNull();
    expect($characterization->status)->toBe(Characterization::STATUS_DRAFT);
    expect($characterization->esrs_topic_ids)->toBe([$topicId]);

    Bus::assertNotDispatched(SubmitCharacterizationJob::class);
});

it('preserves explicit null values for nullable draft booleans', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
            'action' => 'save_draft',
            'form_data' => [
                'company_profile' => [
                    'stock_listed' => null,
                ],
                'data_readiness' => [
                    'items' => [
                        'energy' => [
                            'verified' => null,
                        ],
                    ],
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('data.form_data.company_profile.stock_listed', null)
        ->assertJsonPath('data.form_data.data_readiness.items.energy.verified', null);

    $this->actingAs($this->user)
        ->getJson('/api/characterization')
        ->assertOk()
        ->assertJsonPath('data.form_data.company_profile.stock_listed', null)
        ->assertJsonPath('data.form_data.data_readiness.items.energy.verified', null);

    Bus::assertNotDispatched(SubmitCharacterizationJob::class);
});

it('allows draft saves to clear selected catalog fields', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
            'action' => 'save_draft',
        ]))
        ->assertOk()
        ->assertJsonPath('data.nace_code', 'A')
        ->assertJsonPath('data.esrs_topic_ids', [$topicId])
        ->assertJsonPath('data.form_data.operations.regions', ['eu'])
        ->assertJsonPath('data.form_data.operations.value_chain', ['direct_operations']);

    $this->actingAs($this->user)
        ->putJson('/api/characterization', [
            'action' => 'save_draft',
            'nace_code' => null,
            'esrs_topic_ids' => [],
            'form_data' => [
                'operations' => [
                    'regions' => [],
                    'value_chain' => [],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.nace_code', null)
        ->assertJsonPath('data.esrs_topic_ids', [])
        ->assertJsonPath('data.form_data.operations.regions', [])
        ->assertJsonPath('data.form_data.operations.value_chain', []);

    Bus::assertNotDispatched(SubmitCharacterizationJob::class);
});

it('stores optional csrd orientation and data readiness through the json api', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
            'form_data' => [
                'csrd_orientation' => [
                    'enabled' => true,
                    'listed_entity' => 'no',
                    'public_interest_entity' => 'not_sure',
                    'reports_as_group' => 'yes',
                ],
                'data_readiness' => [
                    'enabled' => true,
                    'items' => [
                        'energy' => [
                            'available' => 'yes',
                            'year' => 2025,
                            'source' => 'invoices',
                            'verified' => true,
                            'traceability' => 'high',
                        ],
                        'ghg_emissions' => [
                            'available' => 'not_sure',
                        ],
                    ],
                ],
            ],
        ]))
        ->assertOk()
        ->assertJsonPath('data.form_data.csrd_orientation.enabled', true)
        ->assertJsonPath('data.form_data.csrd_orientation.public_interest_entity', 'not_sure')
        ->assertJsonPath('data.form_data.data_readiness.enabled', true)
        ->assertJsonPath('data.form_data.data_readiness.items.energy.source', 'invoices')
        ->assertJsonPath('data.form_data.data_readiness.items.energy.verified', true);

    $characterization = Characterization::where('user_id', $this->user->id)->first();

    expect(data_get($characterization->form_data, 'csrd_orientation.reports_as_group'))->toBe('yes');
    expect(data_get($characterization->form_data, 'data_readiness.items.energy.year'))->toBe(2025);
    expect(data_get($characterization->form_data, 'data_readiness.items.ghg_emissions.available'))->toBe('not_sure');

    Bus::assertNotDispatched(SubmitCharacterizationJob::class);
});

it('rejects unsupported progressive disclosure option keys', function () {
    $topicId = EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->putJson('/api/characterization', validApiCharacterizationPayload($topicId, [
            'form_data' => [
                'csrd_orientation' => [
                    'enabled' => true,
                    'listed_entity' => 'maybe',
                ],
                'data_readiness' => [
                    'enabled' => true,
                    'items' => [
                        'energy' => [
                            'available' => 'yes',
                            'source' => 'spreadsheet',
                            'traceability' => 'perfect',
                        ],
                        'unsupported' => [
                            'available' => 'yes',
                        ],
                    ],
                ],
            ],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'form_data.csrd_orientation.listed_entity',
            'form_data.data_readiness.items.energy.source',
            'form_data.data_readiness.items.energy.traceability',
            'form_data.data_readiness.items.unsupported',
        ]);
});

it('submits a characterization through the json api and dispatches processing', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;

    $this->actingAs($this->user)
        ->postJson('/api/characterization/submit', validApiCharacterizationPayload($topicId))
        ->assertAccepted()
        ->assertJsonPath('data.status', Characterization::STATUS_SUBMITTED)
        ->assertJsonPath('data.submitted_at', fn ($value) => is_string($value));

    $characterization = Characterization::where('user_id', $this->user->id)->first();

    expect($characterization->status)->toBe(Characterization::STATUS_SUBMITTED);
    expect($characterization->submitted_at)->not->toBeNull();

    Bus::assertDispatched(SubmitCharacterizationJob::class, function ($job) use ($characterization) {
        return $job->characterization->is($characterization);
    });
});

it('submits a characterization through the json api without preselected ai topic proposals', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;
    $payload = validApiCharacterizationPayload($topicId);
    unset($payload['esrs_topic_ids']);

    $this->actingAs($this->user)
        ->postJson('/api/characterization/submit', $payload)
        ->assertAccepted()
        ->assertJsonPath('data.status', Characterization::STATUS_SUBMITTED)
        ->assertJsonPath('data.esrs_topic_ids', []);

    $characterization = Characterization::where('user_id', $this->user->id)->first();

    expect($characterization->status)->toBe(Characterization::STATUS_SUBMITTED);
    expect($characterization->esrs_topic_ids)->toBe([]);

    Bus::assertDispatched(SubmitCharacterizationJob::class, function ($job) use ($characterization) {
        return $job->characterization->is($characterization);
    });
});

it('does not resubmit or reset retry metadata for in-flight submissions', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;
    $submittedAt = now()->subHour()->startOfSecond();
    $lastAttemptedAt = now()->subMinutes(20)->startOfSecond();
    $nextRetryAt = now()->addMinutes(10)->startOfSecond();

    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_WAITING,
        'retry_count' => 2,
        'submitted_at' => $submittedAt,
        'last_job_attempted_at' => $lastAttemptedAt,
        'next_retry_at' => $nextRetryAt,
        'last_error' => 'Transient Python failure',
        'nace_code' => 'A',
        'esrs_topic_ids' => [$topicId],
        'form_data' => [
            'company_profile' => [
                'nace_code' => 'A',
                'company_name' => 'Queued Entity',
                'headquarters_country' => 'Spain',
                'reporting_year' => 2025,
                'reporting_scope' => 'consolidated_group',
                'num_subsidiaries_countries' => 2,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
                'product_service_type' => 'software_digital_services',
            ],
            'operations' => [
                'regions' => ['eu'],
                'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
            'notes' => 'Original queued data',
        ],
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/characterization/submit', validApiCharacterizationPayload($topicId, [
            'form_data' => [
                'notes' => 'Attempted duplicate submit',
            ],
        ]))
        ->assertAccepted()
        ->assertJsonPath('data.status', Characterization::STATUS_WAITING)
        ->assertJsonPath('data.retry_count', 2)
        ->assertJsonPath('data.next_retry_at', $nextRetryAt->toJSON())
        ->assertJsonPath('data.last_job_attempted_at', $lastAttemptedAt->toJSON());

    $characterization->refresh();

    expect($characterization->status)->toBe(Characterization::STATUS_WAITING);
    expect($characterization->retry_count)->toBe(2);
    expect($characterization->submitted_at->equalTo($submittedAt))->toBeTrue();
    expect($characterization->last_job_attempted_at->equalTo($lastAttemptedAt))->toBeTrue();
    expect($characterization->next_retry_at->equalTo($nextRetryAt))->toBeTrue();
    expect($characterization->last_error)->toBe('Transient Python failure');
    expect($characterization->form_data['notes'])->toBe('Original queued data');

    Bus::assertNotDispatched(SubmitCharacterizationJob::class);
});

it('resets retry metadata when a completed characterization is genuinely resubmitted', function () {
    Bus::fake();

    $topicId = EsrsTopic::first()->id;
    $previousSubmittedAt = now()->subDays(2);

    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'retry_count' => 4,
        'submitted_at' => $previousSubmittedAt,
        'completed_at' => now()->subDay(),
        'last_job_attempted_at' => now()->subDay(),
        'next_retry_at' => now()->subHour(),
        'last_error' => 'Stale failure',
        'result_data' => ['stale' => true],
        'nace_code' => 'A',
        'esrs_topic_ids' => [$topicId],
        'form_data' => [
            'company_profile' => [
                'nace_code' => 'A',
                'company_name' => 'Completed Entity',
                'headquarters_country' => 'Spain',
                'reporting_year' => 2025,
                'reporting_scope' => 'consolidated_group',
                'num_subsidiaries_countries' => 2,
                'stock_listed' => false,
                'reporting_currency' => 'EUR',
                'product_service_type' => 'software_digital_services',
            ],
            'operations' => [
                'regions' => ['eu'],
                'value_chain' => ['direct_operations'],
                'employee_count_range' => '50_249',
                'revenue_range' => '2m_to_10m',
            ],
            'materiality_proposal_review' => [
                'topic_actions' => [
                    (string) $topicId => 'accepted',
                ],
                'action_reasons' => [
                    (string) $topicId => ['sector_fit'],
                ],
                'action_notes' => [
                    (string) $topicId => 'Previous proposal review.',
                ],
                'reviewed_at' => now()->subDay()->toJSON(),
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->postJson('/api/characterization/submit', validApiCharacterizationPayload($topicId))
        ->assertAccepted()
        ->assertJsonPath('data.status', Characterization::STATUS_SUBMITTED)
        ->assertJsonPath('data.retry_count', 0)
        ->assertJsonPath('data.next_retry_at', null)
        ->assertJsonPath('data.last_job_attempted_at', null);

    $characterization->refresh();

    expect($characterization->status)->toBe(Characterization::STATUS_SUBMITTED);
    expect($characterization->retry_count)->toBe(0);
    expect($characterization->submitted_at->greaterThan($previousSubmittedAt))->toBeTrue();
    expect($characterization->next_retry_at)->toBeNull();
    expect($characterization->last_error)->toBeNull();
    expect($characterization->last_job_attempted_at)->toBeNull();
    expect($characterization->completed_at)->toBeNull();
    expect($characterization->result_data)->toBeNull();
    expect(data_get($characterization->form_data, 'materiality_proposal_review'))->toBeNull();

    Bus::assertDispatched(SubmitCharacterizationJob::class);
});
