<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\NaceCode;
use App\Services\Contracts\CharacterizationGateway;
use App\Support\CharacterizationOptions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class ApiCharacterizationGateway implements CharacterizationGateway
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

    private const NEW_FORMAT_REGIONS_BY_P5_REGION = [
        'eu' => ['EU'],
        'north_america' => ['NA'],
        'latin_america' => ['LATAM'],
        'asia' => ['APAC'],
        'middle_east_africa' => ['MENA', 'SSA'],
        'oceania' => ['APAC'],
    ];

    private const NEW_FORMAT_PRODUCTS_BY_PRODUCT_SERVICE_TYPE = [
        'physical_product_manufacturing' => ['C'],
        'physical_product_retail' => ['G'],
        'professional_services' => ['M'],
        'technical_services' => ['F', 'M'],
        'software_digital_services' => ['J'],
        'construction_installations' => ['F'],
        'logistics_transport_storage' => ['H'],
        'finance_insurance' => ['K'],
        'energy_utilities' => ['D'],
        'agrifood' => ['A', 'C'],
        'health_life_sciences' => ['R'],
        'education_training' => ['P'],
        'hospitality_tourism' => ['I'],
    ];

    public function __construct(private readonly CharacterizationPredictionMapper $mapper) {}

    public function submit(Characterization $characterization): array
    {
        $baseUrl = rtrim(config('services.characterization.api.base_url'), '/');
        $token = config('services.characterization.api.token');

        if (blank($baseUrl)) {
            throw new RuntimeException('Characterization API base URL is not configured.');
        }

        $payload = $this->predictionPayload($characterization);

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
            throw new RuntimeException(
                'External characterization API responded with an error (HTTP '.$response->status().'): '
                .$this->responseFailureDetail($response)
            );
        }

        $predictionEnvelope = $this->predictionEnvelope($response);
        $rawPrediction = $predictionEnvelope['esrs'];
        $candidateTopics = $this->mapper->candidateTopics($rawPrediction);
        $reviewRequiredKeys = $this->mapper->reviewRequiredKeys($rawPrediction);
        $laravelMappingMetadata = $this->mapper->mappingMetadata();
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
                'python' => $this->arrayOrEmpty($predictionEnvelope['mapping_metadata'] ?? null),
                'laravel' => $laravelMappingMetadata,
            ],
            'evidence_refs' => $this->arrayOrEmpty($predictionEnvelope['evidence_refs'] ?? null),
        ];
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

        $sectorList = $this->sectorListForPrediction($characterization);

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

        $productsServices = $this->newFormatProducts(Arr::get($companyProfile, 'product_service_type'), $characterization);
        if ($productsServices !== []) {
            $payload['products_services'] = $productsServices;
        }

        $juridicForm = Arr::get($companyProfile, 'juridic_form');
        if (is_string($juridicForm) && filled($juridicForm)) {
            $payload['juridic_form'] = strtoupper($juridicForm);
        }

        $modelProfile = config('services.characterization.api.model_profile');

        if (is_string($modelProfile) && filled($modelProfile)) {
            $payload['model_profile'] = $modelProfile;
        }

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
            return get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private function sectorListForPrediction(Characterization $characterization): array
    {
        $naceCode = $characterization->nace_code;

        if (! is_string($naceCode) || $naceCode === '') {
            return [];
        }

        $section = strtoupper(substr($naceCode, 0, 1));

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

        $mapped = [];
        foreach ($regions as $region) {
            if (! is_string($region) || blank($region)) {
                continue;
            }

            foreach (self::NEW_FORMAT_REGIONS_BY_P5_REGION[$region] ?? [strtoupper($region)] as $code) {
                $mapped[] = $code;
            }
        }

        return $this->uniqueValues($mapped);
    }

    /**
     * @return list<string>
     */
    private function newFormatProducts(mixed $productServiceType, Characterization $characterization): array
    {
        $mapped = [];

        if (is_string($productServiceType) && filled($productServiceType)) {
            $mapped = self::NEW_FORMAT_PRODUCTS_BY_PRODUCT_SERVICE_TYPE[$productServiceType] ?? [];
        }

        if ($mapped === []) {
            $section = $this->naceSection($characterization);
            $mapped = $section !== null ? [$section] : [];
        }

        return $this->uniqueValues($mapped);
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
