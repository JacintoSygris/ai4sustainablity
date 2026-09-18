<?php

namespace App\Services\Report;

use App\Models\Characterization;
use App\Models\ReportingFact;
use App\Services\EsrsDatapointCorpusBuilder;
use Illuminate\Support\Arr;

class ReportingFactProjector
{
    public function __construct(
        private readonly EsrsDatapointCorpusBuilder $corpusBuilder,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function projectLegacy(Characterization $characterization): array
    {
        $stored = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses.responses', []);

        if (! is_array($stored)) {
            return [];
        }

        $allowedDatapointIds = array_flip($this->corpusDatapointIds($this->corpusBuilder->build($characterization)));
        $facts = [];

        foreach ($stored as $response) {
            if (! is_array($response)) {
                continue;
            }

            $datapointId = trim((string) ($response['datapoint_id'] ?? ''));
            $status = $response['status'] ?? null;

            if ($datapointId === '' || ! isset($allowedDatapointIds[$datapointId])) {
                continue;
            }

            if (! in_array($status, ['completed', 'draft', 'not_applicable'], true)) {
                continue;
            }

            $facts[] = $this->projectResponse($characterization, $datapointId, $response, $status);
        }

        return $facts;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function projectResponse(
        Characterization $characterization,
        string $datapointId,
        array $response,
        string $status,
    ): array {
        $text = $this->filledString($response['value'] ?? null);
        $hasText = $text !== null;
        $valueType = $hasText ? 'text' : 'nil';
        $language = $hasText ? 'und' : null;
        $evidenceRefs = $this->candidateEvidenceRefs($response);
        $applicability = match ($status) {
            'completed' => 'applicable',
            'draft' => 'pending',
            'not_applicable' => 'not_applicable',
        };

        $blockingReasons = [];
        if ($status === 'not_applicable' && $evidenceRefs === []) {
            $blockingReasons[] = 'legacy_not_applicable_missing_evidence';
        }

        return [
            'origin' => 'legacy_projection',
            'fact_id' => ReportingFact::factId(
                $characterization->id,
                ReportingFact::PROFILE_ID,
                $datapointId,
                [],
                $valueType,
                $language,
            ),
            'schema_version' => ReportingFact::SCHEMA_VERSION,
            'profile_id' => ReportingFact::PROFILE_ID,
            'datapoint_id' => $datapointId,
            'applicability' => $applicability,
            'value_type' => $valueType,
            'value' => $hasText ? ['text' => $text] : null,
            'unit' => null,
            'decimals' => null,
            'dimensions' => [],
            'language' => $language,
            'nil' => ! $hasText,
            'nil_reason' => $hasText ? null : 'legacy_no_value',
            'evidence_refs' => $evidenceRefs,
            'provenance' => 'p9_legacy_review_required',
            'approval_status' => 'review_required',
            'blocking_reasons' => $blockingReasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, string>>
     */
    private function candidateEvidenceRefs(array $response): array
    {
        $refs = [];

        foreach (['evidence_reference', 'note'] as $field) {
            $value = $this->filledString($response[$field] ?? null);

            if ($value !== null) {
                $refs[] = [
                    'type' => 'p9_legacy_'.$field,
                    'value' => $value,
                ];
            }
        }

        return $refs;
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<int, string>
     */
    private function corpusDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block) => $block['datapoints'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values()
            ->all();
    }
}
