<?php

namespace App\Services\Report;

use App\Models\ReportAuditEvent;
use App\Models\ReportSnapshot;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($snapshot): array {
            $locked = ReportSnapshot::query()
                ->whereKey($snapshot->id)
                ->lockForUpdate()
                ->firstOrFail();
            $result = $this->detect($locked);

            if ($locked->stale_state === ReportSnapshot::STALE_STALE) {
                $result['is_stale'] = true;
                $result['stale_state'] = ReportSnapshot::STALE_STALE;
                $result['reasons'] = $locked->stale_reasons ?? [];

                return $result;
            }

            if (! $result['is_stale']) {
                return $result;
            }

            $locked->forceFill([
                'stale_state' => ReportSnapshot::STALE_STALE,
                'stale_reasons' => $result['reasons'],
            ])->save();

            ReportAuditEvent::create([
                'user_id' => $locked->user_id,
                'characterization_id' => $locked->characterization_id,
                'report_snapshot_id' => $locked->id,
                'event_type' => 'snapshot_stale_detected',
                'payload' => [
                    'snapshot_id' => $locked->id,
                    'characterization_id' => $locked->characterization_id,
                    'snapshot_hash' => $locked->snapshot_hash,
                    'reasons' => $result['reasons'],
                    'hashes' => $result['hashes'],
                ],
            ]);

            return $result;
        }, 3);
    }
}
