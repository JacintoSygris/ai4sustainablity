<?php

namespace App\Services\Report;

use Illuminate\Database\Eloquent\Model;

final class ReportContentReadiness
{
    public function __construct(private readonly ReportClaimBuilder $claimBuilder) {}

    /**
     * @param  iterable<int, Model|array<string, mixed>>  $facts
     * @param  iterable<int, string>|null  $expectedDatapointIds
     * @return array{ready: bool, claimable_count: int, required_count: int, resolved_required_count: int, missing_datapoint_ids: list<string>, reasons: list<string>}
     */
    public function assess(iterable $facts, iterable|null $expectedDatapointIds = null): array
    {
        $normalizedFacts = [];
        $expected = [];
        $scopeIsExplicit = $expectedDatapointIds !== null;

        foreach ($facts as $fact) {
            $normalizedFacts[] = $fact instanceof Model ? $fact->toArray() : $fact;
        }

        if ($expectedDatapointIds !== null) {
            foreach ($expectedDatapointIds as $datapointId) {
                $datapointId = trim((string) $datapointId);
                if ($datapointId !== '') {
                    $expected[$datapointId] = true;
                }
            }
        }
        $expected = array_keys($expected);
        sort($expected);

        if ($normalizedFacts === []) {
            return [
                'ready' => false,
                'claimable_count' => 0,
                'required_count' => count($expected),
                'resolved_required_count' => 0,
                'missing_datapoint_ids' => $expected,
                'reasons' => $expected === []
                    ? ['no_persisted_facts']
                    : ['no_persisted_facts', 'completed_datapoint_facts_missing'],
            ];
        }

        $scopedFacts = $scopeIsExplicit
            ? array_values(array_filter(
                $normalizedFacts,
                fn (array $fact): bool => in_array((string) ($fact['datapoint_id'] ?? ''), $expected, true),
            ))
            : $normalizedFacts;

        $reasons = [];

        foreach ($scopedFacts as $fact) {
            if (! in_array($fact['approval_status'] ?? null, ['reviewed', 'approved'], true)) {
                $reasons[] = 'fact_status_not_reviewed';
                break;
            }
        }

        foreach ($scopedFacts as $fact) {
            if (($fact['applicability'] ?? null) === 'blocked' || ($fact['blocking_reasons'] ?? []) !== []) {
                $reasons[] = 'fact_blocking_reasons_present';
                break;
            }
        }

        $claims = $this->claimBuilder->build(
            $scopedFacts,
            'readiness_check',
            'readiness_check',
        );

        if ($claims === []) {
            $reasons[] = 'no_claimable_report_content';
        }

        $claimableDatapointIds = array_values(array_unique(array_filter(
            array_column($claims, 'datapoint_id'),
            'is_string',
        )));
        sort($claimableDatapointIds);
        $missingDatapointIds = array_values(array_diff($expected, $claimableDatapointIds));

        if ($missingDatapointIds !== []) {
            $reasons[] = 'completed_datapoint_facts_missing';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'ready' => $reasons === [],
            'claimable_count' => count($claims),
            'required_count' => count($expected),
            'resolved_required_count' => count($expected) - count($missingDatapointIds),
            'missing_datapoint_ids' => $missingDatapointIds,
            'reasons' => $reasons,
        ];
    }
}
