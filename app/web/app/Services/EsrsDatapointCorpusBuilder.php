<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Support\CharacterizationOptions;
use App\Services\Report\ReportScopeAssets;
use Illuminate\Support\Arr;

class EsrsDatapointCorpusBuilder
{
    private const SOURCE_NAME = 'EFRAG IG 3 List of ESRS Data Points';

    public function __construct(
        private readonly Ar16MatterDrMappingRepository $matterDrMappingRepository,
        private readonly ?array $nativeFixtureSource = null,
    ) {
        if ($nativeFixtureSource !== null) { $this->assertNativeFixture(); }
    }

    private function assertNativeFixture(): void
    {
        if (! app()->runningInConsole() || ! app()->environment('testing')
            || config('services.learning_native_fixture') === null
            || ! in_array(\Illuminate\Support\Facades\DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)
            || ! CharacterizationStateTransaction::admitsIsolatedConnections([\Illuminate\Support\Facades\DB::connection()])) {
            throw new \DomainException('esrs_builder.native_fixture_denied');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Characterization $characterization): array
    {
        return $this->buildFromInputs($characterization, null);
    }

    public function buildCaptured(Characterization $characterization, ReportScopeAssets $assets): array
    {
        return $this->buildFromInputs($characterization, $assets);
    }

    private function buildFromInputs(Characterization $characterization, ?ReportScopeAssets $assets): array
    {
        if (! \App\Services\Dma\P8Authority::enabled()) { return $this->buildNormative($characterization, $assets); }
        $authority = app(\App\Services\Dma\P8Authority::class);
        $p8 = $authority->assertCurrent($characterization);
        $corpus = $this->buildNormative($characterization, $assets);
        $this->assertMappingAvailable($corpus);
        $inferred = \App\Services\Dma\DmaNlpIroContextProjector::hasAssociations($p8['dma_import_evidence'] ?? []);
        $projector = $inferred ? \App\Services\Dma\DmaNlpIroContextProjector::class : \App\Services\Dma\IroContextProjector::class;
        $corpus['iro_context_v1'] = app($projector)->project($p8['dma_import_evidence'] ?? [], $corpus);
        $corpus['p9_assets'] = [
            'mapping' => $corpus['mapping_snapshot_digest'],
            'ig3' => $corpus['ig3_snapshot_digest'],
            'scope' => \App\Services\Dma\P8Authority::hash($corpus['blocks']),
            'rules' => \App\Services\Dma\P8Authority::hash([
                \App\Services\Dma\IroContextProjector::VERSION,
                hash_file('sha256', __DIR__.'/Dma/IroContextProjector.php'),
                $corpus['iro_context_v1']['guidance_sha256'],
                ...($inferred ? [hash_file('sha256', __DIR__.'/Dma/DmaNlpIroContextProjector.php')] : []),
            ]),
        ];
        $corpus['p9_authority_digest'] = $authority->digest($characterization, $corpus['p9_assets']);
        if (\App\Services\Dma\DmaNlpRecommendations::enabled()) {
            $corpus['dma_nlp_enabled'] = true;
            $corpus['dma_nlp_p8_revision'] = $p8['revision'];
            $corpus['dma_nlp_imports'] = array_map(fn ($item) => ['id' => $item['import_id'], 'iro_rows' => $item['iro_rows']], $p8['dma_import_evidence'] ?? []);
            $corpus['iro_context_v1']['dma_nlp'] = \App\Services\Dma\DmaNlpRecommendations::project($characterization->form_data['dma_nlp_evidence'] ?? [], $corpus['p9_authority_digest']);
        }
        if (isset($characterization->form_data['dma_nlp_evidence'])) { $corpus['iro_context_v1']['dma_nlp_history'] = array_values($characterization->form_data['dma_nlp_evidence']); }
        if (isset($p8['dma_nlp_evidence'])) { $corpus['iro_context_v1']['dma_nlp_topic_evidence'] = $p8['dma_nlp_evidence']; }
        return $corpus;
    }

    /** Read-only P8 estimate; never produces a write/export authority digest. */
    public function buildPreview(Characterization $characterization, ?ReportScopeAssets $assets = null): array
    {
        $corpus = $this->buildNormative($characterization, $assets);
        $corpus['provisional'] = true;
        return $corpus;
    }

    public function assertMappingAvailable(array $corpus): void
    {
        if (($corpus['generation']['coverage_status'] ?? null) !== 'dr_level'
            || ($corpus['generation']['matter_to_dr_mapping_status'] ?? null) !== 'loaded') {
            \App\Services\Dma\P8Authority::reject('p9_mapping_unavailable');
        }
    }

    private function buildNormative(Characterization $characterization, ?ReportScopeAssets $assets = null): array
    {
        $p6TopicIds = $this->topicIds($characterization->esrs_topic_ids ?? []);
        $employeeCount = $this->employeeCountAssessment($characterization);
        $lessThan750 = $employeeCount['less_than_750'] === true;
        $confirmation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation', []);
        // Arr::get's default only applies when the key is absent; a present-but-null
        // value (e.g. an earlier save wiped it) would otherwise flow through as null.
        $confirmation = is_array($confirmation) ? $confirmation : [];
        $materialTopicIds = $this->topicIds(Arr::get($confirmation, 'confirmed_topic_ids', $p6TopicIds));
        $activatedStandards = $this->activatedStandards($materialTopicIds);
        $source = $assets?->corpus ?? $this->source();
        if (\App\Services\Dma\P8Authority::enabled()) { $this->assertSourceStructure($source); }
        $datapoints = $source['datapoints'];
        $approvedMatterDrMapping = $assets?->mapping ?? $this->matterDrMappingRepository->approved();
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
        $summary = $this->summary($allSelectedDatapoints, $alwaysRequired, $topical, $minimumDisclosureRequirements, $lessThan750);
        $e1ExceptionBlock = $this->e1ExceptionBlock(
            $characterization,
            $this->e1BaselineTopicIds($confirmation),
            $materialTopicIds
        );

        return [
            'characterization_id' => $characterization->id,
            // Witness of the exact normalized map accepted by this build, including null.
            ...(\App\Services\Dma\P8Authority::enabled() ? ['ig3_snapshot_digest' => \App\Services\Dma\P8Authority::hash($source)] : []),
            'mapping_snapshot_digest' => hash('sha256', json_encode($approvedMatterDrMapping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'material_topic_ids' => $materialTopicIds,
            'activated_esrs_standards' => $activatedStandards,
            'material_topics' => $this->topicSummaries($materialTopicIds),
            'generation' => $this->generationMetadata($source, $matterDrMappingState),
            'matter_mapping' => $this->matterMapping($materialTopicIds, $topical, $validatedMatterDrMapping, $matterDrMappingState),
            'phase_in_assessment' => $this->phaseInAssessment($employeeCount, $allSelectedDatapoints),
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
                    'datapoints' => $this->datapointDtos($alwaysRequired, 'always_required', 'always_required', $lessThan750),
                ],
                'topical' => [
                    'key' => 'topical',
                    'title' => 'Topical datapoints for mapped material Disclosure Requirements',
                    'applies' => $topical !== [],
                    'standards' => $activatedStandards,
                    'datapoint_count' => count($topical),
                    'disclosure_requirements' => $this->disclosureRequirementDtos($topical),
                    'datapoints' => $this->datapointDtos($topical, 'topical', $matterDrMappingState['current_filter'], $lessThan750),
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
                        'conditional_mdr_for_material_topics',
                        $lessThan750
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
        // Per-instance tiny input never enters or reads the production static cache.
        if ($this->nativeFixtureSource !== null) {
            $this->assertNativeFixture();
            return $this->nativeFixtureSource;
        }
        if (\App\Services\Dma\P8Authority::enabled()) {
            $path = config('report_scope.corpus_path', base_path('data/esrs_datapoints_ig3.json'));
            if (! is_string($path) || ! is_readable($path)) { \App\Services\Dma\P8Authority::reject('p9_mapping_unavailable'); }
            try {
                $current = json_decode(\Illuminate\Support\Facades\File::get($path), true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException|\Illuminate\Contracts\Filesystem\FileNotFoundException) {
                \App\Services\Dma\P8Authority::reject('p9_mapping_unavailable');
            }
            $this->assertSourceStructure($current);
            return $current;
        }
        return ReportScopeAssets::readCorpus();
    }

    /** Validate the entire input before filtering: malformed unselected rows are unsafe too. */
    private function assertSourceStructure(mixed $source): void
    {
        $reject = fn () => \App\Services\Dma\P8Authority::reject('p9_mapping_unavailable');
        if (! is_array($source) || ! is_array($source['source'] ?? null)
            || ! is_array($source['datapoints'] ?? null) || ! array_is_list($source['datapoints'])
            || $source['datapoints'] === []) {
            $reject();
        }
        foreach (['name', 'url', 'sha256', 'workbook_version', 'downloaded_at', 'note'] as $key) {
            if (! is_string($source['source'][$key] ?? null) || trim($source['source'][$key]) === '') { $reject(); }
        }
        if (! preg_match('/\A[0-9a-f]{64}\z/i', $source['source']['sha256'])) { $reject(); }
        $seen = [];
        foreach ($source['datapoints'] as $row) {
            if (! is_array($row)) { $reject(); }
            foreach (['id', 'esrs', 'name', 'inclusion_type'] as $key) {
                if (! is_string($row[$key] ?? null) || trim($row[$key]) === '') { $reject(); }
            }
            // IG-3 legitimately contains null DRs, data types and optional annotations.
            // A missing member is not the same as an explicitly empty annotation.
            foreach (['dr', 'paragraph', 'related_ar', 'data_type', 'conditional_or_alternative',
                'appendix_b', 'phase_in_less_than_750', 'phase_in_all'] as $key) {
                if (! array_key_exists($key, $row) || ($row[$key] !== null && ! is_string($row[$key]))) { $reject(); }
            }
            if (! is_bool($row['may_disclose'] ?? null)
                || ! in_array($row['inclusion_type'], ['always_required', 'materiality_based', 'minimum_disclosure_requirement'], true)
                || isset($seen[$row['id']])) { $reject(); }
            $seen[$row['id']] = true;
        }
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
     * @param  array<string, mixed>  $employeeCount
     * @param  array<int, array<string, mixed>>  $datapoints
     * @return array<string, mixed>
     */
    private function phaseInAssessment(array $employeeCount, array $datapoints): array
    {
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
            'application' => [
                'mode' => 'first_report_assumed',
                'less_than_750_relief_applied' => $employeeCount['less_than_750'] === true,
                'note' => 'Phase-in reliefs are applied as default-unselected datapoints assuming this is the undertaking\'s first sustainability statement; the size-dependent relief applies only when the workforce is below 750 employees.',
            ],
            'note' => 'Phase-in application marks datapoints as default-unselected; it never deletes them. ESRS remains authoritative for final applicability and timing.',
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
        bool $lessThan750,
    ): array {
        $requiredCount = count(array_filter(
            $all,
            fn (array $datapoint) => $this->datapointSelection($datapoint, $lessThan750)['default_selected']
        ));

        return [
            'always_required_datapoint_count' => count($alwaysRequired),
            'topical_datapoint_count' => count($topical),
            'minimum_disclosure_requirement_datapoint_count' => count($minimumDisclosureRequirements),
            'total_datapoint_count' => count($all),
            'required_datapoint_count' => $requiredCount,
            'default_unselected_datapoint_count' => count($all) - $requiredCount,
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
    private function datapointDtos(array $datapoints, string $blockKey, string $mappingBasis, bool $lessThan750): array
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
            'selection' => $this->datapointSelection($datapoint, $lessThan750),
            'applicability' => $this->datapointApplicability($datapoint, $blockKey, $mappingBasis),
        ], $datapoints);
    }

    /**
     * Default selection state: voluntary datapoints and permitted phase-in
     * deferrals (first sustainability statement assumed) start unselected.
     *
     * @param  array<string, mixed>  $datapoint
     * @return array<string, mixed>
     */
    private function datapointSelection(array $datapoint, bool $lessThan750): array
    {
        $reasonCodes = [];

        if ($datapoint['may_disclose']) {
            $reasonCodes[] = 'voluntary_may_disclose';
        }

        if (filled($datapoint['phase_in_all'])) {
            $reasonCodes[] = 'phase_in_all_undertakings';
        }

        if ($lessThan750 && filled($datapoint['phase_in_less_than_750'])) {
            $reasonCodes[] = 'phase_in_less_than_750';
        }

        return [
            'default_selected' => $reasonCodes === [],
            'reason_codes' => $reasonCodes,
        ];
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
     * The E1 exemption asks "did the user assess E1 and reject it?". That is a
     * question about the FROZEN proposal the user actually confirmed against, not
     * about whatever P6 proposes today — P6 re-runs, so the live ids drift. Using
     * the live ids here while the omission statements use the snapshot would let one
     * document assert "E1 se evaluó y no se consideró material" while this block
     * silently stops applying. With no snapshot, only a stored explanation can
     * establish this exception; the current live proposal is not evidence.
     *
     * @param  array<string, mixed>  $confirmation
     * @return array<int, int>
     */
    private function e1BaselineTopicIds(array $confirmation): array
    {
        $snapshotIds = Arr::get($confirmation, 'p6_snapshot.topic_ids');

        return is_array($snapshotIds) ? $this->topicIds($snapshotIds) : [];
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
        $explanation = Arr::get($characterization->form_data ?? [], 'materiality_confirmation.e1_not_material_explanation');
        // Legacy confirmations without a P6 snapshot still retain the user's
        // explanation. A later live proposal must never hide that narrative.
        $applies = ! $materialHasE1 && ($p6HadE1 || filled($explanation));

        return [
            'key' => 'e1_not_material_explanation',
            'title' => 'E1 not material explanation',
            'applies' => $applies,
            'status' => $applies && filled($explanation) ? 'satisfied' : ($applies ? 'missing' : 'not_applicable'),
            'explanation' => $explanation,
        ];
    }
}
