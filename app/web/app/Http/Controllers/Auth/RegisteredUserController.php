<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RegistrationGuard;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
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
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Honeypot + Turnstile (fail-open when unconfigured; see RegistrationGuard).
        $guardErrors = RegistrationGuard::check($request);
        if ($guardErrors !== []) {
            throw ValidationException::withMessages($guardErrors);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Sends the verification email when the mail driver is real (prod).
        event(new Registered($user));
        // NB: do NOT clear the limiter on success — the cap is accounts-per-IP
        // per window, so a bot that successfully creates accounts stays limited.

        Auth::login($user);

        // With verification enforced, unverified users land on the notice; the
        // Next frontend detects the unverified session and shows the "verify
        // your email" screen. Without enforcement, straight to the dashboard.
        if (config('services.auth_hardening.require_email_verification') && ! $user->hasVerifiedEmail()) {
            return redirect('/verify-email');
        }

        return redirect('/dashboard');
    }
}
