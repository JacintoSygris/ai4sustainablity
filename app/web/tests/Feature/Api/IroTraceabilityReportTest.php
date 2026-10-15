<?php

use App\Models\ReportingFact;
use App\Models\ReportSnapshot;
use App\Services\Report\DocxRenderer;
use App\Services\Report\EvidenceBundleBuilder;
use App\Services\Report\HtmlReportRenderer;
use App\Services\Report\ReportIrBuilder;
use App\Services\Report\ReportSnapshotBuilder;
use App\Services\Report\ReportStalenessDetector;
use Tests\Support\IroTraceabilityFixture;

it('freezes the reviewed IRO graph through P9 approval evidence and all renderers', function () {
    $f = IroTraceabilityFixture::make($this);
    $corpus = IroTraceabilityFixture::confirm($this, $f);
    $this->putJson('/api/esrs-datapoints/responses', ['expected_revision' => 0,
        'expected_authority_digest' => $corpus['p9_authority_digest'], 'responses' => [
            ['datapoint_id' => 'SBM-1_06', 'status' => 'completed', 'value' => '123.45'],
        ]])->assertOk();
    ReportingFact::create([
        'characterization_id' => $f['characterization']->id, 'fact_id' => 'rf_synthetic_iro',
        'schema_version' => ReportingFact::SCHEMA_VERSION, 'profile_id' => ReportingFact::PROFILE_ID,
        'datapoint_id' => 'SBM-1_06', 'applicability' => 'applicable', 'value_type' => 'number',
        'value' => ['value' => '123.45'], 'unit' => 'EUR', 'decimals' => 2, 'dimensions' => [],
        'language' => 'es', 'nil' => false, 'nil_reason' => null,
        'evidence_refs' => [['type' => 'note', 'value' => 'Synthetic evidence only']],
        'provenance' => 'api', 'approval_status' => 'reviewed', 'blocking_reasons' => [],
    ]);
    $id = $this->postJson('/api/report/snapshot')->assertCreated()->json('data.id');
    $this->postJson('/api/report/snapshots/'.$id.'/approve', ['single_person_declaration' => 'Synthetic test declaration'])->assertSuccessful();
    $snapshot = ReportSnapshot::findOrFail($id);
    $frozen = $snapshot->source_manifest['p9_context_v1']['corpus'];
    expect($frozen['iro_context_v1'])->toBe($corpus['iro_context_v1']);
    config(['services.dma_import.iro_traceability_enabled' => false]);
    $ir = app(ReportIrBuilder::class)->buildFromApprovedSnapshot($snapshot);
    expect($ir['iro_context_v1'])->toBe($corpus['iro_context_v1'])
        ->and($ir['claims'])->toHaveCount(1);
    $bundle = app(EvidenceBundleBuilder::class)->build($ir);
    expect($bundle['iro_context_v1'])->toBe($corpus['iro_context_v1']);
    $html = app(HtmlReportRenderer::class)->render($ir);
    $docx = app(DocxRenderer::class)->render($ir);
    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>');
    $visible = docxVisibleText($docx);
    foreach ($corpus['iro_context_v1']['cards'] as $card) {
        expect($html)->toContain($card['review']['iro_id'])->and($visible)->toContain($card['review']['iro_id']);
    }
    // Synthetic taxonomy metadata only: this is rendering, not an Arelle qualification.
    $this->mock(\App\Services\Report\TaxonomyConceptMetadata::class)->shouldReceive('load')->andReturn([
        'esrs:Revenue' => ['type' => '{http://www.xbrl.org/2003/instance}monetaryItemType', 'period_type' => 'duration', 'abstract' => false],
    ]);
    $ir['company'] = ['name' => 'Synthetic renderer test', 'reporting_year' => 2026,
        'reporting_period_start' => '2026-01-01', 'reporting_period_end' => '2026-12-31',
        'entity_identifier' => 'TEST0000000000000075', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442'];
    $profile = app(\App\Services\Report\ReportingProfileRepository::class)->load(ReportingFact::PROFILE_ID);
    $xhtml = app(\App\Services\Report\XhtmlIxbrlCandidateRenderer::class)->render($ir, $profile, [
        'taxonomy_entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd', 'taxonomy_package_path' => '/tmp/synthetic-unused.zip',
    ]);
    $doc = new DOMDocument; expect($doc->loadXML($xhtml))->toBeTrue();
    expect($doc->getElementsByTagNameNS('http://www.xbrl.org/2013/inlineXBRL', 'nonFraction')->length)->toBe(1)
        ->and($xhtml)->not->toContain('<script>');
    foreach ($corpus['iro_context_v1']['cards'] as $card) { expect($xhtml)->toContain($card['review']['iro_id']); }
});

it('makes stale authority recoverable and blocks approval and publication after a P6 generation change', function () {
    $f = IroTraceabilityFixture::make($this);
    IroTraceabilityFixture::confirm($this, $f);
    $snapshot = app(ReportSnapshotBuilder::class)->create($f['characterization']->fresh());
    $f['characterization']->update(['submission_generation' => 2]);
    $stale = app(ReportStalenessDetector::class)->detect($snapshot);
    expect($stale['is_stale'])->toBeTrue()->and($stale['reasons'])->toContain('p8_confirmation_stale');
    $this->postJson('/api/report/snapshots/'.$snapshot->id.'/approve', ['single_person_declaration' => 'Synthetic'])
        ->assertConflict()->assertJsonPath('code', 'report_stale');
});

it('detects changes to mapping assets without modifying a frozen snapshot', function () {
    $f = IroTraceabilityFixture::make($this);
    IroTraceabilityFixture::confirm($this, $f);
    $snapshot = app(ReportSnapshotBuilder::class)->create($f['characterization']->fresh());
    $before = $snapshot->snapshot_json;
    $map = app(\App\Services\Ar16MatterDrMappingRepository::class)->approved();
    $map['version'] .= '-synthetic-changed';
    $this->mock(\App\Services\Ar16MatterDrMappingRepository::class)->shouldReceive('approved')->andReturn($map);
    $result = app(ReportStalenessDetector::class)->detect($snapshot);
    expect($result['reasons'])->toContain('p9_mapping_changed', 'p9_authority_changed')
        ->and($snapshot->fresh()->snapshot_json)->toBe($before);
});

it('rejects malformed IG3 before any definitive operation and reports recoverable snapshot staleness', function (string $defect) {
    $f = IroTraceabilityFixture::make($this);
    $corpus = IroTraceabilityFixture::confirm($this, $f);
    $snapshot = app(ReportSnapshotBuilder::class)->create($f['characterization']->fresh());
    $before = $f['characterization']->fresh()->form_data;
    $frozen = $snapshot->source_manifest;
    $path = base_path('data/esrs_datapoints_ig3.json');
    $source = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    switch ($defect) {
        case 'empty source': $source = ['source' => [], 'datapoints' => []]; break;
        case 'scalar source': $source = 42; break;
        case 'empty datapoints': $source['datapoints'] = []; break;
        case 'missing datapoints': unset($source['datapoints']); break;
        case 'missing metadata': unset($source['source']['workbook_version']); break;
        case 'invalid metadata': $source['source']['note'] = []; break;
        case 'invalid hash': $source['source']['sha256'] = 'synthetic-invalid'; break;
        case 'non-list rows': $source['datapoints'] = ['invalid' => $source['datapoints'][0]]; break;
        case 'non-object row': $source['datapoints'][0] = 'synthetic-invalid'; break;
        case 'missing id': unset($source['datapoints'][0]['id']); break;
        case 'duplicate id': $source['datapoints'][1]['id'] = $source['datapoints'][0]['id']; break;
        case 'invalid inclusion': $source['datapoints'][0]['inclusion_type'] = 'synthetic-invalid'; break;
        case 'invalid boolean': $source['datapoints'][0]['may_disclose'] = 'false'; break;
        case 'missing annotation': unset($source['datapoints'][0]['phase_in_all']); break;
        case 'invalid annotation': $source['datapoints'][0]['data_type'] = []; break;
        case 'malformed unselected row':
            $last = array_key_last($source['datapoints']);
            unset($source['datapoints'][$last]['paragraph']); break;
    }
    // Inject bytes at the file read, never rewrite the shared normative asset.
    \Illuminate\Support\Facades\File::partialMock()->shouldReceive('get')->with($path)
        ->andReturn(json_encode($source, JSON_THROW_ON_ERROR));
    $body = ['expected_revision' => 0, 'responses' => [], 'expected_authority_digest' => $corpus['p9_authority_digest']];
    foreach ([['GET', '/api/esrs-datapoints'], ['GET', '/api/esrs-datapoints/responses'],
        ['PUT', '/api/esrs-datapoints/responses'], ['GET', '/api/esrs-datapoints/export.csv'],
        ['GET', '/api/esrs-datapoints/export.localized.csv'], ['GET', '/api/esrs-datapoints/responses/export.csv'],
        ['GET', '/api/esrs-datapoints/responses/export.localized.csv']] as [$method, $url]) {
        $this->json($method, $url, $body)->assertConflict()->assertJsonPath('code', 'p9_mapping_unavailable');
    }
    $this->postJson('/api/materiality-confirmation/preview', ['candidate_topic_ids' => [$f['topic']->id]])
        ->assertConflict()->assertJsonPath('code', 'p9_mapping_unavailable');
    $stale = app(ReportStalenessDetector::class)->detect($snapshot);
    expect($stale['is_stale'])->toBeTrue()->and($stale['reasons'])->toContain('p9_mapping_unavailable');
    $this->postJson('/api/report/snapshots/'.$snapshot->id.'/approve', ['single_person_declaration' => 'Synthetic'])
        ->assertConflict()->assertJsonPath('code', 'report_stale');
    expect($f['characterization']->fresh()->form_data)->toBe($before)
        ->and($snapshot->fresh()->source_manifest)->toBe($frozen);
})->with(['empty source', 'scalar source', 'empty datapoints', 'missing datapoints', 'missing metadata', 'invalid metadata', 'invalid hash', 'non-list rows', 'non-object row',
    'missing id', 'duplicate id', 'invalid inclusion', 'invalid boolean', 'missing annotation', 'invalid annotation', 'malformed unselected row']);
