<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

class ValidateEsrsMatterDrMapping extends Command
{
    protected $signature = 'esrs:validate-matter-dr-mapping {path? : Mapping JSON path (defaults to data/ar16_to_esrs_dr_mapping_esrs2023_v1.json)}';

    protected $description = 'Validate an AR16 matter to ESRS Disclosure Requirement mapping file against the selectable AR16 topics and the IG3 datapoint corpus';

    public function handle(): int
    {
        $path = $this->argument('path') ?: base_path('data/ar16_to_esrs_dr_mapping_esrs2023_v1.json');
        $errors = [];

        if (! is_file($path)) {
            $this->error("missing_mapping_file: {$path}");

            return self::FAILURE;
        }

        try {
            $payload = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->error('invalid_json: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (blank(Arr::get($payload, 'version'))) {
            $errors[] = 'missing_version';
        }

        $status = Arr::get($payload, 'source.status');

        if (! in_array($status, ['draft', 'approved'], true)) {
            $errors[] = 'invalid_source_status: expected draft or approved, got '.var_export($status, true);
        }

        $topics = $this->selectableTopics();
        $validDrKeysByStandard = $this->materialityDrKeysByStandard();
        $mappings = Arr::get($payload, 'mappings', []);

        if (! is_array($mappings) || $mappings === []) {
            $errors[] = 'missing_mappings';
            $mappings = [];
        }

        $seenTopicIds = [];
        $uniqueDrPairs = [];
        $needsReviewTopicIds = [];

        foreach ($mappings as $index => $mapping) {
            if (! is_array($mapping)) {
                $errors[] = "malformed_mapping: mappings[{$index}] is not an object";

                continue;
            }

            $topicId = Arr::get($mapping, 'ar16_topic_id');

            if (! is_int($topicId) || $topicId <= 0) {
                $errors[] = "malformed_mapping: mappings[{$index}] has no valid ar16_topic_id";

                continue;
            }

            if (isset($seenTopicIds[$topicId])) {
                $errors[] = "duplicate_ar16_topic_id: {$topicId}";
            }

            $seenTopicIds[$topicId] = true;
            $topicStandard = $topics[$topicId] ?? null;

            if ($topicStandard === null) {
                $errors[] = "unknown_ar16_topic_id: {$topicId}";

                continue;
            }

            $esrsCode = trim((string) Arr::get($mapping, 'esrs_code', ''));

            if ($esrsCode !== $topicStandard) {
                $errors[] = "esrs_code_mismatch: topic {$topicId} is {$topicStandard}, mapping says ".var_export($esrsCode, true);
            }

            $disclosureRequirements = Arr::get($mapping, 'disclosure_requirements');

            if (! is_array($disclosureRequirements) || $disclosureRequirements === []) {
                $errors[] = "missing_disclosure_requirements: topic {$topicId}";

                continue;
            }

            $seenDrKeys = [];

            foreach ($disclosureRequirements as $drKey) {
                if (! is_string($drKey) || trim($drKey) === '') {
                    $errors[] = "malformed_disclosure_requirement: topic {$topicId} contains a non-string or empty DR key";

                    continue;
                }

                $drKey = trim($drKey);

                if (isset($seenDrKeys[$drKey])) {
                    $errors[] = "duplicate_disclosure_requirement: topic {$topicId} lists {$drKey} twice";
                }

                $seenDrKeys[$drKey] = true;

                if (! in_array($drKey, $validDrKeysByStandard[$topicStandard] ?? [], true)) {
                    $errors[] = "unknown_disclosure_requirement: topic {$topicId} maps {$drKey}, which is not a materiality-based DR of {$topicStandard} in esrs_datapoints_ig3.json";

                    continue;
                }

                $uniqueDrPairs[$topicStandard.'|'.$drKey] = true;
            }

            if (Arr::get($mapping, 'needs_review') === true) {
                $needsReviewTopicIds[] = $topicId;
            }
        }

        $missingTopicIds = array_values(array_diff(array_keys($topics), array_keys($seenTopicIds)));

        if ($missingTopicIds !== []) {
            $errors[] = 'missing_ar16_topic_coverage: '.implode(', ', $missingTopicIds);
        }

        $this->line('mapping_path: '.$path);
        $this->line('source.status: '.(is_string($status) ? $status : var_export($status, true)));
        $this->line('ar16_topics_selectable: '.count($topics));
        $this->line('ar16_topics_mapped: '.count($seenTopicIds));
        $this->line('unique_disclosure_requirements: '.count($uniqueDrPairs));
        $this->line('needs_review_topics: '.count($needsReviewTopicIds).($needsReviewTopicIds !== [] ? ' ('.implode(', ', $needsReviewTopicIds).')' : ''));

        foreach ($errors as $error) {
            $this->error($error);
        }

        if ($errors !== []) {
            $this->error('result: INVALID ('.count($errors).' error(s)). P9 unlock: no (fail-closed)');

            return self::FAILURE;
        }

        $this->info($status === 'approved'
            ? 'result: VALID. P9 unlock: yes at dr_level for selections covered by this map (requires ESRS_MATTER_DR_MAPPING_PATH to point at this file)'
            : 'result: VALID as '.$status.'. P9 unlock: no (fail-closed) until source.status is approved by an accountable reviewer');

        return self::SUCCESS;
    }

    /**
     * Selectable AR16 topics keyed by sequential id (EsrsTopicSeeder insertion order).
     *
     * @return array<int, string>
     */
    private function selectableTopics(): array
    {
        $topics = json_decode(
            file_get_contents(base_path('data/esrs_topics.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $byId = [];

        foreach (array_values($topics) as $index => $topic) {
            $byId[$index + 1] = (string) ($topic['esrs'] ?? '');
        }

        return $byId;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function materialityDrKeysByStandard(): array
    {
        $corpus = json_decode(
            file_get_contents(base_path('data/esrs_datapoints_ig3.json')),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        $keysByStandard = [];

        foreach ($corpus['datapoints'] as $datapoint) {
            if (($datapoint['inclusion_type'] ?? null) !== 'materiality_based') {
                continue;
            }

            $standard = (string) ($datapoint['esrs'] ?? '');
            $drKey = trim((string) ($datapoint['dr'] ?? ''));

            if ($standard === '' || $drKey === '') {
                continue;
            }

            $keysByStandard[$standard][$drKey] = $drKey;
        }

        return array_map('array_values', $keysByStandard);
    }
}
