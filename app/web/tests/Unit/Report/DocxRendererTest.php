<?php

use App\Services\Report\DocxRenderer;

function docxEntry(string $bytes, string $name): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $content = $zip->getFromName($name);
    $zip->close();
    unlink($tmp);

    return $content ?: '';
}

it('renders a docx with SDT tags bound to slot node ids and a custom xml map', function () {
    $ir = [
        'version_hash' => str_repeat('b', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2025],
        'disclaimers' => ['No es presentación oficial.'],
        'chapters' => [[
            'title' => 'ESRS E1',
            'sections' => [[
                'dr_key' => 'E1-6', 'cross_refs' => [],
                'blocks' => [[
                    'datapoint_id' => 'E1-6_01', 'name' => 'Alcance 1',
                    'assertions' => ['Material'],
                    'slots' => [['node_id' => 'slot_E1-6_01', 'label' => 'Alcance 1', 'xbrl_concept' => 'esrs:GrossScope1', 'taggable_state' => 'mapped']],
                    'guidance' => ['text' => 'Prepare...', 'provenance_tier' => 'certified_support_rule', 'authoritative' => true],
                ]],
            ]],
        ]],
    ];

    $bytes = (new DocxRenderer())->render($ir);

    $documentXml = docxEntry($bytes, 'word/document.xml');
    expect($documentXml)->toContain('slot_E1-6_01');
    // The slot must be a real SDT structural marker, not merely a text token.
    expect($documentXml)->toContain('<w:sdt>');
    expect($documentXml)->toContain('w:tag w:val="slot_E1-6_01"');

    $customXml = docxEntry($bytes, 'customXml/item1.xml');
    expect($customXml)->toContain('slot_E1-6_01');
    expect($customXml)->toContain('esrs:GrossScope1');
});

it('marks non-authoritative guidance with a visible generic-guidance prefix', function () {
    $ir = [
        'version_hash' => str_repeat('c', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2025],
        'disclaimers' => [],
        'chapters' => [[
            'title' => 'ESRS E2',
            'sections' => [[
                'dr_key' => 'E2-1', 'cross_refs' => [],
                'blocks' => [[
                    'datapoint_id' => 'E2-1_01', 'name' => 'Contaminación',
                    'assertions' => ['Material'],
                    'slots' => [['node_id' => 'slot_E2-1_01', 'label' => 'Contaminación', 'xbrl_concept' => 'esrs:Pollution', 'taggable_state' => 'mapped']],
                    'guidance' => ['text' => 'Texto genérico de orientación.', 'provenance_tier' => 'generic_guidance', 'authoritative' => false],
                ]],
            ]],
        ]],
    ];

    $bytes = (new DocxRenderer())->render($ir);

    $documentXml = docxEntry($bytes, 'word/document.xml');
    expect($documentXml)->toContain('orientación general');
    expect($documentXml)->toContain('Texto genérico de orientación.');
});

it('renders factual labels and values in Spanish without visible internal identifiers', function () {
    $ir = [
        'schema_version' => 'report_ir_v1',
        'version_hash' => str_repeat('c', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2026],
        'disclaimers' => [],
        'claims' => [[
            'claim_id' => 'claim_energy',
            'fact_id' => 'fact_energy',
            'datapoint_id' => 'E1-5_01',
            'value_type' => 'number',
            'value' => ['value' => 86.4],
            'unit' => 'MWh',
            'decimals' => 1,
        ]],
        'chapters' => [[
            'title' => 'Topical datapoints for mapped material Disclosure Requirements',
            'block_key' => 'topical',
            'floor_prose' => 'E9-9 English floor sentinel',
            'sections' => [[
                'dr_key' => 'E1-5',
                'cross_refs' => [],
                'cross_ref_sentences' => ['E9-9 English cross-reference sentinel'],
                'blocks' => [[
                    'datapoint_id' => 'E1-5_01',
                    'name' => 'Información reportada',
                    'assertions' => ['E9-9 English assertion sentinel'],
                    'claims' => ['claim_energy'],
                    'slots' => [[
                        'node_id' => 'slot_E1-5_01_fact_energy',
                        'claim_id' => 'claim_energy',
                        'fact_id' => 'fact_energy',
                        'label' => 'Información reportada',
                        'xbrl_concept' => 'esrs:EnergyConsumption',
                    ]],
                    'guidance' => [
                        'text' => 'E9-9 English guidance sentinel',
                        'authoritative' => true,
                        'citations' => ['E9-9 English citation sentinel'],
                    ],
                ]],
            ]],
        ]],
    ];

    $bytes = (new DocxRenderer())->render($ir);
    $text = docxVisibleText($bytes);
    $documentXml = docxEntry($bytes, 'word/document.xml');

    expect($text)->toContain('Información sobre temas materiales');
    expect($text)->toContain('Consumo y combinación energética');
    expect($text)->toContain('Consumo total de energía en operaciones propias');
    expect($text)->toContain('86,4 MWh');
    expect($text)->not->toContain('E1-5');
    expect($text)->not->toContain('Topical datapoints');
    expect($text)->not->toContain('orientación general');
    expect($text)->not->toContain('E9-9 English');
    expect($documentXml)->toMatch('/<w:p>(?:(?!<\/w:p>).)*<w:keepNext w:val="1"\/>'.
        '(?:(?!<\/w:p>).)*Consumo total de energía en operaciones propias(?:(?!<\/w:p>).)*<\/w:p>/s');
});

it('never renders a banned overclaiming term in the generated document.xml', function () {
    $ir = [
        'version_hash' => str_repeat('d', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2025],
        'disclaimers' => ['No es presentación oficial.'],
        'chapters' => [[
            'title' => 'ESRS E1',
            'sections' => [[
                'dr_key' => 'E1-6', 'cross_refs' => [],
                'blocks' => [[
                    'datapoint_id' => 'E1-6_01', 'name' => 'Alcance 1',
                    'assertions' => ['Material'],
                    'slots' => [['node_id' => 'slot_E1-6_01', 'label' => 'Alcance 1', 'xbrl_concept' => 'esrs:GrossScope1', 'taggable_state' => 'mapped']],
                    'guidance' => ['text' => 'Prepare el desglose por alcance.', 'provenance_tier' => 'certified_support_rule', 'authoritative' => true],
                ]],
            ]],
        ]],
    ];

    $bytes = (new DocxRenderer())->render($ir);

    $documentXml = docxEntry($bytes, 'word/document.xml');

    $bannedTerms = ['iXBRL-ready', 'iXBRL ready', 'filing-ready', 'filing ready', 'official filing'];
    foreach ($bannedTerms as $term) {
        expect($documentXml)->not->toContain($term);
    }
});

it('renders the omission section, chapter prose, cross-refs and citations', function () {
    $ir = [
        'version_hash' => str_repeat('d', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2025],
        'disclaimers' => ['No es presentación oficial.'],
        'omission_section' => [
            'title' => 'Temas evaluados y no considerados materiales',
            'declaration' => 'No consta registro de la propuesta inicial de temas.',
            'limitation' => '1 tema(s) evaluado(s) no pudieron identificarse.',
            'statements' => ['Agua se evaluó y no se consideró material.'],
        ],
        'chapters' => [[
            'title' => 'ESRS E1', 'block_key' => 'topical',
            'floor_prose' => 'Este capítulo cubre Cambio climático.',
            'sections' => [[
                'dr_key' => 'E1-6', 'standard' => 'E1',
                'cross_refs' => [['target_dr' => 'E1-5', 'relation' => 'related', 'resolution' => 'in_scope', 'citation' => 'AR39']],
                'cross_ref_sentences' => ['Véase la sección E1-5 (AR39); no se repite aquí.'],
                'blocks' => [[
                    'datapoint_id' => 'E1-6_01', 'name' => 'Alcance 1', 'assertions' => ['Material'],
                    'slots' => [['node_id' => 'slot_E1-6_01', 'label' => 'Alcance 1', 'xbrl_concept' => 'esrs:GrossScope1', 'taggable_state' => 'mapped']],
                    'guidance' => ['text' => 'Prepare...', 'provenance_tier' => 'certified_support_rule', 'authoritative' => true, 'citations' => ['E1-6:AR39', 'ESRS Set 1 OJ 2023-12-22']],
                ]],
            ]],
        ]],
    ];

    $text = docxVisibleText((new DocxRenderer())->render($ir));

    expect($text)->toContain('Temas evaluados y no considerados materiales');
    expect($text)->toContain('No consta registro de la propuesta inicial de temas.');
    expect($text)->toContain('1 tema(s) evaluado(s) no pudieron identificarse.');
    expect($text)->toContain('Agua se evaluó y no se consideró material.');
    expect($text)->toContain('Este capítulo cubre Cambio climático.');
    expect($text)->toContain('Véase la sección E1-5 (AR39); no se repite aquí.');
    expect($text)->toContain('E1-6:AR39');
    expect($text)->toContain('ESRS Set 1 OJ 2023-12-22');
});

it('renders the omission declaration when the statements key is entirely absent', function () {
    $ir = [
        'version_hash' => str_repeat('f', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2025],
        'disclaimers' => [],
        'omission_section' => [
            'title' => 'Temas evaluados y no considerados materiales',
            'declaration' => 'No consta registro de la propuesta inicial de temas.',
        ],
        'chapters' => [],
    ];

    $text = docxVisibleText((new DocxRenderer())->render($ir));

    expect($text)->toContain('Temas evaluados y no considerados materiales');
    expect($text)->toContain('No consta registro de la propuesta inicial de temas.');
});

it('omits the omission section entirely when there is nothing to declare', function () {
    $ir = [
        'version_hash' => str_repeat('e', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2025],
        'disclaimers' => [],
        'omission_section' => ['title' => 'Temas evaluados y no considerados materiales', 'declaration' => null, 'limitation' => null, 'statements' => []],
        'chapters' => [],
    ];

    expect(docxVisibleText((new DocxRenderer())->render($ir)))
        ->not->toContain('Temas evaluados y no considerados materiales');
});

it('renders approved factual claim values without leaking evidence and maps factual ids in custom xml', function () {
    $ir = [
        'schema_version' => 'report_ir_v1',
        'version_hash' => str_repeat('f', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2025],
        'disclaimers' => [],
        'claims' => [[
            'claim_id' => 'claim_bp1_01_rf_frozen',
            'fact_id' => 'rf_frozen',
            'datapoint_id' => 'BP-1_01',
            'value' => ['text' => 'Frozen approved fact'],
            'evidence_refs' => [['type' => 'note', 'value' => 'Evidence sentinel must never render']],
            'source' => ['table' => 'reporting_facts'],
            'approval_status' => 'reviewed',
        ]],
        'chapters' => [[
            'title' => 'ESRS 2',
            'sections' => [[
                'dr_key' => 'BP-1',
                'cross_refs' => [],
                'blocks' => [[
                    'datapoint_id' => 'BP-1_01',
                    'name' => 'Base general',
                    'assertions' => ['Material'],
                    'claims' => ['claim_bp1_01_rf_frozen'],
                    'slots' => [[
                        'node_id' => 'slot_BP-1_01_rf_frozen',
                        'claim_id' => 'claim_bp1_01_rf_frozen',
                        'fact_id' => 'rf_frozen',
                        'label' => 'Base general',
                        'xbrl_concept' => 'esrs:BasisForPreparation',
                        'taggable_state' => 'mapped',
                    ]],
                    'guidance' => ['text' => 'Prepare...', 'provenance_tier' => 'certified_support_rule', 'authoritative' => true],
                ]],
            ]],
        ]],
    ];

    $bytes = (new DocxRenderer())->render($ir);
    $text = docxVisibleText($bytes);
    $documentXml = docxEntry($bytes, 'word/document.xml');
    $customXml = docxEntry($bytes, 'customXml/item1.xml');

    expect($text)->toContain('Frozen approved fact');
    expect($customXml)->toContain('claim_id="claim_bp1_01_rf_frozen"');
    expect($customXml)->toContain('fact_id="rf_frozen"');
    expect($text)->not->toContain('slot_BP-1_01_rf_frozen');
    expect($text)->not->toContain('claim_bp1_01_rf_frozen');
    expect($text)->not->toContain('rf_frozen');
    expect($text)->not->toContain('Evidence sentinel must never render');
    expect($documentXml)->not->toContain('Evidence sentinel must never render');
    expect($bytes)->not->toContain('Evidence sentinel must never render');
});

it('preserves dollar and backslash literals in DOCX factual slot text', function () {
    $ir = [
        'schema_version' => 'report_ir_v1',
        'version_hash' => str_repeat('f', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2026],
        'disclaimers' => [],
        'claims' => [[
            'claim_id' => 'claim_bp1_literal',
            'fact_id' => 'fact_literal',
            'datapoint_id' => 'BP-1_01',
            'value_type' => 'text',
            'value' => ['text' => 'Importe $100 y ruta \\servidor\\carpeta'],
            'dimensions' => [['axis' => 'esrs:CountryAxis', 'member' => 'esrs:ES']],
        ]],
        'chapters' => [[
            'block_key' => 'always_required',
            'sections' => [[
                'dr_key' => 'BP-1',
                'blocks' => [[
                    'datapoint_id' => 'BP-1_01',
                    'claims' => ['claim_bp1_literal'],
                    'slots' => [[
                        'node_id' => 'slot_literal',
                        'claim_id' => 'claim_bp1_literal',
                        'fact_id' => 'fact_literal',
                        'xbrl_concept' => 'esrs:BasisForPreparation',
                    ]],
                ]],
            ]],
        ]],
    ];

    $text = docxVisibleText((new DocxRenderer())->render($ir));

    expect($text)->toContain('Importe $100 y ruta \\servidor\\carpeta — España');
});

it('fails closed when factual omission prose contains a technical disclosure identifier', function () {
    $ir = [
        'schema_version' => 'report_ir_v1',
        'version_hash' => str_repeat('f', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2026],
        'disclaimers' => [],
        'claims' => [],
        'chapters' => [],
        'omission_section' => [
            'title' => 'Omisiones',
            'statements' => ['Referencia interna E1-5 no permitida.'],
            'declaration' => null,
            'limitation' => null,
        ],
    ];

    expect(fn () => (new DocxRenderer())->render($ir))
        ->toThrow(\DomainException::class);
});

it('renders factual omissions once as a final appendix after the report body', function () {
    $ir = [
        'schema_version' => 'report_ir_v1',
        'version_hash' => str_repeat('f', 64),
        'company' => ['name' => 'ACME', 'reporting_year' => 2026],
        'disclaimers' => ['No es presentación oficial.'],
        'claims' => [[
            'claim_id' => 'claim_energy',
            'fact_id' => 'fact_energy',
            'datapoint_id' => 'E1-5_01',
            'value_type' => 'number',
            'value' => 86.4,
            'unit' => 'MWh',
            'decimals' => 1,
        ]],
        'omission_section' => [
            'title' => 'Temas evaluados y no considerados materiales',
            'declaration' => null,
            'limitation' => null,
            'statements' => ['Agua no fue confirmado como material.'],
        ],
        'chapters' => [
            [
                'title' => 'General',
                'block_key' => 'general',
                'sections' => [],
            ],
            [
                'title' => 'Topical',
                'block_key' => 'topical',
                'sections' => [[
                    'dr_key' => 'E1-5',
                    'blocks' => [[
                        'datapoint_id' => 'E1-5_01',
                        'claims' => ['claim_energy'],
                        'slots' => [[
                            'node_id' => 'slot_energy',
                            'claim_id' => 'claim_energy',
                            'fact_id' => 'fact_energy',
                            'xbrl_concept' => 'esrs:EnergyConsumption',
                        ]],
                    ]],
                ]],
            ],
        ],
    ];

    $bytes = (new DocxRenderer())->render($ir);
    $text = docxVisibleText($bytes);
    $documentXml = docxEntry($bytes, 'word/document.xml');

    expect($text)->toContain('Anexo: Temas evaluados y no considerados materiales');
    expect(substr_count($text, 'Temas evaluados y no considerados materiales'))->toBe(1);
    expect(strpos($text, 'Información sobre temas materiales'))
        ->toBeLessThan(strpos($text, 'Anexo: Temas evaluados y no considerados materiales'));
    expect(substr_count($documentXml, 'w:type="page"'))->toBe(2);
});
