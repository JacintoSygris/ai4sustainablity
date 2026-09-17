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
