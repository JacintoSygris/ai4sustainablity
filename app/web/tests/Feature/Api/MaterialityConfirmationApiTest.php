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
    $this->withSession(['app_locale' => 'es']);
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
        ->assertJsonPath('data.preview.mapping_granularity', 'disclosure_requirement_mapping_required')
        ->assertJsonPath('data.preview.coverage_status', 'topical_mapping_required');

    $preview = $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->json('data.preview');

    expect($preview['datapoint_estimate']['total_datapoint_count'])->toBeGreaterThan(0);
    expect($preview['datapoint_estimate']['topical_datapoint_count'])->toBe(0);
    expect($preview['datapoint_estimate']['label'])->toBe('Estimación del catálogo de datos según la materialidad');
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
            'expected_revision' => 0,
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
        ->assertJsonPath('data.preview.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.preview.datapoint_estimate.topical_datapoint_count', 0);

    $characterization->refresh();

    expect(data_get($characterization->form_data, 'materiality_confirmation.confirmed_topic_ids'))
        ->toBe([$this->e2Topic->id, $this->s1Topic->id]);
    expect(data_get($characterization->form_data, 'materiality_confirmation.change_reasons.'.$this->s1Topic->id))
        ->toBe(['stakeholders', 'new_data']);
});

it('rejects a stale P8 confirmation without overwriting the newer confirmation', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.revision', 0);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.confirmation.revision', 1);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->s1Topic->id],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_materiality_state')
        ->assertJsonPath('data.current_revision', 1);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmed_topic_ids', [$this->e2Topic->id]);
});

it('accepts a detailed E1 non-material explanation up to 2000 characters', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
            'e1_not_material_explanation' => str_repeat('a', 1500),
        ])
        ->assertOk()
        ->assertJsonPath('data.confirmation.e1_not_material_explanation', str_repeat('a', 1500));

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
            'e1_not_material_explanation' => str_repeat('b', 2100),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['e1_not_material_explanation']);
});

it('persists dimensions and guided answers and validates their topic-keyed maps', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $guidedAnswer = guidedMaterialityAnswer([
        'impacto' => 'alto',
        'financiero' => 'medio',
        'confianza' => 'media',
        'exposicion' => 'fuerte',
        'suggested_result' => 'material',
        'final_result' => 'material',
        'revisar' => false,
        'note' => 'Validated in the guided review.',
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            'dimensions' => [
                (string) $this->e2Topic->id => 'both',
                (string) $this->s1Topic->id => 'impact',
            ],
            'guided_answers' => [
                (string) $this->s1Topic->id => $guidedAnswer,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.confirmation.dimensions.'.$this->e2Topic->id, 'both')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.impacto', 'alto')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.note', 'Validated in the guided review.');

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'material');

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 1,
            'confirmed_topic_ids' => [$this->e2Topic->id],
            'dimensions' => [
                (string) $this->e2Topic->id => 'operational',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dimensions.'.$this->e2Topic->id]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 1,
            'confirmed_topic_ids' => [$this->e2Topic->id],
            'guided_answers' => [
                (string) $this->e2Topic->id => guidedMaterialityAnswer(['impacto' => 'urgent']),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guided_answers.'.$this->e2Topic->id.'.impacto']);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 1,
            'confirmed_topic_ids' => [$this->e2Topic->id],
            'guided_answers' => [
                '999999' => guidedMaterialityAnswer(),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guided_answers']);
});

it('rejects foreign and non-canonical dimension and guided keys without changing user data', function () {
    $foreign = EsrsTopic::whereKeyNot([$this->e2Topic->id, $this->s1Topic->id])->firstOrFail();
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => ['user_note' => 'Texto libre unchanged =1+1'],
    ]);
    $before = $characterization->getRawOriginal('form_data');
    $answer = guidedMaterialityAnswer(['final_result' => 'no_material']);

    // A real, seeded topic outside both selections bypassed the old exists-only check.
    foreach ([(string) $foreign->id, '0'.$this->e2Topic->id, $this->e2Topic->id.'x'] as $key) {
        foreach (['dimensions' => 'both', 'guided_answers' => $answer] as $field => $value) {
            $this->actingAs($this->user)->putJson('/api/materiality-confirmation', [
                'expected_revision' => 0,
                'confirmed_topic_ids' => [$this->s1Topic->id],
                $field => [$key => $value],
            ])->assertUnprocessable()->assertJsonValidationErrors([$field]);

            expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($before);
            $this->getJson('/api/materiality-confirmation')->assertOk()
                ->assertJsonPath('data.decision_basis', 'none')
                ->assertJsonPath('data.confirmation.guided_answers', [])
                ->assertJsonPath('data.confirmation.dimensions', []);
        }
    }
});

it('accepts both removed P6 and newly confirmed P8 map entries', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $this->actingAs($this->user)->putJson('/api/materiality-confirmation', [
        'expected_revision' => 0,
        'confirmed_topic_ids' => [$this->s1Topic->id],
        'dimensions' => [$this->e2Topic->id => 'both', $this->s1Topic->id => 'impact'],
        'guided_answers' => [
            $this->e2Topic->id => guidedMaterialityAnswer(['final_result' => 'no_material', 'note' => 'Texto libre unchanged']),
            $this->s1Topic->id => guidedMaterialityAnswer(),
        ],
    ])->assertOk()
        ->assertJsonPath('data.decision_basis', 'guided_questionnaire')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->e2Topic->id, 'both')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->e2Topic->id.'.note', 'Texto libre unchanged')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'material');
});

it('does not let foreign legacy answers or a stored guided basis create guided evidence on reads', function (?string $storedBasis, bool $hasActa) {
    $foreign = EsrsTopic::whereKeyNot([$this->e2Topic->id])->firstOrFail();
    $confirmation = [
        'confirmed_topic_ids' => [$this->e2Topic->id],
        'dimensions' => [$foreign->id => 'both'],
        'guided_answers' => [$foreign->id => guidedMaterialityAnswer(['suggested_result' => 'en_observacion', 'final_result' => 'no_material'])],
    ];
    if ($storedBasis !== null) {
        $confirmation['decision_basis'] = $storedBasis;
    }
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => $confirmation,
            'user_note' => 'Unchanged',
            'double_materiality_process' => $hasActa ? [
                'acta' => ['completed_on' => '2026-09-29', 'method' => 'Synthetic review', 'participants' => 'Synthetic reviewer'],
            ] : [],
        ],
    ]);
    $before = $characterization->getRawOriginal('form_data');
    $clockBefore = \Illuminate\Support\Facades\DB::table('learning_p8_source_revisions')->get()->toJson();

    $this->actingAs($this->user)->getJson('/api/materiality-confirmation')->assertOk()
        ->assertJsonPath('data.decision_basis', $hasActa ? 'adm_registered' : 'none')
        ->assertJsonPath('data.confirmation.dimensions', [])
        ->assertJsonPath('data.confirmation.guided_answers', []);
    $this->getJson('/api/materiality-confirmation/decision-sheet')->assertOk()
        ->assertJsonPath('data.decision_basis', $hasActa ? 'adm_registered' : 'none')
        ->assertJsonPath('data.observation_resolutions', [])
        ->assertJsonPath('data.changes.unchanged.0.guided', null);
    expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($before);
    expect(\Illuminate\Support\Facades\DB::table('learning_p8_source_revisions')->get()->toJson())->toBe($clockBefore);
})->with([
    'legacy without stored basis' => [null, false],
    'legacy with false guided basis' => ['guided_questionnaire', false],
    'legacy with legitimate ADM record' => ['guided_questionnaire', true],
]);

it('preserves legitimate historical snapshot and current map entries without exposing foreign keys or rewriting history', function () {
    $foreign = EsrsTopic::whereKeyNot([$this->e1Topic->id, $this->e2Topic->id, $this->s1Topic->id])->firstOrFail();
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => ['materiality_confirmation' => [
            'confirmed_topic_ids' => [$this->s1Topic->id],
            'p6_snapshot' => ['topic_ids' => [$this->e1Topic->id], 'captured_at' => now()->subDay()->toJSON()],
            'decision_basis' => 'guided_questionnaire',
            'dimensions' => [$this->e1Topic->id => 'impact', $this->e2Topic->id => 'both', $foreign->id => 'financial'],
            'guided_answers' => [
                $this->e1Topic->id => guidedMaterialityAnswer(['final_result' => 'no_material', 'note' => 'Nota histórica intacta']),
                $this->e2Topic->id => guidedMaterialityAnswer(['final_result' => 'no_material', 'note' => 'Nota actual intacta']),
                $this->s1Topic->id => guidedMaterialityAnswer(),
                $foreign->id => guidedMaterialityAnswer(['suggested_result' => 'en_observacion', 'final_result' => 'no_material']),
            ],
        ]],
    ]);
    $before = $characterization->getRawOriginal('form_data');
    $response = $this->actingAs($this->user)->getJson('/api/materiality-confirmation')->assertOk()
        ->assertJsonPath('data.is_stale', true)
        ->assertJsonPath('data.decision_basis', 'guided_questionnaire')
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.dimensions.'.$this->e1Topic->id, 'impact')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->e2Topic->id, 'both')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->e1Topic->id.'.note', 'Nota histórica intacta')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->e2Topic->id.'.note', 'Nota actual intacta')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'material');
    expect($response->json('data.confirmation.dimensions'))->not->toHaveKey((string) $foreign->id);
    expect($response->json('data.confirmation.guided_answers'))->not->toHaveKey((string) $foreign->id);
    expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($before);

    $this->getJson('/api/materiality-confirmation/decision-sheet')->assertOk()
        ->assertJsonPath('data.observation_resolutions', [
            ['topic_id' => $this->e1Topic->id, 'final_result' => 'no_material', 'revisar' => true],
            ['topic_id' => $this->e2Topic->id, 'final_result' => 'no_material', 'revisar' => true],
            ['topic_id' => $this->s1Topic->id, 'final_result' => 'material', 'revisar' => true],
        ])
        ->assertJsonPath('data.changes.removed.0.guided.note', 'Nota actual intacta');
    expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($before);

    // Historical read allowance must never widen the current write boundary.
    foreach (['change_reasons' => ['other'], 'change_reason_notes' => 'New write to a snapshot-only topic.', 'dimensions' => 'impact', 'guided_answers' => guidedMaterialityAnswer(['final_result' => 'no_material'])] as $field => $value) {
        $this->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->s1Topic->id],
            $field => [$this->e1Topic->id => $value],
        ])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($before);
    }
});

it('does not use malformed frozen snapshot IDs as projection membership or guided evidence', function (string $shape) {
    $topicId = $this->e1Topic->id;
    $snapshotIds = match ($shape) {
        'numeric string' => [(string) $topicId],
        'duplicate' => [$topicId, $topicId],
        'unknown' => [$topicId, 999999],
        'boolean' => [true],
    };
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => ['materiality_confirmation' => [
            'confirmed_topic_ids' => [$this->e2Topic->id],
            'p6_snapshot' => ['topic_ids' => $snapshotIds, 'captured_at' => now()->subDay()->toJSON()],
            'decision_basis' => 'guided_questionnaire',
            'dimensions' => [$topicId => 'both'],
            'guided_answers' => [$topicId => guidedMaterialityAnswer(['final_result' => 'no_material'])],
        ]],
    ]);
    $before = $characterization->getRawOriginal('form_data');
    $this->actingAs($this->user)->getJson('/api/materiality-confirmation')->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id])
        ->assertJsonPath('data.confirmation.dimensions', [])
        ->assertJsonPath('data.confirmation.guided_answers', [])
        ->assertJsonPath('data.decision_basis', 'none')
        ->assertJsonPath('data.learning_topic_labels', null);
    expect($characterization->fresh()->getRawOriginal('form_data'))->toBe($before);
})->with(['numeric string', 'duplicate', 'unknown', 'boolean']);

it('rejects guided verdicts that contradict the final confirmed topic set', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
            'guided_answers' => [
                (string) $this->e2Topic->id => guidedMaterialityAnswer([
                    'suggested_result' => 'no_material',
                    'final_result' => 'no_material',
                ]),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guided_answers.'.$this->e2Topic->id.'.final_result']);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [],
            'guided_answers' => [
                (string) $this->e2Topic->id => guidedMaterialityAnswer(),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['guided_answers.'.$this->e2Topic->id.'.final_result']);
});

it('derives decision basis and captures the P6 snapshot on save', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.decision_basis', 'none')
        ->assertJsonPath('data.p6_snapshot.topic_ids', [$this->e2Topic->id, $this->s1Topic->id]);

    $admUser = User::factory()->create();
    Characterization::factory()->create([
        'user_id' => $admUser->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $this->actingAs($admUser)
        ->putJson('/api/double-materiality-guide/state', [
            'acta' => [
                'completed_on' => '2026-06-01',
                'method' => 'Taller interno con dirección',
                'participants' => 'Gerencia, RRHH, producción',
            ],
        ])
        ->assertOk();

    $this->actingAs($admUser)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.decision_basis', 'adm_registered');

    $guidedUser = User::factory()->create();
    Characterization::factory()->create([
        'user_id' => $guidedUser->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $this->actingAs($guidedUser)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
            'guided_answers' => [
                (string) $this->e2Topic->id => guidedMaterialityAnswer(),
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.decision_basis', 'guided_questionnaire');
});

it('marks a confirmed materiality selection stale when the P6 proposal changes after its snapshot', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.is_stale', false)
        ->assertJsonPath('data.p6_snapshot.topic_ids', [$this->e2Topic->id]);

    $characterization->forceFill([
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
    ])->save();

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.is_stale', true);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 1,
            'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.is_stale', false)
        ->assertJsonPath('data.p6_snapshot.topic_ids', [$this->e2Topic->id, $this->s1Topic->id]);
});

it('fails closed for learning authority when a legacy confirmation has no P6 snapshot', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id],
                'reviewed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
                'universe_attestation' => [
                    'version' => 1,
                    'reviewed_universe' => true,
                    'mode' => 'direct',
                ],
                'change_reason_notes' => [(string) $this->s1Topic->id => 'Legacy evidence remains visible.'],
                'confirmed_at' => now()->toJSON(),
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.is_stale', false)
        ->assertJsonPath('data.p6_snapshot', null)
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Legacy evidence remains visible.');
});

it('previews candidate materiality without mutating the stored confirmation', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson('/api/materiality-confirmation/preview', [
            'candidate_topic_ids' => [$this->s1Topic->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.preview.material_topic_count', 1)
        ->assertJsonPath('data.preview.activated_esrs_standards', ['S1'])
        ->assertJsonPath('data.preview.datapoint_estimate.topical_datapoint_count', 0);

    $characterization->refresh();

    expect(data_get($characterization->form_data, 'materiality_confirmation.confirmed_topic_ids'))
        ->toBe([$this->e2Topic->id]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmed_topic_ids', [$this->e2Topic->id]);
});

it('exposes ADM state and all-normal exposure defaults when prediction evidence is unavailable', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.adm.acta_registered', false)
        ->assertJsonPath('data.adm.acta.completed_on', null)
        ->assertJsonPath('data.exposicion_defaults.'.$this->e2Topic->id, 'normal')
        ->assertJsonPath('data.exposicion_defaults.'.$this->s1Topic->id, 'normal');
});

it('marks confident AI-proposed topics with fuerte exposure defaults', function () {
    config([
        'services.characterization.prediction_mapping_path' => base_path('data/ar16_to_python_esrs_mapping_new_format_732_v1.json'),
    ]);

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'result_data' => [
            'raw_prediction' => [
                'esrs_e2_air_pollution' => 1,
            ],
            'review_required_prediction_keys' => [],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.exposicion_defaults.'.$this->e2Topic->id, 'fuerte')
        ->assertJsonPath('data.exposicion_defaults.'.$this->s1Topic->id, 'normal');
});

it('adds guided decision metadata to the decision sheet', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'A',
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'company_profile' => [
                'company_name' => 'Entidad Demo',
                'reporting_year' => 2025,
            ],
            'double_materiality_process' => [
                'checklist' => [
                    'identified_stakeholders' => false,
                    'assessed_impacts' => false,
                    'assessed_financial_effects' => false,
                    'reached_conclusions' => false,
                ],
                'acta' => [
                    'completed_on' => '2026-06-01',
                    'method' => 'Taller interno con dirección',
                    'participants' => 'Gerencia, RRHH, producción',
                ],
                'updated_at' => now()->toJSON(),
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
                'dimensions' => [
                    (string) $this->e2Topic->id => 'financial',
                    (string) $this->s1Topic->id => 'both',
                ],
                'guided_answers' => [
                    (string) $this->s1Topic->id => guidedMaterialityAnswer([
                        'suggested_result' => 'en_observacion',
                        'final_result' => 'material',
                        'revisar' => true,
                    ]),
                ],
                'decision_basis' => 'guided_questionnaire',
                'p6_snapshot' => [
                    'topic_ids' => [$this->e2Topic->id],
                    'captured_at' => now()->toJSON(),
                ],
                'confirmed_at' => now()->toJSON(),
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation/decision-sheet')
        ->assertOk()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.decision_basis', 'guided_questionnaire')
        ->assertJsonPath('data.adm.acta_registered', true)
        ->assertJsonPath('data.adm.acta.method', 'Taller interno con dirección')
        ->assertJsonPath('data.observation_resolutions.0.topic_id', $this->s1Topic->id)
        ->assertJsonPath('data.observation_resolutions.0.final_result', 'material')
        ->assertJsonPath('data.observation_resolutions.0.revisar', true);

    $added = collect($response->json('data.changes.added'));

    expect($added->firstWhere('id', $this->s1Topic->id)['dimension'])->toBe('both');
    expect($added->firstWhere('id', $this->s1Topic->id)['guided']['suggested_result'])->toBe('en_observacion');
});

it('rejects final confirmation until a completed non-empty P6 proposal exists', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_DRAFT,
        'esrs_topic_ids' => [$this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
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
            'expected_revision' => 0,
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
        ->putJson('/api/materiality-confirmation', ['expected_revision' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmed_topic_ids']);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 0,
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
                'expected_revision' => 0,
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
            'expected_revision' => 0,
            'confirmed_topic_ids' => [$this->s1Topic->id],
            'change_reason_notes' => [
                (string) $unrelatedTopic->id => 'This topic is not part of the current P6/P8 set.',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['change_reason_notes']);
});

it('projects only independently reviewed stored evidence while filtering invalid and foreign legacy keys', function () {
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
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [
            $this->e2Topic->id,
            $this->s1Topic->id,
        ])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->e2Topic->id, ['threshold'])
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['stakeholders'])
        ->assertJsonPath('data.confirmation.change_reasons.'.$unrelatedTopic->id, null)
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Stakeholder review added this topic.')
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$unrelatedTopic->id, null);

    expect($response->json('data.confirmation.change_reasons'))
        ->not->toHaveKey('0');
});

it('marks an unconfirmed decision sheet as preview-only instead of final ADM evidence', function () {
    $this->withSession(['app_locale' => 'es']);
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
        ->assertJsonPath('data.note', 'Todavía no se ha guardado la confirmación final. Los valores de la propuesta de materialidad se muestran únicamente como vista previa.');
});

it('returns a P8 decision sheet summary for the separate frontend', function () {
    $this->withSession(['app_locale' => 'es']);
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
        ->assertJsonPath('data.summary.coverage_status', 'topical_mapping_required')
        ->assertJsonPath('data.e1_not_material_explanation', 'Climate impacts are below the documented ADM threshold.')
        ->assertJsonPath('data.note', 'Estas selecciones reflejan la evaluación externa de doble importancia relativa. Las evidencias permanecen fuera de la aplicación.');

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
            'expected_revision' => 0,
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
            'expected_revision' => 0,
            'confirmed_topic_ids' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['e1_not_material_explanation']);
});

function guidedMaterialityAnswer(array $overrides = []): array
{
    return [
        'impacto' => 'medio',
        'financiero' => 'medio',
        'confianza' => 'media',
        'exposicion' => 'normal',
        'suggested_result' => 'en_observacion',
        'final_result' => 'material',
        'revisar' => true,
        ...$overrides,
    ];
}

it('persists the same scope_change reason for two different removed topics through the materiality confirmation HTTP boundary', function () {
    $p6TopicIds = [$this->e1Topic->id, $this->e2Topic->id, $this->s1Topic->id];
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => $p6TopicIds,
        'form_data' => [
            'company_profile' => ['company_name' => 'Synthetic P8 scope-change entity'],
            'notes' => 'Unrelated synthetic characterization notes must survive P8.',
        ],
    ]);
    $characterization->refresh();
    $originalFormData = $characterization->form_data;
    $originalMetadata = $characterization->getRawOriginal();
    unset($originalMetadata['form_data'], $originalMetadata['updated_at']);

    $initial = $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', false)
        ->assertJsonPath('data.confirmation.revision', 0)
        ->assertJsonPath('data.p6_topic_ids', $p6TopicIds);
    $revision = $initial->json('data.confirmation.revision');
    $note = str_pad('Synthetic scope-change review; this removed topic is outside the fixture scope. ', 299, 'x');
    $changeReasons = [
        (string) $this->e2Topic->id => ['scope_change'],
        (string) $this->s1Topic->id => ['scope_change'],
    ];
    $changeNotes = [
        (string) $this->e2Topic->id => $note,
        (string) $this->s1Topic->id => $note,
    ];

    // Retain E1 and omit the optional learning-universe attestation.
    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => $revision,
            'confirmed_topic_ids' => [$this->e1Topic->id],
            'change_reasons' => $changeReasons,
            'change_reason_notes' => $changeNotes,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.confirmation.revision', $revision + 1)
        ->assertJsonPath('data.confirmation.change_reasons', $changeReasons)
        ->assertJsonPath('data.confirmation.change_reason_notes', $changeNotes);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', true)
        ->assertJsonPath('data.confirmation_status', 'confirmed')
        ->assertJsonPath('data.confirmation.revision', $revision + 1)
        ->assertJsonPath('data.confirmed_topic_ids', [$this->e1Topic->id])
        ->assertJsonPath('data.p6_topic_ids', $p6TopicIds)
        ->assertJsonPath('data.delta.added', [])
        ->assertJsonPath('data.delta.removed', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.delta.unchanged', [$this->e1Topic->id])
        ->assertJsonPath('data.confirmation.change_reasons', $changeReasons)
        ->assertJsonPath('data.confirmation.change_reason_notes', $changeNotes)
        ->assertJsonPath('data.confirmation.universe_attestation', null);

    $characterization->refresh();
    $confirmation = $characterization->form_data['materiality_confirmation'];
    expect($confirmation['revision'])->toBe($revision + 1);
    expect($confirmation['confirmed_topic_ids'])->toBe([$this->e1Topic->id]);
    expect($confirmation['change_reasons'])->toBe($changeReasons);
    expect($confirmation['change_reason_notes'])->toBe($changeNotes);
    expect($confirmation['universe_attestation'])->toBeNull();

    $persistedFormData = $characterization->form_data;
    unset($persistedFormData['materiality_confirmation']);
    expect($persistedFormData)->toBe($originalFormData);
    $persistedMetadata = $characterization->getRawOriginal();
    unset($persistedMetadata['form_data'], $persistedMetadata['updated_at']);
    expect($persistedMetadata)->toEqual($originalMetadata);
});
