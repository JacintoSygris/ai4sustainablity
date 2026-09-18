<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\ReportSnapshot;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ArelleXhtmlIxbrlValidator;
use App\Services\Report\DocxRenderer;
use App\Services\Report\EvidenceBundleBuilder;
use App\Services\Report\ExternalTaxonomyManifestRepository;
use App\Services\Report\HtmlReportRenderer;
use App\Services\Report\ReportIrBuilder;
use App\Services\Report\ReportStalenessDetector;
use App\Services\Report\ReportingProfileException;
use App\Services\Report\ReportingProfileRepository;
use App\Services\Report\XhtmlIxbrlCandidateException;
use App\Services\Report\XhtmlIxbrlCandidateRenderer;
use DomainException;
use Illuminate\Http\Request;
use RuntimeException;

class GuidedReportController extends Controller
{
    public function __construct(
        private readonly ReportIrBuilder $irBuilder,
        private readonly EvidenceBundleBuilder $evidence,
        private readonly DocxRenderer $docx,
        private readonly HtmlReportRenderer $html,
        private readonly ReportController $reportController,
        private readonly ReportStalenessDetector $staleness,
        private readonly ReportingProfileRepository $profiles,
        private readonly ExternalTaxonomyManifestRepository $externalTaxonomyManifest,
        private readonly XhtmlIxbrlCandidateRenderer $xhtmlIxbrl,
        private readonly ArelleXhtmlIxbrlValidator $arelle,
    ) {}

    public function docx(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        [$characterization, $readiness] = $this->readyOrBlock($request, $datapoints);
        if ($readiness !== null) {
            return $readiness;
        }

        [$snapshot, $snapshotBlock] = $this->approvedFreshSnapshotOrBlock($request);
        if ($snapshotBlock !== null) {
            return $snapshotBlock;
        }

        try {
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
        } catch (DomainException) {
            return $this->blocked('approved_snapshot_invalid');
        }

        return response($this->docx->render($ir), 200)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->header('Content-Disposition', 'attachment; filename="informe-esrs-borrador.docx"');
    }

    public function evidenceBundle(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        [$characterization, $readiness] = $this->readyOrBlock($request, $datapoints);
        if ($readiness !== null) {
            return $readiness;
        }

        [$snapshot, $snapshotBlock] = $this->approvedFreshSnapshotOrBlock($request);
        if ($snapshotBlock !== null) {
            return $snapshotBlock;
        }

        try {
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
        } catch (DomainException) {
            return $this->blocked('approved_snapshot_invalid');
        }

        return response()->json(['data' => $this->evidence->build($ir)]);
    }

    public function html(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        [$characterization, $readiness] = $this->readyOrBlock($request, $datapoints);
        if ($readiness !== null) {
            return $readiness;
        }

        [$snapshot, $snapshotBlock] = $this->approvedFreshSnapshotOrBlock($request);
        if ($snapshotBlock !== null) {
            return $snapshotBlock;
        }

        try {
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
        } catch (DomainException) {
            return $this->blocked('approved_snapshot_invalid');
        }

        return response($this->html->render($ir), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="informe-esrs-borrador.html"');
    }

    public function xhtmlIxbrlCandidate(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        [, $readiness] = $this->readyOrBlock($request, $datapoints, 'xhtml_ixbrl_candidate_blocked');
        if ($readiness !== null) {
            return $readiness;
        }

        [$snapshot, $snapshotBlock] = $this->approvedFreshSnapshotOrBlock($request, 'xhtml_ixbrl_candidate_blocked');
        if ($snapshotBlock !== null) {
            return $snapshotBlock;
        }

        try {
            $profile = $this->profiles->load((string) $snapshot->profile_id);
        } catch (ReportingProfileException) {
            return $this->blocked('approved_snapshot_invalid', 'xhtml_ixbrl_candidate_blocked');
        }

        if (! hash_equals($profile->hash(), (string) $snapshot->profile_hash)) {
            return $this->blocked('approved_snapshot_invalid', 'xhtml_ixbrl_candidate_blocked');
        }

        try {
            $manifest = $this->externalTaxonomyManifest->loadInternalForProfile($profile);
        } catch (RuntimeException $e) {
            return $this->blocked($e->getMessage(), 'xhtml_ixbrl_candidate_blocked');
        }

        try {
            $ir = $this->irBuilder->buildFromApprovedSnapshot($snapshot);
            $xhtml = $this->xhtmlIxbrl->render($ir, $profile, $manifest);
            $this->arelle->validate($xhtml, $profile, $manifest);
        } catch (DomainException) {
            return $this->blocked('approved_snapshot_invalid', 'xhtml_ixbrl_candidate_blocked');
        } catch (XhtmlIxbrlCandidateException $e) {
            return $this->blocked($this->allowedXhtmlIxbrlReason($e->getMessage()), 'xhtml_ixbrl_candidate_blocked');
        }

        return response($xhtml, 200)
            ->header('Content-Type', 'application/xhtml+xml; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="informe-esrs-candidato.xhtml"')
            ->header('X-Report-Publication-State', 'validated_candidate');
    }

    /** @return array{0: ?Characterization, 1: mixed} */
    private function readyOrBlock(
        Request $request,
        EsrsDatapointCorpusBuilder $datapoints,
        string $blockedType = 'guided_report_blocked'
    ): array
    {
        $readiness = $this->reportController->show($request, $datapoints)->getData(true)['data'] ?? null;

        if ($readiness === null || ($readiness['status'] ?? null) !== 'ready') {
            return [null, response()->json([
                'data' => ['type' => $blockedType, 'status' => $readiness['status'] ?? 'incomplete'],
            ], 409)];
        }

        return [Characterization::forUser($request->user()->id)->first(), null];
    }

    /** @return array{0: ?ReportSnapshot, 1: mixed} */
    private function approvedFreshSnapshotOrBlock(Request $request, string $blockedType = 'guided_report_blocked'): array
    {
        $snapshots = ReportSnapshot::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('approval')
            ->with('approval')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($snapshots->isEmpty()) {
            return [null, $this->blocked('approved_snapshot_missing', $blockedType)];
        }

        foreach ($snapshots as $snapshot) {
            $result = $this->staleness->refreshState($snapshot);
            if (! $result['is_stale']) {
                return [$snapshot->fresh(['approval']), null];
            }
        }

        return [null, $this->blocked('approved_snapshot_stale', $blockedType)];
    }

    private function blocked(string $reasonCode, string $type = 'guided_report_blocked')
    {
        return response()->json([
            'data' => [
                'type' => $type,
                'reason_code' => $reasonCode,
            ],
        ], 409);
    }

    private function allowedXhtmlIxbrlReason(string $reasonCode): string
    {
        $allowed = [
            'xhtml_ixbrl_arelle_unavailable',
            'xhtml_ixbrl_arelle_validation_failed',
            'xhtml_ixbrl_concept_unavailable',
            'xhtml_ixbrl_context_missing',
            'xhtml_ixbrl_decimals_invalid',
            'xhtml_ixbrl_dimensions_unsupported',
            'xhtml_ixbrl_no_renderable_claims',
            'xhtml_ixbrl_render_failed',
            'xhtml_ixbrl_unit_invalid',
        ];

        return in_array($reasonCode, $allowed, true)
            ? $reasonCode
            : 'xhtml_ixbrl_arelle_validation_failed';
    }
}
