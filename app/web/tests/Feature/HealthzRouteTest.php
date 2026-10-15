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
        'app_release',
        'db',
        'queue_recent',
        'time',
    ]);

    $json = $response->json();

    expect($json['status'])->toBe('ok');
    expect($json['db'])->toBe('ok');
    expect($json['queue_recent'])->toBeIn(['ok', 'unknown']);
    expect($json['app_release'])->toBeString()->not->toBe('');
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

    // Only the five documented keys are present (no accidental config leakage).
    $keys = array_keys($this->getJson('/healthz')->json());
    sort($keys);
    expect($keys)->toBe(['app_release', 'db', 'queue_recent', 'status', 'time']);
});

test('health probe accepts the configured trusted host and rejects the loopback host', function () {
    config([
        'app.url' => 'https://sostenibilidad.example.org',
        'app.enforce_trusted_hosts' => true,
        'app.trusted_hosts' => ['sostenibilidad.example.org'],
    ]);
    try {
        // Symfony's test request takes Host from the URI, unlike curl's separate
        // transport address and Host header. Both requests stay in-process.
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->getJson('http://sostenibilidad.example.org:8080/healthz')->assertOk()->assertJsonPath('db', 'ok');

        $this->getJson('http://127.0.0.1:8080/healthz')->assertStatus(400);
    } finally {
        \Illuminate\Http\Request::setTrustedHosts([]);
    }
});
