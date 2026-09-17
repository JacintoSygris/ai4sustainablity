<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('login screen keeps the Airis visual shell while using Laravel csrf', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
    $response->assertSee('Airis');
    $response->assertSee('By Sygris');
    $response->assertSee('Necesitas ayuda');
    $response->assertSee('Español');
    $response->assertSee('Iniciar sesión');
    $response->assertSee('nombre@empresa.com', false);
    $response->assertSee('name="_token"', false);
    $response->assertDontSee('Remember me');
    $response->assertDontSee('Laravel');
});

test('login screen restores Figma social login options', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
    $response->assertSee('Iniciar sesión con Google');
    $response->assertSee('Iniciar sesión con Microsoft');
    $response->assertSee('>O<', false);
});

test('unconfigured social login options are not clickable links', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
    $response->assertSee('disabled', false);
    $response->assertDontSee('href="'.route('social.redirect', ['provider' => 'google'], false).'"', false);
    $response->assertDontSee('href="'.route('social.redirect', ['provider' => 'microsoft'], false).'"', false);
});

test('social login fails closed when provider is not configured', function () {
    $response = $this->from('/login')->get('/auth/google/redirect');

    $this->assertGuest();
    $response->assertRedirect('/login');
    $response->assertSessionHas('status', 'El inicio de sesión con Google no está configurado en esta demo.');
});

test('login validation errors are Spanish and product neutral', function () {
    config(['app.locale' => 'es']);

    $response = $this->from('/login')->post('/login', [
        'email' => '',
        'password' => '',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors(['email', 'password']);

    $messages = implode(' ', session('errors')->getBag('default')->all());

    expect($messages)->toContain('correo electrónico');
    expect($messages)->toContain('obligatorio');
    expect($messages)->not->toContain('field');
    expect($messages)->not->toContain('Laravel');
});

test('invalid login error is Spanish and product neutral', function () {
    config(['app.locale' => 'es']);

    $user = User::factory()->create();

    $response = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors(['email']);

    $message = session('errors')->first('email');

    expect($message)->toBe('Estas credenciales no coinciden con nuestros registros.');
    expect($message)->not->toContain('Laravel');
});

test('configured google social login redirects to google oauth', function () {
    config([
        'services.social_login.enabled' => true,
        'services.google.client_id' => 'google-client-id',
        'services.google.client_secret' => 'google-client-secret',
        'services.google.redirect' => 'https://i4s.ueporreres.com/auth/google/callback',
    ]);

    $response = $this->get('/auth/google/redirect');

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->not->toBe(url('/login'));
    expect($location)->toContain('accounts.google.com');
    expect($location)->toContain('google-client-id');
});

test('configured microsoft social login redirects to microsoft oauth', function () {
    config([
        'services.social_login.enabled' => true,
        'services.microsoft.client_id' => 'microsoft-client-id',
        'services.microsoft.client_secret' => 'microsoft-client-secret',
        'services.microsoft.redirect' => 'https://i4s.ueporreres.com/auth/microsoft/callback',
        'services.microsoft.tenant' => 'common',
    ]);

    $response = $this->get('/auth/microsoft/redirect');

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    expect($location)->not->toBe(url('/login'));
    expect($location)->toContain('microsoft');
    expect($location)->toContain('microsoft-client-id');
});

test('laravel login alias renders csrf form without replacing canonical route name', function () {
    $response = $this->get('/laravel/login');

    $response->assertStatus(200);
    $response->assertSee('name="_token"', false);
    expect(route('login', absolute: false))->toBe('/login');
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');
});

test('users can authenticate using the laravel login alias', function () {
    $user = User::factory()->create();

    $response = $this->post('/laravel/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
