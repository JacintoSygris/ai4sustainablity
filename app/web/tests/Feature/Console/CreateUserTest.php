<?php

use App\Console\Commands\CreateUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Tester\CommandTester;

function userCreationTester(): CommandTester
{
    $command = app(CreateUser::class);
    $command->setLaravel(app());

    return new CommandTester($command);
}

it('creates an unverified account through a hidden prompt without printing the password', function () {
    config(['services.auth_hardening.public_registration_enabled' => false]);
    $tester = userCreationTester();
    $password = 'Fixture-password-348!';
    $tester->setInputs([$password]);

    expect($tester->execute(['email' => 'Operator@example.test', '--name' => 'Operator']))->toBe(0);
    $user = User::where('email', 'operator@example.test')->sole();
    expect($user->name)->toBe('Operator');
    expect($user->email_verified_at)->toBeNull();
    expect(Hash::check($password, $user->password))->toBeTrue();
    expect($tester->getDisplay())->toContain('PASS user_create: user_id='.$user->id)->not->toContain($password);
});

it('reads only one STDIN line, preserves password spaces and supports verified accounts', function () {
    $tester = userCreationTester();
    $password = '  Fixture-password-348!  ';
    $tester->setInputs([$password, 'must-not-be-read']);

    expect($tester->execute(['email' => 'stdin@example.test', '--password-stdin' => true, '--verified' => true], ['interactive' => false]))->toBe(0);
    $user = User::where('email', 'stdin@example.test')->sole();
    expect($user->name)->toBe('stdin@example.test');
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect(Hash::check($password, $user->password))->toBeTrue();
    expect($tester->getDisplay())->not->toContain($password)->not->toContain('must-not-be-read');
});

it('refuses duplicate emails without changing the existing account', function () {
    $user = User::factory()->create(['email' => 'existing@example.test']);
    $before = $user->refresh()->getAttributes();
    $tester = userCreationTester();

    expect($tester->execute(['email' => 'EXISTING@example.test', '--verified' => true], ['interactive' => false]))->toBe(1);
    expect($tester->getDisplay())->toContain('FAIL user_create: duplicate_email');
    expect($user->fresh()->getAttributes())->toBe($before);
    expect(User::count())->toBe(1);
});

it('applies the registration password rules and never prints rejected passwords', function () {
    $property = new ReflectionProperty(Password::class, 'defaultCallback');
    $previous = $property->getValue();
    Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());

    try {
        $tester = userCreationTester();
        $tester->setInputs(['weak-value']);
        expect($tester->execute(['email' => 'invalid@example.test', '--password-stdin' => true]))->toBe(1);
        expect($tester->getDisplay())->toContain('invalid_password')->not->toContain('weak-value');
        expect(User::count())->toBe(0);
    } finally {
        $property->setValue(null, $previous);
    }
});

it('fails safely for an empty password line', function () {
    $tester = userCreationTester();
    $tester->setInputs(['']);

    expect($tester->execute(['email' => 'empty@example.test', '--password-stdin' => true]))->toBe(1);
    expect($tester->getDisplay())->toContain('invalid_password');
    expect(User::count())->toBe(0);
});

it('requires explicit STDIN mode when no interactive terminal is requested', function () {
    $this->artisan('i4s:user:create', ['email' => 'operator@example.test', '--no-interaction' => true])
        ->expectsOutputToContain('password_input_required')
        ->assertFailed();
});
