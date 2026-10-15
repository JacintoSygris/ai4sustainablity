<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PurgeUsabilityStudy extends Command
{
    protected $signature = 'usability:purge {--all : Erase all study data at study closure}';

    protected $description = 'Erase usability records older than 90 days, or all records on closure';

    public function handle(): int
    {
        if (! Schema::hasTable('usability_participants')) {
            return self::SUCCESS;
        }
        $cutoff = now()->subDays(90);
        $query = DB::table('usability_participants');
        if (! $this->option('all')) {
            $query->where('enrolled_at', '<=', $cutoff);
        }
        $deleted = 0;
        foreach ($query->get(['study_participant_id', 'user_id']) as $candidate) {
            $deleted += DB::transaction(function () use ($candidate, $cutoff): int {
                DB::table('users')->where('id', $candidate->user_id)->lockForUpdate()->first();
                $query = DB::table('usability_participants')->where('study_participant_id', $candidate->study_participant_id)->lockForUpdate();
                if (! $this->option('all')) {
                    $query->where('enrolled_at', '<=', $cutoff);
                }
                if ($query->first() === null) {
                    return 0;
                }
                return DB::table('usability_participants')->where('study_participant_id', $candidate->study_participant_id)->delete();
            });
        }
        $this->info((string) $deleted);
        return self::SUCCESS;
    }
}
