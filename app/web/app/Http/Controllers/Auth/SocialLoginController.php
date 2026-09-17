<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class SocialLoginController extends Controller
{
    private const PROVIDERS = [
        'google' => 'Google',
        'microsoft' => 'Microsoft',
    ];

    public function redirect(string $provider): RedirectResponse
    {
        $this->abortUnknownProvider($provider);

        if (! $this->isConfigured($provider)) {
            return $this->unconfiguredProvider($provider);
        }

        try {
            return $this->socialite()::driver($provider)->redirect();
        } catch (Throwable) {
            return $this->unconfiguredProvider($provider);
        }
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->abortUnknownProvider($provider);

        if (! $this->isConfigured($provider)) {
            return $this->unconfiguredProvider($provider);
        }

        try {
            $socialUser = $this->socialite()::driver($provider)->user();
            $email = $socialUser->getEmail();

            if (! $email) {
                return redirect()->route('login')->with('status', 'No se ha podido obtener el email de la cuenta social.');
            }

            $user = User::firstOrNew(['email' => $email]);
            $user->name = $user->name ?: ($socialUser->getName() ?: $email);
            $user->password = $user->password ?: Hash::make(Str::random(40));
            $user->email_verified_at = $user->email_verified_at ?: now();
            $user->save();

            Auth::login($user, remember: true);

            return redirect('/dashboard');
        } catch (Throwable) {
            return redirect()->route('login')->with('status', 'No se ha podido completar el inicio de sesión social.');
        }
    }

    private function abortUnknownProvider(string $provider): void
    {
        abort_unless(array_key_exists($provider, self::PROVIDERS), 404);
    }

    private function isConfigured(string $provider): bool
    {
        if (! config('services.social_login.enabled')) {
            return false;
        }

        if (! class_exists('Laravel\\Socialite\\Facades\\Socialite')) {
            return false;
        }

        return filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"))
            && filled(config("services.{$provider}.redirect"));
    }

    /**
     * @return class-string
     */
    private function socialite(): string
    {
        return 'Laravel\\Socialite\\Facades\\Socialite';
    }

    private function unconfiguredProvider(string $provider): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->with('status', 'El inicio de sesión con '.self::PROVIDERS[$provider].' no está configurado en esta demo.');
    }
}
