<?php

use App\Console\Commands\DeployCheck;
use App\Support\RegistrationGuard;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    $this->app['env'] = 'production';
    config([
        'app.env' => 'production',
        'app.url' => 'https://airis.sygris.com',
        'app.trusted_hosts' => ['airis.sygris.com', 'web', 'localhost', '127.0.0.1'],
        'app.enforce_trusted_hosts' => true,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.cipher' => 'AES-256-CBC',
        'services.auth_hardening.public_registration_enabled' => true,
        'services.auth_hardening.require_email_verification' => true,
        'services.auth_hardening.turnstile.site_key' => 'fixture-site-key',
        'services.auth_hardening.turnstile.secret' => 'fixture-turnstile-secret',
        'services.auth_hardening.turnstile.expected_hostname' => 'airis.sygris.com',
        'services.auth_hardening.turnstile.expected_action' => 'register',
        'services.auth_hardening.turnstile.verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'mail.default' => 'smtp',
        'mail.mailers.smtp' => [
            'transport' => 'smtp', 'scheme' => 'smtp', 'require_tls' => true,
            'host' => 'smtp.example.net', 'port' => 587,
            'username' => 'fixture-mail-user', 'password' => 'fixture-mail-password',
        ],
        'mail.from.address' => 'no-reply@example.net',
        'queue.default' => 'database',
    ]);
});

function runDeploymentCheck(): array
{
    $command = app(DeployCheck::class);
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $exit = $tester->execute([]);

    return [$exit, $tester->getDisplay()];
}

it('passes a complete deployment configuration without printing secret values', function () {
    [$exit, $output] = runDeploymentCheck();
    expect($exit)->toBe(0);
    foreach (['APP_URL', 'ENFORCE_TRUSTED_HOSTS', 'trusted_hosts', 'APP_KEY', 'DB_writable', 'migrations_current', 'registration', 'mailer_safe', 'smtp_configured', 'queue_durable'] as $check) {
        expect($output)->toContain('PASS '.$check);
    }
    foreach ([config('app.key'), 'fixture-site-key', 'fixture-turnstile-secret', 'fixture-mail-user', 'fixture-mail-password'] as $secret) {
        expect($output)->not->toContain($secret);
    }
    expect(RegistrationGuard::registrationAvailable())->toBeTrue();
});

it('is registered with Artisan', function () {
    $this->artisan('i4s:deploy:check')->expectsOutputToContain('PASS trusted_hosts')->assertSuccessful();
});

it('fails hard when production host enforcement is disabled despite a complete host list', function () {
    config(['app.enforce_trusted_hosts' => false]);
    [$exit, $output] = runDeploymentCheck();

    expect($exit)->toBe(1);
    expect($output)->toContain('PASS APP_URL')
        ->toContain('FAIL ENFORCE_TRUSTED_HOSTS')->toContain('FAIL trusted_hosts')
        ->not->toContain('PASS trusted_hosts');
});

it('fails hard for invalid origins, missing trusted hosts or invalid keys', function (string $key, mixed $value, string $check) {
    config([$key => $value]);
    [$exit, $output] = runDeploymentCheck();

    expect($exit)->toBe(1);
    expect($output)->toContain('FAIL '.$check);
})->with([
    ['app.url', '', 'APP_URL'],
    ['app.url', 'http://localhost', 'APP_URL'],
    ['app.url', 'https://example.com', 'APP_URL'],
    ['app.url', 'https://APP.EXAMPLE.ORG', 'APP_URL'],
    ['app.url', 'http://airis.sygris.com', 'APP_URL'],
    ['app.trusted_hosts', ['web'], 'trusted_hosts'],
    ['app.trusted_hosts', ['airis.sygris.com'], 'trusted_hosts'],
    ['app.key', '', 'APP_KEY'],
    ['app.key', 'invalid-key-fixture', 'APP_KEY'],
    ['app.key', 'base64:%%%', 'APP_KEY'],
]);

it('fails hard on a read only database without exposing an exception', function () {
    DB::statement('PRAGMA query_only = ON');
    try {
        [$exit, $output] = runDeploymentCheck();
        expect($exit)->toBe(1);
        expect($output)->toContain('FAIL DB_writable')->not->toContain('SQLSTATE');
    } finally {
        DB::statement('PRAGMA query_only = OFF');
    }
});

it('fails hard when migrations are pending', function () {
    $migration = DB::table('migrations')->value('migration');
    DB::table('migrations')->where('migration', $migration)->delete();
    [$exit, $output] = runDeploymentCheck();

    expect($exit)->toBe(1);
    expect($output)->toContain('PASS DB_writable')->toContain('FAIL migrations_current');
});

it('reports each registration prerequisite by name without failing a closed deployment', function (string $key, mixed $value, string $reason) {
    config([$key => $value]);
    [$exit, $output] = runDeploymentCheck();

    expect($exit)->toBe(0);
    expect($output)->toContain('FAIL registration:')->toContain($reason);
    expect(RegistrationGuard::registrationAvailable())->toBeFalse();
    expect(RegistrationGuard::registrationUnavailableReasons())->toContain($reason);
})->with([
    ['services.auth_hardening.public_registration_enabled', false, 'AUTH_PUBLIC_REGISTRATION_ENABLED'],
    ['services.auth_hardening.require_email_verification', false, 'AUTH_REQUIRE_EMAIL_VERIFICATION'],
    ['services.auth_hardening.turnstile.site_key', '', 'TURNSTILE_SITE_KEY'],
    ['services.auth_hardening.turnstile.site_key', '1x00000000000000000000AA', 'TURNSTILE_SITE_KEY'],
    ['services.auth_hardening.turnstile.secret', '', 'TURNSTILE_SECRET'],
    ['services.auth_hardening.turnstile.secret', '1x0000000000000000000000000000000AA', 'TURNSTILE_SECRET'],
    ['services.auth_hardening.turnstile.expected_hostname', '', 'TURNSTILE_EXPECTED_HOSTNAME'],
    ['services.auth_hardening.turnstile.expected_hostname', 'other.example.net', 'APP_URL_TURNSTILE_HOSTNAME'],
    ['services.auth_hardening.turnstile.expected_action', 'other', 'TURNSTILE_EXPECTED_ACTION'],
    ['services.auth_hardening.turnstile.verify_url', 'https://other.example.net', 'TURNSTILE_VERIFY_URL'],
    ['mail.default', 'log', 'MAIL_MAILER'],
    ['mail.mailers.smtp.require_tls', false, 'MAIL_MAILER'],
    ['queue.default', 'sync', 'QUEUE_CONNECTION'],
]);

it('reports unsafe mail and a non durable queue as soft failures', function () {
    config(['mail.default' => 'log', 'queue.default' => 'sync']);
    [$exit, $output] = runDeploymentCheck();

    expect($exit)->toBe(0);
    expect($output)->toContain('FAIL mailer_safe')->toContain('FAIL queue_durable');
});

it('reports missing SMTP configuration even when the transport protects secrets', function (string $key, mixed $value) {
    config([$key => $value]);
    [$exit, $output] = runDeploymentCheck();

    expect($exit)->toBe(0);
    expect($output)->toContain('FAIL smtp_configured');
})->with([
    ['mail.default', 'sendmail'],
    ['mail.mailers.smtp.host', ''],
    ['mail.mailers.smtp.port', 0],
    ['mail.mailers.smtp.username', ''],
    ['mail.mailers.smtp.password', ''],
    ['mail.from.address', 'invalid'],
]);

it('accepts implicit SMTP TLS as well as required STARTTLS', function () {
    config(['mail.mailers.smtp.scheme' => 'smtps', 'mail.mailers.smtp.require_tls' => false]);
    [$exit, $output] = runDeploymentCheck();

    expect($exit)->toBe(0);
    expect($output)->toContain('PASS mailer_safe')->toContain('PASS registration');
});
