<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

test('legacy recaller cannot authenticate and is expired before auth version resolution', function () {
    $user = User::factory()->create(['remember_token' => 'synthetic-recaller']);
    $name = Auth::guard('web')->getRecallerName();
    $recaller = $user->getAuthIdentifier().'|synthetic-recaller|'.$user->getAuthPassword();
    $this->withCookie($name, $recaller)->get('/profile')->assertRedirect('/login')->assertCookieExpired($name);
    $this->assertGuest();
});

test('removing recaller preserves authenticated session and csrf token', function () {
    $user = User::factory()->create();
    $name = Auth::guard('web')->getRecallerName();
    Route::middleware('web')->get('/_test/consent-session', function () use ($name) {
        return response()->json([
            'user' => request()->user()?->id,
            'token' => request()->session()->token(),
            'marker' => request()->session()->get('marker'),
            'recaller_present' => request()->cookies->has($name),
        ]);
    });
    $response = $this->actingAs($user)->withSession(['_token' => 'synthetic-csrf', 'marker' => 'preserved', 'auth_version' => (int) $user->auth_version])
        ->withCookie($name, 'old-cookie')->get('/_test/consent-session');
    $response->assertOk()->assertJson(['user' => $user->id, 'token' => 'synthetic-csrf', 'marker' => 'preserved', 'recaller_present' => false])
        ->assertCookieExpired($name)->assertCookie('XSRF-TOKEN')->assertCookie(config('session.cookie'));
    $this->assertAuthenticatedAs($user);
});

test('password login ignores a supplied remember flag and keeps normal session', function () {
    $user = User::factory()->create();
    $name = Auth::guard('web')->getRecallerName();
    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
    $response->assertRedirect('/dashboard')->assertCookieMissing($name)->assertCookie(config('session.cookie'));
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->remember_token)->toBe($user->remember_token);
});
