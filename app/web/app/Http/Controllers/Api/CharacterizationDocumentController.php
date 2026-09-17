<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractCharacterizationDocumentJob;
use App\Models\Characterization;
use App\Models\CharacterizationDocument;
use App\Services\DocumentEvidencePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CharacterizationDocumentController extends Controller
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'docx'];

    private const MAX_SIZE_KILOBYTES = 51200; // 50 MB

    private const MAGIC_BYTES_BY_EXTENSION = [
        'pdf' => '%PDF',
        'docx' => "PK\x03\x04",
    ];

    public function __construct(private readonly DocumentEvidencePresenter $presenter) {}

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

        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();

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
        $scan = \App\Support\UploadVirusScanner::scan($file);
        if (! $scan['ok']) {
            $message = $scan['result'] === \App\Support\UploadVirusScanner::INFECTED
                ? 'El documento no ha superado el análisis de seguridad y no se ha guardado.'
                : 'No se ha podido analizar el documento en este momento. Inténtalo de nuevo más tarde.';
            throw \Illuminate\Validation\ValidationException::withMessages(['document' => $message]);
        }

        $storedPath = $file->storeAs(
            'characterization-documents/'.$characterization->id,
            Str::uuid().'.'.$extension,
            CharacterizationDocument::STORAGE_DISK
        );

        if (! is_string($storedPath)) {
            abort(500, 'No se pudo guardar el documento. Inténtalo de nuevo.');
        }

        $document = CharacterizationDocument::create([
            'characterization_id' => $characterization->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'size_bytes' => (int) $file->getSize(),
            'mime' => (string) $file->getMimeType(),
            'status' => CharacterizationDocument::STATUS_UPLOADED,
        ]);

        ExtractCharacterizationDocumentJob::dispatch($document);

        return response()->json(['data' => $this->documentSummary($document)], 201);
    }

    public function destroy(Request $request, int $document)
    {
        $this->abortUnlessEnabled();

        $characterization = Characterization::forUser($request->user()->id)->firstOrFail();

        /** @var CharacterizationDocument $documentModel */
        $documentModel = $characterization->documents()->findOrFail($document);

        $this->storeDeletionTombstone($characterization, $documentModel);

        // Hard delete: the model deleting hook removes the stored file, and the
        // row (with its extraction JSON, evidence and snippets) is purged.
        $documentModel->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function abortUnlessEnabled(): void
    {
        abort_unless((bool) config('services.p6_document_upload.enabled'), 404);
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
            'original_filename' => $document->original_filename,
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
