<?php

namespace App\Http\Controllers;

use App\Http\Requests\CharacterizationRequest;
use App\Http\Resources\EsrsTopicResource;
use App\Http\Resources\NaceCodeResource;
use App\Jobs\SubmitCharacterizationJob;
use App\Models\Characterization;
use App\Repositories\EsrsTopicRepository;
use App\Repositories\NaceCodeRepository;
use App\Services\CharacterizationRecorder;
use App\Support\CharacterizationOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;

class CharacterizationController extends Controller
{
    public function create(
        Request $request,
        NaceCodeRepository $naceCodeRepository,
        EsrsTopicRepository $esrsTopicRepository,
    ) {
        $locale = $request->user()?->preferred_locale ?? app()->getLocale();
        $steps = $this->steps();
        $stepKeys = array_keys($steps);

        $requestedStep = $request->query('step', 'company');
        $step = array_key_exists($requestedStep, $steps) ? $requestedStep : 'company';

        $index = array_search($step, $stepKeys, true) ?: 0;
        $previousStep = $index > 0 ? $stepKeys[$index - 1] : null;
        $nextStep = $index < count($stepKeys) - 1 ? $stepKeys[$index + 1] : null;

        $characterization = $request->user()
            ? Characterization::forUser($request->user()->id)->first()
            : null;

        $selectedNaceCode = old(
            'nace_code',
            Arr::get($characterization?->form_data, 'company_profile.nace_code', $characterization?->nace_code)
        );

        $selectedTopicsIds = collect(old(
            'esrs_topic_ids',
            Arr::get($characterization?->form_data, 'esg_focus.topic_ids', $characterization?->esrs_topic_ids ?? [])
        ))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();

        $selectedNaceModel = $naceCodeRepository->findByCode($selectedNaceCode);
        $selectedNace = $selectedNaceModel
            ? NaceCodeResource::make($selectedNaceModel)->resolve()
            : null;

        $selectedTopics = EsrsTopicResource::collection(
            $esrsTopicRepository->findByIds($selectedTopicsIds)
        )->resolve();

        $companyData = Arr::get($characterization?->form_data, 'company_profile', []);
        $companyData = array_merge([
            'company_name' => $companyData['company_name'] ?? null,
            'headquarters_country' => $companyData['headquarters_country'] ?? 'Spain',
            'reporting_year' => $companyData['reporting_year'] ?? null,
            'reporting_scope' => $companyData['reporting_scope'] ?? null,
            'num_subsidiaries_countries' => $companyData['num_subsidiaries_countries'] ?? 0,
            'stock_listed' => $companyData['stock_listed'] ?? null,
            'reporting_currency' => $companyData['reporting_currency'] ?? 'EUR',
            'product_service_type' => $companyData['product_service_type'] ?? null,
        ], $companyData);

        $operationsData = Arr::get($characterization?->form_data, 'operations', []);
        $operationsData = array_merge([
            'regions' => $operationsData['regions'] ?? [],
            'value_chain' => $operationsData['value_chain'] ?? [],
            'employee_count_range' => $operationsData['employee_count_range'] ?? null,
            'revenue_range' => $operationsData['revenue_range'] ?? null,
            'employee_count' => $operationsData['employee_count'] ?? null,
            'revenue' => $operationsData['revenue'] ?? null,
            'notes' => $operationsData['notes'] ?? null,
        ], $operationsData);

        return view('characterization.create', [
            'locale' => $locale,
            'characterization' => $characterization,
            'selectedNace' => $selectedNace,
            'selectedTopics' => $selectedTopics,
            'steps' => $steps,
            'step' => $step,
            'previousStep' => $previousStep,
            'nextStep' => $nextStep,
            'regionOptions' => $this->regionOptions(),
            'valueChainOptions' => $this->valueChainOptions(),
            'employeeCountRangeOptions' => $this->employeeCountRangeOptions(),
            'revenueRangeOptions' => $this->revenueRangeOptions(),
            'headquartersCountryOptions' => $this->headquartersCountryOptions(),
            'reportingScopeOptions' => $this->reportingScopeOptions(),
            'reportingCurrencyOptions' => $this->reportingCurrencyOptions(),
            'productServiceTypeOptions' => $this->productServiceTypeOptions(),
            'companyData' => $companyData,
            'operationsData' => $operationsData,
        ]);
    }

    public function store(CharacterizationRequest $request, CharacterizationRecorder $recorder)
    {
        $status = $request->input('status', Characterization::STATUS_DRAFT);
        $step = $request->input('step', 'company');
        $action = $request->input('action', 'save_draft');

        $recorder->save($request->user(), $request->validated());

        $stepKeys = array_keys($this->steps());
        $index = array_search($step, $stepKeys, true) ?: 0;
        $previousStep = $index > 0 ? $stepKeys[$index - 1] : null;
        $nextStep = $index < count($stepKeys) - 1 ? $stepKeys[$index + 1] : null;

        if ($action === 'prev' && $previousStep) {
            return redirect()
                ->route('characterization.create', ['step' => $previousStep])
                ->with('status', __('Draft saved successfully.'));
        }

        if ($action === 'next' && $nextStep) {
            return redirect()
                ->route('characterization.create', ['step' => $nextStep])
                ->with('status', __('Draft saved successfully.'));
        }

        $message = $status === Characterization::STATUS_SUBMITTED
            ? __('Characterization submitted successfully.')
            : __('Draft saved successfully.');

        return redirect()
            ->route('characterization.create', ['step' => $step])
            ->with('status', $message);
    }

    public function retry(Request $request)
    {
        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();

        if (! in_array($characterization->status, [Characterization::STATUS_FAILED, Characterization::STATUS_TIMED_OUT], true)) {
            return redirect()
                ->route('characterization.create')
                ->with('status', __('Retry is only available for failed or timed-out submissions.'));
        }

        $characterization->updateStatus(Characterization::STATUS_SUBMITTED, [
            'submitted_at' => now(),
            'retry_count' => 0,
            'next_retry_at' => null,
            'last_job_attempted_at' => null,
            'last_error' => null,
            'completed_at' => null,
            'result_data' => null,
        ]);

        Bus::dispatch(new SubmitCharacterizationJob($characterization));

        return redirect()
            ->route('characterization.create')
            ->with('status', __('Characterization submission retried.'));
    }

    public function summary(Request $request)
    {
        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();
        $format = $request->query('format', 'html');

        $data = $this->buildSummaryData($characterization);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('characterization.summary', $data);

            return $pdf->download('characterization-summary.pdf');
        }

        return view('characterization.summary', $data);
    }

    protected function steps(): array
    {
        return [
            'company' => __('Company Profile'),
            'operations' => __('Operations Overview'),
            'esg' => __('ESRS Topics'),
            'review' => __('Review & Submit'),
        ];
    }

    protected function regionOptions(): array
    {
        return CharacterizationOptions::regions();
    }

    protected function valueChainOptions(): array
    {
        return CharacterizationOptions::valueChainPositions();
    }

    protected function employeeCountRangeOptions(): array
    {
        return array_map(
            fn (string $label): string => __($label),
            CharacterizationOptions::employeeCountRanges()
        );
    }

    protected function revenueRangeOptions(): array
    {
        return array_map(
            fn (string $label): string => __($label),
            CharacterizationOptions::revenueRanges()
        );
    }

    protected function headquartersCountryOptions(): array
    {
        return CharacterizationOptions::headquartersCountries();
    }

    protected function reportingScopeOptions(): array
    {
        return CharacterizationOptions::reportingScopes();
    }

    protected function reportingCurrencyOptions(): array
    {
        return [
            'EUR' => __('EUR'),
            'GBP' => __('GBP'),
            'USD' => __('USD'),
        ];
    }

    protected function productServiceTypeOptions(): array
    {
        return array_map(
            fn (string $label): string => __($label),
            CharacterizationOptions::productServiceTypes()
        );
    }

    protected function buildSummaryData(Characterization $characterization): array
    {
        $locale = app()->getLocale();
        $formData = $characterization->form_data ?? [];

        $regionOptions = $this->regionOptions();
        $valueChainOptions = $this->valueChainOptions();

        $selectedTopics = EsrsTopicResource::collection(
            app(EsrsTopicRepository::class)->findByIds($characterization->esrs_topic_ids ?? [])
        )->resolve();

        $selectedNaceModel = $characterization->nace_code
            ? app(NaceCodeRepository::class)->findByCode($characterization->nace_code)
            : null;

        $selectedNace = $selectedNaceModel
            ? NaceCodeResource::make($selectedNaceModel)->resolve()
            : null;

        return [
            'characterization' => $characterization,
            'locale' => $locale,
            'formData' => $formData,
            'selectedTopics' => $selectedTopics,
            'selectedNace' => $selectedNace,
            'regionOptions' => $regionOptions,
            'valueChainOptions' => $valueChainOptions,
            'employeeCountRangeOptions' => $this->employeeCountRangeOptions(),
            'revenueRangeOptions' => $this->revenueRangeOptions(),
            'headquartersCountryOptions' => $this->headquartersCountryOptions(),
            'reportingScopeOptions' => $this->reportingScopeOptions(),
            'reportingCurrencyOptions' => $this->reportingCurrencyOptions(),
            'productServiceTypeOptions' => $this->productServiceTypeOptions(),
        ];
    }
}
