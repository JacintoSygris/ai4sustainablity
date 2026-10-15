<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunLearningAdminAction;
use App\Services\LearningActivationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class LearningAdminController extends Controller
{
    public function show(Request $request, LearningActivationStatus $status)
    {
        abort_unless(LearningActivationStatus::isAdmin($request->user()), 403);
        $input = $request->validate(['candidate' => ['sometimes', 'required', 'string', 'regex:/^[a-f0-9]{32}$/D']]);
        return response()->json(['data' => $status->snapshot($input['candidate'] ?? null)])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, LearningActivationStatus $status)
    {
        abort_unless(LearningActivationStatus::isAdmin($request->user()), 403);
        $input = $request->validate([
            'loop' => ['required', Rule::in(['wp13', 'wp23'])],
            'action' => ['required', Rule::in(['train', 'process', 'promote', 'rollback'])],
            'candidate' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        abort_unless(in_array($input['action'], $input['loop'] === 'wp13' ? ['train', 'promote', 'rollback'] : ['process', 'promote', 'rollback'], true), 422);
        if ($input['action'] === 'promote' && empty($input['candidate'])) { abort(422); }
        // The recent list is a display window, not the promotion candidate set.
        $state = $input['loop'] === 'wp13' && $input['action'] === 'promote'
            ? $status->snapshot($input['candidate'])
            : $status->snapshot();
        if (! $status->ready($state, $input['loop'], $input['action'])) {
            return response()->json(['message' => __('learning.unavailable'), 'code' => 'learning_prerequisites', 'data' => $state], 503);
        }
        if ($input['action'] === 'promote') {
            $candidate = $input['loop'] === 'wp13'
                ? collect($state['wp13']['candidates'] ?? [])->firstWhere('id', $input['candidate'])
                : ($state['wp23']['candidate'] ?? null);
            abort_unless($candidate !== null && $candidate['id'] === $input['candidate'] && ($candidate['eligible'] ?? false) === true, 409);
        }
        $id = (string) Str::uuid();
        DB::table('learning_admin_actions')->insert(['id' => $id, 'admin_id' => $request->user()->id,
            'loop' => $input['loop'], 'action' => $input['action'], 'candidate' => $input['candidate'] ?? null,
            'reason' => trim($input['reason']), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        try { RunLearningAdminAction::dispatch($id)->onQueue('learning'); }
        catch (\Throwable) {
            DB::table('learning_admin_actions')->where('id', $id)->update(['status' => 'failed_closed', 'error_code' => 'learning.queue_unavailable']);
            return response()->json(['message' => __('learning.unavailable'), 'code' => 'learning_queue_unavailable'], 503);
        }
        return response()->json(['data' => ['id' => $id, 'status' => 'queued']], 202)->header('Cache-Control', 'no-store');
    }
}
