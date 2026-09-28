<?php

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Support\CanonicalPublicUrl;
use App\Support\PasswordResetGuard;
use App\Support\SensitiveDeliveryGuard;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('laravel reset password alias renders csrf form without replacing canonical route name', function () {
    $this->get('/laravel/forgot-password')
        ->assertStatus(200)
        ->assertSee('name="_token"', false)
        ->assertSee('/forgot-password', false);

    expect(route('password.request', absolute: false))->toBe('/forgot-password');
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('password reset responses do not reveal whether an account exists', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->post('/forgot-password', ['email' => $user->email]);
    $knownStatus = $known->getSession()->get('status');
    $unknown = $this->post('/forgot-password', ['email' => 'absent@example.test']);

    $known->assertSessionHasNoErrors()->assertSessionHas('status');
    $unknown->assertSessionHasNoErrors()->assertSessionHas('status');
    expect($knownStatus)->toBe($unknown->getSession()->get('status'));
});

test('password reset requests are rate limited on canonical and Laravel alias routes', function () {
    foreach (['/forgot-password', '/laravel/forgot-password'] as $path) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post($path, ['email' => 'absent-'.$attempt.'@example.test'])
                ->assertRedirect();
        }

        $this->post($path, ['email' => 'blocked@example.test'])
            ->assertTooManyRequests();

        $this->app->make('cache')->flush();
    }
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password?email=person%40example.test');

        $response->assertStatus(200)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('window.location.hash', false)
            ->assertDontSee($notification->token);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password reset requests enqueue identical work without synchronous account lookup', function () {
    Queue::fake();
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->post('/forgot-password', ['email' => 'absent@example.test']);

    $known->assertSessionHasNoErrors()->assertSessionHas('status');
    $unknown->assertSessionHasNoErrors()->assertSessionHas('status');
    expect($known->getSession()->get('status'))->toBe($unknown->getSession()->get('status'));
    Queue::assertPushed(SendPasswordResetLink::class, 2);
    Notification::assertNothingSent();
});

test('production password reset rejects non durable queue fallbacks and mailers that log bearer links', function () {
    $this->app['env'] = 'production';
    config([
        'app.url' => 'https://example.com',
        'app.trusted_hosts' => ['example.com'],
        'services.auth_hardening.password_reset_enabled' => true,
        'queue.default' => 'failover',
        'queue.connections.failover' => [
            'driver' => 'failover',
            'connections' => ['database', 'deferred'],
        ],
        'mail.default' => 'failover',
        'mail.mailers.failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
        ],
    ]);

    expect(PasswordResetGuard::available())->toBeFalse();
});

test('password reset links use the canonical application origin', function () {
    config(['app.url' => 'https://example.com']);
    URL::forceRootUrl('https://attacker.example');
    $user = User::factory()->create(['email' => 'canonical@example.test']);

    $mail = (new ResetPassword('opaque-token'))->toMail($user);

    expect($mail->actionUrl)->toStartWith('https://example.com/reset-password?email=canonical%40example.test#token=opaque-token')
        ->not->toContain('/reset-password/opaque-token')
        ->not->toContain('attacker.example');
});

test('reset bearer and anti abuse token are never flashed into the session on validation failure', function () {
    $response = $this->from('/reset-password')->post('/reset-password', [
        'token' => 'sensitive-reset-bearer',
        'cf-turnstile-response' => 'sensitive-turnstile-bearer',
        'email' => 'person@example.test',
        'password' => 'short',
        'password_confirmation' => 'different',
    ]);

    $response->assertRedirect('/reset-password');
    $oldInput = $response->getSession()->getOldInput();
    expect($oldInput)->not->toHaveKey('token')
        ->and($oldInput)->not->toHaveKey('cf-turnstile-response');
});

test('issued reset records bind the bearer to immutable account epoch and generation', function () {
    Notification::fake();
    $user = User::factory()->create(['auth_version' => 7]);

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        expect($record)->not->toBeNull()
            ->and((int) $record->user_id)->toBe((int) $user->id)
            ->and((int) $record->auth_version)->toBe(7)
            ->and($record->generation)->not->toBeNull()
            ->and(hash_equals($record->token_fingerprint, hash('sha256', $notification->token)))->toBeTrue();

        return true;
    });
});

test('opening a new reset generation revokes an already issued bearer synchronously', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email]);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

    Queue::fake();
    $this->post('/forgot-password', ['email' => $user->email]);

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
});

test('reset redemption rejects a correctly hashed bearer from an earlier account epoch', function () {
    Notification::fake();
    $user = User::factory()->create(['auth_version' => 0]);
    $token = null;
    $this->post('/forgot-password', ['email' => $user->email]);
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });
    User::query()->whereKey($user->id)->increment('auth_version');

    $this->from('/reset-password')->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'replacement-password',
        'password_confirmation' => 'replacement-password',
    ])->assertRedirect('/reset-password')->assertSessionHasErrors('email');

    expect(Hash::check('replacement-password', $user->fresh()->password))->toBeFalse();
});

test('an untrusted host is rejected before rendering authentication pages', function () {
    config([
        'app.enforce_trusted_hosts' => true,
        'app.trusted_hosts' => ['example.com'],
    ]);

    $this->withServerVariables(['HTTP_HOST' => 'attacker.example'])
        ->get('/login')
        ->assertBadRequest();
});

test('the canonical origin rejects embedded credentials in production', function () {
    $this->app['env'] = 'production';
    config([
        'app.url' => 'https://operator@example.com',
        'app.trusted_hosts' => ['example.com'],
    ]);

    expect(fn () => CanonicalPublicUrl::root())->toThrow(LogicException::class);
});

test('reset redemption never reveals whether an email belongs to an account', function () {
    $user = User::factory()->create();

    $known = $this->from('/reset-password')->post('/reset-password', [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'replacement-password',
        'password_confirmation' => 'replacement-password',
    ]);
    $knownError = session('errors')->get('email');

    $unknown = $this->from('/reset-password')->post('/reset-password', [
        'token' => 'invalid-token',
        'email' => 'absent@example.test',
        'password' => 'replacement-password',
        'password_confirmation' => 'replacement-password',
    ]);
    $unknownError = session('errors')->get('email');

    $known->assertRedirect('/reset-password');
    $unknown->assertRedirect('/reset-password');
    expect($knownError)->toBe($unknownError);
});

test('a delayed reset job cannot mint a token after the account epoch changes', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
    $job = null;
    Queue::assertPushed(SendPasswordResetLink::class, function (SendPasswordResetLink $queued) use (&$job): bool {
        $job = $queued;

        return true;
    });

    User::query()->whereKey($user->id)->increment('auth_version');
    $job->handle();

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
});

test('reset issuance and redemption fail closed when password reset is disabled', function () {
    config(['services.auth_hardening.password_reset_enabled' => false]);

    $this->get('/reset-password/token')->assertNotFound();
    $this->post('/reset-password', [
        'token' => 'token',
        'email' => 'person@example.test',
        'password' => 'replacement-password',
        'password_confirmation' => 'replacement-password',
    ])->assertNotFound();
});

test('secret-bearing smtp delivery requires mandatory tls', function () {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => 'smtp', 'require_tls' => false],
    ]);
    expect(SensitiveDeliveryGuard::mailerProtectsSecrets())->toBeFalse();

    config(['mail.mailers.smtp.require_tls' => true]);
    expect(SensitiveDeliveryGuard::mailerProtectsSecrets())->toBeTrue();

    config(['mail.mailers.smtp' => ['transport' => 'smtp', 'scheme' => 'smtps', 'require_tls' => false]]);
    expect(SensitiveDeliveryGuard::mailerProtectsSecrets())->toBeTrue();
});

test('a superseded reset request cannot mint a token', function () {
    Queue::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);
    $first = null;
    Queue::assertPushed(SendPasswordResetLink::class, function (SendPasswordResetLink $queued) use (&$first): bool {
        $first ??= $queued;

        return true;
    });
    Queue::fake();
    $this->post('/forgot-password', ['email' => $user->email]);

    $first->handle();

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
});

test('a queued reset request cannot mint a token after the feature is disabled', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email]);
    $job = null;
    Queue::assertPushed(SendPasswordResetLink::class, function (SendPasswordResetLink $queued) use (&$job): bool {
        $job = $queued;

        return true;
    });
    config(['services.auth_hardening.password_reset_enabled' => false]);

    $job->handle();

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
});
