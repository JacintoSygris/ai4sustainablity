<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Services\EsrsDatapointCorpusBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ReportController extends Controller
{
    private const CONFIRMATION_STATUS_CONFIRMED = 'confirmed';

    private const CONFIRMATION_STATUS_MISSING = 'missing';

    public function show(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $characterization = $this->currentCharacterization($request);

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        $corpus = $datapoints->build($characterization);
        $responseState = $this->responseState($characterization, $corpus);
        $sections = $this->sections($characterization, $corpus, $responseState);

        return response()->json([
            'data' => [
                'type' => 'report_package_readiness',
                'version' => 'v0',
                'characterization_id' => $characterization->id,
                'status' => $this->status($sections),
                'sections' => $sections,
                'downloads' => $this->downloads($sections),
                'next_actions' => $this->nextActions($sections),
                'limitations' => $this->limitations($corpus),
            ],
        ]);
    }

    public function draft(Request $request, EsrsDatapointCorpusBuilder $datapoints)
    {
        $characterization = $this->currentCharacterization($request);

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        $corpus = $datapoints->build($characterization);
        $responseState = $this->responseState($characterization, $corpus);
        $sections = $this->sections($characterization, $corpus, $responseState);

        return response()->json([
            'data' => [
                'type' => 'report_draft',
                'version' => 'v0',
                'characterization_id' => $characterization->id,
                'generation_status' => 'frontend_rendered_draft',
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
                'limitations' => $this->limitations($corpus),
            ],
        ]);
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
        $materialityConfirmation = $this->materialityConfirmation($formData);
        $confirmedTopicIds = $materialityConfirmation['confirmed_topic_ids'];
        $proposalTopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);
        $totalDatapoints = (int) Arr::get($corpus, 'summary.total_datapoint_count', 0);

        return [
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
                'status' => 'ready',
                'endpoint' => '/api/double-materiality-guide',
            ],
            'materiality_confirmation' => [
                'status' => $materialityConfirmation['is_confirmed'] ? 'ready' : 'missing',
                'endpoint' => '/api/materiality-confirmation',
                'is_confirmed' => $materialityConfirmation['is_confirmed'],
                'confirmation_status' => $materialityConfirmation['confirmation_status'],
                'confirmed_topic_count' => count($confirmedTopicIds),
            ],
            'esrs_datapoints' => [
                'status' => $totalDatapoints > 0 ? 'ready' : 'missing',
                'endpoint' => '/api/esrs-datapoints',
                'total_datapoint_count' => $totalDatapoints,
                'coverage_status' => Arr::get($corpus, 'generation.coverage_status'),
                'matter_to_dr_mapping_status' => Arr::get($corpus, 'generation.matter_to_dr_mapping_status'),
            ],
            'datapoint_responses' => [
                'status' => $this->datapointResponseStatus($responseState, $totalDatapoints),
                'endpoint' => '/api/esrs-datapoints/responses',
                'response_count' => $responseState['response_count'],
                'completed_count' => $responseState['completed_count'],
                'not_applicable_count' => $responseState['not_applicable_count'],
                'decided_count' => $responseState['decided_count'],
                'completion_ratio' => $responseState['completion_ratio'],
                'total_datapoint_count' => $totalDatapoints,
            ],
            'final_report_generation' => [
                'status' => 'not_implemented',
                'reason_code' => 'final_report_generation_pending',
            ],
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function responseState(Characterization $characterization, array $corpus): array
    {
        $filtered = collect($this->currentResponseRows($characterization, $corpus));
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
     * @return array{is_confirmed: bool, confirmation_status: string, confirmed_topic_ids: list<int>, confirmed_at: mixed}
     */
    private function materialityConfirmation(array $formData): array
    {
        $confirmation = Arr::get($formData, 'materiality_confirmation', []);
        $confirmation = is_array($confirmation) ? $confirmation : [];
        $isConfirmed = array_key_exists('confirmed_topic_ids', $confirmation);

        return [
            'is_confirmed' => $isConfirmed,
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
            'coverage_status' => Arr::get($corpus, 'generation.coverage_status'),
            'matter_to_dr_mapping_status' => Arr::get($corpus, 'generation.matter_to_dr_mapping_status'),
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
            $sections['materiality_confirmation']['status'],
            $sections['esrs_datapoints']['status'],
            $sections['datapoint_responses']['status'],
        ];

        return in_array('missing', $requiredStatuses, true)
            || in_array('incomplete', $requiredStatuses, true)
            || in_array('not_started', $requiredStatuses, true)
            || in_array('in_progress', $requiredStatuses, true)
            ? 'incomplete'
            : 'generation_pending';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function downloads(array $sections): array
    {
        return [
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

        if ($actions === []) {
            return ['/api/report/draft'];
        }

        return array_values(array_unique($actions));
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
    private function limitations(array $corpus): array
    {
        $limitations = [
            [
                'key' => 'final_report_generation_pending',
                'message' => 'Final AI report and XBRL generation are not implemented in this release.',
            ],
        ];

        if (Arr::get($corpus, 'generation.matter_to_dr_mapping_status') !== 'loaded') {
            $limitations[] = [
                'key' => 'exact_ar16_matter_to_dr_mapping_pending',
                'message' => 'P9 currently uses the documented standard-level partial fallback.',
            ];
        }

        return $limitations;
    }
}
