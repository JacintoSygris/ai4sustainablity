<?php

namespace App\Support;

use App\Models\Characterization;
use Illuminate\Support\Arr;

/**
 * Computes the merged-state version bound to a document extraction: the sha256
 * of the canonical JSON of `form_data.company_profile` + `form_data.operations`
 * (P6 document extraction contract v0). Canonical means associative keys are
 * sorted recursively so semantically identical states hash identically.
 */
final class CharacterizationStateVersion
{
    public static function hash(Characterization $characterization): string
    {
        $formData = $characterization->form_data ?? [];

        $state = [
            'company_profile' => self::arrayOrEmpty(Arr::get($formData, 'company_profile')),
            'operations' => self::arrayOrEmpty(Arr::get($formData, 'operations')),
        ];

        return hash('sha256', self::canonicalJson($state));
    }

    private static function canonicalJson(array $state): string
    {
        $encoded = json_encode(
            self::normalize($state),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return is_string($encoded) ? $encoded : '';
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => self::normalize($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => self::normalize($item), $value);
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
