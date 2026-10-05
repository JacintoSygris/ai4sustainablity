<?php

namespace App\Services;

use App\Models\{LearningCase, LearningCaseP5Snapshot};
use DomainException;
use Illuminate\Support\Facades\DB;

/** Private in-memory synthetic consumer. Export does not confer future dataset rights. */
final class LearningCaseExport
{
    private array $context = [];
    private ?LearningCaseClosure $closure = null;

    public static function forSyntheticTests(array $context, LearningCaseClosure $closure): self
    {
        $self = new self;
        $self->context = $context;
        $self->closure = $closure;
        return $self;
    }

    private function connection(): \Illuminate\Database\Connection
    {
        // Admission precedes connection/PDO resolution; default container instance denies.
        if (! app()->environment('testing') || $this->closure === null
            || ($this->context['namespace'] ?? null) !== 'test-namespace:t07-export'
            || ($this->context['synthetic_only'] ?? null) !== true
            || ($this->context['promotion_allowed'] ?? null) !== false) {
            throw new DomainException('learning_export.disabled');
        }
        $this->closure->assertAvailable();
        $c = DB::connection();
        if ((new LearningCase)->getConnection() !== $c || (new LearningCaseP5Snapshot)->getConnection() !== $c) {
            throw new DomainException('learning_export.isolation_required');
        }
        if (! CharacterizationStateTransaction::admitsIsolatedConnections([$c])) { throw new DomainException('learning_export.isolation_required'); }
        return $c;
    }

    /** Server-owned reference only, never an authorization/grant or feature snapshot. */
    public function referenceForAccount(int $actor): array
    {
        return $this->deliver($actor, null)['proofs']['reference'];
    }

    public function exportForAccount(int $actor, array $expected): array
    {
        return $this->deliver($actor, $expected);
    }

    private function deliver(int $actor, ?array $expected): array
    {
        $c = $this->connection();
        $pdo = $c->getPdo();
        return $c->transaction(function () use ($actor, $expected, $c, $pdo): array {
            $out = app(CharacterizationStateTransaction::class)->runForUser($actor, function () use ($actor, $expected): array {
                $packet = $this->reconstruct($actor);
                if ($expected !== null && $expected !== $packet['proofs']['reference']) {
                    throw new DomainException('learning_export.stale_command');
                }
                return $packet;
            });
            // Finite revalidation AFTER Common, BEFORE outer commit on the exact PDO.
            if ($this->connection() !== $c || $c->getPdo() !== $pdo || $this->reconstruct($actor) !== $out) {
                throw new DomainException('learning_export.changed');
            }
            return $out;
        });
    }

    private function reconstruct(int $actor): array
    {
        $dto = $this->closure->draft($actor);
        // closed comes from completed(): current issuer/ledger, source clocks, ownership,
        // case hash, receipt and persisted P5. Never trust an operational status alone.
        if ($dto['status'] !== 'closed' || ! is_array($dto['receipt'])) {
            throw new DomainException('learning_export.ineligible');
        }
        $receipt = $dto['receipt'];
        $id = LearningCase::query()->where('case_id', $receipt['case_id'])->where('case_hash', $dto['case_hash'])->value('id');
        if ($id === null) { throw new DomainException('learning_export.case_missing'); }
        $case = app(LearningCaseSnapshot::class)->findForUser($actor, $id);
        (new LearningCaseContract)->assertLearningCaseHash($case->payload_text);
        $payload = json_decode($case->payload_text, true, 512, JSON_THROW_ON_ERROR);
        $p5 = LearningCaseP5Snapshot::query()->where('learning_case_id', $case->id)->lockForUpdate()->first();
        $completion = DB::table('learning_case_p5_storage_receipts')->where('learning_case_id', $case->id)->lockForUpdate()->first();
        if ($p5 === null || $completion === null || $completion->snapshot_id !== $p5->id
            || $completion->completion_reference !== $receipt['p5_completion_reference']
            || $p5->schema_version !== LearningP5Snapshot::P5_INPUT_SCHEMA_VERSION
            || $p5->feature_schema_version !== LearningP5Snapshot::FEATURE_SCHEMA_VERSION
            || $p5->transform_version !== LearningP5Snapshot::TRANSFORM_VERSION) {
            throw new DomainException('learning_export.p5_invalid');
        }
        if (($receipt['actor_id'] ?? null) !== $actor || ($receipt['authorization_generation'] ?? null) !== $p5->authorization_generation
            || $receipt['authorization_generation'] !== $dto['expected_authorization_generation']
            || ($receipt['authorization_digest'] ?? null) !== $p5->authorization_digest
            || ($receipt['source_token'] ?? null) !== $dto['source_token']
            || ($receipt['provenance'] ?? null) !== 'synthetic-only' || ($receipt['promotion_allowed'] ?? null) !== false
            || ($receipt['recorded_at'] ?? null) !== $payload['closure_evidence']['recorded_at']) {
            throw new DomainException('learning_export.receipt_invalid');
        }
        // X is read ONLY from persisted values_text; live draft values are never inputs.
        $values = json_decode($p5->values_text, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($values) || array_keys($values) !== ['employee_count_range','headquarters_country','stock_listed']
            || ! is_string($values['employee_count_range']) || ! is_string($values['headquarters_country'])
            || ! is_bool($values['stock_listed']) || $this->encode($values) !== $p5->values_text
            || hash('sha256', $p5->values_text) !== $p5->digest || $payload['p5_snapshot']['digest'] !== $p5->digest) {
            throw new DomainException('learning_export.p5_invalid');
        }
        $revisions = [];
        foreach ($dto['expected_revisions'] as $key => $header) {
            $revisions[$key] = array_intersect_key($header, array_flip(['generation','revision','epoch','digest']));
        }
        $reference = ['case_id'=>$case->case_id, 'case_hash'=>$case->case_hash,
            'expected_revisions'=>$revisions, 'source_token'=>$dto['source_token'],
            'expected_authorization_generation'=>$dto['expected_authorization_generation']];
        $projectedReceipt = array_intersect_key($receipt, array_flip(['schema_version','case_id','case_hash','source_token',
            'p5_completion_reference','authorization_generation','authorization_digest','recorded_at','provenance','promotion_allowed','receipt_hash']));
        $decisions = [];
        foreach ($payload['datapoint_decisions'] as $decision) {
            $decisions[] = array_intersect_key($decision, array_flip(['datapoint_id','relevant','selected_to_answer','reason_codes']));
        }
        $envelope = ['schema_version'=>'learning-case-export-v1', 'namespace'=>$this->context['namespace'],
            'synthetic_only'=>true, 'promotion_allowed'=>false, 'reference'=>$reference,
            'company_group_key'=>hash('sha256', 'learning-export-group-v1:'.$payload['company_group_key']),
            'period_scope'=>['period_key'=>hash('sha256', 'learning-export-period-v1:'.$payload['period_scope']['period_key']),
                'perimeter_key'=>hash('sha256', 'learning-export-perimeter-v1:'.$payload['period_scope']['perimeter_key'])],
            'authority'=>$payload['authority'],
            'source_revisions'=>$payload['source_revisions'], 'provenance'=>['synthetic_only'=>true,
                'source_record_digest'=>$payload['provenance']['source_record_digest']],
            'receipt'=>$projectedReceipt, 'X'=>['schema_version'=>$p5->feature_schema_version,
                'input_schema_version'=>$p5->schema_version, 'transform_version'=>$p5->transform_version,
                'digest'=>$p5->digest, 'values'=>$values], 'topic_labels'=>$payload['topic_labels'],
            'topic_universe'=>$payload['topic_universe'], 'p9_feedback'=>['universe'=>$payload['datapoint_universe'],'decisions'=>$decisions]];
        $jsonl = $this->encode($envelope)."\n";
        return ['jsonl'=>$jsonl, 'digest'=>hash('sha256', $jsonl), 'proofs'=>['reference'=>$reference,
            'closure_receipt_hash'=>$receipt['receipt_hash'], 'receipt_projection_digest'=>hash('sha256', $this->encode($projectedReceipt)), 'p5_digest'=>$p5->digest,
            'p5_completion_reference'=>$completion->completion_reference]];
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
    private ?LearningAuthorizationLedger $eligibilityLedger = null;
    private int $eligibilityGeneration = 0;
    private array $eligibilityHistory = [];

    /** Trusted SERVER state only: caller must hold the batch fence transaction. */
    public function resumeEligibilityFromBatch(\App\Models\LearningBatch $batch): void
    {
        \App\Models\LearningBatch::admit();
        if (!$batch->exists || $batch->id!==1 || $batch->status!=='running' || DB::connection()->transactionLevel()<1) {
            throw new DomainException('learning_eligibility.batch_required');
        }
        $stored=\App\Models\LearningBatch::query()->lockForUpdate()->find(1);
        if ($stored===null || $stored->fence!==$batch->fence || $stored->batch_id!==$batch->batch_id
            || $stored->status!=='running' || $stored->lease_until<=now()->getTimestamp()) { throw new DomainException('learning_eligibility.batch_required'); }
        $state=$stored->issuer_state ?? ['generation'=>0,'history'=>[]];
        if (!is_int($state['generation'] ?? null) || $state['generation']<0 || !is_array($state['history'] ?? null)) {
            throw new DomainException('learning_eligibility.cursor_invalid');
        }
        $this->eligibilityGeneration=$state['generation']; $this->eligibilityHistory=$state['history'];
    }

    public function persistEligibilityInBatch(\App\Models\LearningBatch $batch): void
    {
        \App\Models\LearningBatch::admit();
        if (!$batch->exists || $batch->id!==1 || $batch->status!=='running' || DB::connection()->transactionLevel()<1) {
            throw new DomainException('learning_eligibility.batch_required');
        }
        $stored=\App\Models\LearningBatch::query()->lockForUpdate()->find(1);
        if ($stored===null || $stored->fence!==$batch->fence || $stored->batch_id!==$batch->batch_id || $stored->status!=='running'
            || $stored->lease_until<=now()->getTimestamp() || $this->eligibilityGeneration!==($stored->issuer_state['generation'] ?? 0)+1) {
            throw new DomainException('learning_eligibility.cursor_invalid');
        }
        $batch->issuer_state=['generation'=>$this->eligibilityGeneration,'history'=>$this->eligibilityHistory]; $batch->save();
    }

    public static function forSyntheticEligibilityTests(array $context, LearningCaseClosure $closure, LearningAuthorizationLedger $ledger): self
    {
        $self = self::forSyntheticTests($context, $closure);
        $self->eligibilityLedger = $ledger;
        return $self;
    }

    public function eligibilityBundleForAccounts(mixed $actors): array
    {
        if ($this->eligibilityLedger === null) { throw new DomainException('learning_eligibility.disabled'); }
        $c = $this->connection();
        if (! is_array($actors) || ! array_is_list($actors) || ! $actors || count($actors) > 32
            || count(array_unique($actors, SORT_REGULAR)) !== count($actors)) {
            throw new DomainException('learning_eligibility.actors_invalid');
        }
        foreach ($actors as $actor) {
            if (! is_int($actor) || $actor < 1 || $actor > 9007199254740991) {
                throw new DomainException('learning_eligibility.actors_invalid');
            }
        }
        sort($actors, SORT_NUMERIC);
        $pdo = $c->getPdo();
        $projection = $c->transaction(function () use ($actors, $c, $pdo): array {
            // Hold every account fence in ascending order through the final recheck.
            \App\Models\User::query()->whereIn('id', $actors)->orderBy('id')->lockForUpdate()->get();
            $members = \App\Models\LearningCompanyMembership::query()->whereIn('user_id', $actors)->orderBy('user_id')->orderBy('id')->lockForUpdate()->get();
            \App\Models\LearningCompanyGroup::query()->whereIn('id', $members->pluck('learning_company_group_id'))->orderBy('id')->lockForUpdate()->get();
            $out = $this->eligibilityProjection($actors);
            if ($this->connection() !== $c || $c->getPdo() !== $pdo || $this->eligibilityProjection($actors) !== $out) {
                throw new DomainException('learning_eligibility.changed');
            }
            return $out;
        });
        $issued = now()->utc();
        $generation = $this->eligibilityGeneration + 1;
        if ($generation > 9007199254740991) { throw new DomainException('learning_eligibility.generation_exhausted'); }
        $manifest = ['schema_version'=>'learning-eligibility-v1', 'generation'=>$generation,
            'issued_at'=>$issued->format('Y-m-d\TH:i:s.u\Z'), 'valid_until'=>$issued->copy()->addSeconds(60)->format('Y-m-d\TH:i:s.u\Z'),
            'cases'=>$projection['cases'], 'eligible_case_ids'=>array_column($projection['cases'], 'case_id'),
            'tombstones'=>$projection['tombstones'],
            'rights_snapshot_digest'=>$this->eligibilityDigest(array_column($projection['cases'], 'rights_digest')),
            'eligibility_policy_digest'=>$this->eligibilityDigest(array_column($projection['cases'], 'policy_digest'))];
        $manifest['canonical_digest'] = LearningCaseContract::eligibilityManifestDigest($this->encode($manifest));
        $this->eligibilityGeneration = $generation;
        $this->eligibilityHistory = $projection['history'];
        $bindings = ['schema_version'=>'learning-t07-bindings-v1', 'namespace'=>'test-namespace:t07-export',
            'synthetic_only'=>true, 'promotion_allowed'=>false, 'purpose'=>'synthetic:learning',
            'manifest_digest'=>$manifest['canonical_digest'], 'cases'=>$projection['bindings'], 'exclusions'=>$projection['exclusions']];
        return ['jsonl'=>$projection['jsonl'], 'manifest_json'=>$this->encode($manifest), 'bindings_json'=>$this->encode($bindings)];
    }

    private function eligibilityProjection(array $actors): array
    {
        $out = ['jsonl'=>'', 'cases'=>[], 'bindings'=>[], 'exclusions'=>[], 'tombstones'=>['revoked'=>[], 'deleted'=>[]], 'history'=>$this->eligibilityHistory];
        foreach ($actors as $actor) {
            $snapshots = LearningCaseP5Snapshot::query()->where('actor_id', $actor)->orderBy('learning_case_id')->limit(257)->get();
            if ($snapshots->count() > 256) { throw new DomainException('learning_eligibility.limit'); }
            foreach ($snapshots as $snapshot) {
                $case = LearningCase::query()->whereKey($snapshot->learning_case_id)->sole();
                (new LearningCaseContract)->assertLearningCaseHash($case->payload_text);
                $p = json_decode($case->payload_text, true, 512, JSON_THROW_ON_ERROR);
                $receipt = DB::table('learning_case_closure_receipts')->where('learning_case_id', $case->id)->first();
                $p5Receipt = DB::table('learning_case_p5_storage_receipts')->where('learning_case_id', $case->id)->first();
                if ($p['case_id'] !== $case->case_id || $p['case_hash'] !== $case->case_hash
                    || $snapshot->purpose !== 'synthetic:learning' || $snapshot->group_id !== $case->learning_company_group_id
                    || $snapshot->digest !== hash('sha256', $snapshot->values_text) || $snapshot->digest !== $p['p5_snapshot']['digest']
                    || $snapshot->schema_version !== LearningP5Snapshot::P5_INPUT_SCHEMA_VERSION
                    || $snapshot->feature_schema_version !== LearningP5Snapshot::FEATURE_SCHEMA_VERSION
                    || $snapshot->transform_version !== LearningP5Snapshot::TRANSFORM_VERSION || $receipt === null || $p5Receipt === null) {
                    throw new DomainException('learning_eligibility.corrupt_history');
                }
                $r = json_decode($receipt->receipt_text, true, 512, JSON_THROW_ON_ERROR);
                $hash = $r['receipt_hash'] ?? null; unset($r['receipt_hash']);
                if ($hash !== $receipt->receipt_hash || $hash !== hash('sha256', $this->encode($r))
                    || ($r['case_hash'] ?? null) !== $case->case_hash || ($r['case_id'] ?? null) !== $case->case_id
                    || ($r['authorization_digest'] ?? null) !== $snapshot->authorization_digest
                    || ($r['authorization_generation'] ?? null) !== $snapshot->authorization_generation
                    || ($r['p5_completion_reference'] ?? null) !== $p5Receipt->completion_reference
                    || $p5Receipt->snapshot_id !== $snapshot->id) { throw new DomainException('learning_eligibility.corrupt_history'); }
                $out['history'][$case->case_id] = ['actor'=>$actor, 'group'=>$case->learning_company_group_id, 'case_hash'=>$case->case_hash, 'at'=>$r['recorded_at']];
                if (count($out['history']) > 256) { throw new DomainException('learning_eligibility.limit'); }
            }
            $current = null;
            $user = \App\Models\User::query()->whereKey($actor)->first();
            $member = \App\Models\LearningCompanyMembership::query()->where('user_id', $actor)->where('subject_type', 'account')
                ->where('subject_identifier', (string)$actor)->where('verification_status', 'verified')->whereNotNull('verified_at')->whereNull('revoked_at')->first();
            if ($user !== null && $user->hasVerifiedEmail() && $member !== null) {
                $source = \App\Models\Characterization::query()->where('user_id', $actor)->first();
                if ($source !== null) {
                    foreach (['form_data', 'result_data'] as $key) {
                        $raw = $source->getRawOriginal($key);
                        if ($raw !== null) { json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
                    }
                }
                $dto = $this->closure->draft($actor);
                if ($dto['status'] === 'closed') { $current = $dto['receipt']['case_id']; }
            }
            foreach ($out['history'] as $id=>$history) {
                if ($history['actor'] !== $actor) { continue; }
                $rights = $this->eligibilityLedger->current($actor, $history['group'], 'synthetic:learning');
                $terminal = $user === null ? 'deleted' : (in_array($rights['status'], ['revoked', 'deleted'], true) ? $rights['status'] : null);
                if ($terminal !== null) {
                    $out['tombstones'][$terminal][] = ['case_id'=>$id, 'case_hash'=>$history['case_hash'], 'at'=>$history['at']];
                }
                if ($current !== $id || $rights['status'] !== 'granted') {
                    $out['exclusions'][] = ['case_id'=>$id, 'case_hash'=>$history['case_hash'], 'reason'=>$terminal ?? 'not_current'];
                }
            }
            if ($current === null) { continue; }
            $packet = $this->deliver($actor, null);
            $line = json_decode($packet['jsonl'], true, 512, JSON_THROW_ON_ERROR);
            $case = LearningCase::query()->where('case_id', $line['reference']['case_id'])->sole();
            $payload = json_decode($case->payload_text, true, 512, JSON_THROW_ON_ERROR);
            $rights = $this->eligibilityLedger->current($actor, $case->learning_company_group_id, 'synthetic:learning');
            if ($rights['status'] !== 'granted' || $rights['generation'] !== $line['reference']['expected_authorization_generation']
                || $rights['current_digest'] !== $line['receipt']['authorization_digest']) {
                throw new DomainException('learning_eligibility.rights_changed');
            }
            $entry = ['case_id'=>$case->case_id, 'case_hash'=>$case->case_hash, 'source_revisions'=>$payload['source_revisions'],
                'rights_digest'=>$rights['current_digest'], 'policy_digest'=>$payload['rights']['policy_digest']];
            $out['cases'][] = $entry;
            $out['bindings'][] = $entry + ['export_digest'=>$packet['digest'], 'source_kind'=>$payload['provenance']['source_kind'],
                'source_revision'=>$payload['provenance']['source_revision'], 'authorization_generation'=>$rights['generation'],
                'authority'=>$payload['authority'] + ['topic_ids'=>array_merge($payload['topic_universe']['reviewed_topic_ids'], $payload['topic_universe']['outside_scope_topic_ids']),
                    'datapoint_ids'=>array_merge($payload['datapoint_universe']['reviewed_datapoint_ids'], $payload['datapoint_universe']['outside_scope_datapoint_ids']),
                    'ambiguous_topic_ids'=>[], 'ambiguous_datapoint_ids'=>[]],
                'feature_vocab'=>['employee_count_range'=>array_keys(\App\Support\CharacterizationOptions::employeeCountRanges()),
                    'headquarters_country'=>array_keys(\App\Support\CharacterizationOptions::headquartersCountries())]];
            $out['jsonl'] .= $packet['jsonl'];
        }
        return $out;
    }

    private function eligibilityDigest(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }
}
