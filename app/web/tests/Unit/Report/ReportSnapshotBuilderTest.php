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

it('creates deterministic v2 manifests and ignores JSON whitespace and object key order', function () {
    $paths = \Tests\Support\ReportScopeFixture::assets($this->topic);
    $c = reportSnapshotUnitCharacterization($this->user, $this->topic);
    reportSnapshotUnitFact($c);
    $builder = app(ReportSnapshotBuilder::class);
    $s = $builder->create($c);
    expect($s->snapshot_json['schema_version'])->toBe('report_snapshot_v2');
    foreach ($paths as $path) {
        $decoded = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        krsort($decoded);
        file_put_contents($path, str_replace("\n", "\r\n", json_encode($decoded, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
    }
    expect($builder->createOrFind($c)['created'])->toBeFalse()
        ->and($builder->buildCanonicalState($c)['snapshot_hash'])->toBe($s->snapshot_hash);
    $builder->assertFrozenSnapshotIntegrity($s);
});

it('rejects missing or corrupt scope assets with a sanitized typed failure', function (string $asset, string $bytes) {
    $paths = \Tests\Support\ReportScopeFixture::assets($this->topic);
    $c = reportSnapshotUnitCharacterization($this->user, $this->topic);
    file_put_contents($paths[$asset], $bytes);
    expect(fn () => app(ReportSnapshotBuilder::class)->create($c))->toThrow(\App\Services\Report\ReportScopeException::class, 'scope_assets_unavailable');
})->with([['corpus', '{broken'], ['mapping', '{}'], ['mapping', '{broken'], ['corpus', '{"datapoints":[]}']]);

it('rejects both manifest copies when tampered and preserves v1 hashes across activation', function () {
    $paths = \Tests\Support\ReportScopeFixture::assets($this->topic);
    config(['report_scope.enabled' => false]);
    $c = reportSnapshotUnitCharacterization($this->user, $this->topic);
    reportSnapshotUnitFact($c);
    $builder = app(ReportSnapshotBuilder::class);
    $v1 = $builder->create($c);
    $bytes = json_encode($v1->snapshot_json, JSON_THROW_ON_ERROR);
    config(['report_scope.enabled' => true]);
    expect(app(ReportStalenessDetector::class)->refreshState($v1)['reasons'])->toContain('scope_identity_missing');
    $builder->assertFrozenSnapshotIntegrity($v1->fresh());
    expect(json_encode($v1->fresh()->snapshot_json, JSON_THROW_ON_ERROR))->toBe($bytes);
    $v2 = $builder->create($c);
    foreach (['source_manifest', 'snapshot_json'] as $column) {
        $copy = $v2->replicate();
        $payload = $copy->$column;
        if ($column === 'source_manifest') $payload['scope']['corpus_sha256'] = str_repeat('0', 64);
        else $payload['source_manifest']['scope']['corpus_sha256'] = str_repeat('0', 64);
        $copy->$column = $payload;
        expect(fn () => $builder->assertFrozenSnapshotIntegrity($copy))->toThrow(DomainException::class);
    }
    config(['services.esrs_datapoints.matter_dr_mapping_path' => $paths['mapping'].'.missing']);
    expect(fn () => $builder->create($c))->toThrow(\App\Services\Report\ReportScopeException::class, 'scope_assets_unavailable');
});

it('preserves canonical decoded JSON object identity numeric types and list order', function () {
    $hash = \App\Services\Report\ReportScopeAssets::hash(...);
    expect($hash(json_decode('{"b":[],"a":{"y":1,"x":1.0}}')))->toBe($hash(json_decode('{"a":{"x":1.0,"y":1},"b":[]}')));
    expect($hash(json_decode('{"a":1.0}')))->not->toBe($hash(json_decode('{"a":1}')));
    expect($hash(json_decode('{"a":{}}')))->not->toBe($hash(json_decode('{"a":[]}')));
    expect($hash([1, 2]))->not->toBe($hash([2, 1]));
});

require_once __DIR__.'/../../Support/xbrl-runtime-fixture.php';

it('gates scope and runtime evidence independently when creating snapshots', function (bool $scopeEnabled, bool $runtimeEnabled) {
    if ($scopeEnabled) \Tests\Support\ReportScopeFixture::assets($this->topic);
    config(['report_scope.enabled' => $scopeEnabled, 'xbrl_suggestions.runtime_enabled' => $runtimeEnabled]);
    $company = reportSnapshotUnitCharacterization($this->user, $this->topic);
    xbrlRuntimeReviewedFixture($company);
    $builder = app(ReportSnapshotBuilder::class);
    $snapshot = $builder->create($company);

    expect($snapshot->snapshot_json['schema_version'])->toBe($scopeEnabled ? 'report_snapshot_v2' : 'report_snapshot_v1')
        ->and(array_key_exists('scope', $snapshot->source_manifest))->toBe($scopeEnabled)
        ->and(array_key_exists('xbrl_identifications', $snapshot->source_manifest))->toBe($runtimeEnabled)
        ->and($builder->createOrFind($company)['created'])->toBeFalse();
    $builder->assertFrozenSnapshotIntegrity($snapshot);
})->with([
    'both off' => [false, false],
    'scope only' => [true, false],
    'runtime only' => [false, true],
    'both on' => [true, true],
]);

it('freezes identification inputs results and reviews independently of live flags and later decisions', function (bool $scopeEnabled) {
    $paths = $scopeEnabled ? \Tests\Support\ReportScopeFixture::assets($this->topic) : [];
    config(['report_scope.enabled' => $scopeEnabled, 'xbrl_suggestions.runtime_enabled' => true]);
    $company = reportSnapshotUnitCharacterization($this->user, $this->topic);
    $run = xbrlRuntimeReviewedFixture($company);
    $builder = app(ReportSnapshotBuilder::class);
    $snapshot = $builder->create($company);
    $frozen = $snapshot->source_manifest['xbrl_identifications'];
    expect($frozen['records'][0]['input_json'])->toBe($run->input_json)
        ->and($frozen['records'][0]['response_json'])->toBe($run->response_json);
    \App\Models\XbrlIdentificationReview::create(['run_id' => $run->id, 'candidate_qname' => $run->response_json['candidates'][0]['qname'],
        'decision' => 'rejected', 'reason' => 'Synthetic later decision.', 'reviewer_id' => $this->user->id, 'created_at' => now('UTC'), 'sequence' => 2]);
    expect($builder->create($company)->snapshot_hash)->not->toBe($snapshot->snapshot_hash);
    config(['report_scope.enabled' => false, 'xbrl_suggestions.runtime_enabled' => false, 'xbrl_suggestions.runtime_manifest_path' => '/invalid-now']);
    $builder->assertFrozenSnapshotIntegrity($snapshot->fresh());
    expect($snapshot->fresh()->source_manifest['xbrl_identifications'])->toBe($frozen);
    expect(app(ReportStalenessDetector::class)->detect($snapshot->fresh())['is_stale'])->toBeFalse();
    if ($scopeEnabled) {
        \Tests\Support\ReportScopeFixture::edit($paths['corpus'], function (array $corpus): array {
            $corpus['source']['name'] = 'Changed synthetic corpus';
            return $corpus;
        });
        expect(app(ReportStalenessDetector::class)->detect($snapshot->fresh())['reasons'])->toContain('ig3_corpus_changed');
    }
    $payload = $snapshot->snapshot_json;
    $payload['source_manifest']['xbrl_identifications']['records'][0]['input_json']['indicator']['description'] = 'tampered';
    $snapshot->forceFill(['snapshot_json' => $payload]);
    expect(fn () => $builder->assertFrozenSnapshotIntegrity($snapshot))->toThrow(DomainException::class);
})->with(['runtime only' => [false], 'scope and runtime' => [true]]);
