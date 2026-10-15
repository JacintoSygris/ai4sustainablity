<?php

use App\Models\EsrsTopic;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Services\Report\ArelleXhtmlIxbrlValidator;
use App\Services\Report\ExternalTaxonomyManifestRepository;
use App\Services\Report\HtmlReportRenderer;
use App\Services\Report\ReportSnapshotBuilder;
use App\Services\Report\TaxonomyConceptMetadata;
use App\Services\Report\XbrlConceptMap;
use App\Services\Report\XhtmlIxbrlCandidateException;
use Tests\Support\ReportScopeFixture as F;

beforeEach(function () {
    config(['services.private_dev.auto_login' => false, 'services.report.external_taxonomy_manifest_path' => null, 'services.report.arelle_command' => null]);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);
    $this->topic = EsrsTopic::where('esrs_code', 'E2')->firstOrFail();
    $this->paths = F::assets($this->topic);
    $this->user = User::factory()->create();
});

it('requires a session and the capability and preserves the existing verification gate', function () {
    $this->getJson('/api/report/xhtml')->assertUnauthorized();
    config(['report_scope.enabled' => false]);
    $this->actingAs($this->user)->getJson('/api/report/xhtml')->assertNotFound();
    config(['report_scope.enabled' => true, 'services.auth_hardening.require_email_verification' => true]);
    $this->user->forceFill(['email_verified_at' => null])->save();
    $this->getJson('/api/report/xhtml')->assertStatus(409)->assertJsonPath('code', 'email_unverified');
});

it('returns a complete presentation-only attachment without external taxonomy or Arelle', function (string $locale, string $filename) {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    F::approve($c);
    $this->actingAs($this->user)->withSession(['app_locale' => $locale])->getJson('/api/report')->assertOk()->assertJsonPath('data.report_scope_integrity_enabled', true);
    $response = $this->get('/api/report/xhtml')->assertOk()->assertHeader('X-Report-Publication-State', 'presentation_only');
    expect($response->headers->get('Content-Type'))->toContain('application/xhtml+xml');
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="'.$filename.'"');
    expect($response->getContent())->toContain('Synthetic approved narrative &lt;script&gt;')->not->toContain('ix:nonFraction', 'validated_candidate');
    $xml = new DOMDocument;
    expect($xml->loadXML($response->getContent(), LIBXML_NONET))->toBeTrue();
})->with([['es', 'informe-neis.xhtml'], ['en', 'esrs-report.xhtml']]);

it('does not select a foreign approved snapshot or accept ownership and asset overrides', function () {
    $foreign = F::characterization(User::factory()->create(), $this->topic);
    F::fact($foreign);
    $snapshot = F::approve($foreign);
    $own = F::characterization($this->user, $this->topic);
    F::fact($own);
    $this->actingAs($this->user)->getJson('/api/report/xhtml?owner='.$foreign->user_id.'&snapshot='.$snapshot->id.'&corpus_path=/tmp/ignored')
        ->assertStatus(409)->assertJsonPath('data.reason_code', 'approved_snapshot_missing');
    $this->postJson('/api/report/snapshots/'.$snapshot->id.'/approve', ['single_person_declaration' => 'Synthetic declaration'])->assertNotFound();
});

it('enforces the actual presentation throttle without invoking candidate validation', function () {
    $this->actingAs($this->user);
    for ($i = 0; $i < 10; $i++) $this->getJson('/api/report/xhtml')->assertStatus(409);
    $this->getJson('/api/report/xhtml')->assertStatus(429);
    $this->getJson('/api/report/xhtml-ixbrl-candidate')->assertStatus(409);
});

it('blocks approval and every current factual export after independent asset changes', function (string $asset, string $reason) {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    $s = F::approve($c);
    F::edit($this->paths[$asset], function ($data) { $data['source']['note'] = 'Synthetic asset change'; return $data; });
    $this->actingAs($this->user)->postJson('/api/report/snapshots/'.$s->id.'/approve', ['single_person_declaration' => 'Synthetic declaration'])
        ->assertStatus(409)->assertJsonFragment(['reasons' => [$reason]]);
    foreach (['/api/report/xhtml', '/api/report/html', '/api/guided-report/docx', '/api/guided-report/evidence-bundle'] as $route) {
        $r = $this->getJson($route)->assertStatus(409)->assertJsonPath('data.reason_code', $reason);
        expect($r->headers->has('Content-Disposition'))->toBeFalse();
    }
})->with([['corpus', 'ig3_corpus_changed'], ['mapping', 'ar16_dr_mapping_changed']]);

it('rejects unavailable assets and preserves a controlled error for malformed historical dimensions', function (bool $missing) {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c, ['dimensions' => [['axis' => [], 'member' => 'bad']]]);
    F::approve($c);
    $this->actingAs($this->user)->getJson('/api/report/xhtml')->assertStatus(409)->assertJsonPath('data.reason_code', 'approved_snapshot_invalid');
    if ($missing) {
        config(['services.esrs_datapoints.matter_dr_mapping_path' => $this->paths['mapping'].'.missing']);
    } else {
        file_put_contents($this->paths['mapping'], '{invalid');
    }
    $count = ReportSnapshot::count();
    $response = $this->postJson('/api/report/snapshot')->assertStatus(409)->assertExactJson(['data' => [
        'type' => 'guided_report_blocked', 'reason_code' => 'scope_assets_unavailable',
    ]]);
    expect(ReportSnapshot::count())->toBe($count)
        ->and($response->getContent())->not->toContain($this->paths['mapping']);
})->with(['missing' => true, 'corrupt' => false]);

it('persists corpus staleness before projecting live export readiness', function (string $route, bool $enabled, bool $addDatapoint) {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    $snapshot = F::approve($c);
    $payload = $snapshot->snapshot_json;
    $approval = $snapshot->approval->getAttributes();
    config(['report_scope.enabled' => $enabled, 'ixbrl.package_ui_enabled' => true]);
    F::edit($this->paths['corpus'], function ($data) use ($addDatapoint) {
        if ($addDatapoint) {
            $data['datapoints'][] = array_replace($data['datapoints'][0], [
                'id' => 'SYNTHETIC_UNTRANSLATED', 'name' => 'Synthetic untranslated datapoint',
            ]);
        } else {
            $data['source']['note'] = 'Synthetic corpus note mutation';
        }
        return $data;
    });
    $response = $this->actingAs($this->user)->getJson($route)->assertStatus(409)
        ->assertJsonPath('data.reason_code', 'ig3_corpus_changed');
    $snapshot->refresh();
    expect($response->headers->has('Content-Disposition'))->toBeFalse()
        ->and($snapshot->stale_state)->toBe('stale')
        ->and($snapshot->stale_reasons)->toContain('ig3_corpus_changed')
        ->and($snapshot->snapshot_json)->toBe($payload)
        ->and($snapshot->approval->getAttributes())->toBe($approval);
    $this->assertDatabaseHas('report_audit_events', [
        'report_snapshot_id' => $snapshot->id, 'event_type' => 'snapshot_stale_detected',
    ]);
})->with([
    ['/api/report/xhtml', true], ['/api/report/html', true],
    ['/api/guided-report/docx', true], ['/api/guided-report/evidence-bundle', true],
    ['/api/report/xhtml-ixbrl-candidate', true], ['/api/report/ixbrl-package', true],
    ['/api/report/html', false],
])->with([false, true]);

it('checks fresh assets after rendering and returns no attachment on mutation', function (string $asset) {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    $s = F::approve($c);
    $path = $this->paths[$asset];
    $this->mock(HtmlReportRenderer::class)->shouldReceive('render')->once()->andReturnUsing(function ($ir, $locale) use ($path) {
        $bytes = (new HtmlReportRenderer)->render($ir, $locale);
        F::edit($path, function ($data) { $data['source']['note'] = 'Synthetic mutation during render'; return $data; });
        return $bytes;
    });
    $r = $this->actingAs($this->user)->getJson('/api/report/html')->assertStatus(409);
    expect($r->headers->has('Content-Disposition'))->toBeFalse()->and($s->fresh()->stale_state)->toBe('stale');
})->with(['corpus', 'mapping']);

it('exports a newly approved successor after corpus metadata changes without translating readiness', function (string $locale) {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    $old = F::approve($c);
    $payload = $old->snapshot_json;
    $approval = $old->approval->getAttributes();
    F::edit($this->paths['corpus'], function ($data) {
        $data['source']['note'] = 'Synthetic unsupported source note <private-metadata>';
        return $data;
    });
    $this->actingAs($this->user)->withSession(['app_locale' => $locale]);
    $this->getJson('/api/report/html')->assertStatus(409)->assertJsonPath('data.reason_code', 'ig3_corpus_changed');
    $id = $this->postJson('/api/report/snapshot')->assertCreated()->json('data.id');
    expect($id)->not->toBe($old->id);
    $this->postJson('/api/report/snapshots/'.$id.'/approve', ['single_person_declaration' => 'Synthetic successor approval'])->assertCreated();
    foreach (['/api/report/html', '/api/guided-report/docx', '/api/report/xhtml', '/api/guided-report/evidence-bundle'] as $route) {
        $this->get($route)->assertOk()->assertHeader('Content-Disposition');
    }
    // UI metadata still needs supported copy, but its failure must be sanitized.
    foreach (['/api/report', '/api/report/draft'] as $route) {
        $this->getJson($route)->assertStatus(409)->assertExactJson(['data' => [
            'type' => 'guided_report_blocked', 'reason_code' => 'approved_snapshot_invalid',
        ]]);
    }
    expect($old->fresh()->snapshot_json)->toBe($payload)
        ->and($old->fresh()->approval->getAttributes())->toBe($approval);
})->with(['es', 'en']);

it('exports withdrawn reviewed facts in an English appendix using only captured labels', function () {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    F::fact($c, ['fact_id' => 'rf_withdrawn', 'datapoint_id' => 'SBM-1_06',
        'value_type' => 'number', 'value' => ['value' => '99'], 'unit' => 'EUR',
        'applicability' => 'not_applicable', 'nil_reason' => 'Synthetic withdrawn reason <retained>',
        'dimensions' => [['axis' => 'custom', 'member' => 'Synthetic <member>']], 'evidence_refs' => []]);
    F::approve($c);
    F::edit($this->paths['corpus'], function ($data) {
        $data['datapoints'] = array_values(array_filter($data['datapoints'], fn ($row) => $row['id'] !== 'SBM-1_06'));
        return $data;
    });
    $this->actingAs($this->user)->withSession(['app_locale' => 'en']);
    $this->getJson('/api/report/html')->assertStatus(409)->assertJsonPath('data.reason_code', 'ig3_corpus_changed');
    $id = $this->postJson('/api/report/snapshot')->assertCreated()->json('data.id');
    $this->postJson('/api/report/snapshots/'.$id.'/approve', ['single_person_declaration' => 'Synthetic successor approval'])->assertCreated();

    // Reproduce the missing canonical label in a cold/changed catalogue without
    // mutating the shared product corpus; any forbidden fallback now throws.
    $cache = new ReflectionProperty(\App\Support\EsrsDisplayCatalogue::class, 'canonicalNames');
    $previous = $cache->getValue();
    $cache->setValue(null, []);
    try {
        $texts = [];
        foreach (['html' => '/api/report/html', 'docx' => '/api/guided-report/docx', 'xhtml' => '/api/report/xhtml'] as $format => $route) {
            $bytes = $this->get($route)->assertOk()->getContent();
            $texts[$format] = F::presentationText($bytes, $format);
        }
        expect($texts['docx'])->toBe($texts['html'])->and($texts['xhtml'])->toBe($texts['html']);
        $index = array_search('Label unavailable', $texts['html'], true);
        expect($index)->not->toBeFalse();
        expect(array_slice($texts['html'], $index, 4))->toBe([
            'Label unavailable', 'Applicability: Not applicable',
            'Recorded value: 99 EUR — custom: Synthetic <member>',
            'Reason for no value: Synthetic withdrawn reason <retained>',
        ]);
        $this->getJson('/api/guided-report/evidence-bundle')->assertOk()
            ->assertJsonPath('data.display.claim_labels.0.label', 'Basis for preparation of sustainability statement');
        expect($cache->getValue())->toBe([]);
    } finally {
        $cache->setValue(null, $previous);
    }
});

it('requires reapproval of v1 and keeps v2 enforcement when the flag is disabled', function () {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    config(['report_scope.enabled' => false]);
    F::approve($c);
    config(['report_scope.enabled' => true]);
    $this->actingAs($this->user)->getJson('/api/report/xhtml')->assertStatus(409)->assertJsonPath('data.reason_code', 'scope_identity_missing');
    $s = app(ReportSnapshotBuilder::class)->create($c);
    $this->postJson('/api/report/snapshots/'.$s->id.'/approve', ['single_person_declaration' => 'Synthetic new approval'])->assertCreated();
    config(['report_scope.enabled' => false]);
    F::edit($this->paths['mapping'], function ($d) { $d['version'] = 'synthetic-changed'; return $d; });
    $this->getJson('/api/report/html')->assertStatus(409)->assertJsonPath('data.reason_code', 'ar16_dr_mapping_changed');
});

it('directs zero-tag candidates and packages to presentation without taxonomy prerequisites', function () {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    F::approve($c);
    config(['ixbrl.package_ui_enabled' => true]);
    foreach (['/api/report/xhtml-ixbrl-candidate', '/api/report/ixbrl-package'] as $route) {
        $this->actingAs($this->user)->getJson($route)->assertStatus(409)
            ->assertJsonPath('data.reason_code', 'xhtml_ixbrl_no_taggable_claims')->assertJsonPath('data.next_step', '/api/report/xhtml');
    }
});

it('still blocks eligible candidates when Arelle fails and exposes no validator details', function () {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c, ['value_type' => 'number', 'value' => ['value' => '123.45'], 'unit' => 'EUR', 'decimals' => 2]);
    F::approve($c);
    $this->mock(ExternalTaxonomyManifestRepository::class)->shouldReceive('loadInternalForProfile')->once()->andReturn(['taxonomy_package_checksum' => str_repeat('a', 64)]);
    $concept = (new XbrlConceptMap)->conceptFor('BP-1_01')['concept_id'];
    $this->mock(TaxonomyConceptMetadata::class)->shouldReceive('load')->once()->andReturn([
        $concept => ['type' => '{http://www.xbrl.org/2003/instance}monetaryItemType', 'period_type' => 'duration', 'abstract' => false],
    ]);
    $this->mock(ArelleXhtmlIxbrlValidator::class)->shouldReceive('validate')->once()->andThrow(new XhtmlIxbrlCandidateException('xhtml_ixbrl_arelle_validation_failed'));
    $r = $this->actingAs($this->user)->getJson('/api/report/xhtml-ixbrl-candidate')->assertStatus(409)
        ->assertJsonPath('data.reason_code', 'xhtml_ixbrl_arelle_validation_failed');
    expect($r->headers->has('Content-Disposition'))->toBeFalse()->and($r->getContent())->not->toContain('/tmp/', '123.45');
});

it('rechecks scope after presentation XHTML rendering before sending any attachment', function () {
    $c = F::characterization($this->user, $this->topic);
    F::fact($c);
    F::approve($c);
    $path = $this->paths['corpus'];
    $this->mock(\App\Services\Report\XhtmlIxbrlCandidateRenderer::class)->shouldReceive('renderPresentation')->once()->andReturnUsing(function ($ir, $locale) use ($path) {
        $bytes = (new \App\Services\Report\XhtmlIxbrlCandidateRenderer(new XbrlConceptMap))->renderPresentation($ir, $locale);
        F::edit($path, function ($data) { $data['source']['note'] = 'Synthetic concurrent asset mutation'; return $data; });
        return $bytes;
    });
    $r = $this->actingAs($this->user)->getJson('/api/report/xhtml')->assertStatus(409)->assertJsonPath('data.reason_code', 'ig3_corpus_changed');
    expect($r->headers->has('Content-Disposition'))->toBeFalse();
});
