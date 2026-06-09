<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Support\CharacterizationOptions;
use Illuminate\Support\Arr;

class EsrsDatapointCorpusBuilder
{
    private const SOURCE_NAME = 'EFRAG IG 3 List of ESRS Data Points';

    public function __construct(
        private readonly Ar16MatterDrMappingRepository $matterDrMappingRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Characterization $characterization): array
    {
        $p6TopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);
        $confirmation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation', []);
        $materialTopicIds = $this->topicIds(Arr::get($confirmation, 'confirmed_topic_ids', $p6TopicIds));
        $activatedStandards = $this->activatedStandards($materialTopicIds);
        $source = $this->source();
        $datapoints = $source['datapoints'];
        $approvedMatterDrMapping = $this->matterDrMappingRepository->approved();
        $validatedMatterDrMapping = $this->validatedMatterDrMapping(
            $materialTopicIds,
            $approvedMatterDrMapping,
            $datapoints
        );
        $matterDrMappingState = $this->matterDrMappingState($materialTopicIds, $validatedMatterDrMapping);

        $alwaysRequired = $this->filterByInclusion($datapoints, 'always_required');
        $topical = $this->filterTopical(
            $datapoints,
            $activatedStandards,
            $materialTopicIds,
            $validatedMatterDrMapping,
            $matterDrMappingState
        );
        $minimumDisclosureRequirements = $activatedStandards === []
            ? []
            : $this->filterByInclusion($datapoints, 'minimum_disclosure_requirement');
        $allSelectedDatapoints = [
            ...$alwaysRequired,
            ...$topical,
            ...$minimumDisclosureRequirements,
        ];
        $summary = $this->summary($allSelectedDatapoints, $alwaysRequired, $topical, $minimumDisclosureRequirements);
        $e1ExceptionBlock = $this->e1ExceptionBlock($characterization, $p6TopicIds, $materialTopicIds);

        return [
            'characterization_id' => $characterization->id,
            'material_topic_ids' => $materialTopicIds,
            'activated_esrs_standards' => $activatedStandards,
            'material_topics' => $this->topicSummaries($materialTopicIds),
            'generation' => $this->generationMetadata($source, $matterDrMappingState),
            'matter_mapping' => $this->matterMapping($materialTopicIds, $topical, $validatedMatterDrMapping, $matterDrMappingState),
            'phase_in_assessment' => $this->phaseInAssessment($characterization, $allSelectedDatapoints),
            'summary' => $summary,
            'completion_plan' => $this->completionPlan($summary, $activatedStandards, $e1ExceptionBlock, $matterDrMappingState),
            'blocks' => [
                'always_required' => [
                    'key' => 'always_required',
                    'title' => 'ESRS 2 General Disclosures',
                    'applies' => true,
                    'standards' => ['ESRS 2'],
                    'datapoint_count' => count($alwaysRequired),
                    'disclosure_requirements' => $this->disclosureRequirementDtos($alwaysRequired),
                    'datapoints' => $this->datapointDtos($alwaysRequired, 'always_required', 'always_required'),
                ],
                'topical' => [
                    'key' => 'topical',
                    'title' => 'Topical datapoints for mapped material Disclosure Requirements',
                    'applies' => $topical !== [],
                    'standards' => $activatedStandards,
                    'datapoint_count' => count($topical),
                    'disclosure_requirements' => $this->disclosureRequirementDtos($topical),
                    'datapoints' => $this->datapointDtos($topical, 'topical', $matterDrMappingState['current_filter']),
                ],
                'minimum_disclosure_requirements' => [
                    'key' => 'minimum_disclosure_requirements',
                    'title' => 'ESRS 2 Minimum Disclosure Requirements linked to material matters',
                    'applies' => $activatedStandards !== [],
                    'standards' => ['ESRS 2 MDR'],
                    'datapoint_count' => count($minimumDisclosureRequirements),
                    'disclosure_requirements' => $this->disclosureRequirementDtos($minimumDisclosureRequirements),
                    'note' => 'Conditional block: these datapoints apply when policies, actions, targets, or metrics exist for confirmed material sustainability matters.',
                    'datapoints' => $this->datapointDtos(
                        $minimumDisclosureRequirements,
                        'minimum_disclosure_requirements',
                        'conditional_mdr_for_material_topics'
                    ),
                ],
                'e1_not_material_explanation' => $e1ExceptionBlock,
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, int>
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
     * @param  array<int, int>  $topicIds
     * @return array<int, string>
     */
    private function activatedStandards(array $topicIds): array
    {
        $topics = EsrsTopic::whereIn('id', $topicIds)->get()->keyBy('id');

        return collect($topicIds)
            ->map(fn (int $id) => $topics->get($id)?->esrs_code)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $topicIds
     * @return array<int, array<string, mixed>>
     */
    private function topicSummaries(array $topicIds): array
    {
        $topics = EsrsTopic::whereIn('id', $topicIds)->get()->keyBy('id');

        return collect($topicIds)
            ->map(fn (int $id) => $topics->get($id))
            ->filter()
            ->map(fn (EsrsTopic $topic) => [
                'id' => $topic->id,
                'esrs_code' => $topic->esrs_code,
                'theme' => [
                    'en' => $topic->theme_en,
                    'es' => $topic->theme_es,
                ],
                'subtheme' => [
                    'en' => $topic->subtheme_en,
                    'es' => $topic->subtheme_es,
                ],
                'subtopic' => [
                    'en' => $topic->subtopic_en,
                    'es' => $topic->subtopic_es,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $topicIds
     * @param  array<int, array<string, mixed>>  $topicalDatapoints
     * @param  array<string, mixed>|null  $approvedMatterDrMapping
     * @param  array<string, mixed>  $matterDrMappingState
     * @return array<string, mixed>
     */
    private function matterMapping(
        array $topicIds,
        array $topicalDatapoints,
        ?array $approvedMatterDrMapping,
        array $matterDrMappingState,
    ): array {
        $topics = EsrsTopic::whereIn('id', $topicIds)->get()->keyBy('id');
        $coverageByStandard = collect($topicalDatapoints)
            ->groupBy('esrs')
            ->map(fn ($datapoints) => [
                'datapoint_count' => $datapoints->count(),
                'disclosure_requirement_count' => count(
                    $this->disclosureRequirementDtos($datapoints->values()->all())
                ),
            ]);

        $payload = [
            'status' => $matterDrMappingState['status'],
            'scope' => 'ar16_matter_to_disclosure_requirement',
            'coverage_status' => $matterDrMappingState['coverage_status'],
            'current_filter' => $matterDrMappingState['current_filter'],
            'material_topics' => collect($topicIds)
                ->map(function (int $topicId) use ($topics, $coverageByStandard, $topicalDatapoints, $approvedMatterDrMapping, $matterDrMappingState) {
                    $topic = $topics->get($topicId);

                    if (! $topic instanceof EsrsTopic) {
                        return null;
                    }

                    $coverage = $coverageByStandard->get($topic->esrs_code, [
                        'datapoint_count' => 0,
                        'disclosure_requirement_count' => 0,
                    ]);

                    $topicPayload = [
                        'topic_id' => $topic->id,
                        'esrs_code' => $topic->esrs_code,
                        'theme' => [
                            'en' => $topic->theme_en,
                            'es' => $topic->theme_es,
                        ],
                        'subtheme' => [
                            'en' => $topic->subtheme_en,
                            'es' => $topic->subtheme_es,
                        ],
                        'subtopic' => [
                            'en' => $topic->subtopic_en,
                            'es' => $topic->subtopic_es,
                        ],
                    ];

                    $topicMapping = $matterDrMappingState['coverage_status'] === 'dr_level'
                        ? ($approvedMatterDrMapping['by_topic_id'][$topicId] ?? null)
                        : null;

                    if (is_array($topicMapping)) {
                        $mappedKeys = $topicMapping['disclosure_requirements'];
                        $mappedDatapointCount = collect($topicalDatapoints)
                            ->filter(fn (array $datapoint) => $datapoint['esrs'] === $topic->esrs_code
                                && in_array(trim((string) $datapoint['dr']), $mappedKeys, true))
                            ->count();

                        return [
                            ...$topicPayload,
                            'mapping_status' => 'mapped_to_disclosure_requirements',
                            'current_filter' => 'disclosure_requirement_level',
                            'mapped_disclosure_requirement_keys' => $mappedKeys,
                            'mapped_disclosure_requirement_count' => count($mappedKeys),
                            'mapped_datapoint_count' => $mappedDatapointCount,
                        ];
                    }

                    return [
                        ...$topicPayload,
                        'mapping_status' => 'pending_explicit_dr_mapping',
                        'current_filter' => $matterDrMappingState['current_filter'],
                        'standard_level_disclosure_requirement_count' => $coverage['disclosure_requirement_count'],
                        'standard_level_datapoint_count' => $coverage['datapoint_count'],
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];

        if (isset($matterDrMappingState['limitation'])) {
            $payload['limitation'] = $matterDrMappingState['limitation'];
        }

        if (isset($matterDrMappingState['source'])) {
            $payload['source'] = $matterDrMappingState['source'];
        }

        return $payload;
    }

    /**
     * @param  array<int, int>  $topicIds
     * @param  array<string, mixed>|null  $approvedMatterDrMapping
     * @return array<string, mixed>
     */
    private function matterDrMappingState(array $topicIds, ?array $approvedMatterDrMapping): array
    {
        if ($approvedMatterDrMapping === null) {
            return [
                'status' => 'pending',
                'mapping_granularity' => 'disclosure_requirement_mapping_required',
                'coverage_status' => 'topical_mapping_required',
                'current_filter' => 'topical_blocked_until_dr_mapping',
                'limitation' => 'No approved AR16 matter to Disclosure Requirement mapping is loaded yet; topical datapoints are not included to avoid assigning full ESRS standards from materiality alone.',
            ];
        }

        $validationErrors = $approvedMatterDrMapping['validation_errors'] ?? [];
        $hasValidationErrors = $validationErrors !== [];
        $hasDuplicateMappingErrors = collect($validationErrors)
            ->flatten()
            ->contains('duplicate_ar16_topic_id');
        $mappedTopicIds = collect($topicIds)
            ->filter(fn (int $topicId) => isset($approvedMatterDrMapping['by_topic_id'][$topicId]))
            ->values()
            ->all();

        if (! $hasValidationErrors && count($mappedTopicIds) === count($topicIds)) {
            return [
                'status' => 'loaded',
                'mapping_granularity' => 'disclosure_requirement_level',
                'coverage_status' => 'dr_level',
                'current_filter' => 'mapped_disclosure_requirements',
                'source' => $approvedMatterDrMapping['source'],
            ];
        }

        return [
            'status' => 'partial',
            'mapping_granularity' => 'disclosure_requirement_mapping_required',
            'coverage_status' => 'topical_mapping_required',
            'current_filter' => 'topical_blocked_until_dr_mapping',
            'source' => $approvedMatterDrMapping['source'],
            'limitation' => match (true) {
                $hasDuplicateMappingErrors => 'An approved AR16 matter to Disclosure Requirement mapping is configured, but duplicate mappings affect one or more selected material topics; topical datapoints are not included until the mapping is unambiguous.',
                $hasValidationErrors => 'An approved AR16 matter to Disclosure Requirement mapping is configured, but one or more selected material topic mappings are missing or invalid; topical datapoints are not included until every selected matter has a valid Disclosure Requirement mapping.',
                default => 'An approved AR16 matter to Disclosure Requirement mapping is configured, but it does not cover every selected material topic; topical datapoints are not included until coverage is complete.',
            },
        ];
    }

    /**
     * @param  array<int, int>  $topicIds
     * @param  array<string, mixed>|null  $approvedMatterDrMapping
     * @param  array<int, array<string, mixed>>  $datapoints
     * @return array<string, mixed>|null
     */
    private function validatedMatterDrMapping(
        array $topicIds,
        ?array $approvedMatterDrMapping,
        array $datapoints,
    ): ?array {
        if ($approvedMatterDrMapping === null) {
            return null;
        }

        $topics = EsrsTopic::whereIn('id', $topicIds)->get()->keyBy('id');
        $drKeysByStandard = $this->drKeysByStandard($datapoints);
        $duplicateTopicIds = array_flip($approvedMatterDrMapping['duplicate_topic_ids'] ?? []);
        $validByTopicId = [];
        $validationErrors = [];

        foreach ($topicIds as $topicId) {
            $mapping = $approvedMatterDrMapping['by_topic_id'][$topicId] ?? null;

            if (! is_array($mapping)) {
                continue;
            }

            $topic = $topics->get($topicId);
            $errors = [];

            if (isset($duplicateTopicIds[$topicId])) {
                $errors[] = 'duplicate_ar16_topic_id';
            }

            if (! $topic instanceof EsrsTopic) {
                $errors[] = 'topic_not_found';
            } else {
                $mappingEsrsCode = trim((string) ($mapping['esrs_code'] ?? ''));

                if ($mappingEsrsCode !== $topic->esrs_code) {
                    $errors[] = 'esrs_code_mismatch';
                }

                $mappedDrKeys = collect($mapping['disclosure_requirements'] ?? [])
                    ->filter(fn ($key) => filled($key))
                    ->map(fn ($key) => trim((string) $key))
                    ->unique()
                    ->values()
                    ->all();
                $validDrKeys = $drKeysByStandard[$topic->esrs_code] ?? [];
                $invalidDrKeys = array_values(array_diff($mappedDrKeys, $validDrKeys));

                if ($mappedDrKeys === []) {
                    $errors[] = 'missing_disclosure_requirements';
                }

                if ($invalidDrKeys !== []) {
                    $errors[] = 'invalid_disclosure_requirements';
                }
            }

            if ($errors !== []) {
                $validationErrors[$topicId] = $errors;

                continue;
            }

            $validByTopicId[$topicId] = [
                ...$mapping,
                'disclosure_requirements' => $mappedDrKeys,
            ];
        }

        return [
            ...$approvedMatterDrMapping,
            'by_topic_id' => $validByTopicId,
            'validation_errors' => $validationErrors,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $datapoints
     * @return array<string, array<int, string>>
     */
    private function drKeysByStandard(array $datapoints): array
    {
        $keysByStandard = [];

        foreach ($datapoints as $datapoint) {
            if ($datapoint['inclusion_type'] !== 'materiality_based') {
                continue;
            }

            $standard = (string) $datapoint['esrs'];
            $dr = trim((string) $datapoint['dr']);

            if ($standard === '' || $dr === '') {
                continue;
            }

            $keysByStandard[$standard] ??= [];
            $keysByStandard[$standard][$dr] = $dr;
        }

        return array_map('array_values', $keysByStandard);
    }

    /**
     * @return array<string, mixed>
     */
    private function source(): array
    {
        static $source = null;

        if ($source === null) {
            $source = json_decode(
                file_get_contents(base_path('data/esrs_datapoints_ig3.json')),
                true,
                flags: JSON_THROW_ON_ERROR
            );
        }

        return $source;
    }

    /**
     * @param  array<int, array<string, mixed>>  $datapoints
     * @return array<int, array<string, mixed>>
     */
    private function filterByInclusion(array $datapoints, string $inclusionType): array
    {
        return array_values(array_filter(
            $datapoints,
            fn (array $datapoint) => $datapoint['inclusion_type'] === $inclusionType
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $datapoints
     * @param  array<int, string>  $activatedStandards
     * @param  array<int, int>  $materialTopicIds
     * @param  array<string, mixed>|null  $approvedMatterDrMapping
     * @param  array<string, mixed>  $matterDrMappingState
     * @return array<int, array<string, mixed>>
     */
    private function filterTopical(
        array $datapoints,
        array $activatedStandards,
        array $materialTopicIds,
        ?array $approvedMatterDrMapping,
        array $matterDrMappingState,
    ): array {
        if ($matterDrMappingState['coverage_status'] !== 'dr_level') {
            return [];
        }

        $allowedDisclosureRequirementPairs = $matterDrMappingState['coverage_status'] === 'dr_level'
            ? collect($materialTopicIds)
                ->map(fn (int $topicId) => $approvedMatterDrMapping['by_topic_id'][$topicId] ?? null)
                ->filter(fn ($mapping) => is_array($mapping))
                ->reduce(function (array $pairs, array $mapping) {
                    $standard = trim((string) ($mapping['esrs_code'] ?? ''));

                    foreach ($mapping['disclosure_requirements'] ?? [] as $key) {
                        $dr = trim((string) $key);

                        if ($standard === '' || $dr === '') {
                            continue;
                        }

                        $pairs[$standard] ??= [];
                        $pairs[$standard][$dr] = true;
                    }

                    return $pairs;
                }, [])
            : [];

        return array_values(array_filter(
            $datapoints,
            fn (array $datapoint) => $datapoint['inclusion_type'] === 'materiality_based'
                && in_array($datapoint['esrs'], $activatedStandards, true)
                && isset($allowedDisclosureRequirementPairs[$datapoint['esrs']][trim((string) $datapoint['dr'])])
        ));
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $matterDrMappingState
     * @return array<string, mixed>
     */
    private function generationMetadata(array $source, array $matterDrMappingState): array
    {
        $metadata = [
            'source_name' => $source['source']['name'],
            'source_url' => $source['source']['url'],
            'source_sha256' => $source['source']['sha256'],
            'workbook_version' => $source['source']['workbook_version'],
            'downloaded_at' => $source['source']['downloaded_at'],
            'source_note' => $source['source']['note'],
            'mapping_granularity' => $matterDrMappingState['mapping_granularity'],
            'matter_to_dr_mapping_status' => $matterDrMappingState['status'],
            'coverage_status' => $matterDrMappingState['coverage_status'],
            'limitations' => ['ESRS remains authoritative if it conflicts with EFRAG IG 3 implementation guidance.'],
        ];

        if (isset($matterDrMappingState['limitation'])) {
            array_unshift($metadata['limitations'], $matterDrMappingState['limitation']);
        }

        if (isset($matterDrMappingState['source'])) {
            $metadata['matter_dr_mapping_source'] = $matterDrMappingState['source'];
        }

        return $metadata;
    }

    /**
     * @param  array<int, array<string, mixed>>  $datapoints
     * @return array<string, mixed>
     */
    private function phaseInAssessment(Characterization $characterization, array $datapoints): array
    {
        $employeeCount = $this->employeeCountAssessment($characterization);
        $lessThan750Count = count(array_filter(
            $datapoints,
            fn (array $datapoint) => filled($datapoint['phase_in_less_than_750'])
        ));
        $allUndertakingsCount = count(array_filter(
            $datapoints,
            fn (array $datapoint) => filled($datapoint['phase_in_all'])
        ));

        return [
            'status' => match ($employeeCount['less_than_750']) {
                true => 'eligible_less_than_750',
                false => 'not_eligible_750_or_more',
                default => 'unknown_employee_count',
            },
            'employee_count' => $employeeCount,
            'counts' => [
                'less_than_750_relief_datapoint_count' => $lessThan750Count,
                'all_undertakings_phase_in_datapoint_count' => $allUndertakingsCount,
                'applicable_phase_in_datapoint_count' => $allUndertakingsCount + (
                    $employeeCount['less_than_750'] === true ? $lessThan750Count : 0
                ),
            ],
            'note' => 'Phase-in metadata is advisory for planning. ESRS remains authoritative for final applicability and timing.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeCountAssessment(Characterization $characterization): array
    {
        $operations = Arr::get($characterization->form_data ?? [], 'operations', []);
        $employeeCount = Arr::get($operations, 'employee_count');

        if (filled($employeeCount) && (int) $employeeCount > 0) {
            $estimate = (int) $employeeCount;

            return [
                'source' => 'employee_count',
                'range' => Arr::get($operations, 'employee_count_range'),
                'estimate' => $estimate,
                'less_than_750' => $estimate < 750,
            ];
        }

        $range = Arr::get($operations, 'employee_count_range');
        $estimate = CharacterizationOptions::employeeCountEstimate($range) ?: null;

        return [
            'source' => filled($range) ? 'employee_count_range' : 'missing',
            'range' => $range,
            'estimate' => $estimate,
            'less_than_750' => match ($range) {
                '1_9', '10_49', '50_249', '250_499' => true,
                '1000_plus' => false,
                default => null,
            },
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $all
     * @param  array<int, array<string, mixed>>  $alwaysRequired
     * @param  array<int, array<string, mixed>>  $topical
     * @param  array<int, array<string, mixed>>  $minimumDisclosureRequirements
     * @return array<string, int>
     */
    private function summary(
        array $all,
        array $alwaysRequired,
        array $topical,
        array $minimumDisclosureRequirements,
    ): array {
        return [
            'always_required_datapoint_count' => count($alwaysRequired),
            'topical_datapoint_count' => count($topical),
            'minimum_disclosure_requirement_datapoint_count' => count($minimumDisclosureRequirements),
            'total_datapoint_count' => count($all),
            'voluntary_datapoint_count' => count(array_filter($all, fn (array $datapoint) => $datapoint['may_disclose'])),
            'conditional_datapoint_count' => count(array_filter($all, fn (array $datapoint) => filled($datapoint['conditional_or_alternative']))),
            'phase_in_datapoint_count' => count(array_filter(
                $all,
                fn (array $datapoint) => filled($datapoint['phase_in_less_than_750']) || filled($datapoint['phase_in_all'])
            )),
        ];
    }

    /**
     * @param  array<string, int>  $summary
     * @param  array<int, string>  $activatedStandards
     * @param  array<string, mixed>  $e1ExceptionBlock
     * @param  array<string, mixed>  $matterDrMappingState
     * @return array<string, mixed>
     */
    private function completionPlan(
        array $summary,
        array $activatedStandards,
        array $e1ExceptionBlock,
        array $matterDrMappingState,
    ): array {
        return [
            'strategy' => 'baseline_then_material_topics',
            'phases' => [
                [
                    'sequence' => 1,
                    'key' => 'always_required',
                    'title' => 'Complete ESRS 2 general disclosures first',
                    'block_key' => 'always_required',
                    'applies' => true,
                    'standards' => ['ESRS 2'],
                    'datapoint_count' => $summary['always_required_datapoint_count'],
                    'status' => 'ready',
                ],
                [
                    'sequence' => 2,
                    'key' => 'topical',
                    'title' => 'Complete topical datapoints for mapped material matters',
                    'block_key' => 'topical',
                    'applies' => $summary['topical_datapoint_count'] > 0,
                    'standards' => $activatedStandards,
                    'datapoint_count' => $summary['topical_datapoint_count'],
                    'status' => match (true) {
                        $activatedStandards === [] => 'not_applicable',
                        $matterDrMappingState['coverage_status'] === 'dr_level' => 'ready',
                        default => 'blocked',
                    },
                    'coverage_status' => $matterDrMappingState['coverage_status'],
                ],
                [
                    'sequence' => 3,
                    'key' => 'minimum_disclosure_requirements',
                    'title' => 'Review ESRS 2 MDR datapoints for material matters',
                    'block_key' => 'minimum_disclosure_requirements',
                    'applies' => $activatedStandards !== [],
                    'standards' => ['ESRS 2 MDR'],
                    'datapoint_count' => $summary['minimum_disclosure_requirement_datapoint_count'],
                    'status' => $activatedStandards === [] ? 'not_applicable' : 'conditional',
                ],
                [
                    'sequence' => 4,
                    'key' => 'e1_not_material_explanation',
                    'title' => 'Complete E1 non-material explanation when required',
                    'block_key' => 'e1_not_material_explanation',
                    'applies' => $e1ExceptionBlock['applies'],
                    'standards' => ['E1'],
                    'datapoint_count' => 0,
                    'status' => $e1ExceptionBlock['status'],
                ],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $datapoints
     * @return array<int, array<string, mixed>>
     */
    private function datapointDtos(array $datapoints, string $blockKey, string $mappingBasis): array
    {
        return array_map(fn (array $datapoint) => [
            'id' => $datapoint['id'],
            'standard' => $datapoint['esrs'],
            'dr' => trim((string) $datapoint['dr']),
            'paragraph' => $datapoint['paragraph'],
            'related_ar' => $datapoint['related_ar'],
            'name' => $datapoint['name'],
            'data_type' => $datapoint['data_type'],
            'conditional_or_alternative' => $datapoint['conditional_or_alternative'],
            'may_disclose' => $datapoint['may_disclose'],
            'appendix_b' => $datapoint['appendix_b'],
            'phase_in' => [
                'less_than_750' => $datapoint['phase_in_less_than_750'],
                'all_undertakings' => $datapoint['phase_in_all'],
            ],
            'applicability' => $this->datapointApplicability($datapoint, $blockKey, $mappingBasis),
        ], $datapoints);
    }

    /**
     * @param  array<string, mixed>  $datapoint
     * @return array<string, mixed>
     */
    private function datapointApplicability(array $datapoint, string $blockKey, string $mappingBasis): array
    {
        $dr = trim((string) $datapoint['dr']);
        $limitations = [];

        if ($blockKey === 'topical' && $mappingBasis !== 'mapped_disclosure_requirements') {
            $limitations[] = 'Topical datapoints require a fully covering approved AR16 matter to Disclosure Requirement map.';
        }

        return [
            'block_key' => $blockKey,
            'reason_code' => match ($blockKey) {
                'always_required' => 'always_required_esrs_2',
                'topical' => 'material_esrs_standard',
                'minimum_disclosure_requirements' => 'conditional_esrs_2_mdr',
                default => 'selected_datapoint',
            },
            'reason' => match ($blockKey) {
                'always_required' => 'ESRS 2 general disclosure datapoints are included as the baseline sustainability statement corpus.',
                'topical' => $mappingBasis === 'mapped_disclosure_requirements'
                    ? 'This topical datapoint belongs to a mapped Disclosure Requirement for the confirmed material matter.'
                    : 'This topical datapoint requires a mapped Disclosure Requirement for the confirmed material matter.',
                'minimum_disclosure_requirements' => 'ESRS 2 MDR datapoints are included as a conditional review block for confirmed material matters.',
                default => 'This datapoint is included by the current P9 corpus rules.',
            },
            'mapping_basis' => $mappingBasis,
            'source_chain' => [
                'source_dataset' => self::SOURCE_NAME,
                'esrs_standard' => $datapoint['esrs'],
                'disclosure_requirement' => $dr !== '' ? $dr : null,
                'datapoint_id' => $datapoint['id'],
            ],
            'limitations' => $limitations,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $datapoints
     * @return array<int, array<string, mixed>>
     */
    private function disclosureRequirementDtos(array $datapoints): array
    {
        $groups = [];

        foreach ($datapoints as $datapoint) {
            $dr = trim((string) ($datapoint['dr'] ?? ''));
            $key = $dr !== '' ? $dr : 'unassigned';
            $groupKey = $datapoint['esrs'].'|'.$key;

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $key,
                    'standard' => $datapoint['esrs'],
                    'dr' => $dr !== '' ? $dr : null,
                    'datapoint_ids' => [],
                ];
            }

            $groups[$groupKey]['datapoint_ids'][] = $datapoint['id'];
        }

        return array_values(array_map(
            fn (array $group) => [
                ...$group,
                'datapoint_count' => count($group['datapoint_ids']),
            ],
            $groups
        ));
    }

    /**
     * @param  array<int, int>  $p6TopicIds
     * @param  array<int, int>  $materialTopicIds
     * @return array<string, mixed>
     */
    private function e1ExceptionBlock(Characterization $characterization, array $p6TopicIds, array $materialTopicIds): array
    {
        $p6HadE1 = EsrsTopic::whereIn('id', $p6TopicIds)->where('esrs_code', 'E1')->exists();
        $materialHasE1 = EsrsTopic::whereIn('id', $materialTopicIds)->where('esrs_code', 'E1')->exists();
        $applies = $p6HadE1 && ! $materialHasE1;
        $explanation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation.e1_not_material_explanation');

        return [
            'key' => 'e1_not_material_explanation',
            'title' => 'E1 not material explanation',
            'applies' => $applies,
            'status' => $applies && filled($explanation) ? 'satisfied' : ($applies ? 'missing' : 'not_applicable'),
            'explanation' => $explanation,
        ];
    }
}
