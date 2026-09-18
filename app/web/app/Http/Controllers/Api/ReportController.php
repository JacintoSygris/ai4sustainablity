<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\Report\ExternalTaxonomyManifestRepository;
use App\Services\Report\ReportingProfileException;
use App\Services\Report\ReportingProfileRepository;
use App\Support\DoubleMaterialityProcessState;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use RuntimeException;

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

    public function taxonomy(
        ReportingProfileRepository $profiles,
        ExternalTaxonomyManifestRepository $externalTaxonomyManifest,
    ) {
        try {
            $profile = $profiles->load('esrs-2023-preparatory-v1');
        } catch (ReportingProfileException $e) {
            return response()->json([
                'data' => $this->taxonomyStatusPayload(
                    'esrs-2023-preparatory-v1',
                    'blocked',
                    $e->getMessage(),
                ),
            ]);
        }

        try {
            $externalTaxonomyManifest->loadForProfile($profile);

            return response()->json([
                'data' => $this->taxonomyStatusPayload($profile->profileId(), 'verified'),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'data' => $this->taxonomyStatusPayload($profile->profileId(), 'blocked', $e->getMessage()),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function taxonomyStatusPayload(string $profileId, string $state, ?string $reasonCode = null): array
    {
        return [
            'type' => 'report_taxonomy_status',
            'version' => 'v0',
            'taxonomy' => [
                'name' => 'EFRAG ESRS XBRL Taxonomy Set 1',
                'version' => '2023-12-22',
            ],
            'reporting_profile' => $profileId,
            'availability' => array_filter([
                'state' => $state,
                'reason_code' => $reasonCode,
            ], fn (mixed $value): bool => $value !== null),
        ];
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
        return [
            'type' => 'report_package_readiness',
            'version' => 'v0',
            'characterization_id' => $characterization->id,
            'status' => $this->status($sections),
            'sections' => $sections,
            'downloads' => $this->downloads($sections),
            'next_actions' => $this->nextActions($sections),
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
            ], $this->downloads($sections)),
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
  <title>Paquete de preparación ESRS 2023 - '.$company.'</title>
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
    <h1>Paquete de preparación ESRS 2023</h1>
    <p><strong>'.$company.'</strong> - Ejercicio '.$year.'</p>
  </header>
  <section class="notice">
    <strong>No sustituye la presentación oficial.</strong>
    Este paquete organiza la preparación ESRS 2023, evidencias y trazabilidad; no realiza filing oficial, aseguramiento, Taxonomía UE ni xHTML/iXBRL.
  </section>
  <section class="metrics">
    <div class="metric"><strong>Estado</strong><br>'.$status.'</div>
    <div class="metric"><strong>Temas materiales</strong><br>'.$this->e((string) Arr::get($draft, 'materiality.confirmed_topic_count', 0)).'</div>
    <div class="metric"><strong>Datapoints decididos</strong><br>'.$datapointPercent.'%</div>
  </section>
  <section>
    <h2>Temas materiales confirmados</h2>
    <ul>'.$topics.'</ul>
  </section>
  <section>
    <h2>Cobertura de datapoints</h2>
    <table>
      <thead><tr><th>Bloque</th><th>Decididos</th><th>Total</th></tr></thead>
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
                'status' => $this->datapointResponseStatus($responseState, $totalDatapoints),
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
        $filtered = collect($this->currentResponseRows($characterization, $corpus));
        $orphanedResponseCount = count($this->orphanedResponseRows($characterization, $corpus));
        $totalDatapoints = (int) Arr::get($corpus, 'summary.total_datapoint_count', count($this->datapointIds($corpus)));
        $completedCount = $filtered
            ->filter(fn (array $response): bool => ($response['status'] ?? null) === 'completed')
            ->count();
        $notApplicableCount = $filtered
            ->filter(fn (array $response): bool => ($response['status'] ?? null) === 'not_applicable')
            ->count();
        $decidedCount = $completedCount + $notApplicableCount;

        return [
            'response_count' => $filtered->count(),
            'completed_count' => $completedCount,
            'not_applicable_count' => $notApplicableCount,
            'decided_count' => $decidedCount,
            'completion_ratio' => $totalDatapoints > 0
                ? round($decidedCount / $totalDatapoints, 4)
                : 1.0,
            'orphaned_response_count' => $orphanedResponseCount,
        ];
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
        $responses = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses.responses', []);

        if (! is_array($responses)) {
            return [];
        }

        $allowedIds = array_flip($this->datapointIds($corpus));

        return collect($responses)
            ->filter(
                fn ($response, string|int $datapointId): bool => isset($allowedIds[(string) $datapointId])
                    && is_array($response)
            )
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function orphanedResponseRows(Characterization $characterization, array $corpus): array
    {
        $responses = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses.responses', []);

        if (! is_array($responses)) {
            return [];
        }

        $allowedIds = array_flip($this->datapointIds($corpus));

        return collect($responses)
            ->filter(
                fn ($response, string|int $datapointId): bool => ! isset($allowedIds[(string) $datapointId])
                    && is_array($response)
            )
            ->all();
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
            'response_status' => $this->datapointResponseStatus($responseState, $totalDatapoints),
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
                    fn (string $id): bool => ($responses[$id]['status'] ?? null) === 'completed'
                );
                $notApplicableIds = $datapointIds->filter(
                    fn (string $id): bool => ($responses[$id]['status'] ?? null) === 'not_applicable'
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
    private function downloads(array $sections): array
    {
        return [
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
                'message' => 'The report package supports ESRS 2023 preparation and evidence organization. It is not official filing, assurance, Taxonomy attestation, native PDF generation, or xHTML/iXBRL software.',
            ],
        ];

        if (Arr::get($corpus, 'generation.matter_to_dr_mapping_status') !== 'loaded') {
            $limitations[] = [
                'key' => 'exact_ar16_matter_to_dr_mapping_pending',
                'message' => 'P9 does not include topical datapoints until a fully covering approved AR16 matter to Disclosure Requirement map is configured.',
            ];
        }

        if ((int) Arr::get($sections, 'datapoint_responses.orphaned_response_count', 0) > 0) {
            $limitations[] = [
                'key' => 'orphaned_datapoint_responses',
                'message' => 'Some stored datapoint responses no longer match the current materiality scope. They are preserved and will reattach if the scope includes them again.',
            ];
        }

        if (Arr::get($sections, 'materiality_confirmation.is_stale') === true) {
            $limitations[] = [
                'key' => 'materiality_confirmation_stale',
                'message' => 'The final materiality confirmation predates the latest proposal changes. Re-confirm in step 4.',
            ];
        }

        return $limitations;
    }
}
