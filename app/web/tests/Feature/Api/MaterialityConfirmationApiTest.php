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
});

it('requires authentication for materiality confirmation state', function () {
    $this->getJson('/api/materiality-confirmation')
        ->assertUnauthorized();
});

it('requires authentication for the materiality decision sheet', function () {
    $this->getJson('/api/materiality-confirmation/decision-sheet')
        ->assertUnauthorized();
});

it('returns delta state using the P6 proposal as the default confirmation', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
        'submitted_at' => now()->subDay(),
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', false)
        ->assertJsonPath('data.confirmation_status', 'defaulted_from_p6')
        ->assertJsonPath('data.p6_topic_ids', [$this->e1Topic->id, $this->e2Topic->id])
        ->assertJsonPath('data.confirmed_topic_ids', [$this->e1Topic->id, $this->e2Topic->id])
        ->assertJsonPath('data.delta.added', [])
        ->assertJsonPath('data.delta.removed', [])
        ->assertJsonPath('data.delta.unchanged', [$this->e1Topic->id, $this->e2Topic->id])
        ->assertJsonPath('data.preview.material_topic_count', 2)
        ->assertJsonPath('data.preview.activated_esrs_standards', ['E1', 'E2'])
        ->assertJsonPath('data.preview.mapping_granularity', 'standard_level')
        ->assertJsonPath('data.preview.coverage_status', 'standard_level_partial');

    $preview = $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->json('data.preview');

    expect($preview['datapoint_estimate']['total_datapoint_count'])->toBeGreaterThan(0);
    expect($preview['datapoint_estimate']['topical_datapoint_count'])->toBeGreaterThan(0);
    expect($preview['datapoint_estimate']['label'])->toBe('Orientative standard-level estimate');
    expect($preview['effort_level'])->toBeIn(['low', 'medium', 'high']);
});

it('stores final materiality confirmation and returns added and removed topics', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            'change_reasons' => [
                (string) $this->s1Topic->id => ['stakeholders', 'new_data'],
                (string) $this->e1Topic->id => ['threshold'],
            ],
            'e1_not_material_explanation' => 'Climate impacts are below the documented ADM threshold.',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.confirmation_status', 'confirmed')
        ->assertJsonPath('data.confirmed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.delta.added', [$this->s1Topic->id])
        ->assertJsonPath('data.delta.removed', [$this->e1Topic->id])
        ->assertJsonPath('data.delta.unchanged', [$this->e2Topic->id])
        ->assertJsonPath('data.preview.activated_esrs_standards', ['E2', 'S1'])
        ->assertJsonPath('data.preview.coverage_status', 'standard_level_partial');

    $characterization->refresh();

    expect(data_get($characterization->form_data, 'materiality_confirmation.confirmed_topic_ids'))
        ->toBe([$this->e2Topic->id, $this->s1Topic->id]);
    expect(data_get($characterization->form_data, 'materiality_confirmation.change_reasons.'.$this->s1Topic->id))
        ->toBe(['stakeholders', 'new_data']);
});

it('rejects final confirmation until a completed non-empty P6 proposal exists', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_DRAFT,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['characterization']);

    Characterization::where('user_id', $this->user->id)->delete();

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'confirmed_topic_ids' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['characterization']);
});

it('requires the final confirmation topic list key to be present while allowing an empty array', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmed_topic_ids']);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'confirmed_topic_ids' => [],
            'change_reasons' => [
                (string) $this->e2Topic->id => ['threshold'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.confirmation_status', 'confirmed')
        ->assertJsonPath('data.confirmed_topic_ids', [])
        ->assertJsonPath('data.delta.removed', [$this->e2Topic->id])
        ->assertJsonPath('data.preview.material_topic_count', 0)
        ->assertJsonPath('data.preview.activated_esrs_standards', [])
        ->assertJsonPath('data.preview.datapoint_estimate.topical_datapoint_count', 0)
        ->assertJsonPath('data.preview.datapoint_estimate.minimum_disclosure_requirement_datapoint_count', 0);

    $this->actingAs($this->user)
        ->getJson('/api/report')
        ->assertOk()
        ->assertJsonPath('data.sections.materiality_confirmation.status', 'ready')
        ->assertJsonPath('data.sections.materiality_confirmation.is_confirmed', true)
        ->assertJsonPath('data.sections.materiality_confirmation.confirmation_status', 'confirmed')
        ->assertJsonPath('data.sections.materiality_confirmation.confirmed_topic_count', 0)
        ->assertJsonPath('data.sections.esrs_datapoints.status', 'ready');
});

it('rejects non-canonical or stale reason topic keys', function () {
    $unrelatedTopic = EsrsTopic::whereKeyNot([
        $this->e1Topic->id,
        $this->e2Topic->id,
        $this->s1Topic->id,
    ])->firstOrFail();

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    foreach (['0', '-1', '04', $this->s1Topic->id.'x', (string) $unrelatedTopic->id] as $invalidKey) {
        $this->actingAs($this->user)
            ->putJson('/api/materiality-confirmation', [
                'confirmed_topic_ids' => [$this->s1Topic->id],
                'change_reasons' => [
                    $invalidKey => ['stakeholders'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['change_reasons']);
    }

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'confirmed_topic_ids' => [$this->s1Topic->id],
            'change_reason_notes' => [
                (string) $unrelatedTopic->id => 'This topic is not part of the current P6/P8 set.',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['change_reason_notes']);
});

it('filters stale stored reason keys from the confirmation state', function () {
    $unrelatedTopic = EsrsTopic::whereKeyNot([
        $this->e1Topic->id,
        $this->e2Topic->id,
        $this->s1Topic->id,
    ])->firstOrFail();

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->s1Topic->id],
                'change_reasons' => [
                    (string) $this->e2Topic->id => ['threshold'],
                    (string) $this->s1Topic->id => ['stakeholders'],
                    (string) $unrelatedTopic->id => ['other'],
                    '0' => ['other'],
                ],
                'change_reason_notes' => [
                    (string) $this->s1Topic->id => 'Stakeholder review added this topic.',
                    (string) $unrelatedTopic->id => 'Stale note.',
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->e2Topic->id, ['threshold'])
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['stakeholders'])
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Stakeholder review added this topic.');

    expect($response->json('data.confirmation.change_reasons'))
        ->not->toHaveKey((string) $unrelatedTopic->id)
        ->not->toHaveKey('0');
    expect($response->json('data.confirmation.change_reason_notes'))
        ->not->toHaveKey((string) $unrelatedTopic->id);
});

it('marks an unconfirmed decision sheet as preview-only instead of final ADM evidence', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$this->e2Topic->id],
        'submitted_at' => now()->subDay(),
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'reporting_year' => 2025,
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation/decision-sheet')
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', false)
        ->assertJsonPath('data.confirmation_status', 'defaulted_from_p6')
        ->assertJsonPath('data.confirmed_at', null)
        ->assertJsonPath('data.summary.confirmed_topic_count', 1)
        ->assertJsonPath('data.note', 'No final P8 confirmation has been stored yet. Values are defaulted from the P6 proposal for preview only.');
});

it('returns a P8 decision sheet summary for the separate frontend', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
        'submitted_at' => now()->subDay(),
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'reporting_year' => 2025,
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
                'change_reasons' => [
                    (string) $this->s1Topic->id => ['stakeholders', 'new_data'],
                    (string) $this->e1Topic->id => ['threshold'],
                ],
                'change_reason_notes' => [
                    (string) $this->s1Topic->id => 'Added after stakeholder review.',
                ],
                'e1_not_material_explanation' => 'Climate impacts are below the documented ADM threshold.',
                'confirmed_at' => now()->toJSON(),
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation/decision-sheet')
        ->assertOk()
        ->assertJsonPath('data.type', 'p8_decision_sheet')
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.confirmation_status', 'confirmed')
        ->assertJsonPath('data.company.name', 'Entidad Demo')
        ->assertJsonPath('data.company.reporting_year', 2025)
        ->assertJsonPath('data.summary.p6_topic_count', 2)
        ->assertJsonPath('data.summary.confirmed_topic_count', 2)
        ->assertJsonPath('data.summary.added_count', 1)
        ->assertJsonPath('data.summary.removed_count', 1)
        ->assertJsonPath('data.summary.unchanged_count', 1)
        ->assertJsonPath('data.summary.activated_esrs_standards', ['E2', 'S1'])
        ->assertJsonPath('data.summary.coverage_status', 'standard_level_partial')
        ->assertJsonPath('data.e1_not_material_explanation', 'Climate impacts are below the documented ADM threshold.')
        ->assertJsonPath('data.note', 'These selections reflect the external double materiality assessment. Evidence remains outside the application.');

    expect($response->json('data.summary.p9_total_datapoint_estimate'))->toBeGreaterThan(0);
    expect($response->json('data.summary.effort_level'))->toBeIn(['low', 'medium', 'high']);

    $added = collect($response->json('data.changes.added'));
    $removed = collect($response->json('data.changes.removed'));

    expect($added->pluck('id'))->toContain($this->s1Topic->id);
    expect($added->firstWhere('id', $this->s1Topic->id)['change_reasons'])
        ->toBe(['stakeholders', 'new_data']);
    expect($added->firstWhere('id', $this->s1Topic->id)['change_reason_note'])
        ->toBe('Added after stakeholder review.');
    expect($removed->pluck('id'))->toContain($this->e1Topic->id);
    expect($removed->firstWhere('id', $this->e1Topic->id)['change_reasons'])
        ->toBe(['threshold']);
});

it('requires an explanation when E1 was proposed but removed from final materiality', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['e1_not_material_explanation']);
});

it('requires an explanation when E1 was proposed and the final topic list is empty', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'confirmed_topic_ids' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['e1_not_material_explanation']);
});
