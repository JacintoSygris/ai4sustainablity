<?php

namespace App\Services\Dma;

use App\Models\Characterization;
use App\Models\DmaImport;
use App\Models\DmaNlpRun;
use App\Models\DmaNlpReview;
use App\Services\CharacterizationStateTransaction;
use App\Services\EsrsDatapointCorpusBuilder;
use Illuminate\Validation\ValidationException;

/** Append-only analysis/review history; form_data contains frozen advisory evidence. */
class DmaNlpRecommendations
{
    public static function enabled(): bool
    {
        return (bool) config('services.dma_nlp.enabled', false)
            && class_exists(P8Authority::class) && P8Authority::enabled();
    }

    public static function p8Context(Characterization $record): string
    {
        $attributes = $record->getAttributes();
        // Use IRO's canonical context hashing and revision; never invent another revision counter.
        return P8Authority::hash(['p5' => array_diff_key($attributes, array_flip(['form_data', 'updated_at', 'created_at'])),
            'p6' => $record->esrs_topic_ids, 'p8' => $record->form_data['materiality_confirmation'] ?? null,
            'adm' => $record->form_data['double_materiality_process'] ?? null,
            'mapping' => app(\App\Services\Ar16MatterDrMappingRepository::class)->approved()]);
    }

    public function context(Characterization $record, string $stage): string
    {
        return $stage === 'p8' ? self::p8Context($record)
            : app(EsrsDatapointCorpusBuilder::class)->build($record)['p9_authority_digest'];
    }

    public function assertContext(Characterization $record, string $stage, string $expected, int $revision): string
    {
        $actual = $this->context($record, $stage);
        abort_unless(hash_equals($actual, $expected) && ($record->form_data['materiality_confirmation']['revision'] ?? 0) === $revision, 409, __('The text analysis context has changed. Reload and review again.'));
        return $actual;
    }

    public function source(Characterization $record, int $importId, array $ids, string $stage): array
    {
        $import = DmaImport::where('user_id', $record->user_id)->where('characterization_id', $record->id)->findOrFail($importId);
        $rows = app(DmaCsvParser::class)->identifyRows($import->payload['iro_rows'], (int) $record->id, $import->sources['iro_file']['sha256']);
        $byId = array_column($rows, null, 'iro_id'); $selected = [];
        $reviews = [];
        if ($stage === 'p9') {
            foreach (app(P8Authority::class)->assertCurrent($record)['dma_import_evidence'] ?? [] as $evidence) {
                if ((int) $evidence['import_id'] === $importId) {
                    foreach ($evidence['iro_reviews'] ?? [] as $review) { $reviews[$review['iro_id']] = $review; }
                }
            }
        }
        sort($ids);
        foreach ($ids as $id) {
            abort_unless(isset($byId[$id]), 404);
            if ($stage === 'p9') { abort_unless(isset($reviews[$id]), 409, __('Review the imported IRO in double materiality first.')); }
            $row = $byId[$id];
            if (strlen($row['iro_description']) > 2000) { throw ValidationException::withMessages(['iro_ids' => __('Each IRO description must occupy at most 2,000 UTF-8 bytes.')]); }
            $selected[] = ['iro_id' => $id, 'description' => $row['iro_description'], 'source_sha256' => $import->sources['iro_file']['sha256']];
        }
        return [$import, $selected, $byId, $reviews];
    }

    public function create(Characterization $record, int $importId, array $input): DmaNlpRun
    {
        $transactions = app(CharacterizationStateTransaction::class); $gateway = app(DmaNlpGateway::class);
        $capture = function (Characterization $locked) use ($record, $importId, $input, $gateway): array {
            abort_unless(self::enabled() && (int) $locked->user_id === (int) $record->user_id, 404);
            $context = $this->assertContext($locked, $input['stage'], $input['expected_context'], $input['expected_revision']);
            $builder = app(EsrsDatapointCorpusBuilder::class);
            $builder->assertMappingAvailable($builder->buildPreview($locked));
            [$import, $iros] = $this->source($locked, $importId, $input['iro_ids'], $input['stage']);
            try { $manifest = $gateway->manifest(); }
            catch (\Throwable) { abort(503, __('Text analysis is unavailable. Try again later.')); }
            $request = ['version' => DmaNlpGateway::VERSION, 'locale' => $input['locale'], 'iros' => $iros];
            $digest = P8Authority::hash(['request' => $request, 'context' => $context, 'stage' => $input['stage'],
                'import' => $import->payload, 'sources' => $import->sources, 'model' => $manifest]);
            $existing = DmaNlpRun::where('dma_import_id', $importId)->where('input_digest', $digest)->first();
            if (! $existing && DmaNlpRun::where('dma_import_id', $importId)->count() >= 200) {
                throw ValidationException::withMessages(['iro_ids' => __('This import has reached the 200-analysis limit.')]);
            }
            return compact('request', 'digest', 'context', 'manifest', 'existing');
        };
        $before = $transactions->run($record->id, $capture);
        if ($before['existing']) { return $before['existing']; }
        // Never hold a database lock while waiting for the private service.
        try { $result = $gateway->analyse($before['request']); }
        catch (\Throwable) { abort(503, __('Text analysis is unavailable. Try again later.')); }
        return $transactions->run($record->id, function (Characterization $locked) use ($capture, $before, $result, $input, $importId): DmaNlpRun {
            $after = $capture($locked);
            abort_unless(hash_equals($before['digest'], $after['digest']), 409, __('The text analysis context has changed. Reload and review again.'));
            if ($after['existing']) { return $after['existing']; }
            return DmaNlpRun::create(['characterization_id' => $locked->id, 'dma_import_id' => $importId, 'user_id' => $locked->user_id,
                'iro_ids' => array_column($before['request']['iros'], 'iro_id'), 'stage' => $input['stage'], 'locale' => $input['locale'],
                'input_digest' => $before['digest'], 'context_digest' => $before['context'], 'p8_revision' => $input['expected_revision'],
                'model_sha256' => $result['model_sha256'], 'catalogue_sha256' => $result['catalogue_sha256'], 'result' => $result, 'created_at' => now()]);
        });
    }

    public function candidates(DmaNlpRun $run): array
    {
        $candidates = [];
        foreach ($run->result['iros'] as $iro) {
            foreach ($iro['levels'] as $level => $values) {
                foreach ($values['candidates'] as $candidate) {
                    $id = P8Authority::hash([$run->input_digest, $iro['iro_id'], $level, $candidate['id']]);
                    $candidates[$id] = $candidate + ['candidate_id' => $id, 'level' => $level, 'iro_id' => $iro['iro_id'], 'description_sha256' => $iro['description_sha256']];
                }
            }
        }
        $points = array_column(json_decode(file_get_contents(base_path('data/esrs_datapoints_ig3.json')), true, 64, JSON_THROW_ON_ERROR)['datapoints'], null, 'id');
        $mapping = app(\App\Services\Ar16MatterDrMappingRepository::class)->approved();
        $retrieved = $candidates;
        foreach ($retrieved as $key => $candidate) {
            $candidates[$key]['related_datapoint_ids'] = [];
            if ($candidate['level'] === 'datapoint') { continue; }
            foreach ($retrieved as $point) {
                if ($point['level'] !== 'datapoint' || $point['iro_id'] !== $candidate['iro_id']) { continue; }
                $dr = $points[$point['id']]['dr'];
                $compatible = $candidate['level'] === 'dr' ? $dr === $candidate['id']
                    : in_array($dr, $mapping['by_topic_id'][(int) $candidate['id']]['disclosure_requirements'] ?? [], true);
                if ($compatible) { $candidates[$key]['related_datapoint_ids'][] = $point['id']; }
            }
        }
        return $candidates;
    }

    public function presentation(DmaNlpRun $run, Characterization $record): array
    {
        $decisions = DmaNlpReview::where('dma_nlp_run_id', $run->id)->pluck('decision', 'candidate_id')->all();
        $scope = $run->stage === 'p9' ? $this->iroScope($record) : [];
        $materialIros = [];
        foreach ($record->form_data['materiality_confirmation']['dma_import_evidence'] ?? [] as $source) {
            foreach ($source['iro_reviews'] ?? [] as $review) {
                if ($review['decision'] === 'material' && (int) $source['import_id'] === (int) $run->dma_import_id) { $materialIros[$review['iro_id']] = $this->reviewScope($review, $scope); }
            }
        }
        return ['id' => $run->id, 'import_id' => $run->dma_import_id, 'stage' => $run->stage,
            'context_digest' => $run->context_digest, 'p8_revision' => $run->p8_revision, 'result' => $run->result,
            'candidates' => array_values(array_map(fn ($candidate) => $candidate + ['decision' => $decisions[$candidate['candidate_id']] ?? null,
                'in_scope' => $run->stage === 'p8' ? $candidate['level'] === 'topic' : ($candidate['level'] === 'datapoint' && isset($materialIros[$candidate['iro_id']][$candidate['id']]))], $this->candidates($run)))];
    }

    private function iroScope(Characterization $record): array
    {
        return app(IroContextProjector::class)->candidates(app(EsrsDatapointCorpusBuilder::class)->build($record));
    }

    private function reviewScope(array $review, array $scope): array
    {
        $points = [];
        foreach ($review['associated_topic_ids'] ?? [$review['topic_id']] as $topicId) {
            $points += array_fill_keys(array_column($scope[$topicId] ?? [], 'id'), true);
        }
        return $points;
    }

    public function review(Characterization $record, DmaNlpRun $run, array $input): array
    {
        return app(CharacterizationStateTransaction::class)->run($record->id, function (Characterization $locked) use ($record, $run, $input): array {
            abort_unless(self::enabled() && (int) $locked->user_id === (int) $record->user_id, 404);
            $context = $this->assertContext($locked, $run->stage, $input['expected_context'], $input['expected_revision']);
            abort_unless(hash_equals($context, $run->context_digest) && $run->p8_revision === $input['expected_revision'], 409);
            [$import, $iros, $rows, $reviews] = $this->source($locked, (int) $run->dma_import_id, $run->iro_ids, $run->stage);
            try { $manifest = app(DmaNlpGateway::class)->manifest(); }
            catch (\Throwable) { abort(503, __('Text analysis is unavailable. Try again later.')); }
            abort_unless($manifest['model_sha256'] === $run->model_sha256 && $manifest['catalogue_sha256'] === $run->catalogue_sha256, 409);
            $inputDigest = P8Authority::hash(['request' => ['version' => DmaNlpGateway::VERSION, 'locale' => $run->locale, 'iros' => $iros],
                'context' => $context, 'stage' => $run->stage, 'import' => $import->payload, 'sources' => $import->sources, 'model' => $manifest]);
            abort_unless(hash_equals($run->input_digest, $inputDigest), 409);
            $candidate = $this->candidates($run)[$input['candidate_id']] ?? null;
            abort_unless($candidate !== null, 422);
            abort_unless(hash('sha256', $rows[$candidate['iro_id']]['iro_description']) === $candidate['description_sha256'], 409);
            $existing = DmaNlpReview::where('dma_nlp_run_id', $run->id)->where('candidate_id', $input['candidate_id'])->first();
            if ($existing) { abort_unless($existing->decision === $input['decision'], 409); return $this->presentation($run, $locked); }
            if ($input['decision'] === 'accepted') {
                $allowed = $run->stage === 'p8' ? $candidate['level'] === 'topic'
                    : ($candidate['level'] === 'datapoint' && $reviews[$candidate['iro_id']]['decision'] === 'material'
                        && isset($this->reviewScope($reviews[$candidate['iro_id']], $this->iroScope($locked))[$candidate['id']]));
                if (! $allowed) { throw ValidationException::withMessages(['candidate_id' => __('This suggestion is informational. Review the topic in double materiality first.')]); }
            }
            $decision = DmaNlpReview::create(['dma_nlp_run_id' => $run->id, 'candidate_id' => $input['candidate_id'],
                'reviewer_id' => $locked->user_id, 'decision' => $input['decision'], 'created_at' => now()]);
            $data = $locked->form_data ?? [];
            $key = $run->stage === 'p8' ? 'dma_nlp_draft' : 'dma_nlp_evidence';
            $evidence = ['run_id' => $run->id, 'import_id' => $import->id, 'candidate' => $candidate,
                'source' => $rows[$candidate['iro_id']], 'source_sha256' => $import->sources['iro_file']['sha256'],
                'reviewed_context' => $reviews[$candidate['iro_id']] ?? null,
                'context_digest' => $context, 'p8_revision' => $run->p8_revision, 'stage' => $run->stage,
                'model_sha256' => $run->model_sha256, 'catalogue_sha256' => $run->catalogue_sha256,
                'decision' => $decision->decision, 'reviewer_id' => $decision->reviewer_id, 'reviewed_at' => $decision->created_at->toJSON()];
            $data[$key][(string) $run->id.'|'.$input['candidate_id']] = $evidence;
            if (count($data[$key]) > 10000 || strlen(json_encode($data[$key], JSON_THROW_ON_ERROR)) > 4194304) {
                throw ValidationException::withMessages(['candidate_id' => __('The stored text-analysis evidence limit has been reached.')]);
            }
            $locked->forceFill(['form_data' => $data])->save();
            return $this->presentation($run, $locked);
        });
    }

    /** Resolve inferred-topic references separately from import-supplied topic suggestions. */
    public function confirmationEvidence(Characterization $record, array $references, array $reviewedTopics, array $iroReviews, int $revision): array
    {
        $resolved = []; $context = self::p8Context($record);
        $manifest = $references === [] ? null : app(DmaNlpGateway::class)->manifest();
        foreach ($references as $reference) {
            $entry = $record->form_data['dma_nlp_draft'][$reference] ?? null;
            if (! is_array($entry) || $entry['decision'] !== 'accepted' || $entry['context_digest'] !== $context
                || ! in_array((int) $entry['candidate']['id'], $reviewedTopics, true)) {
                throw ValidationException::withMessages(['dma_nlp_refs' => __('Review the inferred topic association again.')]);
            }
            $review = collect($iroReviews)->first(fn ($item) => $item['iro_id'] === $entry['candidate']['iro_id'] && $item['import_id'] === $entry['import_id']);
            if (! $review) { throw ValidationException::withMessages(['dma_nlp_refs' => __('Review the imported IRO in double materiality first.')]); }
            [$sourceImport, $sourceRows] = $this->source($record, (int) $entry['import_id'], [$entry['candidate']['iro_id']], 'p8');
            abort_unless($entry['source_sha256'] === $sourceImport->sources['iro_file']['sha256']
                && $entry['candidate']['description_sha256'] === hash('sha256', $sourceRows[0]['description']), 409);
            $ownedRun = DmaNlpRun::where('characterization_id', $record->id)->where('user_id', $record->user_id)->where('stage', 'p8')->findOrFail($entry['run_id']);
            abort_unless((int) $ownedRun->dma_import_id === (int) $entry['import_id'] && DmaNlpReview::where('dma_nlp_run_id', $ownedRun->id)
                ->where('candidate_id', $entry['candidate']['candidate_id'])->where('decision', 'accepted')->exists(), 409);
            abort_unless($ownedRun->model_sha256 === $manifest['model_sha256'] && $ownedRun->catalogue_sha256 === $manifest['catalogue_sha256'], 409);
            $entry['reviewed_context'] = $review; $entry['confirmed_p8_revision'] = $revision; $resolved[] = $entry;
        }
        return $resolved;
    }

    /** Pure projection from captured form_data. Exports never query live recommendation tables. */
    public static function project(array $entries, string $digest): array
    {
        $shortlist = []; $byPoint = [];
        foreach ($entries as $entry) {
            if ($entry['decision'] !== 'accepted' || $entry['context_digest'] !== $digest || $entry['candidate']['level'] !== 'datapoint') { continue; }
            $shortlist[] = $entry; $byPoint[$entry['candidate']['id']][] = $entry;
        }
        return ['shortlist' => $shortlist, 'by_datapoint' => $byPoint];
    }
}
