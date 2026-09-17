<?php

namespace App\Jobs;

use App\Models\CharacterizationDocument;
use App\Support\CharacterizationStateVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ExtractCharacterizationDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Per-job timeout: a wedged conversion must never block the queue.
     */
    public int $timeout;

    /**
     * Extraction failures never retry and never block the classifier proposal.
     */
    public int $tries = 1;

    public function __construct(public CharacterizationDocument $document)
    {
        $this->timeout = (int) config('services.p6_document_upload.job_timeout', 300);
    }

    public function handle(): void
    {
        if (! config('services.p6_document_upload.enabled')) {
            return;
        }

        $document = $this->document->fresh();

        if (! $document || ! $document->characterization) {
            return;
        }

        $document->forceFill(['status' => CharacterizationDocument::STATUS_EXTRACTING])->save();

        try {
            $body = $this->requestExtraction($document);

            $document->forceFill([
                'status' => match ($body['status'] ?? null) {
                    'ok' => CharacterizationDocument::STATUS_EXTRACTED,
                    'no_usable_evidence' => CharacterizationDocument::STATUS_NO_USABLE_EVIDENCE,
                    default => CharacterizationDocument::STATUS_FAILED,
                },
                'extraction_json' => $body,
                'merged_state_version' => CharacterizationStateVersion::hash($document->characterization),
            ])->save();

            Log::info('Characterization document extraction finished', [
                'document_id' => $document->id,
                'sha256' => $document->sha256,
                'status' => $document->status,
            ]);
        } catch (\Throwable $exception) {
            $document->forceFill(['status' => CharacterizationDocument::STATUS_FAILED])->save();

            // Log redaction: filename + hash + ids only, never document content.
            Log::error('Characterization document extraction failed', [
                'document_id' => $document->id,
                'original_filename' => $document->original_filename,
                'sha256' => $document->sha256,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestExtraction(CharacterizationDocument $document): array
    {
        $baseUrl = rtrim((string) config('services.characterization.api.base_url'), '/');

        if (blank($baseUrl)) {
            throw new RuntimeException('Characterization API base URL is not configured.');
        }

        $response = Http::timeout((int) config('services.p6_document_upload.extract_timeout', 240))
            ->post("{$baseUrl}/extract-document", [
                'document_id' => (string) $document->id,
                'document_path' => Storage::disk(CharacterizationDocument::STORAGE_DISK)->path($document->stored_path),
                'original_filename' => $document->original_filename,
                'sha256' => $document->sha256,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Document extraction service responded with HTTP '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Document extraction service response is not a JSON object.');
        }

        return $body;
    }
}
