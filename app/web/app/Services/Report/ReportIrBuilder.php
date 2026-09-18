<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\ReportApproval;
use App\Models\ReportSnapshot;
use App\Services\EsrsDatapointCorpusBuilder;
use DomainException;
use Illuminate\Support\Arr;

/**
 * Assembles the immutable, versioned typed-AST Content IR ("p10_ir_v1") for the
 * report package: it walks the existing EsrsDatapointCorpusBuilder applicable
 * slice and enriches every datapoint with an XBRL tagging slot, guidance text,
 * and related-DR cross-references, then hashes the whole tree so the same
 * characterization always yields the same version_hash.
 */
class ReportIrBuilder
{
    public function __construct(
        private readonly EsrsDatapointCorpusBuilder $corpus,
        private readonly XbrlConceptMap $xbrl,
        private readonly GuidanceLibrary $guidance,
        private readonly RelatedDrMap $relatedDr,
        private readonly FloorProseComposer $prose,
        private readonly NotMaterialTopicResolver $notMaterial,
        private readonly ReportClaimBuilder $claims,
        private readonly ReportingProfileRepository $profiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Characterization $characterization): array
    {
        $corpus = $this->corpus->build($characterization);
        $inScopeDrKeys = $this->inScopeDrKeys($corpus);
        $omissions = $this->notMaterial->resolve($characterization);

        $chapters = [];
        foreach ($corpus['blocks'] as $block) {
            if (! is_array($block) || ! ($block['applies'] ?? false)) {
                continue;
            }

            $chapters[] = ($block['key'] ?? null) === 'e1_not_material_explanation'
                ? $this->e1ExceptionChapter($block)
                : $this->chapter($block, $inScopeDrKeys, $corpus);
        }

        $disclaimers = [
            'No es presentación oficial, aseguramiento, Taxonomía UE ni un documento iXBRL presentado.',
        ];

        if ($omissions['is_stale']) {
            $disclaimers[] = $this->prose->staleDisclaimer($omissions['confirmed_at']);
        }

        $company = [
            'name' => Arr::get($characterization->form_data ?? [], 'company_profile.company_name'),
            'reporting_year' => Arr::get($characterization->form_data ?? [], 'company_profile.reporting_year'),
        ];
        $companyProfile = Arr::get($characterization->form_data ?? [], 'company_profile');
        if (is_array($companyProfile)) {
            foreach (['entity_identifier', 'entity_identifier_scheme'] as $key) {
                if (array_key_exists($key, $companyProfile)) {
                    $company[$key] = $companyProfile[$key];
                }
            }
        }

        $ir = [
            'schema_version' => 'p10_ir_v1',
            'asset_versions' => [
                'ig3' => Arr::get($corpus, 'generation.workbook_version'),
                'xbrl' => $this->xbrl->version(),
                'related_dr' => $this->relatedDr->version(),
            ],
            'company' => $company,
            'omission_section' => $this->omissionSection($omissions),
            'chapters' => $chapters,
            'disclaimers' => $disclaimers,
        ];

        $ir['version_hash'] = hash('sha256', json_encode($ir, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $ir;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildFromApprovedSnapshot(ReportSnapshot $snapshot): array
    {
        $approval = $this->approvedSnapshotApproval($snapshot);
        $snapshotJson = is_array($snapshot->snapshot_json) ? $snapshot->snapshot_json : [];
        $profileId = (string) ($snapshot->profile_id ?: Arr::get($snapshotJson, 'profile.profile_id'));

        $profile = $this->profiles->load($profileId);
        if ($profile->hash() !== $snapshot->profile_hash) {
            throw new DomainException('report_snapshot_profile_hash_mismatch');
        }

        if (Arr::get($snapshotJson, 'profile.profile_hash') !== $snapshot->profile_hash) {
            throw new DomainException('report_snapshot_payload_profile_hash_mismatch');
        }

        $characterizationPayload = Arr::get($snapshotJson, 'characterization');
        if (! is_array($characterizationPayload)) {
            throw new DomainException('report_snapshot_missing_characterization');
        }

        $facts = Arr::get($snapshotJson, 'facts');
        if (! is_array($facts)) {
            throw new DomainException('report_snapshot_missing_facts');
        }

        $characterization = $this->hydrateFrozenCharacterization($characterizationPayload);
        $layout = $this->build($characterization);
        unset($layout['version_hash']);

        $claims = $this->claims->build($facts, $snapshot->snapshot_hash, $profileId);
        $ir = [
            'schema_version' => 'report_ir_v1',
            'source' => [
                'report_snapshot_id' => $snapshot->id,
                'report_approval_id' => $approval->id,
                'snapshot_hash' => $snapshot->snapshot_hash,
                'profile_id' => $profileId,
                'profile_hash' => $snapshot->profile_hash,
                'approved_at' => $approval->approved_at?->toJSON(),
            ],
            'claims' => $claims,
            'asset_versions' => $layout['asset_versions'],
            'company' => $layout['company'],
            'omission_section' => $layout['omission_section'],
            'chapters' => $this->attachClaimsToDatapointBlocks($layout['chapters'], $claims),
            'disclaimers' => $layout['disclaimers'],
        ];

        $ir['version_hash'] = hash('sha256', $this->canonicalJson($ir));

        return $ir;
    }

    private function approvedSnapshotApproval(ReportSnapshot $snapshot): ReportApproval
    {
        $snapshot->loadMissing('approval');
        $approval = $snapshot->approval;

        if (! $approval instanceof ReportApproval) {
            throw new DomainException('report_snapshot_approval_missing');
        }

        if ($approval->snapshot_hash !== $snapshot->snapshot_hash) {
            throw new DomainException('report_snapshot_approval_hash_mismatch');
        }

        return $approval;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hydrateFrozenCharacterization(array $payload): Characterization
    {
        $characterization = new Characterization();
        $characterization->forceFill([
            'id' => $payload['id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'status' => $payload['status'] ?? null,
            'nace_code' => $payload['nace_code'] ?? null,
            'esrs_topic_ids' => is_array($payload['esrs_topic_ids'] ?? null) ? $payload['esrs_topic_ids'] : [],
            'form_data' => is_array($payload['form_data'] ?? null) ? $payload['form_data'] : [],
            'result_data' => is_array($payload['result_data'] ?? null) ? $payload['result_data'] : [],
        ]);
        $characterization->exists = false;

        return $characterization;
    }

    /**
     * @param  list<array<string, mixed>>  $chapters
     * @param  list<array<string, mixed>>  $claims
     * @return list<array<string, mixed>>
     */
    private function attachClaimsToDatapointBlocks(array $chapters, array $claims): array
    {
        $claimsByDatapoint = [];
        $claimsById = [];
        foreach ($claims as $claim) {
            $datapointId = (string) ($claim['datapoint_id'] ?? '');
            if ($datapointId === '') {
                continue;
            }

            $claimsByDatapoint[$datapointId][] = $claim['claim_id'];
            $claimsById[(string) $claim['claim_id']] = $claim;
        }

        foreach ($chapters as &$chapter) {
            if (! isset($chapter['sections']) || ! is_array($chapter['sections'])) {
                continue;
            }

            foreach ($chapter['sections'] as &$section) {
                if (! isset($section['blocks']) || ! is_array($section['blocks'])) {
                    continue;
                }

                foreach ($section['blocks'] as &$block) {
                    if (! array_key_exists('datapoint_id', $block)) {
                        continue;
                    }

                    $baseSlots = is_array($block['slots'] ?? null) ? $block['slots'] : [];
                    $baseSlot = $baseSlots[0] ?? [];
                    $blockClaims = $claimsByDatapoint[(string) $block['datapoint_id']] ?? [];
                    $block['claims'] = $blockClaims;
                    $block['slots'] = array_map(
                        fn (string $claimId): array => $this->slotFromClaim($baseSlot, $claimsById[$claimId]),
                        $blockClaims,
                    );
                }
            }
        }
        unset($chapter, $section, $block);

        return $chapters;
    }

    /**
     * @param  array<string, mixed>  $baseSlot
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    private function slotFromClaim(array $baseSlot, array $claim): array
    {
        $datapointId = (string) $claim['datapoint_id'];
        $factId = (string) $claim['fact_id'];

        return [
            'node_id' => 'slot_'.$datapointId.'_'.$factId,
            'claim_id' => (string) $claim['claim_id'],
            'fact_id' => $factId,
            'label' => $baseSlot['label'] ?? $datapointId,
            'xbrl_concept' => $baseSlot['xbrl_concept'] ?? null,
            'taggable_state' => $baseSlot['taggable_state'] ?? 'unmapped',
        ];
    }

    /**
     * The consolidated omission section sits above the chapters, because a whole
     * removed standard has no chapter of its own to be declared in.
     *
     * @param  array<string, mixed>  $omissions
     * @return array<string, mixed>
     */
    private function omissionSection(array $omissions): array
    {
        $statements = array_map(
            fn (array $topic) => $this->prose->omissionStatement($topic),
            $omissions['omitted_topics']
        );

        $hasDirect = collect($omissions['omitted_topics'])
            ->contains(fn (array $t) => $t['evidence_grade'] === NotMaterialTopicResolver::GRADE_DIRECT);

        $unresolvedCount = count($omissions['unresolved_omitted_topic_ids']);

        return [
            'title' => 'Temas evaluados y no considerados materiales',
            'declaration' => $omissions['inferred_omissions_status'] === NotMaterialTopicResolver::STATUS_NOT_DETERMINABLE
                ? $this->prose->notDeterminableDeclaration($hasDirect)
                : null,
            'limitation' => $unresolvedCount > 0 ? $this->prose->unresolvedLimitation($unresolvedCount) : null,
            'statements' => $statements,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<int, string>  $inScopeDrKeys
     * @param  array<string, mixed>  $corpus
     * @return array<string, mixed>
     */
    private function chapter(array $block, array $inScopeDrKeys, array $corpus): array
    {
        $sections = [];
        foreach ($block['disclosure_requirements'] ?? [] as $dr) {
            $drKey = (string) ($dr['dr'] ?? $dr['key']);
            $blocks = [];

            foreach ($this->datapointsForDr($block, $drKey) as $dp) {
                $concept = $this->xbrl->conceptFor($dp['id']);
                $guidance = $this->guidance->guidanceFor($dp['id'], $drKey);
                $blocks[] = [
                    'datapoint_id' => $dp['id'],
                    'name' => $dp['name'],
                    'assertions' => [$dp['applicability']['reason'] ?? ''],
                    'slots' => [[
                        'node_id' => 'slot_'.$dp['id'],
                        'label' => $dp['name'],
                        'xbrl_concept' => $concept['concept_id'] ?? null,
                        'taggable_state' => $concept['taggable_state'] ?? 'unmapped',
                    ]],
                    'guidance' => $guidance,
                ];
            }

            $edges = $this->relatedDr->resolveEdges($drKey, $inScopeDrKeys);

            $sections[] = [
                'dr_key' => $drKey,
                'standard' => $dr['standard'] ?? $block['standards'][0] ?? '',
                'cross_refs' => $edges,
                'cross_ref_sentences' => array_map(fn (array $e) => $this->prose->crossRefSentence($e), $edges),
                'blocks' => $blocks,
            ];
        }

        return [
            'title' => $block['title'],
            'block_key' => $block['key'],
            'floor_prose' => $this->floorProse($block, $corpus),
            'sections' => $sections,
        ];
    }

    /**
     * The E1 exclusion block (`EsrsDatapointCorpusBuilder::e1ExceptionBlock()`)
     * carries a single mandatory free-text explanation and has no
     * `disclosure_requirements`/`datapoints` shape like the other blocks, so
     * it cannot go through chapter() — that would silently drop the
     * ESRS-mandated "why E1 was excluded" text. Assemble it directly instead.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function e1ExceptionChapter(array $block): array
    {
        return [
            'title' => $block['title'],
            'block_key' => $block['key'],
            'floor_prose' => null,
            'sections' => [[
                'dr_key' => 'E1',
                'standard' => 'E1',
                'cross_refs' => [],
                'cross_ref_sentences' => [],
                'blocks' => [[
                    'datapoint_id' => 'e1_not_material_explanation',
                    'name' => $block['title'],
                    'assertions' => [$block['explanation'] ?? ''],
                    'slots' => [],
                    'guidance' => [
                        'text' => 'Nota: este bloque recoge la explicación obligatoria de por qué E1 (Cambio climático) se excluyó tras la evaluación de doble materialidad, conforme a ESRS 2 IRO-2 párrafo 33 y la nota de exención de E1. No es un texto de orientación certificado.',
                        'provenance_tier' => 'generic_scaffold',
                        'authoritative' => false,
                        'citations' => [],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * Chapter-level prose: the confirmed material themes of this block's standards.
     *
     * Omitted topics of the same standards are declared once, in the consolidated
     * root `omission_section` (see `omissionSection()`), not repeated here — see the
     * owner decision recorded in `docs/superpowers/specs/2026-07-09-p10-floor-omissions-design.md`.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $corpus
     */
    private function floorProse(array $block, array $corpus): ?string
    {
        $standards = $block['standards'] ?? [];

        // ->unique() fixes a pre-existing defect: `theme.es` is the standard-level name and
        // repeats across a standard's topics, so a chapter covering three E3 topics used to
        // read "Este capítulo cubre Agua y recursos marinos, Agua y recursos marinos y
        // Agua y recursos marinos." It was never rendered, so nobody saw it.
        $themes = collect($corpus['material_topics'] ?? [])
            ->filter(fn (array $topic) => in_array($topic['esrs_code'], $standards, true))
            ->map(fn (array $topic) => $topic['theme']['es'] ?? $topic['esrs_code'])
            ->unique()
            ->values()
            ->all();

        if ($themes === []) {
            return null;
        }

        return $this->prose->chapterIntro($themes, []);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<int, array<string, mixed>>
     */
    private function datapointsForDr(array $block, string $drKey): array
    {
        return array_values(array_filter(
            $block['datapoints'] ?? [],
            fn (array $dp) => trim((string) ($dp['dr'] ?? '')) === $drKey
        ));
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<int, string>
     */
    private function inScopeDrKeys(array $corpus): array
    {
        $keys = [];
        foreach ($corpus['blocks'] as $block) {
            if (! is_array($block)) {
                continue;
            }

            foreach ($block['disclosure_requirements'] ?? [] as $dr) {
                $keys[] = (string) ($dr['dr'] ?? $dr['key']);
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
