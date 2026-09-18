<?php

namespace App\Services\Report;

use App\Models\ReportAuditEvent;
use App\Models\ReportSnapshot;

class ReportStalenessDetector
{
    public function __construct(
        private readonly ReportSnapshotBuilder $builder,
    ) {}

    /**
     * @return array{is_stale: bool, stale_state: string, reasons: list<string>, hashes: array<string, string>}
     */
    public function detect(ReportSnapshot $snapshot): array
    {
        $characterization = $snapshot->characterization()->firstOrFail();
        $live = $this->builder->buildCanonicalState($characterization);
        $reasons = [];

        if ($live['characterization_hash'] !== $snapshot->characterization_hash) {
            $reasons[] = 'characterization_changed';
        }

        if ($live['facts_hash'] !== $snapshot->facts_hash) {
            $reasons[] = 'reporting_facts_changed';
        }

        if ($live['profile_hash'] !== $snapshot->profile_hash) {
            $reasons[] = 'reporting_profile_changed';
        }

        return [
            'is_stale' => $reasons !== [],
            'stale_state' => $reasons === [] ? ReportSnapshot::STALE_FRESH : ReportSnapshot::STALE_STALE,
            'reasons' => $reasons,
            'hashes' => [
                'snapshot_hash' => $snapshot->snapshot_hash,
                'live_snapshot_hash' => $live['snapshot_hash'],
                'snapshot_characterization_hash' => $snapshot->characterization_hash,
                'live_characterization_hash' => $live['characterization_hash'],
                'snapshot_facts_hash' => $snapshot->facts_hash,
                'live_facts_hash' => $live['facts_hash'],
                'snapshot_profile_hash' => $snapshot->profile_hash,
                'live_profile_hash' => $live['profile_hash'],
            ],
        ];
    }

    /**
     * @return array{is_stale: bool, stale_state: string, reasons: list<string>, hashes: array<string, string>}
     */
    public function refreshState(ReportSnapshot $snapshot): array
    {
        $result = $this->detect($snapshot);

        $stateChanged = $snapshot->stale_state !== $result['stale_state'] || ($snapshot->stale_reasons ?? []) !== $result['reasons'];

        if ($stateChanged) {
            $snapshot->forceFill([
                'stale_state' => $result['stale_state'],
                'stale_reasons' => $result['reasons'],
            ])->save();
        }

        if ($result['is_stale'] && $stateChanged) {
            ReportAuditEvent::create([
                'user_id' => $snapshot->user_id,
                'characterization_id' => $snapshot->characterization_id,
                'report_snapshot_id' => $snapshot->id,
                'event_type' => 'snapshot_stale_detected',
                'payload' => [
                    'snapshot_id' => $snapshot->id,
                    'characterization_id' => $snapshot->characterization_id,
                    'snapshot_hash' => $snapshot->snapshot_hash,
                    'reasons' => $result['reasons'],
                    'hashes' => $result['hashes'],
                ],
            ]);
        }

        return $result;
    }
}
