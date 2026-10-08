<?php

use App\Services\Report\ReportVisiblePresentation;
use App\Services\Report\ReportVisibleCorpusLabels;

it('uses neutral Spanish labels for unknown technical identifiers', function () {
    expect(ReportVisiblePresentation::chapterTitle('future_block'))->toBe('Información revisada');
    expect(ReportVisiblePresentation::sectionTitle('FUTURE-1'))->toBe('Título no disponible');
    expect(ReportVisiblePresentation::claimLabel('FUTURE-1_01'))->toBe('Etiqueta no disponible');
    expect(ReportVisiblePresentation::claimLabel('FUTURE-1_01', ['value_type' => 'text']))
        ->toBe('Etiqueta no disponible');
    expect(ReportVisiblePresentation::claimLabel('FUTURE-1_02', ['value_type' => 'monetary']))
        ->toBe('Etiqueta no disponible');
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
    expect(ReportVisiblePresentation::claimLabel('S1-16_02'))->toBe('Ratio de remuneración total anual');
});

it('uses business-readable Spanish labels across the multi-standard report', function () {
    expect(ReportVisiblePresentation::sectionTitle('E1-6'))->toBe('Emisiones brutas de gases de efecto invernadero');
    expect(ReportVisiblePresentation::sectionTitle('S2-4'))->toBe('Acciones sobre los trabajadores de la cadena de valor');
    expect(ReportVisiblePresentation::sectionTitle('S4-3'))->toBe('Canales para consumidores y usuarios finales');
    expect(ReportVisiblePresentation::sectionTitle('G1-3'))->toBe('Prevención y detección de corrupción y soborno');
    expect(ReportVisiblePresentation::sectionTitle('MDR-T'))->toBe('Objetivos y seguimiento del progreso');

    expect(ReportVisiblePresentation::claimLabel('E1-6_12'))->toBe('Emisiones totales de GEI según la ubicación');
    expect(ReportVisiblePresentation::claimLabel('S2-4_10'))->toBe('Prevención de impactos negativos materiales de las prácticas propias sobre trabajadores de la cadena de valor');
    expect(ReportVisiblePresentation::claimLabel('S4-3_13'))->toBe('Número de reclamaciones recibidas de consumidores o usuarios finales durante el periodo');
    expect(ReportVisiblePresentation::claimLabel('G1-3_07'))->toBe('Porcentaje de funciones de riesgo cubiertas por programas de formación');
    expect(ReportVisiblePresentation::claimLabel('MDR-T_02'))->toBe('Meta medible');
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
        'IRO-2' => 'Requisitos de información cubiertos por el informe',
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
        'G1-5' => 'Influencia política y actividades de presión política',
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
        ->toBe('Definiciones de los horizontes temporales a medio y largo plazo');
    expect(ReportVisiblePresentation::claimLabel('E1-2_01'))
        ->toBe('Cuestiones de sostenibilidad abordadas por la política climática');
    expect(ReportVisiblePresentation::claimLabel('E5-1_01'))
        ->toBe('Si la política aborda y cómo la transición para dejar de utilizar recursos vírgenes, incluidos los aumentos relativos en el uso de recursos secundarios reciclados');
    expect(ReportVisiblePresentation::claimLabel('S1-5_04'))
        ->toBe('Resultados previstos en la vida del personal propio');
    expect(ReportVisiblePresentation::claimLabel('G1-4_02'))
        ->toBe('Importe de multas por vulnerar la legislación contra la corrupción y el soborno');
    expect(ReportVisiblePresentation::claimLabel('MDR-P_01'))
        ->toBe('Contenido principal de la política');
    expect(ReportVisiblePresentation::claimLabel('MDR-M_01'))
        ->toBe('Indicador para evaluar el desempeño y la eficacia respecto a un impacto, riesgo u oportunidad material');
});

it('keeps the reviewed corpus label map complete and free of visible technical fallbacks', function () {
    $labels = json_decode(file_get_contents(__DIR__.'/../../../data/esrs_datapoint_labels_es_v1.json'), true, 512, JSON_THROW_ON_ERROR)['labels'];

    $originalLabels = [
        'BP-2_01' => 'Definiciones de los horizontes temporales a medio y largo plazo',
        'BP-2_21' => 'Temas E4, S1, S2, S3 y S4 evaluados como materiales',
        'BP-2_22' => 'Cuestiones de sostenibilidad evaluadas como materiales durante la aplicación gradual',
        'BP-2_23' => 'Cómo el modelo de negocio y la estrategia tienen en cuenta los impactos relacionados con cuestiones de sostenibilidad evaluadas como materiales durante la aplicación gradual',
        'BP-2_24' => 'Objetivos con plazos definidos para las cuestiones materiales y avances logrados durante la aplicación gradual',
        'BP-2_25' => 'Políticas relativas a las cuestiones de sostenibilidad materiales durante la aplicación gradual',
        'BP-2_26' => 'Actuaciones realizadas para identificar, supervisar, prevenir, mitigar, reparar o poner fin a impactos negativos reales o potenciales relacionados con cuestiones de sostenibilidad evaluadas como materiales durante la aplicación gradual y resultados de esas actuaciones',
        'BP-2_27' => 'Indicadores de las cuestiones de sostenibilidad materiales durante la aplicación gradual',
        'E1-1_01' => 'Plan de transición para la mitigación del cambio climático',
        'E1-1_03' => 'Palancas de descarbonización y actuaciones principales',
        'E1-1_04' => 'Gastos operativos y gastos de capital significativos necesarios para el plan de actuación',
        'E1-1_13' => 'Integración del plan de transición en la estrategia empresarial y la planificación financiera',
        'E1-1_14' => 'Aprobación del plan de transición por los órganos de administración, dirección y supervisión',
        'E1-2_01' => 'Cuestiones de sostenibilidad abordadas por la política climática',
        'E1-3_01' => 'Tipo de palanca de descarbonización',
        'E1-3_05' => 'Dependencia de la ejecución de las actuaciones respecto a la disponibilidad y asignación de recursos',
        'E1-4_02' => 'Tabla de año base, metas, tipos de GEI, categorías de alcance 3, palancas de descarbonización y denominadores de intensidad',
        'E1-4_18' => 'Coherencia de las metas de reducción con los límites del inventario de GEI',
        'E1-4_23' => 'Palancas de descarbonización previstas y contribución cuantitativa a la meta de reducción de GEI',
        'E1-5_07' => 'Consumo de electricidad, calor, vapor y refrigeración adquiridos de fuentes renovables',
        'E1-6_01' => 'Emisiones brutas de GEI de alcances 1, 2 y 3 y totales, por alcance [tabla]',
        'E1-6_02' => 'Emisiones brutas de GEI por control financiero y operativo [tabla]',
        'E1.MDR-A_01-12' => 'Actuaciones y recursos para la mitigación y adaptación al cambio climático [véase NEIS 2 MDR-A]',
        'E1.MDR-P_01-06' => 'Políticas sobre impactos, riesgos y oportunidades de mitigación y adaptación al cambio climático [véase NEIS 2 MDR-P]',
        'E1.MDR-T_01-13' => 'Seguimiento de la eficacia de políticas y actuaciones mediante metas [véase NEIS 2 MDR-T]',
        'E1.SBM-3_02' => 'Alcance del análisis de resiliencia',
        'E5-1_01' => 'Si la política aborda y cómo la transición para dejar de utilizar recursos vírgenes, incluidos los aumentos relativos en el uso de recursos secundarios reciclados',
        'E5-1_03' => 'Si la política aborda y cómo la jerarquía de residuos: prevención, preparación para la reutilización, reciclado, otras formas de valorización y eliminación',
        'E5-1_04' => 'Prioridad de la prevención y minimización de residuos frente a su tratamiento',
        'E5-2_02' => 'Mayores tasas de utilización de materias primas secundarias',
        'E5-2_04' => 'Aplicación de prácticas empresariales circulares',
        'E5-2_05' => 'Actuaciones para prevenir residuos en la cadena de valor anterior y posterior',
        'E5-2_06' => 'Optimización de la gestión de residuos',
        'E5-2_07' => 'Colaboraciones e iniciativas colectivas para aumentar la circularidad de productos y materiales',
        'E5-2_08' => 'Contribución a la economía circular',
        'E5-3_01' => 'Relación de la meta con el uso de recursos y la economía circular',
        'E5-3_06' => 'Vinculación de la meta con la gestión de residuos',
        'E5-3_07' => 'Relación de la meta con la gestión de residuos',
        'E5-3_08' => 'Relación de la meta con otras cuestiones de uso de recursos o economía circular',
        'E5-3_09' => 'Nivel de la jerarquía de residuos al que se refiere la meta',
        'E5-4_06' => 'Metodologías de cálculo de datos e hipótesis principales',
        'E5-5_18' => 'Participación en la gestión de residuos al final de la vida útil del producto',
        'E5.IRO-1_01' => 'Si la empresa ha analizado sus activos y actividades para identificar impactos reales y potenciales, riesgos y oportunidades en las operaciones propias y en los tramos anteriores y posteriores de la cadena de valor y, en caso afirmativo, las metodologías, hipótesis y herramientas utilizadas',
        'E5.IRO-1_02' => 'Consultas realizadas sobre recursos y economía circular',
        'E5.MDR-A_01-12' => 'Actuaciones y recursos sobre uso de recursos y economía circular [véase NEIS 2 MDR-A]',
        'E5.MDR-P_01-06' => 'Políticas sobre impactos, riesgos y oportunidades materiales del uso de recursos y la economía circular [véase NEIS 2 MDR-P]',
        'E5.MDR-T_01-13' => 'Seguimiento de la eficacia de políticas y actuaciones mediante metas [véase NEIS 2 MDR-T]',
        'G1-1_03' => 'Ausencia de políticas contra la corrupción y el soborno conformes con la Convención de las Naciones Unidas contra la Corrupción',
        'G1-1_06' => 'Ausencia de políticas de protección de informantes',
        'G1-2_01' => 'Política para evitar retrasos en los pagos, especialmente a pymes',
        'G1-2_02' => 'Enfoques de las relaciones con proveedores teniendo en cuenta los riesgos de la cadena de suministro y los impactos sobre cuestiones de sostenibilidad',
        'G1-3_03' => 'Proceso de comunicación de resultados a los órganos de gobierno',
        'G1-3_09' => 'Análisis de las actividades de formación, por ejemplo por región de formación o categoría',
        'G1-4_02' => 'Importe de multas por vulnerar la legislación contra la corrupción y el soborno',
        'G1-4_03' => 'Formación para prevenir y detectar corrupción y soborno [tabla]',
        'G1-4_04' => 'Número de incidentes confirmados de corrupción o soborno',
        'G1-4_05' => 'Naturaleza de los incidentes confirmados de corrupción o soborno',
        'G1-4_06' => 'Número de incidentes confirmados en los que se despidió o sancionó a trabajadores propios por incidentes de corrupción o soborno',
        'G1-4_07' => 'Número de incidentes confirmados relativos a contratos con socios comerciales que se resolvieron o no se renovaron por infracciones relacionadas con corrupción o soborno',
        'G1-5_03' => 'Contribuciones políticas financieras realizadas',
        'G1-6_01' => 'Media de días para pagar facturas desde el inicio del plazo contractual o legal',
        'G1-6_02' => 'Descripción de los plazos habituales de pago en número de días, por categoría principal de proveedores',
        'G1-6_04' => 'Número de procedimientos judiciales pendientes por retrasos en los pagos',
        'G1-6_05' => 'Información contextual sobre las prácticas de pago',
        'G1.MDR-A_01-12' => 'Planes de actuación y recursos para gestionar impactos, riesgos y oportunidades materiales relacionados con la corrupción y el soborno [véase NEIS 2 MDR-A]',
        'GOV-1_08' => 'Órganos o personas responsables de supervisar los impactos, riesgos y oportunidades',
        'GOV-1_10' => 'Papel de la dirección en los procesos, controles y procedimientos sobre impactos, riesgos y oportunidades',
        'GOV-1_12' => 'Líneas de comunicación con los órganos de administración, dirección y supervisión',
        'GOV-1_14' => 'Supervisión de los objetivos sobre impactos, riesgos y oportunidades materiales y seguimiento de los avances',
        'GOV-2_01' => 'Si se informa, quién informa y con qué frecuencia a los órganos de administración, dirección y supervisión sobre impactos, riesgos y oportunidades materiales, aplicación de la diligencia debida y resultados y eficacia de las políticas, actuaciones, indicadores y metas adoptados para abordarlos',
        'GOV-2_02' => 'Consideración de los impactos, riesgos y oportunidades al supervisar la estrategia, las operaciones importantes y la gestión de riesgos',
        'GOV-2_03' => 'Impactos, riesgos y oportunidades materiales tratados por los órganos de gobierno o sus comités',
        'GOV-2_04' => 'Garantías de los órganos de gobierno para disponer de mecanismos adecuados de seguimiento del desempeño',
        'GOV-4_01' => 'Correspondencia de la información del estado de sostenibilidad con el proceso de diligencia debida',
        'IRO-1_01' => 'Metodologías e hipótesis para identificar impactos, riesgos y oportunidades',
        'IRO-1_02' => 'Proceso para identificar, evaluar, priorizar y supervisar impactos potenciales y reales sobre las personas y el medio ambiente, basado en el proceso de diligencia debida',
        'IRO-1_03' => 'Atención a actividades, relaciones, zonas geográficas y otros factores con mayor riesgo de impactos negativos',
        'IRO-1_04' => 'Consideración de los impactos de las operaciones propias y las relaciones comerciales',
        'IRO-1_05' => 'Consulta a grupos de interés afectados y expertos externos para comprender los impactos',
        'IRO-1_06' => 'Cómo el proceso prioriza los impactos negativos según su gravedad relativa y probabilidad y los positivos según su escala, alcance y probabilidad relativos, y determina qué cuestiones de sostenibilidad son materiales para informar',
        'IRO-1_07' => 'Proceso para identificar, evaluar, priorizar y supervisar riesgos y oportunidades que tienen o podrían tener efectos financieros',
        'IRO-1_08' => 'Consideración de las conexiones entre impactos, dependencias, riesgos y oportunidades',
        'IRO-1_09' => 'Evaluación de la probabilidad, magnitud y naturaleza de los efectos de los riesgos y oportunidades',
        'IRO-1_10' => 'Priorización de los riesgos de sostenibilidad respecto a otros riesgos',
        'IRO-1_11' => 'Proceso de toma de decisiones y procedimientos de control interno relacionados',
        'IRO-1_12' => 'Grado y forma de integración del proceso de identificación, evaluación y gestión de impactos y riesgos en la gestión general de riesgos y su uso para evaluar el perfil global de riesgo y los procesos de gestión de riesgos',
        'IRO-1_13' => 'Integración de la identificación, evaluación y gestión de oportunidades en la gestión general',
        'IRO-1_14' => 'Parámetros utilizados para identificar, evaluar y gestionar impactos, riesgos y oportunidades materiales',
        'IRO-1_15' => 'Cambios en el proceso de identificación, evaluación y gestión respecto al ejercicio anterior',
        'IRO-2_02' => 'Requisitos de información NEIS aplicados tras la evaluación de materialidad',
        'IRO-2_07' => 'Explicación de la conclusión de no materialidad de NEIS E5 Economía circular',
        'IRO-2_08' => 'Explicación de la conclusión de no materialidad de NEIS S1 Personal propio',
        'IRO-2_11' => 'Explicación de la conclusión de no materialidad de NEIS S4 Consumidores y usuarios finales',
        'IRO-2_12' => 'Explicación de la conclusión de no materialidad de NEIS G1 Conducta empresarial',
        'IRO-2_13' => 'Determinación de la información material sobre impactos, riesgos y oportunidades que debe divulgarse',
        'MDR-A_01' => 'Actuación principal',
        'MDR-A_02' => 'Alcance de la actuación principal',
        'MDR-A_03' => 'Plazo para completar la actuación principal',
        'MDR-A_06' => 'Recursos financieros y de otro tipo, actuales y futuros, asignados al plan de actuación (gastos de capital y gastos operativos)',
        'MDR-M_01' => 'Indicador para evaluar el desempeño y la eficacia respecto a un impacto, riesgo u oportunidad material',
        'MDR-M_02' => 'Metodologías e hipótesis significativas del indicador',
        'MDR-P_01' => 'Contenido principal de la política',
        'MDR-P_02' => 'Alcance y exclusiones de la política',
        'MDR-P_03' => 'Máximo nivel de responsabilidad en la aplicación de la política',
        'MDR-P_05' => 'Consideración de los intereses de los principales grupos de interés al definir la política',
        'MDR-P_06' => 'Si la política se pone a disposición de los grupos de interés potencialmente afectados y de quienes deben ayudar a aplicarla, y cómo',
        'MDR-T_01' => 'Relación con los objetivos de la política',
        'MDR-T_03' => 'Naturaleza de la meta',
        'MDR-T_04' => 'Alcance de la meta',
        'MDR-T_07' => 'Periodo de aplicación de la meta',
        'MDR-T_08' => 'Hitos o metas intermedias',
        'MDR-T_09' => 'Metodologías e hipótesis significativas para definir la meta',
        'MDR-T_11' => 'Participación de los grupos de interés en la definición de la meta',
        'MDR-T_13' => 'Desempeño respecto a la meta comunicada',
        'MDR-T_16' => 'Seguimiento de la eficacia de las políticas y actuaciones respecto a impactos, riesgos y oportunidades materiales',
        'MDR-T_17' => 'Procesos de seguimiento de la eficacia de las políticas y actuaciones de sostenibilidad',
        'MDR-T_18' => 'Nivel de ambición e indicadores cualitativos o cuantitativos para evaluar los avances',
        'S1-17_07' => 'Contexto y recopilación de los datos de reclamaciones e incidentes laborales, sociales y de derechos humanos',
        'S1-1_01' => 'Políticas para todo el personal propio o grupos específicos sobre impactos, riesgos y oportunidades materiales',
        'S1-1_03' => 'Compromisos de derechos humanos relativos al personal propio',
        'S1-3_02' => 'Canales específicos para que el personal propio plantee inquietudes o necesidades directamente a la empresa y estas sean atendidas',
        'S1-3_09' => 'Políticas de protección frente a represalias por utilizar los canales',
        'S1-4_03' => 'Iniciativas y actuaciones para generar impactos positivos sobre el personal propio',
        'S1-4_20' => 'Funciones internas responsables de gestionar impactos y actuaciones para reducir los negativos y promover los positivos',
        'S1-5_04' => 'Resultados previstos en la vida del personal propio',
        'S1-6_03' => 'Número medio de empleados (personas)',
        'S1-6_06' => 'Número medio de empleados en países con al menos 50 empleados que representan al menos el 10 % del total',
        'S1-6_07' => 'Empleados por tipo de contrato y género [tabla]',
        'S1-6_08' => 'Empleados por región [tabla]',
        'S1-6_10' => 'Número medio de empleados (personas o equivalentes a jornada completa)',
        'S1.SBM-3_04' => 'Actividades con impactos positivos y tipos de personal propio beneficiado o potencialmente beneficiado',
        'S1.SBM-3_05' => 'Riesgos y oportunidades materiales derivados de impactos y dependencias del personal propio',
        'SBM-1_02' => 'Principales mercados y grupos de clientes atendidos',
        'SBM-1_21' => 'Metas de sostenibilidad por productos, servicios, clientes, zonas geográficas y relaciones con grupos de interés',
        'SBM-1_23' => 'Elementos de la estrategia relacionados con las cuestiones de sostenibilidad',
        'SBM-1_24' => 'Sectores NEIS significativos para la empresa',
        'SBM-1_25' => 'Modelo de negocio y cadena de valor',
        'SBM-1_27' => 'Productos y resultados: beneficios actuales y previstos para clientes, inversores y otros grupos de interés',
        'SBM-1_28' => 'Características de la cadena de valor anterior y posterior y posición de la empresa',
        'SBM-2_01' => 'Participación de los grupos de interés',
        'SBM-2_02' => 'Principales grupos de interés',
        'SBM-2_03' => 'Categorías de grupos de interés con los que se mantiene una relación de participación',
        'SBM-2_04' => 'Organización de la participación de los grupos de interés',
        'SBM-2_05' => 'Finalidad de la participación de los grupos de interés',
        'SBM-2_06' => 'Consideración de los resultados de la participación de los grupos de interés',
        'SBM-2_07' => 'Comprensión de los intereses y opiniones de los grupos de interés sobre la estrategia y el modelo de negocio',
        'SBM-2_12' => 'Información a los órganos de gobierno sobre los intereses y opiniones de los grupos afectados por los impactos de sostenibilidad',
        'SBM-3_01' => 'Impactos materiales identificados en la evaluación de materialidad',
        'SBM-3_02' => 'Riesgos y oportunidades materiales identificados en la evaluación de materialidad',
        'SBM-3_03' => 'Efectos actuales y previstos en el modelo de negocio, la cadena de valor, la estrategia y las decisiones, y respuesta de la empresa',
        'SBM-3_04' => 'Cómo afectan o probablemente afectarán los impactos materiales positivos y negativos a las personas o al medio ambiente',
        'SBM-3_05' => 'Origen o vinculación de los impactos materiales con la estrategia y el modelo de negocio',
        'SBM-3_06' => 'Horizontes temporales previstos de los impactos materiales',
        'SBM-3_07' => 'Actividades y relaciones comerciales que vinculan a la empresa con los impactos materiales',
        'SBM-3_10' => 'Resiliencia de la estrategia y el modelo de negocio frente a los impactos, riesgos y oportunidades materiales',
    ];

    expect($originalLabels)->toHaveCount(155);
    expect(array_unique($originalLabels))->toHaveCount(154);
    foreach ($originalLabels as $datapointId => $expectedLabel) {
        expect($labels[$datapointId])->toBe($expectedLabel);
    }
    expect($labels)->toHaveCount(1184);
    $canonical = json_decode(file_get_contents(__DIR__.'/../../../data/esrs_datapoints_ig3.json'), true, 512, JSON_THROW_ON_ERROR)['datapoints'];
    expect(array_keys($labels))->toBe(array_column($canonical, 'id'));
    foreach ($canonical as $record) {
        expect($labels[$record['id']])->not->toBe($record['name']);
        expect(ReportVisiblePresentation::claimLabel($record['id']))->toBe($labels[$record['id']]);
    }

    foreach ($labels as $datapointId => $label) {
        expect(trim($label))->not->toBe('');
        expect(ReportVisibleCorpusLabels::claimLabel($datapointId))->toBe($label);
        expect(str_contains($label, $datapointId))->toBeFalse();
        expect(preg_match('/Información revisada|Dato revisado|Declaración revisada|Indicador numérico revisado|Importe revisado|Recuento revisado|\b(?:filing|cloud|hardware|software)\b|_/iu', $label))->toBe(0);
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
    ]))->toBe('0,8 t — Restos vegetales; no peligroso; compostaje');
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
    ]))->toBe('3 t — Residuos residuales');
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

it('renders the machine year unit as an ungrouped calendar year without changing the claim', function () {
    $claim = [
        'value_type' => 'integer',
        'value' => ['value' => 2024],
        'decimals' => 0,
        'unit' => 'year',
        'dimensions' => [],
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('2024');
});

it('localizes the persons alias in singular without changing typed data or dimensions', function () {
    $claim = [
        'value_type' => 'integer',
        'value' => ['value' => 1],
        'decimals' => 0,
        'unit' => 'persons',
        'dimensions' => [
            ['axis' => 'contract_type', 'member' => 'permanent'],
            ['axis' => 'gender', 'member' => 'men'],
        ],
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('1 persona — Hombres; Contrato indefinido');
});

it('localizes the persons alias in plural without changing the claim', function () {
    $claim = [
        'value_type' => 'integer',
        'value' => ['value' => 2],
        'decimals' => 0,
        'unit' => 'persons',
        'dimensions' => [],
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('2 personas');
});

it('localizes the percent alias without rescaling or changing the claim', function () {
    $claim = [
        'value_type' => 'number',
        'value' => ['value' => 50],
        'decimals' => 0,
        'unit' => 'percent',
        'dimensions' => [],
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('50 %');
});

it('renders a numeric percent without artificial decimal padding', function () {
    $claim = [
        'value_type' => 'number',
        'value' => ['value' => 50],
        'decimals' => 6,
        'unit' => 'percent',
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('50 %');
});

it('renders a grouped numeric amount without artificial decimal padding', function () {
    $claim = [
        'value_type' => 'number',
        'value' => ['value' => 1200],
        'decimals' => 6,
        'unit' => 'MWh',
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('1.200 MWh');
});

it('renders a small numeric intensity without rounding its scalar digits', function () {
    $claim = [
        'value_type' => 'number',
        'value' => ['value' => 1.167e-5],
        'decimals' => 6,
        'unit' => 'tCO2e/EUR',
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('0,00001167 tCO2e/EUR');
});

it('renders a small decimal-string intensity without rounding it to zero', function () {
    $claim = [
        'value_type' => 'number',
        'value' => ['value' => '0.0000000417'],
        'decimals' => 6,
        'unit' => 'tCO2e/EUR',
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('0,0000000417 tCO2e/EUR');
});

it('renders a large decimal string without losing integer or fractional digits', function () {
    $claim = [
        'value_type' => 'number',
        'value' => ['value' => '12000000000000000000.000055'],
        'decimals' => 6,
        'unit' => 'MWh',
    ];
    $original = $claim;

    $visible = ReportVisiblePresentation::claimValue($claim);

    expect($claim)->toBe($original);
    expect($visible)->toBe('12.000.000.000.000.000.000,000055 MWh');
});

it('preserves declared monetary decimal formatting and literal numeric text', function () {
    $monetaryClaim = [
        'value_type' => 'monetary',
        'value' => ['value' => 1234.5],
        'decimals' => 2,
        'unit' => 'EUR',
    ];
    $originalMonetaryClaim = $monetaryClaim;
    $textClaim = [
        'value_type' => 'text',
        'value' => ['text' => '990.0'],
    ];
    $originalTextClaim = $textClaim;

    $visibleMonetary = ReportVisiblePresentation::claimValue($monetaryClaim);
    $visibleText = ReportVisiblePresentation::claimValue($textClaim);

    expect($monetaryClaim)->toBe($originalMonetaryClaim);
    expect($textClaim)->toBe($originalTextClaim);
    expect($visibleMonetary)->toBe('1.234,50 EUR');
    expect($visibleText)->toBe('990.0');
});

it('uses specific Spanish labels for all 145 missing approved-claim datapoints', function () {
    $datapointIds = [
        'BP-1_03', 'BP-1_05', 'BP-1_06', 'BP-2_02', 'BP-2_03',
        'BP-2_04', 'BP-2_05', 'BP-2_06', 'BP-2_07', 'BP-2_08',
        'BP-2_09', 'BP-2_10', 'BP-2_16', 'E1-1_02', 'E1-1_07',
        'E1-1_08', 'E1-1_15', 'E1-3_03', 'E1-3_04', 'E1-3_06',
        'E1-3_07', 'E1-3_08', 'E1-4_03', 'E1-4_04', 'E1-4_05',
        'E1-4_06', 'E1-4_07', 'E1-4_08', 'E1-4_09', 'E1-4_10',
        'E1-4_11', 'E1-4_12', 'E1-4_13', 'E1-4_14', 'E1-4_15',
        'E1-4_16', 'E1-4_17', 'E1-4_20', 'E1-4_21', 'E1-4_22',
        'E1-4_24', 'E1-5_03', 'E1-5_04', 'E1-5_06', 'E1-5_08',
        'E1-5_10', 'E1-5_11', 'E1-5_13', 'E1-5_14', 'E1-5_16',
        'E1-5_17', 'E1-5_21', 'E1-6_03', 'E1-6_08', 'E1-6_14',
        'E1-6_15', 'E1-6_16', 'E1-6_17', 'E1-6_18', 'E1-6_19',
        'E1-6_21', 'E1-6_22', 'E1-6_23', 'E1-6_24', 'E1-7_01',
        'E1-7_02', 'E1-7_03', 'E1-7_20', 'E1-7_21', 'E1.GOV-3_01',
        'E1.GOV-3_02', 'E1.GOV-3_03', 'E1.IRO-1_01', 'E1.IRO-1_02', 'E1.IRO-1_03',
        'E1.IRO-1_04', 'E1.IRO-1_05', 'E1.IRO-1_06', 'E1.IRO-1_07', 'E1.IRO-1_08',
        'E1.IRO-1_09', 'E1.IRO-1_10', 'E1.IRO-1_11', 'E1.IRO-1_12', 'E1.IRO-1_13',
        'E1.IRO-1_14', 'E1.IRO-1_15', 'E1.IRO-1_16', 'E1.SBM-3_01', 'E1.SBM-3_03',
        'E1.SBM-3_04', 'E1.SBM-3_05', 'E1.SBM-3_06', 'E1.SBM-3_07', 'G1-3_02',
        'G1.GOV-1_02', 'GOV-1_01', 'GOV-1_02', 'GOV-1_03', 'GOV-1_04',
        'GOV-1_05', 'GOV-1_06', 'GOV-1_07', 'GOV-1_09', 'GOV-1_11',
        'GOV-1_13', 'GOV-1_15', 'GOV-1_16', 'GOV-1_17', 'GOV-3_01',
        'GOV-3_02', 'GOV-3_03', 'GOV-3_04', 'GOV-3_05', 'GOV-3_06',
        'GOV-5_01', 'GOV-5_02', 'GOV-5_03', 'GOV-5_04', 'GOV-5_05',
        'IRO-2_01', 'MDR-A_04', 'MDR-A_05', 'MDR-A_07', 'MDR-A_11',
        'MDR-A_12', 'MDR-M_03', 'MDR-P_04', 'MDR-T_05', 'MDR-T_10',
        'MDR-T_12', 'SBM-1_05', 'SBM-1_09', 'SBM-1_15', 'SBM-1_17',
        'SBM-1_19', 'SBM-1_22', 'SBM-1_26', 'SBM-2_08', 'SBM-2_09',
        'SBM-2_10', 'SBM-2_11', 'SBM-3_08', 'SBM-3_11', 'SBM-3_12',
    ];

    expect($datapointIds)->toHaveCount(145);
    expect(array_unique($datapointIds))->toHaveCount(145);

    $labels = [];
    foreach ($datapointIds as $datapointId) {
        $labels[$datapointId] = ReportVisiblePresentation::claimLabel($datapointId);
    }

    foreach ($labels as $datapointId => $label) {
        expect(trim($label))->not->toBe('');
        expect(ReportVisibleCorpusLabels::claimLabel($datapointId))->toBe($label);
        expect(str_contains($label, $datapointId))->toBeFalse();
        expect(preg_match('/Información revisada|Dato revisado|Declaración revisada|Indicador numérico revisado|Indicador sí[\/-]no revisado|Importe revisado|Recuento revisado|Fecha revisada|Clasificación revisada|\b(?:ESRS|MDR|SBM|IRO|GOV|filing|cloud|hardware|software|Disclosure|Requirements?|datapoints?|undertaking|GHG|Scope|CapEx|OpEx|baseline)\b|_/iu', $label))->toBe(0);
    }
});

it('uses grounded Spanish titles for all five missing approved-report sections', function () {
    $headings = [
        'GOV-3' => 'Incentivos de remuneración ligados a la sostenibilidad',
        'GOV-5' => 'Gestión de riesgos y controles internos de la información de sostenibilidad',
        'E1.GOV-3' => 'Remuneración ligada a cuestiones climáticas',
        'E1.IRO-1' => 'Identificación de impactos, riesgos y oportunidades climáticos',
        'E1-7' => 'Absorciones de gases de efecto invernadero y créditos de carbono',
    ];

    foreach ($headings as $section => $expected) {
        expect(ReportVisiblePresentation::sectionTitle($section))->toBe($expected);
    }
});

it('preserves probability in the stakeholder relationship change label', function () {
    expect(ReportVisiblePresentation::claimLabel('SBM-2_11'))
        ->toBe('Probables cambios en las relaciones y opiniones de los grupos de interés derivados de las actuaciones previstas');
});
