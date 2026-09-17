<?php

namespace App\Support;

use Illuminate\Support\Arr;

class DoubleMaterialityProcessState
{
    private const CHECKLIST_DEFAULTS = [
        'identified_stakeholders' => false,
        'assessed_impacts' => false,
        'assessed_financial_effects' => false,
        'reached_conclusions' => false,
    ];

    private const ACTA_DEFAULTS = [
        'completed_on' => null,
        'method' => null,
        'participants' => null,
    ];

    /**
     * @param  array<string, mixed>  $formData
     * @return array<string, mixed>
     */
    public static function fromFormData(array $formData): array
    {
        $stored = Arr::get($formData, 'double_materiality_process', []);
        $stored = is_array($stored) ? $stored : [];

        $checklist = self::normalizeChecklist(Arr::get($stored, 'checklist', []));
        $acta = self::normalizeActa(Arr::get($stored, 'acta', []));
        $actaRegistered = collect($acta)->every(fn ($value): bool => filled($value));
        $checklistComplete = collect($checklist)->every(fn (bool $value): bool => $value);
        $hasAnyChecklistFlag = collect($checklist)->contains(fn (bool $value): bool => $value);
        $hasAnyActaField = collect($acta)->contains(fn ($value): bool => filled($value));

        return [
            'checklist' => $checklist,
            'acta' => $acta,
            'acta_registered' => $actaRegistered,
            'guide_status' => match (true) {
                $actaRegistered || $checklistComplete => 'ready',
                $hasAnyChecklistFlag || $hasAnyActaField => 'in_progress',
                default => 'missing',
            },
            'updated_at' => Arr::get($stored, 'updated_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, bool>
     */
    public static function checklistFromPayload(array $values): array
    {
        $checklist = self::CHECKLIST_DEFAULTS;

        foreach (array_keys(self::CHECKLIST_DEFAULTS) as $key) {
            if (array_key_exists($key, $values)) {
                $checklist[$key] = (bool) $values[$key];
            }
        }

        return $checklist;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    public static function actaFromPayload(array $values): array
    {
        $acta = self::ACTA_DEFAULTS;

        foreach (array_keys(self::ACTA_DEFAULTS) as $key) {
            if (array_key_exists($key, $values)) {
                $acta[$key] = filled($values[$key]) ? (string) $values[$key] : null;
            }
        }

        return $acta;
    }

    /**
     * @param  mixed  $values
     * @return array<string, bool>
     */
    private static function normalizeChecklist(mixed $values): array
    {
        $values = is_array($values) ? $values : [];

        return self::checklistFromPayload($values);
    }

    /**
     * @param  mixed  $values
     * @return array<string, string|null>
     */
    private static function normalizeActa(mixed $values): array
    {
        $values = is_array($values) ? $values : [];

        return self::actaFromPayload($values);
    }
}
