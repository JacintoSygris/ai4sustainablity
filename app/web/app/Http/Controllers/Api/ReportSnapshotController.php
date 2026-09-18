<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\ReportApproval;
use App\Models\ReportAuditEvent;
use App\Models\ReportingFact;
use App\Models\ReportSnapshot;
use App\Services\Report\ReportSnapshotBuilder;
use App\Services\Report\ReportStalenessDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReportSnapshotController extends Controller
{
    public function store(Request $request, ReportSnapshotBuilder $builder): JsonResponse
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        if (! $characterization) {
            return response()->json(['message' => 'No characterization found.'], 404);
        }

        $result = $builder->createOrFind($characterization);
        $snapshot = $result['snapshot'];

        return response()->json(['data' => $this->snapshotSummary($snapshot)], $result['created'] ? 201 : 200);
    }

    public function index(Request $request, ReportStalenessDetector $detector): JsonResponse
    {
        $snapshots = ReportSnapshot::query()
            ->with('approval')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ReportSnapshot $snapshot) use ($detector): array {
                $detector->refreshState($snapshot);

                return $this->snapshotSummary($snapshot);
            })
            ->values()
            ->all();

        return response()->json(['data' => $snapshots]);
    }

    public function approve(
        Request $request,
        int $snapshot,
        ReportStalenessDetector $detector,
    ): JsonResponse {
        $reportSnapshot = ReportSnapshot::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($snapshot)
            ->firstOrFail();

        $staleness = $detector->refreshState($reportSnapshot);

        if ($staleness['is_stale']) {
            return response()->json([
                'message' => 'The report snapshot is stale.',
                'code' => 'report_stale',
                'reasons' => $staleness['reasons'],
            ], 409);
        }

        $reviewability = $this->reviewability($reportSnapshot);

        if (! $reviewability['reviewable']) {
            return response()->json([
                'message' => 'The report snapshot is not reviewable.',
                'code' => 'report_not_reviewable',
                'reasons' => $reviewability['reasons'],
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'single_person_declaration' => ['required', 'string', 'min:1'],
        ]);

        if ($validator->fails() || trim((string) $request->input('single_person_declaration', '')) === '') {
            return response()->json([
                'message' => 'A single-person approval declaration is required.',
                'code' => 'single_person_declaration_required',
                'errors' => $validator->errors(),
            ], 422);
        }

        $approval = ReportApproval::create([
            'report_snapshot_id' => $reportSnapshot->id,
            'user_id' => $request->user()->id,
            'role_mode' => ReportApproval::ROLE_MODE_SINGLE_PERSON_DECLARED,
            'preparer_user_id' => $request->user()->id,
            'reviewer_user_id' => $request->user()->id,
            'approver_user_id' => $request->user()->id,
            'single_person_declaration' => trim((string) $request->input('single_person_declaration')),
            'snapshot_hash' => $reportSnapshot->snapshot_hash,
            'approved_at' => now(),
        ]);

        ReportAuditEvent::create([
            'user_id' => $request->user()->id,
            'characterization_id' => $reportSnapshot->characterization_id,
            'report_snapshot_id' => $reportSnapshot->id,
            'report_approval_id' => $approval->id,
            'event_type' => 'snapshot_approved',
            'payload' => [
                'snapshot_id' => $reportSnapshot->id,
                'approval_id' => $approval->id,
                'characterization_id' => $reportSnapshot->characterization_id,
                'snapshot_hash' => $reportSnapshot->snapshot_hash,
                'role_mode' => $approval->role_mode,
                'preparer_user_id' => $approval->preparer_user_id,
                'reviewer_user_id' => $approval->reviewer_user_id,
                'approver_user_id' => $approval->approver_user_id,
            ],
        ]);

        return response()->json(['data' => $this->approvalSummary($approval)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSummary(ReportSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'characterization_id' => $snapshot->characterization_id,
            'profile_id' => $snapshot->profile_id,
            'profile_hash' => $snapshot->profile_hash,
            'facts_hash' => $snapshot->facts_hash,
            'characterization_hash' => $snapshot->characterization_hash,
            'snapshot_hash' => $snapshot->snapshot_hash,
            'stale_state' => $snapshot->stale_state,
            'stale_reasons' => $snapshot->stale_reasons ?? [],
            'approval' => $snapshot->relationLoaded('approval')
                ? $this->publicApprovalSummary($snapshot->approval)
                : $this->publicApprovalSummary($snapshot->approval()->first()),
            'is_approved' => $snapshot->relationLoaded('approval')
                ? $snapshot->approval !== null
                : $snapshot->approval()->exists(),
            'created_at' => $snapshot->created_at?->toJSON(),
            'updated_at' => $snapshot->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicApprovalSummary(?ReportApproval $approval): ?array
    {
        if (! $approval) {
            return null;
        }

        return [
            'id' => $approval->id,
            'role_mode' => $approval->role_mode,
            'approved_at' => $approval->approved_at?->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalSummary(ReportApproval $approval): array
    {
        return [
            'id' => $approval->id,
            'report_snapshot_id' => $approval->report_snapshot_id,
            'role_mode' => $approval->role_mode,
            'preparer_user_id' => $approval->preparer_user_id,
            'reviewer_user_id' => $approval->reviewer_user_id,
            'approver_user_id' => $approval->approver_user_id,
            'snapshot_hash' => $approval->snapshot_hash,
            'approved_at' => $approval->approved_at?->toJSON(),
        ];
    }

    /**
     * @return array{reviewable: bool, reasons: list<string>}
     */
    private function reviewability(ReportSnapshot $snapshot): array
    {
        $facts = ReportingFact::query()
            ->where('characterization_id', $snapshot->characterization_id)
            ->orderBy('fact_id')
            ->get();
        $reasons = [];

        if ($facts->isEmpty()) {
            $reasons[] = 'no_persisted_facts';
        }

        foreach ($facts as $fact) {
            if (! in_array($fact->approval_status, ['reviewed', 'approved'], true)) {
                $reasons[] = 'fact_status_not_reviewed';
                break;
            }
        }

        foreach ($facts as $fact) {
            if ($fact->applicability === 'blocked' || count($fact->blocking_reasons ?? []) > 0) {
                $reasons[] = 'fact_blocking_reasons_present';
                break;
            }
        }

        return [
            'reviewable' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }
}
