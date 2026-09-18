<?php

namespace App\Services\Report;

class ReportClaimBuilder
{
    /**
     * @param  iterable<int, array<string, mixed>>  $facts
     * @return list<array<string, mixed>>
     */
    public function build(iterable $facts, string $snapshotHash, string $profileId): array
    {
        $claims = [];

        foreach ($facts as $fact) {
            if (! $this->isClaimable($fact)) {
                continue;
            }

            $normalized = $this->canonicalizeClaimInput($fact);
            $claims[] = [
                'claim_id' => $this->claimId($normalized, $snapshotHash),
                'schema_version' => 'report_claim_v1',
                'fact_id' => $normalized['fact_id'],
                'datapoint_id' => $normalized['datapoint_id'],
                'value_type' => $normalized['value_type'],
                'value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'decimals' => $normalized['decimals'],
                'dimensions' => $normalized['dimensions'],
                'language' => $normalized['language'],
                'applicability' => $normalized['applicability'],
                'nil' => $normalized['nil'],
                'nil_reason' => $normalized['nil_reason'],
                'evidence_refs' => $normalized['evidence_refs'],
                'approval_status' => $normalized['approval_status'],
                'provenance' => [
                    'source' => 'reporting_fact_v1',
                    'snapshot_hash' => $snapshotHash,
                    'profile_id' => $profileId,
                ],
            ];
        }

        usort($claims, fn (array $left, array $right): int => $left['fact_id'] <=> $right['fact_id']);

        return array_map(fn (array $claim): array => $this->canonicalize($claim), $claims);
    }

    /**
     * @param  array<string, mixed>  $fact
     */
    private function isClaimable(array $fact): bool
    {
        if (! in_array($fact['approval_status'] ?? null, ['reviewed', 'approved'], true)) {
            return false;
        }

        if (($fact['applicability'] ?? null) !== 'applicable') {
            return false;
        }

        if (($fact['blocking_reasons'] ?? []) !== []) {
            return false;
        }

        if (($fact['nil'] ?? false) === true && ! $this->hasAffirmableValue($fact['value'] ?? null)) {
            return false;
        }

        return true;
    }

    private function hasAffirmableValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasAffirmableValue($item)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @return array<string, mixed>
     */
    private function canonicalizeClaimInput(array $fact): array
    {
        return $this->canonicalize([
            'fact_id' => (string) $fact['fact_id'],
            'datapoint_id' => (string) $fact['datapoint_id'],
            'value_type' => $fact['value_type'] ?? null,
            'value' => $fact['value'] ?? null,
            'unit' => $fact['unit'] ?? null,
            'decimals' => $fact['decimals'] ?? null,
            'dimensions' => $this->canonicalizeList($fact['dimensions'] ?? []),
            'language' => $fact['language'] ?? null,
            'applicability' => $fact['applicability'] ?? null,
            'nil' => (bool) ($fact['nil'] ?? false),
            'nil_reason' => $fact['nil_reason'] ?? null,
            'evidence_refs' => $this->canonicalizeList($fact['evidence_refs'] ?? []),
            'approval_status' => $fact['approval_status'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fact
     */
    private function claimId(array $fact, string $snapshotHash): string
    {
        $identity = [
            'snapshot_hash' => $snapshotHash,
            'fact_id' => $fact['fact_id'],
            'datapoint_id' => $fact['datapoint_id'],
            'value_type' => $fact['value_type'],
            'dimensions' => $fact['dimensions'],
            'language' => $fact['language'],
        ];

        return 'claim_'.substr(hash('sha256', $this->canonicalJson($identity)), 0, 32);
    }

    private function canonicalizeList(mixed $value): array
    {
        $items = is_array($value) ? array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value) : [];

        usort($items, fn (mixed $left, mixed $right): int => $this->canonicalJson($left) <=> $this->canonicalJson($right));

        return array_values($items);
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
