<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticatePrivateDevUser;
use App\Jobs\SendRegistrationVerification;
use App\Models\User;
use App\Support\RegistrationGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View|RedirectResponse
    {
        abort_unless(RegistrationGuard::registrationAvailable(), 404);

        if (AuthenticatePrivateDevUser::enabled()) {
            return redirect('/dashboard');
        }

        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(RegistrationGuard::registrationAvailable(), 404);

        // Rate limit public registration per IP (open-signup abuse control).
        $throttleKey = 'register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Demasiados intentos de registro. Espera unos minutos e inténtalo de nuevo.'),
            ]);
        }
        RateLimiter::hit($throttleKey, 600);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Honeypot + Turnstile. Production prerequisites are fail-closed.
        $guardErrors = RegistrationGuard::check($request);
        if ($guardErrors !== []) {
            throw ValidationException::withMessages($guardErrors);
        }

        $email = mb_strtolower((string) $request->string('email'));
        app(Timebox::class)->call(function () use ($request, $email): void {
            $user = null;
            try {
                $user = User::query()->create([
                    'name' => $request->name,
                    'email' => $email,
                    'password' => Hash::make($request->password),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Intentionally indistinguishable from a newly created account.
            }

            $userId = 0;
            $authVersion = -1;
            if ($user !== null) {
                $user->refresh();
                $userId = (int) $user->getKey();
                $authVersion = (int) ($user->auth_version ?? 0);
            }

            SendRegistrationVerification::dispatch($userId, $email, $authVersion, app()->getLocale());
        }, 350_000);
        // NB: do NOT clear the limiter on success — the cap is accounts-per-IP
        // per window, so a bot that successfully creates accounts stays limited.

        return redirect('/login')->with(
            'status',
            __('Si el registro puede completarse, recibirás un correo para verificar la cuenta.'),
        );
    }
}
