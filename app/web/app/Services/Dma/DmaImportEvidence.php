<?php

namespace App\Services\Dma;

use App\Models\Characterization;
use App\Models\DmaImport;
use Illuminate\Validation\ValidationException;

final class DmaImportEvidence
{
    /** Resolve references on the server; a client cannot supply evidence or another owner's import. */
    public function resolve(Characterization $characterization, array $references, array $reviewed, ?array $reviews = null, array $candidates = [], array $confirmed = [], int $revision = 0, array $inferredEvidence = []): array
    {
        // Inferred entries are resolved server-side by DmaNlpRecommendations, never client topics.
        $ids = array_values(array_unique([...array_values($references), ...array_column($inferredEvidence, 'import_id')]));
        $imports = DmaImport::where('characterization_id', $characterization->id)
            ->where('user_id', $characterization->user_id)->whereIn('id', $ids)->get()->keyBy('id');
        abort_if(P8Authority::enabled() && $imports->count() !== count($ids), 404);
        $grouped = [];
        foreach ($references as $topicId => $id) {
            $record = $imports->get($id);
            $suggestion = $record ? collect($record->payload['suggestions'])->firstWhere('topic_id', (int) $topicId) : null;
            if (preg_match('/\A[1-9][0-9]{0,8}\z/D', (string) $topicId) !== 1
                || ! in_array((int) $topicId, $reviewed, true) || ! $suggestion) {
                throw ValidationException::withMessages(['dma_import_refs.'.$topicId => __('Use an owned ADM import containing this reviewed topic.')]);
            }
            $grouped[$id][] = $suggestion;
        }
        ksort($grouped);
        $evidence = [];
        foreach ($grouped as $id => $suggestions) {
            $record = $imports->get($id);
            $topics = array_column($suggestions, 'topic_id');
            $evidence[] = [
                'basis' => 'imported_adm', 'import_id' => $record->id,
                'user_id' => $record->user_id, 'uploaded_at' => $record->uploaded_at->toJSON(),
                'sources' => $record->sources, 'suggestions' => $suggestions,
                'iro_rows' => array_values(array_filter(P8Authority::enabled() ? app(DmaCsvParser::class)->identifyRows($record->payload['iro_rows'], (int) $characterization->id, $record->sources['iro_file']['sha256']) : $record->payload['iro_rows'], fn ($row) => in_array($row['topic_id'], $topics, true))),
                'stakeholder_rows' => array_values(array_filter($record->payload['stakeholder_rows'], fn ($row) => in_array($row['topic_id'], $topics, true))),
            ];
        }
        $associations = [];
        foreach ($inferredEvidence as $entry) {
            $import = $imports->get($entry['import_id']);
            $iroId = $entry['candidate']['iro_id'];
            $rows = app(DmaCsvParser::class)->identifyRows($import->payload['iro_rows'], (int) $characterization->id, $import->sources['iro_file']['sha256']);
            $row = collect($rows)->firstWhere('iro_id', $iroId);
            abort_unless($row !== null, 409);
            $associations[$import->id.'|'.$iroId][] = (int) $entry['candidate']['id'];
            $index = null;
            foreach ($evidence as $i => $record) { if ((int) $record['import_id'] === (int) $import->id) { $index = $i; break; } }
            if ($index === null) {
                $evidence[] = ['basis' => 'imported_adm', 'import_id' => $import->id, 'user_id' => $import->user_id,
                    'uploaded_at' => $import->uploaded_at->toJSON(), 'sources' => $import->sources,
                    'suggestions' => [], 'iro_rows' => [], 'stakeholder_rows' => []];
                $index = array_key_last($evidence);
            }
            // Retain the source row and supplied topic unchanged, even if it is not confirmed.
            if (! in_array($iroId, array_column($evidence[$index]['iro_rows'], 'iro_id'), true)) {
                $evidence[$index]['iro_rows'][] = $row;
            }
        }
        if ($reviews !== null) {
            $evidence = $this->reviewRows($characterization, $evidence, $reviews, $candidates, $confirmed, $revision, $associations);
        }
        return $evidence;
    }

    private function reviewRows(Characterization $characterization, array $evidence, array $reviews, array $candidates, array $confirmed, int $revision, array $associations = []): array
    {
        $validated = \Illuminate\Support\Facades\Validator::make(['iro_reviews' => $reviews], [
            'iro_reviews' => ['present', 'array', 'list', 'max:1000'],
            'iro_reviews.*' => ['required', 'array:import_id,iro_id,decision,type,dimension,locations,groups,description,group,channel,reason,focus_datapoint_ids'],
            'iro_reviews.*.import_id' => ['required', 'integer:strict', 'min:1'],
            'iro_reviews.*.iro_id' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D', 'distinct:strict'],
            'iro_reviews.*.decision' => ['required', 'in:material,not_material,excluded'],
            'iro_reviews.*.type' => ['required', 'in:impact,risk,opportunity'],
            'iro_reviews.*.dimension' => ['required', 'in:impact,financial,both'],
            'iro_reviews.*.locations' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'iro_reviews.*.locations.*' => ['required', 'in:own_operations,upstream,downstream'],
            'iro_reviews.*.groups' => ['required', 'array', 'list', 'min:1', 'max:2'],
            'iro_reviews.*.groups.*' => ['required', 'in:internal,external'],
            'iro_reviews.*.description' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'iro_reviews.*.group' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'iro_reviews.*.channel' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'iro_reviews.*.reason' => ['present', 'nullable', 'string', 'max:300', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'],
            'iro_reviews.*.focus_datapoint_ids' => ['present', 'array', 'list', 'max:100'],
            'iro_reviews.*.focus_datapoint_ids.*' => ['required', 'string', 'max:200'],
        ])->validate()['iro_reviews'];
        $fail = static function (): never {
            throw ValidationException::withMessages(['iro_reviews' => __('Review every referenced IRO and select only indicators belonging to its topic.')]);
        };
        $originals = [];
        foreach ($evidence as $index => $record) {
            foreach ($record['iro_rows'] as $row) {
                $originals[$record['import_id'].'|'.$row['iro_id']] = [$index, $row];
            }
            $evidence[$index]['iro_reviews'] = [];
        }
        $sets = array_map(fn ($points) => array_fill_keys(array_column($points, 'id'), true), $candidates);
        $total = 0;
        if (count($originals) !== count($validated)) { $fail(); }
        foreach ($validated as $review) {
            $key = $review['import_id'].'|'.$review['iro_id'];
            if (! isset($originals[$key])) { $fail(); }
            [$index, $row] = $originals[$key]; unset($originals[$key]);
            $topicIds = array_values(array_unique([$row['topic_id'], ...($associations[$key] ?? [])]));
            $confirmedAssociations = array_values(array_intersect($topicIds, $confirmed));
            sort($confirmedAssociations);
            $eligiblePoints = [];
            foreach ($confirmedAssociations as $topicId) { $eligiblePoints += $sets[$topicId] ?? []; }
            foreach (['locations', 'groups', 'focus_datapoint_ids'] as $field) {
                if (count($review[$field]) !== count(array_unique($review[$field]))) { $fail(); }
                sort($review[$field]);
            }
            $total += count($review['focus_datapoint_ids']);
            if ($total > 10000 || ($review['decision'] === 'excluded' && blank($review['reason']))
                || ($review['decision'] === 'material' && ($confirmedAssociations === [] || $review['focus_datapoint_ids'] === []))
                || ($review['decision'] !== 'material' && $review['focus_datapoint_ids'] !== [])
                || ($review['type'] === 'impact' && $review['dimension'] === 'financial')
                || ($review['type'] !== 'impact' && $review['dimension'] === 'impact')) { $fail(); }
            foreach ($review['focus_datapoint_ids'] as $id) {
                if (! isset($eligiblePoints[$id])) { $fail(); }
            }
            $record = $evidence[$index];
            $evidence[$index]['iro_reviews'][] = $review + [
                'topic_id' => $confirmedAssociations[0] ?? $row['topic_id'], 'source_row' => $row['row'],
                ...(isset($associations[$key]) ? ['source_topic_id' => $row['topic_id'],
                    'associated_topic_ids' => $confirmedAssociations] : []),
                'source_sha256' => $record['sources']['iro_file']['sha256'],
                'reviewed_by_user_id' => (int) $characterization->user_id,
                'reviewed_at' => now()->toJSON(), 'p8_revision' => $revision,
                'rules_version' => IroContextProjector::VERSION,
            ];
        }
        foreach ($evidence as &$record) {
            usort($record['iro_reviews'], fn ($a, $b) => strcmp($a['iro_id'], $b['iro_id']));
        }
        return $evidence;
    }

    public static function references(array $evidence): array
    {
        $references = [];
        foreach ($evidence as $record) {
            foreach ($record['suggestions'] ?? [] as $suggestion) {
                $references[(string) $suggestion['topic_id']] = $record['import_id'];
            }
        }
        return $references;
    }
}
