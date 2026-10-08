<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Models\ReportingFact;
use App\Services\EsrsDatapointCorpusBuilder;
use App\Services\EsrsDatapointResponseState;
use App\Services\Report\ReportContentReadiness;
use App\Services\Report\ReportContentScope;
use App\Support\DoubleMaterialityProcessState;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ReportController extends Controller
{
    private const CONFIRMATION_STATUS_CONFIRMED = 'confirmed';

    private const CONFIRMATION_STATUS_MISSING = 'missing';

    public function __construct(
        private readonly ReportContentReadiness $contentReadiness,
        private readonly ReportContentScope $contentScope,
        private readonly EsrsDatapointResponseState $datapointResponses,
    ) {}

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

        if (! ($readiness['workflow_complete'] ?? false)) {
            return $this->blockedPackageResponse($readiness);
        }

        $draft = $this->draftPayload(...$context);

        return response($this->packageHtml($readiness, $draft), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.(app()->getLocale() === 'en' ? 'esrs-preparation-package.html' : 'paquete-preparacion-neis.html').'"');
    }

    public function evidenceBundle(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $context = $this->reportContext($request, $datapoints);

        if (! $context) {
            return response()->json(['data' => null]);
        }

        $readiness = $this->readinessPayload(...$context);

        if (! ($readiness['workflow_complete'] ?? false)) {
            return $this->blockedPackageResponse($readiness);
        }

        return response()->json([
            'data' => $this->evidenceBundlePayload(...$context),
        ])->header('Content-Disposition', 'attachment; filename="'.(app()->getLocale() === 'en' ? 'esrs-preparation-evidence.json' : 'evidencias-preparacion-neis.json').'"');
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
                'locale' => app()->getLocale(),
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

        $corpus = (new \App\Support\EsrsDisplayCatalogue)->project($datapoints->build($characterization), app()->getLocale());
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
        $workflowStatus = $this->workflowStatus($sections);
        $reportContentStatus = Arr::get($sections, 'report_content.status', 'incomplete');

        return [
            'type' => 'report_package_readiness',
            'version' => 'v0',
            'locale' => app()->getLocale(),
            'characterization_id' => $characterization->id,
            'status' => $this->status($sections),
            'status_label' => \App\Support\DisplayLabels::code($this->status($sections)),
            'workflow_status' => $workflowStatus,
            'workflow_complete' => $workflowStatus === 'ready',
            'report_content_status' => $reportContentStatus,
            'report_content_ready' => $reportContentStatus === 'ready',
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
        $workflowStatus = $this->workflowStatus($sections);
        $reportContentStatus = Arr::get($sections, 'report_content.status', 'incomplete');

        return [
            'type' => 'report_draft',
            'version' => 'v0',
            'locale' => app()->getLocale(),
            'characterization_id' => $characterization->id,
            'generation_status' => $workflowStatus === 'ready'
                ? 'report_preparation_package_ready'
                : 'frontend_rendered_draft',
            'readiness_status' => $this->status($sections),
            'workflow_status' => $workflowStatus,
            'workflow_complete' => $workflowStatus === 'ready',
            'report_content_status' => $reportContentStatus,
            'report_content_ready' => $reportContentStatus === 'ready',
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
            'locale' => app()->getLocale(),
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
                    'datapoint_responses_csv' => '/api/esrs-datapoints/responses/export.localized.csv',
                    'datapoints_csv' => '/api/esrs-datapoints/export.localized.csv',
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
        return view('reports.preparation-package', [
            'readiness' => $readiness,
            'draft' => $draft,
            'locale' => app()->getLocale(),
            'statusLabel' => \App\Support\DisplayLabels::code($readiness['status']),
        ])->render();
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
                'effective_required_datapoint_count' => $responseState['effective_required_datapoint_count'],
            ],
        ];

        $workflowReady = $this->workflowStatus($sections) === 'ready';
        $facts = ReportingFact::query()
            ->where('characterization_id', $characterization->id)
            ->orderBy('fact_id')
            ->get();
        $expectedDatapointIds = $this->contentScope->completedDatapointIds($characterization, $corpus);
        $contentAssessment = $this->contentReadiness->assess($facts, $expectedDatapointIds);
        $sections['report_content'] = [
            'status' => $contentAssessment['ready'] ? 'ready' : 'incomplete',
            'endpoint' => '/api/report/facts',
            'claimable_count' => $contentAssessment['claimable_count'],
            'required_count' => $contentAssessment['required_count'],
            'resolved_required_count' => $contentAssessment['resolved_required_count'],
            'missing_datapoint_count' => count($contentAssessment['missing_datapoint_ids']),
            'reason_code' => $contentAssessment['ready']
                ? null
                : ($contentAssessment['reasons'][0] ?? 'no_claimable_report_content'),
            'reasons' => $contentAssessment['reasons'],
        ];

        $factualReportReady = $workflowReady && $contentAssessment['ready'];
        $sections['final_report_generation'] = [
            'status' => $factualReportReady ? 'ready' : 'blocked',
            'endpoint' => '/api/report/html',
            'generation_status' => $factualReportReady
                ? 'factual_report_ready'
                : 'blocked_by_incomplete_inputs',
            'reason_code' => $factualReportReady
                ? null
                : ($workflowReady
                    ? ($contentAssessment['reasons'][0] ?? 'no_claimable_report_content')
                    : 'report_package_prerequisites_incomplete'),
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
        return $this->datapointResponses->reportSummary($characterization, $corpus);
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
        $confirmedTopics = $this->topicSummaries($confirmedTopicIds);
        $confirmedThemes = $this->materialThemes($confirmedTopics);

        return [
            'proposal_source' => 'p6_ai_candidate_topics',
            'is_confirmed' => $materialityConfirmation['is_confirmed'],
            'confirmation_status' => $materialityConfirmation['confirmation_status'],
            'proposed_topic_count' => count($proposedTopicIds),
            'confirmed_topic_count' => count($confirmedTopicIds),
            'confirmed_theme_count' => count($confirmedThemes),
            'confirmed_at' => $materialityConfirmation['confirmed_at'],
            'confirmed_topics' => $confirmedTopics,
            'confirmed_themes' => $confirmedThemes,
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
     * @param  list<array<string, mixed>>  $topics
     * @return list<array{esrs_code: string, label: string}>
     */
    private function materialThemes(array $topics): array
    {
        return collect($topics)
            ->filter(fn (array $topic): bool => filled($topic['esrs_code'] ?? null))
            ->unique(fn (array $topic): string => (string) $topic['esrs_code'])
            ->map(fn (array $topic): array => [
                'esrs_code' => (string) $topic['esrs_code'],
                'label' => (string) (Arr::get($topic, 'theme.'.app()->getLocale())
                    ?: Arr::get($topic, 'theme.es')
                    ?: $topic['esrs_code']),
            ])
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
        return match ($responseState['completion_status'] ?? null) {
            'completed' => 'complete',
            'in_progress' => 'in_progress',
            default => 'not_started',
        };
    }

    private function status(array $sections): string
    {
        return $this->workflowStatus($sections) === 'ready'
            && Arr::get($sections, 'report_content.status') === 'ready'
                ? 'ready'
                : 'incomplete';
    }

    private function workflowStatus(array $sections): string
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
                'endpoint' => '/api/esrs-datapoints/responses/export.localized.csv',
                'content_type' => 'text/csv',
                ...$this->downloadReadiness($sections, ['esrs_datapoints', 'datapoint_responses']),
            ],
            'p9_datapoints_csv' => [
                'endpoint' => '/api/esrs-datapoints/export.localized.csv',
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
            'report_content',
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
                'message' => __('El paquete permite preparar el informe NEIS 2023 y organizar sus evidencias. No sustituye la presentación oficial ni el aseguramiento, no acredita el cumplimiento de la Taxonomía de la UE y no genera de forma nativa documentos PDF ni formatos electrónicos regulatorios.'),
            ],
        ];

        if (Arr::get($corpus, 'generation.matter_to_dr_mapping_status') !== 'loaded') {
            $limitations[] = [
                'key' => 'exact_ar16_matter_to_dr_mapping_pending',
                'message' => __('El paso de datos no incluye los puntos temáticos hasta que se configure un mapa aprobado y completo entre los asuntos AR16 y los requisitos de información.'),
            ];
        }

        if ((int) Arr::get($sections, 'datapoint_responses.orphaned_response_count', 0) > 0) {
            $limitations[] = [
                'key' => 'orphaned_datapoint_responses',
                'message' => __('Algunas respuestas guardadas ya no coinciden con el alcance de materialidad vigente. Se conservan y volverán a incorporarse si el alcance las incluye de nuevo.'),
            ];
        }

        if (Arr::get($sections, 'materiality_confirmation.is_stale') === true) {
            $limitations[] = [
                'key' => 'materiality_confirmation_stale',
                'message' => __('La confirmación final de materialidad es anterior a los últimos cambios de la propuesta. Vuelve a confirmarla en el paso 4.'),
            ];
        }

        return $limitations;
    }
}
