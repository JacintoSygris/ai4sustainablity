<?php

namespace App\Services\Report;

use App\Models\ReportAuditEvent;
use App\Models\Characterization;
use App\Services\EsrsDatapointCorpusBuilder;
use DomainException;
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
        $reasons = [];
        try {
            $this->builder->assertFrozenSnapshotIntegrity($snapshot);
            $live = $this->builder->buildCanonicalState($characterization, captureXbrl: false, captureScope: false, captureP9: false);
        } catch (DomainException|ReportingProfileException) {
            return ['is_stale' => true, 'stale_state' => ReportSnapshot::STALE_STALE, 'reasons' => ['approved_snapshot_invalid'], 'hashes' => []];
        }
        $assets = null;
        if (ReportScopeAssets::required($snapshot)) {
            if (($snapshot->snapshot_json['schema_version'] ?? null) !== 'report_snapshot_v2') {
                $reasons[] = 'scope_identity_missing';
            } else {
                try {
                    $assets = ReportScopeAssets::capture();
                    // Normative differences must still be detected when P8 needs reconfirmation.
                    $scope = app(EsrsDatapointCorpusBuilder::class)->buildPreview($characterization, $assets);
                    $reasons = array_merge($reasons, $assets->differences($snapshot->source_manifest['scope'], $scope));
                } catch (ReportScopeException|\Illuminate\Http\Exceptions\HttpResponseException) {
                    $reasons[] = 'scope_assets_unavailable';
                }
            }
        }
        if (\App\Services\Dma\P8Authority::enabled()) {
            try {
                $corpusBuilder = app(EsrsDatapointCorpusBuilder::class);
                $latest = $assets !== null
                    ? $corpusBuilder->buildCaptured($characterization, $assets)
                    : $corpusBuilder->build($characterization);
                $frozen = $snapshot->source_manifest['p9_context_v1']['corpus'] ?? [];
                if (($frozen['p9_authority_digest'] ?? null) !== $latest['p9_authority_digest']) { $reasons[] = 'p9_authority_changed'; }
                foreach (['mapping', 'ig3', 'rules'] as $asset) {
                    if (($frozen['p9_assets'][$asset] ?? null) !== $latest['p9_assets'][$asset]) { $reasons[] = 'p9_'.$asset.'_changed'; }
                }
                if (app(\App\Services\EsrsDatapointResponseState::class)->state($characterization, $latest)['authority_review_required']) {
                    $reasons[] = 'iro_review_required';
                }
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
                $reasons[] = $exception->getResponse()->getData(true)['code'] ?? 'p9_authority_unavailable';
            }
        }

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
            Characterization::query()->whereKey($snapshot->characterization_id)->lockForUpdate()->firstOrFail();
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
