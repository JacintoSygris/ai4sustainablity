<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('guest home page redirects to login instead of the Laravel welcome screen', function () {
    $this->get('/')
        ->assertRedirect(route('login', absolute: false));
});

test('authenticated home page redirects to the Next dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/dashboard');
});

test('dashboard fallback does not render the retired Blade characterization wizard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect('/wizard/step-1');
});

test('dashboard route does not advertise email verification gating', function () {
    $middleware = Route::getRoutes()->getByName('dashboard')?->gatherMiddleware() ?? [];

    expect($middleware)
        ->toContain('auth')
        ->not->toContain('verified');
});

test('private dev auto login redirects auth screens to the Next dashboard', function () {
    config([
        'services.private_dev.auto_login' => true,
        'services.private_dev.user_email' => 'i4sdev@i4s.local',
        'services.private_dev.user_name' => 'I4S Dev',
    ]);

    $this->get(route('login'))
        ->assertRedirect('/dashboard');
});

test('private dev auto login is ignored in production', function () {
    config([
        'services.private_dev.auto_login' => true,
        'services.private_dev.user_email' => 'i4sdev@i4s.local',
        'services.private_dev.user_name' => 'I4S Dev',
    ]);
    app()->detectEnvironment(fn () => 'production');

    $this->getJson('/api/auth/session')->assertUnauthorized();
    $this->get(route('login'))->assertOk();
    // Production registration remains fail-closed until every delivery and
    // anti-abuse prerequisite is configured; auto-login must not bypass it.
    $this->get(route('register'))->assertNotFound();

    expect(User::where('email', 'i4sdev@i4s.local')->exists())->toBeFalse();
    $this->assertGuest();
});

test('private dev register screen redirects to the Next dashboard', function () {
    config([
        'services.private_dev.auto_login' => true,
        'services.private_dev.user_email' => 'i4sdev@i4s.local',
        'services.private_dev.user_name' => 'I4S Dev',
    ]);

    $this->get(route('register'))
        ->assertRedirect('/dashboard');
});
