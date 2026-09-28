<?php

use App\Jobs\PurgeCharacterizationDocumentJob;
use App\Models\CharacterizationDocument;
use App\Models\CharacterizationDocumentPurge;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('queues every durable private-document purge intent for retry', function () {
    Queue::fake();
    $purge = CharacterizationDocumentPurge::query()->create([
        'source_document_id' => 42,
        'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0pending.pdf"),
        'storage_disk' => CharacterizationDocument::STORAGE_DISK,
        'stored_path' => 'pending.pdf',
    ]);

    $this->artisan('characterization-documents:purge-pending')
        ->expectsOutput('Queued 1 pending private document purge(s).')
        ->assertSuccessful();

    Queue::assertPushed(
        PurgeCharacterizationDocumentJob::class,
        fn (PurgeCharacterizationDocumentJob $job): bool => $job->purgeId === $purge->id,
    );
});

it('advances past a claimed page so newer purge intents cannot starve', function () {
    Queue::fake();

    foreach (range(1, 3) as $index) {
        CharacterizationDocumentPurge::query()->create([
            'source_document_id' => $index,
            'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0pending-{$index}.pdf"),
            'storage_disk' => CharacterizationDocument::STORAGE_DISK,
            'stored_path' => "pending-{$index}.pdf",
        ]);
    }

    $this->artisan('characterization-documents:purge-pending --limit=2')->assertSuccessful();
    $this->artisan('characterization-documents:purge-pending --limit=2')->assertSuccessful();

    Queue::assertPushed(PurgeCharacterizationDocumentJob::class, 3);
    foreach (range(1, 3) as $purgeId) {
        Queue::assertPushed(
            PurgeCharacterizationDocumentJob::class,
            fn (PurgeCharacterizationDocumentJob $job): bool => $job->purgeId === $purgeId,
        );
    }
});

it('skips a poisoned future retry and queues newer eligible intents', function () {
    Queue::fake();
    CharacterizationDocumentPurge::query()->create([
        'source_document_id' => 1,
        'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0poison.pdf"),
        'storage_disk' => CharacterizationDocument::STORAGE_DISK,
        'stored_path' => 'poison.pdf',
        'next_attempt_at' => now()->addHour(),
    ]);
    foreach (range(2, 3) as $index) {
        CharacterizationDocumentPurge::query()->create([
            'source_document_id' => $index,
            'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0eligible-{$index}.pdf"),
            'storage_disk' => CharacterizationDocument::STORAGE_DISK,
            'stored_path' => "eligible-{$index}.pdf",
        ]);
    }

    $this->artisan('characterization-documents:purge-pending --limit=2')->assertSuccessful();

    Queue::assertPushed(PurgeCharacterizationDocumentJob::class, 2);
    Queue::assertNotPushed(
        PurgeCharacterizationDocumentJob::class,
        fn (PurgeCharacterizationDocumentJob $job): bool => $job->purgeId === 1,
    );
});

it('does not starve a due retry behind a sustained page of new intents', function () {
    Queue::fake();
    $due = CharacterizationDocumentPurge::query()->create([
        'source_document_id' => 1,
        'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0due-retry.pdf"),
        'storage_disk' => CharacterizationDocument::STORAGE_DISK,
        'stored_path' => 'due-retry.pdf',
        'next_attempt_at' => now()->subHour(),
    ]);
    foreach (range(2, 101) as $index) {
        CharacterizationDocumentPurge::query()->create([
            'source_document_id' => $index,
            'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0new-{$index}.pdf"),
            'storage_disk' => CharacterizationDocument::STORAGE_DISK,
            'stored_path' => "new-{$index}.pdf",
        ]);
    }

    $this->artisan('characterization-documents:purge-pending --limit=100')->assertSuccessful();

    Queue::assertPushed(
        PurgeCharacterizationDocumentJob::class,
        fn (PurgeCharacterizationDocumentJob $job): bool => $job->purgeId === $due->id,
    );
});

it('enforces one unique non-overlapping worker per purge intent', function () {
    $job = new PurgeCharacterizationDocumentJob(42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('characterization-document-purge:42')
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('continues dispatching later purge intents when one queue dispatch fails', function () {
    foreach (range(1, 2) as $index) {
        CharacterizationDocumentPurge::query()->create([
            'source_document_id' => $index,
            'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0dispatch-{$index}.pdf"),
            'storage_disk' => CharacterizationDocument::STORAGE_DISK,
            'stored_path' => "dispatch-{$index}.pdf",
        ]);
    }

    $dispatches = 0;
    $originalDispatcher = $this->app->make(Dispatcher::class);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->twice()
        ->andReturnUsing(function (PurgeCharacterizationDocumentJob $job) use (&$dispatches): mixed {
            $dispatches++;
            if ($job->purgeId === 1) {
                throw new RuntimeException('synthetic_queue_outage');
            }

            return null;
        });
    $this->app->instance(Dispatcher::class, $dispatcher);

    $this->artisan('characterization-documents:purge-pending --limit=2')
        ->expectsOutput('Queued 1 pending private document purge(s); 1 dispatch failure(s).')
        ->assertFailed();

    expect($dispatches)->toBe(2);
    $this->assertDatabaseHas('characterization_document_purges', [
        'id' => 1,
        'last_error' => 'dispatch_failed',
    ]);

    $this->travel(61)->seconds();
    $this->app->instance(Dispatcher::class, $originalDispatcher);
    Queue::fake();
    $this->artisan('characterization-documents:purge-pending --limit=2')->assertSuccessful();
    Queue::assertPushed(
        PurgeCharacterizationDocumentJob::class,
        fn (PurgeCharacterizationDocumentJob $job): bool => $job->purgeId === 1,
    );
});

it('continues later claims when persisting one dispatch failure also fails', function () {
    foreach (range(1, 2) as $index) {
        CharacterizationDocumentPurge::query()->create([
            'source_document_id' => $index,
            'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0record-{$index}.pdf"),
            'storage_disk' => CharacterizationDocument::STORAGE_DISK,
            'stored_path' => "record-{$index}.pdf",
        ]);
    }

    $dispatches = 0;
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->twice()
        ->andReturnUsing(function (PurgeCharacterizationDocumentJob $job) use (&$dispatches): mixed {
            $dispatches++;
            if ($job->purgeId === 1) {
                throw new RuntimeException('synthetic_queue_outage');
            }

            return null;
        });
    $this->app->instance(Dispatcher::class, $dispatcher);
    CharacterizationDocumentPurge::saving(function (CharacterizationDocumentPurge $purge): void {
        if ($purge->last_error === 'dispatch_failed') {
            throw new RuntimeException('synthetic_dispatch_record_failure');
        }
    });

    try {
        $this->artisan('characterization-documents:purge-pending --limit=2')
            ->expectsOutput('Queued 1 pending private document purge(s); 1 dispatch failure(s).')
            ->assertFailed();
    } finally {
        CharacterizationDocumentPurge::flushEventListeners();
    }

    expect($dispatches)->toBe(2);
    $this->assertDatabaseHas('characterization_document_purges', ['id' => 1]);
});

it('purges bytes idempotently and removes the retry locator only after absence is verified', function () {
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    $path = 'characterization-documents/42/private.pdf';
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->put($path, 'private bytes');
    $purge = CharacterizationDocumentPurge::query()->create([
        'source_document_id' => 42,
        'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0".$path),
        'storage_disk' => CharacterizationDocument::STORAGE_DISK,
        'stored_path' => $path,
    ]);

    (new PurgeCharacterizationDocumentJob($purge->id))->handle(
        app(\App\Services\CharacterizationDocumentPurgeService::class),
    );

    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertMissing($path);
    $this->assertDatabaseMissing('characterization_document_purges', ['id' => $purge->id]);

    (new PurgeCharacterizationDocumentJob($purge->id))->handle(
        app(\App\Services\CharacterizationDocumentPurgeService::class),
    );

    $this->assertDatabaseCount('characterization_document_purges', 0);
});

it('retains and marks the retry locator when the storage backend throws', function () {
    $purge = CharacterizationDocumentPurge::query()->create([
        'source_document_id' => 42,
        'path_hash' => hash('sha256', CharacterizationDocument::STORAGE_DISK."\0throwing.pdf"),
        'storage_disk' => CharacterizationDocument::STORAGE_DISK,
        'stored_path' => 'throwing.pdf',
    ]);
    Storage::shouldReceive('disk')
        ->once()
        ->with(CharacterizationDocument::STORAGE_DISK)
        ->andThrow(new RuntimeException('synthetic_storage_failure'));

    expect(fn () => app(\App\Services\CharacterizationDocumentPurgeService::class)
        ->purgeOrFail($purge->id))
        ->toThrow(RuntimeException::class, 'characterization_document_purge_storage_failed');

    $this->assertDatabaseHas('characterization_document_purges', [
        'id' => $purge->id,
        'attempts' => 1,
        'last_error' => 'storage_error',
    ]);
});
