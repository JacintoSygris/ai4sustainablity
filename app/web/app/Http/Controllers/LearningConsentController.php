<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ConsentLearningAuthorizationAuthority;
use App\Services\LearningDataLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LearningConsentController extends Controller
{
    public function update(Request $request, LearningDataLifecycle $lifecycle)
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('users', 'learning_consent_at')) {
            return response()->json(['message' => __('learning.unavailable')], 503);
        }
        $validated = $request->validate(['consent' => ['required', 'boolean'],
            'version' => ['required', 'in:'.config('learning_engine.consent_version')]]);
        if ($validated['consent']) {
            abort_unless(ConsentLearningAuthorizationAuthority::enabled() && $request->user()->hasVerifiedEmail(), 403);
        }
        DB::transaction(function () use ($request, $validated, $lifecycle) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! $validated['consent']) { $lifecycle->withdraw($user); return; }
            if ($user->learning_consent_version === config('learning_engine.consent_version')
                && $user->learning_consent_at !== null) { return; }
            $lifecycle->withdraw($user);
            $user->forceFill(['learning_subject' => (string) Str::uuid(), 'learning_consent_at' => now(),
                'learning_consent_version' => config('learning_engine.consent_version')])->save();
        }, 3);
        return redirect()->route('profile.edit')->with('status', 'learning-consent-updated');
    }
}
