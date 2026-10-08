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
        return (new \App\Support\EsrsDisplayCatalogue)->sectionTitle((string) $drKey) ?? 'Título no disponible';
    }

    /** @param array<string, mixed>|null $claim */
    public static function claimLabel(?string $datapointId, ?array $claim = null): string
    {
        return (new \App\Support\EsrsDisplayCatalogue)->claimLabel((string) $datapointId) ?? 'Etiqueta no disponible';
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
    public static function claimValue(array $claim, string $locale = 'es'): ?string
    {
        $dimensions = array_key_exists('dimensions', $claim) ? $claim['dimensions'] : [];
        self::withVisibleDimensions('', $dimensions, $locale);

        if (($claim['nil'] ?? false) === true) {
            return null;
        }

        $value = ReportFactValue::scalar($claim);
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return self::withVisibleDimensions($value ? ($locale === 'en' ? 'Yes' : 'Sí') : 'No', $dimensions, $locale);
        }

        $valueType = (string) ($claim['value_type'] ?? '');
        $isNumeric = in_array($valueType, ['number', 'monetary', 'integer'], true)
            || is_int($value)
            || is_float($value);

        if ($isNumeric && is_numeric($value)) {
            $decimals = max(0, min(12, (int) ($claim['decimals'] ?? 0)));
            $unit = trim((string) ($claim['unit'] ?? ''));
            $calendarYear = $unit === 'año' || $unit === 'year';
            $formatted = $valueType !== 'monetary'
                ? self::visibleNumber($value, $locale, ! $calendarYear)
                : (is_string($value)
                    ? self::formatDecimalString($value, $decimals, $locale, ! $calendarYear)
                    : number_format($value, $decimals, $locale === 'en' ? '.' : ',', $calendarYear ? '' : ($locale === 'en' ? ',' : '.')));
            if ($calendarYear) {
                return self::withVisibleDimensions($formatted, $dimensions, $locale);
            }

            $singular = is_string($value) ? preg_match('/^[+-]?0*1(?:\.0*)?$/D', $value) === 1 : abs($value) == 1;
            $displayUnit = $locale === 'en'
                ? match ($unit) {
                    'personas', 'persons' => $singular ? 'person' : 'persons',
                    'percent' => '%',
                    default => $unit,
                }
                : self::visibleUnit($unit, $singular);
            $displayValue = $displayUnit === '' ? $formatted : $formatted.' '.$displayUnit;

            return self::withVisibleDimensions($displayValue, $dimensions, $locale);
        }

        if (is_string($value)) {
            return self::withVisibleDimensions($value, $dimensions, $locale);
        }

        throw new RuntimeException('Unsupported factual value type.');
    }

    /** Format decimal lexemes with exact half-up rounding, without binary floating point. */
    private static function formatDecimalString(string $value, int $decimals, string $locale, bool $group): string
    {
        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $value) !== 1) {
            throw new RuntimeException('Unsupported factual decimal string.');
        }
        $negative = str_starts_with($value, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($value, '+-'), 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $digits = $integer.str_pad(substr($fraction, 0, $decimals), $decimals, '0');
        if (strlen($fraction) > $decimals && $fraction[$decimals] >= '5') {
            // Carry through the decimal digits directly, even above PHP_INT_MAX.
            $position = strlen($digits) - 1;
            while ($position >= 0 && $digits[$position] === '9') {
                $digits[$position--] = '0';
            }
            if ($position < 0) {
                $digits = '1'.$digits;
            } else {
                $digits[$position] = chr(ord($digits[$position]) + 1);
            }
        }
        $integer = $decimals === 0 ? $digits : substr($digits, 0, -$decimals);
        $fraction = $decimals === 0 ? '' : substr($digits, -$decimals);
        if ($group) {
            $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', $locale === 'en' ? ',' : '.', $integer);
        }

        return ($negative && trim($digits, '0') !== '' ? '-' : '').$integer
            .($decimals === 0 ? '' : ($locale === 'en' ? '.' : ',').$fraction);
    }

    private static function visibleNumber(string|int|float $value, string $locale, bool $groupThousands): string
    {
        if (is_float($value)) {
            // Use native shortest round-trip serialization, independent of display precision.
            $previousPrecision = ini_set('serialize_precision', '-1');
            if ($previousPrecision === false) {
                throw new RuntimeException('Unable to serialize factual float without precision loss.');
            }

            try {
                $decimal = json_encode($value, JSON_THROW_ON_ERROR);
            } finally {
                ini_set('serialize_precision', $previousPrecision);
            }
        } else {
            $decimal = (string) $value;
        }

        $sign = '';
        if ($decimal[0] === '-' || $decimal[0] === '+') {
            $sign = $decimal[0];
            $decimal = substr($decimal, 1);
        }

        $exponent = 0;
        if (is_float($value) && preg_match('/\A(.+)[eE]([+-]?\d+)\z/', $decimal, $matches) === 1) {
            // Only native finite floats can reach this branch, bounding expansion to their range.
            $decimal = $matches[1];
            $exponent = (int) $matches[2];
        }

        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        if ($exponent !== 0) {
            $digits = $integer.$fraction;
            $point = strlen($integer) + $exponent;
            if ($point <= 0) {
                $integer = '0';
                $fraction = str_repeat('0', -$point).$digits;
            } elseif ($point >= strlen($digits)) {
                $integer = $digits.str_repeat('0', $point - strlen($digits));
                $fraction = '';
            } else {
                $integer = substr($digits, 0, $point);
                $fraction = substr($digits, $point);
            }
        }

        if (is_float($value)) {
            $fraction = rtrim($fraction, '0');
        }

        $integer = $integer === '' ? '0' : $integer;
        if ($groupThousands) {
            $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', $locale === 'en' ? ',' : '.', $integer);
        }

        return $sign.$integer.($fraction === '' ? '' : ($locale === 'en' ? '.' : ',').$fraction);
    }

    private static function visibleUnit(string $unit, bool $singular): string
    {
        return match ($unit) {
            'personas', 'persons' => $singular ? 'persona' : 'personas',
            'percent' => '%',
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

    private static function withVisibleDimensions(string $value, mixed $dimensions, string $locale = 'es'): string
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

            // Only closed system-code axes have translated display values. Free-text axes/members stay verbatim.
            $systemAxis = in_array($axis, ['waste_stream', 'hazard_class', 'treatment_type', 'gender', 'contract_type', 'country', 'esrs:CountryAxis'], true);
            if ($locale === 'en' && $systemAxis) {
                $label = preg_match('/^País ([A-Z]{2})$/', $label, $code) === 1
                    ? 'Country '.$code[1]
                    : (new ReportDisplayProjection('en'))->text($label);
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

        if ($axis === 'País') {
            $countryMember = trim($member);
            if (in_array($countryMember, self::supportedCountryNames(), true)) {
                return $countryMember;
            }

            if (! array_key_exists($countryMember, self::DIMENSION_MEMBERS_ES)
                && self::isSafeCountryName($countryMember)) {
                return $countryMember;
            }
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

    private static function isSafeCountryName(string $member): bool
    {
        $trimmed = trim($member);

        return $trimmed !== ''
            && mb_strlen($trimmed) <= 80
            && preg_match('/^[\p{L}\p{M} .\'’\-()]+$/u', $trimmed) === 1;
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
            'BR' => 'Brasil',
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
