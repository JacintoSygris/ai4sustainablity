<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('exposes the current Laravel browser session for the Next frontend', function () {
    $user = User::factory()->create([
        'email' => 'frontend-session@example.test',
        'name' => 'Frontend Session User',
    ]);

    $this->actingAs($user)
        ->getJson('/api/auth/session')
        ->assertOk()
        ->assertJsonPath('data.authenticated', true)
        ->assertJsonPath('data.user.email', 'frontend-session@example.test')
        ->assertJsonPath('data.user.name', 'Frontend Session User')
        ->assertJsonPath('data.csrf_header', 'X-XSRF-TOKEN')
        ->assertCookie('XSRF-TOKEN')
        ->assertJsonStructure([
            'data' => [
                'csrf_token',
                'user' => [
                    'id',
                    'name',
                    'email',
                ],
            ],
        ]);
});

it('requires authentication for the frontend session endpoint', function () {
    $this->getJson('/api/auth/session')
        ->assertUnauthorized();
});

it('serves workflow APIs through Laravel web session middleware for browser clients', function () {
    $middleware = Route::getRoutes()->getByName('api.workflow.show')?->gatherMiddleware() ?? [];

    expect($middleware)
        ->toContain('web')
        ->toContain('auth');
});
