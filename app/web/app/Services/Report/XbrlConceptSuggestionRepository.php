<?php

namespace App\Services\Report;

use RuntimeException;

/** Operator-only review. No model output is itself an accepted mapping. */
class XbrlConceptSuggestionRepository
{
    public function suggestions(): array
    {
        $sha = null;
        $data = $this->read((string) config('xbrl_suggestions.suggestions_path'), $sha);
        if (($data['schema_version'] ?? null) !== 1 || ! is_string($data['model_version'] ?? null)
            || ! is_array($data['suggestions'] ?? null) || ! array_is_list($data['suggestions'])
            || ($data['source']['concept_map_sha256'] ?? null) !== hash_file('sha256', base_path('data/atomizer_xbrl_concepts_v1.json'))
            || ($data['source']['ig3_sha256'] ?? null) !== hash_file('sha256', base_path('data/esrs_datapoints_ig3.json'))) {
            throw new RuntimeException('invalid_suggestions');
        }

        $map = $this->read(base_path('data/atomizer_xbrl_concepts_v1.json'))['concepts'];
        $inventory = array_column($map, 'concept_id');
        $rows = [];
        foreach ($data['suggestions'] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('invalid_suggestion');
            }
            $id = $row['id'] ?? null;
            $dp = $row['datapoint_id'] ?? null;
            $concept = $row['concept_id'] ?? null;
            if (! is_string($id) || ! is_string($dp) || isset($rows[$id])
                || ! isset($map[$dp]) || ($map[$dp]['concept_id'] ?? null) !== null
                || ! is_string($concept) || ! preg_match('/\Aesrs:[A-Za-z_][A-Za-z0-9_]*\z/', $concept)
                || ! in_array($concept, $inventory, true)
                || ! is_int($row['rank'] ?? null) || $row['rank'] < 1 || $row['rank'] > 5
                || $id !== $dp.'::'.$row['rank']
                || ! is_numeric($row['confidence'] ?? null) || $row['confidence'] < 0 || $row['confidence'] > 1
                || ($row['status'] ?? null) !== 'pending_review') {
                throw new RuntimeException('invalid_suggestion');
            }
            $rows[$id] = array_merge($row, ['model_version' => $data['model_version'], 'suggestions_sha256' => $sha]);
        }

        return $rows;
    }

    public function decisions(): array
    {
        $path = (string) config('xbrl_suggestions.overlay_path');
        if (! is_file($path)) {
            return [];
        }
        $data = $this->read($path);
        if (($data['schema_version'] ?? null) !== 1 || ! is_array($data['decisions'] ?? null) || ! array_is_list($data['decisions'])) {
            throw new RuntimeException('invalid_overlay');
        }
        foreach ($data['decisions'] as $entry) {
            if (! is_array($entry) || ! in_array($entry['decision'] ?? null, ['accepted', 'rejected'], true)
                || ! is_string($entry['suggestion_id'] ?? null) || ! is_string($entry['datapoint_id'] ?? null)
                || ! is_string($entry['concept_id'] ?? null) || ! is_string($entry['model_version'] ?? null)
                || ! is_string($entry['suggestions_sha256'] ?? null)
                || ! is_string($entry['reviewer'] ?? null) || ! preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $entry['reviewer'])
                || ! is_string($entry['reviewed_at'] ?? null)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $entry['reviewed_at'])) {
                throw new RuntimeException('invalid_decision');
            }
        }

        return $data['decisions'];
    }

    public function latest(): array
    {
        $latest = [];
        foreach ($this->decisions() as $entry) {
            $latest[$entry['suggestion_id']] = $entry;
        }

        return $latest;
    }

    public function accepted(): array
    {
        $latest = $this->latest();
        if ($latest === []) {
            return [];
        }
        $suggestions = $this->suggestions();
        $accepted = [];
        foreach ($latest as $id => $entry) {
            if ($entry['decision'] !== 'accepted') {
                continue;
            }
            $row = $suggestions[$id] ?? null;
            // Changed candidate bytes invalidate earlier approvals, even if IDs match.
            if ($row === null || $entry['suggestions_sha256'] !== $row['suggestions_sha256']
                || $entry['model_version'] !== $row['model_version']
                || $entry['datapoint_id'] !== $row['datapoint_id'] || $entry['concept_id'] !== $row['concept_id']) {
                continue;
            }
            if (isset($accepted[$row['datapoint_id']])) {
                throw new RuntimeException('conflicting_acceptances');
            }
            $accepted[$row['datapoint_id']] = [
                'concept_id' => $row['concept_id'], 'taggable_state' => 'mapped',
                'reason_code' => 'human_accepted_suggestion',
            ];
        }
        ksort($accepted);

        return $accepted;
    }

    public function record(string $id, string $decision, string $reviewer): void
    {
        if (! in_array($decision, ['accepted', 'rejected'], true)
            || ! preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $reviewer)) {
            throw new RuntimeException('invalid_review');
        }
        $path = (string) config('xbrl_suggestions.overlay_path');
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('overlay_write_failed');
        }
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('overlay_write_failed');
        }
        $temporary = null;
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('overlay_write_failed');
            }
            $rows = $this->suggestions();
            $row = $rows[$id] ?? null;
            if ($row === null) {
                throw new RuntimeException('unknown_suggestion');
            }
            $accepted = $this->accepted();
            if ($decision === 'accepted' && isset($accepted[$row['datapoint_id']])) {
                throw new RuntimeException('reject_previous_acceptance_first');
            }
            $entries = $this->decisions();
            $entries[] = [
                'suggestion_id' => $id, 'datapoint_id' => $row['datapoint_id'], 'concept_id' => $row['concept_id'],
                'decision' => $decision, 'reviewer' => $reviewer, 'reviewed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'model_version' => $row['model_version'],
                'suggestions_sha256' => $row['suggestions_sha256'],
            ];
            $bytes = json_encode(['schema_version' => 1, 'decisions' => $entries], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
            $temporary = tempnam($directory, '.xbrl-review-');
            if ($temporary === false || ! chmod($temporary, 0600)
                || file_put_contents($temporary, $bytes) !== strlen($bytes) || ! rename($temporary, $path)) {
                throw new RuntimeException('overlay_write_failed');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(string $path, ?string &$sha256 = null): array
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new RuntimeException('asset_read_failed');
        }
        $sha256 = hash('sha256', $bytes);
        $data = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('asset_read_failed');
        }

        return $data;
    }
}
