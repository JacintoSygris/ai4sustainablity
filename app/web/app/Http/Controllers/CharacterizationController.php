<?php

namespace App\Http\Controllers;

use App\Http\Resources\EsrsTopicResource;
use App\Http\Resources\NaceCodeResource;
use App\Jobs\SubmitCharacterizationJob;
use App\Models\Characterization;
use App\Repositories\EsrsTopicRepository;
use App\Repositories\NaceCodeRepository;
use App\Support\CharacterizationOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;

class CharacterizationController extends Controller
{
    public function create()
    {
        return redirect('/wizard/step-1');
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
