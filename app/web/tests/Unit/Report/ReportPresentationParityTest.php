<?php

use App\Services\Report\DocxRenderer;
use App\Services\Report\HtmlReportRenderer;
use App\Services\Report\XhtmlIxbrlCandidateRenderer;
use Tests\Support\ReportScopeFixture as F;

uses(Tests\TestCase::class);

it('preserves explicit ordered approved content in parsed HTML DOCX and XHTML', function () {
    $ir = F::presentationIr();
    $original = serialize($ir);
    $html = app(HtmlReportRenderer::class)->render($ir);
    $xhtml = app(XhtmlIxbrlCandidateRenderer::class)->renderPresentation($ir);
    $docx = app(DocxRenderer::class)->render($ir);
    $expected = [
        'Entidad sintética & <prueba> — Ejercicio 2025',
        'Borrador factual basado en una versión aprobada',
        'Contenido sintético de prueba.',
        'Información general de la empresa',
        'Bases de preparación',
        'Bases para la elaboración del estado de sostenibilidad',
        'Narrativa sintética <script>alert(1)</script> & texto',
        'Referencia de evidencia: https://example.invalid/<evidencia>',
        'Estrategia, modelo de negocio y productos o servicios',
        'Ingresos totales',
        '-1.234,567 EUR — custom: A & B; zona: Norte <sur>',
        'Explicación sobre cambio climático',
        'Explicación climática sintética <conservada>.',
        'Anexo: decisiones revisadas fuera del contenido afirmativo',
        'Ingresos totales',
        'Aplicabilidad: No aplicable',
        'Valor registrado: 99 EUR — segmento: Excluido',
        'Motivo sin valor: Fuera del alcance sintético.',
        'Bases para la elaboración del estado de sostenibilidad',
        'Aplicabilidad: No disponible',
        'Valor registrado: Sin valor',
        'Motivo sin valor: Dato no disponible & pendiente.',
        'Anexo: Temas no confirmados como materiales',
        'Declaración sintética de omisión.',
        'Omisión sintética conservada.',
    ];
    foreach ([$html, $xhtml] as $index => $bytes) {
        $dom = new DOMDocument;
        if ($index === 0) { @$dom->loadHTML('<?xml encoding="UTF-8">'.$bytes, LIBXML_NONET); }
        else expect($dom->loadXML($bytes, LIBXML_NONET))->toBeTrue();
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[local-name()="body"]//*[local-name()="h1" or local-name()="h2" or local-name()="h3" or local-name()="h4" or local-name()="p"]');
        $text = [];
        foreach ($nodes as $node) $text[] = $node->textContent;
        expect($text)->toBe($expected);
        expect($xpath->query('//*[local-name()="script" or local-name()="a" or local-name()="nonFraction"]')->length)->toBe(0);
    }
    $path = F::scratch('parity-docx-');
    file_put_contents($path, $docx);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $dom = new DOMDocument;
    expect($dom->loadXML($zip->getFromName('word/document.xml'), LIBXML_NONET))->toBeTrue();
    $zip->close();
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $text = [];
    foreach ($xp->query('//w:body/w:p') as $paragraph) {
        $line = '';
        foreach ($xp->query('.//w:t', $paragraph) as $node) $line .= $node->textContent;
        if ($line !== '') $text[] = $line;
    }
    expect($text)->toBe($expected)->and(serialize($ir))->toBe($original);
});

it('renders a narrative-only presentation with no contexts units or tags', function () {
    $ir = F::presentationIr();
    array_pop($ir['claims']);
    array_pop($ir['chapters'][0]['sections']);
    $ir['fact_decisions'] = [];
    $ir['company'] = ['name' => 'Synthetic narrative only'];
    $xml = app(XhtmlIxbrlCandidateRenderer::class)->renderPresentation($ir);
    expect($xml)->toContain('Narrativa sintética', 'Explicación climática', 'Omisión sintética')
        ->not->toContain('ix:nonFraction', 'xbrli:context', 'xbrli:unit');
});

it('preserves reviewed IRO context in every coherent format without changing approved content', function (string $locale) {
    $ir = F::presentationIr();
    unset($ir['omission_section']);
    $baseline = F::presentationText(app(HtmlReportRenderer::class)->render($ir, $locale), 'html');
    $ir['iro_context_v1'] = ['cards' => [[
        'review' => [
            'description' => 'Descripción IRO sintética <script>alert(2)</script> & contexto',
            'iro_id' => 'iro_synthetic', 'topic_id' => 'E2-1',
            'source_sha256' => str_repeat('a', 64), 'source_row' => 2, 'p8_revision' => 3,
        ],
        'links' => [
            ['esrs' => 'E2', 'dr' => 'E2.IRO-1', 'datapoint_id' => 'E2.IRO-1_01'],
            ['esrs' => 'E2', 'dr' => 'E2-1', 'datapoint_id' => 'E2-1_01'],
        ],
    ]]];
    $original = serialize($ir);
    $expected = [
        $locale === 'en' ? 'Reviewed IRO context' : 'Contexto IRO revisado',
        $locale === 'en' ? 'All ESRS obligations are preserved' : 'Se mantienen todas las obligaciones NEIS',
        'Descripción IRO sintética <script>alert(2)</script> & contexto',
        'IRO iro_synthetic / AR16 E2-1',
        'E2 / E2.IRO-1 / E2.IRO-1_01',
        'E2 / E2-1 / E2-1_01',
        ($locale === 'en' ? 'Source SHA-256: ' : 'Fuente SHA-256: ').str_repeat('a', 64)
            .($locale === 'en' ? ' / row 2 / P8 revision 3' : ' / fila 2 / revisión P8 3'),
    ];
    $texts = [
        F::presentationText(app(HtmlReportRenderer::class)->render($ir, $locale), 'html'),
        F::presentationText(app(DocxRenderer::class)->render($ir, $locale), 'docx'),
        F::presentationText(app(XhtmlIxbrlCandidateRenderer::class)->renderPresentation($ir, $locale), 'xhtml'),
    ];
    foreach ($texts as $text) {
        // Context follows the title, factual notice and disclaimer, before the report chapters.
        expect(array_splice($text, 3, count($expected)))->toBe($expected);
        expect($text)->toBe($baseline);
    }
    expect(serialize($ir))->toBe($original);

    $ir['iro_context_v1'] = ['cards' => []];
    foreach ([
        F::presentationText(app(HtmlReportRenderer::class)->render($ir, $locale), 'html'),
        F::presentationText(app(DocxRenderer::class)->render($ir, $locale), 'docx'),
        F::presentationText(app(XhtmlIxbrlCandidateRenderer::class)->renderPresentation($ir, $locale), 'xhtml'),
    ] as $text) {
        expect($text)->toBe($baseline);
    }
})->with(['es', 'en']);

it('uses captured English labels and a safe absent-label policy in every coherent format', function () {
    $ir = F::presentationIr();
    array_pop($ir['claims']);
    array_pop($ir['chapters'][0]['sections']);
    unset($ir['omission_section']);
    $ir['display_labels'] = ['en' => ['BP-1_01' => 'Synthetic captured label <preserved>']];
    $ir['fact_decisions'] = [$ir['fact_decisions'][0]];
    $original = serialize($ir);
    $texts = [
        F::presentationText(app(HtmlReportRenderer::class)->render($ir, 'en'), 'html'),
        F::presentationText(app(DocxRenderer::class)->render($ir, 'en'), 'docx'),
        F::presentationText(app(XhtmlIxbrlCandidateRenderer::class)->renderPresentation($ir, 'en'), 'xhtml'),
    ];
    expect($texts[1])->toBe($texts[0])->and($texts[2])->toBe($texts[0]);
    expect($texts[0])->toContain('Synthetic captured label <preserved>', 'Label unavailable',
        'Recorded value: 99 EUR — segmento: Excluido', 'Reason for no value: Fuera del alcance sintético.');
    expect(serialize($ir))->toBe($original);
});

it('fails closed on malformed dimensions and XML-incompatible approved strings', function (mixed $dimensions, string $text, string $exception, string $reason) {
    $ir = F::presentationIr();
    $ir['claims'][0]['dimensions'] = $dimensions;
    $ir['claims'][0]['value'] = ['text' => $text];
    expect(fn () => app(XhtmlIxbrlCandidateRenderer::class)->renderPresentation($ir))->toThrow($exception, $reason);
})->with([
    'non-list dimensions' => [['axis' => 'bad'], 'Synthetic', RuntimeException::class, 'Unsupported factual dimension shape.'],
    'non-string axis' => [[['axis' => [], 'member' => 'bad']], 'Synthetic', RuntimeException::class, 'Unsupported factual dimension shape.'],
    'XML control character' => [[], "Synthetic\x01text", DomainException::class, 'approved_snapshot_invalid'],
]);

it('keeps calendar units and recognized versus free-text dimensions consistent in the projection', function () {
    $ir = F::presentationIr();
    $ir['claims'][1] = array_replace($ir['claims'][1], ['value_type' => 'integer', 'value' => ['value' => 2025], 'unit' => 'year', 'dimensions' => []]);
    $events = (new \App\Services\Report\ReportDisplayProjection)->ordered($ir);
    $values = array_values(array_filter($events, fn ($event) => $event['kind'] === 'value'));
    expect($values[1]['text'])->toBe('2025')->and($values[1]['numeric_text'])->toBe('2025');
    expect(\App\Services\Report\ReportVisiblePresentation::withVisibleDimensions('1', [
        ['axis' => 'gender', 'member' => 'women'], ['axis' => 'gender', 'member' => 'Texto libre'],
    ], 'en', true))->toBe('1 — gender: Texto libre; Women');
});
