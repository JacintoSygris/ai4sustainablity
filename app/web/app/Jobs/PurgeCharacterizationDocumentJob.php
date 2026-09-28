<?php

namespace App\Jobs;

use App\Services\CharacterizationDocumentPurgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class PurgeCharacterizationDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public int $uniqueFor = 900;

    public function __construct(public int $purgeId) {}

    public function handle(CharacterizationDocumentPurgeService $purges): void
    {
        $purges->purgeOrFail($this->purgeId);
    }

    public function uniqueId(): string
    {
        return 'characterization-document-purge:'.$this->purgeId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(60)
                ->expireAfter(3600),
        ];
    }
}
