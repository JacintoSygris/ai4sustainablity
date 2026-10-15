<?php

namespace App\Console\Commands;

use App\Services\Report\XbrlConceptSuggestionRepository;
use Illuminate\Console\Command;
use Throwable;

class XbrlConceptSuggestions extends Command
{
    protected $signature = 'xbrl:concept-suggestions {--list} {--accept=} {--reject=} {--reviewer=}';

    protected $description = '';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('xbrl_suggestions.description'));
    }

    public function handle(XbrlConceptSuggestionRepository $repository): int
    {
        $accept = $this->option('accept');
        $reject = $this->option('reject');
        $list = (bool) $this->option('list');
        if ((int) $list + (int) ($accept !== null) + (int) ($reject !== null) !== 1
            || ((! $list) && ! is_string($this->option('reviewer')))) {
            $this->error(__('xbrl_suggestions.usage'));

            return self::FAILURE;
        }
        try {
            if ($list) {
                $latest = $repository->latest();
                $accepted = $repository->accepted();
                $rows = [];
                foreach ($repository->suggestions() as $id => $row) {
                    $state = $latest[$id]['decision'] ?? 'pending_review';
                    if ($state === 'accepted' && ($accepted[$row['datapoint_id']]['concept_id'] ?? null) !== $row['concept_id']) {
                        $state = 'stale_approval';
                    }
                    $rows[] = [$id, $row['datapoint_id'], $row['concept_id'], $row['confidence'], __('xbrl_suggestions.'.$state)];
                }
                $this->table(__('xbrl_suggestions.headers'), $rows);
            } else {
                $repository->record((string) ($accept ?? $reject), $accept !== null ? 'accepted' : 'rejected', (string) $this->option('reviewer'));
                $this->info(__('xbrl_suggestions.recorded'));
            }
        } catch (Throwable) {
            $this->error(__('xbrl_suggestions.failed'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
