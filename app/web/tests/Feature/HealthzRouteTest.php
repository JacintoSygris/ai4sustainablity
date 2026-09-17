<?php

use Illuminate\Support\Facades\Route;

test('healthz responds 200 without authentication', function () {
    // No actingAs(): the probe must be reachable by an anonymous uptime monitor.
    $response = $this->getJson('/healthz');

    $response->assertOk();
});

test('healthz returns the documented shape and healthy states in test env', function () {
    $response = $this->getJson('/healthz');

    $response->assertOk();

    $response->assertJsonStructure([
        'status',
        'db',
        'queue_recent',
        'time',
    ]);

    $json = $response->json();

    expect($json['status'])->toBe('ok');
    expect($json['db'])->toBe('ok');
    expect($json['queue_recent'])->toBeIn(['ok', 'unknown']);
    expect($json['time'])->toBeString()->not->toBe('');
});

test('healthz route carries no auth middleware', function () {
    $route = Route::getRoutes()->getByName('healthz');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    expect($middleware)
        ->not->toContain('auth')
        ->not->toContain('verified');
});

test('healthz does not leak framework name or secret-like values', function () {
    $body = $this->getJson('/healthz')->getContent();

    // No backend framework name in the public probe body.
    expect(strtolower($body))->not->toContain('laravel');

    // Only the four documented keys are present (no accidental config leakage).
    $keys = array_keys($this->getJson('/healthz')->json());
    sort($keys);
    expect($keys)->toBe(['db', 'queue_recent', 'status', 'time']);
});
