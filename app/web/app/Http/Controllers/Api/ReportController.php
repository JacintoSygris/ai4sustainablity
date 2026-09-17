<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ArelleIxbrlCandidateValidator;
use App\Services\Report\IxbrlCandidateBuilder;
use App\Support\DoubleMaterialityProcessState;
use App\Support\EsrsDatapointFactState;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ReportController extends Controller
{
    private const CONFIRMATION_STATUS_CONFIRMED = 'confirmed';

    private const CONFIRMATION_STATUS_MISSING = 'missing';

    public function show(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $context = $this->reportContext($request, $datapoints);

        if (! $context) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => $this->readinessPayload(...$context),
        ]);
    }

    public function draft(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $context = $this->reportContext($request, $datapoints);

        if (! $context) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => $this->draftPayload(...$context),
        ]);
    }

    public function package(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $context = $this->reportContext($request, $datapoints);

        if (! $context) {
            return response()->json(['data' => null]);
        }

        $readiness = $this->readinessPayload(...$context);

        if ($readiness['status'] !== 'ready') {
            return $this->blockedPackageResponse($readiness);
        }

        $draft = $this->draftPayload(...$context);

        return response($this->packageHtml($readiness, $draft), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function evidenceBundle(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $context = $this->reportContext($request, $datapoints);

        if (! $context) {
            return response()->json(['data' => null]);
        }

        $readiness = $this->readinessPayload(...$context);

        if ($readiness['status'] !== 'ready') {
            return $this->blockedPackageResponse($readiness);
        }

        return response()->json([
            'data' => $this->evidenceBundlePayload(...$context),
        ]);
    }

    public function ixbrlCandidate(
        Request $request,
        EsrsDatapointCorpusBuilder $datapoints,
        IxbrlCandidateBuilder $builder,
        ArelleIxbrlCandidateValidator $validator,
    )
    {
        $context = $this->reportContext($request, $datapoints);

        if (! $context) {
            return response()->json(['data' => null], 409);
        }

        $readiness = $this->readinessPayload(...$context);

        if ($readiness['status'] !== 'ready') {
            return $this->blockedIxbrlCandidateResponse([
                'status' => 'blocked',
                'reason_codes' => ['report_package_prerequisites_incomplete'],
                'blocking_datapoint_ids' => [],
                'fact_count' => 0,
                'limitations' => $this->ixbrlCandidateLimitations(),
            ]);
        }

        $state = $this->fullFactState($context[0], $context[1]);
        $preflight = $builder->preflight($state);

        if ($preflight['status'] !== 'available') {
            return $this->blockedIxbrlCandidateResponse($preflight);
        }

        $document = $builder->build($state);
        $validation = $validator->validate($document);
        if ($validation['status'] !== 'passed') {
            return $this->blockedIxbrlCandidateResponse([
                'status' => 'blocked',
                'reason_codes' => $validation['reason_codes'] ?: ['arelle_validation_failed'],
                'blocking_datapoint_ids' => [],
                'fact_count' => $preflight['fact_count'],
                'limitations' => $preflight['limitations'],
                'diagnostics' => $validation['diagnostics'],
            ], 'arelle_validation_failed');
        }

        return response($document->bytes, 200)
            ->header('Content-Type', 'application/xhtml+xml; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$document->filename.'"');
    }

    /**
     * @param  array<string, mixed>  $readiness
     */
    private function blockedPackageResponse(array $readiness)
    {
        return response()->json([
            'data' => [
                'type' => 'report_package_blocked',
                'version' => 'v0',
                'status' => $readiness['status'],
                'sections' => $readiness['sections'],
                'downloads' => $readiness['downloads'],
                'next_actions' => $readiness['next_actions'],
                'limitations' => $readiness['limitations'],
            ],
        ], 409);
    }

    /**
     * @param  array<string, mixed>  $preflight
     */
    private function blockedIxbrlCandidateResponse(array $preflight, string $type = 'ixbrl_candidate_blocked')
    {
        return response()->json([
            'data' => [
                'type' => $type,
                'version' => 'v0',
                'status' => 'blocked',
                'reason_codes' => array_values($preflight['reason_codes'] ?? []),
                'blocking_datapoint_ids' => array_values($preflight['blocking_datapoint_ids'] ?? []),
                'fact_count' => (int) ($preflight['fact_count'] ?? 0),
                'limitations' => $preflight['limitations'] ?? $this->ixbrlCandidateLimitations(),
                'diagnostics' => array_values($preflight['diagnostics'] ?? []),
            ],
        ], 409);
    }

    /**
     * @return array{0: Characterization, 1: array<string, mixed>, 2: array<string, int|float>, 3: array<string, mixed>}|null
     */
    private function reportContext(Request $request, EsrsDatapointCorpusBuilder $datapoints): ?array
    {
        $characterization = $this->currentCharacterization($request);

        if (! $characterization) {
            return null;
        }

        $corpus = $datapoints->build($characterization);
        $responseState = $this->responseState($characterization, $corpus);
        $sections = $this->sections($characterization, $corpus, $responseState);

        return [$characterization, $corpus, $responseState, $sections];
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, int|float>  $responseState
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    private function readinessPayload(
        Characterization $characterization,
        array $corpus,
        array $responseState,
        array $sections,
    ): array {
        $ixbrlCandidate = $this->ixbrlCandidateMetadata($characterization, $corpus, $sections);

        return [
            'type' => 'report_package_readiness',
            'version' => 'v0',
            'characterization_id' => $characterization->id,
            'status' => $this->status($sections),
            'sections' => $sections,
            'downloads' => $this->downloads($sections, $ixbrlCandidate),
            'next_actions' => $this->nextActions($sections),
            'limitations' => $this->limitations($corpus, $sections),
            'coverage_mode' => $this->coverageMode($corpus),
            'ixbrl_candidate' => $ixbrlCandidate,
        ];
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, int|float>  $responseState
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    private function draftPayload(
        Characterization $characterization,
        array $corpus,
        array $responseState,
        array $sections,
    ): array {
        return [
            'type' => 'report_draft',
            'version' => 'v0',
            'characterization_id' => $characterization->id,
            'generation_status' => $this->status($sections) === 'ready'
                ? 'report_preparation_package_ready'
                : 'frontend_rendered_draft',
            'readiness_status' => $this->status($sections),
            'company' => $this->company($characterization),
            'materiality' => $this->materiality($characterization),
            'datapoints' => $this->datapoints($characterization, $corpus, $responseState),
            'exports' => array_merge([
                'report_readiness' => [
                    'endpoint' => '/api/report',
                    'content_type' => 'application/json',
                    'status' => 'ready',
                    'depends_on' => [],
                    'blocking_sections' => [],
                ],
            ], $this->downloads($sections, $this->ixbrlCandidateMetadata($characterization, $corpus, $sections))),
            'limitations' => $this->limitations($corpus, $sections),
            'coverage_mode' => $this->coverageMode($corpus),
        ];
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @param  array<string, int|float>  $responseState
     * @param  array<string, mixed>  $sections
     * @return array<string, mixed>
     */
    private function evidenceBundlePayload(
        Characterization $characterization,
        array $corpus,
        array $responseState,
        array $sections,
    ): array {
        $readiness = $this->readinessPayload($characterization, $corpus, $responseState, $sections);
        $draft = $this->draftPayload($characterization, $corpus, $responseState, $sections);

        return [
            'type' => 'report_evidence_bundle',
            'version' => 'v0',
            'characterization_id' => $characterization->id,
            'generated_at' => now()->toJSON(),
            'bundle' => [
                'company' => $draft['company'],
                'readiness' => [
                    'status' => $readiness['status'],
                    'sections' => $readiness['sections'],
                'downloads' => $readiness['downloads'],
                    'next_actions' => $readiness['next_actions'],
                ],
                'materiality' => $draft['materiality'],
                'datapoints' => $draft['datapoints'],
                'limitations' => $draft['limitations'],
            ],
            'traceability' => [
                'source_endpoints' => [
                    'readiness' => '/api/report',
                    'draft' => '/api/report/draft',
                    'report_package' => '/api/report/package',
                    'decision_sheet' => '/api/materiality-confirmation/decision-sheet',
                    'datapoint_responses_csv' => '/api/esrs-datapoints/responses/export.csv',
                    'datapoints_csv' => '/api/esrs-datapoints/export.csv',
                ],
                'ar16_to_dr_mapping' => [
                    'status' => Arr::get($corpus, 'generation.matter_to_dr_mapping_status'),
                    'coverage_status' => Arr::get($corpus, 'generation.coverage_status'),
                    'mapping_granularity' => Arr::get($corpus, 'generation.mapping_granularity'),
                    'source' => Arr::get($corpus, 'generation.matter_dr_mapping_source'),
                ],
                'datapoint_source' => [
                    'name' => Arr::get($corpus, 'generation.source_name'),
                    'url' => Arr::get($corpus, 'generation.source_url'),
                    'sha256' => Arr::get($corpus, 'generation.source_sha256'),
                    'workbook_version' => Arr::get($corpus, 'generation.workbook_version'),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  array<string, mixed>  $draft
     */
    private function packageHtml(array $readiness, array $draft): string
    {
        $company = $this->e(Arr::get($draft, 'company.name') ?: 'Empresa sin nombre');
        $year = $this->e((string) (Arr::get($draft, 'company.reporting_year') ?: '-'));
        $status = $this->e((string) $readiness['status']);
        $datapointCompletion = (float) Arr::get($draft, 'datapoints.completion_ratio', 0);
        $datapointPercent = (string) round($datapointCompletion * 100);
        $topics = collect(Arr::get($draft, 'materiality.confirmed_topics', []))
            ->map(function (array $topic): string {
                $label = Arr::get($topic, 'subtopic.es')
                    ?: Arr::get($topic, 'subtheme.es')
                    ?: Arr::get($topic, 'theme.es')
                    ?: ('Topic '.$topic['id']);

                return '<li><strong>'.$this->e((string) $topic['esrs_code']).'</strong> - '.$this->e((string) $label).'</li>';
            })
            ->implode('');
        $blocks = collect(Arr::get($draft, 'datapoints.blocks', []))
            ->map(fn (array $block): string => '<tr><td>'.$this->e((string) ($block['title'] ?? $block['key'] ?? 'Bloque')).'</td><td>'.$this->e((string) $block['decided_count']).'</td><td>'.$this->e((string) $block['datapoint_count']).'</td></tr>')
            ->implode('');
        $limitations = collect(Arr::get($draft, 'limitations', []))
            ->map(fn (array $limitation): string => '<li>'.$this->e((string) ($limitation['message'] ?? $limitation['key'] ?? '')).'</li>')
            ->implode('');

        if ($topics === '') {
            $topics = '<li>Sin temas materiales confirmados.</li>';
        }

        if ($limitations === '') {
            $limitations = '<li>Sin limitaciones registradas.</li>';
        }

        return '<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Resumen de preparación ESRS 2023 - '.$company.'</title>
  <style>
    body { color: #172033; font-family: Arial, sans-serif; line-height: 1.5; margin: 32px; }
    header { border-bottom: 2px solid #172033; margin-bottom: 24px; padding-bottom: 16px; }
    h1, h2 { line-height: 1.2; }
    .notice { background: #fff7ed; border: 1px solid #fed7aa; margin: 18px 0; padding: 12px 14px; }
    .metrics { display: grid; gap: 12px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 16px 0; }
    .metric { border: 1px solid #d6d9e0; padding: 12px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #d6d9e0; padding: 8px; text-align: left; vertical-align: top; }
    @media print { body { margin: 18mm; } .notice { break-inside: avoid; } }
  </style>
</head>
<body>
  <header>
    <h1>Resumen de preparación ESRS 2023</h1>
    <p><strong>'.$company.'</strong> - Ejercicio '.$year.'</p>
  </header>
  <section class="notice">
    <strong>No sustituye la presentación oficial.</strong>
    Este resumen organiza estado, temas, cobertura y trazabilidad. No es una presentación oficial, aseguramiento, opinión legal, memoria redactada ni aceptación de formatos digitales.
  </section>
  <section class="metrics">
    <div class="metric"><strong>Estado</strong><br>'.$status.'</div>
    <div class="metric"><strong>Temas materiales</strong><br>'.$this->e((string) Arr::get($draft, 'materiality.confirmed_topic_count', 0)).'</div>
    <div class="metric"><strong>Cobertura registrada</strong><br>'.$datapointPercent.'%</div>
  </section>
  <section>
    <h2>Temas materiales confirmados</h2>
    <ul>'.$topics.'</ul>
  </section>
  <section>
    <h2>Cobertura de información</h2>
    <table>
      <thead><tr><th>Bloque</th><th>Tratados</th><th>Total</th></tr></thead>
      <tbody>'.$blocks.'</tbody>
    </table>
  </section>
  <section>
    <h2>Limitaciones y alcance</h2>
    <ul>'.$limitations.'</ul>
  </section>
</body>
</html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function currentCharacterization(Request $request): ?Characterization
    {
        return Characterization::forUser($request->user()->id)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function sections(Characterization $characterization, array $corpus, array $responseState): array
    {
        $formData = $characterization->form_data ?? [];
        $doubleMaterialityProcess = DoubleMaterialityProcessState::fromFormData($formData);
        $proposalTopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);
        $materialityConfirmation = $this->materialityConfirmation($formData, $proposalTopicIds);
        $confirmedTopicIds = $materialityConfirmation['confirmed_topic_ids'];
        $totalDatapoints = (int) Arr::get($corpus, 'summary.total_datapoint_count', 0);

        $sections = [
            'characterization' => [
                'status' => $this->hasCharacterizationBaseline($characterization) ? 'ready' : 'incomplete',
                'endpoint' => '/api/characterization',
            ],
            'materiality_proposal' => [
                'status' => $proposalTopicIds !== [] ? 'ready' : 'missing',
                'endpoint' => '/api/materiality-proposal',
                'topic_count' => count($proposalTopicIds),
            ],
            'double_materiality_guide' => [
                'status' => $doubleMaterialityProcess['guide_status'],
                'endpoint' => '/api/double-materiality-guide',
            ],
            'materiality_confirmation' => [
                'status' => $materialityConfirmation['is_confirmed'] ? 'ready' : 'missing',
                'endpoint' => '/api/materiality-confirmation',
                'is_confirmed' => $materialityConfirmation['is_confirmed'],
                'is_stale' => $materialityConfirmation['is_stale'],
                'confirmation_status' => $materialityConfirmation['confirmation_status'],
                'confirmed_topic_count' => count($confirmedTopicIds),
            ],
            'esrs_datapoints' => [
                'status' => $totalDatapoints > 0 ? 'ready' : 'missing',
                'endpoint' => '/api/esrs-datapoints',
                'total_datapoint_count' => $totalDatapoints,
                'coverage_status' => Arr::get($corpus, 'generation.coverage_status'),
                'matter_to_dr_mapping_status' => Arr::get($corpus, 'generation.matter_to_dr_mapping_status'),
                'coverage_mode' => $this->coverageMode($corpus),
            ],
            'datapoint_responses' => [
                'status' => $this->datapointResponseStatus($responseState, $responseState['applicable_datapoint_count']),
                'endpoint' => '/api/esrs-datapoints/responses',
                'response_count' => $responseState['response_count'],
                'completed_count' => $responseState['completed_count'],
                'not_applicable_count' => $responseState['not_applicable_count'],
                'decided_count' => $responseState['decided_count'],
                'completion_ratio' => $responseState['completion_ratio'],
                'orphaned_response_count' => $responseState['orphaned_response_count'],
                'total_datapoint_count' => $totalDatapoints,
            ],
        ];

        $packageReady = $this->status($sections) === 'ready';
        $sections['final_report_generation'] = [
            'status' => $packageReady ? 'ready' : 'blocked',
            'endpoint' => '/api/report/package',
            'generation_status' => $packageReady
                ? 'report_preparation_package_ready'
                : 'blocked_by_incomplete_inputs',
            'reason_code' => $packageReady ? null : 'report_package_prerequisites_incomplete',
        ];

        return $sections;
    }

    private function coverageMode(array $corpus): string
    {
        return Arr::get($corpus, 'generation.matter_to_dr_mapping_status') === 'loaded'
            ? 'full'
            : 'scoping_only';
    }

    /**
     * @return array<string, int|float>
     */
    private function responseState(Characterization $characterization, array $corpus): array
    {
        $state = app(EsrsDatapointFactState::class)->state($characterization, $corpus);
        $summary = $state['summary'];

        return [
            'applicable_datapoint_count' => (int) $summary['applicable_datapoint_count'],
            'response_count' => (int) $summary['response_count'],
            'completed_count' => (int) $summary['completed_count'],
            'not_applicable_count' => (int) $summary['not_applicable_count'],
            'decided_count' => (int) $summary['decided_count'],
            'completion_ratio' => (float) $summary['completion_ratio'],
            'orphaned_response_count' => (int) Arr::get($state, 'orphaned.count', 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fullFactState(Characterization $characterization, array $corpus): array
    {
        return app(EsrsDatapointFactState::class)->state($characterization, $corpus);
    }

    /**
     * @return list<string>
     */
    private function datapointIds(array $corpus): array
    {
        return collect(Arr::get($corpus, 'blocks', []))
            ->flatMap(fn (array $block): array => $block['datapoints'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function currentResponseRows(Characterization $characterization, array $corpus): array
    {
        return app(EsrsDatapointFactState::class)->state($characterization, $corpus)['responses'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function orphanedResponseRows(Characterization $characterization, array $corpus): array
    {
        return app(EsrsDatapointFactState::class)->state($characterization, $corpus)['orphaned']['responses'];
    }

    /**
     * @return array<string, mixed>
     */
    private function company(Characterization $characterization): array
    {
        $formData = $characterization->form_data ?? [];

        return [
            'name' => Arr::get($formData, 'company_profile.company_name'),
            'nace_code' => $characterization->nace_code,
            'status' => $characterization->status,
            'reporting_year' => Arr::get($formData, 'company_profile.reporting_year'),
            'product_service_type' => Arr::get($formData, 'company_profile.product_service_type'),
            'employee_count_range' => Arr::get($formData, 'operations.employee_count_range'),
            'revenue_range' => Arr::get($formData, 'operations.revenue_range'),
            'regions' => Arr::get($formData, 'operations.regions', []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function materiality(Characterization $characterization): array
    {
        $formData = $characterization->form_data ?? [];
        $proposedTopicIds = $characterization->esrs_topic_ids ?? [];
        $materialityConfirmation = $this->materialityConfirmation($formData);
        $confirmedTopicIds = $materialityConfirmation['confirmed_topic_ids'];

        return [
            'proposal_source' => 'p6_ai_candidate_topics',
            'is_confirmed' => $materialityConfirmation['is_confirmed'],
            'confirmation_status' => $materialityConfirmation['confirmation_status'],
            'proposed_topic_count' => count($proposedTopicIds),
            'confirmed_topic_count' => count($confirmedTopicIds),
            'confirmed_at' => $materialityConfirmation['confirmed_at'],
            'confirmed_topics' => $this->topicSummaries($confirmedTopicIds),
        ];
    }

    /**
     * @param  array<string, mixed>  $formData
     * @return array{is_confirmed: bool, is_stale: bool, confirmation_status: string, confirmed_topic_ids: list<int>, confirmed_at: mixed}
     */
    private function materialityConfirmation(array $formData, array $proposalTopicIds = []): array
    {
        $confirmation = Arr::get($formData, 'materiality_confirmation', []);
        $confirmation = is_array($confirmation) ? $confirmation : [];
        $isConfirmed = array_key_exists('confirmed_topic_ids', $confirmation);

        return [
            'is_confirmed' => $isConfirmed,
            'is_stale' => $this->isStaleConfirmation($isConfirmed, $confirmation, $proposalTopicIds),
            'confirmation_status' => $isConfirmed
                ? self::CONFIRMATION_STATUS_CONFIRMED
                : self::CONFIRMATION_STATUS_MISSING,
            'confirmed_topic_ids' => $isConfirmed
                ? $this->topicIds(Arr::get($confirmation, 'confirmed_topic_ids', []))
                : [],
            'confirmed_at' => Arr::get($confirmation, 'confirmed_at'),
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function topicIds(array $values): array
    {
        return collect($values)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function isStaleConfirmation(bool $isConfirmed, array $confirmation, array $proposalTopicIds): bool
    {
        if (! $isConfirmed || ! is_array(Arr::get($confirmation, 'p6_snapshot'))) {
            return false;
        }

        $snapshotTopicIds = Arr::get($confirmation, 'p6_snapshot.topic_ids', []);

        return $this->sortedTopicIds(is_array($snapshotTopicIds) ? $snapshotTopicIds : [])
            !== $this->sortedTopicIds($proposalTopicIds);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function sortedTopicIds(array $values): array
    {
        $topicIds = $this->topicIds($values);
        sort($topicIds);

        return array_values($topicIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function datapoints(Characterization $characterization, array $corpus, array $responseState): array
    {
        $totalDatapoints = (int) Arr::get($corpus, 'summary.total_datapoint_count', 0);

        return [
            'total_datapoint_count' => $totalDatapoints,
            'response_status' => $this->datapointResponseStatus($responseState, $responseState['applicable_datapoint_count']),
            'response_count' => $responseState['response_count'],
            'completed_count' => $responseState['completed_count'],
            'not_applicable_count' => $responseState['not_applicable_count'],
            'decided_count' => $responseState['decided_count'],
            'completion_ratio' => $responseState['completion_ratio'],
            'orphaned_response_count' => $responseState['orphaned_response_count'],
            'coverage_status' => Arr::get($corpus, 'generation.coverage_status'),
            'matter_to_dr_mapping_status' => Arr::get($corpus, 'generation.matter_to_dr_mapping_status'),
            'coverage_mode' => $this->coverageMode($corpus),
            'blocks' => $this->datapointBlocks($characterization, $corpus),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topicSummaries(array $topicIds): array
    {
        $topics = EsrsTopic::whereIn('id', $topicIds)->get()->keyBy('id');

        return collect($topicIds)
            ->map(fn (int $topicId): ?array => $topics->has($topicId) ? [
                'id' => $topicId,
                'esrs_code' => $topics->get($topicId)->esrs_code,
                'theme' => [
                    'en' => $topics->get($topicId)->theme_en,
                    'es' => $topics->get($topicId)->theme_es,
                ],
                'subtheme' => [
                    'en' => $topics->get($topicId)->subtheme_en,
                    'es' => $topics->get($topicId)->subtheme_es,
                ],
                'subtopic' => [
                    'en' => $topics->get($topicId)->subtopic_en,
                    'es' => $topics->get($topicId)->subtopic_es,
                ],
            ] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function datapointBlocks(Characterization $characterization, array $corpus): array
    {
        $responses = $this->currentResponseRows($characterization, $corpus);

        return collect(Arr::get($corpus, 'blocks', []))
            ->map(function (array $block) use ($responses): array {
                $datapointIds = collect($block['datapoints'] ?? [])->pluck('id')->filter()->values();
                $answeredIds = $datapointIds->filter(fn (string $id): bool => array_key_exists($id, $responses));
                $completedIds = $datapointIds->filter(
                    fn (string $id): bool => ($responses[$id]['fact_readiness']['state'] ?? null) === 'valid_completed'
                );
                $notApplicableIds = $datapointIds->filter(
                    fn (string $id): bool => ($responses[$id]['fact_readiness']['state'] ?? null) === 'valid_not_applicable'
                );

                return [
                    'key' => $block['key'] ?? null,
                    'title' => $block['title'] ?? null,
                    'datapoint_count' => $datapointIds->count(),
                    'response_count' => $answeredIds->count(),
                    'completed_count' => $completedIds->count(),
                    'not_applicable_count' => $notApplicableIds->count(),
                    'decided_count' => $completedIds->count() + $notApplicableIds->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function hasCharacterizationBaseline(Characterization $characterization): bool
    {
        $formData = $characterization->form_data ?? [];

        return $characterization->status === Characterization::STATUS_COMPLETED
            && filled($characterization->nace_code)
            && filled(Arr::get($formData, 'company_profile.reporting_year'))
            && filled(Arr::get($formData, 'operations.employee_count_range'))
            && filled(Arr::get($formData, 'operations.revenue_range'));
    }

    private function datapointResponseStatus(array $responseState, int $totalDatapoints): string
    {
        if ($responseState['decided_count'] >= $totalDatapoints && $totalDatapoints > 0) {
            return 'complete';
        }

        if ($responseState['response_count'] > 0) {
            return 'in_progress';
        }

        return 'not_started';
    }

    private function status(array $sections): string
    {
        $requiredStatuses = [
            $sections['characterization']['status'],
            $sections['materiality_proposal']['status'],
            $sections['double_materiality_guide']['status'],
            $sections['materiality_confirmation']['status'],
            $sections['esrs_datapoints']['status'],
            $sections['datapoint_responses']['status'],
        ];

        return in_array('missing', $requiredStatuses, true)
            || in_array('incomplete', $requiredStatuses, true)
            || in_array('not_started', $requiredStatuses, true)
            || in_array('in_progress', $requiredStatuses, true)
            ? 'incomplete'
            : 'ready';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function downloads(array $sections, ?array $ixbrlCandidate = null): array
    {
        $downloads = [
            'report_package_html' => [
                'endpoint' => '/api/report/package',
                'content_type' => 'text/html',
                ...$this->downloadReadiness($sections, $this->reportPackageDependencies()),
            ],
            'evidence_bundle_json' => [
                'endpoint' => '/api/report/evidence-bundle',
                'content_type' => 'application/json',
                ...$this->downloadReadiness($sections, $this->reportPackageDependencies()),
            ],
            'p8_decision_sheet' => [
                'endpoint' => '/api/materiality-confirmation/decision-sheet',
                'content_type' => 'application/json',
                ...$this->downloadReadiness($sections, ['materiality_confirmation']),
            ],
            'p9_responses_csv' => [
                'endpoint' => '/api/esrs-datapoints/responses/export.csv',
                'content_type' => 'text/csv',
                ...$this->downloadReadiness($sections, ['esrs_datapoints', 'datapoint_responses']),
            ],
            'p9_datapoints_csv' => [
                'endpoint' => '/api/esrs-datapoints/export.csv',
                'content_type' => 'text/csv',
                ...$this->downloadReadiness($sections, ['esrs_datapoints']),
            ],
            'characterization_summary_pdf' => [
                'endpoint' => '/characterization/summary?format=pdf',
                'content_type' => 'application/pdf',
                ...$this->downloadReadiness($sections, ['characterization']),
            ],
        ];

        if ($ixbrlCandidate !== null) {
            $downloads['ixbrl_candidate'] = [
                'endpoint' => '/api/report/ixbrl-candidate',
                'content_type' => 'application/xhtml+xml',
                'status' => $ixbrlCandidate['status'] === 'available' ? 'ready' : 'blocked',
                'depends_on' => $this->reportPackageDependencies(),
                'blocking_sections' => $ixbrlCandidate['status'] === 'available' ? [] : ['ixbrl_candidate_preflight'],
                'reason_codes' => $ixbrlCandidate['reason_codes'],
            ];
        }

        return $downloads;
    }

    /**
     * @return list<string>
     */
    private function nextActions(array $sections): array
    {
        $actions = [];
        $orderedSections = [
            'characterization',
            'materiality_proposal',
            'double_materiality_guide',
            'materiality_confirmation',
            'esrs_datapoints',
            'datapoint_responses',
        ];

        foreach ($orderedSections as $sectionKey) {
            if ($this->sectionIsReady($sections[$sectionKey]['status'] ?? null)) {
                continue;
            }

            $actions[] = $sections[$sectionKey]['endpoint'];
        }

        return array_values(array_unique($actions));
    }

    /**
     * @return list<string>
     */
    private function reportPackageDependencies(): array
    {
        return [
            'characterization',
            'materiality_proposal',
            'double_materiality_guide',
            'materiality_confirmation',
            'esrs_datapoints',
            'datapoint_responses',
        ];
    }

    /**
     * @param  list<string>  $dependencies
     * @return array{status: string, depends_on: list<string>, blocking_sections: list<string>}
     */
    private function downloadReadiness(array $sections, array $dependencies): array
    {
        $blocking = collect($dependencies)
            ->filter(fn (string $sectionKey): bool => ! $this->sectionIsReady($sections[$sectionKey]['status'] ?? null))
            ->values()
            ->all();

        return [
            'status' => $blocking === []
                ? 'ready'
                : ($this->hasIncompleteDependency($sections, $blocking) ? 'incomplete' : 'blocked'),
            'depends_on' => $dependencies,
            'blocking_sections' => $blocking,
        ];
    }

    /**
     * @param  list<string>  $blocking
     */
    private function hasIncompleteDependency(array $sections, array $blocking): bool
    {
        foreach ($blocking as $sectionKey) {
            if (($sections[$sectionKey]['status'] ?? null) === 'incomplete'
                || ($sections[$sectionKey]['status'] ?? null) === 'in_progress'
                || ($sections[$sectionKey]['status'] ?? null) === 'not_started') {
                return true;
            }
        }

        return false;
    }

    private function sectionIsReady(?string $status): bool
    {
        return in_array($status, ['ready', 'complete'], true);
    }

    /**
     * @return list<array<string, string>>
     */
    private function limitations(array $corpus, array $sections): array
    {
        $limitations = [
            [
                'key' => 'report_package_scope',
                'message' => 'The package supports ESRS 2023 preparation status and traceability. It is not an official submission, assurance, Taxonomy attestation, native PDF generation, developed report, or regulatory acceptance of digital formats.',
            ],
        ];

        if (Arr::get($corpus, 'generation.matter_to_dr_mapping_status') !== 'loaded') {
            $limitations[] = [
                'key' => 'exact_ar16_matter_to_dr_mapping_pending',
                'message' => 'The information list does not include topical information derived from confirmed topics until a complete and valid topic to Disclosure Requirement mapping is configured.',
            ];
        }

        if ((int) Arr::get($sections, 'datapoint_responses.orphaned_response_count', 0) > 0) {
            $limitations[] = [
                'key' => 'orphaned_datapoint_responses',
                'message' => 'Some stored responses no longer match the current materiality scope. They are preserved and will reattach if the scope includes them again.',
            ];
        }

        if (Arr::get($sections, 'materiality_confirmation.is_stale') === true) {
            $limitations[] = [
                'key' => 'materiality_confirmation_stale',
                'message' => 'The final materiality confirmation predates the latest proposal changes. Re-confirm the material topics before using results.',
            ];
        }

        $limitations[] = [
            'key' => 'ixbrl_candidate_technical_package',
            'message' => 'The iXBRL candidate endpoint emits a technical preparation output; it is not an official submission, assurance, legal opinion, or regulator-accepted output.',
        ];

        $limitations[] = [
            'key' => 'xhtml_ixbrl_generation_still_disabled',
            'message' => 'XHTML/iXBRL candidate downloads are conditional and require runtime Arelle structural validation.',
        ];

        return $limitations;
    }

    /**
     * @return array<string, mixed>
     */
    private function ixbrlCandidateMetadata(Characterization $characterization, array $corpus, array $sections): array
    {
        if ($this->status($sections) !== 'ready') {
            return [
                'status' => 'blocked',
                'endpoint' => '/api/report/ixbrl-candidate',
                'content_type' => 'application/xhtml+xml',
                'reason_codes' => ['report_package_prerequisites_incomplete'],
                'blocking_datapoint_ids' => [],
                'limitations' => $this->ixbrlCandidateLimitations(),
            ];
        }

        $preflight = app(IxbrlCandidateBuilder::class)->preflight($this->fullFactState($characterization, $corpus));

        return [
            'status' => $preflight['status'],
            'endpoint' => '/api/report/ixbrl-candidate',
            'content_type' => 'application/xhtml+xml',
            'reason_codes' => $preflight['reason_codes'],
            'blocking_datapoint_ids' => $preflight['blocking_datapoint_ids'],
            'fact_count' => $preflight['fact_count'],
            'limitations' => $preflight['limitations'],
            'runtime_validation' => [
                'required' => true,
                'validator' => 'arelle',
                'status' => 'not_run',
            ],
        ];
    }

    /** @return list<string> */
    private function ixbrlCandidateLimitations(): array
    {
        return [
            'candidate_technical_package',
            'not_official_filing',
            'requires_arelle_runtime_structural_validation',
            'xhtml_ixbrl_generation_capability_remains_disabled',
        ];
    }
}
