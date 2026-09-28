<?php

use App\Jobs\ExtractCharacterizationDocumentJob;
use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('stale extracting documents receive a new generation and are durably redispatched', function () {
    config([
        'services.p6_document_upload.enabled' => true,
        'services.p6_document_upload.job_timeout' => 300,
    ]);
    Queue::fake();
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $stale = $characterization->documents()->create([
        'original_filename' => 'stale.pdf',
        'stored_path' => "characterization-documents/{$characterization->id}/stale.pdf",
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_EXTRACTING,
        'extraction_generation' => '11111111-1111-4111-8111-111111111111',
        'extraction_lease_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    ]);
    $fresh = $characterization->documents()->create([
        'original_filename' => 'fresh.pdf',
        'stored_path' => "characterization-documents/{$characterization->id}/fresh.pdf",
        'sha256' => str_repeat('b', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_EXTRACTING,
        'extraction_generation' => '22222222-2222-4222-8222-222222222222',
        'extraction_lease_token' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    ]);
    CharacterizationDocument::query()->whereKey($stale->id)->update([
        'updated_at' => now()->subSeconds(421),
    ]);

    $this->artisan('characterization-documents:recover-stale-extractions')
        ->assertSuccessful();

    expect($stale->fresh()->status)->toBe(CharacterizationDocument::STATUS_UPLOADED)
        ->and($stale->fresh()->extraction_generation)->not->toBe('11111111-1111-4111-8111-111111111111')
        ->and($stale->fresh()->extraction_lease_token)->toBeNull()
        ->and($fresh->fresh()->status)->toBe(CharacterizationDocument::STATUS_EXTRACTING);
    Queue::assertPushed(ExtractCharacterizationDocumentJob::class, 1);
});

test('a committed uploaded intent is redispatched after a controller dispatch gap', function () {
    config(['services.p6_document_upload.enabled' => true]);
    Queue::fake();
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $document = $characterization->documents()->create([
        'original_filename' => 'pending.pdf',
        'stored_path' => "characterization-documents/{$characterization->id}/pending.pdf",
        'sha256' => str_repeat('c', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
        'extraction_generation' => '33333333-3333-4333-8333-333333333333',
        'extraction_dispatched_at' => null,
    ]);

    $this->artisan('characterization-documents:recover-stale-extractions')
        ->assertSuccessful();

    Queue::assertPushed(
        ExtractCharacterizationDocumentJob::class,
        fn (ExtractCharacterizationDocumentJob $job): bool => $job->documentId === $document->id
            && $job->generation === $document->extraction_generation,
    );
    expect($document->fresh()->extraction_dispatched_at)->not->toBeNull();
});

test('recovery fails closed without consuming intents after runtime qualification drifts', function () {
    $this->app['env'] = 'production';
    config([
        'services.p6_document_upload.enabled' => true,
        'services.p6_document_upload.scan.enabled' => true,
        'services.p6_document_upload.scan.binary' => 'clamscan',
        'services.p6_document_upload.ai_worker_timeout' => 180,
        'services.p6_document_upload.extract_timeout' => 240,
        'services.p6_document_upload.job_timeout' => 300,
        'services.characterization.api.base_url' => 'http://127.0.0.1:8001',
        'services.characterization.api.token' => 'unit-test-ai-token',
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 90,
        'cache.default' => 'database',
    ]);
    Queue::fake();
    $user = User::factory()->create();
    $characterization = Characterization::factory()->create(['user_id' => $user->id]);
    $document = $characterization->documents()->create([
        'original_filename' => 'pending.pdf',
        'stored_path' => 'characterization-documents/pending.pdf',
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
        'extraction_generation' => '77777777-7777-4777-8777-777777777777',
    ]);

    $this->artisan('characterization-documents:recover-stale-extractions')->assertFailed();

    Queue::assertNothingPushed();
    expect($document->fresh()->status)->toBe(CharacterizationDocument::STATUS_UPLOADED)
        ->and($document->fresh()->extraction_dispatched_at)->toBeNull();
});
