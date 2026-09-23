<?php

use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\EsrsTopic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const P6_DOCS_PDF_CONTENT = "%PDF-1.7\nDocumento de prueba con contenido suficiente.";
const P6_DOCS_DOCX_CONTENT = "PK\x03\x04Documento docx de prueba con contenido suficiente.";

beforeEach(function () {
    config(['services.characterization.api.base_url' => 'http://127.0.0.1:8001']);

    $this->seed(\Database\Seeders\EsrsTopicSeeder::class);

    $this->user = User::factory()->create();
    $this->e1Topic = EsrsTopic::where('esrs_code', 'E1')->firstOrFail();
});

function p6DocsEnableUpload(): void
{
    config(['services.p6_document_upload.enabled' => true]);
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
        '*/extract-document' => Http::response([
            'document_id' => '1',
            'sha256' => 'reflected-by-service',
            'status' => $status,
            'parser_version' => 'v3',
            'config_version' => 'gate-run-4',
            'evidence' => $evidence,
        ]),
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

    // The dependent document-only topic stays visible in the deleted state,
    // with provenance only: pages, confidences and snippets are purged.
    expect($evidence['topics'])->toHaveCount(1);
    expect($evidence['topics'][0]['esrs_code'])->toBe('E1');
    expect($evidence['topics'][0]['kind'])->toBe('positive');
    expect($evidence['topics'][0]['evidence'])->toBe([['document_id' => $document->id]]);
    expect(json_encode($evidence, JSON_UNESCAPED_UNICODE))->not->toContain($snippet);
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

    // The base payload must not drift when documents arrive; time travel exposes
    // touched-parent timestamp drift that same-second runs mask.
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
