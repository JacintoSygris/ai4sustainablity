<?php

namespace App\Console\Commands;

use App\Services\Report\EsrsTaxonomyPackageRepository;
use Illuminate\Console\Command;
use RuntimeException;

class ValidateTaxonomyAssets extends Command
{
    protected $signature = 'taxonomy:validate-assets {version=esrs-set1-2024}';

    protected $description = 'Validate vendored XBRL taxonomy technical input assets.';

    public function handle(EsrsTaxonomyPackageRepository $packages): int
    {
        try {
            $package = $packages->verified($this->argument('version'));
        } catch (RuntimeException $e) {
            $this->error('taxonomy_integrity_failed:'.$e->getMessage());

            return self::FAILURE;
        }

        $metadata = $package->metadata();

        $this->info('taxonomy:validate-assets OK');
        $this->line('version:'.$metadata['version']);
        $this->line('published_date:'.$metadata['published_date']);
        $this->line('entrypoint:'.$metadata['entrypoint']);
        $this->line('catalog:'.$metadata['catalog']);
        $this->line('member_count:'.$metadata['member_count']);

        return self::SUCCESS;
    }
}
