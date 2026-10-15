<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class LearningDataLifecycle
{
    /** Durable erasure outbox survives account deletion and feature disablement. */
    public function withdraw(User $user): void
    {
        if ($user->learning_subject !== null && Schema::hasTable('learning_erasure_requests')) {
            DB::table('learning_erasure_requests')->updateOrInsert(
                ['subject' => $user->learning_subject], ['created_at' => now()]);
            // Remove the short-lived serving lease immediately. If storage is down,
            // its fixed expiry still prevents continued use; the outbox retries.
            $lease = config('learning_engine.root').'/authority.json';
            if (is_file($lease)) { @unlink($lease); }
        }
        if ($user->learning_subject !== null && Schema::hasTable('learning_bandit_events')) {
            DB::table('learning_bandit_events')->where('subject', $user->learning_subject)->delete();
        }
        if (Schema::hasColumn('users', 'learning_subject')) {
            $user->forceFill(['learning_subject' => null, 'learning_consent_at' => null,
                'learning_consent_version' => null])->save();
        }
        $row = \App\Models\Characterization::where('user_id', $user->id)->lockForUpdate()->first();
        if ($row !== null) {
            $form = $row->form_data ?? [];
            \Illuminate\Support\Arr::forget($form, ['materiality_confirmation.training_source', 'esrs_datapoint_responses.training_source']);
            $row->forceFill(['form_data' => $form])->save();
        }
    }

    public function refreshAuthority(): void
    {
        $startedAt = time();
        $root = config('learning_engine.root');
        if (! is_dir($root)) { return; }
        $subjects = [];
        $bindings = [];
        if (ConsentLearningAuthorizationAuthority::enabled()) {
            $authority = app(ConsentLearningAuthorizationAuthority::class);
            foreach (User::whereNotNull('learning_consent_at')->cursor() as $user) {
                if ($authority->permits($user)) {
                    $subjects[] = $user->learning_subject;
                    $membership = $authority->membership($user);
                    if ($membership !== null) {
                        $bindings[$user->learning_subject] = $authority->binding($user, $membership);
                    }
                }
            }
        }
        $pending = Schema::hasTable('learning_erasure_requests')
            ? DB::table('learning_erasure_requests')->pluck('subject')->all() : [];
        // Preserve consent history while switches are OFF. Serving separately
        // requires the engine/mode flags; privacy upkeep must still see withdrawals.
        $banditSubjects = User::whereNotNull('learning_consent_at')->whereNotNull('learning_subject')
            ->whereNotNull('email_verified_at')->where('learning_consent_version', config('learning_engine.consent_version'))
            ->pluck('learning_subject')->all();
        $value = ['bandit_subjects' => array_values(array_diff($banditSubjects, $pending)), 'expires_at' => $startedAt + 90, 'subjects' => array_values(array_diff($subjects, $pending)),
            'bandit_revisions' => (object) app(LearningBanditFeedback::class)->revisions(),
            'bindings' => (object) array_diff_key($bindings, array_flip($pending)),
            'mapping_sha256' => hash_file('sha256', config('services.characterization.prediction_mapping_path'))];
        $temp = tempnam($root, '.authority-');
        try {
            if ($temp === false || file_put_contents($temp, json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || ! chmod($temp, 0600) || ! rename($temp, $root.'/authority.json')) {
                throw new \RuntimeException('learning.authority_write_failed');
            }
        } finally { if ($temp !== false && is_file($temp)) { unlink($temp); } }
    }
}
