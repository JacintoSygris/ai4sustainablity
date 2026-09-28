<?php

namespace App\Jobs;

use App\Models\CharacterizationDocument;
use App\Support\CharacterizationStateVersion;
use App\Support\P6DocumentUploadGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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

    public bool $failOnTimeout = true;

    public readonly string $leaseToken;

    public function __construct(
        public readonly int $documentId,
        public readonly string $generation,
    ) {
        $this->timeout = (int) config('services.p6_document_upload.job_timeout', 300);
        $this->leaseToken = (string) Str::uuid();
    }

    public function handle(): void
    {
        if (! P6DocumentUploadGuard::available()) {
            return;
        }

        $document = DB::transaction(function (): ?CharacterizationDocument {
            $document = CharacterizationDocument::query()->lockForUpdate()->find($this->documentId);
            if (! $document
                || ! hash_equals((string) $document->extraction_generation, $this->generation)
                || ! in_array($document->status, [
                    CharacterizationDocument::STATUS_UPLOADED,
                    CharacterizationDocument::STATUS_EXTRACTING,
                ], true)
                || ($document->status === CharacterizationDocument::STATUS_EXTRACTING
                    && ! hash_equals((string) $document->extraction_lease_token, $this->leaseToken))) {
                return null;
            }

            $document->forceFill([
                'status' => CharacterizationDocument::STATUS_EXTRACTING,
                'extraction_lease_token' => $this->leaseToken,
                'extraction_started_at' => now(),
            ])->save();

            return $document->fresh();
        }, 3);

        if (! $document || ! $document->characterization) {
            return;
        }

        try {
            $body = $this->requestExtraction($document);

            DB::transaction(function () use ($body): void {
                $current = CharacterizationDocument::query()->lockForUpdate()->find($this->documentId);
                if (! $this->owns($current)) {
                    return;
                }

                $current->forceFill([
                    'status' => match ($body['status'] ?? null) {
                        'ok' => CharacterizationDocument::STATUS_EXTRACTED,
                        'no_usable_evidence' => CharacterizationDocument::STATUS_NO_USABLE_EVIDENCE,
                        default => CharacterizationDocument::STATUS_FAILED,
                    },
                    'extraction_json' => $body,
                    'merged_state_version' => CharacterizationStateVersion::hash($current->characterization),
                    'extraction_lease_token' => null,
                ])->save();
            }, 3);

            Log::info('Characterization document extraction finished', [
                'document_id' => $this->documentId,
                'sha256' => $document->sha256,
                'status' => $body['status'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            $this->markFailedIfOwned();

            // Filenames and exception messages may contain private user text.
            Log::error('Characterization document extraction failed', [
                'document_id' => $this->documentId,
                'error_type' => $exception::class,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailedIfOwned();

        Log::error('Characterization document extraction job terminated', [
            'document_id' => $this->documentId,
            'error_type' => $exception ? $exception::class : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestExtraction(CharacterizationDocument $document): array
    {
        $baseUrl = P6DocumentUploadGuard::apiBaseUrl();
        $apiToken = trim((string) config('services.characterization.api.token'));

        if ($baseUrl === null) {
            throw new RuntimeException('Characterization API base URL is not configured.');
        }
        if ($apiToken === '') {
            throw new RuntimeException('Characterization API token is not configured.');
        }

        $timeout = max(1, (int) config('services.p6_document_upload.extract_timeout', 240));
        $deadline = microtime(true) + $timeout;
        $response = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $remaining = max(1, (int) floor($deadline - microtime(true)));
            try {
                $response = Http::connectTimeout(min(5, $remaining))
                    ->timeout($remaining)
                    ->withToken($apiToken)
                    ->post("{$baseUrl}/extract-document", [
                        'document_id' => (string) $document->id,
                        'document_path' => Storage::disk(CharacterizationDocument::STORAGE_DISK)->path($document->stored_path),
                        'original_filename' => $document->original_filename,
                        'sha256' => $document->sha256,
                    ]);
            } catch (ConnectionException $exception) {
                if ($attempt >= 2 || microtime(true) >= $deadline - 1) {
                    throw $exception;
                }

                usleep(250_000);

                continue;
            }

            if (! in_array($response->status(), [429, 503], true)
                || $attempt >= 2
                || microtime(true) >= $deadline - 1) {
                break;
            }

            $retryAfter = min(2, max(0, (int) $response->header('Retry-After', '0')));
            if ($retryAfter > 0) {
                usleep($retryAfter * 1_000_000);
            }
        }

        if ($response === null) {
            throw new RuntimeException('Document extraction service did not return a response.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Document extraction service responded with HTTP '.$response->status().'.');
        }

        if (strlen($response->body()) > 131072) {
            throw new RuntimeException('Document extraction service response is too large.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Document extraction service response is not a JSON object.');
        }

        return $this->validateResponse($body, $document);
    }

    private function owns(?CharacterizationDocument $document): bool
    {
        return $document !== null
            && $document->status === CharacterizationDocument::STATUS_EXTRACTING
            && hash_equals((string) $document->extraction_generation, $this->generation)
            && hash_equals((string) $document->extraction_lease_token, $this->leaseToken);
    }

    private function markFailedIfOwned(): void
    {
        CharacterizationDocument::query()
            ->whereKey($this->documentId)
            ->where('status', CharacterizationDocument::STATUS_EXTRACTING)
            ->where('extraction_generation', $this->generation)
            ->where('extraction_lease_token', $this->leaseToken)
            ->update([
                'status' => CharacterizationDocument::STATUS_FAILED,
                'extraction_json' => null,
                'extraction_lease_token' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function validateResponse(array $body, CharacterizationDocument $document): array
    {
        $requiredKeys = ['document_id', 'sha256', 'status', 'parser_version', 'config_version', 'evidence'];
        $allowedKeys = [...$requiredKeys, 'message'];
        $keys = array_keys($body);
        sort($keys);
        sort($requiredKeys);
        $missing = array_diff($requiredKeys, $keys);
        $unexpected = array_diff($keys, $allowedKeys);
        if ($missing !== [] || $unexpected !== []) {
            throw new RuntimeException('Document extraction service response has an invalid root schema.');
        }

        if (! is_string($body['document_id']) || ! hash_equals((string) $document->id, $body['document_id'])) {
            throw new RuntimeException('Document extraction response document id mismatch.');
        }
        if (! is_string($body['sha256']) || ! hash_equals((string) $document->sha256, $body['sha256'])) {
            throw new RuntimeException('Document extraction response hash mismatch.');
        }
        if ($body['parser_version'] !== 'v3' || $body['config_version'] !== 'gate-run-4') {
            throw new RuntimeException('Document extraction response version mismatch.');
        }

        $status = $body['status'];
        if (! in_array($status, ['ok', 'no_usable_evidence', 'error'], true)) {
            throw new RuntimeException('Document extraction response status is invalid.');
        }
        if (! is_array($body['evidence']) || count($body['evidence']) > 100) {
            throw new RuntimeException('Document extraction response evidence is invalid.');
        }
        if (($status === 'ok') !== ($body['evidence'] !== [])) {
            throw new RuntimeException('Document extraction response status and evidence disagree.');
        }

        foreach ($body['evidence'] as $row) {
            $this->validateEvidenceRow($row);
        }

        if (array_key_exists('message', $body)
            && (! is_string($body['message']) || mb_strlen($body['message']) > 200)) {
            throw new RuntimeException('Document extraction response message is invalid.');
        }
        if ($status === 'error') {
            throw new RuntimeException('Document extraction service reported an error.');
        }

        return $body;
    }

    private function validateEvidenceRow(mixed $row): void
    {
        if (! is_array($row)) {
            throw new RuntimeException('Document extraction evidence row is not an object.');
        }

        $keys = array_keys($row);
        sort($keys);
        $expected = ['standard', 'topic_key', 'kind', 'confidence', 'page', 'snippet'];
        sort($expected);
        if ($keys !== $expected
            || ! in_array($row['standard'], ['E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1'], true)
            || (! is_null($row['topic_key']) && (! is_string($row['topic_key'])
                || preg_match('/\A[a-z0-9_]{1,100}\z/', $row['topic_key']) !== 1))
            || ! in_array($row['kind'], ['positive', 'negative'], true)
            || ! is_numeric($row['confidence'])
            || (float) $row['confidence'] < 0.0
            || (float) $row['confidence'] > 1.0
            || (! is_null($row['page']) && (! is_int($row['page']) || $row['page'] < 1 || $row['page'] > 100000))
            || ! is_string($row['snippet'])
            || trim($row['snippet']) === ''
            || mb_strlen($row['snippet']) > 300) {
            throw new RuntimeException('Document extraction evidence row is invalid.');
        }
    }
}
