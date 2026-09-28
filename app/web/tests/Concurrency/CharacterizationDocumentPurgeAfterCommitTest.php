<?php

use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(Tests\TestCase::class, DatabaseMigrations::class);

beforeEach(function () {
    config([
        'services.private_dev.auto_login' => false,
        'services.p6_document_upload.enabled' => true,
    ]);
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
});

/**
 * @return array{0: User, 1: Characterization, 2: CharacterizationDocument, 3: string}
 */
function purgeAfterCommitFixture(): array
{
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $path = 'characterization-documents/'.$characterization->id.'/outer-transaction.pdf';
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->put($path, 'private bytes');
    $document = CharacterizationDocument::query()->create([
        'characterization_id' => $characterization->id,
        'original_filename' => 'outer-transaction.pdf',
        'stored_path' => $path,
        'sha256' => hash('sha256', 'private bytes'),
        'size_bytes' => strlen('private bytes'),
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
    ]);

    return [$user, $characterization, $document, $path];
}

it('does not purge document bytes before an ambient transaction rolls back', function () {
    [$user, $characterization, $document, $path] = purgeAfterCommitFixture();

    DB::beginTransaction();
    $this->actingAs($user)
        ->deleteJson('/api/characterization/documents/'.$document->id)
        ->assertOk()
        ->assertJsonPath('data.purge_status', 'pending');

    $this->assertDatabaseMissing('characterization_documents', ['id' => $document->id]);
    $this->assertDatabaseHas('characterization_document_purges', ['source_document_id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);

    DB::rollBack();

    $this->assertDatabaseHas('characterizations', ['id' => $characterization->id]);
    $this->assertDatabaseHas('characterization_documents', ['id' => $document->id]);
    $this->assertDatabaseMissing('characterization_document_purges', ['source_document_id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);
});

it('purges document bytes only after the ambient transaction commits', function () {
    [$user, $characterization, $document, $path] = purgeAfterCommitFixture();

    DB::beginTransaction();
    $this->actingAs($user)
        ->deleteJson('/api/characterization/documents/'.$document->id)
        ->assertOk()
        ->assertJsonPath('data.purge_status', 'pending');

    $this->assertDatabaseMissing('characterization_documents', ['id' => $document->id]);
    $this->assertDatabaseHas('characterization_document_purges', ['source_document_id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);

    DB::commit();

    $this->assertDatabaseHas('characterizations', ['id' => $characterization->id]);
    $this->assertDatabaseMissing('characterization_documents', ['id' => $document->id]);
    $this->assertDatabaseMissing('characterization_document_purges', ['source_document_id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertMissing($path);
});

it('does not purge account document bytes before an ambient transaction rolls back', function () {
    [$user, $characterization, $document, $path] = purgeAfterCommitFixture();

    DB::beginTransaction();
    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseHas('characterization_document_purges', ['source_document_id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);

    DB::rollBack();

    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('characterizations', ['id' => $characterization->id]);
    $this->assertDatabaseHas('characterization_documents', ['id' => $document->id]);
    $this->assertDatabaseMissing('characterization_document_purges', ['source_document_id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);
});

it('purges account document bytes only after the ambient transaction commits', function () {
    [$user, $characterization, $document, $path] = purgeAfterCommitFixture();

    DB::beginTransaction();
    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseHas('characterization_document_purges', ['source_document_id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);

    DB::commit();

    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('characterizations', ['id' => $characterization->id]);
    $this->assertDatabaseMissing('characterization_documents', ['id' => $document->id]);
    $this->assertDatabaseMissing('characterization_document_purges', ['source_document_id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertMissing($path);
});
