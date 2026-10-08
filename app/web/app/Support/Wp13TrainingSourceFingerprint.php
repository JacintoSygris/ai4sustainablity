<?php

namespace App\Support;

use App\Models\Characterization;
use InvalidArgumentException;

/**
 * Versioned P5 input allowlist. Never include identity, free text or P6/P8 outputs.
 * Changes to this contract require a new schema version for offline consumers.
 */
final class Wp13TrainingSourceFingerprint
{
    public const SCHEMA_VERSION = 1;

    private const COMPANY_FIELDS = [
        'headquarters_country', 'reporting_year', 'reporting_scope',
        'num_subsidiaries_countries', 'stock_listed', 'reporting_currency', 'product_service_type',
    ];

    private const OPERATIONS_FIELDS = [
        'employee_count_range', 'revenue_range', 'employee_count', 'revenue',
    ];

    private const ACTIVITY_FIELDS = [
        'physical_operations', 'water_use', 'hazardous_substances',
        'biodiversity_sensitive_locations', 'physical_goods_resources',
        'external_value_chain_workers', 'local_communities', 'consumer_end_users',
        'international_footprint',
    ];

    public static function hash(Characterization $characterization): string
    {
        return hash('sha256', json_encode(
            self::snapshot($characterization),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    /** The exact canonical, identity-free P5 input covered by schema v1. */
    public static function snapshot(Characterization $characterization): array
    {
        $form = $characterization->form_data;
        if (! is_array($form)) {
            throw new InvalidArgumentException('Malformed P5 form.');
        }
        $nace = $characterization->nace_code;
        // Seeder codes (A, A1, A1.1, A1.1.0) and historical numeric NACE
        // groups/classes (10.1, 10.11). Missing NACE retains its v1 null byte.
        if ($nace !== null && (! is_string($nace)
            || ! preg_match('/^(?:[A-V](?:[1-9][0-9]?(?:\.[0-9]){0,2})?|[0-9]{2}\.[0-9]{1,2})$/D', $nace))) {
            throw new InvalidArgumentException('Malformed P5 NACE code.');
        }

        return self::normalize([
            'nace_code' => $nace,
            'company_profile' => self::section($form, 'company_profile', self::COMPANY_FIELDS, [
                'headquarters_country' => CharacterizationOptions::headquartersCountries(),
                'reporting_scope' => CharacterizationOptions::reportingScopes(),
                'reporting_currency' => CharacterizationOptions::reportingCurrencies(),
                'product_service_type' => CharacterizationOptions::productServiceTypes(),
            ]),
            'operations' => self::section($form, 'operations', [...self::OPERATIONS_FIELDS, 'regions', 'value_chain'], [
                'employee_count_range' => CharacterizationOptions::employeeCountRanges(),
                'revenue_range' => CharacterizationOptions::revenueRanges(),
                'regions' => CharacterizationOptions::regions(),
                'value_chain' => CharacterizationOptions::valueChainPositions(),
            ]),
            'activity_questions' => self::section($form, 'activity_questions', self::ACTIVITY_FIELDS,
                array_fill_keys(self::ACTIVITY_FIELDS, CharacterizationOptions::yesNoUnknown())),
        ]);
    }

    private static function section(array $form, string $section, array $fields, array $enums): array
    {
        if (! array_key_exists($section, $form)) {
            return [];
        }
        $values = $form[$section];
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidArgumentException('Malformed P5 '.$section.'.');
        }
        $result = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }
            $value = $values[$field];
            $list = in_array($field, ['regions', 'value_chain'], true);
            if ($list) {
                $valid = is_array($value) && array_is_list($value);
                if ($valid) {
                    foreach ($value as $item) {
                        if (! is_string($item) || ! array_key_exists($item, $enums[$field])) {
                            $valid = false;
                            break;
                        }
                    }
                }
            } elseif ($value === null) {
                // Nullable form scalars retain null; absence is never filled in.
                // The range selectors are optional but not nullable in P5.
                $valid = ! in_array($field, ['employee_count_range', 'revenue_range'], true);
            } elseif (isset($enums[$field])) {
                $valid = is_string($value) && array_key_exists($value, $enums[$field]);
            } else {
                $valid = match ($field) {
                    'reporting_year' => self::integer($value, 2000, (int) date('Y')),
                    'num_subsidiaries_countries' => self::integer($value, 0, 500),
                    'employee_count' => self::integer($value, 1, PHP_INT_MAX),
                    'stock_listed' => in_array($value, [true, false, 0, 1, '0', '1'], true),
                    'revenue' => (is_int($value) || is_float($value)
                            || (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value)))
                        && is_finite((float) $value) && $value >= 0,
                    default => false,
                };
            }
            if (! $valid) {
                // Never echo the rejected value: it may contain private text.
                throw new InvalidArgumentException('Malformed P5 '.$section.'.'.$field.'.');
            }
            $result[$field] = $value;
        }

        return $result;
    }

    private static function integer(mixed $value, int $min, int $max): bool
    {
        return (is_int($value) || (is_string($value)
                && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)
                && filter_var($value, FILTER_VALIDATE_INT) !== false))
            && $value >= $min && $value <= $max;
    }

    // Same canonicalization as CharacterizationStateVersion: recursive object
    // key sorting, preserving list order. JSON errors fail closed here.
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => self::normalize($item), $value);
    }
}
