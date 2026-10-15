<?php

namespace App\Console\Commands;

use App\Services\Usability\StudyAnalysis;
use Illuminate\Console\Command;

final class UsabilityReport extends Command
{
    protected $signature = 'usability:report {--csv : Export pseudonymous participant/task rows as CSV}';

    protected $description = 'Compute consented usability metrics without account identities or free text';

    public function handle(StudyAnalysis $analysis): int
    {
        if (! config('usability.enabled')) {
            $this->error('USABILITY_STUDY_ENABLED is disabled.');
            return self::FAILURE;
        }
        $report = $analysis->report();
        $this->output->write($this->option('csv') ? $analysis->csv($report) : json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        return self::SUCCESS;
    }
}
