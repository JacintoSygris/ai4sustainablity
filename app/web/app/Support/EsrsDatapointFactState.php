<?php

namespace App\Support;

use App\Models\Characterization;
use App\Services\Report\XbrlConceptMap;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EsrsDatapointFactState
{
    public const SCHEMA_VERSION = 'v1';

    private const VALUE_KINDS = ['narrative', 'string', 'boolean', 'date', 'integer', 'decimal', 'monetary', 'percent'];
    private const NUMERIC_KINDS = ['integer', 'decimal', 'monetary', 'percent'];
    private const QNAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?$/';

    public function __construct(private readonly XbrlConceptMap $conceptMap) {}

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<string, mixed>
     */
    public function state(Characterization $characterization, array $corpus): array
    {
        $stored = Arr::get($characterization->form_data ?? [], 'esrs_datapoint_responses', []);
        $stored = is_array($stored) ? $stored : [];
        $responses = Arr::get($stored, 'responses', []);
        $responses = is_array($responses) ? $responses : [];
        $allowedDatapoints = $this->datapointLookup($corpus);
        $allowedDatapointIds = array_keys($allowedDatapoints);
        $allowedDatapointLookup = array_flip($allowedDatapointIds);
        $reportingEntity = $this->normalizeReportingEntity(Arr::get($stored, 'reporting_entity', []), false);

        $normalized = [];
        foreach ($responses as $datapointId => $response) {
            if (! is_array($response)) {
                continue;
            }

            $id = trim((string) ($response['datapoint_id'] ?? $datapointId));
            if ($id === '') {
                continue;
            }

            $datapoint = $allowedDatapoints[$id] ?? null;
            $normalized[$id] = $this->normalizeStoredResponse($id, $response, is_array($datapoint) ? $datapoint : null);
        }

        $orphanedResponses = array_diff_key($normalized, $allowedDatapointLookup);
        $liveResponses = array_intersect_key($normalized, $allowedDatapointLookup);

        return [
            'characterization_id' => $characterization->id,
            'schema_version' => self::SCHEMA_VERSION,
            'stored_schema_version' => Arr::get($stored, 'schema_version'),
            'updated_at' => Arr::get($stored, 'updated_at'),
            'reporting_entity' => $reportingEntity,
            'datapoints' => $this->datapointMetadata($allowedDatapoints),
            'responses' => $liveResponses,
            'orphaned' => [
                'count' => count($orphanedResponses),
                'responses' => $orphanedResponses,
            ],
            'summary' => $this->summary($liveResponses, $this->requiredDatapointIds($corpus)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $corpus
     * @param  array<string, array<string, mixed>>  $orphanedResponses
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload, array $corpus, array $orphanedResponses): array
    {
        $errors = [];
        $responses = Arr::get($payload, 'responses', []);
        $responses = is_array($responses) ? $responses : [];
        $datapoints = $this->datapointLookup($corpus);
        $ids = [];
        $idIndexes = [];
        $reportingEntity = $this->normalizeReportingEntity(Arr::get($payload, 'reporting_entity', []), false);
        $hasCompletedFacts = false;

        foreach ($responses as $index => $response) {
            if (! is_array($response)) {
                $errors["responses.$index"] = 'La respuesta debe ser un objeto.';
                continue;
            }

            $id = trim((string) ($response['datapoint_id'] ?? ''));
            if ($id === '') {
                $errors["responses.$index.datapoint_id"] = 'El datapoint es obligatorio.';
                continue;
            }

            if (in_array($id, $ids, true)) {
                $errors["responses.$index.datapoint_id"] = 'Cada datapoint sólo puede enviarse una vez.';
                foreach ($idIndexes[$id] ?? [] as $previousIndex) {
                    $errors["responses.$previousIndex.datapoint_id"] = 'Cada datapoint sólo puede enviarse una vez.';
                }
                $errors['responses'] = 'Cada datapoint sólo puede enviarse una vez.';
            }

            $ids[] = $id;
            $idIndexes[$id][] = $index;

            if (! array_key_exists($id, $datapoints)) {
                $errors["responses.$index.datapoint_id"] = 'El datapoint no forma parte del alcance actual.';
                $errors['responses'] = 'Uno o más datapoints no forman parte del alcance actual.';
            }
        }

        $normalizedResponses = [];
        $updatedAt = now()->toJSON();

        foreach ($responses as $index => $response) {
            if (! is_array($response)) {
                continue;
            }

            $id = trim((string) ($response['datapoint_id'] ?? ''));
            if ($id === '' || ! array_key_exists($id, $datapoints)) {
                continue;
            }

            $normalized = $this->normalizeSubmittedResponse($id, $response, $datapoints[$id], $index, $errors);
            $normalized['updated_at'] = $updatedAt;
            $normalizedResponses[$id] = $normalized;

            if ($normalized['status'] === 'completed' && count($normalized['facts']) > 0) {
                $hasCompletedFacts = true;
            }
        }

        if ($hasCompletedFacts) {
            $reportingEntity = $this->normalizeReportingEntity(Arr::get($payload, 'reporting_entity', []), true, $errors);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'updated_at' => $updatedAt,
            'reporting_entity' => $reportingEntity,
            'responses' => array_replace($orphanedResponses, $normalizedResponses),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $datapoint
     * @return array<string, mixed>
     */
    public function normalizeStoredResponse(string $datapointId, array $response, ?array $datapoint = null): array
    {
        $status = in_array(($response['status'] ?? null), ['draft', 'completed', 'not_applicable'], true)
            ? $response['status']
            : 'draft';

        $normalized = [
            'datapoint_id' => $datapointId,
            'status' => $status,
            'updated_at' => is_string($response['updated_at'] ?? null) ? $response['updated_at'] : null,
            'facts' => $this->normalizeStoredFacts($datapointId, Arr::get($response, 'facts', [])),
            'concept' => $this->concept($datapointId),
            'suggested_value_kind' => $this->suggestedValueKind((string) Arr::get($datapoint, 'data_type', '')),
        ];

        foreach (['value', 'note', 'evidence_reference', 'triage'] as $field) {
            if (array_key_exists($field, $response)) {
                $normalized[$field === 'value' ? 'legacy_value' : $field] = $response[$field];
                if ($field === 'value') {
                    $normalized['value'] = $response[$field];
                }
            }
        }

        $normalized['fact_readiness'] = $this->responseReadiness($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, array<string, mixed>>  $responses
     * @param  list<string>  $requiredDatapointIds
     * @return array<string, int|float|string>
     */
    public function summary(array $responses, array $requiredDatapointIds): array
    {
        $statusCounts = collect($responses)->countBy('status');
        $validCompleted = collect($responses)->filter(fn (array $response): bool => $this->isValidCompleted($response))->count();
        $invalidCompleted = collect($responses)->filter(fn (array $response): bool => ($response['status'] ?? null) === 'completed' && ! $this->isValidCompleted($response))->count();
        $validNotApplicable = collect($responses)->filter(fn (array $response): bool => $this->isValidNotApplicable($response))->count();
        $facts = collect($responses)->flatMap(fn (array $response): array => $response['facts'] ?? []);
        $taggableFacts = $facts->filter(fn (array $fact): bool => Arr::get($fact, 'concept.taggable_state') === 'mapped')->count();
        $requiredResponses = array_intersect_key($responses, array_flip($requiredDatapointIds));
        $requiredDecided = collect($requiredResponses)
            ->filter(fn (array $response): bool => $this->isValidCompleted($response) || $this->isValidNotApplicable($response))
            ->count();
        $requiredCount = count($requiredDatapointIds);

        return [
            'applicable_datapoint_count' => $requiredCount,
            'response_count' => count($responses),
            'completed_count' => $validCompleted,
            'draft_count' => (int) $statusCounts->get('draft', 0),
            'not_applicable_count' => $validNotApplicable,
            'invalid_completed_count' => $invalidCompleted,
            'invalid_not_applicable_count' => (int) $statusCounts->get('not_applicable', 0) - $validNotApplicable,
            'optional_response_count' => count($responses) - count($requiredResponses),
            'decided_count' => $validCompleted + $validNotApplicable,
            'facts_count' => $facts->count(),
            'taggable_facts_count' => $taggableFacts,
            'unmapped_or_not_taggable_facts_count' => $facts->count() - $taggableFacts,
            'completion_ratio' => $requiredCount > 0 ? round($requiredDecided / $requiredCount, 4) : 1.0,
            'completion_status' => match (true) {
                count($responses) === 0 => 'not_started',
                $requiredCount > 0 && $requiredDecided >= $requiredCount => 'completed',
                default => 'in_progress',
            },
        ];
    }

    public function suggestedValueKind(string $dataType): string
    {
        // Deterministic P9 v1 suggestion map:
        // monetary => monetary; percent/percentage => percent; integer => integer;
        // narrative/text/table/semi-narrative => narrative; mixed or unknown => string.
        $type = Str::lower(trim($dataType));

        return match (true) {
            in_array($type, ['monetary'], true) => 'monetary',
            in_array($type, ['percent', 'percentage'], true) => 'percent',
            $type === 'integer' => 'integer',
            str_contains($type, '/') && ! str_starts_with($type, 'table') => 'string',
            str_contains($type, 'narrative') || str_contains($type, 'text') || str_starts_with($type, 'table') => 'narrative',
            default => 'string',
        };
    }

    /**
     * @param  array<string, mixed>  $corpus
     * @return array<string, array<string, mixed>>
     */
    public function datapointLookup(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block) => $block['datapoints'] ?? [])
            ->filter(fn ($datapoint): bool => is_array($datapoint) && filled($datapoint['id'] ?? null))
            ->mapWithKeys(fn (array $datapoint): array => [trim((string) $datapoint['id']) => $datapoint])
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $datapoints
     * @return array<string, array<string, mixed>>
     */
    private function datapointMetadata(array $datapoints): array
    {
        return collect($datapoints)
            ->mapWithKeys(fn (array $datapoint, string $id): array => [
                $id => [
                    'concept' => $this->concept($id),
                    'suggested_value_kind' => $this->suggestedValueKind((string) Arr::get($datapoint, 'data_type', '')),
                ],
            ])
            ->all();
    }

    /** @return list<string> */
    public function requiredDatapointIds(array $corpus): array
    {
        return collect($corpus['blocks'] ?? [])
            ->flatMap(fn (array $block) => $block['datapoints'] ?? [])
            ->filter(fn (array $datapoint) => (bool) Arr::get($datapoint, 'selection.default_selected', true))
            ->pluck('id')
            ->filter()
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, string|null> */
    private function concept(string $datapointId): array
    {
        return $this->conceptMap->conceptFor($datapointId) ?? [
            'concept_id' => null,
            'taggable_state' => 'unmapped',
            'reason_code' => 'concept_map_unavailable',
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $datapoint
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function normalizeSubmittedResponse(string $datapointId, array $response, array $datapoint, int $index, array &$errors): array
    {
        $status = $response['status'] ?? null;
        if (! in_array($status, ['draft', 'completed', 'not_applicable'], true)) {
            $errors["responses.$index.status"] = 'El estado no es válido.';
            $status = 'draft';
        }

        $normalized = [
            'datapoint_id' => $datapointId,
            'status' => $status,
            'facts' => [],
        ];

        foreach (['value', 'note', 'evidence_reference', 'triage'] as $field) {
            if (array_key_exists($field, $response) && filled($response[$field])) {
                $normalized[$field === 'value' ? 'legacy_value' : $field] = is_string($response[$field])
                    ? trim($response[$field])
                    : $response[$field];
                if ($field === 'value') {
                    $normalized['value'] = $normalized['legacy_value'];
                }
            }
        }

        $facts = Arr::get($response, 'facts', []);
        if (is_array($facts)) {
            foreach (array_values($facts) as $factIndex => $fact) {
                if (! is_array($fact)) {
                    $errors["responses.$index.facts.$factIndex"] = 'El fact debe ser un objeto.';
                    continue;
                }

                $normalized['facts'][] = $this->normalizeFact($datapointId, $fact, "responses.$index.facts.$factIndex", $errors);
            }
        }

        if ($status === 'completed' && $normalized['facts'] === []) {
            $errors["responses.$index.facts"] = 'Una respuesta completada necesita al menos un fact válido.';
        }

        if ($status === 'not_applicable') {
            if (! filled($normalized['note'] ?? null)) {
                $errors["responses.$index.note"] = 'No aplica requiere una razón.';
            }
            if (! filled($normalized['evidence_reference'] ?? null)) {
                $errors["responses.$index.evidence_reference"] = 'No aplica requiere evidencia.';
            }
        }

        $normalized['fact_readiness'] = $this->responseReadiness($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $fact
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function normalizeFact(string $datapointId, array $fact, string $path, array &$errors): array
    {
        $kind = $fact['value_kind'] ?? null;
        if (! in_array($kind, self::VALUE_KINDS, true)) {
            $errors["$path.value_kind"] = 'El tipo de valor no es válido.';
            $kind = 'string';
        }

        $value = $this->canonicalValue($kind, $fact['value'] ?? null, "$path.value", $errors);
        $decimals = $this->normalizeDecimals($kind, $fact['decimals'] ?? null, "$path.decimals", $errors);
        $unit = $this->normalizeUnit($kind, $fact['unit'] ?? null, "$path.unit", $errors);
        $context = $this->normalizeContext($fact['context'] ?? null, "$path.context", $errors);
        $evidence = is_string($fact['evidence_reference'] ?? null) ? trim($fact['evidence_reference']) : '';
        if ($evidence === '') {
            $errors["$path.evidence_reference"] = 'Cada fact necesita evidencia.';
        }

        $seed = implode('|', [$datapointId, $kind, is_bool($value) ? ($value ? 'true' : 'false') : $value, json_encode($context), $evidence]);

        return [
            'fact_id' => $this->safeFactId($fact['fact_id'] ?? null, $seed),
            'value_kind' => $kind,
            'value' => $value,
            'decimals' => $decimals,
            'unit' => $unit,
            'context' => $context,
            'evidence_reference' => $evidence,
        ];
    }

    private function canonicalValue(string $kind, mixed $value, string $path, array &$errors): string|bool
    {
        if ($kind === 'boolean') {
            if (! is_bool($value)) {
                $errors[$path] = 'El valor booleano debe ser true o false.';
                return false;
            }

            return $value;
        }

        $text = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
        if ($text === '') {
            $errors[$path] = 'El valor es obligatorio.';
            return '';
        }

        if ($kind === 'integer' && ! preg_match('/^[+-]?\d+$/', $text)) {
            $errors[$path] = 'El entero debe usar sólo dígitos, sin separadores.';
        } elseif (in_array($kind, ['decimal', 'monetary', 'percent'], true) && ! preg_match('/^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/', $text)) {
            $errors[$path] = 'El número debe usar punto decimal y no llevar separadores de miles.';
        } elseif ($kind === 'date' && ! $this->validDate($text)) {
            $errors[$path] = 'La fecha debe usar formato YYYY-MM-DD.';
        }

        return $text;
    }

    private function normalizeDecimals(string $kind, mixed $value, string $path, array &$errors): ?int
    {
        if (! in_array($kind, self::NUMERIC_KINDS, true)) {
            if ($value !== null) {
                $errors[$path] = 'Los decimales sólo aplican a valores numéricos.';
            }

            return null;
        }

        if (! is_int($value)) {
            $errors[$path] = 'Los decimales son obligatorios para valores numéricos.';
            return null;
        }

        if ($value < -18 || $value > 18) {
            $errors[$path] = 'Los decimales deben estar entre -18 y 18.';
        }

        return $value;
    }

    private function normalizeUnit(string $kind, mixed $unit, string $path, array &$errors): ?array
    {
        $measure = is_array($unit) && is_string($unit['measure'] ?? null) ? trim($unit['measure']) : null;

        if ($kind === 'monetary') {
            if (! is_string($measure) || ! preg_match('/^iso4217:[A-Z]{3}$/', $measure)) {
                $errors["$path.measure"] = 'La unidad monetaria debe tener formato iso4217:EUR.';
            }

            return ['measure' => $measure ?? ''];
        }

        if ($kind === 'percent') {
            if (! in_array($measure, ['pure', 'xbrli:pure'], true)) {
                $errors["$path.measure"] = 'El porcentaje debe usar unidad pure o xbrli:pure.';
            }

            return ['measure' => $measure ?? ''];
        }

        if ($kind === 'decimal') {
            if (! is_string($measure) || (! in_array($measure, ['pure', 'xbrli:pure'], true) && ! preg_match(self::QNAME_PATTERN, $measure))) {
                $errors["$path.measure"] = 'La unidad decimal no es segura.';
            }

            return ['measure' => $measure ?? ''];
        }

        if ($unit !== null) {
            $errors[$path] = 'Este tipo de valor no debe llevar unidad.';
        }

        return null;
    }

    private function normalizeContext(mixed $context, string $path, array &$errors): array
    {
        if (! is_array($context)) {
            $errors[$path] = 'El contexto es obligatorio.';
            return ['period_type' => 'duration', 'start_date' => null, 'end_date' => null, 'instant_date' => null, 'dimensions' => []];
        }

        $periodType = $context['period_type'] ?? null;
        if (! in_array($periodType, ['duration', 'instant'], true)) {
            $errors["$path.period_type"] = 'El periodo debe ser duration o instant.';
            $periodType = 'duration';
        }

        $start = $this->dateOrNull($context['start_date'] ?? null);
        $end = $this->dateOrNull($context['end_date'] ?? null);
        $instant = $this->dateOrNull($context['instant_date'] ?? null);

        if ($periodType === 'duration') {
            if (! $start || ! $end) {
                $errors[$path] = 'El periodo de duración necesita fecha inicial y final.';
            } elseif ($start > $end) {
                $errors[$path] = 'La fecha inicial no puede ser posterior a la final.';
            }
            if ($instant !== null) {
                $errors["$path.instant_date"] = 'El periodo de duración no debe llevar fecha instantánea.';
            }
            $instant = null;
        } else {
            if (! $instant) {
                $errors[$path] = 'El periodo instantáneo necesita fecha.';
            }
            if ($start !== null || $end !== null) {
                $errors[$path] = 'El periodo instantáneo no debe llevar fecha inicial ni final.';
            }
            $start = null;
            $end = null;
        }

        $dimensions = $this->normalizeDimensions($context['dimensions'] ?? [], "$path.dimensions", $errors);

        return [
            'period_type' => $periodType,
            'start_date' => $start,
            'end_date' => $end,
            'instant_date' => $instant,
            'dimensions' => $dimensions,
        ];
    }

    private function normalizeDimensions(mixed $dimensions, string $path, array &$errors): array
    {
        if ($dimensions === null) {
            return [];
        }

        if (! is_array($dimensions)) {
            $errors[$path] = 'Las dimensiones deben ser una lista.';
            return [];
        }

        $out = [];
        foreach ($dimensions as $index => $dimension) {
            $axis = is_array($dimension) && is_string($dimension['axis'] ?? null) ? trim($dimension['axis']) : '';
            $member = is_array($dimension) && is_string($dimension['member'] ?? null) ? trim($dimension['member']) : '';
            if (! preg_match(self::QNAME_PATTERN, $axis) || ! preg_match(self::QNAME_PATTERN, $member)) {
                $errors["$path.$index"] = 'La dimensión debe usar tokens seguros.';
                continue;
            }
            if (array_key_exists($axis, $out)) {
                $errors["$path.$index.axis"] = 'Cada eje sólo puede aparecer una vez.';
                continue;
            }
            $out[$axis] = ['axis' => $axis, 'member' => $member];
        }

        ksort($out);

        return array_values($out);
    }

    private function normalizeReportingEntity(mixed $entity, bool $required, ?array &$errors = null): array
    {
        $errors ??= [];
        $entity = is_array($entity) ? $entity : [];
        $scheme = is_string($entity['identifier_scheme'] ?? null) ? trim($entity['identifier_scheme']) : '';
        $identifier = is_string($entity['identifier'] ?? null) ? trim($entity['identifier']) : '';
        $name = is_string($entity['name'] ?? null) ? trim($entity['name']) : null;

        if ($required) {
            if ($scheme === '' || mb_strlen($scheme) > 512) {
                $errors['reporting_entity.identifier_scheme'] = 'El esquema identificador de la entidad es obligatorio.';
            }
            if ($identifier === '' || mb_strlen($identifier) > 512) {
                $errors['reporting_entity.identifier'] = 'El identificador de la entidad es obligatorio.';
            }
        }

        return [
            'identifier_scheme' => $scheme,
            'identifier' => $identifier,
            'name' => $name !== '' ? $name : null,
        ];
    }

    private function safeFactId(mixed $factId, string $seed): string
    {
        if (is_string($factId) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $factId)) {
            return Str::lower($factId);
        }

        return 'fact-'.substr(hash('sha256', $seed), 0, 32);
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }

    private function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $date = trim($value);

        return $this->validDate($date) ? $date : null;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeStoredFacts(string $datapointId, mixed $facts): array
    {
        if (! is_array($facts)) {
            return [];
        }

        return collect(array_values($facts))
            ->filter(fn ($fact): bool => is_array($fact))
            ->map(function (array $fact) use ($datapointId): array {
                $fact['concept'] = $this->concept($datapointId);

                return $fact;
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $response */
    private function responseReadiness(array $response): array
    {
        if (($response['status'] ?? null) === 'completed') {
            return [
                'state' => $this->isValidCompleted($response) ? 'valid_completed' : 'invalid_completed',
                'fact_count' => count($response['facts'] ?? []),
            ];
        }

        if (($response['status'] ?? null) === 'not_applicable') {
            return [
                'state' => $this->isValidNotApplicable($response) ? 'valid_not_applicable' : 'invalid_not_applicable',
                'fact_count' => 0,
            ];
        }

        return [
            'state' => 'draft',
            'fact_count' => count($response['facts'] ?? []),
        ];
    }

    /** @param array<string, mixed> $response */
    private function isValidCompleted(array $response): bool
    {
        return ($response['status'] ?? null) === 'completed' && count($response['facts'] ?? []) > 0;
    }

    /** @param array<string, mixed> $response */
    private function isValidNotApplicable(array $response): bool
    {
        return ($response['status'] ?? null) === 'not_applicable'
            && filled($response['note'] ?? null)
            && filled($response['evidence_reference'] ?? null);
    }
}
