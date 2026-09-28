<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Models\CharacterizationDocumentPurge;
use App\Services\CharacterizationDocumentDispatchService;
use App\Services\CharacterizationDocumentPurgeService;
use App\Services\CharacterizationStateTransaction;
use App\Services\DocumentEvidencePresenter;
use App\Support\P6DocumentUploadGuard;
use App\Support\UploadVirusScanner;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CharacterizationDocumentController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'docx'];

    private const MAX_SIZE_KILOBYTES = 51200; // 50 MB

    private const MAGIC_BYTES_BY_EXTENSION = [
        'pdf' => '%PDF',
        'docx' => "PK\x03\x04",
    ];

    public function __construct(
        private readonly DocumentEvidencePresenter $presenter,
        private readonly CharacterizationStateTransaction $stateTransactions,
        private readonly CharacterizationDocumentPurgeService $documentPurges,
        private readonly CharacterizationDocumentDispatchService $documentDispatch,
    ) {}

    public function index(Request $request)
    {
        $this->abortUnlessEnabled();

        $characterization = Characterization::forUser($request->user()->id)->first();

        $documents = $characterization
            ? $characterization->documents()->orderBy('id')->get()
            : collect();

        return response()->json([
            'data' => [
                'documents' => $documents
                    ->map(fn (CharacterizationDocument $document) => $this->documentSummary($document))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->abortUnlessEnabled();

        $request->validate([
            'document' => ['required', 'file', 'max:'.self::MAX_SIZE_KILOBYTES],
        ], [
            'document.required' => 'Selecciona un documento para subirlo.',
            'document.file' => 'El archivo no se pudo recibir. Inténtalo de nuevo.',
            'document.max' => 'El documento supera el tamaño máximo de 50 MB.',
        ]);

        $file = $request->file('document');

        $extension = $this->validatedExtension($file);
        $this->validateMagicBytes($file, $extension);

        // Fail-closed virus scan (config-gated; disabled locally). Scan BEFORE
        // storing so an infected/unscannable file never lands on disk.
        $scanSlot = $this->acquireScanSlot();
        try {
            $scan = UploadVirusScanner::scan($file);
            if (! $scan['ok']) {
                $message = $scan['result'] === UploadVirusScanner::INFECTED
                    ? 'El documento no ha superado el análisis de seguridad y no se ha guardado.'
                    : 'No se ha podido analizar el documento en este momento. Inténtalo de nuevo más tarde.';
                throw ValidationException::withMessages(['document' => $message]);
            }
        } finally {
            $scanSlot->release();
        }

        $globalQuotaLock = Cache::lock('p6-document-global-quota', 120);
        if (! $globalQuotaLock->get()) {
            abort(503, 'El almacenamiento de documentos está ocupado. Inténtalo de nuevo.');
        }

        try {
            $characterization = Characterization::forUser($request->user()->id)->firstOrFail();
            $filename = Str::uuid().'.'.$extension;
            $storedPath = 'characterization-documents/'.$characterization->id.'/'.$filename;
            $uploadIntent = $this->documentPurges->stageUnregisteredPath(
                (int) $request->user()->id,
                (int) $characterization->id,
                (int) $file->getSize(),
                $storedPath,
            );

            try {
                $document = $this->stateTransactions->runForUser(
                    $request->user()->id,
                    function (Characterization $locked) use ($file, $filename, $uploadIntent): CharacterizationDocument {
                        $lockedUploadIntent = CharacterizationDocumentPurge::query()
                            ->lockForUpdate()
                            ->findOrFail($uploadIntent->id);
                        $maxDocuments = max(1, (int) config('services.p6_document_upload.max_documents', 5));
                        $maxTotalBytes = max(1, (int) config('services.p6_document_upload.max_total_bytes', 262144000));
                        $currentCount = $locked->documents()->count();
                        $currentBytes = (int) $locked->documents()->sum('size_bytes');
                        $pendingPurges = CharacterizationDocumentPurge::query()
                            ->where('user_id', $locked->user_id)
                            ->whereKeyNot($lockedUploadIntent->id);
                        $currentCount += (clone $pendingPurges)->count();
                        $currentBytes += (int) (clone $pendingPurges)->sum('size_bytes');
                        $incomingBytes = (int) $file->getSize();

                        if ($currentCount >= $maxDocuments || $currentBytes + $incomingBytes > $maxTotalBytes) {
                            throw ValidationException::withMessages([
                                'document' => 'Se ha alcanzado el límite de documentos almacenados. Elimina uno antes de subir otro.',
                            ]);
                        }

                        $maxGlobalBytes = max(1, (int) config('services.p6_document_upload.max_global_bytes', 5368709120));
                        $maxGlobalDocuments = max(1, (int) config('services.p6_document_upload.max_global_documents', 1000));
                        $globalBytes = (int) CharacterizationDocument::query()->sum('size_bytes')
                            + (int) CharacterizationDocumentPurge::query()
                                ->whereKeyNot($lockedUploadIntent->id)
                                ->sum('size_bytes');
                        if ($globalBytes + $incomingBytes > $maxGlobalBytes) {
                            abort(503, 'El almacenamiento de documentos no está disponible en este momento.');
                        }
                        $globalDocuments = CharacterizationDocument::query()->count()
                            + CharacterizationDocumentPurge::query()->whereKeyNot($lockedUploadIntent->id)->count();
                        if ($globalDocuments >= $maxGlobalDocuments) {
                            abort(503, 'El almacenamiento de documentos no está disponible en este momento.');
                        }

                        $storedPath = $file->storeAs(
                            'characterization-documents/'.$locked->id,
                            $filename,
                            CharacterizationDocument::STORAGE_DISK
                        );

                        if (! is_string($storedPath)) {
                            abort(500, 'No se pudo guardar el documento. Inténtalo de nuevo.');
                        }

                        $document = $locked->documents()->create([
                            'original_filename' => $file->getClientOriginalName(),
                            'stored_path' => $storedPath,
                            'sha256' => hash_file('sha256', $file->getRealPath()),
                            'size_bytes' => (int) $file->getSize(),
                            'mime' => (string) $file->getMimeType(),
                            'status' => CharacterizationDocument::STATUS_UPLOADED,
                            'extraction_generation' => (string) Str::uuid(),
                        ]);
                        $lockedUploadIntent->delete();

                        return $document;
                    }
                );
            } catch (Throwable $exception) {
                $this->documentPurges->recoverUnregisteredPath((int) $uploadIntent->id);

                throw $exception;
            }
        } finally {
            $globalQuotaLock->release();
        }

        $this->documentDispatch->dispatch(
            (int) $document->getKey(),
            (string) $document->extraction_generation,
        );

        return response()->json(['data' => $this->documentSummary($document->fresh() ?? $document)], 201);
    }

    public function destroy(Request $request, int $document)
    {
        $this->abortUnlessEnabled();

        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();

        $purgeIds = $this->stateTransactions->run($characterization->id, function (Characterization $locked) use ($document): array {
            /** @var CharacterizationDocument $documentModel */
            $documentModel = $locked->documents()->lockForUpdate()->findOrFail($document);
            abort_if(
                $documentModel->status === CharacterizationDocument::STATUS_EXTRACTING,
                409,
                'El documento se está procesando. Inténtalo de nuevo cuando termine.'
            );

            $this->storeDeletionTombstone($locked, $documentModel);

            // The model hook writes a durable purge intent in this transaction;
            // physical bytes are removed only after the commit is irreversible.
            $documentModel->delete();

            return $this->documentPurges->intentIdsForDocumentIds([$documentModel->id]);
        });

        $purgePending = $this->documentPurges->purgeAfterCommit($purgeIds);

        return response()->json(['data' => [
            'deleted' => true,
            'purge_status' => $purgePending ? 'pending' : 'completed',
        ]]);
    }

    private function abortUnlessEnabled(): void
    {
        abort_unless(P6DocumentUploadGuard::available(), 404);
    }

    private function acquireScanSlot(): mixed
    {
        $slots = max(1, min(32, (int) config('services.p6_document_upload.scan.max_concurrent', 2)));
        $ttl = max(5, (int) ceil((float) config('services.p6_document_upload.scan.timeout_seconds', 30)) + 10);

        for ($slot = 0; $slot < $slots; $slot++) {
            $lock = Cache::lock("p6-document-scan-slot:{$slot}", $ttl);
            if ($lock->get()) {
                return $lock;
            }
        }

        abort(503, 'El análisis de documentos está ocupado. Inténtalo de nuevo.');
    }

    private function validatedExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'document' => 'Solo se admiten documentos PDF o DOCX.',
            ]);
        }

        return $extension;
    }

    private function validateMagicBytes(UploadedFile $file, string $extension): void
    {
        $expected = self::MAGIC_BYTES_BY_EXTENSION[$extension];
        $realPath = $file->getRealPath();
        $header = is_string($realPath) && $realPath !== ''
            ? (string) file_get_contents($realPath, false, null, 0, strlen($expected))
            : '';

        // Fail-closed: an unreadable header or a mismatch rejects the upload.
        if ($header !== $expected) {
            throw ValidationException::withMessages([
                'document' => 'El contenido del archivo no coincide con un documento PDF o DOCX válido.',
            ]);
        }
    }

    private function storeDeletionTombstone(
        Characterization $characterization,
        CharacterizationDocument $document
    ): void {
        $formData = $characterization->form_data ?? [];
        $tombstones = Arr::get($formData, DocumentEvidencePresenter::TOMBSTONES_FORM_DATA_KEY, []);
        $tombstones = is_array($tombstones) ? $tombstones : [];

        // Only topic identities survive (standard/topic_key/kind); pages,
        // snippets and confidences are purged with the document (gate 6.4-ter).
        $tombstones[] = [
            'document_id' => $document->id,
            'deleted_at' => now()->toJSON(),
            'topics' => $this->presenter->topicIdentityRows($document->extraction_json),
        ];

        Arr::set($formData, DocumentEvidencePresenter::TOMBSTONES_FORM_DATA_KEY, $tombstones);

        $characterization->forceFill(['form_data' => $formData])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function documentSummary(CharacterizationDocument $document): array
    {
        return [
            'id' => $document->id,
            'original_filename' => $document->original_filename,
            'status' => $document->status,
            'size_bytes' => $document->size_bytes,
            'created_at' => $document->created_at?->toJSON(),
        ];
    }
}
