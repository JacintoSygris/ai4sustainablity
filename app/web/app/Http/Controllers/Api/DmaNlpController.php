<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\DmaImport;
use App\Models\DmaNlpRun;
use App\Services\Dma\DmaNlpRecommendations;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DmaNlpController extends Controller
{
    private function owner(Request $request, int $id): Characterization
    {
        abort_unless(DmaNlpRecommendations::enabled(), 404);
        return Characterization::forUser($request->user()->id)->findOrFail($id);
    }
    private function run(Characterization $record, int $id): DmaNlpRun
    {
        $run = DmaNlpRun::where('characterization_id', $record->id)->where('user_id', $record->user_id)->findOrFail($id);
        DmaImport::where('characterization_id', $record->id)->where('user_id', $record->user_id)->findOrFail($run->dma_import_id);
        return $run;
    }
    private function validateInput(Request $request, array $rules): array
    {
        if (array_diff(array_keys($request->all()), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['request' => __('Unexpected analysis properties are not accepted.')]);
        }
        return $request->validate($rules);
    }
    private function contextRules(): array
    {
        return ['expected_context' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'], 'expected_revision' => ['required', 'integer:strict', 'min:0']];
    }
    public function store(Request $request, int $characterization, int $import, DmaNlpRecommendations $service)
    {
        $record = $this->owner($request, $characterization);
        $input = $this->validateInput($request, $this->contextRules() + ['stage' => ['required', 'in:p8,p9'], 'locale' => ['required', 'in:es,en'],
            'iro_ids' => ['required', 'array', 'list', 'min:1', 'max:20'], 'iro_ids.*' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D', 'distinct:strict']]);
        // Foreign imports are hidden before any context details are returned.
        DmaImport::where('characterization_id', $record->id)->where('user_id', $record->user_id)->findOrFail($import);
        $run = $service->create($record, $import, $input);
        return response()->json(['data' => $service->presentation($run, $record->fresh())], $run->wasRecentlyCreated ? 201 : 200);
    }
    public function show(Request $request, int $characterization, int $run, DmaNlpRecommendations $service)
    {
        $record = $this->owner($request, $characterization);
        return response()->json(['data' => $service->presentation($this->run($record, $run), $record)]);
    }
    public function review(Request $request, int $characterization, int $run, DmaNlpRecommendations $service)
    {
        $record = $this->owner($request, $characterization); $found = $this->run($record, $run);
        $input = $this->validateInput($request, $this->contextRules() + ['candidate_id' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'], 'decision' => ['required', 'in:accepted,rejected']]);
        return response()->json(['data' => $service->review($record, $found, $input)]);
    }
}
