<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('registration screen restores Figma social registration options', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
    $response->assertSee('Te damos la bienvenida a Airis');
    $response->assertSee('Regístrate con Google');
    $response->assertSee('Regístrate con Microsoft');
    $response->assertSee('>O<', false);
    $response->assertDontSee('Laravel');
});

test('unconfigured social registration options are not clickable links', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
    $response->assertSee('disabled', false);
    $response->assertDontSee('href="'.route('social.redirect', ['provider' => 'google'], false).'"', false);
    $response->assertDontSee('href="'.route('social.redirect', ['provider' => 'microsoft'], false).'"', false);
});

test('laravel register alias renders csrf form without replacing canonical route name', function () {
    $response = $this->get('/laravel/register');

    $response->assertStatus(200);
    $response->assertSee('name="_token"', false);
    expect(route('register', absolute: false))->toBe('/register');
});

test('registration validation errors are Spanish and product neutral', function () {
    config(['app.locale' => 'es']);

    $response = $this->from('/register')->post('/register', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'different',
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors(['name', 'email', 'password']);

    $messages = implode(' ', session('errors')->getBag('default')->all());

    expect($messages)->toContain('nombre');
    expect($messages)->toContain('correo electrónico');
    expect($messages)->toContain('confirmación');
    expect($messages)->not->toContain('field');
    expect($messages)->not->toContain('Laravel');
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');
});

test('new users can register using the laravel register alias', function () {
    $response = $this->post('/laravel/register', [
        'name' => 'Alias Test User',
        'email' => 'alias-test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');
});

test('the register-config endpoint is public and reports guard state', function () {
    config([
        'services.auth_hardening.turnstile.site_key' => 'test-site-key',
        'services.auth_hardening.require_email_verification' => true,
        'services.auth_hardening.honeypot_field' => 'company_website',
    ]);

    $this->getJson('/api/auth/register-config')
        ->assertOk()
        ->assertJsonPath('data.turnstile_site_key', 'test-site-key')
        ->assertJsonPath('data.require_email_verification', true)
        ->assertJsonPath('data.honeypot_field', 'company_website');
});

test('a filled honeypot silently blocks registration', function () {
    config(['services.auth_hardening.honeypot_field' => 'company_website']);

    $this->post('/register', [
        'name' => 'Bot',
        'email' => 'bot@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'company_website' => 'http://spam.example',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(\App\Models\User::where('email', 'bot@example.com')->exists())->toBeFalse();
});

test('a configured Turnstile blocks registration without a valid token', function () {
    config([
        'services.auth_hardening.turnstile.site_key' => 'sk',
        'services.auth_hardening.turnstile.secret' => 'secret',
    ]);
    \Illuminate\Support\Facades\Http::fake([
        '*siteverify*' => \Illuminate\Support\Facades\Http::response(['success' => false], 200),
    ]);

    $this->post('/register', [
        'name' => 'Human?',
        'email' => 'noturnstile@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'cf-turnstile-response' => 'bad-token',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a configured Turnstile allows registration with a valid token', function () {
    config([
        'services.auth_hardening.turnstile.site_key' => 'sk',
        'services.auth_hardening.turnstile.secret' => 'secret',
    ]);
    \Illuminate\Support\Facades\Http::fake([
        '*siteverify*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200),
    ]);

    $this->post('/register', [
        'name' => 'Real Human',
        'email' => 'good@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'cf-turnstile-response' => 'good-token',
    ]);

    $this->assertAuthenticated();
});

test('registration is rate limited per ip', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('/register', [
            'name' => "User $i",
            'email' => "rl$i@example.com",
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        auth()->logout();
    }

    $this->post('/register', [
        'name' => 'Over Limit',
        'email' => 'overlimit@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(\App\Models\User::where('email', 'overlimit@example.com')->exists())->toBeFalse();
});

test('when verification is enforced a new user lands on the verify screen and cannot reach protected APIs', function () {
    config(['services.auth_hardening.require_email_verification' => true]);

    $this->post('/register', [
        'name' => 'Unverified',
        'email' => 'unverified@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect('/verify-email');

    // session is still readable and reports the unverified state
    $this->getJson('/api/auth/session')
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', false)
        ->assertJsonPath('data.require_email_verification', true);

    // a protected API route is blocked with 409, not silently served
    $this->getJson('/api/characterization/options')->assertStatus(409);
});

test('session reports verified true for a verified user', function () {
    $user = \App\Models\User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->getJson('/api/auth/session')
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', true);
});
