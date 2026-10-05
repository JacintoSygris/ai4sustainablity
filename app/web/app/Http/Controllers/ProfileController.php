<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\User;
use App\Services\CharacterizationDocumentPurgeService;
use App\Services\LearningAuthorizationLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(
        private readonly CharacterizationDocumentPurgeService $documentPurges,
        private readonly LearningAuthorizationLedger $learningLedger,
    ) {}

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        [$user, $securityChanged] = DB::transaction(function () use ($request): array {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $previousEmail = $user->email;
            $validated = $request->safe()->only(['name', 'email']);
            if ((int) $request->session()->get('auth_version', -1) !== (int) $user->auth_version) {
                throw ValidationException::withMessages(['email' => __('auth.password')]);
            }
            $user->fill($validated);
            $securityChanged = $user->isDirty('email');

            if ($securityChanged) {
                if (! Hash::check((string) $request->input('current_password'), $user->password)) {
                    throw ValidationException::withMessages(['current_password' => __('auth.password')]);
                }
                $user->email_verified_at = null;
                $user->auth_version = (int) $user->auth_version + 1;
                $user->remember_token = Str::random(60);
                $user->password_reset_generation = null;
            }

            $user->save();

            if ($securityChanged) {
                DB::table('password_reset_tokens')
                    ->whereIn('email', [$previousEmail, $user->email])
                    ->delete();
            }

            return [$user, $securityChanged];
        }, 3);

        if ($securityChanged) {
            Auth::setUser($user);
            $request->setUserResolver(static fn () => $user);
            $request->session()->put('auth_version', (int) $user->auth_version);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        $purgeIds = DB::transaction(function () use ($user, $request): array {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ((int) $request->session()->get('auth_version', -1) !== (int) $lockedUser->auth_version
                || ! Hash::check((string) $request->input('password'), $lockedUser->password)) {
                throw ValidationException::withMessages(['password' => __('auth.password')])
                    ->errorBag('userDeletion');
            }
            /** @var Characterization|null $characterization */
            $characterization = Characterization::query()
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->first();

            if ($characterization?->documents()
                ->where('status', CharacterizationDocument::STATUS_EXTRACTING)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages([
                    'password' => 'Hay un documento en proceso. Inténtalo de nuevo cuando termine.',
                ])->errorBag('userDeletion');
            }

            $documentIds = $characterization?->documents()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all() ?? [];

            $this->learningLedger->tombstoneForAccount($lockedUser->id);

            $characterization?->delete();
            DB::table('password_reset_tokens')->where('email', $lockedUser->email)->delete();
            $lockedUser->delete();

            return $this->documentPurges->intentIdsForDocumentIds($documentIds);
        }, 3);

        $this->documentPurges->purgeAfterCommit($purgeIds);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
