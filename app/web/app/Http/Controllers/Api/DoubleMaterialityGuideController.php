<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Services\CharacterizationStateTransaction;
use App\Support\DoubleMaterialityGuide;
use App\Support\DoubleMaterialityProcessState;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class DoubleMaterialityGuideController extends Controller
{
    public function show()
    {
        return response()->json(['data' => DoubleMaterialityGuide::toArray(app()->getLocale())]);
    }

    public function templateCsv(Request $request, string $template)
    {
        $validated = $request->validate([
            'locale' => ['sometimes', 'string', 'in:en,es'],
        ]);

        // Legacy direct clients may choose a query locale only without a stored preference.
        $locale = \App\Support\ApplicationLocale::preference($request) === null
            ? ($validated['locale'] ?? app()->getLocale())
            : app()->getLocale();
        $csv = DoubleMaterialityGuide::templateCsv($template, $locale);

        if (! $csv) {
            return response()->json(['message' => __('Template not found.')], 404);
        }

        return response($csv['content'], 200, [
            'Content-Disposition' => 'attachment; filename='.$csv['filename'],
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Language' => $locale,
        ]);
    }

    public function state(Request $request)
    {
        $characterization = Characterization::forUser($request->user()->id)->first();

        return response()->json([
            'data' => DoubleMaterialityProcessState::fromFormData($characterization?->form_data ?? []),
        ]);
    }

    public function updateState(Request $request, CharacterizationStateTransaction $stateTransactions)
    {
        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'checklist' => ['sometimes', 'array'],
            'checklist.identified_stakeholders' => ['sometimes', 'boolean'],
            'checklist.assessed_impacts' => ['sometimes', 'boolean'],
            'checklist.assessed_financial_effects' => ['sometimes', 'boolean'],
            'checklist.reached_conclusions' => ['sometimes', 'boolean'],
            'acta' => ['sometimes', 'array'],
            'acta.completed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'acta.method' => ['nullable', 'string', 'max:500'],
            'acta.participants' => ['nullable', 'string', 'max:500'],
        ]);

        $characterization = $stateTransactions->run($characterization->id, function (Characterization $locked) use ($validated): Characterization {
            $formData = $locked->form_data ?? [];
            $current = DoubleMaterialityProcessState::fromFormData($formData);
            $process = [
                'checklist' => $current['checklist'],
                'acta' => $current['acta'],
                'updated_at' => now()->toJSON(),
            ];

            if (array_key_exists('checklist', $validated)) {
                $process['checklist'] = DoubleMaterialityProcessState::checklistFromPayload($validated['checklist']);
            }

            if (array_key_exists('acta', $validated)) {
                $process['acta'] = DoubleMaterialityProcessState::actaFromPayload($validated['acta']);
            }

            Arr::set($formData, 'double_materiality_process', $process);
            $locked->forceFill(['form_data' => $formData])->save();

            return $locked;
        });

        return response()->json([
            'data' => DoubleMaterialityProcessState::fromFormData($characterization->form_data ?? []),
        ]);
    }
}
