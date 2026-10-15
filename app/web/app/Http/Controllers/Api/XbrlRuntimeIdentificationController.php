<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Services\Report\XbrlRuntimeIdentificationService;
use App\Services\Report\XbrlRuntimeContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class XbrlRuntimeIdentificationController extends Controller
{
    private function owned(Request $request): Characterization
    {
        abort_unless(config('xbrl_suggestions.runtime_enabled', false), 404);
        return Characterization::forUser($request->user()->id)->firstOrFail();
    }

    private function payload(Request $request, array $rules): array
    {
        // Parse raw JSON to preserve wording through Laravel's generic trim middleware.
        abort_if(strlen($request->getContent()) > 32768, 413);
        try { $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR); }
        catch (Throwable) { abort(422, __('xbrl_runtime.invalid_input')); }
        abort_unless(is_array($data) && ! array_is_list($data), 422, __('xbrl_runtime.invalid_input'));
        $allowed = array_filter(array_keys($rules), fn ($key) => ! str_contains($key, '.'));
        abort_if(array_diff(array_keys($data), $allowed) !== [], 422, __('xbrl_runtime.invalid_input'));
        return Validator::make($data, $rules)->validate();
    }

    public function index(Request $request, XbrlRuntimeIdentificationService $service)
    {
        $owned = $this->owned($request);
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        return response()->json(['data' => $service->history($owned, (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20))]);
    }

    public function store(Request $request, XbrlRuntimeIdentificationService $service)
    {
        $owned = $this->owned($request);
        $input = $this->payload($request, [
            'reporting_fact_id' => ['sometimes', 'integer', 'min:1'],
            'datapoint_id' => ['sometimes', 'string', 'max:255'],
            'indicator' => ['required', 'array:label,description,language,standard,dr,value_type,unit,period_type'],
            'indicator.label' => ['present', 'string', 'max:300'],
            'indicator.description' => ['present', 'string', 'max:4000'],
            'indicator.language' => ['required', 'string', 'max:16', 'regex:/\A[a-zA-Z-]{2,16}\z/'],
            'indicator.standard' => ['sometimes', 'nullable', Rule::in(['ESRS 2', 'E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1'])],
            'indicator.dr' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/\A(BP-[12]|GOV-[1-5]|SBM-[1-3]|IRO-[12]|MDR-[PAMT]|(?:E[1-5]|S[1-4]|G1)-[0-9]{1,2})\z/'],
            'indicator.value_type' => ['sometimes', 'nullable', Rule::in(['text', 'number', 'monetary', 'integer', 'boolean', 'enumeration', 'date', 'nil'])],
            'indicator.unit' => ['sometimes', 'nullable', 'string', 'min:1', 'max:64'],
            'indicator.period_type' => ['sometimes', 'nullable', Rule::in(['instant', 'duration'])],
        ]);
        abort_if(trim($input['indicator']['label'].$input['indicator']['description']) === '', 422, __('xbrl_runtime.invalid_input'));
        abort_unless(XbrlRuntimeContext::supportedContext($input['indicator']), 422, __('xbrl_runtime.invalid_input'));
        return $this->safe(fn () => response()->json(['data' => $service->identify($owned, $request->user()->id, $input)], 201));
    }

    public function review(Request $request, int $run, XbrlRuntimeIdentificationService $service)
    {
        $owned = $this->owned($request);
        $input = $this->payload($request, [
            'candidate_qname' => ['required', 'string', 'max:500', 'regex:/\Aesrs:[A-Za-z_][A-Za-z0-9_]*\z/'],
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'reason' => ['required', 'string', 'max:1000'],
            'expected_sequence' => ['required', 'integer', 'min:0'],
        ]);
        abort_if(trim($input['reason']) === '', 422, __('xbrl_runtime.invalid_input'));
        return $this->safe(fn () => response()->json(['data' => $service->review($owned, $request->user()->id, $run, $input)]));
    }

    private function safe(callable $operation)
    {
        try { return $operation(); }
        catch (HttpExceptionInterface | ModelNotFoundException $exception) { throw $exception; }
        catch (Throwable) { return response()->json(['message' => __('xbrl_runtime.unavailable'), 'code' => 'xbrl_runtime_unavailable'], 503); }
    }
}
