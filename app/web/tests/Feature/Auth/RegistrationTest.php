<?php

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('laravel register alias renders csrf form without replacing canonical route name', function () {
    $response = $this->get('/laravel/register');

    $response->assertStatus(200);
    $response->assertSee('name="_token"', false);
    expect(route('register', absolute: false))->toBe('/register');
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('characterization.create', absolute: false));
});

test('new users can register using the laravel register alias', function () {
    $response = $this->post('/laravel/register', [
        'name' => 'Alias Test User',
        'email' => 'alias-test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('characterization.create', absolute: false));
});
