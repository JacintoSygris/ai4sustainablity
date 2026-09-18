<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportApproval;
use App\Models\ReportAuditEvent;
use App\Models\ReportingFact;
use App\Models\ReportSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
    $this->topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('requires authentication for report snapshots', function () {
    $this->getJson('/api/report/snapshots')->assertUnauthorized();
    $this->postJson('/api/report/snapshot')->assertUnauthorized();
});

it('creates a persisted-facts snapshot without materializing legacy facts', function () {
    $characterization = reportSnapshotApiCharacterization($this->user, $this->topic, [
        'BP-1_legacy' => [
            'datapoint_id' => 'BP-1_legacy',
            'status' => 'completed',
            'value' => 'Legacy value must not become a persisted fact.',
        ],
    ]);
    reportSnapshotApiFact($characterization, ['fact_id' => 'rf_review_required']);

    $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->assertJsonPath('data.characterization_id', $characterization->id)
        ->assertJsonPath('data.stale_state', 'fresh')
        ->assertJsonStructure(['data' => ['id', 'snapshot_hash', 'facts_hash', 'profile_hash']]);

    expect(ReportingFact::where('characterization_id', $characterization->id)->count())->toBe(1)
        ->and(ReportSnapshot::where('characterization_id', $characterization->id)->count())->toBe(1);
});

it('returns an existing snapshot idempotently for identical factual state', function () {
    $characterization = reportSnapshotApiCharacterization($this->user, $this->topic);
    $fact = reportSnapshotApiFact($characterization, [
        'fact_id' => 'rf_idempotent',
        'approval_status' => 'reviewed',
    ]);

    $first = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->json('data');

    $second = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertOk()
        ->json('data');

    expect($second['id'])->toBe($first['id'])
        ->and($second['snapshot_hash'])->toBe($first['snapshot_hash'])
        ->and(ReportSnapshot::where('characterization_id', $characterization->id)->count())->toBe(1)
        ->and(ReportAuditEvent::where('event_type', 'snapshot_created')->count())->toBe(1);

    $fact->update(['value' => ['text' => 'Changed factual value.']]);

    $changed = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->json('data');

    expect($changed['id'])->not->toBe($first['id'])
        ->and($changed['snapshot_hash'])->not->toBe($first['snapshot_hash'])
        ->and(ReportSnapshot::where('characterization_id', $characterization->id)->count())->toBe(2)
        ->and(ReportAuditEvent::where('event_type', 'snapshot_created')->count())->toBe(2);

    $characterization->update(['nace_code' => 'C11']);

    $changedCharacterization = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->json('data');

    expect($changedCharacterization['id'])->not->toBe($changed['id'])
        ->and($changedCharacterization['snapshot_hash'])->not->toBe($changed['snapshot_hash'])
        ->and(ReportSnapshot::where('characterization_id', $characterization->id)->count())->toBe(3)
        ->and(ReportAuditEvent::where('event_type', 'snapshot_created')->count())->toBe(3);
});

it('lists only the current users snapshots', function () {
    $ownCharacterization = reportSnapshotApiCharacterization($this->user, $this->topic);
    $otherCharacterization = reportSnapshotApiCharacterization($this->otherUser, $this->topic);
    reportSnapshotApiFact($ownCharacterization, ['fact_id' => 'rf_own', 'approval_status' => 'reviewed']);
    reportSnapshotApiFact($otherCharacterization, ['fact_id' => 'rf_other', 'approval_status' => 'reviewed']);

    $ownSnapshot = app(\App\Services\Report\ReportSnapshotBuilder::class)->create($ownCharacterization);
    app(\App\Services\Report\ReportSnapshotBuilder::class)->create($otherCharacterization);

    $this->actingAs($this->user)
        ->getJson('/api/report/snapshots')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownSnapshot->id);
});

it('lists snapshot approval state without exposing private approval fields or other users data', function () {
    $ownCharacterization = reportSnapshotApiCharacterization($this->user, $this->topic);
    $otherCharacterization = reportSnapshotApiCharacterization($this->otherUser, $this->topic);
    reportSnapshotApiFact($ownCharacterization, ['fact_id' => 'rf_own_approval_summary', 'approval_status' => 'reviewed']);
    reportSnapshotApiFact($otherCharacterization, ['fact_id' => 'rf_other_approval_summary', 'approval_status' => 'reviewed']);

    $ownSnapshot = app(\App\Services\Report\ReportSnapshotBuilder::class)->create($ownCharacterization);
    $otherSnapshot = app(\App\Services\Report\ReportSnapshotBuilder::class)->create($otherCharacterization);

    $this->actingAs($this->user)
        ->getJson('/api/report/snapshots')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownSnapshot->id)
        ->assertJsonPath('data.0.approval', null)
        ->assertJsonPath('data.0.is_approved', false);

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$ownSnapshot->id}/approve", [
            'single_person_declaration' => 'I prepared, reviewed and approve this snapshot.',
        ])
        ->assertCreated();

    $response = $this->actingAs($this->user)
        ->getJson('/api/report/snapshots')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownSnapshot->id)
        ->assertJsonPath('data.0.is_approved', true)
        ->assertJsonPath('data.0.approval.role_mode', 'single_person_declared')
        ->assertJsonMissingPath('data.0.approval.single_person_declaration')
        ->assertJsonMissingPath('data.0.approval.snapshot_hash')
        ->assertJsonMissingPath('data.0.approval.preparer_user_id')
        ->assertJsonMissingPath('data.0.approval.reviewer_user_id')
        ->assertJsonMissingPath('data.0.approval.approver_user_id')
        ->assertJsonMissing(['id' => $otherSnapshot->id]);

    expect($response->json('data.0.approval.id'))->toBeInt()
        ->and($response->json('data.0.approval.approved_at'))->toBeString();
});

it('enforces reviewability, single-person declaration and staleness before approval', function () {
    $characterization = reportSnapshotApiCharacterization($this->user, $this->topic);
    $fact = reportSnapshotApiFact($characterization, [
        'fact_id' => 'rf_gate',
        'approval_status' => 'review_required',
    ]);

    $snapshotId = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$snapshotId}/approve", [
            'single_person_declaration' => 'I prepared, reviewed and approve this snapshot.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'report_not_reviewable');

    $fact->update(['approval_status' => 'reviewed']);

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$snapshotId}/approve", [
            'single_person_declaration' => '',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'report_stale');

    $freshSnapshotId = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$freshSnapshotId}/approve", [
            'single_person_declaration' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'single_person_declaration_required');

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$freshSnapshotId}/approve", [
            'single_person_declaration' => 'I prepared, reviewed and approve this snapshot.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.role_mode', 'single_person_declared')
        ->assertJsonPath('data.preparer_user_id', $this->user->id)
        ->assertJsonPath('data.reviewer_user_id', $this->user->id)
        ->assertJsonPath('data.approver_user_id', $this->user->id);

    expect(ReportApproval::where('report_snapshot_id', $freshSnapshotId)->count())->toBe(1);

    $characterization->update(['nace_code' => 'C11']);

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$freshSnapshotId}/approve", [
            'single_person_declaration' => 'I prepared, reviewed and approve this snapshot.',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'report_stale');
});

it('marks a snapshot stale when only fact review state changes', function () {
    $characterization = reportSnapshotApiCharacterization($this->user, $this->topic);
    $fact = reportSnapshotApiFact($characterization, [
        'fact_id' => 'rf_review_state_stale',
        'approval_status' => 'review_required',
    ]);

    $snapshotId = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->assertJsonPath('data.stale_state', 'fresh')
        ->json('data.id');

    $fact->update(['approval_status' => 'reviewed']);

    $this->actingAs($this->user)
        ->getJson('/api/report/snapshots')
        ->assertOk()
        ->assertJsonPath('data.0.id', $snapshotId)
        ->assertJsonPath('data.0.stale_state', 'stale')
        ->assertJsonPath('data.0.stale_reasons', ['reporting_facts_changed']);
});

it('does not expose or approve another users snapshot', function () {
    $otherCharacterization = reportSnapshotApiCharacterization($this->otherUser, $this->topic);
    reportSnapshotApiFact($otherCharacterization, ['fact_id' => 'rf_other', 'approval_status' => 'reviewed']);
    $otherSnapshot = app(\App\Services\Report\ReportSnapshotBuilder::class)->create($otherCharacterization);

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$otherSnapshot->id}/approve", [
            'single_person_declaration' => 'I prepared, reviewed and approve this snapshot.',
        ])
        ->assertNotFound();
});

it('records only safe audit event payloads', function () {
    $characterization = reportSnapshotApiCharacterization($this->user, $this->topic);
    $fact = reportSnapshotApiFact($characterization, [
        'fact_id' => 'rf_safe',
        'approval_status' => 'reviewed',
        'value' => ['text' => 'Sensitive value.'],
        'evidence_refs' => [['type' => 'note', 'value' => 'Sensitive evidence.']],
    ]);

    $snapshotId = $this->actingAs($this->user)
        ->postJson('/api/report/snapshot')
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$snapshotId}/approve", [
            'single_person_declaration' => 'I prepared, reviewed and approve this snapshot.',
        ])
        ->assertCreated();

    $fact->update(['value' => ['text' => 'Changed sensitive value.']]);

    $this->actingAs($this->user)
        ->postJson("/api/report/snapshots/{$snapshotId}/approve", [
            'single_person_declaration' => 'I prepared, reviewed and approve this snapshot.',
        ])
        ->assertStatus(409);

    $payload = ReportAuditEvent::query()
        ->orderBy('id')
        ->get()
        ->map(fn (ReportAuditEvent $event) => $event->payload)
        ->toJson();

    expect($payload)->not->toContain('Sensitive value')
        ->and($payload)->not->toContain('Sensitive evidence')
        ->and($payload)->not->toContain('Changed sensitive value')
        ->and(ReportAuditEvent::where('event_type', 'snapshot_created')->count())->toBe(1)
        ->and(ReportAuditEvent::where('event_type', 'snapshot_approved')->count())->toBe(1)
        ->and(ReportAuditEvent::where('event_type', 'snapshot_stale_detected')->count())->toBe(1);
});

it('has no API route that updates or deletes snapshots', function () {
    $characterization = reportSnapshotApiCharacterization($this->user, $this->topic);
    reportSnapshotApiFact($characterization, ['fact_id' => 'rf_immutable', 'approval_status' => 'reviewed']);
    $snapshot = app(\App\Services\Report\ReportSnapshotBuilder::class)->create($characterization);

    $this->actingAs($this->user)
        ->putJson("/api/report/snapshots/{$snapshot->id}", ['stale_state' => 'stale'])
        ->assertStatus(404);

    $this->actingAs($this->user)
        ->deleteJson("/api/report/snapshots/{$snapshot->id}")
        ->assertStatus(404);

    expect($snapshot->fresh()->stale_state)->toBe('fresh');
});

function reportSnapshotApiCharacterization(User $user, EsrsTopic $topic, array $responses = []): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'nace_code' => 'C10',
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
        'result_data' => [
            'candidate_topics' => ['E2'],
        ],
    ]);
}

function reportSnapshotApiFact(Characterization $characterization, array $overrides = []): ReportingFact
{
    return ReportingFact::create(array_replace([
        'characterization_id' => $characterization->id,
        'fact_id' => 'rf_default',
        'schema_version' => ReportingFact::SCHEMA_VERSION,
        'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => 'BP-1_01',
        'applicability' => 'applicable',
        'value_type' => 'text',
        'value' => ['text' => 'Default fact.'],
        'unit' => null,
        'decimals' => null,
        'dimensions' => [],
        'language' => 'en',
        'nil' => false,
        'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Internal support.']],
        'provenance' => 'api',
        'approval_status' => 'review_required',
        'blocking_reasons' => [],
    ], $overrides));
}
