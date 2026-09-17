<?php

use App\Models\User;

test('web responses include production baseline security headers', function () {
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
});

test('authenticated JSON API responses include production baseline security headers', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/auth/session');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
});
