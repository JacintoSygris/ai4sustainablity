<?php

namespace App\Services;

use App\Models\Characterization;
use App\Support\CharacterizationOptions;
use InvalidArgumentException;
use JsonException;

/**
 * Private transient projection only: no frozen case, rights or operational consumer.
 * A future caller must lock/check live characterization and persist values atomically with its case.
 */
final class LearningP5Snapshot
{
    public const P5_INPUT_SCHEMA_VERSION = 'p5-learning-input-v1';

    public const FEATURE_SCHEMA_VERSION = 'learning-p5-features-v1';

    public const TRANSFORM_VERSION = 'p5-small-categorical-v1';

    /** @return array{schema_version: string, digest: string, values: array{employee_count_range: ?string, headquarters_country: ?string, stock_listed: ?bool}} */
    public function project(Characterization $characterization): array
    {
        // Read current raw storage: the model cast loses JSON errors as null.
        $rawForm = $characterization->getAttributes()['form_data'] ?? null;
        if ($rawForm !== null && ! is_string($rawForm)) {
            throw new InvalidArgumentException('learning_p5.form_data_invalid');
        }
        try {
            $decodedForm = $rawForm === null ? null : json_decode($rawForm, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('learning_p5.form_data_invalid', 0, $exception);
        }
        $form = $this->section($decodedForm, 'form_data');
        $profile = $this->section($form['company_profile'] ?? null, 'company_profile');
        $operations = $this->section($form['operations'] ?? null, 'operations');
        $country = $profile['headquarters_country'] ?? null;
        $employees = $operations['employee_count_range'] ?? null;
        $stock = $profile['stock_listed'] ?? null;

        if ($country !== null && (! is_string($country) || ! array_key_exists($country, CharacterizationOptions::headquartersCountries()))) {
            throw new InvalidArgumentException('learning_p5.headquarters_country_invalid');
        }
        if ($employees !== null && (! is_string($employees) || ! array_key_exists($employees, CharacterizationOptions::employeeCountRanges()))) {
            throw new InvalidArgumentException('learning_p5.employee_count_range_invalid');
        }
        if ($stock !== null && ! is_bool($stock)) {
            throw new InvalidArgumentException('learning_p5.stock_listed_invalid');
        }

        // Exact three scalar fields in lexical key order; no source metadata enters the digest.
        $values = [
            'employee_count_range' => $employees,
            'headquarters_country' => $country,
            'stock_listed' => $stock,
        ];
        $canonicalValues = json_encode(
            $values,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_LINE_TERMINATORS
            | JSON_PRESERVE_ZERO_FRACTION,
        );

        return [
            'schema_version' => self::P5_INPUT_SCHEMA_VERSION,
            'digest' => hash('sha256', $canonicalValues),
            'values' => $values,
        ];
    }

    /** @return array<string, mixed> */
    private function section(mixed $value, string $name): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('learning_p5.'.$name.'_invalid');
        }

        return $value;
    }
}
