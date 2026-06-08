<?php

namespace App\Http\Requests;

use App\Models\Characterization;
use App\Support\CharacterizationOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CharacterizationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $action = $this->routeIs('api.characterization.submit')
            ? 'submit'
            : $this->input('action', 'save_draft');

        $this->merge([
            'step' => $this->input('step', $action === 'submit' ? 'review' : 'company'),
            'action' => $action,
        ]);

        $status = $action === 'submit'
            ? Characterization::STATUS_SUBMITTED
            : Characterization::STATUS_DRAFT;

        $normalized = [
            'status' => $status,
        ];

        if ($this->has('esrs_topic_ids')) {
            $normalized['esrs_topic_ids'] = collect($this->input('esrs_topic_ids', []))
                ->filter()
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values()
                ->all();
        }

        $this->merge($normalized);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $step = $this->input('step', 'company');
        $action = $this->input('action', 'save_draft');
        $isApiSubmit = $this->routeIs('api.characterization.submit');

        $rules = [
            'step' => ['required', Rule::in(['company', 'operations', 'esg', 'review'])],
            'action' => ['nullable', Rule::in(['save_draft', 'next', 'prev', 'submit'])],
            'status' => ['nullable', Rule::in([Characterization::STATUS_DRAFT, Characterization::STATUS_SUBMITTED])],
            'form_data' => ['nullable', 'array'],
            'form_data.notes' => ['nullable', 'string', 'max:2000'],
        ];

        $requiresNace = (
            $step === 'company' && in_array($action, ['next', 'submit'], true)
        ) || (
            $step === 'review' && $action === 'submit'
        );

        $requiresOperations = (
            $step === 'operations' && in_array($action, ['next', 'submit'], true)
        ) || (
            $step === 'review' && $action === 'submit'
        );

        $requiresTopics = (
            $step === 'esg' && in_array($action, ['next', 'submit'], true)
        ) || (
            $step === 'review' && $action === 'submit'
        );

        if ($isApiSubmit) {
            $requiresTopics = false;
        }

        $requiresCompanyProfile = (
            $step === 'company' && in_array($action, ['next', 'submit'], true)
        ) || (
            $step === 'review' && $action === 'submit'
        );

        if ($action === 'prev') {
            $requiresNace = false;
            $requiresOperations = false;
            $requiresTopics = false;
            $requiresCompanyProfile = false;
        }

        $rules['nace_code'] = [$requiresNace ? 'required' : 'nullable', 'string', 'max:50', 'exists:nace_codes,code'];
        $rules['esrs_topic_ids'] = $this->multiSelectRules($requiresTopics);
        $rules['esrs_topic_ids.*'] = ['integer', 'exists:esrs_topics,id'];

        $companyRule = $requiresCompanyProfile ? 'required' : 'nullable';
        $rules['form_data.company_profile.company_name'] = [$companyRule, 'string', 'max:255'];
        $rules['form_data.company_profile.headquarters_country'] = [$companyRule, 'string', Rule::in(array_keys($this->headquartersCountryOptions()))];
        $rules['form_data.company_profile.reporting_year'] = [$companyRule, 'integer', 'digits:4', 'min:2000', 'max:'.now()->year];
        $rules['form_data.company_profile.reporting_scope'] = [$companyRule, 'string', Rule::in(array_keys($this->reportingScopeOptions()))];
        $rules['form_data.company_profile.num_subsidiaries_countries'] = [$companyRule, 'integer', 'min:0', 'max:500'];
        $rules['form_data.company_profile.stock_listed'] = [$companyRule, 'boolean'];
        $rules['form_data.company_profile.reporting_currency'] = [$companyRule, 'string', Rule::in(array_keys($this->reportingCurrencyOptions()))];
        $rules['form_data.company_profile.product_service_type'] = [$companyRule, 'string', Rule::in(array_keys($this->productServiceTypeOptions()))];

        $regionRule = $requiresOperations ? 'required' : 'sometimes';
        $valueChainRule = $requiresOperations ? 'required' : 'sometimes';
        $employeeRangeRule = $requiresOperations ? 'required' : 'sometimes';
        $revenueRangeRule = $requiresOperations ? 'required' : 'sometimes';

        $rules['form_data.operations.regions'] = $this->multiSelectRules($requiresOperations, $regionRule);
        $rules['form_data.operations.regions.*'] = ['string', Rule::in(array_keys($this->regionOptions()))];
        $rules['form_data.operations.value_chain'] = $this->multiSelectRules($requiresOperations, $valueChainRule);
        $rules['form_data.operations.value_chain.*'] = ['string', Rule::in(array_keys($this->valueChainOptions()))];
        $rules['form_data.operations.employee_count_range'] = [$employeeRangeRule, 'string', Rule::in(array_keys($this->employeeCountRangeOptions()))];
        $rules['form_data.operations.revenue_range'] = [$revenueRangeRule, 'string', Rule::in(array_keys($this->revenueRangeOptions()))];
        $rules['form_data.operations.employee_count'] = ['sometimes', 'nullable', 'integer', 'min:1'];
        $rules['form_data.operations.revenue'] = ['sometimes', 'nullable', 'numeric', 'min:0'];
        $rules['form_data.operations.notes'] = ['sometimes', 'nullable', 'string', 'max:2000'];

        $yesNoUnknownKeys = array_keys($this->yesNoUnknownOptions());
        $rules['form_data.csrd_orientation'] = ['sometimes', 'array'];
        $rules['form_data.csrd_orientation.enabled'] = ['sometimes', 'boolean'];
        $rules['form_data.csrd_orientation.listed_entity'] = ['sometimes', 'nullable', 'string', Rule::in($yesNoUnknownKeys)];
        $rules['form_data.csrd_orientation.public_interest_entity'] = ['sometimes', 'nullable', 'string', Rule::in($yesNoUnknownKeys)];
        $rules['form_data.csrd_orientation.reports_as_group'] = ['sometimes', 'nullable', 'string', Rule::in($yesNoUnknownKeys)];

        $rules['form_data.data_readiness'] = ['sometimes', 'array'];
        $rules['form_data.data_readiness.enabled'] = ['sometimes', 'boolean'];
        $rules['form_data.data_readiness.items'] = ['sometimes', 'array'];
        $rules['form_data.data_readiness.items.*'] = ['array'];
        $rules['form_data.data_readiness.items.*.available'] = ['sometimes', 'nullable', 'string', Rule::in($yesNoUnknownKeys)];
        $rules['form_data.data_readiness.items.*.year'] = ['sometimes', 'nullable', 'integer', 'digits:4', 'min:2000', 'max:'.now()->year];
        $rules['form_data.data_readiness.items.*.source'] = ['sometimes', 'nullable', 'string', Rule::in(array_keys($this->dataReadinessSourceOptions()))];
        $rules['form_data.data_readiness.items.*.verified'] = ['sometimes', 'nullable', 'boolean'];
        $rules['form_data.data_readiness.items.*.traceability'] = ['sometimes', 'nullable', 'string', Rule::in(array_keys($this->traceabilityLevelOptions()))];

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $items = $this->input('form_data.data_readiness.items', []);

            if (! is_array($items)) {
                return;
            }

            $allowedItems = array_keys($this->dataReadinessItemOptions());

            foreach (array_keys($items) as $itemKey) {
                if (! in_array($itemKey, $allowedItems, true)) {
                    $validator->errors()->add(
                        "form_data.data_readiness.items.{$itemKey}",
                        'The selected data readiness item is invalid.'
                    );
                }
            }
        });
    }

    protected function regionOptions(): array
    {
        return CharacterizationOptions::regions();
    }

    protected function multiSelectRules(bool $requiresNonEmpty, ?string $presenceRule = null): array
    {
        $rules = [$presenceRule ?? ($requiresNonEmpty ? 'required' : 'sometimes'), 'array'];

        if ($requiresNonEmpty) {
            $rules[] = 'min:1';
        }

        return $rules;
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
        return CharacterizationOptions::reportingCurrencies();
    }

    protected function employeeCountRangeOptions(): array
    {
        return CharacterizationOptions::employeeCountRanges();
    }

    protected function revenueRangeOptions(): array
    {
        return CharacterizationOptions::revenueRanges();
    }

    protected function productServiceTypeOptions(): array
    {
        return CharacterizationOptions::productServiceTypes();
    }

    protected function valueChainOptions(): array
    {
        return CharacterizationOptions::valueChainPositions();
    }

    protected function yesNoUnknownOptions(): array
    {
        return CharacterizationOptions::yesNoUnknown();
    }

    protected function dataReadinessItemOptions(): array
    {
        return CharacterizationOptions::dataReadinessItems();
    }

    protected function dataReadinessSourceOptions(): array
    {
        return CharacterizationOptions::dataReadinessSources();
    }

    protected function traceabilityLevelOptions(): array
    {
        return CharacterizationOptions::traceabilityLevels();
    }
}
