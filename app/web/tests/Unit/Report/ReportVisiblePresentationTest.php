<?php

use App\Services\Report\ReportVisiblePresentation;
use App\Services\Report\ReportVisibleCorpusLabels;

it('uses neutral Spanish labels for unknown technical identifiers', function () {
    expect(ReportVisiblePresentation::chapterTitle('future_block'))->toBe('Información revisada');
    expect(ReportVisiblePresentation::sectionTitle('FUTURE-1'))->toBe('Información revisada');
    expect(ReportVisiblePresentation::claimLabel('FUTURE-1_01'))->toBe('Dato revisado');
    expect(ReportVisiblePresentation::claimLabel('FUTURE-1_01', ['value_type' => 'text']))
        ->toBe('Declaración revisada');
    expect(ReportVisiblePresentation::claimLabel('FUTURE-1_02', ['value_type' => 'monetary']))
        ->toBe('Importe revisado');
});

it('rejects technical identifiers and English corpus titles in controlled visible prose', function () {
    expect(fn () => ReportVisiblePresentation::controlledNarrative('Referencia E1-5 no permitida'))
        ->toThrow(\DomainException::class);
    expect(fn () => ReportVisiblePresentation::controlledNarrative('Topical datapoints for Disclosure Requirements'))
        ->toThrow(\DomainException::class);
    expect(fn () => ReportVisiblePresentation::controlledNarrative('Prueba E2E del modelo SaaS en cloud con hardware y software.'))
        ->toThrow(\DomainException::class);
    expect(fn () => ReportVisiblePresentation::controlledNarrative('Borrador basado en snapshot para filing.'))
        ->toThrow(\DomainException::class);
    expect(ReportVisiblePresentation::controlledNarrative('Texto empresarial revisado.'))
        ->toBe('Texto empresarial revisado.');
});

it('formats factual values in Spanish with units and booleans', function () {
    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'number',
        'value' => ['value' => 1234.5],
        'decimals' => 1,
        'unit' => 'MWh',
    ]))->toBe('1.234,5 MWh');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'number',
        'value' => 1234.5,
        'decimals' => 1,
        'unit' => 'MWh',
    ]))->toBe('1.234,5 MWh');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'boolean',
        'value' => true,
    ]))->toBe('Sí');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 2023,
        'decimals' => 0,
        'unit' => 'año',
    ]))->toBe('2023');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'number',
        'value' => 6.5,
        'decimals' => 1,
        'unit' => 'tCO2e/MUSD',
    ]))->toBe('6,5 toneladas de CO₂ equivalente por millón de USD');
});

it('uses business-readable Spanish labels for E5 and S1 factual disclosures', function () {
    expect(ReportVisiblePresentation::sectionTitle('E5-5'))->toBe('Salidas de recursos y residuos');
    expect(ReportVisiblePresentation::sectionTitle('S1-14'))->toBe('Salud y seguridad laboral');
    expect(ReportVisiblePresentation::claimLabel('E5-5_11'))->toBe('Porcentaje de residuos no reciclados');
    expect(ReportVisiblePresentation::claimLabel('S1-16_02'))->toBe('Relación entre la remuneración máxima y la mediana');
});

it('uses business-readable Spanish labels across the multi-standard report', function () {
    expect(ReportVisiblePresentation::sectionTitle('E1-6'))->toBe('Emisiones brutas de gases de efecto invernadero');
    expect(ReportVisiblePresentation::sectionTitle('S2-4'))->toBe('Acciones sobre los trabajadores de la cadena de valor');
    expect(ReportVisiblePresentation::sectionTitle('S4-3'))->toBe('Canales para consumidores y usuarios finales');
    expect(ReportVisiblePresentation::sectionTitle('G1-3'))->toBe('Prevención y detección de corrupción y soborno');
    expect(ReportVisiblePresentation::sectionTitle('MDR-T'))->toBe('Objetivos y seguimiento del progreso');

    expect(ReportVisiblePresentation::claimLabel('E1-6_12'))->toBe('Emisiones totales de gases de efecto invernadero según ubicación');
    expect(ReportVisiblePresentation::claimLabel('S2-4_10'))->toBe('Medidas para evitar que las prácticas propias causen o contribuyan a impactos negativos materiales');
    expect(ReportVisiblePresentation::claimLabel('S4-3_13'))->toBe('Número de reclamaciones recibidas de consumidores y usuarios finales');
    expect(ReportVisiblePresentation::claimLabel('G1-3_07'))->toBe('Porcentaje de funciones de riesgo cubiertas por programas de formación');
    expect(ReportVisiblePresentation::claimLabel('MDR-T_02'))->toBe('Objetivo medible');
});

it('uses business-readable Spanish headings for every active services-report section', function () {
    $headings = [
        'BP-2' => 'Circunstancias específicas de preparación',
        'GOV-1' => 'Función y composición de los órganos de gobierno',
        'GOV-2' => 'Información y asuntos de sostenibilidad tratados por los órganos de gobierno',
        'GOV-4' => 'Declaración sobre diligencia debida',
        'SBM-2' => 'Intereses y opiniones de las partes interesadas',
        'SBM-3' => 'Impactos, riesgos y oportunidades materiales y su relación con la estrategia',
        'IRO-1' => 'Proceso para identificar y evaluar impactos, riesgos y oportunidades materiales',
        'IRO-2' => 'Requisitos de divulgación cubiertos por el informe',
        'E1.SBM-3' => 'Impactos, riesgos y oportunidades climáticos y su relación con la estrategia',
        'E1-2' => 'Políticas de mitigación y adaptación al cambio climático',
        'E1-3' => 'Acciones y recursos climáticos',
        'E5.IRO-1' => 'Proceso para identificar impactos, riesgos y oportunidades sobre circularidad',
        'E5-1' => 'Políticas de uso de recursos y economía circular',
        'E5-2' => 'Acciones y recursos para la economía circular',
        'E5-3' => 'Objetivos de uso de recursos y economía circular',
        'S1-5' => 'Objetivos relativos al personal propio',
        'G1-2' => 'Gestión de las relaciones con proveedores',
        'G1-4' => 'Casos de corrupción o soborno',
        'G1-5' => 'Influencia política y actividades de representación',
        'G1-6' => 'Prácticas de pago',
        'MDR-P' => 'Políticas para gestionar temas materiales',
        'MDR-M' => 'Métricas para medir temas materiales',
    ];

    foreach ($headings as $section => $expected) {
        expect(ReportVisiblePresentation::sectionTitle($section))->toBe($expected);
    }
});

it('uses specific business labels instead of generic reviewed-value fallbacks', function () {
    expect(ReportVisiblePresentation::claimLabel('BP-2_01'))
        ->toBe('Definición de los horizontes temporales a medio y largo plazo');
    expect(ReportVisiblePresentation::claimLabel('E1-2_01'))
        ->toBe('Temas climáticos cubiertos por la política');
    expect(ReportVisiblePresentation::claimLabel('E5-1_01'))
        ->toBe('Transición desde recursos vírgenes hacia recursos secundarios');
    expect(ReportVisiblePresentation::claimLabel('S1-5_04'))
        ->toBe('Resultados previstos que se pretenden lograr en la vida de las personas de la plantilla propia');
    expect(ReportVisiblePresentation::claimLabel('G1-4_02'))
        ->toBe('Multas por infracciones relacionadas con corrupción o soborno');
    expect(ReportVisiblePresentation::claimLabel('MDR-P_01'))
        ->toBe('Contenido principal de la política');
    expect(ReportVisiblePresentation::claimLabel('MDR-M_01'))
        ->toBe('Métrica utilizada para evaluar el rendimiento y la eficacia respecto al impacto, riesgo u oportunidad material');
});

it('keeps the reviewed corpus label map complete and free of visible technical fallbacks', function () {
    $labels = (new ReflectionClass(ReportVisibleCorpusLabels::class))
        ->getConstant('CLAIM_LABELS_ES');

    expect($labels)->toHaveCount(155);
    expect(array_unique($labels))->toHaveCount(154);

    foreach ($labels as $datapointId => $label) {
        expect(trim($label))->not->toBe('');
        expect(mb_strlen($label))->toBeLessThanOrEqual(140);
        expect(str_contains($label, $datapointId))->toBeFalse();
        expect(preg_match('/Información revisada|Dato revisado|Declaración revisada|Indicador numérico revisado|Importe revisado|Recuento revisado|\b(?:ESRS|MDR|SBM|IRO|GOV|filing|cloud|hardware|software)\b|_/iu', $label))->toBe(0);
    }
});

it('localizes technical units and agrees them with the numeric value', function () {
    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 1,
        'decimals' => 0,
        'unit' => 'accidentes_registrables',
    ]))->toBe('1 accidente registrable');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 2,
        'decimals' => 0,
        'unit' => 'personas',
    ]))->toBe('2 personas');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'number',
        'value' => 2.1,
        'decimals' => 1,
        'unit' => 'ratio',
    ]))->toBe('2,1 veces');
});

it('renders dimensional waste facts with business-readable context', function () {
    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'number',
        'value' => 0.8,
        'decimals' => 2,
        'unit' => 't',
        'dimensions' => [
            ['axis' => 'hazard_class', 'member' => 'non_hazardous'],
            ['axis' => 'treatment_type', 'member' => 'recycling_composting'],
            ['axis' => 'waste_stream', 'member' => 'plant_residues'],
        ],
    ]))->toBe('0,80 t — Restos vegetales; no peligroso; compostaje');
});

it('renders workforce dimensions with business-readable Spanish context', function () {
    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 96,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'gender', 'member' => 'men'],
        ],
    ]))->toBe('96 personas — Hombres');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 138,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'contract_type', 'member' => 'permanent'],
        ],
    ]))->toBe('138 personas — Contrato indefinido');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 150,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'country', 'member' => 'Germany'],
        ],
    ]))->toBe('150 personas — Alemania');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'text',
        'value' => ['text' => 'Operaciones cubiertas'],
        'dimensions' => [
            ['axis' => 'esrs:CountryAxis', 'member' => 'esrs:ES'],
        ],
    ]))->toBe('Operaciones cubiertas — España');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 42,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'esrs:CountryAxis', 'member' => 'esrs:DE'],
        ],
    ]))->toBe('42 personas — Alemania');
});

it('renders the service profile waste dimensions with business-readable labels', function () {
    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'number',
        'value' => 8.5,
        'decimals' => 1,
        'unit' => 't',
        'dimensions' => [
            ['axis' => 'tipo de residuo', 'member' => 'Residuos electrónicos'],
        ],
    ]))->toBe('8,5 t — Residuos electrónicos');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'number',
        'value' => 10.5,
        'decimals' => 1,
        'unit' => 't',
        'dimensions' => [
            ['axis' => 'tipo de residuo', 'member' => 'Otros residuos reciclables'],
        ],
    ]))->toBe('10,5 t — Otros residuos reciclables');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'number',
        'value' => 3,
        'decimals' => 1,
        'unit' => 't',
        'dimensions' => [
            ['axis' => 'tipo de residuo', 'member' => 'Residuos residuales'],
        ],
    ]))->toBe('3,0 t — Residuos residuales');
});

it('renders the service profile workforce dimensions without exposing technical tokens', function () {
    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 226,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'Género', 'member' => 'Hombres'],
        ],
    ]))->toBe('226 personas — Hombres');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 210,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'Contrato y género', 'member' => 'Permanente - hombres'],
        ],
    ]))->toBe('210 personas — Contrato indefinido; Hombres');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 23,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'Género en dirección', 'member' => 'Hombres'],
        ],
    ]))->toBe('23 personas — Hombres');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 280,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'País', 'member' => 'Estados Unidos'],
        ],
    ]))->toBe('280 personas — Estados Unidos');

    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 280,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'Región', 'member' => 'Norteamérica'],
        ],
    ]))->toBe('280 personas — Norteamérica');
});

it('renders every persisted service-profile dimension pair through an explicit allowlist', function () {
    $pairs = [
        ['tipo de residuo', 'Residuos electrónicos', 'Residuos electrónicos'],
        ['tipo de residuo', 'Otros residuos reciclables', 'Otros residuos reciclables'],
        ['tipo de residuo', 'Residuos residuales', 'Residuos residuales'],
        ['Género', 'Hombres', 'Hombres'],
        ['Género', 'Mujeres', 'Mujeres'],
        ['Género', 'No binario', 'Personas no binarias'],
        ['Género en dirección', 'Hombres', 'Hombres'],
        ['Género en dirección', 'Mujeres', 'Mujeres'],
        ['Género en dirección', 'No binario', 'Personas no binarias'],
        ['Contrato y género', 'Permanente - hombres', 'Contrato indefinido; Hombres'],
        ['Contrato y género', 'Permanente - mujeres', 'Contrato indefinido; Mujeres'],
        ['Contrato y género', 'Permanente - no binario', 'Contrato indefinido; Personas no binarias'],
        ['Contrato y género', 'Temporal - hombres', 'Contrato temporal; Hombres'],
        ['Contrato y género', 'Temporal - mujeres', 'Contrato temporal; Mujeres'],
        ['Contrato y género', 'Temporal - no binario', 'Contrato temporal; Personas no binarias'],
        ['País', 'Estados Unidos', 'Estados Unidos'],
        ['País', 'Alemania', 'Alemania'],
        ['País', 'Brasil', 'Brasil'],
        ['País', 'Bulgaria', 'Bulgaria'],
        ['País', 'España', 'España'],
        ['País', 'Irlanda', 'Irlanda'],
        ['País', 'Rumanía', 'Rumanía'],
        ['Región', 'Norteamérica', 'Norteamérica'],
        ['Región', 'Unión Europea', 'Unión Europea'],
    ];

    foreach ($pairs as [$axis, $member, $visibleLabel]) {
        expect(ReportVisiblePresentation::claimValue([
            'value_type' => 'integer',
            'value' => 1,
            'decimals' => 0,
            'unit' => 'personas',
            'dimensions' => [
                ['axis' => $axis, 'member' => $member],
            ],
        ]))->toBe('1 persona — '.$visibleLabel);
    }
});

it('fails closed when an allowlisted member is paired with the wrong axis', function () {
    expect(fn () => ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 1,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'País', 'member' => 'Hombres'],
        ],
    ]))->toThrow(RuntimeException::class, 'Unsupported factual dimension label.');

    expect(fn () => ReportVisiblePresentation::claimValue([
        'value_type' => 'integer',
        'value' => 1,
        'decimals' => 0,
        'unit' => 'personas',
        'dimensions' => [
            ['axis' => 'País', 'member' => 'Hombres '],
        ],
    ]))->toThrow(RuntimeException::class, 'Unsupported factual dimension label.');
});

it('fails closed for malformed or unknown dimensions on numeric and textual facts', function () {
    expect(fn () => ReportVisiblePresentation::claimValue([
        'value_type' => 'decimal',
        'value' => 1,
        'unit' => 't',
        'decimals' => 0,
        'dimensions' => 'not-an-array',
    ]))->toThrow(RuntimeException::class, 'Unsupported factual dimension shape.');

    expect(fn () => ReportVisiblePresentation::claimValue([
        'value_type' => 'text',
        'value' => ['text' => 'Descripción'],
        'dimensions' => [
            ['axis' => 'unknown_axis', 'member' => 'unknown_member'],
        ],
    ]))->toThrow(RuntimeException::class, 'Unsupported factual dimension label.');

    expect(fn () => ReportVisiblePresentation::claimValue([
        'nil' => true,
        'value' => null,
        'dimensions' => 'not-an-array',
    ]))->toThrow(RuntimeException::class, 'Unsupported factual dimension shape.');

    expect(fn () => ReportVisiblePresentation::claimValue([
        'value' => null,
        'dimensions' => [
            ['axis' => 'unknown_axis', 'member' => 'unknown_member'],
        ],
    ]))->toThrow(RuntimeException::class, 'Unsupported factual dimension label.');
});

it('renders valid dimensions on textual facts instead of dropping their context', function () {
    expect(ReportVisiblePresentation::claimValue([
        'value_type' => 'text',
        'value' => ['text' => 'Gestión separada'],
        'dimensions' => [
            ['axis' => 'waste_stream', 'member' => 'used_oil'],
            ['axis' => 'hazard_class', 'member' => 'hazardous'],
        ],
    ]))->toBe('Gestión separada — Aceite usado; peligroso');
});
