<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('guest home page redirects to login instead of the Laravel welcome screen', function () {
    $this->get('/')
        ->assertRedirect(route('login', absolute: false));
});

test('authenticated home page redirects to the characterization workspace', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('characterization.create', absolute: false));
});

test('dashboard route redirects to the characterization workspace', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('characterization.create', absolute: false));
});

test('dashboard route does not advertise email verification gating', function () {
    $middleware = Route::getRoutes()->getByName('dashboard')?->gatherMiddleware() ?? [];

    expect($middleware)
        ->toContain('auth')
        ->not->toContain('verified');
});
