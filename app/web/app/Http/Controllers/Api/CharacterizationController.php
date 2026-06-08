<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CharacterizationRequest;
use App\Http\Resources\CharacterizationResource;
use App\Models\Characterization;
use App\Services\CharacterizationRecorder;
use App\Support\CharacterizationOptions;
use Illuminate\Http\Request;

class CharacterizationController extends Controller
{
    public function options()
    {
        return response()->json([
            'data' => [
                'levels' => [
                    'core' => [
                        'required_fields' => CharacterizationOptions::submitRequiredFields(),
                        'draft_clearable_fields' => CharacterizationOptions::draftClearableFields(),
                        'company_profile' => [
                            'headquarters_countries' => CharacterizationOptions::headquartersCountries(),
                            'reporting_scopes' => CharacterizationOptions::reportingScopes(),
                            'reporting_currencies' => CharacterizationOptions::reportingCurrencies(),
                            'product_service_types' => CharacterizationOptions::productServiceTypes(),
                        ],
                        'operations' => [
                            'regions' => CharacterizationOptions::regions(),
                            'value_chain' => CharacterizationOptions::valueChainPositions(),
                            'employee_count_ranges' => CharacterizationOptions::employeeCountRanges(),
                            'revenue_ranges' => CharacterizationOptions::revenueRanges(),
                            'numeric_estimates' => [
                                'employee_count' => [
                                    'field' => 'form_data.operations.employee_count',
                                    'type' => 'integer',
                                    'minimum' => 1,
                                    'range_field' => 'form_data.operations.employee_count_range',
                                    'range_estimates' => CharacterizationOptions::employeeCountRangeEstimates(),
                                ],
                                'revenue' => [
                                    'field' => 'form_data.operations.revenue',
                                    'type' => 'number',
                                    'minimum' => 0,
                                    'currency_field' => 'form_data.company_profile.reporting_currency',
                                    'range_field' => 'form_data.operations.revenue_range',
                                    'range_estimates' => CharacterizationOptions::revenueRangeEstimates(),
                                ],
                            ],
                        ],
                    ],
                    'csrd_orientation' => [
                        'enabled_field' => 'form_data.csrd_orientation.enabled',
                        'fields' => [
                            'listed_entity' => 'Listed entity',
                            'public_interest_entity' => 'Public interest entity',
                            'reports_as_group' => 'Reports as group',
                        ],
                        'values' => CharacterizationOptions::yesNoUnknown(),
                        'disclaimer' => CharacterizationOptions::csrdOrientationDisclaimer(),
                    ],
                    'data_readiness' => [
                        'enabled_field' => 'form_data.data_readiness.enabled',
                        'items' => CharacterizationOptions::dataReadinessItems(),
                        'fields' => [
                            'available',
                            'year',
                            'source',
                            'verified',
                            'traceability',
                        ],
                        'values' => CharacterizationOptions::yesNoUnknown(),
                        'sources' => CharacterizationOptions::dataReadinessSources(),
                        'traceability_levels' => CharacterizationOptions::traceabilityLevels(),
                    ],
                ],
            ],
        ]);
    }

    public function show(Request $request)
    {
        $characterization = $request->user()->characterization()->first();

        if (! $characterization) {
            return response()->json(['data' => null]);
        }

        return CharacterizationResource::make($characterization);
    }

    public function update(
        CharacterizationRequest $request,
        CharacterizationRecorder $recorder,
    ) {
        $payload = $request->validated();
        $payload['status'] = Characterization::STATUS_DRAFT;

        return CharacterizationResource::make(
            $recorder->save($request->user(), $payload)
        )->response()->setStatusCode(200);
    }

    public function submit(
        CharacterizationRequest $request,
        CharacterizationRecorder $recorder,
    ) {
        $characterization = $recorder->save($request->user(), $request->validated());

        return CharacterizationResource::make($characterization)
            ->response()
            ->setStatusCode(202);
    }
}
