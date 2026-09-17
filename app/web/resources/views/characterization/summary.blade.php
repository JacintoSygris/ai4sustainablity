<!DOCTYPE html>
@php use Illuminate\Support\Arr; @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Characterization Summary') }}</title>
    <style>
        body { font-family: sans-serif; color: #111827; }
        .section { margin-bottom: 2rem; }
        .section h2 { font-size: 1.25rem; margin-bottom: 0.5rem; border-bottom: 1px solid #d1d5db; padding-bottom: 0.25rem; }
        dl { margin: 0; }
        dt { font-weight: 600; margin-top: 0.75rem; }
        dd { margin: 0.25rem 0 0.5rem 0; }
        ul { margin: 0.5rem 0 0.75rem 1.25rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 0.75rem; }
        th, td { border: 1px solid #d1d5db; padding: 0.5rem; font-size: 0.875rem; }
        th { background: #f3f4f6; text-align: left; }
    </style>
</head>
<body>
    <h1>{{ __('Characterization Summary') }}</h1>
    <p>{{ __('Generated at :date', ['date' => now()->format('Y-m-d H:i')]) }}</p>

    <div class="section">
        <h2>{{ __('Status Overview') }}</h2>
        <dl>
            <dt>{{ __('Status') }}</dt>
            <dd>{{ __(ucfirst(str_replace('_', ' ', $characterization->status))) }}</dd>
            <dt>{{ __('Submitted at') }}</dt>
            <dd>{{ optional($characterization->submitted_at)->format('Y-m-d H:i') ?? '—' }}</dd>
            <dt>{{ __('Completed at') }}</dt>
            <dd>{{ optional($characterization->completed_at)->format('Y-m-d H:i') ?? '—' }}</dd>
            <dt>{{ __('Retry count') }}</dt>
            <dd>{{ $characterization->retry_count }}</dd>
            <dt>{{ __('Next retry at') }}</dt>
            <dd>{{ optional($characterization->next_retry_at)->format('Y-m-d H:i') ?? '—' }}</dd>
            @if($characterization->last_error)
                <dt>{{ __('Last error') }}</dt>
                <dd>{{ $characterization->last_error }}</dd>
            @endif
        </dl>
    </div>

    <div class="section">
        <h2>{{ __('Company Profile') }}</h2>
        <dl>
            <dt>{{ __('Company name') }}</dt>
            <dd>{{ Arr::get($formData, 'company_profile.company_name', '—') }}</dd>
            <dt>{{ __('Headquarters country') }}</dt>
            @php $headquartersCountry = Arr::get($formData, 'company_profile.headquarters_country'); @endphp
            <dd>{{ $headquartersCountryOptions[$headquartersCountry] ?? '—' }}</dd>
            <dt>{{ __('Reporting year') }}</dt>
            <dd>{{ Arr::get($formData, 'company_profile.reporting_year', '—') }}</dd>
            <dt>{{ __('Reporting scope') }}</dt>
            @php $reportingScope = Arr::get($formData, 'company_profile.reporting_scope'); @endphp
            <dd>{{ $reportingScopeOptions[$reportingScope] ?? '—' }}</dd>
            <dt>{{ __('Countries with subsidiaries') }}</dt>
            <dd>{{ Arr::get($formData, 'company_profile.num_subsidiaries_countries', '—') }}</dd>
            <dt>{{ __('Listed company') }}</dt>
            @php $stockListed = Arr::get($formData, 'company_profile.stock_listed'); @endphp
            <dd>
                @if ($stockListed === null)
                    —
                @else
                    {{ $stockListed ? __('Yes') : __('No') }}
                @endif
            </dd>
            <dt>{{ __('Reporting currency') }}</dt>
            @php $reportingCurrency = Arr::get($formData, 'company_profile.reporting_currency'); @endphp
            <dd>{{ $reportingCurrencyOptions[$reportingCurrency] ?? '—' }}</dd>
            <dt>{{ __('Product/service type') }}</dt>
            @php $productServiceType = Arr::get($formData, 'company_profile.product_service_type'); @endphp
            <dd>{{ $productServiceTypeOptions[$productServiceType] ?? '—' }}</dd>
            <dt>{{ __('NACE Code') }}</dt>
            <dd>
                @if ($selectedNace)
                    {{ $selectedNace['code'] }} · {{ $selectedNace['title'][$locale] ?? $selectedNace['title']['en'] }}
                @else
                    —
                @endif
            </dd>
            <dt>{{ __('Regions of operation') }}</dt>
            <dd>
                @php $regions = Arr::get($formData, 'operations.regions', []); @endphp
                @if (count($regions))
                    <ul>
                        @foreach ($regions as $region)
                            <li>{{ $regionOptions[$region] ?? $region }}</li>
                        @endforeach
                    </ul>
                @else
                    —
                @endif
            </dd>
            <dt>{{ __('Value chain stages') }}</dt>
            <dd>
                @php $stages = Arr::get($formData, 'operations.value_chain', []); @endphp
                @if (count($stages))
                    <ul>
                        @foreach ($stages as $stage)
                            <li>{{ $valueChainOptions[$stage] ?? $stage }}</li>
                        @endforeach
                    </ul>
                @else
                    —
                @endif
            </dd>
            <dt>{{ __('Employees') }}</dt>
            @php $employeeCountRange = Arr::get($formData, 'operations.employee_count_range'); @endphp
            <dd>{{ $employeeCountRangeOptions[$employeeCountRange] ?? Arr::get($formData, 'operations.employee_count', '—') }}</dd>
            <dt>{{ __('Annual revenue') }}</dt>
            @php $revenueRange = Arr::get($formData, 'operations.revenue_range'); @endphp
            <dd>{{ $revenueRangeOptions[$revenueRange] ?? Arr::get($formData, 'operations.revenue', '—') }}</dd>
        </dl>
    </div>

    <div class="section">
        <h2>{{ __('ESRS Topics') }}</h2>
        @if (count($selectedTopics))
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Code') }}</th>
                        <th>{{ __('Theme') }}</th>
                        <th>{{ __('Subtheme') }}</th>
                        <th>{{ __('Subtopic') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($selectedTopics as $topic)
                        <tr>
                            <td>{{ $topic['esrs_code'] }}</td>
                            <td>{{ $topic['theme'][$locale] ?? $topic['theme']['en'] }}</td>
                            <td>{{ $topic['subtheme'][$locale] ?? $topic['subtheme']['en'] }}</td>
                            <td>{{ $topic['subtopic'][$locale] ?? $topic['subtopic']['en'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p>{{ __('No topics selected.') }}</p>
        @endif
    </div>

    <div class="section">
        <h2>{{ __('Notes') }}</h2>
        <p>{{ Arr::get($formData, 'notes', __('No additional notes provided.')) }}</p>
    </div>
</body>
</html>
