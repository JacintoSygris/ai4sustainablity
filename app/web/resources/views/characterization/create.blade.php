<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Characterization Wizard') }}
            </h2>
            <span
                x-data
                x-bind:class="$store.characterization.badgeClass()"
                x-text="`${@js(__('Current status:'))} ${$store.characterization.label()}`"
            ></span>
            <div class="flex items-center gap-2">
                <a href="{{ route('characterization.summary') }}" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm hover:bg-gray-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                    {{ __('View summary') }}
                </a>
                <a href="{{ route('characterization.summary', ['format' => 'pdf']) }}" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('Download PDF') }}
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $characterizationPayload = [
            'status' => $characterization?->status ?? 'draft',
            'submitted_at' => $characterization?->submitted_at?->toIso8601String(),
            'completed_at' => $characterization?->completed_at?->toIso8601String(),
            'last_error' => $characterization?->last_error,
            'result_data' => $characterization?->result_data,
            'retry_count' => $characterization?->retry_count ?? 0,
            'next_retry_at' => $characterization?->next_retry_at?->toIso8601String(),
            'last_job_attempted_at' => $characterization?->last_job_attempted_at?->toIso8601String(),
        ];
        $statusLabels = [
            'draft' => __('Draft'),
            'submitted' => __('Submitted'),
            'waiting' => __('Waiting'),
            'processing' => __('Processing'),
            'completed' => __('Completed'),
            'failed' => __('Failed'),
            'timed_out' => __('Timed out'),
        ];
    @endphp

    <script>
        window.App = Object.assign(window.App || {}, {
            characterization: @json($characterizationPayload),
            statusLabels: @json($statusLabels),
            statusClasses: {
                default: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
                waiting: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-200',
                processing: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-200',
                completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200',
                failed: 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-200',
                timed_out: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-200',
            },
        });
    </script>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100 space-y-8">
                    <div class="flex items-center justify-between">
                        @foreach ($steps as $key => $label)
                            @php
                                $stepNumber = $loop->iteration;
                                $isActive = $key === $step;
                                $isCompleted = array_search($key, array_keys($steps), true) < array_search($step, array_keys($steps), true);
                            @endphp
                            <div class="flex-1 flex items-center">
                                <div class="flex items-center">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full border-2 {{ $isActive ? 'border-indigo-500 bg-indigo-500 text-white' : 'border-gray-300 dark:border-gray-600 ' . ($isCompleted ? 'bg-indigo-500 text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300') }}">
                                        {{ $stepNumber }}
                                    </div>
                                    <span class="ml-3 text-sm font-medium {{ $isActive ? 'text-indigo-600 dark:text-indigo-300' : 'text-gray-600 dark:text-gray-300' }}">{{ $label }}</span>
                                </div>
                                @if (! $loop->last)
                                    <div class="flex-1 h-0.5 mx-4 {{ $isCompleted ? 'bg-indigo-500' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if (session('status'))
                        <x-auth-session-status class="mb-4" :status="session('status')" />
                    @endif

                    <div x-data class="space-y-4">
                        <template x-if="$store.characterization.lastError">
                            <div class="flex items-center gap-3 rounded-md border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-800/60 dark:bg-rose-900/30 dark:text-rose-200">
                                <span aria-hidden="true" class="font-bold">!</span>
                                <span x-text="$store.characterization.lastError"></span>
                            </div>
                        </template>

                        <template x-if="$store.characterization.resultData">
                            <div class="rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-200">
                                <p class="font-semibold">{{ __('Latest result summary') }}</p>
                                <p x-text="$store.characterization.resultData.summary ?? '{{ __('Summary not available') }}'"></p>
                            </div>
                        </template>

                        <div class="flex flex-wrap items-center gap-4 text-sm text-gray-600 dark:text-gray-300">
                            <div>
                                <span class="font-semibold">{{ __('Retries') }}:</span>
                                <span x-text="$store.characterization.retryCount"></span>
                            </div>
                            <div>
                                <span class="font-semibold">{{ __('Next retry at') }}:</span>
                                <span x-text="$store.characterization.nextRetryAt ?? '—'"></span>
                            </div>
                            <div>
                                <span class="font-semibold">{{ __('Last attempted at') }}:</span>
                                <span x-text="$store.characterization.lastJobAttemptedAt ?? '—'"></span>
                            </div>
                        </div>

                        @if ($characterization)
                            <template x-if="['failed', 'timed_out'].includes($store.characterization.status)">
                                <form method="POST" action="{{ route('characterization.retry') }}" class="inline-flex">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white shadow hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                                        {{ __('Retry submission') }}
                                    </button>
                                </form>
                            </template>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('characterization.store') }}" class="space-y-6">
                        @csrf
                        <input type="hidden" name="step" value="{{ $step }}">

                        @if ($step === 'company')
                            <div
                                x-data="NacePicker({
                                    locale: '{{ $locale }}',
                                    selected: @json($selectedNace)
                                })"
                                x-init="init()"
                            >
                                <h3 class="text-lg font-semibold mb-2">{{ __('Company Profile') }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ __('Select the NACE code that best represents your organisation.') }}
                                </p>
                                <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div class="sm:col-span-2">
                                        <label for="company_profile_company_name" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('Company name') }}
                                        </label>
                                        <input type="text" id="company_profile_company_name" name="form_data[company_profile][company_name]" value="{{ old('form_data.company_profile.company_name', $companyData['company_name'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500" />
                                        <x-input-error :messages="$errors->get('form_data.company_profile.company_name')" class="mt-2" />
                                    </div>

                                    <div>
                                        <label for="company_profile_headquarters_country" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('Headquarters country') }}
                                        </label>
                                        <select id="company_profile_headquarters_country" name="form_data[company_profile][headquarters_country]" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                            @foreach ($headquartersCountryOptions as $value => $label)
                                                <option value="{{ $value }}" @selected($value === old('form_data.company_profile.headquarters_country', $companyData['headquarters_country'] ?? 'Spain'))>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('form_data.company_profile.headquarters_country')" class="mt-2" />
                                    </div>

                                    <div>
                                        <label for="company_profile_reporting_year" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('Reporting year') }}
                                        </label>
                                        <input type="number" min="2000" max="{{ now()->year }}" id="company_profile_reporting_year" name="form_data[company_profile][reporting_year]" value="{{ old('form_data.company_profile.reporting_year', $companyData['reporting_year'] ?? '') }}" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500" />
                                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Use the last closed fiscal year.') }}</p>
                                        <x-input-error :messages="$errors->get('form_data.company_profile.reporting_year')" class="mt-2" />
                                    </div>

                                    <div>
                                        <label for="company_profile_reporting_scope" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('Reporting scope') }}
                                        </label>
                                        <select id="company_profile_reporting_scope" name="form_data[company_profile][reporting_scope]" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">{{ __('Select scope') }}</option>
                                            @foreach ($reportingScopeOptions as $value => $label)
                                                <option value="{{ $value }}" @selected($value === old('form_data.company_profile.reporting_scope', $companyData['reporting_scope'] ?? ''))>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('form_data.company_profile.reporting_scope')" class="mt-2" />
                                    </div>

                                    <div>
                                        <label for="company_profile_num_subsidiaries_countries" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('Countries with subsidiaries') }}
                                        </label>
                                        <input type="number" min="0" id="company_profile_num_subsidiaries_countries" name="form_data[company_profile][num_subsidiaries_countries]" value="{{ old('form_data.company_profile.num_subsidiaries_countries', $companyData['num_subsidiaries_countries'] ?? 0) }}" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500" />
                                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Count countries only; value-chain geography is captured later.') }}</p>
                                        <x-input-error :messages="$errors->get('form_data.company_profile.num_subsidiaries_countries')" class="mt-2" />
                                    </div>

                                    <div>
                                        <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('Listed company') }}
                                        </span>
                                        @php
                                            $stockListed = old(
                                                'form_data.company_profile.stock_listed',
                                                ($companyData['stock_listed'] ?? null) === null ? null : (int) $companyData['stock_listed']
                                            );
                                        @endphp
                                        <div class="mt-2 flex gap-4">
                                            <label class="inline-flex items-center gap-2 text-sm">
                                                <input type="radio" name="form_data[company_profile][stock_listed]" value="1" class="border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @checked((string) $stockListed === '1')>
                                                <span>{{ __('Yes') }}</span>
                                            </label>
                                            <label class="inline-flex items-center gap-2 text-sm">
                                                <input type="radio" name="form_data[company_profile][stock_listed]" value="0" class="border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @checked((string) $stockListed === '0')>
                                                <span>{{ __('No') }}</span>
                                            </label>
                                        </div>
                                        <x-input-error :messages="$errors->get('form_data.company_profile.stock_listed')" class="mt-2" />
                                    </div>

                                    <div>
                                        <label for="company_profile_reporting_currency" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('Reporting currency') }}
                                        </label>
                                        <select id="company_profile_reporting_currency" name="form_data[company_profile][reporting_currency]" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                            @foreach ($reportingCurrencyOptions as $value => $label)
                                                <option value="{{ $value }}" @selected($value === old('form_data.company_profile.reporting_currency', $companyData['reporting_currency'] ?? 'EUR'))>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('form_data.company_profile.reporting_currency')" class="mt-2" />
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label for="company_profile_product_service_type" class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                                            {{ __('Product/service type') }}
                                        </label>
                                        <select id="company_profile_product_service_type" name="form_data[company_profile][product_service_type]" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">{{ __('Select product/service type') }}</option>
                                            @foreach ($productServiceTypeOptions as $value => $label)
                                                <option value="{{ $value }}" @selected($value === old('form_data.company_profile.product_service_type', $companyData['product_service_type'] ?? ''))>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('form_data.company_profile.product_service_type')" class="mt-2" />
                                    </div>
                                </div>
                                <label for="nace_code" class="block mt-4 text-sm font-medium">
                                    {{ __('NACE code') }}
                                </label>
                                <div class="relative">
                                    <input type="text" x-model="search" x-on:input="filter()" placeholder="{{ __('Search NACE codes') }}" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500" />
                                    <select id="nace_code" name="nace_code" x-ref="select" x-on:change="updateSelected($event.target.value)" class="mt-3 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="">{{ __('Select a code') }}</option>
                                        <template x-for="option in options" :key="option.code">
                                            <option :value="option.code" x-text="displayLabel(option)" :selected="isSelected(option.code)"></option>
                                        </template>
                                    </select>
                                    <template x-if="meta && meta.current_page < meta.last_page">
                                        <button type="button" class="mt-2 text-sm text-indigo-600 hover:underline" x-on:click="loadMore()" x-bind:disabled="isLoading">
                                            {{ __('Load more codes') }}
                                        </button>
                                    </template>
                                </div>
                                <x-input-error :messages="$errors->get('nace_code')" class="mt-2" />
                            </div>
                        @elseif ($step === 'operations')
                            <div class="space-y-6">
                                <div>
                                    <h3 class="text-lg font-semibold mb-2">{{ __('Operational Footprint') }}</h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ __('Select the regions where your organisation has a significant presence.') }}
                                    </p>
                                    <label for="operations_regions" class="block mt-4 text-sm font-medium">
                                        {{ __('Regions of operation') }}
                                    </label>
                                    <select id="operations_regions" name="form_data[operations][regions][]" multiple size="6" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                        @foreach ($regionOptions as $value => $label)
                                            <option value="{{ $value }}" @selected(in_array($value, old('form_data.operations.regions', $operationsData['regions'] ?? [])))>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Hold Cmd/Ctrl to select multiple options.') }}</p>
                                    <x-input-error :messages="$errors->get('form_data.operations.regions')" class="mt-2" />
                                </div>

                                <div>
                                    <h3 class="text-lg font-semibold mb-2">{{ __('Value Chain Coverage') }}</h3>
                                    <fieldset class="space-y-2">
                                        @foreach ($valueChainOptions as $value => $label)
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="checkbox" name="form_data[operations][value_chain][]" value="{{ $value }}" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" @checked(in_array($value, old('form_data.operations.value_chain', $operationsData['value_chain'] ?? [])))>
                                                <span>{{ $label }}</span>
                                            </label>
                                        @endforeach
                                    </fieldset>
                                    <x-input-error :messages="$errors->get('form_data.operations.value_chain')" class="mt-2" />
                                </div>

                                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200" for="operations_employee_count_range">{{ __('Employees') }}</label>
                                        <select id="operations_employee_count_range" name="form_data[operations][employee_count_range]" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">{{ __('Select employee range') }}</option>
                                            @foreach ($employeeCountRangeOptions as $value => $label)
                                                <option value="{{ $value }}" @selected($value === old('form_data.operations.employee_count_range', $operationsData['employee_count_range'] ?? ''))>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('form_data.operations.employee_count_range')" class="mt-2" />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200" for="operations_revenue_range">{{ __('Annual revenue') }}</label>
                                        <select id="operations_revenue_range" name="form_data[operations][revenue_range]" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                            <option value="">{{ __('Select revenue range') }}</option>
                                            @foreach ($revenueRangeOptions as $value => $label)
                                                <option value="{{ $value }}" @selected($value === old('form_data.operations.revenue_range', $operationsData['revenue_range'] ?? ''))>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <x-input-error :messages="$errors->get('form_data.operations.revenue_range')" class="mt-2" />
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200" for="operations_notes">{{ __('Operational notes') }}</label>
                                    <textarea id="operations_notes" name="form_data[operations][notes]" rows="3" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">{{ old('form_data.operations.notes', $operationsData['notes'] ?? '') }}</textarea>
                                </div>
                            </div>
                        @elseif ($step === 'esg')
                            <div
                                x-data="EsrsPicker({
                                    locale: '{{ $locale }}',
                                    selected: @json($selectedTopics)
                                })"
                                x-init="init()"
                            >
                                <h3 class="text-lg font-semibold mb-2">{{ __('ESRS Topics') }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ __('Choose the sustainability topics that apply to your organisation.') }}
                                </p>
                                <label for="esrs_topics" class="block mt-4 text-sm font-medium">
                                    {{ __('Relevant ESRS subtopics') }}
                                </label>
                                <div class="relative">
                                    <input type="text" x-model="search" x-on:input="filter()" placeholder="{{ __('Search topics') }}" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500" />
                                    <select id="esrs_topics" name="esrs_topic_ids[]" multiple size="10" x-ref="select" x-on:change="selectedIds = Array.from($event.target.selectedOptions).map(option => parseInt(option.value, 10))" class="mt-3 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
                                        <template x-for="group in groups" :key="group.label">
                                            <optgroup :label="group.label">
                                                <template x-for="topic in group.options" :key="topic.id">
                                                    <option :value="topic.id" x-text="topic.subtheme[locale] ?? topic.subtheme.en" :selected="selectedIds.includes(topic.id)"></option>
                                                </template>
                                            </optgroup>
                                        </template>
                                    </select>
                                    <template x-if="hasMore">
                                        <button type="button" class="mt-2 text-sm text-indigo-600 hover:underline" x-on:click="loadMore()" x-bind:disabled="isLoading">
                                            {{ __('Load more topics') }}
                                        </button>
                                    </template>
                                </div>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Hold Cmd/Ctrl to select multiple options.') }}
                                </p>
                                <x-input-error :messages="$errors->get('esrs_topic_ids')" class="mt-2" />
                            </div>
                        @elseif ($step === 'review')
                            <div class="space-y-6">
                                <div>
                                    <h3 class="text-lg font-semibold mb-2">{{ __('Review selections') }}</h3>
                                    <dl class="divide-y divide-gray-200 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-700">
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Company name') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                {{ old('form_data.company_profile.company_name', $companyData['company_name'] ?? __('Not provided')) }}
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Headquarters country') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php $selectedHeadquartersCountry = old('form_data.company_profile.headquarters_country', $companyData['headquarters_country'] ?? null); @endphp
                                                {{ $headquartersCountryOptions[$selectedHeadquartersCountry] ?? __('Not provided') }}
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Reporting year') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                {{ old('form_data.company_profile.reporting_year', $companyData['reporting_year'] ?? __('Not provided')) }}
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Reporting scope') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php $selectedReportingScope = old('form_data.company_profile.reporting_scope', $companyData['reporting_scope'] ?? null); @endphp
                                                {{ $reportingScopeOptions[$selectedReportingScope] ?? __('Not provided') }}
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Countries with subsidiaries') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                {{ old('form_data.company_profile.num_subsidiaries_countries', $companyData['num_subsidiaries_countries'] ?? __('Not provided')) }}
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Listed company') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php $reviewStockListed = old('form_data.company_profile.stock_listed', $companyData['stock_listed'] ?? null); @endphp
                                                @if ($reviewStockListed === null || $reviewStockListed === '')
                                                    {{ __('Not provided') }}
                                                @else
                                                    {{ filter_var($reviewStockListed, FILTER_VALIDATE_BOOLEAN) ? __('Yes') : __('No') }}
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Reporting currency') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php $selectedReportingCurrency = old('form_data.company_profile.reporting_currency', $companyData['reporting_currency'] ?? null); @endphp
                                                {{ $reportingCurrencyOptions[$selectedReportingCurrency] ?? __('Not provided') }}
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Product/service type') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php $selectedProductServiceType = old('form_data.company_profile.product_service_type', $companyData['product_service_type'] ?? null); @endphp
                                                {{ $productServiceTypeOptions[$selectedProductServiceType] ?? __('Not provided') }}
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('NACE code') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @if ($selectedNace)
                                                    {{ $selectedNace['code'] }} · {{ $selectedNace['title'][$locale] ?? $selectedNace['title']['en'] }}
                                                @else
                                                    <span class="text-gray-400">{{ __('Not provided') }}</span>
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Selected ESRS topics') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @if (count($selectedTopics))
                                                    <ul class="list-disc space-y-1 pl-4">
                                                        @foreach ($selectedTopics as $topic)
                                                            <li>
                                                                {{ $topic['esrs_code'] }} · {{ $topic['subtheme'][$locale] ?? $topic['subtheme']['en'] }}
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <span class="text-gray-400">{{ __('Not provided') }}</span>
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Regions of operation') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php
                                                    $selectedRegions = old('form_data.operations.regions', $operationsData['regions'] ?? []);
                                                @endphp
                                                @if (count($selectedRegions))
                                                    <ul class="list-disc space-y-1 pl-4">
                                                        @foreach ($selectedRegions as $region)
                                                            <li>{{ $regionOptions[$region] ?? $region }}</li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <span class="text-gray-400">{{ __('Not provided') }}</span>
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Value chain stages') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php
                                                    $selectedValueChain = old('form_data.operations.value_chain', $operationsData['value_chain'] ?? []);
                                                @endphp
                                                @if (count($selectedValueChain))
                                                    <ul class="list-disc space-y-1 pl-4">
                                                        @foreach ($selectedValueChain as $stage)
                                                            <li>{{ $valueChainOptions[$stage] ?? $stage }}</li>
                                                        @endforeach
                                                    </ul>
                                                @else
                                                    <span class="text-gray-400">{{ __('Not provided') }}</span>
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Employees') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php $selectedEmployeeCountRange = old('form_data.operations.employee_count_range', $operationsData['employee_count_range'] ?? null); @endphp
                                                {{ $employeeCountRangeOptions[$selectedEmployeeCountRange] ?? old('form_data.operations.employee_count', $operationsData['employee_count'] ?? __('Not provided')) }}
                                            </dd>
                                        </div>
                                        <div class="px-4 py-3 grid grid-cols-3 gap-4">
                                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Annual revenue') }}</dt>
                                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 col-span-2">
                                                @php $selectedRevenueRange = old('form_data.operations.revenue_range', $operationsData['revenue_range'] ?? null); @endphp
                                                {{ $revenueRangeOptions[$selectedRevenueRange] ?? old('form_data.operations.revenue', $operationsData['revenue'] ?? __('Not provided')) }}
                                            </dd>
                                        </div>
                                    </dl>
                                </div>

                                <input type="hidden" name="form_data[company_profile][company_name]" value="{{ old('form_data.company_profile.company_name', $companyData['company_name'] ?? '') }}">
                                <input type="hidden" name="form_data[company_profile][headquarters_country]" value="{{ old('form_data.company_profile.headquarters_country', $companyData['headquarters_country'] ?? '') }}">
                                <input type="hidden" name="form_data[company_profile][reporting_year]" value="{{ old('form_data.company_profile.reporting_year', $companyData['reporting_year'] ?? '') }}">
                                <input type="hidden" name="form_data[company_profile][reporting_scope]" value="{{ old('form_data.company_profile.reporting_scope', $companyData['reporting_scope'] ?? '') }}">
                                <input type="hidden" name="form_data[company_profile][num_subsidiaries_countries]" value="{{ old('form_data.company_profile.num_subsidiaries_countries', $companyData['num_subsidiaries_countries'] ?? '') }}">
                                <input type="hidden" name="form_data[company_profile][stock_listed]" value="{{ old('form_data.company_profile.stock_listed', ($companyData['stock_listed'] ?? null) === null ? '' : (int) $companyData['stock_listed']) }}">
                                <input type="hidden" name="form_data[company_profile][reporting_currency]" value="{{ old('form_data.company_profile.reporting_currency', $companyData['reporting_currency'] ?? '') }}">
                                <input type="hidden" name="form_data[company_profile][product_service_type]" value="{{ old('form_data.company_profile.product_service_type', $companyData['product_service_type'] ?? '') }}">
                                <input type="hidden" name="nace_code" value="{{ $selectedNace['code'] ?? '' }}">
                                @foreach (old('form_data.operations.regions', $operationsData['regions'] ?? []) as $region)
                                    <input type="hidden" name="form_data[operations][regions][]" value="{{ $region }}">
                                @endforeach
                                @foreach (old('form_data.operations.value_chain', $operationsData['value_chain'] ?? []) as $stage)
                                    <input type="hidden" name="form_data[operations][value_chain][]" value="{{ $stage }}">
                                @endforeach
                                <input type="hidden" name="form_data[operations][employee_count_range]" value="{{ old('form_data.operations.employee_count_range', $operationsData['employee_count_range'] ?? '') }}">
                                <input type="hidden" name="form_data[operations][revenue_range]" value="{{ old('form_data.operations.revenue_range', $operationsData['revenue_range'] ?? '') }}">
                                <input type="hidden" name="form_data[operations][employee_count]" value="{{ old('form_data.operations.employee_count', $operationsData['employee_count'] ?? '') }}">
                                <input type="hidden" name="form_data[operations][revenue]" value="{{ old('form_data.operations.revenue', $operationsData['revenue'] ?? '') }}">
                                <input type="hidden" name="form_data[operations][notes]" value="{{ old('form_data.operations.notes', $operationsData['notes'] ?? '') }}">
                                @foreach ($selectedTopics as $topic)
                                    <input type="hidden" name="esrs_topic_ids[]" value="{{ $topic['id'] }}">
                                @endforeach

                                <div>
                                    <h3 class="text-lg font-semibold mb-2">{{ __('Additional Notes') }}</h3>
                                    <textarea name="form_data[notes]" rows="4" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:border-indigo-500 focus:ring-indigo-500">{{ old('form_data.notes', $characterization?->form_data['notes'] ?? '') }}</textarea>
                                    <x-input-error :messages="$errors->get('form_data.notes')" class="mt-2" />
                                </div>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="submit" name="action" value="save_draft" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                                {{ __('Save Draft') }}
                            </button>

                            @if ($previousStep)
                                <button type="submit" name="action" value="prev" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-200 uppercase tracking-widest hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                                    {{ __('Previous') }}
                                </button>
                            @endif

                            @if ($step !== 'review')
                                <button type="submit" name="action" value="next" class="inline-flex items-center px-4 py-2 bg-emerald-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-700 focus:bg-emerald-700 active:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                                    {{ __('Next') }}
                                </button>
                            @else
                                <button type="submit" name="action" value="submit" class="inline-flex items-center px-4 py-2 bg-emerald-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-emerald-700 focus:bg-emerald-700 active:bg-emerald-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                                    {{ __('Submit for processing') }}
                                </button>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
