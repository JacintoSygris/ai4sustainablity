<?php

namespace App\Services;

use App\Jobs\ExtractCharacterizationDocumentJob;
use App\Models\CharacterizationDocument;
use App\Support\P6DocumentUploadGuard;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CharacterizationDocumentDispatchService
{
    public function dispatch(int $documentId, string $generation): bool
    {
        if (! P6DocumentUploadGuard::available()) {
            return false;
        }

        try {
            ExtractCharacterizationDocumentJob::dispatch($documentId, $generation);
        } catch (Throwable $exception) {
            Log::error('Characterization document extraction dispatch deferred', [
                'document_id' => $documentId,
                'error_type' => $exception::class,
            ]);

            return false;
        }

        try {
            CharacterizationDocument::query()
                ->whereKey($documentId)
                ->where('status', CharacterizationDocument::STATUS_UPLOADED)
                ->where('extraction_generation', $generation)
                ->update(['extraction_dispatched_at' => now()]);
        } catch (Throwable $exception) {
            Log::warning('Characterization document extraction dispatch marker deferred', [
                'document_id' => $documentId,
                'error_type' => $exception::class,
            ]);
        }

        return true;
    }
}
