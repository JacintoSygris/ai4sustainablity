<?php

use App\Services\Report\ReportingProfileRepository;
use App\Services\Report\XhtmlIxbrlCandidateException;
use App\Services\Report\XhtmlIxbrlCandidateRenderer;
use App\Services\Report\XbrlConceptMap;

uses(Tests\TestCase::class);

beforeEach(function () {
    // Synthetic metadata fixture; real taxonomy validation is a separate Arelle check.
    $this->mock(\App\Services\Report\TaxonomyConceptMetadata::class)
        ->shouldReceive('load')->andReturn([
            'esrs:Revenue' => ['type' => '{http://www.xbrl.org/2003/instance}monetaryItemType', 'period_type' => 'duration', 'abstract' => false],
        ]);
});

it('renders deterministic parseable XHTML with iXBRL namespaces and schemaRef', function () {
    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
    $ir = xhtmlIxbrlRendererIr();

    $first = $renderer->render($ir, $profile, xhtmlIxbrlRendererManifest());
    $second = $renderer->render($ir, $profile, xhtmlIxbrlRendererManifest());

    expect($first)->toBe($second);
    expect($first)->toContain('xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"');
    expect($first)->toContain('xmlns:ixt="http://www.xbrl.org/inlineXBRL/transformation/2020-02-12"');
    expect($first)->toContain('link:schemaRef');
    expect($first)->toContain('https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd');
    expect($first)->toContain('<ix:nonFraction');
    expect($first)->toContain('unitRef="u_EUR"');
    expect($first)->toContain('decimals="2"');
    expect($first)->toMatch('/<ix:nonFraction[^>]*>123\.45<\/ix:nonFraction>/');
    expect($first)->toContain('scheme="http://standards.iso.org/iso/17442"');
    expect($first)->toContain('TEST0000000000000075');
    expect($first)->not->toContain('snapshot-entity-')
        ->not->toContain('ia4sustainability.local');

    $doc = new DOMDocument();
    expect($doc->loadXML($first))->toBeTrue();
});

it('escapes malicious visible values and keeps internal ids out of visible XHTML', function () {
    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
    $ir = xhtmlIxbrlRendererIr([
        'company' => [
            'name' => 'ACME <script>alert(1)</script>',
            'reporting_year' => 2025,
            'reporting_period_start' => '2025-07-01',
            'reporting_period_end' => '2026-06-30',
            'entity_identifier' => 'TEST0000000000000075',
            'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442',
        ],
    ]);

    $xhtml = $renderer->render($ir, $profile, xhtmlIxbrlRendererManifest());

    expect($xhtml)->toContain('ACME &lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>')
        ->not->toContain('claim_')
        ->not->toContain('rf_')
        ->not->toContain('snapshot_hash')
        ->not->toContain('/tmp/operator-package.zip');
});

it('blocks invalid factual candidate inputs without silent omission', function (array $overrides, string $reason) {
    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
    $ir = xhtmlIxbrlRendererIr($overrides);

    expect(fn () => $renderer->render($ir, $profile, xhtmlIxbrlRendererManifest()))
        ->toThrow(XhtmlIxbrlCandidateException::class, $reason);
})->with([
    'missing entity' => [['company' => ['name' => '', 'reporting_year' => 2025]], 'xhtml_ixbrl_context_missing'],
    'name only does not enable context' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025]], 'xhtml_ixbrl_context_missing'],
    'missing period' => [['company' => ['name' => 'ACME', 'reporting_year' => null, 'entity_identifier' => 'TEST0000000000000075', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442']], 'xhtml_ixbrl_context_missing'],
    'missing identifier' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442']], 'xhtml_ixbrl_context_missing'],
    'missing scheme' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier' => 'TEST0000000000000075']], 'xhtml_ixbrl_context_missing'],
    'malicious identifier' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier' => 'ACME<script>', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442']], 'xhtml_ixbrl_context_missing'],
    'malicious scheme' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier' => 'TEST0000000000000075', 'entity_identifier_scheme' => 'javascript:alert(1)']], 'xhtml_ixbrl_context_missing'],
    'missing dates in a legacy calendar year' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier' => 'TEST0000000000000075', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442']], 'xhtml_ixbrl_context_missing'],
    'invalid unit' => [['claims' => [xhtmlIxbrlRendererClaim(['unit' => 'kg'])]], 'xhtml_ixbrl_unit_invalid'],
    'invalid decimals' => [['claims' => [xhtmlIxbrlRendererClaim(['decimals' => 'two'])]], 'xhtml_ixbrl_decimals_invalid'],
    'non numeric claim' => [['claims' => [xhtmlIxbrlRendererClaim(['value_type' => 'text', 'value' => ['text' => 'Narrative']])]], 'xhtml_ixbrl_no_renderable_claims'],
    'no claims' => [['claims' => []], 'xhtml_ixbrl_no_renderable_claims'],
    'unmapped concept' => [['claims' => [xhtmlIxbrlRendererClaim(['datapoint_id' => 'UNKNOWN_DP'])]], 'xhtml_ixbrl_concept_unavailable'],
    'dimensions unsupported' => [['claims' => [xhtmlIxbrlRendererClaim(['dimensions' => [['axis' => 'segment', 'member' => 'EU']]])]], 'xhtml_ixbrl_dimensions_unsupported'],
]);

function xhtmlIxbrlRendererIr(array $overrides = []): array
{
    return array_replace([
        'schema_version' => 'report_ir_v1',
        'source' => ['snapshot_hash' => 'snapshot_hash_must_not_render'],
        'company' => [
            'name' => 'ACME',
            'reporting_year' => 2025,
            'reporting_period_start' => '2025-07-01',
            'reporting_period_end' => '2026-06-30',
            'entity_identifier' => 'TEST0000000000000075',
            'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442',
        ],
        'claims' => [xhtmlIxbrlRendererClaim()],
    ], $overrides);
}

function xhtmlIxbrlRendererClaim(array $overrides = []): array
{
    return array_replace([
        'claim_id' => 'claim_secret',
        'fact_id' => 'rf_secret',
        'datapoint_id' => 'SBM-1_06',
        'value_type' => 'number',
        'value' => ['value' => '123.45'],
        'unit' => 'EUR',
        'decimals' => 2,
        'dimensions' => [],
    ], $overrides);
}

function xhtmlIxbrlRendererManifest(): array
{
    return [
        'taxonomy_entrypoint' => 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd',
        'taxonomy_package_path' => '/tmp/operator-package.zip',
    ];
}

it('localizes the visible candidate shell while preserving machine taxonomy and numeric values', function () {
    $profile = (new ReportingProfileRepository)->load('esrs-2023-preparatory-v1');
    $ir = xhtmlIxbrlRendererIr();
    foreach (['es', 'en'] as $locale) {
        $xhtml = app(XhtmlIxbrlCandidateRenderer::class)->render($ir, $profile, xhtmlIxbrlRendererManifest(), $locale);
        expect($xhtml)->toContain('xml:lang="'.$locale.'"');
        expect($xhtml)->toContain($locale === 'es' ? 'Candidato XHTML/iXBRL NEIS' : 'ESRS XHTML/iXBRL candidate');
        expect($xhtml)->toMatch('/<ix:nonFraction[^>]*>123\.45<\/ix:nonFraction>/');
        expect($xhtml)->toContain('https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd');
    }
});

it('binds the mapped Revenue QName to the official ESRS core namespace', function () {
    $officialNamespace = 'https://xbrl.efrag.org/taxonomy/esrs/2023-12-22';
    $conceptMap = new XbrlConceptMap();
    expect($conceptMap->conceptFor('SBM-1_06'))->toMatchArray([
        'concept_id' => 'esrs:Revenue',
        'taggable_state' => 'mapped',
    ]);

    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
    // Synthetic monetary fact and existing safe context; this is not taxonomy validation.
    $ir = xhtmlIxbrlRendererIr([
        'claims' => [xhtmlIxbrlRendererClaim([
            'datapoint_id' => 'SBM-1_06',
            'value_type' => 'monetary',
            'value' => ['value' => '1234.56'],
            'unit' => 'EUR',
            'decimals' => 2,
        ])],
    ]);

    $doc = new DOMDocument();
    expect($doc->loadXML($renderer->render($ir, $profile, xhtmlIxbrlRendererManifest())))->toBeTrue();
    $facts = $doc->getElementsByTagNameNS('http://www.xbrl.org/2013/inlineXBRL', 'nonFraction');
    expect($facts->length)->toBe(1);
    $fact = $facts->item(0);
    expect($fact)->toBeInstanceOf(DOMElement::class);
    expect($fact->getAttribute('name'))->toBe('esrs:Revenue');
    expect($fact->textContent)->toBe('1234.56');
    expect($fact->getAttribute('unitRef'))->toBe('u_EUR');
    expect($fact->getAttribute('decimals'))->toBe('2');

    expect($doc->documentElement->lookupNamespaceURI('esrs'))->toBe($officialNamespace);
    [$prefix, $localName] = explode(':', $fact->getAttribute('name'), 2);
    expect([$fact->lookupNamespaceURI($prefix), $localName])->toBe([$officialNamespace, 'Revenue']);
});

it('keeps every mixed-content value visible while tagging eligible numbers in their original position', function () {
    $ir = \Tests\Support\ReportScopeFixture::presentationIr();
    $ir['claims'][1]['dimensions'] = [];
    $ir['claims'][] = array_replace($ir['claims'][1], ['claim_id' => 'claim_dimension', 'fact_id' => 'rf_dimension', 'dimensions' => [['axis' => 'custom', 'member' => 'Untranslated']]]);
    $ir['claims'][] = array_replace($ir['claims'][1], ['claim_id' => 'claim_duplicate', 'fact_id' => 'rf_duplicate']);
    $ir['chapters'][0]['sections'][1]['blocks'][0]['claims'] = ['claim_synthetic_number', 'claim_dimension', 'claim_duplicate', 'claim_synthetic_number'];
    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $profile = app(ReportingProfileRepository::class)->load();
    $result = $renderer->renderWithTaggingEvidence($ir, $profile, xhtmlIxbrlRendererManifest());
    $doc = new DOMDocument;
    expect($doc->loadXML($result['xhtml'], LIBXML_NONET))->toBeTrue();
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
    expect($xp->query('//ix:nonFraction')->length)->toBe(1);
    $tag = $xp->query('//ix:nonFraction')->item(0);
    expect($tag->textContent)->toBe('1.234,567')->and($tag->getAttribute('sign'))->toBe('-')
        ->and($tag->getAttribute('format'))->toBe('ixt:num-comma-decimal');
    expect($result['xhtml'])->toContain('Narrativa sintética', 'custom: Untranslated', '99 EUR', 'Dato no disponible', 'Omisión sintética');
    expect($result['tagging']['tagged_claim_ids'])->toBe(['claim_synthetic_number'])
        ->and(array_column($result['tagging']['untagged_claims'], 'reason'))->toBe(['non_numeric', 'dimensions_unsupported', 'duplicate_machine_fact']);
    expect($xp->query('//*[local-name()="p" and @data-claim-id="claim_synthetic_number"]')->length)->toBe(2);
});

it('rejects conflicts between eligible machine facts while keeping dimensional zero-tag reports renderable', function () {
    $ir = \Tests\Support\ReportScopeFixture::presentationIr();
    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $profile = app(ReportingProfileRepository::class)->load();
    expect($renderer->render($ir, $profile, xhtmlIxbrlRendererManifest()))->not->toContain('ix:nonFraction');
    $ir['claims'][1]['dimensions'] = [];
    $ir['claims'][] = array_replace($ir['claims'][1], ['claim_id' => 'conflict', 'value' => ['value' => '2']]);
    $ir['chapters'][0]['sections'][1]['blocks'][0]['claims'][] = 'conflict';
    expect(fn () => $renderer->render($ir, $profile, xhtmlIxbrlRendererManifest()))->toThrow(XhtmlIxbrlCandidateException::class, 'xhtml_ixbrl_duplicate_fact_conflict');
});

it('keeps mapping-required-context and unmapped claims visible with stable exclusion reasons', function () {
    $ir = \Tests\Support\ReportScopeFixture::presentationIr();
    $ir['claims'][1]['dimensions'] = [];
    $ir['claims'][1]['datapoint_id'] = 'MDR-A_11';
    $ir['chapters'][0]['sections'][1]['blocks'][0]['datapoint_id'] = 'MDR-A_11';
    $ir['claims'][] = array_replace($ir['claims'][1], ['claim_id' => 'claim_unmapped', 'fact_id' => 'rf_unmapped', 'datapoint_id' => 'SYNTHETIC_UNKNOWN']);
    $ir['chapters'][0]['sections'][1]['blocks'][] = ['datapoint_id' => 'SYNTHETIC_UNKNOWN', 'claims' => ['claim_unmapped']];
    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $result = $renderer->renderWithTaggingEvidence($ir, app(ReportingProfileRepository::class)->load(), xhtmlIxbrlRendererManifest());
    expect(array_column($result['tagging']['untagged_claims'], 'reason'))->toBe(['non_numeric', 'mapping_required_context', 'unmapped']);
    $dom = new DOMDocument;
    expect($dom->loadXML($result['xhtml'], LIBXML_NONET))->toBeTrue();
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
    $xp->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
    foreach (['claim_synthetic_number', 'claim_unmapped'] as $id) {
        $paragraphs = $xp->query('//*[local-name()="p" and @data-claim-id="'.$id.'"]');
        expect($paragraphs->length)->toBe(1)->and($paragraphs->item(0)->textContent)->toBe('-1.234,567 EUR');
    }
    expect($xp->query('//ix:nonFraction | //xbrli:context')->length)->toBe(0);
    expect($result['xhtml'])->toContain('Narrativa sintética');
});

it('preserves exact large decimals and percentage scale in the shared visible value', function () {
    $ir = \Tests\Support\ReportScopeFixture::presentationIr();
    $ir['claims'][1] = array_replace($ir['claims'][1], ['dimensions' => [], 'value' => ['value' => '-9007199254740993.123456789'], 'unit' => '%', 'decimals' => 9]);
    $ir['xbrl_mapping'] = ['version' => 'synthetic-percent', 'sha256' => str_repeat('a', 64), 'concepts' => [
        'SBM-1_06' => ['concept_id' => 'esrs:SyntheticPercent', 'taggable_state' => 'mapped'],
    ]];
    $metadata = Mockery::mock(\App\Services\Report\TaxonomyConceptMetadata::class);
    $metadata->shouldReceive('load')->andReturn(['esrs:SyntheticPercent' => [
        'type' => '{http://www.xbrl.org/dtr/type/2024-01-31}percentItemType', 'period_type' => 'duration', 'abstract' => false,
    ]]);
    $renderer = new XhtmlIxbrlCandidateRenderer(new XbrlConceptMap, $metadata);
    // No omission reconstruction needed for this independent English display fixture.
    $ir['omission_section'] = null;
    $result = $renderer->render($ir, app(ReportingProfileRepository::class)->load(), xhtmlIxbrlRendererManifest(), 'en');
    $dom = new DOMDocument;
    expect($dom->loadXML($result, LIBXML_NONET))->toBeTrue();
    $tag = $dom->getElementsByTagNameNS('http://www.xbrl.org/2013/inlineXBRL', 'nonFraction')->item(0);
    expect($tag->textContent)->toBe('9,007,199,254,740,993.123456789')
        ->and($tag->getAttribute('sign'))->toBe('-')->and($tag->getAttribute('scale'))->toBe('-2')
        ->and($tag->getAttribute('decimals'))->toBe('11');
});

it('does not change renderer tagging after a reviewed runtime identification', function (bool $scopeEnabled, bool $runtimeEnabled) {
    config(['report_scope.enabled' => $scopeEnabled, 'xbrl_suggestions.runtime_enabled' => $runtimeEnabled]);
    $profile = app(\App\Services\Report\ReportingProfileRepository::class)->load(\App\Models\ReportingFact::PROFILE_ID);
    $ir = $scopeEnabled ? \Tests\Support\ReportScopeFixture::presentationIr() : xhtmlIxbrlRendererIr();
    if ($scopeEnabled) $ir['claims'][1]['dimensions'] = [];
    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $before = $renderer->render($ir, $profile, xhtmlIxbrlRendererManifest());
    $ir['xbrl_identifications'] = ['schema_version' => 'xbrl-identifications-evidence-v1',
        'records' => [['subject_key' => 'custom:synthetic', 'reviews' => [['decision' => 'accepted', 'candidate_qname' => 'esrs:NotTheStaticMapping']]]]];
    expect($renderer->render($ir, $profile, xhtmlIxbrlRendererManifest()))->toBe($before);
    if ($scopeEnabled) {
        expect($renderer->renderWithTaggingEvidence($ir, $profile, xhtmlIxbrlRendererManifest())['tagging']['tagged_claim_ids'])
            ->toBe(['claim_synthetic_number']);
        config(['report_scope.enabled' => false, 'xbrl_suggestions.runtime_enabled' => false]);
        expect($renderer->render($ir, $profile, xhtmlIxbrlRendererManifest()))->toBe($before);
    }
})->with([
    'both off with historical evidence' => [false, false],
    'scope only with historical evidence' => [true, false],
    'runtime only' => [false, true],
    'both on' => [true, true],
]);
