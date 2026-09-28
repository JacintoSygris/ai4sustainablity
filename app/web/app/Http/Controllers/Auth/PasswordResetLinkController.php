<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Support\PasswordResetGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        abort_unless(PasswordResetGuard::available(), 404);

        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(PasswordResetGuard::available(), 404);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        [$userId, $email, $authVersion, $generation] = app(Timebox::class)->call(function () use ($request): array {
            $email = mb_strtolower((string) $request->string('email'));

            return DB::transaction(function () use ($email): array {
                $generation = (string) Str::uuid();
                $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();

                if (! $user) {
                    return [0, $email, -1, $generation];
                }

                Password::deleteToken($user);
                $user->forceFill(['password_reset_generation' => $generation])->save();

                return [$user->getKey(), $user->email, (int) $user->auth_version, $generation];
            });
        }, 250_000);

        SendPasswordResetLink::dispatch($userId, $email, $authVersion, $generation);

        // Always return the same response so this endpoint cannot be used to
        // discover whether an email address belongs to an account.
        return back()->with('status', __('Si existe una cuenta con ese correo, recibirás un enlace para restablecer la contraseña.'));
    }
}
