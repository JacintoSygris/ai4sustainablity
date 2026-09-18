<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportAuditEvent;
use App\Models\ReportingFact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->e2Topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('requires authentication for reporting facts', function () {
    $this->getJson('/api/report/facts')->assertUnauthorized();

    $this->putJson('/api/report/facts', ['facts' => []])->assertUnauthorized();
});

it('returns null when the current user has no characterization', function () {
    $this->actingAs($this->user)
        ->getJson('/api/report/facts')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('lists persisted facts and legacy projection separately without materializing legacy facts', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'completed',
            'value' => 'Prepared on a consolidated basis.',
        ],
    ]);

    ReportingFact::create([
        'characterization_id' => $characterization->id,
        'fact_id' => 'manual-fact-id',
        'schema_version' => 'reporting_fact_v1',
        'profile_id' => 'esrs-2023-preparatory-v1',
        'datapoint_id' => 'BP-1_02',
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Persisted fact.'],
        'language' => 'en',
        'nil' => false,
        'evidence_refs' => [['type' => 'note', 'value' => 'Manual evidence.']],
        'dimensions' => [],
        'provenance' => 'api',
        'approval_status' => 'review_required',
        'blocking_reasons' => [],
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/report/facts')
        ->assertOk()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.persisted_facts.0.datapoint_id', 'BP-1_02')
        ->assertJsonPath('data.legacy_projection.0.datapoint_id', 'BP-1_01')
        ->assertJsonPath('data.legacy_projection.0.origin', 'legacy_projection')
        ->assertJsonPath('data.legacy_projection_count', 1);

    expect(ReportingFact::where('characterization_id', $characterization->id)->count())->toBe(1);
});

it('lists persisted facts with private id and canonical fact id only for the current user', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic);
    $otherCharacterization = reportingFactApiCharacterization($this->otherUser, $this->e2Topic);

    $ownFact = ReportingFact::create(reportingFactApiStoredFact($characterization, [
        'fact_id' => 'rf_current_user',
        'datapoint_id' => 'BP-1_01',
    ]));
    $otherFact = ReportingFact::create(reportingFactApiStoredFact($otherCharacterization, [
        'fact_id' => 'rf_other_user',
        'datapoint_id' => 'BP-1_02',
    ]));

    $response = $this->actingAs($this->user)
        ->getJson('/api/report/facts')
        ->assertOk()
        ->assertJsonPath('data.persisted_facts.0.id', $ownFact->id)
        ->assertJsonPath('data.persisted_facts.0.fact_id', 'rf_current_user');

    $persistedFacts = collect($response->json('data.persisted_facts'));

    expect($persistedFacts->pluck('id')->all())->toBe([$ownFact->id])
        ->and($persistedFacts->pluck('id')->all())->not->toContain($otherFact->id)
        ->and($persistedFacts->pluck('fact_id')->all())->not->toContain('rf_other_user');
});

it('rejects a numeric fact without unit and decimals and does not modify legacy form data', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'completed',
            'value' => 'Legacy response must survive unchanged.',
        ],
    ]);
    $legacyBefore = $characterization->form_data;

    $this->actingAs($this->user)
        ->putJson('/api/report/facts', [
            'facts' => [
                reportingFactApiPayload([
                    'datapoint_id' => 'BP-1_01',
                    'value_type' => 'number',
                    'value' => ['number' => 42],
                    'unit' => null,
                    'decimals' => null,
                ]),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'reporting_fact_invalid');

    expect($characterization->fresh()->form_data)->toBe($legacyBefore)
        ->and(ReportingFact::where('characterization_id', $characterization->id)->count())->toBe(0);
});

it('persists a valid text fact and leaves legacy form data unchanged', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic, [
        'BP-1_01' => [
            'datapoint_id' => 'BP-1_01',
            'status' => 'draft',
            'value' => 'Legacy draft.',
        ],
    ]);
    $legacyBefore = $characterization->form_data;

    $this->actingAs($this->user)
        ->putJson('/api/report/facts', [
            'facts' => [
                reportingFactApiPayload([
                    'datapoint_id' => 'BP-1_01',
                    'value_type' => 'text',
                    'value' => ['text' => 'Reviewed narrative.'],
                    'language' => 'en',
                    'evidence_refs' => [['type' => 'note', 'value' => 'Reviewed by finance.']],
                ]),
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.persisted_facts.0.datapoint_id', 'BP-1_01')
        ->assertJsonPath('data.persisted_facts.0.approval_status', 'review_required')
        ->assertJsonPath('data.legacy_projection_count', 1);

    expect($characterization->fresh()->form_data)->toBe($legacyBefore)
        ->and(ReportingFact::where('characterization_id', $characterization->id)->count())->toBe(1);
});

it('persists canonical dimensions and includes them in fact identity', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic);

    $firstResponse = $this->actingAs($this->user)
        ->putJson('/api/report/facts', [
            'facts' => [
                reportingFactApiPayload([
                    'datapoint_id' => 'BP-1_01',
                    'value' => ['text' => 'Spanish operations narrative.'],
                    'dimensions' => [
                        ['axis' => ' esrs:CountryAxis ', 'member' => ' esrs:ES '],
                    ],
                ]),
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.persisted_facts.0.dimensions', [
            ['axis' => 'esrs:CountryAxis', 'member' => 'esrs:ES'],
        ]);

    $firstFactId = $firstResponse->json('data.persisted_facts.0.fact_id');

    $secondResponse = $this->actingAs($this->user)
        ->putJson('/api/report/facts', [
            'facts' => [
                reportingFactApiPayload([
                    'datapoint_id' => 'BP-1_01',
                    'value' => ['text' => 'Spanish operations narrative.'],
                    'dimensions' => [
                        ['axis' => 'esrs:CountryAxis', 'member' => 'esrs:FR'],
                    ],
                ]),
            ],
        ])
        ->assertOk();

    $factIds = collect($secondResponse->json('data.persisted_facts'))->pluck('fact_id');

    expect($factIds)->toContain($firstFactId)
        ->and($factIds)->not->toContain(ReportingFact::factId(
            $characterization->id,
            ReportingFact::PROFILE_ID,
            'BP-1_01',
            [],
            'text',
            'en',
        ))
        ->and($factIds->unique()->count())->toBe(2);

    expect(ReportingFact::where('characterization_id', $characterization->id)->count())->toBe(2);
});

it('rejects malformed or duplicate dimensions', function () {
    reportingFactApiCharacterization($this->user, $this->e2Topic);

    foreach ([
        [['member' => 'esrs:ES']],
        [['axis' => 'esrs:CountryAxis']],
        [
            ['axis' => ' esrs:CountryAxis ', 'member' => 'esrs:ES'],
            ['axis' => 'esrs:CountryAxis', 'member' => 'esrs:FR'],
        ],
    ] as $dimensions) {
        $this->actingAs($this->user)
            ->putJson('/api/report/facts', [
                'facts' => [
                    reportingFactApiPayload([
                        'dimensions' => $dimensions,
                    ]),
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'reporting_fact_invalid');
    }
});

it('rejects text facts without language', function () {
    reportingFactApiCharacterization($this->user, $this->e2Topic);

    $this->actingAs($this->user)
        ->putJson('/api/report/facts', [
            'facts' => [
                reportingFactApiPayload([
                    'value_type' => 'text',
                    'value' => ['text' => 'Missing language.'],
                    'language' => null,
                ]),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'reporting_fact_invalid');
});

it('rejects nil facts without a reason', function () {
    reportingFactApiCharacterization($this->user, $this->e2Topic);

    $this->actingAs($this->user)
        ->putJson('/api/report/facts', [
            'facts' => [
                reportingFactApiPayload([
                    'value_type' => 'nil',
                    'value' => null,
                    'nil' => true,
                    'nil_reason' => null,
                ]),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'reporting_fact_invalid');
});

it('rejects not applicable and unavailable facts without evidence', function (string $applicability) {
    reportingFactApiCharacterization($this->user, $this->e2Topic);

    $this->actingAs($this->user)
        ->putJson('/api/report/facts', [
            'facts' => [
                reportingFactApiPayload([
                    'applicability' => $applicability,
                    'value_type' => 'nil',
                    'value' => null,
                    'nil' => true,
                    'nil_reason' => 'No value expected for this fact.',
                    'evidence_refs' => [],
                ]),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'reporting_fact_invalid');
})->with(['not_applicable', 'unavailable']);

it('rejects approved facts through the API', function () {
    reportingFactApiCharacterization($this->user, $this->e2Topic);

    $this->actingAs($this->user)
        ->putJson('/api/report/facts', [
            'facts' => [
                reportingFactApiPayload([
                    'approval_status' => 'approved',
                ]),
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'reporting_fact_invalid');
});

it('does not expose another users characterization or facts', function () {
    reportingFactApiCharacterization($this->otherUser, $this->e2Topic);

    $this->actingAs($this->user)
        ->getJson('/api/report/facts')
        ->assertOk()
        ->assertJsonPath('data', null);

    $this->actingAs($this->user)
        ->putJson('/api/report/facts', ['facts' => []])
        ->assertNotFound();
});

it('reviews a review-required fact and records only a safe audit event payload', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic);
    $fact = ReportingFact::create(reportingFactApiStoredFact($characterization, [
        'fact_id' => 'rf_reviewable',
        'value' => ['text' => 'Sensitive fact value.'],
        'evidence_refs' => [['type' => 'note', 'value' => 'Sensitive evidence.']],
        'approval_status' => 'review_required',
    ]));

    $this->actingAs($this->user)
        ->postJson("/api/report/facts/{$fact->id}/review", [
            'review_declaration' => 'I reviewed this fact against supporting records.',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $fact->id)
        ->assertJsonPath('data.fact_id', 'rf_reviewable')
        ->assertJsonPath('data.approval_status', 'reviewed');

    expect($fact->fresh()->approval_status)->toBe('reviewed');

    $event = ReportAuditEvent::where('event_type', 'fact_reviewed')->firstOrFail();
    $payloadJson = json_encode($event->payload);

    expect($event->user_id)->toBe($this->user->id)
        ->and($event->characterization_id)->toBe($characterization->id)
        ->and($event->payload)->toMatchArray([
            'user_id' => $this->user->id,
            'characterization_id' => $characterization->id,
            'fact_id' => 'rf_reviewable',
            'datapoint_id' => 'BP-1_01',
            'previous_approval_status' => 'review_required',
            'approval_status' => 'reviewed',
        ])
        ->and($payloadJson)->not->toContain('Sensitive fact value')
        ->and($payloadJson)->not->toContain('Sensitive evidence')
        ->and($payloadJson)->not->toContain('I reviewed this fact');
});

it('requires a non-empty fact review declaration', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic);
    $fact = ReportingFact::create(reportingFactApiStoredFact($characterization, [
        'fact_id' => 'rf_blank_declaration',
        'approval_status' => 'review_required',
    ]));

    $this->actingAs($this->user)
        ->postJson("/api/report/facts/{$fact->id}/review", [
            'review_declaration' => '   ',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'report_fact_review_declaration_required');

    expect($fact->fresh()->approval_status)->toBe('review_required')
        ->and(ReportAuditEvent::where('event_type', 'fact_reviewed')->count())->toBe(0);
});

it('rejects non-reviewable pending blocked and blocking-reason facts without transition', function (array $overrides, array $expectedReasons) {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic);
    $fact = ReportingFact::create(reportingFactApiStoredFact($characterization, array_replace([
        'fact_id' => 'rf_not_reviewable_'.str_replace(' ', '_', implode('_', $expectedReasons)),
    ], $overrides)));

    $this->actingAs($this->user)
        ->postJson("/api/report/facts/{$fact->id}/review", [
            'review_declaration' => 'Reviewed.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'report_fact_not_reviewable')
        ->assertJsonPath('reasons', $expectedReasons);

    expect($fact->fresh()->approval_status)->toBe($fact->approval_status)
        ->and(ReportAuditEvent::where('event_type', 'fact_reviewed')->count())->toBe(0);
})->with([
    'pending fact' => [
        ['applicability' => 'pending', 'approval_status' => 'review_required'],
        ['fact_pending'],
    ],
    'blocked fact' => [
        ['applicability' => 'blocked', 'approval_status' => 'review_required'],
        ['fact_blocked'],
    ],
    'blocking reasons' => [
        ['approval_status' => 'review_required', 'blocking_reasons' => ['missing_evidence']],
        ['fact_blocking_reasons_present'],
    ],
    'already reviewed' => [
        ['approval_status' => 'reviewed'],
        ['fact_status_not_review_required'],
    ],
]);

it('returns not found when reviewing another users fact', function () {
    $otherCharacterization = reportingFactApiCharacterization($this->otherUser, $this->e2Topic);
    $otherFact = ReportingFact::create(reportingFactApiStoredFact($otherCharacterization, [
        'fact_id' => 'rf_other_review',
        'approval_status' => 'review_required',
    ]));

    $this->actingAs($this->user)
        ->postJson("/api/report/facts/{$otherFact->id}/review", [
            'review_declaration' => 'Reviewed.',
        ])
        ->assertNotFound();

    expect($otherFact->fresh()->approval_status)->toBe('review_required');
});

it('returns not found when reviewing a fact without a current user characterization', function () {
    $otherCharacterization = reportingFactApiCharacterization($this->otherUser, $this->e2Topic);
    $otherFact = ReportingFact::create(reportingFactApiStoredFact($otherCharacterization, [
        'fact_id' => 'rf_no_characterization_review',
        'approval_status' => 'review_required',
    ]));

    $this->actingAs($this->user)
        ->postJson("/api/report/facts/{$otherFact->id}/review", [
            'review_declaration' => 'Reviewed.',
        ])
        ->assertNotFound();

    expect($otherFact->fresh()->approval_status)->toBe('review_required');
});

it('allows not applicable facts with structured evidence to be reviewed', function () {
    $characterization = reportingFactApiCharacterization($this->user, $this->e2Topic);
    $fact = ReportingFact::create(reportingFactApiStoredFact($characterization, [
        'fact_id' => 'rf_not_applicable_review',
        'applicability' => 'not_applicable',
        'value_type' => 'nil',
        'value' => null,
        'nil' => true,
        'nil_reason' => 'The datapoint is outside the undertaking scope.',
        'evidence_refs' => [['type' => 'policy', 'value' => 'Scope assessment.']],
        'approval_status' => 'review_required',
    ]));

    $this->actingAs($this->user)
        ->postJson("/api/report/facts/{$fact->id}/review", [
            'review_declaration' => 'Reviewed scope evidence.',
        ])
        ->assertOk()
        ->assertJsonPath('data.approval_status', 'reviewed');

    expect($fact->fresh()->approval_status)->toBe('reviewed');
});

function reportingFactApiCharacterization(User $user, EsrsTopic $topic, array $responses = []): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$topic->id],
        'form_data' => [
            'operations' => [
                'employee_count_range' => '50_249',
            ],
            'materiality_confirmation' => [
                'confirmed_topic_ids' => [$topic->id],
            ],
            'esrs_datapoint_responses' => [
                'schema_version' => 'v0',
                'responses' => $responses,
            ],
        ],
    ]);
}

function reportingFactApiPayload(array $overrides = []): array
{
    return array_replace([
        'datapoint_id' => 'BP-1_01',
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Default text fact.'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [],
        'language' => 'en',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Prepared by reporting owner.']],
        'provenance' => 'api',
        'approval_status' => 'review_required',
        'blocking_reasons' => [],
    ], $overrides);
}

function reportingFactApiStoredFact(Characterization $characterization, array $overrides = []): array
{
    return array_replace([
        'characterization_id' => $characterization->id,
        'fact_id' => 'rf_default_review',
        'schema_version' => ReportingFact::SCHEMA_VERSION,
        'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => 'BP-1_01',
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Default review fact.'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [],
        'language' => 'en',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Prepared by reporting owner.']],
        'provenance' => 'api',
        'approval_status' => 'review_required',
        'blocking_reasons' => [],
    ], $overrides);
}
