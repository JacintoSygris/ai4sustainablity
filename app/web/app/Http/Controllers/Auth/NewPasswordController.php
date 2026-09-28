<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordResetGuard;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    private const DUMMY_TOKEN_HASH = '$2y$12$cNF2.TQA71Ug5b/xUboCZuDZAox6pYIjY275WdfxKYDJ9u8ufb5ma';

    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        abort_unless(PasswordResetGuard::available(), 404);

        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(PasswordResetGuard::available(), 404);

        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = app(Timebox::class)->call(function () use ($request): string {
            $token = $request->string('token')->toString();
            $email = mb_strtolower($request->string('email')->toString());
            $candidate = DB::table('password_reset_tokens')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            // Every public outcome performs the same two password-hash checks.
            Hash::check($token, self::DUMMY_TOKEN_HASH);
            $tokenHash = is_string($candidate?->token ?? null) ? $candidate->token : self::DUMMY_TOKEN_HASH;
            $hashMatches = Hash::check($token, $tokenHash);

            if (! $candidate
                || ! $hashMatches
                || ! $this->resetRecordIsCurrent($candidate, $token)) {
                return Password::INVALID_TOKEN;
            }

            return DB::transaction(function () use ($request, $candidate, $token, $email): string {
                $user = User::query()
                    ->whereKey((int) $candidate->user_id)
                    ->lockForUpdate()
                    ->first();
                $lockedToken = DB::table('password_reset_tokens')
                    ->where('email', $candidate->email)
                    ->lockForUpdate()
                    ->first();

                if (! $user
                    || ! $lockedToken
                    || ! hash_equals($email, mb_strtolower($user->email))
                    || (int) $lockedToken->user_id !== (int) $user->getKey()
                    || (int) $lockedToken->auth_version !== (int) $user->auth_version
                    || ! hash_equals((string) $lockedToken->generation, (string) $candidate->generation)
                    || ! hash_equals((string) $lockedToken->token_fingerprint, hash('sha256', $token))
                    || ! hash_equals((string) $lockedToken->token, (string) $candidate->token)
                    || ! $this->resetRecordIsCurrent($lockedToken, $token)) {
                    return Password::INVALID_TOKEN;
                }

                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'auth_version' => (int) $user->auth_version + 1,
                    'remember_token' => Str::random(60),
                    'password_reset_generation' => null,
                ])->save();
                DB::table('password_reset_tokens')
                    ->where('email', $lockedToken->email)
                    ->where('token_fingerprint', $lockedToken->token_fingerprint)
                    ->delete();
                DB::afterCommit(fn () => event(new PasswordReset($user)));

                return Password::PASSWORD_RESET;
            }, 3);
        }, 500_000);

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status == Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }

    private function resetRecordIsCurrent(object $record, string $token): bool
    {
        if (! is_numeric($record->user_id ?? null)
            || ! is_numeric($record->auth_version ?? null)
            || ! is_string($record->generation ?? null)
            || ! Str::isUuid($record->generation)
            || ! is_string($record->token_fingerprint ?? null)
            || ! hash_equals($record->token_fingerprint, hash('sha256', $token))
            || ! is_string($record->created_at ?? null)) {
            return false;
        }

        try {
            $createdAt = CarbonImmutable::parse($record->created_at);
        } catch (\Throwable) {
            return false;
        }

        return $createdAt->greaterThanOrEqualTo(
            now()->toImmutable()->subMinutes((int) config('auth.passwords.users.expire', 60)),
        );
    }
}
