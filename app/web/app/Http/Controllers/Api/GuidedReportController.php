<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\DocxRenderer;
use App\Services\Report\EvidenceBundleBuilder;
use App\Services\Report\NotMaterialTopicResolver;
use App\Services\Report\ReportIrBuilder;
use Illuminate\Http\Request;

class GuidedReportController extends Controller
{
    public function __construct(
        private readonly ReportIrBuilder $irBuilder,
        private readonly EvidenceBundleBuilder $evidence,
        private readonly DocxRenderer $docx,
        private readonly ReportController $reportController,
        private readonly NotMaterialTopicResolver $notMaterial,
    ) {}

    public function docx(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        [$characterization, $readiness] = $this->readyOrBlock($request, $datapoints);
        if ($readiness !== null) {
            return $readiness;
        }

        $ir = $this->irBuilder->build($characterization);

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

        return response()->json(['data' => $this->evidence->build(
            $this->irBuilder->build($characterization),
            $this->notMaterial->resolve($characterization),
        )]);
    }

    /** @return array{0: ?Characterization, 1: mixed} */
    private function readyOrBlock(Request $request, EsrsDatapointCorpusBuilder $datapoints): array
    {
        $readiness = $this->reportController->show($request, $datapoints)->getData(true)['data'] ?? null;

        if ($readiness === null || ($readiness['status'] ?? null) !== 'ready') {
            return [null, response()->json([
                'data' => ['type' => 'guided_report_blocked', 'status' => $readiness['status'] ?? 'incomplete'],
            ], 409)];
        }

        return [Characterization::forUser($request->user()->id)->first(), null];
    }
}
