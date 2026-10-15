<?php

namespace App\Services;

use App\Exceptions\CharacterizationCapacityException;
use App\Models\Characterization;
use App\Models\NaceCode;
use App\Services\Contracts\PreparedCharacterizationGateway;
use App\Support\CharacterizationOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class ApiCharacterizationGateway implements PreparedCharacterizationGateway
{
    private const PYTHON_SECTOR_LABELS_BY_NACE_SECTION = [
        'A' => ['Agriculture'],
        'B' => ['Industry'],
        'C' => ['Manufacturing'],
        'D' => ['Energy'],
        'E' => ['Energy'],
        'F' => ['Construction'],
        'G' => ['Retail'],
        'H' => ['Mobility'],
        'I' => ['Consumer Goods'],
        'J' => ['Technology'],
        'K' => ['Information technology'],
        'L' => ['Financial Services'],
        'M' => ['Real Estate'],
        'N' => ['Industry'],
        'R' => ['Healthcare'],
    ];

    /** Deployment-local copy of app/contracts/mappings; byte equality is tested. */
    private function featureEncoding(): array
    {
        return json_decode(
            file_get_contents(base_path('data/p5-gpt41-feature-encoding-v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public function __construct(private readonly CharacterizationPredictionMapper $mapper) {}

    public function submit(Characterization $characterization): array
    {
        if (LearningP6InterpretationContext::requested()) {
            LearningP6InterpretationContext::guard();
            throw new \DomainException('learning_p6_interpretation.prepared_required');
        }
        $baseUrl = rtrim(config('services.characterization.api.base_url'), '/');
        $token = config('services.characterization.api.token');

        if (blank($baseUrl)) {
            throw new RuntimeException('Characterization API base URL is not configured.');
        }

        $payload = $this->predictionPayload($characterization);

        return $this->sendPayload($payload, $baseUrl, $token);
    }

    public function prepare(Characterization $characterization): LearningP6PreparedRequest
    {
        if (LearningP6InterpretationContext::requested()) { LearningP6InterpretationContext::guard(); }
        $this->assertPreparedRequestEnabled($characterization);

        $context = LearningP6InterpretationContext::requested() ? LearningP6InterpretationContext::capture() : null;
        return new LearningP6PreparedRequest($this->predictionPayload($characterization), $context);
    }

    public function submitPrepared(LearningP6PreparedRequest $request): array
    {
        if (LearningP6InterpretationContext::requested() || $request->interpretation() !== null) {
            LearningP6InterpretationContext::guard();
            if ($request->interpretation() === null) { throw new \DomainException('learning_p6_interpretation.missing'); }
        }
        $this->assertPreparedRequestEnabled();
        $baseUrl = rtrim(config('services.characterization.api.base_url'), '/');
        $token = config('services.characterization.api.token');
        if (blank($baseUrl)) {
            throw new RuntimeException('Characterization API base URL is not configured.');
        }

        $request->interpretation()?->assertCurrent();
        return $this->sendPayload($request->payload(), $baseUrl, $token, $request->interpretation()?->mapper());
    }

    private function assertPreparedRequestEnabled(?Characterization $characterization = null): void
    {
        if (config('services.learning_p6_prepared_request.enabled') !== true || ! app()->environment('testing')) {
            throw new RuntimeException('Prepared characterization requests require explicit isolated testing opt-in.');
        }
        $connection = DB::connection();
        $connections = [$connection];
        if ($characterization !== null) { $connections[] = $characterization->getConnection(); }
        if (! CharacterizationStateTransaction::admitsIsolatedConnections($connections)) {
            throw new RuntimeException('Prepared characterization requests require SQLite :memory:.');
        }
    }

    private function sendPayload(array $payload, string $baseUrl, mixed $token, ?CharacterizationPredictionMapper $capturedMapper = null): array
    {
        $mapper = $capturedMapper ?? $this->mapper;
        $client = Http::timeout(config('services.characterization.api.timeout', 30));

        if (filled($token)) {
            $client = $client->withToken($token);
        }

        try {
            $response = $client->post("{$baseUrl}/predict", $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                "AI prediction service is unreachable or timed out at {$baseUrl}. Check CHARACTERIZATION_API_BASE_URL and the private Python service.",
                0,
                $exception
            );
        }

        if ($response->failed()) {
            $capacityException = $this->capacityException($response);
            if ($capacityException !== null) {
                throw $capacityException;
            }

            throw new RuntimeException(
                'External characterization API responded with an error (HTTP '.$response->status().'): '
                .$this->responseFailureDetail($response)
            );
        }

        $predictionEnvelope = $this->predictionEnvelope($response);
        $rawPrediction = $predictionEnvelope['esrs'];
        $pythonMappingMetadata = $this->arrayOrEmpty($predictionEnvelope['mapping_metadata'] ?? null);
        $candidateTopics = $mapper->candidateTopics($rawPrediction);
        $candidateTopics = $this->withIndustryBasis(
            $candidateTopics,
            $this->arrayOrEmpty($pythonMappingMetadata['industry_basis_by_key'] ?? null)
        );
        $reviewRequiredKeys = $mapper->reviewRequiredKeys($rawPrediction);
        $laravelMappingMetadata = $mapper->mappingMetadata();
        $summary = 'AI proposed '.$this->counted(count($candidateTopics), 'candidate ESRS topic').'.';

        if ($reviewRequiredKeys !== []) {
            $reviewKeyCount = count($reviewRequiredKeys);
            $summary .= ' '.$this->counted($reviewKeyCount, 'predicted ESRS key').' '
                .($reviewKeyCount === 1 ? 'needs' : 'need').' manual review.';
        }

        return [
            'status' => 'completed',
            'score' => null,
            'summary' => $summary,
            'candidate_topics' => $candidateTopics,
            'review_required_prediction_keys' => $reviewRequiredKeys,
            'raw_prediction' => $rawPrediction,
            'request_payload' => $payload,
            'model_profile' => $this->stringOrNull($predictionEnvelope['model_profile'] ?? null),
            'model_key_count' => $this->integerOrNull($predictionEnvelope['model_key_count'] ?? null),
            'mapped_key_count' => $laravelMappingMetadata['mapping_key_count']
                ?? $this->integerOrNull($predictionEnvelope['mapped_key_count'] ?? null),
            'feature_metadata' => $this->featureMetadata($predictionEnvelope['feature_metadata'] ?? null),
            'mapping_metadata' => [
                'python' => $pythonMappingMetadata,
                'laravel' => $laravelMappingMetadata,
            ],
            'evidence_refs' => $this->arrayOrEmpty($predictionEnvelope['evidence_refs'] ?? null),
        ];
    }

    private function capacityException(Response $response): ?CharacterizationCapacityException
    {
        if ($response->status() !== 503 || $response->json('detail.code') !== 'prediction_capacity_busy') {
            return null;
        }

        $retryAfter = trim($response->header('Retry-After', ''));
        if ($retryAfter === '' || preg_match('/^\d+$/', $retryAfter) !== 1) {
            return null;
        }

        $seconds = (int) $retryAfter;
        if ($seconds < 1) {
            return null;
        }

        return new CharacterizationCapacityException(min(300, $seconds));
    }

    private function counted(int $count, string $singular): string
    {
        return $count.' '.$singular.($count === 1 ? '' : 's');
    }

    /**
     * @return array<string, mixed>
     */
    private function predictionEnvelope(Response $response): array
    {
        try {
            $body = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('External characterization API response is invalid: expected JSON object with an esrs object.');
        }

        if (! $body instanceof \stdClass || ! property_exists($body, 'esrs') || ! $body->esrs instanceof \stdClass) {
            throw new RuntimeException('External characterization API response is invalid: expected JSON object with an esrs object.');
        }

        $prediction = get_object_vars($body->esrs);

        foreach ($prediction as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new RuntimeException('External characterization API response is invalid: expected esrs to be an object keyed by ESRS prediction key.');
            }

            if (is_bool($value)) {
                $prediction[$key] = $value ? 1 : 0;
                continue;
            }

            if (! is_int($value) || ! in_array($value, [0, 1], true)) {
                throw new RuntimeException('External characterization API response is invalid: expected every esrs value to be 0 or 1.');
            }
        }

        $envelope = get_object_vars($body);
        $envelope['esrs'] = $prediction;

        return $envelope;
    }

    private function responseFailureDetail(Response $response): string
    {
        $detail = $response->json('detail');

        if (is_string($detail) && filled($detail)) {
            return $detail;
        }

        if (is_array($detail)) {
            $encoded = json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : 'Structured error detail could not be encoded.';
        }

        return filled($response->body()) ? $response->body() : 'No response body.';
    }

    /**
     * @return array<string, mixed>
     */
    private function predictionPayload(Characterization $characterization): array
    {
        $formData = $characterization->form_data ?? [];
        $operations = Arr::get($formData, 'operations', []);
        $companyProfile = Arr::get($formData, 'company_profile', []);
        $modelProfile = config('services.characterization.api.model_profile');

        $sectorList = $this->sectorListForPrediction($characterization, $modelProfile);

        $employees = filled(Arr::get($operations, 'employee_count_range'))
            ? CharacterizationOptions::employeeCountEstimate(Arr::get($operations, 'employee_count_range'))
            : (int) (Arr::get($operations, 'employee_count') ?? 0);

        $revenue = filled(Arr::get($operations, 'revenue_range'))
            ? CharacterizationOptions::revenueEstimate(Arr::get($operations, 'revenue_range'))
            : (float) (Arr::get($operations, 'revenue') ?? 0);

        $payload = [
            'company_name' => Arr::get($companyProfile, 'company_name')
                ?: $characterization->user?->name
                ?: config('services.characterization.defaults.company_name'),
            'sector_list' => $sectorList,
            'headquarters_country' => Arr::get(
                $companyProfile,
                'headquarters_country',
                config('services.characterization.defaults.headquarters_country')
            ),
            'num_subsidiaries_countries' => (int) (Arr::get($companyProfile, 'num_subsidiaries_countries') ?? 0),
            'employees_total' => $employees,
            'annual_turnover_million_euro' => $revenue / 1000000,
            'stock_listed' => filter_var(Arr::get($companyProfile, 'stock_listed', false), FILTER_VALIDATE_BOOLEAN),
            'reporting_currency' => Arr::get(
                $companyProfile,
                'reporting_currency',
                config('services.characterization.defaults.reporting_currency')
            ),
        ];

        $subsidiariesRegions = $this->newFormatRegions(Arr::get($operations, 'regions', []));
        if ($subsidiariesRegions !== []) {
            $payload['subsidiaries_regions'] = $subsidiariesRegions;
        }

        $productsServices = $this->newFormatProducts(
            Arr::get($companyProfile, 'product_service_type'),
            $characterization,
            $modelProfile
        );
        if ($productsServices !== []) {
            $payload['products_services'] = $productsServices;
        }

        $juridicForm = Arr::get($companyProfile, 'juridic_form');
        if (is_string($juridicForm) && filled($juridicForm)) {
            $payload['juridic_form'] = strtoupper($juridicForm);
        }

        if (is_string($modelProfile) && filled($modelProfile)) {
            $payload['model_profile'] = $modelProfile;
        }

        if (ConsentLearningAuthorizationAuthority::enabled()) {
            $payload['learning_shadow_authorized'] = $characterization->user !== null
                && app(ConsentLearningAuthorizationAuthority::class)->permits($characterization->user);
            if ($payload['learning_shadow_authorized']) {
                $payload['learning_shadow_subject'] = $characterization->user->learning_subject;
            }
        }

        return $payload;
    }

    /** Same encoding as serving; no names, LEI, notes or free-form juridic form. */
    public function learningPayload(Characterization $characterization): array
    {
        $copy = clone $characterization;
        $form = $copy->form_data;
        Arr::set($form, 'company_profile.company_name', 'pseudonymous');
        Arr::forget($form, 'company_profile.juridic_form');
        $copy->forceFill(['form_data' => $form]);
        $payload = $this->predictionPayload($copy);
        unset($payload['learning_shadow_authorized'], $payload['learning_shadow_subject']);
        return $payload;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && filled($value) ? $value : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function featureMetadata(mixed $value): array
    {
        $metadata = $value instanceof \stdClass ? get_object_vars($value) : (is_array($value) ? $value : []);

        return [
            'derived_fields' => $this->arrayOrEmpty($metadata['derived_fields'] ?? null),
            'defaulted_fields' => $this->arrayOrEmpty($metadata['defaulted_fields'] ?? null),
            'missing_required_fields' => is_array($metadata['missing_required_fields'] ?? null)
                ? array_values(array_filter(
                    $metadata['missing_required_fields'],
                    fn ($item) => is_string($item) && filled($item)
                ))
                : [],
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_map(
            fn ($item) => ($item instanceof \stdClass || is_array($item)) ? $this->arrayOrEmpty($item) : $item,
            $value
        );
    }

    /**
     * @return list<string>
     */
    private function sectorListForPrediction(Characterization $characterization, mixed $modelProfile = null): array
    {
        if ($this->usesNaceHierarchyModel($modelProfile)) {
            $naceCodes = $this->naceCodesForPrediction($characterization);
            if ($naceCodes !== []) {
                return $naceCodes;
            }
        }

        $naceCode = $characterization->nace_code;

        if (! is_string($naceCode) || $naceCode === '') {
            return [];
        }

        $section = strtoupper(substr($naceCode, 0, 1));

        // CNAE-2025 already uses the training taxonomy. Keep legacy labels only
        // for the legacy model; hierarchy experiments retain their own contract.
        if (is_string($modelProfile) && str_starts_with($modelProfile, 'new_format_732_v1_')) {
            return preg_match('/^[A-V]$/', $section) === 1 ? [$section] : [];
        }

        if (array_key_exists($section, self::PYTHON_SECTOR_LABELS_BY_NACE_SECTION)) {
            return self::PYTHON_SECTOR_LABELS_BY_NACE_SECTION[$section];
        }

        $naceTitle = NaceCode::where('code', $naceCode)->value('title_en');

        return $naceTitle ? [$naceTitle] : [];
    }

    /**
     * @return list<string>
     */
    private function newFormatRegions(mixed $regions): array
    {
        if (! is_array($regions)) {
            return [];
        }

        $regionMapping = $this->featureEncoding()['regions_by_p5_region'];
        $mapped = [];
        foreach ($regions as $region) {
            if (! is_string($region) || blank($region)) {
                continue;
            }

            foreach ($regionMapping[$region] ?? [strtoupper($region)] as $code) {
                $mapped[] = $code;
            }
        }

        return $this->uniqueValues($mapped);
    }

    /**
     * @return list<string>
     */
    private function newFormatProducts(
        mixed $productServiceType,
        Characterization $characterization,
        mixed $modelProfile = null
    ): array
    {
        if ($this->usesNaceHierarchyModel($modelProfile)) {
            $naceCodes = $this->naceCodesForPrediction($characterization);
            if ($naceCodes !== []) {
                return $naceCodes;
            }
        }

        if (! is_string($productServiceType)) {
            return [];
        }

        return $this->featureEncoding()['products_by_p5_type'][$productServiceType] ?? [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidateTopics
     * @param  array<string, mixed>  $industryBasisByKey
     * @return array<int, array<string, mixed>>
     */
    private function withIndustryBasis(array $candidateTopics, array $industryBasisByKey): array
    {
        if ($industryBasisByKey === []) {
            return $candidateTopics;
        }

        return array_map(function (array $topic) use ($industryBasisByKey) {
            $matching = [];
            foreach ($topic['python_esrs_keys'] ?? [] as $key) {
                if (! is_string($key) || ! array_key_exists($key, $industryBasisByKey)) {
                    continue;
                }

                $basis = $this->arrayOrEmpty($industryBasisByKey[$key]);
                if ($basis !== []) {
                    $matching[$key] = $basis;
                }
            }

            if (count($matching) === 1) {
                $topic['industry_basis'] = array_values($matching)[0];
            } elseif (count($matching) > 1) {
                $topic['industry_basis_by_python_key'] = $matching;
            }

            return $topic;
        }, $candidateTopics);
    }

    private function usesNaceHierarchyModel(mixed $modelProfile): bool
    {
        return is_string($modelProfile)
            && (str_contains($modelProfile, '_nace2') || str_contains($modelProfile, '_nace3_hierarchy'));
    }

    /**
     * @return list<string>
     */
    private function naceCodesForPrediction(Characterization $characterization): array
    {
        $naceCode = $characterization->nace_code;

        if (! is_string($naceCode) || $naceCode === '') {
            return [];
        }

        if (preg_match('/^[A-Z](\d{1,2})(?:\.(\d))?/i', $naceCode, $matches) !== 1) {
            return [];
        }

        $division = str_pad((string) (int) $matches[1], 2, '0', STR_PAD_LEFT);

        if (isset($matches[2]) && ctype_digit($matches[2])) {
            return [$division.$matches[2]];
        }

        return [$division];
    }

    private function naceSection(Characterization $characterization): ?string
    {
        $naceCode = $characterization->nace_code;

        if (! is_string($naceCode) || $naceCode === '') {
            return null;
        }

        return strtoupper(substr($naceCode, 0, 1));
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function uniqueValues(array $values): array
    {
        return array_values(array_unique(array_filter(
            $values,
            fn (string $value) => $value !== ''
        )));
    }
}
