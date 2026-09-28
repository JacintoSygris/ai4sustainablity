<?php

namespace App\Services\Report;

use DomainException;
use RuntimeException;

final class ReportVisiblePresentation
{
    /** @var array<string, string> */
    private const CHAPTER_TITLES_ES = [
        'always_required' => 'Información general de la empresa',
        'topical' => 'Información sobre temas materiales',
        'minimum_disclosure_requirements' => 'Requisitos mínimos de información',
        'e1_not_material_explanation' => 'Explicación sobre cambio climático',
    ];

    /** @var array<string, string> */
    private const SECTION_TITLES_ES = [
        'BP-1' => 'Bases de preparación',
        'E1-5' => 'Consumo y combinación energética',
        'E5-4' => 'Entradas de recursos',
        'E5-5' => 'Salidas de recursos y residuos',
        'S1-1' => 'Políticas relativas al personal propio',
        'S1-6' => 'Características de la plantilla',
        'S1-8' => 'Cobertura de la negociación colectiva',
        'S1-9' => 'Diversidad de la plantilla',
        'S1-10' => 'Salarios adecuados',
        'S1-13' => 'Formación y desarrollo de capacidades',
        'S1-14' => 'Salud y seguridad laboral',
        'S1-15' => 'Conciliación y permisos familiares',
        'S1-16' => 'Remuneración e igualdad salarial',
        'S1-17' => 'Incidentes, reclamaciones y derechos humanos',
    ];

    /** @var array<string, string> */
    private const CLAIM_LABELS_ES = [
        'BP-1_01' => 'Base de preparación del informe',
        'BP-1_04' => 'Alcance de la cadena de valor',
        'E1-5_01' => 'Consumo total de energía en operaciones propias',
        'E1-5_05' => 'Consumo de energía procedente de fuentes renovables',
        'E1-5_09' => 'Porcentaje de energía renovable sobre el consumo total',
        'E5-4_01' => 'Descripción de las entradas materiales de recursos',
        'E5-4_02' => 'Masa total de productos y materiales utilizados',
        'E5-4_03' => 'Porcentaje de materiales biológicos',
        'E5-5_01' => 'Productos y materiales resultantes de la actividad',
        'E5-5_05' => 'Reciclabilidad de los envases',
        'E5-5_06' => 'Metodología de cálculo de las salidas de recursos',
        'E5-5_07' => 'Total de residuos generados',
        'E5-5_08' => 'Residuos desviados de eliminación',
        'E5-5_09' => 'Residuos destinados a eliminación',
        'E5-5_10' => 'Residuos no reciclados',
        'E5-5_11' => 'Porcentaje de residuos no reciclados',
        'E5-5_12' => 'Composición de los residuos',
        'E5-5_13' => 'Flujos de residuos relevantes para la actividad',
        'E5-5_14' => 'Materiales presentes en los residuos',
        'E5-5_15' => 'Total de residuos peligrosos',
        'E5-5_16' => 'Total de residuos radiactivos',
        'E5-5_17' => 'Metodología de cálculo de los residuos',
        'S1-1_09' => 'Sistema de prevención de accidentes laborales',
        'S1-6_02' => 'Número de personas empleadas al cierre',
        'S1-8_01' => 'Plantilla cubierta por convenios colectivos',
        'S1-9_01' => 'Distribución por género en la dirección superior',
        'S1-9_02' => 'Porcentaje por género en la dirección superior',
        'S1-9_03' => 'Personas menores de 30 años',
        'S1-9_04' => 'Personas de entre 30 y 50 años',
        'S1-9_05' => 'Personas mayores de 50 años',
        'S1-9_06' => 'Definición de dirección superior',
        'S1-10_01' => 'Cobertura de salarios adecuados',
        'S1-10_03' => 'Plantilla por debajo del salario adecuado de referencia',
        'S1-13_01' => 'Indicadores de formación por género',
        'S1-13_03' => 'Promedio de formación por género',
        'S1-13_04' => 'Promedio de formación por persona',
        'S1-14_01' => 'Cobertura del sistema de salud y seguridad laboral',
        'S1-14_02' => 'Fallecimientos relacionados con el trabajo',
        'S1-14_04' => 'Accidentes laborales registrables',
        'S1-14_05' => 'Tasa de accidentes laborales registrables',
        'S1-14_06' => 'Casos registrables de enfermedad profesional',
        'S1-14_07' => 'Días perdidos por daños relacionados con el trabajo',
        'S1-15_01' => 'Plantilla con derecho a permisos familiares',
        'S1-15_02' => 'Uso de permisos familiares',
        'S1-15_03' => 'Uso de permisos familiares por género',
        'S1-15_04' => 'Derecho de toda la plantilla a permisos familiares',
        'S1-16_01' => 'Brecha salarial de género',
        'S1-16_02' => 'Relación entre la remuneración máxima y la mediana',
        'S1-16_03' => 'Metodología de los indicadores de remuneración',
        'S1-17_01' => 'Incidentes de discriminación y acoso por categoría',
        'S1-17_02' => 'Incidentes de discriminación',
        'S1-17_03' => 'Quejas presentadas por la plantilla',
        'S1-17_05' => 'Multas, sanciones e indemnizaciones por discriminación o acoso',
        'S1-17_08' => 'Incidentes graves de derechos humanos',
        'S1-17_09' => 'Incumplimientos de principios internacionales de derechos humanos',
        'S1-17_10' => 'Ausencia de incidentes graves de derechos humanos',
        'S1-17_11' => 'Multas, sanciones e indemnizaciones por incidentes graves',
    ];

    /** @var array<string, string> */
    private const DIMENSION_MEMBERS_ES = [
        'plant_residues' => 'Restos vegetales',
        'cardboard' => 'Cartón',
        'plastic_packaging' => 'Plástico de embalaje',
        'used_oil' => 'Aceite usado',
        'mixed_residual' => 'Rechazo mezclado',
        'contaminated_absorbents' => 'Absorbentes contaminados',
        'non_hazardous' => 'no peligroso',
        'hazardous' => 'peligroso',
        'recycling_composting' => 'compostaje',
        'material_recycling' => 'reciclaje material',
        'recycling_regeneration' => 'regeneración',
        'landfill' => 'vertedero',
        'incineration_without_energy_recovery' => 'incineración sin recuperación de energía',
        'men' => 'Hombres',
        'women' => 'Mujeres',
        'non_binary' => 'Personas no binarias',
        'total' => 'Total',
        'permanent' => 'Contrato indefinido',
        'temporary' => 'Contrato temporal',
        'Germany' => 'Alemania',
        'esrs:ES' => 'España',
        'esrs:FR' => 'Francia',
        'Residuos electrónicos' => 'Residuos electrónicos',
        'Otros residuos reciclables' => 'Otros residuos reciclables',
        'Residuos residuales' => 'Residuos residuales',
        'Hombres' => 'Hombres',
        'Mujeres' => 'Mujeres',
        'No binario' => 'Personas no binarias',
        'Permanente - hombres' => 'Contrato indefinido; Hombres',
        'Permanente - mujeres' => 'Contrato indefinido; Mujeres',
        'Permanente - no binario' => 'Contrato indefinido; Personas no binarias',
        'Temporal - hombres' => 'Contrato temporal; Hombres',
        'Temporal - mujeres' => 'Contrato temporal; Mujeres',
        'Temporal - no binario' => 'Contrato temporal; Personas no binarias',
        'Estados Unidos' => 'Estados Unidos',
        'Alemania' => 'Alemania',
        'Austria' => 'Austria',
        'Bélgica' => 'Bélgica',
        'Dinamarca' => 'Dinamarca',
        'España' => 'España',
        'Francia' => 'Francia',
        'Irlanda' => 'Irlanda',
        'Italia' => 'Italia',
        'Países Bajos' => 'Países Bajos',
        'Polonia' => 'Polonia',
        'Portugal' => 'Portugal',
        'Norteamérica' => 'Norteamérica',
        'Unión Europea' => 'Unión Europea',
    ];

    /** @var array<string, int> */
    private const DIMENSION_AXIS_ORDER = [
        'waste_stream' => 0,
        'hazard_class' => 1,
        'treatment_type' => 2,
        'gender' => 10,
        'contract_type' => 11,
        'country' => 12,
        'esrs:CountryAxis' => 13,
        'tipo de residuo' => 20,
        'Género' => 21,
        'Género en dirección' => 22,
        'Contrato y género' => 23,
        'País' => 24,
        'Región' => 25,
    ];

    /** @var array<string, list<string>> */
    private const DIMENSION_AXIS_MEMBERS = [
        'waste_stream' => [
            'plant_residues',
            'cardboard',
            'plastic_packaging',
            'used_oil',
            'mixed_residual',
            'contaminated_absorbents',
        ],
        'hazard_class' => ['non_hazardous', 'hazardous'],
        'treatment_type' => [
            'recycling_composting',
            'material_recycling',
            'recycling_regeneration',
            'landfill',
            'incineration_without_energy_recovery',
        ],
        'gender' => ['men', 'women', 'non_binary', 'total'],
        'contract_type' => ['permanent', 'temporary'],
        'country' => ['Germany'],
        'esrs:CountryAxis' => ['esrs:ES', 'esrs:FR'],
        'tipo de residuo' => [
            'Residuos electrónicos',
            'Otros residuos reciclables',
            'Residuos residuales',
        ],
        'Género' => ['Hombres', 'Mujeres', 'No binario'],
        'Género en dirección' => ['Hombres', 'Mujeres', 'No binario'],
        'Contrato y género' => [
            'Permanente - hombres',
            'Permanente - mujeres',
            'Permanente - no binario',
            'Temporal - hombres',
            'Temporal - mujeres',
            'Temporal - no binario',
        ],
        'País' => [
            'Estados Unidos',
            'Alemania',
            'Austria',
            'Bélgica',
            'Dinamarca',
            'España',
            'Francia',
            'Irlanda',
            'Italia',
            'Países Bajos',
            'Polonia',
            'Portugal',
        ],
        'Región' => ['Norteamérica', 'Unión Europea'],
    ];

    public static function chapterTitle(mixed $blockKey): string
    {
        return self::CHAPTER_TITLES_ES[(string) $blockKey] ?? 'Información revisada';
    }

    public static function sectionTitle(mixed $drKey): string
    {
        $key = (string) $drKey;

        return self::SECTION_TITLES_ES[$key]
            ?? ReportVisibleLabels::sectionTitle($key)
            ?? 'Información revisada';
    }

    /** @param array<string, mixed>|null $claim */
    public static function claimLabel(?string $datapointId, ?array $claim = null): string
    {
        if (isset(self::CLAIM_LABELS_ES[(string) $datapointId])) {
            return self::CLAIM_LABELS_ES[(string) $datapointId];
        }

        $label = ReportVisibleLabels::claimLabel((string) $datapointId);
        if ($label !== null) {
            return $label;
        }

        return match ((string) ($claim['value_type'] ?? '')) {
            'text' => 'Declaración revisada',
            'number' => 'Indicador numérico revisado',
            'monetary' => 'Importe revisado',
            'integer' => 'Recuento revisado',
            'boolean' => 'Indicador sí/no revisado',
            'enumeration' => 'Clasificación revisada',
            'date' => 'Fecha revisada',
            default => 'Dato revisado',
        };
    }

    public static function controlledNarrative(mixed $value): string
    {
        $text = trim((string) $value);

        if (preg_match('/\b(?:BP|E\d|S\d|G\d|MDR|IRO)-\d+(?:_\d+)?\b/u', $text) === 1
            || preg_match('/\b(?:Topical datapoints|Disclosure Requirements?|Own workforce|Resource use and circular economy)\b/iu', $text) === 1
            || preg_match('/\b(?:E2E|SaaS|cloud|hardware|software|filing|snapshot|facts?|datapoints?)\b/iu', $text) === 1) {
            throw new DomainException('Factual visible narrative contains a technical reporting identifier or corpus title.');
        }

        return $text;
    }

    /** @param array<string, mixed> $claim */
    public static function claimValue(array $claim): ?string
    {
        $dimensions = array_key_exists('dimensions', $claim) ? $claim['dimensions'] : [];
        self::withVisibleDimensions('', $dimensions);

        if (($claim['nil'] ?? false) === true) {
            return null;
        }

        $value = ReportFactValue::scalar($claim);
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return self::withVisibleDimensions($value ? 'Sí' : 'No', $dimensions);
        }

        $valueType = (string) ($claim['value_type'] ?? '');
        $isNumeric = in_array($valueType, ['number', 'monetary', 'integer'], true)
            || is_int($value)
            || is_float($value);

        if ($isNumeric && is_numeric($value)) {
            $decimals = max(0, min(12, (int) ($claim['decimals'] ?? 0)));
            $unit = trim((string) ($claim['unit'] ?? ''));
            if ($unit === 'año') {
                return self::withVisibleDimensions(
                    number_format((float) $value, $decimals, ',', ''),
                    $dimensions,
                );
            }

            $formatted = number_format((float) $value, $decimals, ',', '.');
            $displayUnit = self::visibleUnit($unit, (float) $value);
            $displayValue = $displayUnit === '' ? $formatted : $formatted.' '.$displayUnit;

            return self::withVisibleDimensions($displayValue, $dimensions);
        }

        if (is_string($value)) {
            return self::withVisibleDimensions($value, $dimensions);
        }

        throw new RuntimeException('Unsupported factual value type.');
    }

    private static function visibleUnit(string $unit, float $value): string
    {
        $singular = abs($value) === 1.0;

        return match ($unit) {
            'personas' => $singular ? 'persona' : 'personas',
            'fallecimientos' => $singular ? 'fallecimiento' : 'fallecimientos',
            'accidentes_registrables' => $singular ? 'accidente registrable' : 'accidentes registrables',
            'accidentes/1.000.000 h' => 'accidentes registrables por 1.000.000 h',
            'casos' => $singular ? 'caso' : 'casos',
            'días' => $singular ? 'día' : 'días',
            'incidentes' => $singular ? 'incidente' : 'incidentes',
            'quejas' => $singular ? 'queja' : 'quejas',
            'ratio' => 'veces',
            'tCO2e/MUSD' => 'toneladas de CO₂ equivalente por millón de USD',
            default => $unit,
        };
    }

    private static function withVisibleDimensions(string $value, mixed $dimensions): string
    {
        if (! is_array($dimensions)) {
            throw new RuntimeException('Unsupported factual dimension shape.');
        }

        if ($dimensions === []) {
            return $value;
        }

        usort($dimensions, function (mixed $left, mixed $right): int {
            $leftAxis = is_array($left) ? (string) ($left['axis'] ?? '') : '';
            $rightAxis = is_array($right) ? (string) ($right['axis'] ?? '') : '';

            return (self::DIMENSION_AXIS_ORDER[$leftAxis] ?? PHP_INT_MAX)
                <=> (self::DIMENSION_AXIS_ORDER[$rightAxis] ?? PHP_INT_MAX);
        });

        $labels = [];
        foreach ($dimensions as $dimension) {
            if (! is_array($dimension)) {
                throw new RuntimeException('Unsupported factual dimension shape.');
            }

            $axis = (string) ($dimension['axis'] ?? '');
            $member = (string) ($dimension['member'] ?? '');
            $label = self::dimensionMemberLabel($axis, $member);
            if ($label === null) {
                throw new RuntimeException('Unsupported factual dimension label.');
            }

            $labels[] = $label;
        }

        return $value.' — '.implode('; ', $labels);
    }

    private static function dimensionMemberLabel(string $axis, string $member): ?string
    {
        if (! array_key_exists($axis, self::DIMENSION_AXIS_ORDER)) {
            return null;
        }

        if ($axis === 'esrs:CountryAxis' && preg_match('/^esrs:([A-Z]{2})$/', $member, $matches) === 1) {
            return self::countryCodeLabel($matches[1]);
        }

        if ($axis === 'País' && in_array($member, self::supportedCountryNames(), true)) {
            return $member;
        }

        if (! array_key_exists($member, self::DIMENSION_MEMBERS_ES)
            || ! in_array($member, self::DIMENSION_AXIS_MEMBERS[$axis] ?? [], true)) {
            return null;
        }

        return self::DIMENSION_MEMBERS_ES[$member];
    }

    private static function countryCodeLabel(string $countryCode): string
    {
        $countries = self::countryLabelsByCode();

        return $countries[$countryCode] ?? 'País '.$countryCode;
    }

    /** @return list<string> */
    private static function supportedCountryNames(): array
    {
        return array_values(self::countryLabelsByCode());
    }

    /** @return array<string, string> */
    private static function countryLabelsByCode(): array
    {
        return [
            'AT' => 'Austria',
            'BE' => 'Bélgica',
            'BG' => 'Bulgaria',
            'CY' => 'Chipre',
            'CZ' => 'Chequia',
            'DE' => 'Alemania',
            'DK' => 'Dinamarca',
            'EE' => 'Estonia',
            'EL' => 'Grecia',
            'ES' => 'España',
            'FI' => 'Finlandia',
            'FR' => 'Francia',
            'HR' => 'Croacia',
            'HU' => 'Hungría',
            'IE' => 'Irlanda',
            'IT' => 'Italia',
            'LT' => 'Lituania',
            'LU' => 'Luxemburgo',
            'LV' => 'Letonia',
            'MT' => 'Malta',
            'NL' => 'Países Bajos',
            'PL' => 'Polonia',
            'PT' => 'Portugal',
            'RO' => 'Rumanía',
            'SE' => 'Suecia',
            'SI' => 'Eslovenia',
            'SK' => 'Eslovaquia',
            'UK' => 'Reino Unido',
            'US' => 'Estados Unidos',
        ];
    }
}
