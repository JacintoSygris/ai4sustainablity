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
});

it('requires authentication for the materiality proposal state', function () {
    $this->getJson('/api/materiality-proposal')
        ->assertUnauthorized();
});

it('returns null when the current user has no characterization proposal', function () {
    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('returns a normalized P6 materiality proposal for the separate frontend', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e2Topic->id, $this->e1Topic->id],
        'result_data' => [
            'status' => 'completed',
            'summary' => 'AI proposed 2 candidate ESRS topics. 1 predicted ESRS key needs manual review.',
            'candidate_topics' => [
                [
                    'ar16_topic_id' => $this->e2Topic->id,
                    'web_esrs' => 'E2',
                    'web_label_en' => 'Pollution',
                    'python_esrs_keys' => ['esrs_e2_pollution'],
                    'score_source' => 'python_predict',
                    'suggested' => true,
                ],
                [
                    'ar16_topic_id' => $this->e1Topic->id,
                    'web_esrs' => 'E1',
                    'web_label_en' => 'Climate change',
                    'python_esrs_keys' => ['esrs_e1_adaptation_to_climate_change'],
                    'score_source' => 'python_predict',
                    'suggested' => true,
                ],
            ],
            'review_required_prediction_keys' => ['esrs_e3_other'],
            'raw_prediction' => [
                'esrs_e1_adaptation_to_climate_change' => 1,
                'esrs_e2_pollution' => 1,
                'esrs_e3_other' => 1,
            ],
        ],
        'submitted_at' => now()->subMinute(),
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.status', Characterization::STATUS_COMPLETED)
        ->assertJsonPath('data.source', 'ai_prediction')
        ->assertJsonPath('data.proposal_topic_ids', [$this->e2Topic->id, $this->e1Topic->id])
        ->assertJsonPath('data.ai.summary', 'AI proposed 2 candidate ESRS topics. 1 predicted ESRS key needs manual review.')
        ->assertJsonPath('data.ai.review_required_prediction_keys', ['esrs_e3_other'])
        ->assertJsonPath('data.ai.raw_prediction_key_count', 3)
        ->assertJsonPath('data.ready_for_confirmation', true);

    expect(collect($response->json('data.proposal_topics'))->pluck('esrs_code')->all())
        ->toBe(['E2', 'E1']);

    expect($response->json('data.ai.candidate_topics'))->toHaveCount(2);
});

it('stores P6 proposal review actions for traceability', function () {
    $characterization = Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
        'submitted_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-proposal', [
            'topic_actions' => [
                (string) $this->e2Topic->id => 'unsure',
            ],
            'action_reasons' => [
                (string) $this->e2Topic->id => ['needs_adm', 'stakeholder_input'],
            ],
            'action_notes' => [
                (string) $this->e2Topic->id => 'Review this topic during the ADM workshop.',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.review.topic_actions.'.$this->e2Topic->id, 'unsure')
        ->assertJsonPath('data.review.action_reasons.'.$this->e2Topic->id, ['needs_adm', 'stakeholder_input'])
        ->assertJsonPath('data.review.action_notes.'.$this->e2Topic->id, 'Review this topic during the ADM workshop.')
        ->assertJsonPath('data.review.status', 'in_progress');

    $characterization->refresh();

    expect(data_get($characterization->form_data, 'materiality_proposal_review.topic_actions.'.$this->e2Topic->id))
        ->toBe('unsure');
    expect(data_get($characterization->form_data, 'materiality_proposal_review.reviewed_at'))
        ->not->toBeNull();
});

it('filters stored P6 proposal review actions to the current proposal topics', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id],
        'form_data' => [
            'materiality_proposal_review' => [
                'topic_actions' => [
                    (string) $this->e1Topic->id => 'accepted',
                    (string) $this->e2Topic->id => 'rejected',
                ],
                'action_reasons' => [
                    (string) $this->e1Topic->id => ['sector_fit'],
                    (string) $this->e2Topic->id => ['not_relevant'],
                ],
                'action_notes' => [
                    (string) $this->e1Topic->id => 'Current proposal action.',
                    (string) $this->e2Topic->id => 'Stale action from an old proposal.',
                ],
                'reviewed_at' => now()->toJSON(),
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.review.status', 'reviewed')
        ->assertJsonPath('data.review.topic_actions.'.$this->e1Topic->id, 'accepted')
        ->assertJsonMissingPath('data.review.topic_actions.'.$this->e2Topic->id)
        ->assertJsonMissingPath('data.review.action_reasons.'.$this->e2Topic->id)
        ->assertJsonMissingPath('data.review.action_notes.'.$this->e2Topic->id);

    expect(array_map('strval', array_keys($response->json('data.review.topic_actions'))))
        ->toBe([(string) $this->e1Topic->id]);
});

it('ignores stored P6 proposal review state older than the current completed proposal', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
        'completed_at' => now(),
        'form_data' => [
            'materiality_proposal_review' => [
                'topic_actions' => [
                    (string) $this->e1Topic->id => 'accepted',
                    (string) $this->e2Topic->id => 'rejected',
                ],
                'action_reasons' => [
                    (string) $this->e2Topic->id => ['not_relevant'],
                ],
                'action_notes' => [
                    (string) $this->e2Topic->id => 'Old proposal review.',
                ],
                'reviewed_at' => now()->subMinute()->toJSON(),
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.review.status', 'not_started')
        ->assertJsonPath('data.review.topic_actions', [])
        ->assertJsonPath('data.review.action_reasons', [])
        ->assertJsonPath('data.review.action_notes', [])
        ->assertJsonPath('data.review.reviewed_at', null);
});

it('ignores legacy P6 proposal review state without a valid review timestamp', function (array $reviewTimestampPayload) {
    $review = [
        'topic_actions' => [
            (string) $this->e1Topic->id => 'accepted',
            (string) $this->e2Topic->id => 'rejected',
        ],
        'action_reasons' => [
            (string) $this->e2Topic->id => ['not_relevant'],
        ],
        'action_notes' => [
            (string) $this->e2Topic->id => 'Legacy proposal review.',
        ],
    ];

    if (array_key_exists('reviewed_at', $reviewTimestampPayload)) {
        $review['reviewed_at'] = $reviewTimestampPayload['reviewed_at'];
    }

    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
        'completed_at' => now(),
        'form_data' => [
            'materiality_proposal_review' => $review,
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.review.status', 'not_started')
        ->assertJsonPath('data.review.topic_actions', [])
        ->assertJsonPath('data.review.action_reasons', [])
        ->assertJsonPath('data.review.action_notes', [])
        ->assertJsonPath('data.review.reviewed_at', null);
})->with([
    'missing reviewed_at' => [[]],
    'blank reviewed_at' => [['reviewed_at' => '']],
    'non-string reviewed_at' => [['reviewed_at' => 123]],
]);

it('rejects P6 proposal review before the proposal is completed', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_DRAFT,
        'esrs_topic_ids' => [$this->e1Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-proposal', [
            'topic_actions' => [
                (string) $this->e1Topic->id => 'accepted',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['characterization']);
});

it('marks P6 proposal review as reviewed when every proposed topic has an action', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id, $this->e2Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-proposal', [
            'topic_actions' => [
                (string) $this->e1Topic->id => 'accepted',
                (string) $this->e2Topic->id => 'rejected',
            ],
            'action_reasons' => [
                (string) $this->e2Topic->id => ['not_relevant'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.review.status', 'reviewed')
        ->assertJsonPath('data.ready_for_confirmation', true);
});

it('rejects malformed P6 proposal review topic keys', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-proposal', [
            'topic_actions' => [
                $this->e1Topic->id.'abc' => 'accepted',
            ],
            'action_reasons' => [
                '0'.$this->e1Topic->id => ['sector_fit'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'topic_actions.'.$this->e1Topic->id.'abc',
            'action_reasons.0'.$this->e1Topic->id,
        ]);
});

it('rejects unsupported P6 proposal review actions and reason chips', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-proposal', [
            'topic_actions' => [
                (string) $this->e1Topic->id => 'approved',
            ],
            'action_reasons' => [
                (string) $this->e1Topic->id => ['because'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'topic_actions.'.$this->e1Topic->id,
            'action_reasons.'.$this->e1Topic->id.'.0',
        ]);
});

it('rejects an empty P6 proposal review action set', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id],
    ]);

    $this->actingAs($this->user)
        ->putJson('/api/materiality-proposal', [
            'topic_actions' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['topic_actions']);
});

it('falls back to legacy AI candidate topics when proposal topic ids are not synchronized', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [],
        'result_data' => [
            'status' => 'completed',
            'candidate_topics' => [
                ['ar16_topic_id' => $this->e2Topic->id, 'suggested' => true],
                ['ar16_topic_id' => $this->e1Topic->id, 'suggested' => true],
            ],
            'raw_prediction' => [],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.source', 'ai_prediction')
        ->assertJsonPath('data.proposal_topic_ids', [$this->e2Topic->id, $this->e1Topic->id])
        ->assertJsonPath('data.ready_for_confirmation', true);

    expect(collect($response->json('data.proposal_topics'))->pluck('esrs_code')->all())
        ->toBe(['E2', 'E1']);
});

it('treats malformed legacy raw prediction values as empty evidence', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id],
        'result_data' => [
            'status' => 'completed',
            'candidate_topics' => [],
            'raw_prediction' => 'legacy-scalar',
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.ai.raw_prediction_key_count', 0);
});

it('treats malformed manual review prediction keys as empty evidence', function () {
    Characterization::factory()->create([
        'user_id' => $this->user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$this->e1Topic->id],
        'result_data' => [
            'status' => 'completed',
            'candidate_topics' => [],
            'review_required_prediction_keys' => 'legacy-scalar',
            'raw_prediction' => [],
        ],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.ai.review_required_prediction_keys', []);
});
