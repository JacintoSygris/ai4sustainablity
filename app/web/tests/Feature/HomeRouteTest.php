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

test('private dev auto login opens the characterization workspace without Laravel login', function () {
    config([
        'services.private_dev.auto_login' => true,
        'services.private_dev.user_email' => 'i4sdev@i4s.local',
        'services.private_dev.user_name' => 'I4S Dev',
    ]);

    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->get(route('characterization.create'))
        ->assertOk()
        ->assertSee('form_data[company_profile][company_name]', false);

    $this->assertAuthenticated();
    expect(User::where('email', 'i4sdev@i4s.local')->exists())->toBeTrue();
});

test('private dev auth screens redirect to the characterization workspace', function () {
    config([
        'services.private_dev.auto_login' => true,
        'services.private_dev.user_email' => 'i4sdev@i4s.local',
        'services.private_dev.user_name' => 'I4S Dev',
    ]);

    $this->get(route('login'))
        ->assertRedirect(route('characterization.create', absolute: false));

    $this->get(route('register'))
        ->assertRedirect(route('characterization.create', absolute: false));

    $this->seed(\Database\Seeders\NaceCodeSeeder::class);
    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->followingRedirects()
        ->get(route('login'))
        ->assertOk()
        ->assertSee('name="_token"', false)
        ->assertCookie('XSRF-TOKEN');

    $this->assertAuthenticated();
});
