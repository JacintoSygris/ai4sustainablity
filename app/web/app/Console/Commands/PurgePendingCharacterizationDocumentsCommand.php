<?php

namespace App\Console\Commands;

use App\Services\CharacterizationDocumentPurgeService;
use Illuminate\Console\Command;

class PurgePendingCharacterizationDocumentsCommand extends Command
{
    protected $signature = 'characterization-documents:purge-pending {--limit=100}';

    protected $description = 'Queue idempotent retries for pending private document purges';

    public function handle(CharacterizationDocumentPurgeService $purges): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $claims = $purges->claimEligibleForDispatch($limit);
        $queued = 0;
        $failures = 0;

        foreach ($claims as $claim) {
            if ($purges->dispatchClaim($claim)) {
                $queued++;
            } else {
                $failures++;
            }
        }

        $message = 'Queued '.$queued.' pending private document purge(s).';
        if ($failures > 0) {
            $message = 'Queued '.$queued.' pending private document purge(s); '.$failures.' dispatch failure(s).';
        }

        $this->info($message);

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
