<?php

use App\Jobs\PurgeCharacterizationDocumentJob;
use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\CharacterizationDocumentPurge;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
    $response->assertSee('name="current_password"', false);
    $response->assertSee('Contraseña actual para cambiar el correo');
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('changing an email address requires the current password', function () {
    $user = User::factory()->create(['email' => 'before@example.test']);

    $this->actingAs($user)
        ->from('/profile')
        ->patch('/profile', [
            'name' => $user->name,
            'email' => 'after@example.test',
        ])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors('current_password');

    $user->refresh();
    expect($user->email)->toBe('before@example.test')
        ->and($user->auth_version)->toBe(0);
});

test('changing an email address revokes reset tokens for the former address', function () {
    $user = User::factory()->create(['email' => 'before@example.test']);
    $token = Password::createToken($user);

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => $user->name,
            'email' => 'after@example.test',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'before@example.test']);
    $this->post('/logout');

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'before@example.test',
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])->assertSessionHasErrors('email');
});

test('deleting and recreating an email address does not transfer an old reset token', function () {
    $user = User::factory()->create(['email' => 'reused@example.test']);
    $token = Password::createToken($user);

    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors();

    User::factory()->create(['email' => 'reused@example.test']);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'reused@example.test',
        'password' => 'another-password',
        'password_confirmation' => 'another-password',
    ])->assertSessionHasErrors('email');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('deleting a user account cascades their characterization state', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create([
        'user_id' => $user->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
    $this->assertDatabaseMissing('characterizations', [
        'id' => $characterization->id,
    ]);
});

test('deleting a user account permanently removes uploaded characterization documents', function () {
    Storage::fake(CharacterizationDocument::STORAGE_DISK);

    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $path = 'characterization-documents/'.$characterization->id.'/private-report.pdf';
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->put($path, 'private report bytes');
    $document = CharacterizationDocument::query()->create([
        'characterization_id' => $characterization->id,
        'original_filename' => 'private-report.pdf',
        'stored_path' => $path,
        'sha256' => hash('sha256', 'private report bytes'),
        'size_bytes' => strlen('private report bytes'),
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
    ]);

    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertDatabaseMissing('characterization_documents', ['id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertMissing($path);
});

test('account deletion waits while document extraction owns staged private bytes', function () {
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $document = CharacterizationDocument::query()->create([
        'characterization_id' => $characterization->id,
        'original_filename' => 'active.pdf',
        'stored_path' => 'characterization-documents/active.pdf',
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_EXTRACTING,
        'extraction_generation' => '88888888-8888-4888-8888-888888888888',
        'extraction_lease_token' => '99999999-9999-4999-8999-999999999999',
    ]);

    $this->actingAs($user)
        ->from('/profile')
        ->delete('/profile', ['password' => 'password'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors('password', null, 'userDeletion');

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('characterization_documents', ['id' => $document->id]);
});

test('account deletion rollback never leaves a live document row pointing to missing private bytes', function () {
    Storage::fake(CharacterizationDocument::STORAGE_DISK);

    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $path = 'characterization-documents/'.$characterization->id.'/rollback-safe.pdf';
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->put($path, 'private report bytes');
    $document = CharacterizationDocument::query()->create([
        'characterization_id' => $characterization->id,
        'original_filename' => 'rollback-safe.pdf',
        'stored_path' => $path,
        'sha256' => hash('sha256', 'private report bytes'),
        'size_bytes' => strlen('private report bytes'),
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
    ]);
    Event::listen('eloquent.deleting: '.User::class, function (): never {
        throw new RuntimeException('synthetic_database_failure_after_document_delete');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->delete('/profile', ['password' => 'password']))
        ->toThrow(RuntimeException::class, 'synthetic_database_failure_after_document_delete');

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('characterizations', ['id' => $characterization->id]);
    $this->assertDatabaseHas('characterization_documents', ['id' => $document->id, 'stored_path' => $path]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);
});

test('account deletion retains a durable retry locator when private bytes cannot be purged immediately', function () {
    Queue::fake();
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $path = 'characterization-documents/'.$characterization->id.'/undeletable.pdf';
    $document = CharacterizationDocument::query()->create([
        'characterization_id' => $characterization->id,
        'original_filename' => 'undeletable.pdf',
        'stored_path' => $path,
        'sha256' => hash('sha256', 'private report bytes'),
        'size_bytes' => strlen('private report bytes'),
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
    ]);
    $disk = Mockery::mock();
    $disk->shouldReceive('exists')->once()->with($path)->andReturnTrue();
    $disk->shouldReceive('delete')->once()->with($path)->andReturnFalse();
    Storage::shouldReceive('disk')
        ->once()
        ->with(CharacterizationDocument::STORAGE_DISK)
        ->andReturn($disk);

    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('characterizations', ['id' => $characterization->id]);
    $this->assertDatabaseMissing('characterization_documents', ['id' => $document->id]);
    $this->assertDatabaseHas('characterization_document_purges', [
        'source_document_id' => $document->id,
        'storage_disk' => CharacterizationDocument::STORAGE_DISK,
        'stored_path' => $path,
        'attempts' => 1,
        'last_error' => 'delete_failed',
    ]);
    $purge = CharacterizationDocumentPurge::query()->firstOrFail();
    Queue::assertPushed(
        PurgeCharacterizationDocumentJob::class,
        fn (PurgeCharacterizationDocumentJob $job): bool => $job->purgeId === $purge->id,
    );
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});
