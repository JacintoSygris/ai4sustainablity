<?php

namespace App\Console\Commands;

use App\Models\CharacterizationDocument;
use App\Services\CharacterizationDocumentDispatchService;
use App\Support\P6DocumentUploadGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecoverStaleCharacterizationDocumentsCommand extends Command
{
    protected $signature = 'characterization-documents:recover-stale-extractions';

    protected $description = 'Redispatch durable upload intents and replace stale extraction leases';

    public function handle(CharacterizationDocumentDispatchService $dispatch): int
    {
        if (! config('services.p6_document_upload.enabled')) {
            $this->info('P6 document upload is disabled; no extraction intents were dispatched.');

            return self::SUCCESS;
        }
        if (! P6DocumentUploadGuard::available()) {
            $this->error('P6 document runtime qualification failed; extraction intents were preserved.');

            return self::FAILURE;
        }

        $staleDeadline = now()->subSeconds(
            max(1, (int) config('services.p6_document_upload.job_timeout', 300)) + 120,
        );
        $redispatchDeadline = now()->subMinutes(10);
        $pending = [];

        CharacterizationDocument::query()
            ->where('status', CharacterizationDocument::STATUS_EXTRACTING)
            ->where(function ($query) use ($staleDeadline): void {
                $query->where('extraction_started_at', '<=', $staleDeadline)
                    ->orWhere(function ($legacy) use ($staleDeadline): void {
                        $legacy->whereNull('extraction_started_at')->where('updated_at', '<=', $staleDeadline);
                    });
            })
            ->orderBy('id')
            ->eachById(function (CharacterizationDocument $candidate) use (&$pending): void {
                $envelope = DB::transaction(function () use ($candidate): ?array {
                    $document = CharacterizationDocument::query()->lockForUpdate()->find($candidate->id);
                    if (! $document || $document->status !== CharacterizationDocument::STATUS_EXTRACTING) {
                        return null;
                    }

                    $generation = (string) Str::uuid();
                    $document->forceFill([
                        'status' => CharacterizationDocument::STATUS_UPLOADED,
                        'extraction_generation' => $generation,
                        'extraction_lease_token' => null,
                        'extraction_dispatched_at' => null,
                        'extraction_started_at' => null,
                        'extraction_json' => null,
                    ])->save();

                    return [(int) $document->id, $generation];
                }, 3);

                if ($envelope !== null) {
                    $pending[$envelope[0]] = $envelope;
                }
            });

        CharacterizationDocument::query()
            ->where('status', CharacterizationDocument::STATUS_UPLOADED)
            ->where(function ($query) use ($redispatchDeadline): void {
                $query->whereNull('extraction_dispatched_at')
                    ->orWhere('extraction_dispatched_at', '<=', $redispatchDeadline);
            })
            ->orderBy('id')
            ->eachById(function (CharacterizationDocument $candidate) use (&$pending): void {
                $envelope = DB::transaction(function () use ($candidate): ?array {
                    $document = CharacterizationDocument::query()->lockForUpdate()->find($candidate->id);
                    if (! $document || $document->status !== CharacterizationDocument::STATUS_UPLOADED) {
                        return null;
                    }

                    $generation = (string) ($document->extraction_generation ?: Str::uuid());
                    if (! $document->extraction_generation) {
                        $document->forceFill(['extraction_generation' => $generation])->save();
                    }

                    return [(int) $document->id, $generation];
                }, 3);

                if ($envelope !== null) {
                    $pending[$envelope[0]] = $envelope;
                }
            });

        $dispatched = 0;
        $failed = 0;
        foreach ($pending as [$documentId, $generation]) {
            if ($dispatch->dispatch($documentId, $generation)) {
                $dispatched++;
            } else {
                $failed++;
            }
        }

        $this->info("Dispatched {$dispatched} pending or recovered document extraction(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
