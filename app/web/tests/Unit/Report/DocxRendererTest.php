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
