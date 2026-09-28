<?php

use App\Jobs\ExtractCharacterizationDocumentJob;
use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\CharacterizationDocumentPurge;
use App\Models\EsrsTopic;
use App\Models\User;
use App\Services\DocumentEvidencePresenter;
use App\Support\P6DocumentUploadGuard;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const P6_DOCS_PDF_CONTENT = "%PDF-1.7\nDocumento de prueba con contenido suficiente.";
const P6_DOCS_DOCX_CONTENT = "PK\x03\x04Documento docx de prueba con contenido suficiente.";

beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);
    config(['services.characterization.api.base_url' => 'http://127.0.0.1:8001']);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->e1Topic = EsrsTopic::where('esrs_code', 'E1')->firstOrFail();
});

function p6DocsEnableUpload(): void
{
    config([
        'services.p6_document_upload.enabled' => true,
        'services.characterization.api.token' => 'unit-test-ai-token',
    ]);
}

function p6DocsCharacterization(User $user, EsrsTopic $e1Topic): Characterization
{
    return Characterization::factory()->create([
        'user_id' => $user->id,
        'status' => Characterization::STATUS_COMPLETED,
        'esrs_topic_ids' => [$e1Topic->id],
        'form_data' => [
            'company_profile' => ['company_name' => 'Empresa Uno'],
            'operations' => ['employee_count' => 120],
        ],
        'result_data' => [
            'status' => 'completed',
            'summary' => 'AI proposed 1 candidate ESRS topic.',
            'candidate_topics' => [
                [
                    'ar16_topic_id' => $e1Topic->id,
                    'web_esrs' => 'E1',
                    'web_label_en' => 'Energy',
                    'python_esrs_keys' => ['esrs_e1_energy_use'],
                    'score_source' => 'python_predict',
                    'suggested' => true,
                ],
            ],
            'review_required_prediction_keys' => [],
            'raw_prediction' => ['esrs_e1_energy_use' => 1],
        ],
        'submitted_at' => now()->subMinutes(2),
        'completed_at' => now()->subMinute(),
    ]);
}

/**
 * @param  array<int, array<string, mixed>>  $evidence
 */
function p6DocsFakeExtraction(array $evidence, string $status = 'ok'): void
{
    Http::fake([
        '*/extract-document' => function ($request) use ($evidence, $status) {
            $payload = $request->data();

            return Http::response([
                'document_id' => (string) $payload['document_id'],
                'sha256' => $payload['sha256'],
                'status' => $status,
                'parser_version' => 'v3',
                'config_version' => 'gate-run-4',
                'evidence' => $evidence,
            ]);
        },
    ]);
}

it('returns 404 on every document route and an unchanged proposal payload when the flag is off', function () {
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();

    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->getJson('/api/characterization/documents')
        ->assertNotFound();

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->deleteJson('/api/characterization/documents/1')
        ->assertNotFound();

    Queue::assertNothingPushed();
    expect(Storage::disk(CharacterizationDocument::STORAGE_DISK)->allFiles())->toBe([]);
    expect(CharacterizationDocument::count())->toBe(0);

    $proposal = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->json('data');

    expect(array_key_exists('document_evidence', $proposal))->toBeFalse();
});

it('requires authentication for the document endpoints when the flag is on', function () {
    p6DocsEnableUpload();

    $this->getJson('/api/characterization/documents')
        ->assertUnauthorized();
});

it('uploads a document, extracts evidence, and surfaces it in the proposal payload', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsFakeExtraction([
        [
            'standard' => 'E1',
            'topic_key' => 'esrs_e1_energy_use',
            'kind' => 'positive',
            'confidence' => 0.9,
            'page' => 12,
            'snippet' => 'El consumo energético anual se redujo un 8 %.',
        ],
    ]);

    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated()
        ->assertJsonPath('data.original_filename', 'memoria-2025.pdf');

    $document = CharacterizationDocument::firstOrFail();

    expect($document->status)->toBe(CharacterizationDocument::STATUS_EXTRACTED);
    expect($document->extraction_json['status'])->toBe('ok');
    expect($document->merged_state_version)->not->toBeNull();
    expect($document->sha256)->toBe(hash('sha256', P6_DOCS_PDF_CONTENT));
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($document->stored_path);

    Http::assertSent(function ($request) use ($document) {
        return str_ends_with($request->url(), '/extract-document')
            && $request->hasHeader('Authorization', 'Bearer unit-test-ai-token')
            && $request['document_id'] === (string) $document->id
            && $request['original_filename'] === 'memoria-2025.pdf'
            && $request['sha256'] === $document->sha256;
    });

    $this->actingAs($this->user)
        ->getJson('/api/characterization/documents')
        ->assertOk()
        ->assertJsonPath('data.documents.0.id', $document->id)
        ->assertJsonPath('data.documents.0.status', 'extracted');

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.document_evidence.documents.0.id', $document->id)
        ->assertJsonPath('data.document_evidence.documents.0.original_filename', 'memoria-2025.pdf')
        ->assertJsonPath('data.document_evidence.documents.0.status', 'extracted')
        ->assertJsonPath('data.document_evidence.documents.0.stale', false)
        ->assertJsonPath('data.document_evidence.documents.0.deleted', false)
        ->assertJsonPath('data.document_evidence.topics.0.esrs_code', 'E1')
        ->assertJsonPath('data.document_evidence.topics.0.standard', 'E1')
        ->assertJsonPath('data.document_evidence.topics.0.source', 'document')
        ->assertJsonPath('data.document_evidence.topics.0.kind', 'positive')
        ->assertJsonPath('data.document_evidence.topics.0.evidence.0.document_id', $document->id)
        ->assertJsonPath('data.document_evidence.topics.0.evidence.0.page', 12)
        ->assertJsonPath('data.document_evidence.topics.0.evidence.0.snippet', 'El consumo energético anual se redujo un 8 %.');
});

it('rejects an extraction response that is not bound to the uploaded document', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Http::fake([
        '*/extract-document' => Http::response([
            'document_id' => '1',
            'sha256' => str_repeat('0', 64),
            'status' => 'ok',
            'parser_version' => 'v3',
            'config_version' => 'gate-run-4',
            'evidence' => [[
                'standard' => 'E1',
                'topic_key' => null,
                'kind' => 'positive',
                'confidence' => 0.9,
                'page' => 1,
                'snippet' => 'wrong document',
            ]],
        ]),
    ]);
    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('binding.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $document = CharacterizationDocument::query()->sole();
    expect($document->status)->toBe(CharacterizationDocument::STATUS_FAILED)
        ->and($document->extraction_json)->toBeNull();
});

it('retries transient extraction capacity responses before persisting evidence', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Http::fakeSequence()
        ->push([], 503, ['Retry-After' => '0'])
        ->push([
            'document_id' => '1',
            'sha256' => hash('sha256', P6_DOCS_PDF_CONTENT),
            'status' => 'ok',
            'parser_version' => 'v3',
            'config_version' => 'gate-run-4',
            'evidence' => [[
                'standard' => 'E1',
                'topic_key' => null,
                'kind' => 'positive',
                'confidence' => 0.9,
                'page' => 1,
                'snippet' => 'bounded retry',
            ]],
        ], 200);
    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('retry.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    expect(CharacterizationDocument::query()->sole()->status)->toBe(CharacterizationDocument::STATUS_EXTRACTED);
    Http::assertSentCount(2);
});

it('marks an extracting document failed when the queue job times out', function () {
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);
    $document = $characterization->documents()->create([
        'original_filename' => 'timeout.pdf',
        'stored_path' => "characterization-documents/{$characterization->id}/timeout.pdf",
        'sha256' => str_repeat('a', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_EXTRACTING,
        'extraction_generation' => '11111111-1111-4111-8111-111111111111',
    ]);

    $job = new ExtractCharacterizationDocumentJob($document->id, $document->extraction_generation);
    $document->forceFill(['extraction_lease_token' => $job->leaseToken])->save();
    $job->failed(new RuntimeException('synthetic hard timeout'));

    expect($document->fresh()->status)->toBe(CharacterizationDocument::STATUS_FAILED)
        ->and($job->failOnTimeout)->toBeTrue();
});

it('removes stored upload bytes when document row creation fails', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();

    p6DocsCharacterization($this->user, $this->e1Topic);
    $filesAtFailure = null;
    $purgeIntentsAtFailure = null;
    Event::listen('eloquent.creating: '.CharacterizationDocument::class, function () use (&$filesAtFailure, &$purgeIntentsAtFailure): never {
        $filesAtFailure = Storage::disk(CharacterizationDocument::STORAGE_DISK)->allFiles();
        $purgeIntentsAtFailure = CharacterizationDocumentPurge::query()->count();
        throw new RuntimeException('synthetic document row failure');
    });

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertInternalServerError();

    expect($filesAtFailure)->toHaveCount(1);
    expect($purgeIntentsAtFailure)->toBe(1);
    expect(Storage::disk(CharacterizationDocument::STORAGE_DISK)->allFiles())->toBe([]);
    $this->assertDatabaseCount('characterization_document_purges', 0);
    Queue::assertNothingPushed();
});

it('keeps a committed upload as a durable pending intent when dispatch cannot be persisted', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsCharacterization($this->user, $this->e1Topic);

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::type(ExtractCharacterizationDocumentJob::class))
        ->andThrow(new RuntimeException('synthetic dispatch failure'));
    $this->app->instance(Dispatcher::class, $dispatcher);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('dispatch-failure.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $document = CharacterizationDocument::query()->sole();
    expect($document->status)->toBe(CharacterizationDocument::STATUS_UPLOADED)
        ->and($document->extraction_generation)->not->toBeNull()
        ->and($document->extraction_dispatched_at)->toBeNull();
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($document->stored_path);
});

it('rejects uploads that fail the fail-closed intake gate', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();

    p6DocsCharacterization($this->user, $this->e1Topic);

    // Extension outside the allowlist.
    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('notas.txt', P6_DOCS_PDF_CONTENT),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    // Over the 50 MB cap.
    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->create('grande.pdf', 51201),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    // PDF extension without %PDF magic bytes.
    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('informe.pdf', 'MZ contenido inesperado'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    // DOCX extension with PDF magic bytes.
    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('informe.docx', P6_DOCS_PDF_CONTENT),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    Queue::assertNothingPushed();
    expect(Storage::disk(CharacterizationDocument::STORAGE_DISK)->allFiles())->toBe([]);
    expect(CharacterizationDocument::count())->toBe(0);
});

it('only adds the document_evidence key and never changes the base proposal', function () {
    Storage::fake(CharacterizationDocument::STORAGE_DISK);

    p6DocsCharacterization($this->user, $this->e1Topic);

    $flagOff = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->json('data');

    p6DocsEnableUpload();
    p6DocsFakeExtraction([
        [
            'standard' => 'E2',
            'topic_key' => null,
            'kind' => 'positive',
            'confidence' => 0.7,
            'page' => 4,
            'snippet' => 'Emisiones de compuestos contaminantes al aire.',
        ],
    ]);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $withDocuments = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->json('data');

    // ADD-only invariant: everything except the new key is byte-identical.
    expect(json_encode(Arr::except($withDocuments, ['document_evidence'])))
        ->toBe(json_encode($flagOff));

    // Standard-level evidence with no child resolution keeps esrs_code null.
    expect($withDocuments['document_evidence']['topics'])->toHaveCount(1);
    expect($withDocuments['document_evidence']['topics'][0]['esrs_code'])->toBeNull();
    expect($withDocuments['document_evidence']['topics'][0]['standard'])->toBe('E2');

    // The base topic list is untouched by document-only topics.
    expect($withDocuments['proposal_topic_ids'])->toBe([$this->e1Topic->id]);
});

it('shows negative evidence as context without altering the base topics or review state', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsFakeExtraction([
        [
            'standard' => 'E1',
            'topic_key' => 'esrs_e1_energy_use',
            'kind' => 'negative',
            'confidence' => 0.8,
            'page' => 30,
            'snippet' => 'La energía no se considera un asunto material.',
        ],
    ]);

    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.proposal_topic_ids', [$this->e1Topic->id])
        ->assertJsonPath('data.review.status', 'not_started')
        ->assertJsonPath('data.ai.candidate_topics.0.ar16_topic_id', $this->e1Topic->id)
        ->assertJsonPath('data.document_evidence.topics.0.kind', 'negative')
        ->assertJsonPath('data.document_evidence.topics.0.esrs_code', 'E1');
});

it('unions evidence across documents and dedupes by topic, document and page/snippet', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);

    $row = [
        'standard' => 'E1',
        'topic_key' => 'esrs_e1_energy_use',
        'kind' => 'positive',
        'confidence' => 0.9,
        'page' => 12,
        'snippet' => 'El consumo energético anual se redujo un 8 %.',
    ];
    // The service reply repeats the same row: it must collapse to one entry.
    p6DocsFakeExtraction([$row, $row]);

    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('politicas.docx', P6_DOCS_DOCX_CONTENT),
        ])
        ->assertCreated();

    $evidence = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->json('data.document_evidence');

    expect($evidence['documents'])->toHaveCount(2);
    expect($evidence['topics'])->toHaveCount(1);
    expect($evidence['topics'][0]['evidence'])->toHaveCount(2);
    expect(collect($evidence['topics'][0]['evidence'])->pluck('document_id')->unique())->toHaveCount(2);
});

it('hard-deletes a document and flips its document-only topics to the deleted state', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    $snippet = 'El consumo energético anual se redujo un 8 %.';
    p6DocsFakeExtraction([
        [
            'standard' => 'E1',
            'topic_key' => 'esrs_e1_energy_use',
            'kind' => 'positive',
            'confidence' => 0.9,
            'page' => 12,
            'snippet' => $snippet,
        ],
    ]);

    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $document = CharacterizationDocument::firstOrFail();
    $storedPath = $document->stored_path;

    $this->actingAs($this->user)
        ->deleteJson('/api/characterization/documents/'.$document->id)
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(CharacterizationDocument::count())->toBe(0);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertMissing($storedPath);

    $evidence = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->json('data.document_evidence');

    expect($evidence['documents'])->toHaveCount(1);
    expect($evidence['documents'][0]['id'])->toBe($document->id);
    expect($evidence['documents'][0]['status'])->toBe('deleted');
    expect($evidence['documents'][0]['deleted'])->toBeTrue();
    expect($evidence['documents'][0])->not->toHaveKey('original_filename');
    $tombstones = Characterization::firstOrFail()->form_data['document_evidence_tombstones'];
    expect($tombstones[0])->not->toHaveKey('original_filename');

    // The dependent document-only topic stays visible in the deleted state,
    // with provenance only: pages, confidences and snippets are purged.
    expect($evidence['topics'])->toHaveCount(1);
    expect($evidence['topics'][0]['esrs_code'])->toBe('E1');
    expect($evidence['topics'][0]['kind'])->toBe('positive');
    expect($evidence['topics'][0]['evidence'])->toBe([['document_id' => $document->id]]);
    expect(json_encode($evidence, JSON_UNESCAPED_UNICODE))->not->toContain($snippet);
});

it('rolls back a document deletion without losing private bytes when the database transaction fails', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);
    $path = 'characterization-documents/'.$characterization->id.'/rollback-safe.pdf';
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->put($path, P6_DOCS_PDF_CONTENT);
    $document = CharacterizationDocument::query()->create([
        'characterization_id' => $characterization->id,
        'original_filename' => 'rollback-safe.pdf',
        'stored_path' => $path,
        'sha256' => hash('sha256', P6_DOCS_PDF_CONTENT),
        'size_bytes' => strlen(P6_DOCS_PDF_CONTENT),
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
    ]);
    Event::listen('eloquent.deleted: '.CharacterizationDocument::class, function (): never {
        throw new RuntimeException('synthetic_database_failure_after_document_delete');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->user)
        ->deleteJson('/api/characterization/documents/'.$document->id))
        ->toThrow(RuntimeException::class, 'synthetic_database_failure_after_document_delete');

    $this->assertDatabaseHas('characterization_documents', [
        'id' => $document->id,
        'stored_path' => $path,
    ]);
    $this->assertDatabaseCount('characterization_document_purges', 0);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($path);
    expect(Arr::get($characterization->fresh()->form_data, DocumentEvidencePresenter::TOMBSTONES_FORM_DATA_KEY))
        ->toBeNull();
});

it('keeps the committed delete response truthful when immediate retry recovery also fails', function () {
    p6DocsEnableUpload();
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);
    $document = CharacterizationDocument::query()->create([
        'characterization_id' => $characterization->id,
        'original_filename' => 'private.pdf',
        'stored_path' => 'characterization-documents/private.pdf',
        'sha256' => hash('sha256', 'pdf'),
        'size_bytes' => 3,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_EXTRACTED,
        'extraction_json' => ['status' => 'ok', 'evidence' => []],
    ]);
    Storage::shouldReceive('disk')
        ->once()
        ->with(CharacterizationDocument::STORAGE_DISK)
        ->andThrow(new RuntimeException('synthetic_storage_failure'));
    CharacterizationDocumentPurge::saving(function (CharacterizationDocumentPurge $purge): void {
        if ($purge->isDirty('lease_token') && $purge->lease_token !== null) {
            throw new RuntimeException('synthetic_retry_claim_failure');
        }
    });

    try {
        $this->actingAs($this->user)
            ->deleteJson('/api/characterization/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.purge_status', 'pending');
    } finally {
        CharacterizationDocumentPurge::flushEventListeners();
    }

    $this->assertDatabaseMissing('characterization_documents', ['id' => $document->id]);
    $this->assertDatabaseHas('characterization_document_purges', [
        'source_document_id' => $document->id,
        'attempts' => 1,
        'last_error' => 'storage_error',
    ]);
});

it('keeps a topic on live evidence when another document still supports it after a deletion', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsFakeExtraction([
        [
            'standard' => 'E1',
            'topic_key' => 'esrs_e1_energy_use',
            'kind' => 'positive',
            'confidence' => 0.9,
            'page' => 12,
            'snippet' => 'El consumo energético anual se redujo un 8 %.',
        ],
    ]);

    p6DocsCharacterization($this->user, $this->e1Topic);

    foreach (['memoria-2025.pdf', 'anexo.pdf'] as $filename) {
        $this->actingAs($this->user)
            ->withHeader('Accept', 'application/json')
            ->post('/api/characterization/documents', [
                'document' => UploadedFile::fake()->createWithContent($filename, P6_DOCS_PDF_CONTENT),
            ])
            ->assertCreated();
    }

    $first = CharacterizationDocument::orderBy('id')->firstOrFail();
    $second = CharacterizationDocument::orderBy('id', 'desc')->firstOrFail();

    $this->actingAs($this->user)
        ->deleteJson('/api/characterization/documents/'.$first->id)
        ->assertOk();

    $evidence = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->json('data.document_evidence');

    expect($evidence['documents'])->toHaveCount(2);
    expect(collect($evidence['documents'])->firstWhere('id', $first->id)['deleted'])->toBeTrue();
    expect(collect($evidence['documents'])->firstWhere('id', $second->id)['deleted'])->toBeFalse();

    // The topic is not document-only for the deleted file: it keeps the live
    // document's evidence (with snippet) and gains no tombstone entry.
    expect($evidence['topics'])->toHaveCount(1);
    expect($evidence['topics'][0]['evidence'])->toHaveCount(1);
    expect($evidence['topics'][0]['evidence'][0]['document_id'])->toBe($second->id);
    expect($evidence['topics'][0]['evidence'][0]['snippet'])->not->toBeNull();
});

it('marks extraction evidence as stale when the P5 answers change afterwards', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsFakeExtraction([
        [
            'standard' => 'E1',
            'topic_key' => 'esrs_e1_energy_use',
            'kind' => 'positive',
            'confidence' => 0.9,
            'page' => 12,
            'snippet' => 'El consumo energético anual se redujo un 8 %.',
        ],
    ]);

    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertJsonPath('data.document_evidence.documents.0.stale', false);

    $formData = $characterization->fresh()->form_data;
    Arr::set($formData, 'company_profile.company_name', 'Empresa Uno Renombrada');
    $characterization->forceFill(['form_data' => $formData])->save();

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertJsonPath('data.document_evidence.documents.0.stale', true);
});

it('marks the document as failed when the extraction service errors, without touching the proposal', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Http::fake(['*/extract-document' => Http::response(null, 500)]);

    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $document = CharacterizationDocument::firstOrFail();
    expect($document->status)->toBe(CharacterizationDocument::STATUS_FAILED);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.proposal_topic_ids', [$this->e1Topic->id])
        ->assertJsonPath('data.document_evidence.documents.0.status', 'failed')
        ->assertJsonPath('data.document_evidence.topics', []);
});

it('stores the no_usable_evidence outcome explicitly instead of a partial silent result', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsFakeExtraction([], 'no_usable_evidence');

    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $document = CharacterizationDocument::firstOrFail();
    expect($document->status)->toBe(CharacterizationDocument::STATUS_NO_USABLE_EVIDENCE);

    $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->assertJsonPath('data.document_evidence.documents.0.status', 'no_usable_evidence')
        ->assertJsonPath('data.document_evidence.topics', []);
});

it('keeps the base proposal byte-identical before and after a document upload and extraction', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsFakeExtraction([
        [
            'standard' => 'E2',
            'topic_key' => null,
            'kind' => 'positive',
            'confidence' => 0.9,
            'page' => 12,
            'snippet' => 'Emisiones al aire controladas en la planta.',
        ],
    ]);

    p6DocsCharacterization($this->user, $this->e1Topic);

    $before = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->json('data');

    $this->travel(3)->minutes();

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria-2025.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();

    $this->travel(1)->minutes();

    $after = $this->actingAs($this->user)
        ->getJson('/api/materiality-proposal')
        ->assertOk()
        ->json('data');

    // E2E lane CX-2: the base payload must not drift when documents arrive
    // (time travel exposes touched-parent timestamp drift that same-second runs mask).
    expect(json_encode(Arr::except($after, ['document_evidence'])))
        ->toBe(json_encode(Arr::except($before, ['document_evidence'])));
});

it('rejects an upload fail-closed when the virus scanner is enabled but unavailable', function () {
    p6DocsEnableUpload();
    config([
        'services.p6_document_upload.scan.enabled' => true,
        'services.p6_document_upload.scan.binary' => 'definitely-not-a-real-scanner-binary-xyz',
    ]);
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    // Nothing stored, no job dispatched, no row created (fail-closed before storage).
    expect(Storage::disk(CharacterizationDocument::STORAGE_DISK)->allFiles())->toBe([]);
    expect(CharacterizationDocument::count())->toBe(0);
});

it('allows an upload when the scanner is disabled (default)', function () {
    p6DocsEnableUpload();
    // scan.enabled defaults false
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    p6DocsFakeExtraction([['standard' => 'E1', 'topic_key' => 'esrs_e1_energy_use', 'kind' => 'positive', 'confidence' => 0.9, 'page' => 1, 'snippet' => 'x']]);
    p6DocsCharacterization($this->user, $this->e1Topic);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('memoria.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertCreated();
});

it('rejects uploads after the per-characterization document quota is reached', function () {
    p6DocsEnableUpload();
    config(['services.p6_document_upload.max_documents' => 2]);
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);

    foreach ([1, 2] as $index) {
        $characterization->documents()->create([
            'original_filename' => "existing-{$index}.pdf",
            'stored_path' => "characterization-documents/{$characterization->id}/existing-{$index}.pdf",
            'sha256' => str_repeat((string) $index, 64),
            'size_bytes' => 100,
            'mime' => 'application/pdf',
            'status' => CharacterizationDocument::STATUS_UPLOADED,
        ]);
    }

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('third.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    expect($characterization->documents()->count())->toBe(2)
        ->and(Storage::disk(CharacterizationDocument::STORAGE_DISK)->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('includes pending physical purges in the per-user document quota', function () {
    p6DocsEnableUpload();
    config(['services.p6_document_upload.max_total_bytes' => 100]);
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);

    CharacterizationDocumentPurge::query()->create([
        'source_document_id' => 999999,
        'user_id' => $this->user->id,
        'characterization_id' => $characterization->id,
        'size_bytes' => 100,
        'path_hash' => hash('sha256', 'pending-private-path'),
        'storage_disk' => CharacterizationDocument::STORAGE_DISK,
        'stored_path' => "characterization-documents/{$characterization->id}/pending.pdf",
    ]);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('new.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['document']);

    expect($characterization->documents()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('enforces a global stored-byte ceiling across accounts and pending purges', function () {
    p6DocsEnableUpload();
    config(['services.p6_document_upload.max_global_bytes' => 100]);
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);
    $other = User::factory()->create();

    CharacterizationDocumentPurge::query()->create([
        'source_document_id' => 999998,
        'user_id' => $other->id,
        'size_bytes' => 100,
        'path_hash' => hash('sha256', 'global-pending-private-path'),
        'storage_disk' => CharacterizationDocument::STORAGE_DISK,
        'stored_path' => 'characterization-documents/other/pending.pdf',
    ]);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('new.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertServiceUnavailable();

    expect($characterization->documents()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('serializes the global document quota across concurrent accounts', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();
    p6DocsCharacterization($this->user, $this->e1Topic);
    $lock = Cache::lock('p6-document-global-quota', 120);
    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($this->user)
            ->withHeader('Accept', 'application/json')
            ->post('/api/characterization/documents', [
                'document' => UploadedFile::fake()->createWithContent('concurrent.pdf', P6_DOCS_PDF_CONTENT),
            ])
            ->assertServiceUnavailable();
    } finally {
        $lock->release();
    }

    expect(CharacterizationDocument::query()->count())->toBe(0);
    $this->assertDatabaseCount('characterization_document_purges', 0);
    Queue::assertNothingPushed();
});

it('rate limits document uploads per user', function () {
    // Upload disabled -> each call 404s, but the throttle still counts it.
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->user)
            ->postJson('/api/characterization/documents')
            ->assertNotFound();
    }

    $this->actingAs($this->user)
        ->postJson('/api/characterization/documents')
        ->assertStatus(429);

    // Another user keeps their own budget.
    $this->actingAs(User::factory()->create())
        ->postJson('/api/characterization/documents')
        ->assertNotFound();
});

it('rate limits characterization submits', function () {
    $middleware = \Illuminate\Support\Facades\Route::getRoutes()
        ->getByName('api.characterization.submit')?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('throttle:10,1,characterization-submit');
});

it('keeps separate upload and submit rate limit budgets', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->user)
            ->postJson('/api/characterization/documents')
            ->assertNotFound();
    }

    // Uploads are exhausted, but submit still has its own budget.
    $this->actingAs($this->user)
        ->postJson('/api/characterization/submit', [])
        ->assertStatus(422);
});

it('keeps the queue retry lease longer than the extraction job deadline', function () {
    $jobTimeout = (int) config('services.p6_document_upload.job_timeout');
    $requestTimeout = (int) config('services.p6_document_upload.extract_timeout');
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($jobTimeout)->toBeGreaterThan($requestTimeout)
        ->and($retryAfter)->toBeGreaterThan($jobTimeout);
});

it('enforces a global document-count ceiling across accounts', function () {
    p6DocsEnableUpload();
    config(['services.p6_document_upload.max_global_documents' => 1]);
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);
    $other = User::factory()->create();
    $otherCharacterization = Characterization::factory()->create(['user_id' => $other->id]);
    $otherCharacterization->documents()->create([
        'original_filename' => 'existing.pdf',
        'stored_path' => 'characterization-documents/other/existing.pdf',
        'sha256' => str_repeat('c', 64),
        'size_bytes' => 1,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
    ]);

    $this->actingAs($this->user)
        ->withHeader('Accept', 'application/json')
        ->post('/api/characterization/documents', [
            'document' => UploadedFile::fake()->createWithContent('new.pdf', P6_DOCS_PDF_CONTENT),
        ])
        ->assertServiceUnavailable();

    expect($characterization->documents()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects scanner work when every global scanner slot is occupied', function () {
    p6DocsEnableUpload();
    config(['services.p6_document_upload.scan.max_concurrent' => 1]);
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    Queue::fake();
    p6DocsCharacterization($this->user, $this->e1Topic);
    $slot = Cache::lock('p6-document-scan-slot:0', 60);
    expect($slot->get())->toBeTrue();

    try {
        $this->actingAs($this->user)
            ->withHeader('Accept', 'application/json')
            ->post('/api/characterization/documents', [
                'document' => UploadedFile::fake()->createWithContent('busy.pdf', P6_DOCS_PDF_CONTENT),
            ])
            ->assertServiceUnavailable();
    } finally {
        $slot->release();
    }

    expect(CharacterizationDocument::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('prevents a stale job from overwriting a successor generation', function () {
    p6DocsEnableUpload();
    Http::fake();
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);
    $document = $characterization->documents()->create([
        'original_filename' => 'generation.pdf',
        'stored_path' => 'characterization-documents/generation.pdf',
        'sha256' => str_repeat('d', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_EXTRACTED,
        'extraction_generation' => '22222222-2222-4222-8222-222222222222',
        'extraction_json' => ['status' => 'ok'],
    ]);
    $stale = new ExtractCharacterizationDocumentJob(
        $document->id,
        '11111111-1111-4111-8111-111111111111',
    );

    $stale->handle();
    $stale->failed(new RuntimeException('late stale timeout'));

    expect($document->fresh()->status)->toBe(CharacterizationDocument::STATUS_EXTRACTED)
        ->and($document->fresh()->extraction_generation)->toBe('22222222-2222-4222-8222-222222222222');
    Http::assertNothingSent();
});

it('fails closed in production unless queue leases and distributed locks are operational', function () {
    $this->app['env'] = 'production';
    config([
        'services.p6_document_upload.enabled' => true,
        'queue.default' => 'redis',
        'cache.default' => 'database',
    ]);

    $this->actingAs($this->user)->getJson('/api/characterization/documents')->assertNotFound();

    config([
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 300,
        'services.p6_document_upload.job_timeout' => 300,
    ]);
    $this->actingAs($this->user)->getJson('/api/characterization/documents')->assertNotFound();
});

it('accepts only a strict positive child http job queue deadline chain in production', function () {
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
        'queue.connections.database.retry_after' => 360,
        'cache.default' => 'database',
    ]);

    expect(P6DocumentUploadGuard::available())->toBeTrue();

    foreach ([
        ['services.p6_document_upload.ai_worker_timeout', 0],
        ['services.p6_document_upload.extract_timeout', -1],
        ['services.p6_document_upload.job_timeout', 'invalid'],
        ['queue.connections.database.retry_after', 0],
        ['services.p6_document_upload.ai_worker_timeout', 240],
        ['services.p6_document_upload.extract_timeout', 300],
        ['services.p6_document_upload.job_timeout', 360],
    ] as [$key, $value]) {
        config([$key => $value]);
        expect(P6DocumentUploadGuard::available())->toBeFalse("{$key}={$value} must fail closed");
        config([
            'services.p6_document_upload.ai_worker_timeout' => 180,
            'services.p6_document_upload.extract_timeout' => 240,
            'services.p6_document_upload.job_timeout' => 300,
            'queue.connections.database.retry_after' => 360,
        ]);
    }
});

it('rejects deletion while a document extraction still owns private staged bytes', function () {
    p6DocsEnableUpload();
    Storage::fake(CharacterizationDocument::STORAGE_DISK);
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);
    $document = $characterization->documents()->create([
        'original_filename' => 'active.pdf',
        'stored_path' => "characterization-documents/{$characterization->id}/active.pdf",
        'sha256' => str_repeat('e', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_EXTRACTING,
        'extraction_generation' => '44444444-4444-4444-8444-444444444444',
        'extraction_lease_token' => '55555555-5555-4555-8555-555555555555',
    ]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->put($document->stored_path, P6_DOCS_PDF_CONTENT);

    $this->actingAs($this->user)
        ->deleteJson('/api/characterization/documents/'.$document->id)
        ->assertConflict();

    $this->assertDatabaseHas('characterization_documents', ['id' => $document->id]);
    Storage::disk(CharacterizationDocument::STORAGE_DISK)->assertExists($document->stored_path);
});

it('does not execute a queued extraction after production runtime qualification drifts', function () {
    $this->app['env'] = 'production';
    p6DocsEnableUpload();
    config([
        'services.p6_document_upload.scan.enabled' => true,
        'services.p6_document_upload.scan.binary' => 'clamscan',
        'services.p6_document_upload.ai_worker_timeout' => 180,
        'services.p6_document_upload.extract_timeout' => 240,
        'services.p6_document_upload.job_timeout' => 300,
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 90,
        'cache.default' => 'database',
    ]);
    Http::fake();
    $characterization = p6DocsCharacterization($this->user, $this->e1Topic);
    $document = $characterization->documents()->create([
        'original_filename' => 'drift.pdf',
        'stored_path' => 'characterization-documents/drift.pdf',
        'sha256' => str_repeat('f', 64),
        'size_bytes' => 100,
        'mime' => 'application/pdf',
        'status' => CharacterizationDocument::STATUS_UPLOADED,
        'extraction_generation' => '66666666-6666-4666-8666-666666666666',
    ]);

    (new ExtractCharacterizationDocumentJob($document->id, $document->extraction_generation))->handle();

    expect($document->fresh()->status)->toBe(CharacterizationDocument::STATUS_UPLOADED);
    Http::assertNothingSent();
});
