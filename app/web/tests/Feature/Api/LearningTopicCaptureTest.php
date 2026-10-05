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

it('derives complete direct labels and preserves a rejected added topic with its review evidence', function () {
    $characterization = completedLearningCharacterization($this->user, [$this->e2Topic->id]);
    $rejectedAnswer = learningGuidedAnswer([
        'impacto' => 'bajo',
        'financiero' => 'bajo',
        'confianza' => 'alta',
        'suggested_result' => 'no_material',
        'final_result' => 'no_material',
        'revisar' => false,
        'note' => 'Reviewed after it was added.',
    ]);

    $payload = learningTopicPayload(
        confirmedTopicIds: [$this->e2Topic->id],
        reviewedTopicIds: [$this->e2Topic->id, $this->s1Topic->id],
        reviewedUniverse: true,
        mode: 'direct',
        extra: [
            'change_reasons' => [(string) $this->s1Topic->id => ['stakeholders']],
            'change_reason_notes' => [(string) $this->s1Topic->id => 'Added, reviewed, then rejected.'],
            'dimensions' => [(string) $this->s1Topic->id => 'impact'],
            'guided_answers' => [(string) $this->s1Topic->id => $rejectedAnswer],
        ],
    );

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', $payload)
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation.version', 1)
        ->assertJsonPath('data.confirmation.universe_attestation.reviewed_universe', true)
        ->assertJsonPath('data.confirmation.universe_attestation.mode', 'direct')
        ->assertJsonPath('data.learning_topic_labels.'.$this->e2Topic->id, 1)
        ->assertJsonPath('data.learning_topic_labels.'.$this->s1Topic->id, 0)
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['stakeholders'])
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Added, reviewed, then rejected.')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'no_material');

    $stored = Characterization::query()->findOrFail($characterization->id);
    expect(data_get($stored->form_data, 'materiality_confirmation.reviewed_topic_ids'))
        ->toBe([$this->e2Topic->id, $this->s1Topic->id]);
    expect(data_get($stored->form_data, 'materiality_confirmation.learning_topic_labels'))->toBeNull();

    $this->app->forgetInstance(Characterization::class);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.learning_topic_labels.'.$this->e2Topic->id, 1)
        ->assertJsonPath('data.learning_topic_labels.'.$this->s1Topic->id, 0)
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['stakeholders'])
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.note', 'Reviewed after it was added.');
});

it('derives the same closed binary label map from a complete guided review', function () {
    completedLearningCharacterization($this->user, [$this->e2Topic->id]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', learningTopicPayload(
            confirmedTopicIds: [$this->e2Topic->id],
            reviewedTopicIds: [$this->e2Topic->id, $this->s1Topic->id],
            reviewedUniverse: true,
            mode: 'guided',
            extra: [
                'change_reasons' => [(string) $this->s1Topic->id => ['scope_change']],
                'change_reason_notes' => [(string) $this->s1Topic->id => 'Guided review rejected the added topic.'],
                'dimensions' => [(string) $this->s1Topic->id => 'both'],
                'guided_answers' => [
                    (string) $this->s1Topic->id => learningGuidedAnswer([
                        'impacto' => 'bajo',
                        'financiero' => 'bajo',
                        'confianza' => 'alta',
                        'suggested_result' => 'no_material',
                        'final_result' => 'no_material',
                        'revisar' => false,
                    ]),
                    (string) $this->e2Topic->id => learningGuidedAnswer([
                        'suggested_result' => 'material',
                        'final_result' => 'material',
                        'revisar' => false,
                    ]),
                ],
            ],
        ))
        ->assertOk()
        ->assertJsonPath('data.learning_topic_labels.'.$this->e2Topic->id, 1)
        ->assertJsonPath('data.learning_topic_labels.'.$this->s1Topic->id, 0);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.learning_topic_labels.'.$this->s1Topic->id, 0)
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['scope_change'])
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Guided review rejected the added topic.')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'both')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'no_material');
});

it('keeps incomplete direct review labels null and creates no inferred negatives', function () {
    completedLearningCharacterization($this->user, [$this->e2Topic->id, $this->s1Topic->id]);

    $response = $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', learningTopicPayload(
            confirmedTopicIds: [$this->e2Topic->id],
            reviewedTopicIds: [$this->e2Topic->id, $this->s1Topic->id],
            reviewedUniverse: false,
            mode: 'direct',
        ))
        ->assertOk()
        ->assertJsonPath('data.confirmation.universe_attestation.reviewed_universe', false)
        ->assertJsonPath('data.learning_topic_labels', null);

    expect($response->json('data'))->not->toHaveKey('learning_topic_labels.'.$this->s1Topic->id);
});

it('rejects complete guided attestation when answers are missing contradictory observational or unknown', function (array $guidedAnswers, array $confirmedTopicIds, string $errorKey) {
    completedLearningCharacterization($this->user, [$this->e2Topic->id, $this->s1Topic->id]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', learningTopicPayload(
            confirmedTopicIds: $confirmedTopicIds,
            reviewedTopicIds: [$this->e2Topic->id, $this->s1Topic->id],
            reviewedUniverse: true,
            mode: 'guided',
            extra: ['guided_answers' => $guidedAnswers],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);
})->with([
    'missing reviewed answer' => fn () => [[
        (string) $this->e2Topic->id => learningGuidedAnswer([
            'suggested_result' => 'material',
            'final_result' => 'material',
        ]),
    ], [$this->e2Topic->id], 'universe_attestation.reviewed_universe'],
    'contradictory final result' => fn () => [[
        (string) $this->e2Topic->id => learningGuidedAnswer([
            'suggested_result' => 'no_material',
            'final_result' => 'no_material',
        ]),
        (string) $this->s1Topic->id => learningGuidedAnswer([
            'suggested_result' => 'no_material',
            'final_result' => 'no_material',
        ]),
    ], [$this->e2Topic->id], 'guided_answers.'.$this->e2Topic->id.'.final_result'],
    'observation is not terminal' => fn () => [[
        (string) $this->e2Topic->id => learningGuidedAnswer([
            'suggested_result' => 'material',
            'final_result' => 'material',
        ]),
        (string) $this->s1Topic->id => learningGuidedAnswer([
            'suggested_result' => 'en_observacion',
            'final_result' => 'no_material',
            'revisar' => true,
        ]),
    ], [$this->e2Topic->id], 'universe_attestation.reviewed_universe'],
    'unknown signal is not terminal' => fn () => [[
        (string) $this->e2Topic->id => learningGuidedAnswer([
            'suggested_result' => 'material',
            'final_result' => 'material',
        ]),
        (string) $this->s1Topic->id => learningGuidedAnswer([
            'impacto' => 'no_lo_se',
            'suggested_result' => 'material',
            'final_result' => 'no_material',
        ]),
    ], [$this->e2Topic->id], 'universe_attestation.reviewed_universe'],
]);

it('rejects unknown reviewed ids and topic keyed evidence outside the authoritative universe', function () {
    $unrelated = EsrsTopic::whereKeyNot([$this->e1Topic->id, $this->e2Topic->id, $this->s1Topic->id])->firstOrFail();
    completedLearningCharacterization($this->user, [$this->e2Topic->id]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', learningTopicPayload(
            confirmedTopicIds: [$this->e2Topic->id],
            reviewedTopicIds: [$this->e2Topic->id, 999999],
            reviewedUniverse: false,
            mode: 'direct',
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reviewed_topic_ids.1']);

    foreach ([
        ['confirmed_topic_ids' => [$unrelated->id]],
        ['change_reasons' => [(string) $unrelated->id => ['other']]],
        ['change_reason_notes' => [(string) $unrelated->id => 'Outside universe.']],
        ['dimensions' => [(string) $unrelated->id => 'both']],
        ['guided_answers' => [(string) $unrelated->id => learningGuidedAnswer(['final_result' => 'no_material'])]],
    ] as $outsideEvidence) {
        $this->actingAs($this->user)
            ->putJson('/api/materiality-confirmation', learningTopicPayload(
                confirmedTopicIds: $outsideEvidence['confirmed_topic_ids'] ?? [$this->e2Topic->id],
                reviewedTopicIds: [$this->e2Topic->id],
                reviewedUniverse: true,
                mode: 'direct',
                extra: array_diff_key($outsideEvidence, ['confirmed_topic_ids' => true]),
            ))
            ->assertUnprocessable();
    }
});

it('rejects coerced duplicate and non canonical topic identifiers', function (array $overrides, string $errorKey) {
    completedLearningCharacterization($this->user, [$this->e2Topic->id]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            ...learningTopicPayload(
                confirmedTopicIds: [$this->e2Topic->id],
                reviewedTopicIds: [$this->e2Topic->id],
                reviewedUniverse: false,
                mode: 'direct',
            ),
            ...$overrides,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);
})->with([
    'numeric string reviewed id' => fn () => [['reviewed_topic_ids' => [(string) $this->e2Topic->id]], 'reviewed_topic_ids.0'],
    'boolean reviewed id' => fn () => [['reviewed_topic_ids' => [true]], 'reviewed_topic_ids.0'],
    'duplicate reviewed id' => fn () => [['reviewed_topic_ids' => [$this->e2Topic->id, $this->e2Topic->id]], 'reviewed_topic_ids.0'],
    'numeric string confirmed id' => fn () => [['confirmed_topic_ids' => [(string) $this->e2Topic->id]], 'confirmed_topic_ids.0'],
    'non canonical keyed id' => fn () => [['change_reasons' => ['04' => ['other']]], 'change_reasons'],
]);

it('rejects coerced technical attestation values', function (array $attestation, string $errorKey) {
    completedLearningCharacterization($this->user, [$this->e2Topic->id]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            ...learningTopicPayload(
                confirmedTopicIds: [$this->e2Topic->id],
                reviewedTopicIds: [$this->e2Topic->id],
                reviewedUniverse: false,
                mode: 'direct',
            ),
            'universe_attestation' => $attestation,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);
})->with([
    'numeric string version' => [[
        'version' => '1',
        'reviewed_universe' => false,
        'mode' => 'direct',
    ], 'universe_attestation.version'],
    'integer reviewed flag' => [[
        'version' => 1,
        'reviewed_universe' => 1,
        'mode' => 'direct',
    ], 'universe_attestation.reviewed_universe'],
]);

it('rejects strict write-boundary violations with 422 and no P8 mutation', function (string $case) {
    $characterization = completedLearningCharacterization($this->user, [$this->e2Topic->id]);
    $payload = learningTopicPayload(
        confirmedTopicIds: [$this->e2Topic->id],
        reviewedTopicIds: [$this->e2Topic->id],
        reviewedUniverse: false,
        mode: 'direct',
    );

    [$payload, $errorKey] = match ($case) {
        'numeric string revision' => [[...$payload, 'expected_revision' => '0'], 'expected_revision'],
        'unsafe revision' => [[...$payload, 'expected_revision' => 9_007_199_254_740_992], 'expected_revision'],
        'string guided boolean' => [[...$payload, 'guided_answers' => [
            (string) $this->e2Topic->id => learningGuidedAnswer(['revisar' => '1']),
        ]], 'guided_answers.'.$this->e2Topic->id.'.revisar'],
        'integer guided boolean' => [[...$payload, 'guided_answers' => [
            (string) $this->e2Topic->id => learningGuidedAnswer(['revisar' => 1]),
        ]], 'guided_answers.'.$this->e2Topic->id.'.revisar'],
        'unknown guided property' => [[...$payload, 'guided_answers' => [
            (string) $this->e2Topic->id => [
                ...learningGuidedAnswer(),
                'unexpected' => 'discarded today',
            ],
        ]], 'guided_answers.'.$this->e2Topic->id],
        'duplicate reasons' => [[...$payload, 'change_reasons' => [
            (string) $this->e2Topic->id => ['other', 'other'],
        ]], 'change_reasons.'.$this->e2Topic->id.'.0'],
        'nonpositive confirmed id' => [[...$payload, 'confirmed_topic_ids' => [0]], 'confirmed_topic_ids.0'],
    };

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    $stored = Characterization::query()->findOrFail($characterization->id);
    expect(data_get($stored->form_data, 'materiality_confirmation'))->toBeNull();
})->with([
    'numeric string revision',
    'unsafe revision',
    'string guided boolean',
    'integer guided boolean',
    'unknown guided property',
    'duplicate reasons',
    'nonpositive confirmed id',
]);

it('bounds topic collections at the frozen 89-topic catalog and accepts the valid maximum', function () {
    $topicIds = EsrsTopic::query()->orderBy('id')->pluck('id')->all();
    expect($topicIds)->toHaveCount(89);

    completedLearningCharacterization($this->user, [$this->e2Topic->id]);
    $dimensions = collect($topicIds)->mapWithKeys(fn (int $id): array => [(string) $id => 'impact'])->all();

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', learningTopicPayload(
            confirmedTopicIds: [$this->e2Topic->id],
            reviewedTopicIds: $topicIds,
            reviewedUniverse: false,
            mode: 'direct',
            extra: ['dimensions' => $dimensions],
        ))
        ->assertOk()
        ->assertJsonCount(89, 'data.confirmation.reviewed_topic_ids')
        ->assertJsonCount(89, 'data.confirmation.dimensions');
});

it('rejects over-count topic lists and maps with 422 and no P8 mutation', function (string $field) {
    $characterization = completedLearningCharacterization($this->user, [$this->e2Topic->id]);
    $topicIds = EsrsTopic::query()->orderBy('id')->pluck('id')->all();
    $payload = learningTopicPayload(
        confirmedTopicIds: [$this->e2Topic->id],
        reviewedTopicIds: $topicIds,
        reviewedUniverse: false,
        mode: 'direct',
    );

    if ($field === 'reviewed_topic_ids') {
        $payload['reviewed_topic_ids'][] = $topicIds[0];
    } else {
        $payload['dimensions'] = collect(range(1, 90))
            ->mapWithKeys(fn (int $id): array => [(string) $id => 'impact'])
            ->all();
    }

    $response = $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($response->json('errors.'.$field.'.0'))->toContain('89');
    expect(data_get(Characterization::query()->findOrFail($characterization->id)->form_data, 'materiality_confirmation'))
        ->toBeNull();
})->with(['reviewed_topic_ids', 'dimensions']);

it('enforces monotonic reviewed topics across revisions', function () {
    completedLearningCharacterization($this->user, [$this->e2Topic->id]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', learningTopicPayload(
            confirmedTopicIds: [$this->e2Topic->id],
            reviewedTopicIds: [$this->e2Topic->id, $this->s1Topic->id],
            reviewedUniverse: false,
            mode: 'direct',
        ))
        ->assertOk();

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            ...learningTopicPayload(
                confirmedTopicIds: [$this->e2Topic->id],
                reviewedTopicIds: [$this->e2Topic->id],
                reviewedUniverse: false,
                mode: 'direct',
            ),
            'expected_revision' => 1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reviewed_topic_ids']);
});

it('keeps optimistic conflict behavior and does not overwrite learning capture', function () {
    completedLearningCharacterization($this->user, [$this->e2Topic->id, $this->s1Topic->id]);

    $first = learningTopicPayload(
        confirmedTopicIds: [$this->e2Topic->id],
        reviewedTopicIds: [$this->e2Topic->id, $this->s1Topic->id],
        reviewedUniverse: true,
        mode: 'direct',
    );

    $this->actingAs($this->user)->putJson('/api/materiality-confirmation', $first)->assertOk();

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            ...$first,
            'confirmed_topic_ids' => [$this->s1Topic->id],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_materiality_state')
        ->assertJsonPath('data.current_revision', 1);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.revision', 1)
        ->assertJsonPath('data.learning_topic_labels.'.$this->e2Topic->id, 1)
        ->assertJsonPath('data.learning_topic_labels.'.$this->s1Topic->id, 0);
});

it('fails closed for old stored confirmations without a universe attestation', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'revision' => 7,
                'confirmed_topic_ids' => [$this->s1Topic->id],
                'change_reason_notes' => [(string) $this->s1Topic->id => 'Legacy interaction.'],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Legacy interaction.');
});

it('fails closed for malformed stored keyed evidence even with a positive attestation', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'revision' => 8,
                'confirmed_topic_ids' => [$this->e2Topic->id],
                'reviewed_topic_ids' => [$this->e2Topic->id],
                'universe_attestation' => [
                    'version' => 1,
                    'reviewed_universe' => true,
                    'mode' => 'direct',
                ],
                'change_reason_notes' => ['04' => 'Malformed legacy key.'],
                'p6_snapshot' => [
                    'topic_ids' => [$this->e2Topic->id],
                    'captured_at' => now()->toJSON(),
                ],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.universe_attestation.reviewed_universe', true)
        ->assertJsonPath('data.learning_topic_labels', null);
});

it('fails closed when a stored reviewed universe omits a presented P6 topic', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'revision' => 9,
                'confirmed_topic_ids' => [$this->e2Topic->id],
                'reviewed_topic_ids' => [$this->e2Topic->id],
                'universe_attestation' => [
                    'version' => 1,
                    'reviewed_universe' => true,
                    'mode' => 'direct',
                ],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.learning_topic_labels', null);
});

it('treats stored attestation object key order as semantically irrelevant', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'revision' => 10,
                'confirmed_topic_ids' => [$this->e2Topic->id],
                'reviewed_topic_ids' => [$this->e2Topic->id],
                'universe_attestation' => [
                    'mode' => 'direct',
                    'reviewed_universe' => true,
                    'version' => 1,
                ],
                'p6_snapshot' => [
                    'topic_ids' => [$this->e2Topic->id],
                    'captured_at' => now()->toJSON(),
                ],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.universe_attestation.mode', 'direct')
        ->assertJsonPath('data.learning_topic_labels.'.$this->e2Topic->id, 1);
});

it('fails closed without an authoritative stored P6 snapshot while preserving review evidence', function (string $case) {
    $confirmation = [
        'revision' => 11,
        'confirmed_topic_ids' => [$this->e2Topic->id],
        'reviewed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'universe_attestation' => learningStoredAttestation(),
        'change_reasons' => [(string) $this->s1Topic->id => ['stakeholders']],
        'change_reason_notes' => [(string) $this->s1Topic->id => 'Historical rejection remains visible.'],
        'dimensions' => [(string) $this->s1Topic->id => 'impact'],
        'guided_answers' => [(string) $this->s1Topic->id => learningGuidedAnswer([
            'suggested_result' => 'no_material',
            'final_result' => 'no_material',
        ])],
    ];
    if ($case === 'malformed') {
        $confirmation['p6_snapshot'] = ['topic_ids' => [(string) $this->e2Topic->id]];
    }

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => ['materiality_confirmation' => $confirmation],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Historical rejection remains visible.')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact');
})->with(['missing', 'malformed']);

it('does not derive labels from the P6 display fallback when no strict stored confirmation exists', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id],
        'form_data' => [
            'materiality_confirmation' => [
                'revision' => 11,
                'reviewed_topic_ids' => [$this->e2Topic->id],
                'universe_attestation' => [
                    'version' => 1,
                    'reviewed_universe' => true,
                    'mode' => 'direct',
                ],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', false)
        ->assertJsonPath('data.confirmed_topic_ids', [$this->e2Topic->id])
        ->assertJsonPath('data.learning_topic_labels', null);
});

it('fails closed for malformed stored confirmed topic ids', function (string $case) {
    $confirmed = match ($case) {
        'numeric string' => [(string) $this->e2Topic->id],
        'boolean' => [true],
        'duplicate' => [$this->e2Topic->id, $this->e2Topic->id],
    };

    createStoredLearningConfirmation($this->user, [$this->e2Topic->id], [
        'confirmed_topic_ids' => $confirmed,
        'reviewed_topic_ids' => [$this->e2Topic->id],
        'universe_attestation' => learningStoredAttestation(),
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.is_confirmed', false)
        ->assertJsonPath('data.learning_topic_labels', null);
})->with(['numeric string', 'boolean', 'duplicate']);

it('fails closed for every malformed stored learning value schema', function (string $case) {
    $confirmation = [
        'confirmed_topic_ids' => [$this->e2Topic->id],
        'reviewed_topic_ids' => [$this->e2Topic->id],
        'universe_attestation' => learningStoredAttestation(),
        'change_reasons' => [],
        'change_reason_notes' => [],
        'dimensions' => [],
        'guided_answers' => [],
    ];

    match ($case) {
        'reviewed numeric string' => $confirmation['reviewed_topic_ids'] = [(string) $this->e2Topic->id],
        'reviewed boolean' => $confirmation['reviewed_topic_ids'] = [true],
        'reviewed duplicate' => $confirmation['reviewed_topic_ids'] = [$this->e2Topic->id, $this->e2Topic->id],
        'attestation value' => $confirmation['universe_attestation']['reviewed_universe'] = 1,
        'reason value' => $confirmation['change_reasons'] = [(string) $this->e2Topic->id => ['not_a_reason']],
        'reason duplicate' => $confirmation['change_reasons'] = [(string) $this->e2Topic->id => ['other', 'other']],
        'note value' => $confirmation['change_reason_notes'] = [(string) $this->e2Topic->id => 7],
        'blank note' => $confirmation['change_reason_notes'] = [(string) $this->e2Topic->id => ' '],
        'dimension value' => $confirmation['dimensions'] = [(string) $this->e2Topic->id => 'not_a_dimension'],
        'guided incomplete' => $confirmation['guided_answers'] = [(string) $this->e2Topic->id => ['final_result' => 'material']],
        'guided blank note' => $confirmation['guided_answers'] = [(string) $this->e2Topic->id => learningGuidedAnswer(['note' => ''])],
    };

    createStoredLearningConfirmation($this->user, [$this->e2Topic->id], $confirmation);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.learning_topic_labels', null);
})->with([
    'reviewed numeric string',
    'reviewed boolean',
    'reviewed duplicate',
    'attestation value',
    'reason value',
    'reason duplicate',
    'note value',
    'blank note',
    'dimension value',
    'guided incomplete',
    'guided blank note',
]);

it('merges a grown P6 proposal into the stored universe without losing rejected history', function () {
    $characterization = createStoredLearningConfirmation($this->user, [$this->e2Topic->id], [
        'revision' => 12,
        'confirmed_topic_ids' => [$this->e2Topic->id],
        'reviewed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'universe_attestation' => learningStoredAttestation(),
        'change_reasons' => [(string) $this->s1Topic->id => ['stakeholders']],
        'change_reason_notes' => [(string) $this->s1Topic->id => 'Historical rejection.'],
        'dimensions' => [(string) $this->s1Topic->id => 'impact'],
        'guided_answers' => [(string) $this->s1Topic->id => learningGuidedAnswer([
            'suggested_result' => 'no_material',
            'final_result' => 'no_material',
        ])],
    ]);
    $characterization->forceFill(['esrs_topic_ids' => [$this->e2Topic->id, $this->e1Topic->id]])->save();

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id, $this->e1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Historical rejection.')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'no_material');

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            ...learningTopicPayload(
                confirmedTopicIds: [$this->e2Topic->id],
                reviewedTopicIds: [$this->e2Topic->id, $this->e1Topic->id],
                reviewedUniverse: false,
                mode: 'direct',
            ),
            'expected_revision' => 12,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reviewed_topic_ids']);
});

it('fails closed but preserves the reviewed history when the stored P6 snapshot later shrinks', function () {
    $characterization = createStoredLearningConfirmation($this->user, [$this->e2Topic->id, $this->s1Topic->id], [
        'revision' => 13,
        'confirmed_topic_ids' => [$this->e2Topic->id],
        'reviewed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'universe_attestation' => learningStoredAttestation(),
        'change_reason_notes' => [(string) $this->s1Topic->id => 'Retained after P6 changed.'],
        'p6_snapshot' => [
            'topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
            'captured_at' => now()->toJSON(),
        ],
    ]);
    $characterization->forceFill(['esrs_topic_ids' => [$this->e2Topic->id]])->save();

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Retained after P6 changed.')
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null);
});

it('preserves historical reviewed evidence when a legacy PUT omits the T03 pair', function () {
    $characterization = createStoredLearningConfirmation($this->user, [$this->e2Topic->id], [
        'revision' => 14,
        'confirmed_topic_ids' => [$this->e2Topic->id],
        'reviewed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id],
        'universe_attestation' => learningStoredAttestation(),
        'change_reasons' => [(string) $this->s1Topic->id => ['stakeholders']],
        'change_reason_notes' => [(string) $this->s1Topic->id => 'Legacy client cannot represent this rejection.'],
        'dimensions' => [(string) $this->s1Topic->id => 'impact'],
        'guided_answers' => [(string) $this->s1Topic->id => learningGuidedAnswer([
            'suggested_result' => 'no_material',
            'final_result' => 'no_material',
            'note' => 'Historical guided evidence.',
        ])],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 14,
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['stakeholders'])
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Legacy client cannot represent this rejection.')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.note', 'Historical guided evidence.');

    $this->app->forgetInstance(Characterization::class);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['stakeholders'])
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Legacy client cannot represent this rejection.')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'no_material');

    expect(data_get(Characterization::query()->findOrFail($characterization->id)->form_data, 'materiality_confirmation.universe_attestation'))
        ->toBeNull();
});

it('salvages only valid historical IDs and evidence from a malformed legacy universe without granting authority', function () {
    $characterization = createStoredLearningConfirmation($this->user, [$this->e2Topic->id], [
        'revision' => 17,
        'confirmed_topic_ids' => [$this->e2Topic->id],
        'reviewed_topic_ids' => [$this->e2Topic->id, $this->s1Topic->id, $this->s1Topic->id, 'invalid', 999999],
        'universe_attestation' => learningStoredAttestation(),
        'change_reasons' => [(string) $this->s1Topic->id => ['scope_change']],
        'change_reason_notes' => [
            (string) $this->e2Topic->id => 7,
            (string) $this->s1Topic->id => 'Valid historical note.',
            (string) $this->e1Topic->id => 'Valid evidence key outside the malformed list.',
        ],
        'dimensions' => [
            (string) $this->s1Topic->id => 'impact',
            (string) $this->e1Topic->id => 'financial',
        ],
        'guided_answers' => [(string) $this->s1Topic->id => learningGuidedAnswer([
            'suggested_result' => 'no_material',
            'final_result' => 'no_material',
        ])],
    ]);

    $get = $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id, $this->e1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['scope_change'])
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->s1Topic->id, 'Valid historical note.')
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->e1Topic->id, 'Valid evidence key outside the malformed list.')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->e1Topic->id, 'financial')
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'no_material');
    expect($get->json('data.confirmation.change_reason_notes.'.$this->e2Topic->id))->toBeNull();

    $this->actingAs($this->user)
        ->putJson('/api/materiality-confirmation', [
            'expected_revision' => 17,
            'confirmed_topic_ids' => [$this->e2Topic->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [$this->e2Topic->id, $this->s1Topic->id, $this->e1Topic->id])
        ->assertJsonPath('data.confirmation.universe_attestation', null)
        ->assertJsonPath('data.learning_topic_labels', null)
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$this->e1Topic->id, 'Valid evidence key outside the malformed list.')
        ->assertJsonPath('data.confirmation.dimensions.'.$this->s1Topic->id, 'impact');

    $this->app->forgetInstance(Characterization::class);
    $this->actingAs($this->user)
        ->getJson('/api/materiality-confirmation')
        ->assertOk()
        ->assertJsonPath('data.confirmation.change_reasons.'.$this->s1Topic->id, ['scope_change'])
        ->assertJsonPath('data.confirmation.guided_answers.'.$this->s1Topic->id.'.final_result', 'no_material');

    expect(data_get(Characterization::query()->findOrFail($characterization->id)->form_data, 'materiality_confirmation.universe_attestation'))
        ->toBeNull();
});

it('rejects duplicate raw JSON members before validation without mutating P8', function (string $case) {
    $characterization = completedLearningCharacterization($this->user, [$this->e2Topic->id]);
    $topicId = $this->e2Topic->id;
    $reviewed = json_encode([$topicId], JSON_THROW_ON_ERROR);
    $answer = json_encode(learningGuidedAnswer(), JSON_THROW_ON_ERROR);
    $escapedTopicKey = implode('', array_map(
        fn (string $digit): string => sprintf('\\u%04x', ord($digit)),
        str_split((string) $topicId),
    ));

    $json = match ($case) {
        'top level reviewed universe' => '{'
            .'"expected_revision":0,"confirmed_topic_ids":'.$reviewed.','
            .'"reviewed_topic_ids":'.$reviewed.',"reviewed_topic_ids":'.$reviewed.','
            .'"universe_attestation":{"version":1,"reviewed_universe":true,"mode":"direct"}}',
        'nested attestation member' => '{'
            .'"expected_revision":0,"confirmed_topic_ids":'.$reviewed.',"reviewed_topic_ids":'.$reviewed.','
            .'"universe_attestation":{"version":1,"reviewed_universe":true,"mode":"direct","mode":"direct"}}',
        'guided topic key' => '{'
            .'"expected_revision":0,"confirmed_topic_ids":'.$reviewed.',"reviewed_topic_ids":'.$reviewed.','
            .'"universe_attestation":{"version":1,"reviewed_universe":true,"mode":"direct"},'
            .'"guided_answers":{"'.$topicId.'":'.$answer.',"'.$topicId.'":'.$answer.'}}',
        'escape equivalent guided topic key' => '{'
            .'"expected_revision":0,"confirmed_topic_ids":'.$reviewed.',"reviewed_topic_ids":'.$reviewed.','
            .'"universe_attestation":{"version":1,"reviewed_universe":true,"mode":"direct"},'
            .'"guided_answers":{"'.$topicId.'":'.$answer.',"'.$escapedTopicKey.'":'.$answer.'}}',
    };

    $this->actingAs($this->user)
        ->call('PUT', '/api/materiality-confirmation', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $json)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['json']);

    $stored = Characterization::query()->findOrFail($characterization->id);
    expect(data_get($stored->form_data, 'materiality_confirmation'))->toBeNull();
})->with([
    'top level reviewed universe',
    'nested attestation member',
    'guided topic key',
    'escape equivalent guided topic key',
]);

it('accepts valid raw JSON arrays and ignores object-like content inside strings', function () {
    completedLearningCharacterization($this->user, [$this->e2Topic->id]);
    $json = json_encode([
        'expected_revision' => 0,
        'confirmed_topic_ids' => [$this->e2Topic->id],
        'reviewed_topic_ids' => [$this->e2Topic->id],
        'universe_attestation' => learningStoredAttestation(),
        'change_reasons' => [(string) $this->e2Topic->id => ['other']],
        'change_reason_notes' => [
            (string) $this->e2Topic->id => 'Literal {"mode":"direct","mode":"guided"} is only text.',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->actingAs($this->user)
        ->call('PUT', '/api/materiality-confirmation', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $json)
        ->assertOk()
        ->assertJsonPath('data.learning_topic_labels.'.$this->e2Topic->id, 1);
});

it('accepts exactly 1048576 raw bytes and rejects limit plus one before mutation', function () {
    $characterization = completedLearningCharacterization($this->user, [$this->e2Topic->id]);
    $json = json_encode(learningTopicPayload(
        confirmedTopicIds: [$this->e2Topic->id],
        reviewedTopicIds: [$this->e2Topic->id],
        reviewedUniverse: false,
        mode: 'direct',
    ), JSON_THROW_ON_ERROR);
    $atLimit = $json.str_repeat(' ', 1_048_576 - strlen($json));

    $this->actingAs($this->user)
        ->call('PUT', '/api/materiality-confirmation', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $atLimit)
        ->assertOk();

    $this->actingAs($this->user)
        ->call('PUT', '/api/materiality-confirmation', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $atLimit.' ')
        ->assertStatus(413);

    $stored = Characterization::query()->findOrFail($characterization->id);
    expect(data_get($stored->form_data, 'materiality_confirmation.revision'))->toBe(1);
});

it('roundtrips the exact Node recovery payload without losing server or unsaved local rejected evidence', function () {
    $rejected = learningGuidedAnswer([
        'impacto' => 'bajo', 'financiero' => 'bajo', 'confianza' => 'alta',
        'suggested_result' => 'no_material', 'final_result' => 'no_material',
        'note' => 'synthetic rejected evidence',
    ]);
    $characterization = createStoredLearningConfirmation($this->user, [4], [
        'revision' => 2, 'confirmed_topic_ids' => [4], 'reviewed_topic_ids' => [4, 28],
        'universe_attestation' => learningStoredAttestation(),
        'change_reasons' => ['28' => ['stakeholders']],
        'change_reason_notes' => ['28' => 'server rejection note'],
        'dimensions' => ['28' => 'impact'], 'guided_answers' => ['28' => $rejected],
    ]);
    $server = $this->actingAs($this->user)->getJson('/api/materiality-confirmation')
        ->assertOk()->assertJsonPath('data.confirmation.revision', 2)
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [4, 28])
        ->json('data');

    // No test-side payload reconstruction: invoke the same public composition
    // wired by the TSX callback, against this exact GET and synthetic local state.
    $script = <<<'JS'
import { buildMaterialityConfirmationDraft, rebaseMaterialityConfirmationDraft } from './lib/materiality-confirmation-draft.mjs';
import { mergeMaterialityRecoveryState, buildMaterialityConfirmationPayload } from './lib/materiality-confirmation-state.mjs';
const { server, rejected } = JSON.parse(process.argv[1]);
const old = buildMaterialityConfirmationDraft({ baseRevision: 1, p6TopicIds: [4], selectedTopicIds: [4] });
const rebased = rebaseMaterialityConfirmationDraft(old, { baseRevision: server.confirmation.revision, p6TopicIds: server.p6_topic_ids });
const d = mergeMaterialityRecoveryState({
  serverDraft: { ...server.confirmation, p6_topic_ids: server.p6_topic_ids, selected_topic_ids: server.confirmed_topic_ids, change_notes: server.confirmation.change_reason_notes },
  currentDraft: { reviewed_topic_ids: [4, 28, 31], selected_topic_ids: [4],
    change_reasons: { ...server.confirmation.change_reasons, 31: ['scope_change'] },
    change_notes: { ...server.confirmation.change_reason_notes, 31: 'unsaved rejection note' },
    dimensions: { ...server.confirmation.dimensions, 31: 'financial' },
    guided_answers: { ...server.confirmation.guided_answers, 31: { ...rejected, note: 'unsaved guided note' } } },
  recoveredDraft: rebased, recoveredReviewedTopicIds: [4],
});
const payload = { ...buildMaterialityConfirmationPayload({ selectedTopicIds: d.selected_topic_ids, p6TopicIds: d.p6_topic_ids,
  reviewedTopicIds: d.reviewed_topic_ids, reviewedUniverse: d.reviewed_universe, changeReasons: d.change_reasons,
  changeNotes: d.change_notes, dimensions: d.dimensions, guidedAnswers: d.guided_answers, mode: d.mode }), expected_revision: d.base_revision };
process.stdout.write(JSON.stringify(payload));
JS;
    $node = new \Symfony\Component\Process\Process([
        'node', '--input-type=module', '-e', $script,
        json_encode(['server' => $server, 'rejected' => $rejected], JSON_THROW_ON_ERROR),
    ], base_path('../frontend'));
    $node->setTimeout(30);
    $node->mustRun();
    $payload = json_decode($node->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['expected_revision'])->toBe(2);
    expect($payload['confirmed_topic_ids'])->toBe([4]);
    expect($payload['reviewed_topic_ids'])->toBe([4, 28, 31]);
    expect($payload['universe_attestation']['reviewed_universe'])->toBeFalse();

    $this->actingAs($this->user)->putJson('/api/materiality-confirmation', $payload)
        ->assertOk()->assertJsonPath('data.confirmation.revision', 3)
        ->assertJsonPath('data.learning_topic_labels', null);
    $readback = $this->actingAs($this->user)->getJson('/api/materiality-confirmation')
        ->assertOk()->assertJsonPath('data.confirmation.revision', 3)
        ->assertJsonPath('data.confirmed_topic_ids', [4])
        ->assertJsonPath('data.confirmation.reviewed_topic_ids', [4, 28, 31])
        ->assertJsonPath('data.confirmation.universe_attestation.reviewed_universe', false)
        ->assertJsonPath('data.learning_topic_labels', null)->json('data.confirmation');
    foreach (['change_reasons', 'change_reason_notes', 'dimensions', 'guided_answers'] as $field) {
        expect($readback[$field])->toBe($payload[$field]);
        expect(data_get($characterization->fresh()->form_data, 'materiality_confirmation.'.$field))->toBe($payload[$field]);
    }
    expect(data_get($characterization->fresh()->form_data, 'materiality_confirmation.learning_topic_labels'))->toBeNull();
});

function completedLearningCharacterization(User $user, array $p6TopicIds): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => $p6TopicIds,
    ]);
}

function createStoredLearningConfirmation(User $user, array $p6TopicIds, array $confirmation): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => $p6TopicIds,
        'form_data' => [
            'materiality_confirmation' => [
                'revision' => 1,
                'p6_snapshot' => [
                    'topic_ids' => $p6TopicIds,
                    'captured_at' => now()->toJSON(),
                ],
                ...$confirmation,
            ],
        ],
    ]);
}

function learningStoredAttestation(array $overrides = []): array
{
    return [
        'version' => 1,
        'reviewed_universe' => true,
        'mode' => 'direct',
        ...$overrides,
    ];
}

function learningTopicPayload(
    array $confirmedTopicIds,
    array $reviewedTopicIds,
    bool $reviewedUniverse,
    string $mode,
    array $extra = [],
): array {
    return [
        'expected_revision' => 0,
        'confirmed_topic_ids' => $confirmedTopicIds,
        'reviewed_topic_ids' => $reviewedTopicIds,
        'universe_attestation' => [
            'version' => 1,
            'reviewed_universe' => $reviewedUniverse,
            'mode' => $mode,
        ],
        ...$extra,
    ];
}

function learningGuidedAnswer(array $overrides = []): array
{
    return [
        'impacto' => 'medio',
        'financiero' => 'medio',
        'confianza' => 'media',
        'exposicion' => 'normal',
        'suggested_result' => 'material',
        'final_result' => 'material',
        'revisar' => false,
        ...$overrides,
    ];
}
