<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

test('password can be updated', function () {
    $user = User::factory()->create(['remember_token' => 'remember-before']);

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    expect($user->auth_version)->toBe(1)
        ->and($user->remember_token)->not->toBe('remember-before')
        ->and(session('auth_version'))->toBe(1);
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('updatePassword', 'current_password')
        ->assertRedirect('/profile');
});

test('updating a password revokes every outstanding password reset token', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->post('/logout');

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])->assertSessionHasErrors('email');
});

test('a stale authenticated session is revoked after the credential epoch changes', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/dashboard');

    $this->get('/profile')->assertOk();
    User::query()->whereKey($user->id)->increment('auth_version');
    auth()->forgetUser();

    $this->get('/profile')->assertRedirect('/login');
    $this->assertGuest();
});
