<?php

namespace App\Http\Controllers\Auth;

use App\Auth\OAuthIdentityDescriptor;
use App\Auth\OAuthIdentityResolver;
use App\Http\Controllers\Controller;
use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SocialLoginController extends Controller
{
    private const INTENT_SESSION_KEY = 'oauth_intent';

    private const INTENT_TTL_SECONDS = 600;

    private const PASSWORD_CONFIRM_TTL_SECONDS = 900;

    private const PROVIDERS = [
        'google' => 'Google',
        'microsoft' => 'Microsoft',
    ];

    public function __construct(
        private readonly OAuthIdentityResolver $identityResolver,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $this->abortUnknownProvider($provider);

        if (! $this->isConfigured($provider)) {
            return $this->unconfiguredProvider($provider);
        }

        $request->session()->put(self::INTENT_SESSION_KEY, [
            'mode' => 'login',
            'provider' => $provider,
            'issued_at' => now()->timestamp,
        ]);

        try {
            return $this->socialite()::driver($provider)->redirect();
        } catch (Throwable) {
            $request->session()->forget(self::INTENT_SESSION_KEY);

            return $this->unconfiguredProvider($provider);
        }
    }

    public function link(Request $request, string $provider): RedirectResponse
    {
        $this->abortUnknownProvider($provider);

        if (! $this->isConfigured($provider)) {
            return redirect()->route('profile.edit')->with('status', __('El proveedor social no está disponible.'));
        }

        $request->session()->put(self::INTENT_SESSION_KEY, [
            'mode' => 'link',
            'provider' => $provider,
            'user_id' => (int) $request->user()->getAuthIdentifier(),
            'auth_version' => (int) $request->user()->auth_version,
            'issued_at' => now()->timestamp,
        ]);

        try {
            return $this->socialite()::driver($provider)->redirect();
        } catch (Throwable) {
            $request->session()->forget(self::INTENT_SESSION_KEY);

            return redirect()->route('profile.edit')->with('status', __('No se ha podido iniciar la vinculación social.'));
        }
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->abortUnknownProvider($provider);

        if (! $this->isConfigured($provider)) {
            return $this->unconfiguredProvider($provider);
        }

        $intent = $request->session()->pull(self::INTENT_SESSION_KEY);
        if (! $this->validIntent($intent, $provider)) {
            return $this->oauthFailure($request, __('No se ha podido validar la solicitud de inicio de sesión social.'));
        }

        try {
            $socialUser = $this->socialite()::driver($provider)->user();
            $descriptor = $this->identityResolver->resolve($provider, $socialUser);

            if ($intent['mode'] === 'link') {
                return $this->completeLink($request, $intent, $descriptor);
            }

            return $this->completeLogin($request, $descriptor);
        } catch (Throwable) {
            return $this->oauthFailure($request, __('No se ha podido completar el inicio de sesión social.'));
        }
    }

    private function completeLogin(Request $request, OAuthIdentityDescriptor $descriptor): RedirectResponse
    {
        $identity = OAuthIdentity::query()
            ->where('identity_hash', OAuthIdentity::hashFor(
                $descriptor->provider,
                $descriptor->issuer,
                $descriptor->subject,
            ))
            ->first();

        if (! $identity || ! $this->sameIdentity($identity, $descriptor)) {
            return redirect()->route('login')->with('status', __('La identidad social no está vinculada a una cuenta.'));
        }

        Auth::login($identity->user, remember: false);
        $request->session()->regenerate();
        $request->session()->put('auth_version', (int) $identity->user->auth_version);

        return redirect('/dashboard');
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function completeLink(
        Request $request,
        array $intent,
        OAuthIdentityDescriptor $descriptor,
    ): RedirectResponse {
        $user = $request->user();
        $passwordConfirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        if (! $user
            || (int) $intent['user_id'] !== (int) $user->getAuthIdentifier()
            || ! $user->hasVerifiedEmail()
            || ! is_int($intent['auth_version'] ?? null)
            || (int) $intent['auth_version'] !== (int) $request->session()->get('auth_version', -1)
            || $passwordConfirmedAt < now()->subSeconds(self::PASSWORD_CONFIRM_TTL_SECONDS)->timestamp) {
            return redirect()->route('profile.edit')->with('status', __('La vinculación social ha caducado o no está autorizada.'));
        }

        $hash = OAuthIdentity::hashFor($descriptor->provider, $descriptor->issuer, $descriptor->subject);

        try {
            [$identity, $updatedUser] = DB::transaction(function () use ($user, $descriptor, $hash, $intent): array {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
                if (! $lockedUser
                    || ! $lockedUser->hasVerifiedEmail()
                    || (int) $lockedUser->auth_version !== (int) $intent['auth_version']) {
                    return [null, null];
                }

                $existing = OAuthIdentity::query()->where('identity_hash', $hash)->lockForUpdate()->first();
                if ($existing) {
                    return [$existing, null];
                }

                $identity = OAuthIdentity::query()->create([
                    'user_id' => $user->id,
                    'provider' => $descriptor->provider,
                    'issuer' => $descriptor->issuer,
                    'subject' => $descriptor->subject,
                ]);
                $lockedUser->forceFill([
                    'auth_version' => (int) $lockedUser->auth_version + 1,
                    'remember_token' => Str::random(60),
                    'password_reset_generation' => null,
                ])->save();
                DB::table('password_reset_tokens')->where('email', $lockedUser->email)->delete();

                return [$identity, $lockedUser];
            }, 3);
        } catch (QueryException) {
            $identity = OAuthIdentity::query()->where('identity_hash', $hash)->firstOrFail();
            $updatedUser = null;
        }

        if (! $identity) {
            return redirect()->route('profile.edit')->with('status', __('La vinculación social ha caducado o no está autorizada.'));
        }

        if ((int) $identity->user_id !== (int) $user->id || ! $this->sameIdentity($identity, $descriptor)) {
            return redirect()->route('profile.edit')->with('status', __('Esa identidad social ya está vinculada a otra cuenta.'));
        }

        if ($updatedUser) {
            Auth::setUser($updatedUser);
            $request->setUserResolver(static fn () => $updatedUser);
            $request->session()->put('auth_version', (int) $updatedUser->auth_version);
        }

        return redirect()->route('profile.edit')->with('status', __('Identidad social vinculada correctamente.'));
    }

    /**
     * @param  mixed  $intent
     */
    private function validIntent($intent, string $provider): bool
    {
        if (! is_array($intent)
            || ! in_array($intent['mode'] ?? null, ['login', 'link'], true)
            || ! hash_equals($provider, (string) ($intent['provider'] ?? ''))
            || ! is_int($intent['issued_at'] ?? null)) {
            return false;
        }

        $age = now()->timestamp - $intent['issued_at'];

        return $age >= -30 && $age <= self::INTENT_TTL_SECONDS
            && ($intent['mode'] !== 'link' || (
                is_int($intent['user_id'] ?? null)
                && is_int($intent['auth_version'] ?? null)
            ));
    }

    private function sameIdentity(OAuthIdentity $identity, OAuthIdentityDescriptor $descriptor): bool
    {
        return hash_equals($identity->provider, $descriptor->provider)
            && hash_equals($identity->issuer, $descriptor->issuer)
            && hash_equals($identity->subject, $descriptor->subject);
    }

    private function oauthFailure(Request $request, string $message): RedirectResponse
    {
        return redirect()->route($request->user() ? 'profile.edit' : 'login')->with('status', $message);
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

    /** @return class-string */
    private function socialite(): string
    {
        return 'Laravel\\Socialite\\Facades\\Socialite';
    }

    private function unconfiguredProvider(string $provider): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->with('status', __('El inicio de sesión con :provider no está configurado en esta demo.', ['provider' => self::PROVIDERS[$provider]]));
    }
}
