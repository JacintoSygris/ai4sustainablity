<?php

use App\Services\Report\HtmlReportRenderer;

function minimalHtmlReportIr(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version' => 'report_ir_v1',
        'version_hash' => str_repeat('a', 64),
        'source' => [
            'snapshot_hash' => 'snapshot_hash_must_not_render',
            'profile_hash' => 'profile_hash_must_not_render',
            'approved_at' => '2026-09-17T10:00:00Z',
        ],
        'company' => ['name' => 'ACME', 'reporting_year' => 2025],
        'disclaimers' => ['No constituye una presentación oficial ni un trabajo de aseguramiento.'],
        'omission_section' => [
            'title' => 'Temas evaluados y no considerados materiales',
            'declaration' => 'Sin omisiones declaradas.',
            'statements' => [],
            'limitation' => null,
        ],
        'claims' => [[
            'claim_id' => 'claim_bp1_01_rf_frozen',
            'fact_id' => 'rf_frozen',
            'datapoint_id' => 'BP-1_01',
            'value' => ['text' => 'Frozen approved fact'],
            'evidence_refs' => [['type' => 'note', 'value' => 'Evidence sentinel must never render']],
            'approval_status' => 'reviewed',
        ]],
        'chapters' => [[
            'title' => 'ESRS 2',
            'floor_prose' => 'Este capítulo cubre información general.',
            'sections' => [[
                'dr_key' => 'BP-1',
                'cross_ref_sentences' => ['Véase BP-2 cuando aplique.'],
                'blocks' => [[
                    'datapoint_id' => 'BP-1_01',
                    'name' => 'Base general',
                    'assertions' => ['Material'],
                    'claims' => ['claim_bp1_01_rf_frozen'],
                    'guidance' => [
                        'text' => 'Prepare la base de preparación.',
                        'authoritative' => true,
                        'citations' => ['ESRS 2 BP-1'],
                    ],
                ]],
            ]],
        ]],
    ], $overrides);
}

it('renders a minimal report_ir_v1 without evidence or internal ids', function () {
    $html = (new HtmlReportRenderer())->render(minimalHtmlReportIr());

    expect($html)->toContain('<!doctype html>');
    expect($html)->toContain('lang="es"');
    expect($html)->toContain('Frozen approved fact');
    expect($html)->not->toContain('Evidence sentinel must never render');
    expect($html)->not->toContain('claim_bp1_01_rf_frozen');
    expect($html)->not->toContain('rf_frozen');
    expect($html)->not->toContain('Datapoint:');
    expect($html)->not->toContain('BP-1_01');
    expect($html)->not->toContain('snapshot_hash_must_not_render');
    expect($html)->not->toContain('profile_hash_must_not_render');
    expect($html)->not->toContain('approved_at');
});

it('escapes factual text so script input never becomes executable markup', function () {
    $html = (new HtmlReportRenderer())->render(minimalHtmlReportIr([
        'claims' => [[
            'claim_id' => 'claim_bp1_01_rf_frozen',
            'fact_id' => 'rf_frozen',
            'datapoint_id' => 'BP-1_01',
            'value' => ['text' => '<script>alert(1)</script>'],
            'evidence_refs' => [],
        ]],
    ]));

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    expect($html)->not->toContain('<script>alert(1)</script>');
});

it('renders identical bytes for the same ir', function () {
    $ir = minimalHtmlReportIr();

    expect((new HtmlReportRenderer())->render($ir))
        ->toBe((new HtmlReportRenderer())->render($ir));
});

it('renders Spanish factual labels and units without visible internal identifiers', function () {
    $ir = minimalHtmlReportIr([
        'claims' => [[
            'claim_id' => 'claim_bp1_01_rf_frozen',
            'fact_id' => 'rf_frozen',
            'datapoint_id' => 'E5-4_02',
            'value' => 12.8,
            'unit' => 't',
            'decimals' => 1,
        ]],
        'chapters' => [[
            'title' => 'Topical datapoints for mapped material Disclosure Requirements',
            'block_key' => 'topical',
            'floor_prose' => 'E9-9 English floor sentinel',
            'sections' => [[
                'dr_key' => 'E5-4',
                'cross_ref_sentences' => ['E9-9 English cross-reference sentinel'],
                'blocks' => [[
                    'datapoint_id' => 'E5-4_02',
                    'name' => 'Información reportada',
                    'assertions' => ['E9-9 English assertion sentinel'],
                    'claims' => ['claim_bp1_01_rf_frozen'],
                    'guidance' => [
                        'text' => 'E9-9 English guidance sentinel',
                        'authoritative' => true,
                        'citations' => ['E9-9 English citation sentinel'],
                    ],
                ]],
            ]],
        ]],
    ]);

    $html = (new HtmlReportRenderer())->render($ir);

    expect($html)->toContain('Información sobre temas materiales');
    expect($html)->toContain('Entradas de recursos');
    expect($html)->toContain('Peso total de productos y materiales técnicos y biológicos utilizados durante el periodo');
    expect($html)->toContain('12,8 t');
    expect($html)->not->toContain('E5-4');
    expect($html)->not->toContain('Topical datapoints');
    expect($html)->not->toContain('E9-9 English');
});

it('fails closed for p10_ir_v1', function () {
    $ir = minimalHtmlReportIr(['schema_version' => 'p10_ir_v1']);

    expect(fn () => (new HtmlReportRenderer())->render($ir))
        ->toThrow(\DomainException::class, 'HtmlReportRenderer requires report_ir_v1.');
});

it('fails closed when a factual disclaimer contains a technical disclosure identifier', function () {
    $ir = minimalHtmlReportIr(['disclaimers' => ['Referencia interna E5-4 no permitida.']]);

    expect(fn () => (new HtmlReportRenderer())->render($ir))
        ->toThrow(\DomainException::class);
});

it('renders factual omissions once as an appendix after the report body', function () {
    $html = (new HtmlReportRenderer())->render(minimalHtmlReportIr([
        'chapters' => [[
            'title' => 'ESRS 2',
            'block_key' => 'always_required',
            'sections' => [[
                'dr_key' => 'BP-1',
                'blocks' => [[
                    'datapoint_id' => 'BP-1_01',
                    'claims' => ['claim_bp1_01_rf_frozen'],
                ]],
            ]],
        ]],
    ]));

    expect($html)->toContain('Anexo: Temas evaluados y no considerados materiales');
    expect(substr_count($html, 'Temas evaluados y no considerados materiales'))->toBe(1);
    expect(strpos($html, 'Información general de la empresa'))
        ->toBeLessThan(strpos($html, 'Anexo: Temas evaluados y no considerados materiales'));
});
