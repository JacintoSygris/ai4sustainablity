<?php

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportingFact;
use App\Models\User;
use App\Services\Report\ReportSnapshotBuilder;
use App\Services\Report\ReportStalenessDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
});

it('hashes the same characterization and facts deterministically regardless of fact order', function () {
    $characterization = reportSnapshotUnitCharacterization($this->user, $this->topic);

    $first = reportSnapshotUnitFact($characterization, [
        'fact_id' => 'rf_b',
        'datapoint_id' => 'BP-1_02',
        'value' => ['text' => 'Second fact.'],
    ]);
    $second = reportSnapshotUnitFact($characterization, [
        'fact_id' => 'rf_a',
        'datapoint_id' => 'BP-1_01',
        'value' => ['text' => 'First fact.'],
    ]);

    $builder = app(ReportSnapshotBuilder::class);

    $hashFromDatabaseOrder = $builder->buildCanonicalState($characterization)['snapshot_hash'];
    $hashFromReversedFacts = $builder->buildCanonicalState($characterization, collect([$first, $second]))['snapshot_hash'];

    expect($hashFromDatabaseOrder)->toBe($hashFromReversedFacts);
});

it('detects fact and characterization changes as stale without leaking raw fact values', function () {
    $characterization = reportSnapshotUnitCharacterization($this->user, $this->topic);
    $fact = reportSnapshotUnitFact($characterization, [
        'fact_id' => 'rf_a',
        'datapoint_id' => 'BP-1_01',
        'value' => ['text' => 'Original value.'],
    ]);

    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization);

    $fact->update(['value' => ['text' => 'Changed confidential value.']]);
    $factResult = app(ReportStalenessDetector::class)->detect($snapshot);

    expect($factResult['is_stale'])->toBeTrue()
        ->and($factResult['reasons'])->toContain('reporting_facts_changed')
        ->and(json_encode($factResult, JSON_THROW_ON_ERROR))->not->toContain('Changed confidential value');

    $freshSnapshot = app(ReportSnapshotBuilder::class)->create($characterization->fresh());
    $characterization->update(['nace_code' => 'C11']);
    $characterizationResult = app(ReportStalenessDetector::class)->detect($freshSnapshot);

    expect($characterizationResult['is_stale'])->toBeTrue()
        ->and($characterizationResult['reasons'])->toContain('characterization_changed');
});

it('detects a lei change after snapshot creation as characterization stale state', function () {
    $characterization = reportSnapshotUnitCharacterization($this->user, $this->topic);
    reportSnapshotUnitFact($characterization);

    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization);
    $formData = $characterization->form_data;
    $formData['company_profile']['entity_identifier'] = '5493001KJTIIGC8Y1R12';
    $formData['company_profile']['entity_identifier_scheme'] = 'https://standards.iso.org/iso/17442';
    $characterization->update(['form_data' => $formData]);

    $result = app(ReportStalenessDetector::class)->detect($snapshot);

    expect($result['is_stale'])->toBeTrue()
        ->and($result['reasons'])->toContain('characterization_changed');
});

it('keeps a stale snapshot historical when live state later returns to its original hashes', function () {
    $characterization = reportSnapshotUnitCharacterization($this->user, $this->topic);
    $fact = reportSnapshotUnitFact($characterization, ['value' => ['text' => 'Original value.']]);
    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization);
    $detector = app(ReportStalenessDetector::class);

    $fact->update(['value' => ['text' => 'Changed value.']]);
    expect($detector->refreshState($snapshot)['is_stale'])->toBeTrue();

    $fact->update(['value' => ['text' => 'Original value.']]);
    $restored = $detector->refreshState($snapshot->fresh());

    expect($restored['is_stale'])->toBeTrue()
        ->and($restored['stale_state'])->toBe(\App\Models\ReportSnapshot::STALE_STALE)
        ->and($restored['reasons'])->toContain('reporting_facts_changed')
        ->and($snapshot->fresh()->stale_state)->toBe(\App\Models\ReportSnapshot::STALE_STALE);
});

it('rolls back the stale transition when its audit event cannot be written', function () {
    $characterization = reportSnapshotUnitCharacterization($this->user, $this->topic);
    $fact = reportSnapshotUnitFact($characterization);
    $snapshot = app(ReportSnapshotBuilder::class)->create($characterization);
    $fact->update(['value' => ['text' => 'Changed value.']]);

    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_snapshot_stale_audit
        BEFORE INSERT ON report_audit_events
        WHEN NEW.event_type = 'snapshot_stale_detected'
        BEGIN
            SELECT RAISE(ABORT, 'forced stale audit failure');
        END;
    SQL);

    expect(fn () => app(ReportStalenessDetector::class)->refreshState($snapshot))
        ->toThrow(\Illuminate\Database\QueryException::class);

    expect($snapshot->fresh()->stale_state)->toBe(\App\Models\ReportSnapshot::STALE_FRESH)
        ->and(\App\Models\ReportAuditEvent::where('event_type', 'snapshot_stale_detected')->count())->toBe(0);
});

function reportSnapshotUnitCharacterization(User $user, EsrsTopic $topic): Characterization
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
        ],
        'result_data' => [
            'candidate_topics' => ['E2'],
        ],
    ]);
}

function reportSnapshotUnitFact(Characterization $characterization, array $overrides = []): ReportingFact
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
        'approval_status' => 'reviewed',
        'blocking_reasons' => [],
    ], $overrides));
}
