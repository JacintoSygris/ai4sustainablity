<?php

use App\Services\Report\DocxRenderer;
use App\Services\Report\EvidenceBundleBuilder;
use App\Services\Report\HtmlReportRenderer;
use App\Services\Report\ReportDisplayProjection;

uses(Tests\TestCase::class);

function localizedFactualReportIr(): array
{
    return [
        'schema_version' => 'report_ir_v1',
        'version_hash' => str_repeat('a', 64),
        'source' => ['snapshot_hash' => 'immutable-snapshot'],
        'company' => ['name' => 'Empresa sin traducir', 'reporting_year' => 2025],
        'claims' => [[
            'claim_id' => 'claim_1', 'fact_id' => 'fact_1', 'datapoint_id' => 'BP-1_01',
            'value_type' => 'text', 'value' => ['text' => 'Texto libre unchanged'],
            'evidence_refs' => [['type' => 'note', 'value' => '=1+1']],
        ]],
        'chapters' => [[
            'block_key' => 'always_required', 'title' => 'Source corpus title',
            'sections' => [[
                'dr_key' => 'BP-1', 'blocks' => [[
                    'datapoint_id' => 'BP-1_01', 'claims' => ['claim_1'],
                    'slots' => [['node_id' => 'slot_1', 'claim_id' => 'claim_1', 'fact_id' => 'fact_1', 'xbrl_concept' => null]],
                ]],
            ]],
        ]],
        'materiality_trace' => [
            'omitted_topics' => [], 'is_stale' => false,
            'inferred_omissions_status' => 'determinable',
            'change_reason_notes' => ['7' => '=1+1'],
        ],
    ];
}

it('projects factual HTML DOCX and evidence into both locales without changing the approved source', function () {
    $ir = localizedFactualReportIr();
    $original = $ir;
    foreach (['es', 'en'] as $locale) {
        $html = (new HtmlReportRenderer)->render($ir, $locale);
        $docx = (new DocxRenderer)->render($ir, $locale);
        $visible = docxVisibleText($docx);
        $bundle = (new EvidenceBundleBuilder)->build($ir, [], $locale);
        $heading = $locale === 'es' ? 'Borrador factual basado en una versión aprobada' : 'Factual draft based on an approved version';
        expect($html)->toContain('lang="'.$locale.'"', $heading, 'Texto libre unchanged', 'Empresa sin traducir');
        expect($visible)->toContain($heading, 'Texto libre unchanged', 'Empresa sin traducir');
        expect($html)->not->toContain('=1+1');
        expect($visible)->not->toContain('=1+1');
        if ($locale === 'en') {
            expect($html)->not->toContain('Ejercicio', 'Borrador factual', 'Información general');
            expect($visible)->not->toContain('Ejercicio', 'Borrador factual', 'Información general');
        }
        expect($bundle['claims'])->toBe($original['claims']);
        expect($bundle['materiality_trace'])->toBe($original['materiality_trace']);
        expect($bundle['approved_snapshot'])->toBe($original['source']);
        expect($bundle['ir_version_hash'])->toBe($original['version_hash']);
        expect($bundle['display']['locale'])->toBe($locale);
        expect($bundle['display']['claim_labels'][0]['label'])->toBe((new ReportDisplayProjection($locale))->claimLabel('BP-1_01'));
    }
    expect($ir)->toBe($original);
});

it('uses localized boolean numeric and closed dimension labels with verbatim factual text', function () {
    $es = new ReportDisplayProjection('es');
    $en = new ReportDisplayProjection('en');
    expect($es->claimValue(['value' => true]))->toBe('Sí');
    expect($en->claimValue(['value' => true]))->toBe('Yes');
    expect($en->claimValue(['value' => 'Texto libre unchanged']))->toBe('Texto libre unchanged');
    $claim = ['value' => 12.5, 'decimals' => 1, 'unit' => 't', 'dimensions' => [
        ['axis' => 'waste_stream', 'member' => 'plastic_packaging'],
        ['axis' => 'hazard_class', 'member' => 'non_hazardous'],
    ]];
    expect($es->claimValue($claim))->toBe('12,5 t — Plástico de embalaje; no peligroso');
    expect($en->claimValue($claim))->toBe('12.5 t — Plastic packaging; non-hazardous');
});

it('formats decimal strings exactly through display projection and real HTML and DOCX renderers', function () {
    $cases = [
        ['integer', '9007199254740993', 0, '', '9.007.199.254.740.993', '9,007,199,254,740,993'],
        ['number', '9007199254740993.125', 2, '', '9.007.199.254.740.993,125', '9,007,199,254,740,993.125'],
        ['monetary', '999999999999999999999.995', 2, 'EUR', '1.000.000.000.000.000.000.000,00 EUR', '1,000,000,000,000,000,000,000.00 EUR'],
        ['number', '-.005', 2, '', '-0,005', '-0.005'],
        ['number', '-0.0049', 2, '', '-0,0049', '-0.0049'],
        ['number', '+0001.000', 0, 'personas', '+0.001,000 persona', '+0,001.000 person'],
        ['number', '1.0000000000000000001', 0, 'personas', '1,0000000000000000001 personas', '1.0000000000000000001 persons'],
        ['number', '9007199254740993', 2, 'año', '9007199254740993', '9007199254740993'],
        ['number', '.000000000001', 12, '', '0,000000000001', '0.000000000001'],
        ['number', '0.0000000417', 6, 'tCO2e/EUR', '0,0000000417 tCO2e/EUR', '0.0000000417 tCO2e/EUR'],
        ['integer', 2024, 0, 'year', '2024', '2024'],
        ['integer', 2, 0, 'persons', '2 personas', '2 persons'],
        ['number', 50, 6, 'percent', '50 %', '50 %'],
        // Legacy scalar inputs retain their established presentation.
        ['integer', 1234, 0, '', '1.234', '1,234'],
        ['number', 12.5, 1, 't', '12,5 t', '12.5 t'],
    ];
    foreach ($cases as [$type, $value, $decimals, $unit, $spanish, $english]) {
        $ir = localizedFactualReportIr();
        $ir['claims'][0] = array_replace($ir['claims'][0], [
            'value_type' => $type, 'value' => $value, 'unit' => $unit, 'decimals' => $decimals,
        ]);
        $original = $ir;
        foreach (['es' => $spanish, 'en' => $english] as $locale => $expected) {
            expect((new ReportDisplayProjection($locale))->claimValue($ir['claims'][0]))->toBe($expected);
            expect((new HtmlReportRenderer)->render($ir, $locale))->toContain($expected);
            expect(docxVisibleText((new DocxRenderer)->render($ir, $locale)))->toContain($expected);
        }
        expect($ir)->toBe($original);
    }
});

it('renders the preparation package in the selected language and escapes user content', function () {
    $draft = [
        'company' => ['name' => 'Texto libre unchanged <script>', 'reporting_year' => 2025],
        'materiality' => ['confirmed_theme_count' => 0, 'confirmed_themes' => []],
        'datapoints' => ['completion_ratio' => 0, 'blocks' => []], 'limitations' => [],
    ];
    foreach (['es', 'en'] as $locale) {
        app()->setLocale($locale);
        $html = view('reports.preparation-package', ['draft' => $draft, 'locale' => $locale, 'statusLabel' => \App\Support\DisplayLabels::code('ready')])->render();
        expect($html)->toContain('lang="'.$locale.'"', 'Texto libre unchanged &lt;script&gt;');
        expect($html)->toContain($locale === 'es' ? 'Paquete de preparación NEIS 2023' : 'ESRS 2023 preparation package');
        if ($locale === 'en') expect($html)->not->toContain('Sin temas materiales', 'Estado', 'Decididos');
    }
});

it('retains the narrative guard in both report languages and fails closed for missing omission evidence', function () {
    foreach (['es', 'en'] as $locale) {
        $ir = localizedFactualReportIr();
        $ir['disclaimers'] = ['Referencia interna E5-4 no permitida.'];
        expect(fn () => (new HtmlReportRenderer)->render($ir, $locale))->toThrow(DomainException::class);
    }
    $ir = localizedFactualReportIr();
    unset($ir['materiality_trace']);
    $ir['omission_section'] = ['statements' => ['Un tema no fue confirmado como material.']];
    expect(fn () => (new HtmlReportRenderer)->render($ir, 'en'))->toThrow(DomainException::class);
});


it('keeps omission headings neutral in Spanish and English without rewriting the approved trace', function () {
    $ir = localizedFactualReportIr();
    $ir['omission_section'] = ['title' => 'Temas no confirmados como materiales', 'statements' => []];
    $original = $ir;
    foreach (['es' => 'Temas no confirmados como materiales', 'en' => 'Topics not confirmed as material'] as $locale => $title) {
        $display = new ReportDisplayProjection($locale);
        expect($display->narrative($ir)['omission_section']['title'])->toBe($title);
        expect($ir)->toBe($original);
    }
});

it('renders semantic Spanish labels for admitted claims in real HTML DOCX and evidence without changing the IR', function () {
    $catalogue = json_decode(file_get_contents(base_path('data/esrs_datapoint_labels_es_v1.json')), true, 512, JSON_THROW_ON_ERROR);
    $ids = ['E3-4_01', 'SBM-2_11', 'S1-8_01', 'S1-14_01', 'S1-15_02', 'S1-15_03', 'S1-13_03', 'S1-13_04', 'S1-17_02', 'S1-9_03', 'S1-9_04', 'S1-9_05', 'E1-9_24', 'E1-9_25'];
    $ir = localizedFactualReportIr();
    $ir['claims'] = [];
    $ir['chapters'][0]['sections'][0]['blocks'] = [];
    foreach ($ids as $i => $id) {
        $claimId = 'claim_'.$i;
        $ir['claims'][] = ['claim_id' => $claimId, 'fact_id' => 'fact_'.$i, 'datapoint_id' => $id, 'value_type' => 'text', 'value' => ['text' => 'Texto libre unchanged'], 'evidence_refs' => [['type' => 'note', 'value' => '=1+1']]];
        $ir['chapters'][0]['sections'][0]['blocks'][] = ['datapoint_id' => $id, 'claims' => [$claimId], 'slots' => [['node_id' => 'slot_'.$i, 'claim_id' => $claimId, 'fact_id' => 'fact_'.$i, 'xbrl_concept' => null]]];
    }
    $original = $ir;
    $html = (new HtmlReportRenderer)->render($ir, 'es');
    $docx = docxVisibleText((new DocxRenderer)->render($ir, 'es'));
    $evidence = (new EvidenceBundleBuilder)->build($ir, [], 'es');
    foreach ($ids as $i => $id) {
        expect($html)->toContain($catalogue['labels'][$id]);
        expect($docx)->toContain($catalogue['labels'][$id]);
        expect($evidence['display']['claim_labels'][$i]['label'])->toBe($catalogue['labels'][$id]);
    }
    expect($html)->not->toContain('Dato revisado', 'Posibles cambios');
    expect($docx)->not->toContain('Dato revisado', 'Posibles cambios');
    expect($evidence['claims'])->toBe($original['claims']);
    expect($ir)->toBe($original);
});
