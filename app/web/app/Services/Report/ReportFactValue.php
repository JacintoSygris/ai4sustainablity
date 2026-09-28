<?php

namespace App\Services\Report;

use RuntimeException;

final class ReportFactValue
{
    /**
     * Return the scalar carried by a typed report fact or claim.
     *
     * New numeric, boolean, enumeration and date values use value.value;
     * text uses value.text. The amount/number aliases remain readable for
     * immutable snapshots created before the canonical contract was enforced.
     *
     * @param  array<string, mixed>  $fact
     */
    public static function scalar(array $fact): string|int|float|bool|null
    {
        if (($fact['nil'] ?? false) === true || ($fact['value'] ?? null) === null) {
            return null;
        }

        $valueType = (string) ($fact['value_type'] ?? '');
        $value = $fact['value'];

        if (is_array($value)) {
            // Snapshots created before reporting_fact_v1 did not persist
            // value_type, but their textual envelope is unambiguous and must
            // remain renderable as immutable historical evidence.
            if ($valueType === '' && array_key_exists('text', $value)) {
                $valueType = 'text';
            }

            $keys = $valueType === 'text'
                ? ['text']
                : ['value', 'amount', 'number'];

            $found = false;
            foreach ($keys as $key) {
                if (array_key_exists($key, $value)) {
                    $value = $value[$key];
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                throw new RuntimeException('Unsupported factual value shape for value_type='.$valueType.'.');
            }
        }

        return match ($valueType) {
            'text', 'enumeration', 'date' => is_string($value)
                ? $value
                : throw new RuntimeException('Factual '.$valueType.' value must be a string.'),
            'number', 'monetary' => self::numeric($value, $valueType),
            'integer' => self::integer($value),
            'boolean' => is_bool($value)
                ? $value
                : throw new RuntimeException('Factual boolean value must be a boolean.'),
            default => is_string($value) || is_int($value) || is_float($value) || is_bool($value)
                ? $value
                : throw new RuntimeException('Unsupported factual value type.'),
        };
    }

    private static function numeric(mixed $value, string $valueType): string|int|float
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new RuntimeException('Factual '.$valueType.' value must be finite.');
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/', $value) === 1) {
            return $value;
        }

        throw new RuntimeException('Factual '.$valueType.' value must be numeric.');
    }

    private static function integer(mixed $value): string|int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[+-]?\d+\z/', $value) === 1) {
            return $value;
        }

        throw new RuntimeException('Factual integer value must be an integer.');
    }
}
