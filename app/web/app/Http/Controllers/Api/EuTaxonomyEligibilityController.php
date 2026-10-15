<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EuTaxonomyDeclaration;
use App\Services\CharacterizationStateTransaction;
use App\Services\EuTaxonomyEligibility;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EuTaxonomyEligibilityController extends Controller
{
    public function show(Request $request, EuTaxonomyEligibility $service)
    {
        abort_unless(config('eu_taxonomy.enabled'), 404);
        $characterization = Characterization::forUser($request->user()->id)->first();

        return response()->json(['data' => $characterization ? $service->screen($characterization) : null]);
    }

    public function update(Request $request, EuTaxonomyEligibility $service, CharacterizationStateTransaction $transactions)
    {
        abort_unless(config('eu_taxonomy.enabled'), 404);
        // No caller-supplied owner or characterization identifiers are accepted.
        $data = $request->validate([
            'characterization_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'source_hash' => ['required', 'string', 'size:64'],
            'revision' => ['required', 'integer', 'min:0'],
            'activities' => ['present', 'array', 'list', 'max:50'],
            'activities.*' => ['array:activity_id,turnover_share,capex_share,opex_share'],
            'activities.*.activity_id' => ['required', 'string', 'distinct', Rule::in(array_column($service->catalogue()['activities'], 'id'))],
            // Null means undeclared; 0 means an explicit zero. No totals/KPIs are derived.
            'activities.*.turnover_share' => ['present', 'nullable', 'numeric', 'between:0,100'],
            'activities.*.capex_share' => ['present', 'nullable', 'numeric', 'between:0,100'],
            'activities.*.opex_share' => ['present', 'nullable', 'numeric', 'between:0,100'],
        ]);

        return $transactions->runForUser($request->user()->id, function (?Characterization $characterization) use ($data, $service) {
            abort_if($characterization === null, 404);
            $screen = $service->screen($characterization);
            if (! hash_equals($screen['source_hash'], $data['source_hash']) || $screen['revision'] !== (int) $data['revision']) {
                return response()->json(['message' => __('eu_taxonomy.conflict')], 409);
            }
            EuTaxonomyDeclaration::updateOrCreate(['characterization_id' => $characterization->id], [
                'source_hash' => $screen['source_hash'],
                'source_context' => $screen['source_context'],
                'revision' => $screen['revision'] + 1,
                'activities' => array_map(fn (array $row) => [
                    'activity_id' => $row['activity_id'],
                    'turnover_share' => $row['turnover_share'] === null ? null : (float) $row['turnover_share'],
                    'capex_share' => $row['capex_share'] === null ? null : (float) $row['capex_share'],
                    'opex_share' => $row['opex_share'] === null ? null : (float) $row['opex_share'],
                ], $data['activities']),
            ]);

            return response()->json(['data' => $service->screen($characterization)]);
        });
    }
}
