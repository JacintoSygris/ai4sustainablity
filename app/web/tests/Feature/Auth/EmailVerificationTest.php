<?php

use App\Jobs\SendRegistrationVerification;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('the frontend verification alias provides csrf and sends a rate limited notification', function () {
    Queue::fake();
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $page = $this->actingAs($user)->get('/laravel/email/verification-notification');
    $page->assertOk()->assertSee('name="_token"', false);

    $this->actingAs($user)
        ->post('/laravel/email/verification-notification')
        ->assertRedirect();

    Queue::assertPushed(SendRegistrationVerification::class);
    Notification::assertNothingSent();
});

test('a delayed verification job cannot transfer to a replacement account', function () {
    Queue::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('/email/verification-notification')->assertRedirect();
    $job = null;
    Queue::assertPushed(SendRegistrationVerification::class, function (SendRegistrationVerification $queued) use (&$job): bool {
        $job = $queued;

        return true;
    });

    $email = $user->email;
    $user->delete();
    User::factory()->unverified()->create(['email' => $email]);
    Notification::fake();
    $job->handle();

    Notification::assertNothingSent();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
        absolute: false,
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
        absolute: false,
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('unverified users cannot retry or export characterization summaries', function () {
    config(['services.auth_hardening.require_email_verification' => true]);
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->postJson('/characterization/retry')->assertStatus(409);
    $this->actingAs($user)->getJson('/characterization/summary')->assertStatus(409);
});
