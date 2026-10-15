<?php

use App\Models\EsrsTopic;
use App\Models\ReportAuditEvent;
use App\Models\User;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ReportIrBuilder;
use App\Services\Report\ReportScopeAssets;
use App\Services\Report\ReportSnapshotBuilder;
use App\Services\Report\ReportStalenessDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportScopeFixture as F;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
    $this->paths = F::assets($topic);
    $this->c = F::characterization(User::factory()->create(), $topic);
    F::fact($this->c);
});

it('independently invalidates corpus and map identities without rewriting payload or approval', function (string $asset, string $reason) {
    $s = F::approve($this->c);
    $original = [$s->snapshot_json, $s->source_manifest, $s->snapshot_hash, $s->approval()->first()->toArray()];
    F::edit($this->paths[$asset], function ($data) { $data['source']['name'] .= ' changed'; return $data; });
    $result = app(ReportStalenessDetector::class)->refreshState($s);
    expect($result['reasons'])->toContain($reason);
    $s->refresh();
    expect([$s->snapshot_json, $s->source_manifest, $s->snapshot_hash, $s->approval()->first()->toArray()])->toBe($original);
    expect(fn () => app(ReportIrBuilder::class)->buildFromApprovedSnapshot($s))->toThrow(DomainException::class);
    $fresh = app(ReportSnapshotBuilder::class)->create($this->c);
    expect($fresh->snapshot_hash)->not->toBe($s->snapshot_hash)->and($fresh->approval()->exists())->toBeFalse();
})->with([['corpus', 'ig3_corpus_changed'], ['mapping', 'ar16_dr_mapping_changed']]);

it('uses the captured inputs for hashing and scope while refreshing a reused builder in the same process', function () {
    $assets = ReportScopeAssets::capture();
    $builder = app(EsrsDatapointCorpusBuilder::class);
    $original = $builder->buildCaptured($this->c, $assets);
    F::edit($this->paths['corpus'], function ($d) { $d['datapoints'] = array_values(array_filter($d['datapoints'], fn ($row) => $row['id'] !== 'BP-1_01')); return $d; });
    expect($builder->buildCaptured($this->c, $assets))->toBe($original);
    expect($builder->build($this->c))->not->toBe($original);
    $fresh = ReportScopeAssets::capture();
    expect($fresh->manifest($builder->buildCaptured($this->c, $fresh))['scope_sha256'])->not->toBe($assets->manifest($original)['scope_sha256']);
});

it('enforces v2 scope after the flag is disabled and never revives a stale approval after asset rollback', function () {
    $s = F::approve($this->c);
    $original = file_get_contents($this->paths['corpus']);
    F::edit($this->paths['corpus'], function ($d) { $d['source']['note'] .= ' changed'; return $d; });
    config(['report_scope.enabled' => false]);
    expect(app(ReportStalenessDetector::class)->refreshState($s)['reasons'])->toContain('ig3_corpus_changed');
    file_put_contents($this->paths['corpus'], $original);
    expect(app(ReportStalenessDetector::class)->refreshState($s)['is_stale'])->toBeTrue();
    config(['report_scope.enabled' => true]);
    $builder = app(ReportSnapshotBuilder::class);
    $new = $builder->createOrFind($this->c);
    expect($new['created'])->toBeTrue()->and($new['snapshot']->id)->not->toBe($s->id)
        ->and($new['snapshot']->source_manifest['scope']['generation'])->toBe(1)
        ->and($new['snapshot']->approval()->exists())->toBeFalse();
    expect($builder->createOrFind($this->c)['snapshot']->id)->toBe($new['snapshot']->id);
});

it('rolls back scope stale transitions when the transactional audit fails', function () {
    $s = F::approve($this->c);
    F::edit($this->paths['mapping'], function ($d) { $d['version'] = 'synthetic-v2'; return $d; });
    DB::unprepared("CREATE TRIGGER fail_scope_audit BEFORE INSERT ON report_audit_events WHEN NEW.event_type = 'snapshot_stale_detected' BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END;");
    expect(fn () => app(ReportStalenessDetector::class)->refreshState($s))->toThrow(\Illuminate\Database\QueryException::class);
    expect($s->fresh()->stale_state)->toBe('fresh')->and(ReportAuditEvent::where('event_type', 'snapshot_stale_detected')->count())->toBe(0);
});
