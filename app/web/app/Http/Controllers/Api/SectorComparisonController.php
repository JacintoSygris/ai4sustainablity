<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\EsrsTopic;
use App\Services\SectorComparisonGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectorComparisonController extends Controller
{
    public function show(Request $request, string $characterization, SectorComparisonGateway $gateway): JsonResponse
    {
        abort_unless(config('services.sector_comparison.enabled', false), 404);
        $owned = Characterization::forUser($request->user()->id)->whereKey($characterization)->firstOrFail();
        if ($request->query() !== [] || $request->getContent() !== '') {
            return response()->json(['code' => 'sector_comparison_invalid_request'], 422)->header('Cache-Control', 'private, no-store');
        }
        $fingerprint = SectorComparisonGateway::fingerprint($owned);
        $topics = EsrsTopic::query()->orderBy('id')->pluck('id')->all();
        $result = null;
        try {
            $result = $gateway->compare($owned, $topics);
        } catch (\Throwable) {
            // No upstream exception, token, body, path or manifest is exposed/logged.
        }
        $current = Characterization::forUser($request->user()->id)->whereKey($characterization)->firstOrFail();
        if (! hash_equals($fingerprint, SectorComparisonGateway::fingerprint($current))) {
            return response()->json(['code' => 'sector_comparison_inputs_changed'], 409)->header('Cache-Control', 'private, no-store');
        }
        if ($result === null) {
            return response()->json(['code' => 'sector_comparison_unavailable'], 503)
                ->header('Retry-After', '60')->header('Cache-Control', 'private, no-store');
        }

        return response()->json(['data' => [
            ...$result,
            'characterization_id' => $current->id,
            'input_fingerprint' => $fingerprint,
        ]])->header('Cache-Control', 'private, no-store');
    }
}
