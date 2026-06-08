<?php

namespace App\Services;

use App\Models\Characterization;
use App\Models\NaceCode;
use App\Services\Contracts\CharacterizationGateway;
use App\Support\CharacterizationOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
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

        $response = $client->post("{$baseUrl}/predict", $payload);

        if ($response->failed()) {
            throw new RuntimeException('External characterization API responded with an error: '.$response->body());
        }

        $rawPrediction = $response->json('esrs', []);
        $rawPrediction = is_array($rawPrediction) ? $rawPrediction : [];
        $candidateTopics = $this->mapper->candidateTopics($rawPrediction);
        $reviewRequiredKeys = $this->mapper->reviewRequiredKeys($rawPrediction);
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
        ];
    }

    private function counted(int $count, string $singular): string
    {
        return $count.' '.$singular.($count === 1 ? '' : 's');
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

        return [
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
}
