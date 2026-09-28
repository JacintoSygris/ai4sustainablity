<?php

use App\Models\OAuthIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;
use SocialiteProviders\Microsoft\MicrosoftUser;

function configuredGoogleSocialLogin(): void
{
    config([
        'services.social_login.enabled' => true,
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.google.redirect' => 'https://example.com/auth/google/callback',
    ]);
}

function fakeGoogleSocialUser(string $subject, string $email): void
{
    $socialUser = (new SocialUser)->setRaw([
        'sub' => $subject,
        'email' => $email,
        'name' => 'Social User',
    ])->map([
        'id' => $subject,
        'email' => $email,
        'name' => 'Social User',
    ]);
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->once()->andReturn($socialUser);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
}

function configuredMicrosoftSocialLogin(): void
{
    config([
        'services.social_login.enabled' => true,
        'services.microsoft.client_id' => 'microsoft-client-id',
        'services.microsoft.client_secret' => 'microsoft-client-secret',
        'services.microsoft.redirect' => 'https://example.com/auth/microsoft/callback',
    ]);
}

/** @param array<string, mixed> $claims */
function fakeMicrosoftSocialUser(array $claims): void
{
    $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $idToken = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims).'.signature';
    $subject = (string) ($claims['oid'] ?? '');
    $socialUser = (new MicrosoftUser)->setRaw([
        'id' => $subject,
        'mail' => 'provider@example.test',
    ])->map([
        'id' => $subject,
        'email' => 'provider@example.test',
        'name' => 'Microsoft User',
    ])->setAccessTokenResponseBody(['id_token' => $idToken]);
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->once()->andReturn($socialUser);
    Socialite::shouldReceive('driver')->once()->with('microsoft')->andReturn($driver);
}

test('an unlinked social identity never logs into or creates a same-email account', function () {
    configuredGoogleSocialLogin();
    User::factory()->create(['email' => 'same@example.test']);
    fakeGoogleSocialUser('google-subject-1', 'same@example.test');

    $this->withSession([
        'oauth_intent' => [
            'mode' => 'login',
            'provider' => 'google',
            'issued_at' => now()->timestamp,
        ],
    ])->get('/auth/google/callback')
        ->assertRedirect('/login');

    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
    expect(OAuthIdentity::query()->count())->toBe(0);
});

test('a linked exact social identity logs into its owner even when provider email changes', function () {
    configuredGoogleSocialLogin();
    $user = User::factory()->create([
        'email' => 'local@example.test',
        'password' => Hash::make('password'),
    ]);
    OAuthIdentity::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'issuer' => 'https://accounts.google.com',
        'subject' => 'google-subject-2',
    ]);
    fakeGoogleSocialUser('google-subject-2', 'changed@example.test');
    $recallerName = \Illuminate\Support\Facades\Auth::guard('web')->getRecallerName();

    $this->withSession([
        'oauth_intent' => [
            'mode' => 'login',
            'provider' => 'google',
            'issued_at' => now()->timestamp,
        ],
    ])->get('/auth/google/callback')
        ->assertRedirect('/dashboard')
        ->assertCookieMissing($recallerName)
        ->assertCookie(config('session.cookie'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->email)->toBe('local@example.test');
});

test('an authenticated verified recently-confirmed user can link an unowned identity', function () {
    configuredGoogleSocialLogin();
    $user = User::factory()->create(['email_verified_at' => now()]);
    fakeGoogleSocialUser('google-subject-3', 'other@example.test');

    $this->actingAs($user)
        ->withSession([
            'auth_version' => (int) $user->auth_version,
            'auth.password_confirmed_at' => now()->timestamp,
            'oauth_intent' => [
                'mode' => 'link',
                'provider' => 'google',
                'user_id' => $user->id,
                'auth_version' => (int) $user->auth_version,
                'issued_at' => now()->timestamp,
            ],
        ])
        ->get('/auth/google/callback')
        ->assertRedirect('/profile');

    $this->assertDatabaseHas('oauth_identities', [
        'user_id' => $user->id,
        'provider' => 'google',
        'issuer' => 'https://accounts.google.com',
        'subject' => 'google-subject-3',
    ]);
    expect($user->fresh()->auth_version)->toBe(1)
        ->and(session('auth_version'))->toBe(1);
});

test('linking a social identity revokes every pending password reset bearer', function () {
    configuredGoogleSocialLogin();
    $user = User::factory()->create(['email_verified_at' => now(), 'password_reset_generation' => 'pending-generation']);
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('old-bearer'),
        'created_at' => now(),
    ]);
    fakeGoogleSocialUser('google-reset-revocation-subject', 'other@example.test');

    $this->actingAs($user)
        ->withSession([
            'auth_version' => (int) $user->auth_version,
            'auth.password_confirmed_at' => now()->timestamp,
            'oauth_intent' => [
                'mode' => 'link',
                'provider' => 'google',
                'user_id' => $user->id,
                'auth_version' => (int) $user->auth_version,
                'issued_at' => now()->timestamp,
            ],
        ])
        ->get('/auth/google/callback')
        ->assertRedirect('/profile');

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    expect($user->fresh()->password_reset_generation)->toBeNull();
});

test('an OAuth callback never links an unverified or stale account even when global verification is disabled', function () {
    configuredGoogleSocialLogin();
    config(['services.auth_hardening.require_email_verification' => false]);
    $user = User::factory()->unverified()->create();
    fakeGoogleSocialUser('google-unverified-subject', 'other@example.test');

    $this->actingAs($user)
        ->withSession([
            'auth_version' => (int) $user->auth_version,
            'auth.password_confirmed_at' => now()->timestamp,
            'oauth_intent' => [
                'mode' => 'link',
                'provider' => 'google',
                'user_id' => $user->id,
                'auth_version' => (int) $user->auth_version,
                'issued_at' => now()->timestamp,
            ],
        ])
        ->get('/auth/google/callback')
        ->assertRedirect('/profile');

    expect(OAuthIdentity::query()->count())->toBe(0);
});

test('an OAuth link callback revalidates the locked account epoch', function () {
    configuredGoogleSocialLogin();
    $user = User::factory()->create(['email_verified_at' => now(), 'auth_version' => 0]);
    fakeGoogleSocialUser('google-stale-epoch-subject', 'other@example.test');
    User::query()->whereKey($user->id)->increment('auth_version');

    $this->actingAs($user)
        ->withSession([
            'auth_version' => 0,
            'auth.password_confirmed_at' => now()->timestamp,
            'oauth_intent' => [
                'mode' => 'link',
                'provider' => 'google',
                'user_id' => $user->id,
                'auth_version' => 0,
                'issued_at' => now()->timestamp,
            ],
        ])
        ->get('/auth/google/callback')
        ->assertRedirect('/profile');

    expect(OAuthIdentity::query()->count())->toBe(0);
});

test('a link intent cannot reassign an identity owned by another user', function () {
    configuredGoogleSocialLogin();
    $owner = User::factory()->create();
    $attacker = User::factory()->create(['email_verified_at' => now()]);
    OAuthIdentity::query()->create([
        'user_id' => $owner->id,
        'provider' => 'google',
        'issuer' => 'https://accounts.google.com',
        'subject' => 'google-subject-4',
    ]);
    fakeGoogleSocialUser('google-subject-4', $attacker->email);

    $this->actingAs($attacker)
        ->withSession([
            'auth_version' => (int) $attacker->auth_version,
            'auth.password_confirmed_at' => now()->timestamp,
            'oauth_intent' => [
                'mode' => 'link',
                'provider' => 'google',
                'user_id' => $attacker->id,
                'auth_version' => (int) $attacker->auth_version,
                'issued_at' => now()->timestamp,
            ],
        ])
        ->get('/auth/google/callback')
        ->assertRedirect('/profile');

    expect(OAuthIdentity::query()->firstOrFail()->user_id)->toBe($owner->id);
});

test('social identities are linked from the authenticated profile and never offered as registration', function () {
    configuredGoogleSocialLogin();
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get('/profile')
        ->assertOk()
        ->assertSee('/profile/oauth-identities/google/redirect', false)
        ->assertSee('Vincular Google');

    auth()->logout();
    $this->get('/register')
        ->assertOk()
        ->assertDontSee('Regístrate con Google')
        ->assertDontSee(route('social.redirect', ['provider' => 'google'], false), false);
});

test('Microsoft login binds the exact tenant issuer audience and object id', function () {
    configuredMicrosoftSocialLogin();
    $tenant = '11111111-2222-3333-4444-555555555555';
    $subject = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    $issuer = "https://login.microsoftonline.com/{$tenant}/v2.0";
    $user = User::factory()->create();
    OAuthIdentity::query()->create([
        'user_id' => $user->id,
        'provider' => 'microsoft',
        'issuer' => $issuer,
        'subject' => $subject,
    ]);
    fakeMicrosoftSocialUser([
        'tid' => $tenant,
        'oid' => $subject,
        'iss' => $issuer,
        'aud' => 'microsoft-client-id',
        'exp' => now()->addMinutes(5)->timestamp,
    ]);

    $this->withSession([
        'oauth_intent' => [
            'mode' => 'login',
            'provider' => 'microsoft',
            'issued_at' => now()->timestamp,
        ],
    ])->get('/auth/microsoft/callback')->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

test('Microsoft login rejects an identity token whose audience is not this application', function () {
    configuredMicrosoftSocialLogin();
    $tenant = '11111111-2222-3333-4444-555555555555';
    $subject = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    fakeMicrosoftSocialUser([
        'tid' => $tenant,
        'oid' => $subject,
        'iss' => "https://login.microsoftonline.com/{$tenant}/v2.0",
        'aud' => 'another-application',
        'exp' => now()->addMinutes(5)->timestamp,
    ]);

    $this->withSession([
        'oauth_intent' => [
            'mode' => 'login',
            'provider' => 'microsoft',
            'issued_at' => now()->timestamp,
        ],
    ])->get('/auth/microsoft/callback')->assertRedirect('/login');

    $this->assertGuest();
});
