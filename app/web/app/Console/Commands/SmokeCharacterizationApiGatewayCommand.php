<?php

namespace App\Console\Commands;

use App\Models\Characterization;
use App\Services\ApiCharacterizationGateway;
use Illuminate\Console\Command;
use Throwable;

class SmokeCharacterizationApiGatewayCommand extends Command
{
    protected $signature = 'characterization:smoke-api-gateway
        {--base-url= : Override the configured Python API base URL for this process only}
        {--timeout= : Override the configured Python API timeout in seconds for this process only}';

    protected $description = 'Run a non-persistent smoke check against the Python characterization API gateway.';

    public function handle(ApiCharacterizationGateway $gateway): int
    {
        $baseUrl = trim((string) ($this->option('base-url') ?? ''));

        if ($baseUrl !== '') {
            config(['services.characterization.api.base_url' => $baseUrl]);
        }

        $timeout = $this->option('timeout');

        if ($timeout !== null && $timeout !== '') {
            config(['services.characterization.api.timeout' => (int) $timeout]);
        }

        if (blank(config('services.characterization.api.base_url'))) {
            $this->error('missing_base_url=true');

            return self::FAILURE;
        }

        try {
            $result = $gateway->submit($this->sampleCharacterization());
        } catch (Throwable $exception) {
            $this->error('smoke_failed='.str_replace(["\r", "\n"], ' ', $exception->getMessage()));

            return self::FAILURE;
        }

        $reviewRequiredKeys = data_get($result, 'review_required_prediction_keys', []);
        $reviewRequiredKeys = is_array($reviewRequiredKeys) ? $reviewRequiredKeys : [];
        $rawPrediction = data_get($result, 'raw_prediction', []);
        $rawPrediction = is_array($rawPrediction) ? $rawPrediction : [];
        $candidateTopics = data_get($result, 'candidate_topics', []);
        $candidateTopics = is_array($candidateTopics) ? $candidateTopics : [];

        $metrics = [
            'status' => (string) data_get($result, 'status', 'unknown'),
            'raw_prediction_count' => count($rawPrediction),
            'candidate_count' => count($candidateTopics),
            'review_required_count' => count($reviewRequiredKeys),
            'review_required_has_e3_other' => in_array('esrs_e3_other', $reviewRequiredKeys, true),
            'payload_employees_total' => data_get($result, 'request_payload.employees_total'),
            'payload_turnover_million_euro' => data_get($result, 'request_payload.annual_turnover_million_euro'),
            'summary' => (string) data_get($result, 'summary', ''),
        ];

        foreach ($metrics as $key => $value) {
            $this->line($key.'='.$this->formatMetricValue($value));
        }

        return $metrics['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    private function sampleCharacterization(): Characterization
    {
        return new Characterization([
            'status' => Characterization::STATUS_SUBMITTED,
            'nace_code' => 'A',
            'form_data' => [
                'company_profile' => [
                    'company_name' => 'I4S API Smoke',
                    'headquarters_country' => 'Spain',
                    'num_subsidiaries_countries' => 0,
                    'stock_listed' => false,
                    'reporting_currency' => 'EUR',
                ],
                'operations' => [
                    'employee_count_range' => '50_249',
                    'revenue_range' => '2m_to_10m',
                ],
            ],
            'submitted_at' => now(),
        ]);
    }

    private function formatMetricValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }
}
