<?php

use App\Services\Report\ReportingProfileRepository;
use App\Services\Report\XhtmlIxbrlCandidateException;
use App\Services\Report\XhtmlIxbrlCandidateRenderer;
use App\Services\Report\XbrlConceptMap;

uses(Tests\TestCase::class);

it('renders deterministic parseable XHTML with iXBRL namespaces and schemaRef', function () {
    $renderer = app(XhtmlIxbrlCandidateRenderer::class);
    $profile = (new ReportingProfileRepository())->load('esrs-2023-preparatory-v1');
    $ir = xhtmlIxbrlRendererIr();

    $first = $renderer->render($ir, $profile, xhtmlIxbrlRendererManifest());
    $second = $renderer->render($ir, $profile, xhtmlIxbrlRendererManifest());

    expect($first)->toBe($second);
    expect($first)->toContain('xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"');
    expect($first)->toContain('link:schemaRef');
    expect($first)->toContain('https://xbrl.efrag.org/taxonomy/esrs/2023-12-22/esrs_all.xsd');
    expect($first)->toContain('<ix:nonFraction');
    expect($first)->toContain('scheme="https://standards.iso.org/iso/17442"');
    expect($first)->toContain('TESTENTITYID00000000');
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
            'entity_identifier' => 'TESTENTITYID00000000',
            'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442',
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
    'missing period' => [['company' => ['name' => 'ACME', 'reporting_year' => null, 'entity_identifier' => 'TESTENTITYID00000000', 'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442']], 'xhtml_ixbrl_context_missing'],
    'missing identifier' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442']], 'xhtml_ixbrl_context_missing'],
    'missing scheme' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier' => 'TESTENTITYID00000000']], 'xhtml_ixbrl_context_missing'],
    'malicious identifier' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier' => 'ACME<script>', 'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442']], 'xhtml_ixbrl_context_missing'],
    'malicious scheme' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier' => 'TESTENTITYID00000000', 'entity_identifier_scheme' => 'javascript:alert(1)']], 'xhtml_ixbrl_context_missing'],
    'non https scheme' => [['company' => ['name' => 'ACME', 'reporting_year' => 2025, 'entity_identifier' => 'TESTENTITYID00000000', 'entity_identifier_scheme' => 'http://standards.iso.org/iso/17442']], 'xhtml_ixbrl_context_missing'],
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
            'entity_identifier' => 'TESTENTITYID00000000',
            'entity_identifier_scheme' => 'https://standards.iso.org/iso/17442',
        ],
        'claims' => [xhtmlIxbrlRendererClaim()],
    ], $overrides);
}

function xhtmlIxbrlRendererClaim(array $overrides = []): array
{
    return array_replace([
        'claim_id' => 'claim_secret',
        'fact_id' => 'rf_secret',
        'datapoint_id' => 'BP-1_01',
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
