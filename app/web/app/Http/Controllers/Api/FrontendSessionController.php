<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FrontendSessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'authenticated' => true,
                'learning_admin' => \App\Services\LearningActivationStatus::isAdmin($user),
                'learning_consent_invitation' => \App\Services\ConsentLearningAuthorizationAuthority::enabled()
                    && ! app(\App\Services\ConsentLearningAuthorizationAuthority::class)->permits($user),
                'xbrl_runtime_identification_enabled' => (bool) config('xbrl_suggestions.runtime_enabled', false),
                'ixbrl_package_enabled' => (bool) config('ixbrl.package_ui_enabled', false),
                ...(config('usability.enabled') ? ['usability_study_enabled' => true] : []),
                'eu_taxonomy_eligibility_enabled' => (bool) config('eu_taxonomy.enabled'),
                'locale' => app()->getLocale(),
                'csrf_header' => 'X-XSRF-TOKEN',
                'csrf_token' => csrf_token(),
                'user' => [
                    'id' => $user?->id,
                    'name' => $user?->name,
                    'email' => $user?->email,
                    // Frontend gates the wizard on this when verification is
                    // enforced (config services.auth_hardening).
                    'email_verified' => (bool) $user?->hasVerifiedEmail(),
                ],
                'require_email_verification' => (bool) config('services.auth_hardening.require_email_verification'),
            ],
        ]);
    }
}
