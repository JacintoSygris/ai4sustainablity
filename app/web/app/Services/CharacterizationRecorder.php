<?php

namespace App\Services;

use App\Jobs\SubmitCharacterizationJob;
use App\Models\Characterization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;

class CharacterizationRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(User $user, array $payload): Characterization
    {
        try {
            return $this->saveOnce($user, $payload);
        } catch (QueryException $exception) {
            if (! $this->isCharacterizationUserUniqueViolation($exception)) {
                throw $exception;
            }

            return $this->saveOnce($user->fresh() ?? $user, $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveOnce(User $user, array $payload): Characterization
    {
        $characterization = $user->characterization()->first()
            ?? $user->characterization()->make();

        $previousStatus = $characterization->status ?? Characterization::STATUS_DRAFT;
        $status = Arr::get($payload, 'status', Characterization::STATUS_DRAFT);

        if ($status === Characterization::STATUS_SUBMITTED && in_array($previousStatus, [
            Characterization::STATUS_SUBMITTED,
            Characterization::STATUS_WAITING,
            Characterization::STATUS_PROCESSING,
        ], true)) {
            return $characterization->refresh();
        }

        $formData = $characterization->form_data ?? [];

        $formData['company_profile']['nace_code'] = Arr::get(
            $payload,
            'nace_code',
            Arr::get($formData, 'company_profile.nace_code')
        );

        $companyProfilePayload = Arr::get($payload, 'form_data.company_profile', []);
        foreach ([
            'company_name',
            'headquarters_country',
            'reporting_scope',
            'reporting_currency',
            'product_service_type',
        ] as $field) {
            if (array_key_exists($field, $companyProfilePayload)) {
                $formData['company_profile'][$field] = $companyProfilePayload[$field];
            }
        }

        if (array_key_exists('reporting_year', $companyProfilePayload)) {
            $formData['company_profile']['reporting_year'] = $companyProfilePayload['reporting_year'] !== null
                ? (int) $companyProfilePayload['reporting_year']
                : null;
        }

        if (array_key_exists('num_subsidiaries_countries', $companyProfilePayload)) {
            $formData['company_profile']['num_subsidiaries_countries'] = $companyProfilePayload['num_subsidiaries_countries'] !== null
                ? (int) $companyProfilePayload['num_subsidiaries_countries']
                : null;
        }

        if (array_key_exists('stock_listed', $companyProfilePayload)) {
            $formData['company_profile']['stock_listed'] = $this->toNullableBoolean($companyProfilePayload['stock_listed']);
        }

        if (array_key_exists('esrs_topic_ids', $payload)) {
            $formData['esg_focus']['topic_ids'] = $payload['esrs_topic_ids'] ?? [];
        }

        $operationsPayload = Arr::get($payload, 'form_data.operations', []);
        if (! empty($operationsPayload)) {
            $formData['operations'] = array_merge(
                Arr::get($formData, 'operations', []),
                [
                    'regions' => $operationsPayload['regions'] ?? Arr::get($formData, 'operations.regions', []),
                    'value_chain' => $operationsPayload['value_chain'] ?? Arr::get($formData, 'operations.value_chain', []),
                    'employee_count_range' => $operationsPayload['employee_count_range'] ?? Arr::get($formData, 'operations.employee_count_range'),
                    'revenue_range' => $operationsPayload['revenue_range'] ?? Arr::get($formData, 'operations.revenue_range'),
                    'employee_count' => $operationsPayload['employee_count'] ?? Arr::get($formData, 'operations.employee_count'),
                    'revenue' => $operationsPayload['revenue'] ?? Arr::get($formData, 'operations.revenue'),
                    'notes' => $operationsPayload['notes'] ?? Arr::get($formData, 'operations.notes'),
                ]
            );
        }

        $csrdOrientationPayload = Arr::get($payload, 'form_data.csrd_orientation', []);
        if (is_array($csrdOrientationPayload) && ! empty($csrdOrientationPayload)) {
            $formData['csrd_orientation'] = $this->mergeCsrdOrientation(
                Arr::get($formData, 'csrd_orientation', []),
                $csrdOrientationPayload
            );
        }

        $dataReadinessPayload = Arr::get($payload, 'form_data.data_readiness', []);
        if (is_array($dataReadinessPayload) && ! empty($dataReadinessPayload)) {
            $formData['data_readiness'] = $this->mergeDataReadiness(
                Arr::get($formData, 'data_readiness', []),
                $dataReadinessPayload
            );
        }

        $formData['notes'] = Arr::get($payload, 'form_data.notes', Arr::get($formData, 'notes'));

        if ($status === Characterization::STATUS_SUBMITTED) {
            Arr::forget($formData, 'materiality_proposal_review');
        }

        $attributes = [
            'nace_code' => Arr::get($formData, 'company_profile.nace_code'),
            'esrs_topic_ids' => Arr::get($formData, 'esg_focus.topic_ids', []),
            'form_data' => $formData,
        ];

        if ($status === Characterization::STATUS_SUBMITTED) {
            $attributes['submitted_at'] = now();
            $attributes['retry_count'] = 0;
            $attributes['next_retry_at'] = null;
            $attributes['last_job_attempted_at'] = null;
            $attributes['result_data'] = null;
            $attributes['completed_at'] = null;
            $attributes['last_error'] = null;
        }

        $characterization->updateStatus($status, $attributes);

        if ($status === Characterization::STATUS_SUBMITTED) {
            Bus::dispatch(new SubmitCharacterizationJob($characterization));
        }

        return $characterization->refresh();
    }

    private function isCharacterizationUserUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        if (! $exception instanceof UniqueConstraintViolationException && ! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'characterizations_user_id_unique')
            || str_contains($message, 'characterizations.user_id')
            || (
                str_contains($message, 'duplicate entry')
                && str_contains($message, 'characterizations')
                && str_contains($message, 'user_id')
            );
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeCsrdOrientation(array $existing, array $payload): array
    {
        if (array_key_exists('enabled', $payload)) {
            $existing['enabled'] = $this->toBoolean($payload['enabled']);
        }

        foreach (['listed_entity', 'public_interest_entity', 'reports_as_group'] as $field) {
            if (array_key_exists($field, $payload)) {
                $existing[$field] = $payload[$field];
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeDataReadiness(array $existing, array $payload): array
    {
        if (array_key_exists('enabled', $payload)) {
            $existing['enabled'] = $this->toBoolean($payload['enabled']);
        }

        if (! array_key_exists('items', $payload) || ! is_array($payload['items'])) {
            return $existing;
        }

        $existing['items'] = $existing['items'] ?? [];

        foreach ($payload['items'] as $itemKey => $itemPayload) {
            if (! is_array($itemPayload)) {
                continue;
            }

            $existing['items'][$itemKey] = $this->mergeDataReadinessItem(
                $existing['items'][$itemKey] ?? [],
                $itemPayload
            );
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeDataReadinessItem(array $existing, array $payload): array
    {
        foreach (['available', 'source', 'traceability'] as $field) {
            if (array_key_exists($field, $payload)) {
                $existing[$field] = $payload[$field];
            }
        }

        if (array_key_exists('year', $payload)) {
            $existing['year'] = $payload['year'] !== null
                ? (int) $payload['year']
                : null;
        }

        if (array_key_exists('verified', $payload)) {
            $existing['verified'] = $this->toNullableBoolean($payload['verified']);
        }

        return $existing;
    }

    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function toNullableBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
