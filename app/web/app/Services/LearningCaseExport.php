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

    private function deliver(int $actor, ?array $expected, ?\Closure $checkpoint = null): array
    {
        $c = $this->connection();
        $pdo = $c->getPdo();
        return $c->transaction(function () use ($actor, $expected, $c, $pdo, $checkpoint): array {
            $out = app(CharacterizationStateTransaction::class)->runForUser($actor, function () use ($actor, $expected, $checkpoint): array {
                $checkpoint?->__invoke();
                $packet = $this->reconstruct($actor);
                if ($expected !== null && $expected !== $packet['proofs']['reference']) {
                    throw new DomainException('learning_export.stale_command');
                }
                return $packet;
            });
            $checkpoint?->__invoke();
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

    /** Authenticated historical closure plus raw clock drift can deny, never grant eligibility. */
    public function sourceDriftExclusions(array $cursor, array $bundle, ?\Closure $checkpoint=null): ?array
    {
        if ($this->eligibilityLedger===null) { throw new DomainException('learning_eligibility.disabled'); }
        $c=$this->connection(); $pdo=$c->getPdo(); $level=$c->transactionLevel();
        if ($level<1) { throw new DomainException('learning_eligibility.batch_required'); }
        $fail=static function (): never { throw new DomainException('learning_batch.witness_invalid'); };
        $closureEncode=static fn ($v) => json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS|JSON_PRESERVE_ZERO_FRACTION);
        $integer=static function ($value, bool $positive=true) use ($fail): int {
            if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value) && strlen($value)<=16) { $value=(int)$value; }
            if (!is_int($value) || $value<($positive?1:0) || $value>9007199254740991) { $fail(); }
            return $value;
        };
        $header=static function ($value, bool $identity=false) use ($integer,$fail): array {
            if (!is_array($value)) { $fail(); }
            $keys=$identity?['characterization_id','generation','revision','epoch','digest']:['generation','revision','epoch','digest'];
            if (array_keys($value)!==$keys) { $fail(); }
            foreach ($keys as $key) {
                if (in_array($key,['characterization_id','generation','revision'],true)) { $value[$key]=$integer($value[$key],$key!=='generation'); }
                elseif (!is_string($value[$key]) || !preg_match('/\A[a-f0-9]{64}\z/D',$value[$key])) { $fail(); }
            }
            return $value;
        };
        $actors=config('services.learning_batch.actors');
        if (!is_array($actors) || !array_is_list($actors) || !$actors || count($actors)>32 || count(array_unique($actors,SORT_REGULAR))!==count($actors)) { $fail(); }
        foreach ($actors as $actor) { if (!is_int($actor) || $integer($actor)!==$actor) { $fail(); } }
        sort($actors,SORT_NUMERIC);
        $manifest=json_decode($bundle['manifest_json'],true,512,JSON_THROW_ON_ERROR);
        $bindings=json_decode($bundle['bindings_json'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_array($bindings) || ($manifest['schema_version'] ?? null)!=='learning-eligibility-v1'
            || !is_int($manifest['generation'] ?? null) || $manifest['generation']<1 || $manifest['generation']>$cursor['generation']
            || ($bindings['schema_version'] ?? null)!=='learning-t07-bindings-v1'
            || ($bindings['namespace'] ?? null)!=='test-namespace:t07-export' || ($bindings['synthetic_only'] ?? null)!==true
            || ($bindings['promotion_allowed'] ?? null)!==false || ($bindings['purpose'] ?? null)!=='synthetic:learning'
            || ($bindings['manifest_digest'] ?? null)!==($manifest['canonical_digest'] ?? null)
            || LearningCaseContract::eligibilityManifestDigest($bundle['manifest_json'])!==$manifest['canonical_digest']
            || !is_array($bindings['cases'] ?? null) || !array_is_list($bindings['cases']) || count($bindings['cases'])>256
            || !is_array($bindings['exclusions'] ?? null) || !is_array($manifest['cases'] ?? null)
            || ($manifest['eligible_case_ids'] ?? null)!==array_column($bindings['cases'],'case_id')) { $fail(); }
        $lines=[];
        foreach (explode("\n",trim($bundle['jsonl'])) as $text) {
            $checkpoint?->__invoke();
            if ($text==='') { continue; }
            $line=json_decode($text,true,512,JSON_THROW_ON_ERROR); $id=$line['reference']['case_id'] ?? null;
            if (!is_string($id) || isset($lines[$id]) || ($line['schema_version'] ?? null)!=='learning-case-export-v1'
                || ($line['namespace'] ?? null)!=='test-namespace:t07-export' || ($line['synthetic_only'] ?? null)!==true
                || ($line['promotion_allowed'] ?? null)!==false || ($line['provenance']['synthetic_only'] ?? null)!==true) { $fail(); }
            $lines[$id]=['line'=>$line,'text'=>$text."\n"];
        }
        if (count($lines)!==count($bindings['cases']) || count($cursor['history'])>256) { $fail(); }
        // Record every raw read for the final, same-PDO recheck, with account locks first.
        $reads=[];
        $read=function (string $table, array $where) use ($c,&$reads,$checkpoint): array {
            $checkpoint?->__invoke();
            $order=str_ends_with($table,'source_revisions')?'characterization_id':'id';
            $rows=$c->table($table)->where($where)->orderBy($order)->lockForUpdate()->get()->map(static fn ($row) => (array)$row)->all();
            $reads[]=[$table,$where,$order,$rows]; return $rows;
        };
        $users=[];
        foreach ($actors as $actor) { $users[$actor]=$read('users',['id'=>$actor]); }
        $histories=[];
        foreach ($cursor['history'] as $id=>$history) {
            $checkpoint?->__invoke();
            if (!is_string($id) || !is_array($history) || !is_int($history['actor'] ?? null)
                || !in_array($history['actor'],$actors,true) || !is_int($history['group'] ?? null)
                || !is_string($history['case_hash'] ?? null) || !is_string($history['at'] ?? null)) { $fail(); }
            $cases=$read('learning_cases',['case_id'=>$id]); if (count($cases)!==1) { $fail(); }
            $case=$cases[0]; $caseId=$integer($case['id']);
            if ($case['case_hash']!==$history['case_hash'] || $integer($case['learning_company_group_id'])!==$history['group']) { $fail(); }
            (new LearningCaseContract)->assertLearningCaseHash($case['payload_text']);
            $payload=json_decode($case['payload_text'],true,512,JSON_THROW_ON_ERROR);
            $receipts=$read('learning_case_closure_receipts',['learning_case_id'=>$caseId]); if (count($receipts)!==1) { $fail(); }
            $stored=$receipts[0]; $receipt=json_decode($stored['receipt_text'],true,512,JSON_THROW_ON_ERROR);
            $frame=json_decode($stored['source_text'],true,512,JSON_THROW_ON_ERROR);
            $hash=$receipt['receipt_hash'] ?? null; $unsigned=$receipt; unset($unsigned['receipt_hash']);
            $actor=$history['actor']; $group=$history['group'];
            if (!is_array($frame) || $closureEncode($frame)!==$stored['source_text']
                || !is_string(config('app.key')) || config('app.key')===''
                || !hash_equals(hash_hmac('sha256',$stored['source_text'],config('app.key')),$stored['source_token'])
                || $integer($stored['user_id'])!==$actor || ($receipt['actor_id'] ?? null)!==$actor
                || ($receipt['case_id'] ?? null)!==$id || ($receipt['case_hash'] ?? null)!==$history['case_hash']
                || ($receipt['recorded_at'] ?? null)!==$history['at'] || $hash!==$stored['receipt_hash']
                || hash('sha256',$closureEncode($unsigned))!==$hash || ($receipt['source_token'] ?? null)!==$stored['source_token']
                || ($receipt['schema_version'] ?? null)!=='learning-case-closure-receipt-v1'
                || ($receipt['provenance'] ?? null)!=='synthetic-only' || ($receipt['promotion_allowed'] ?? null)!==false
                || ($frame['context']['synthetic_only'] ?? null)!==true || ($frame['context']['promotion_allowed'] ?? null)!==false
                || !is_string($frame['context']['namespace'] ?? null) || !str_starts_with($frame['context']['namespace'],'test-namespace:')
                || ($frame['context']['purpose'] ?? null)!=='synthetic:learning'
                || ($frame['membership']['user_id'] ?? null)!==$actor || ($frame['group_id'] ?? null)!==$group
                || ($payload['case_id'] ?? null)!==$id || ($payload['case_hash'] ?? null)!==$history['case_hash']
                || ($payload['provenance']['source_revision'] ?? null)!==$stored['source_token']) { $fail(); }
            $states=$read('learning_authorization_states',['user_id'=>$actor,'learning_company_group_id'=>$group,'purpose'=>'synthetic:learning']);
            if (count($states)!==1) { return null; }
            $rights=$states[0]; $status=$rights['status'];
            $events=$read('learning_authorization_records',['learning_authorization_state_id'=>$integer($rights['id'])]);
            $last=null; foreach ($events as $event) { if ($integer($event['generation'])===$integer($rights['generation'])) { $last=$event; } }
            if ($last===null || hash('sha256',$last['payload_text'])!==$rights['event_digest'] || $last['event_digest']!==$rights['event_digest']) { $fail(); }
            $event=json_decode($last['payload_text'],true,512,JSON_THROW_ON_ERROR);
            if (($event['actor_id'] ?? null)!==$actor || ($event['group_id'] ?? null)!==$group || ($event['purpose'] ?? null)!=='synthetic:learning'
                || ($event['status'] ?? null)!==$status || ($event['provenance'] ?? null)!=='synthetic-only' || ($event['promotion_allowed'] ?? null)!==false) { $fail(); }
            $histories[$id]=[$case,$payload,$receipt,$frame];
            if (!isset($lines[$id])) {
                // A simultaneous terminal transition must be reconciled by the full path.
                $reason=count($users[$actor])===0?'deleted':$status;
                $excluded=array_column($bindings['exclusions'],'reason','case_id');
                $tombstones=array_column($manifest['tombstones'][$reason] ?? [],null,'case_id');
                if (!in_array($reason,['revoked','deleted'],true) || ($excluded[$id] ?? null)!==$reason
                    || ($tombstones[$id] ?? null)!==['case_id'=>$id,'case_hash'=>$history['case_hash'],'at'=>$history['at']]) { return null; }
                continue;
            }
            if ($status!=='granted' || count($users[$actor])!==1 || $users[$actor][0]['email_verified_at']===null
                || $integer($rights['generation'])!==$receipt['authorization_generation'] || $rights['event_digest']!==$receipt['authorization_digest']) { return null; }
            $members=$read('learning_company_memberships',['user_id'=>$actor]);
            $groups=$read('learning_company_groups',['id'=>$group]);
            $member=null; foreach ($members as $candidate) { if ($integer($candidate['id'])===$integer($frame['membership']['id'])) { $member=$candidate; } }
            if ($member===null || count($groups)!==1 || $integer($member['learning_company_group_id'])!==$group
                || $member['subject_type']!=='account' || $member['subject_identifier']!==(string)$actor
                || $member['verification_status']!=='verified' || $member['verified_at']===null || $member['revoked_at']!==null
                || $groups[0]['company_group_key']!==$frame['group_key'] || ($event['witness'] ?? null)!==$frame['witness']) { return null; }
        }
        $negative=[];
        foreach ($bindings['cases'] as $index=>$binding) {
            $checkpoint?->__invoke(); $id=$binding['case_id'] ?? null;
            if (!is_string($id) || !isset($lines[$id],$histories[$id])) { $fail(); }
            [$case,$payload,$receipt,$frame]=$histories[$id]; $line=$lines[$id]['line']; $reference=$line['reference'];
            $entry=array_intersect_key($binding,array_flip(['case_id','case_hash','source_revisions','rights_digest','policy_digest']));
            if ($entry!==($manifest['cases'][$index] ?? null) || $binding['case_hash']!==$case['case_hash']
                || ($binding['export_digest'] ?? null)!==hash('sha256',$lines[$id]['text'])
                || $reference['case_hash']!==$case['case_hash'] || $reference['source_token']!==$receipt['source_token']
                || $reference['expected_authorization_generation']!==$receipt['authorization_generation']
                || $binding['source_revisions']!==$payload['source_revisions'] || $line['source_revisions']!==$payload['source_revisions']
                || $binding['rights_digest']!==$receipt['authorization_digest'] || $binding['policy_digest']!==$payload['rights']['policy_digest']
                || $binding['source_kind']!==$payload['provenance']['source_kind'] || $binding['source_revision']!==$receipt['source_token']
                || $line['provenance']['source_record_digest']!==$payload['provenance']['source_record_digest']) { $fail(); }
            $actor=$cursor['history'][$id]['actor'];
            $sources=$read('characterizations',['user_id'=>$actor]); if (count($sources)!==1) { return null; }
            $source=$sources[0]; $char=$integer($source['id']);
            if (($frame['source_headers']['p5']['characterization_id'] ?? null)!==$char) { $fail(); }
            if (array_keys($reference['expected_revisions'] ?? [])!==['p5','p6_base','p8','p9']) { $fail(); }
            $mismatch=false;
            foreach (['p5'=>'learning_p5_source_revisions','p6_base'=>'learning_p6_base_source_revisions','p8'=>'learning_p8_source_revisions','p9'=>'learning_p9_source_revisions'] as $key=>$table) {
                if (!is_int($reference['expected_revisions'][$key]['generation'] ?? null)
                    || !is_int($reference['expected_revisions'][$key]['revision'] ?? null)) { $fail(); }
                $expected=$header($reference['expected_revisions'][$key]);
                $original=$header($frame['source_headers'][$key],true);
                if ($original['characterization_id']!==$char || array_intersect_key($original,$expected)!==$expected) { $fail(); }
                $rows=$read($table,['characterization_id'=>$char]); if (count($rows)!==1) { return null; }
                $clock=[]; foreach (['characterization_id','generation','revision','epoch','digest'] as $field) { $clock[$field]=$rows[0][$field] ?? null; }
                $current=$header($clock,true);
                if ($current['characterization_id']!==$char) { $fail(); }
                if ($current['generation']<$expected['generation'] || ($current['epoch']===$expected['epoch'] && $current['revision']<$expected['revision'])) { $fail(); }
                if (array_intersect_key($current,$expected)!==$expected) { $mismatch=true; }
            }
            if ($mismatch) { $negative[]=['case_id'=>$id,'case_hash'=>$case['case_hash'],'reason'=>'not_current']; }
        }
        if (!$negative) { return null; }
        foreach ($reads as [$table,$where,$order,$rows]) {
            $checkpoint?->__invoke();
            if ($c->table($table)->where($where)->orderBy($order)->lockForUpdate()->get()->map(static fn ($row) => (array)$row)->all()!==$rows) {
                throw new DomainException('learning_eligibility.changed');
            }
        }
        $checkpoint?->__invoke();
        if ($this->connection()!==$c || $c->getPdo()!==$pdo || $c->transactionLevel()!==$level) { throw new DomainException('learning_eligibility.changed'); }
        return $negative;
    }

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

    /** $checkpoint is a trusted internal budget check only; it throws to abort and roll back. */
    public function eligibilityBundleForAccounts(mixed $actors, ?\Closure $checkpoint = null): array
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
        $checkpoint?->__invoke();
        $projection = $c->transaction(function () use ($actors, $c, $pdo, $checkpoint): array {
            // Hold every account fence in ascending order through the final recheck.
            \App\Models\User::query()->whereIn('id', $actors)->orderBy('id')->lockForUpdate()->get();
            $members = \App\Models\LearningCompanyMembership::query()->whereIn('user_id', $actors)->orderBy('user_id')->orderBy('id')->lockForUpdate()->get();
            \App\Models\LearningCompanyGroup::query()->whereIn('id', $members->pluck('learning_company_group_id'))->orderBy('id')->lockForUpdate()->get();
            $out = $this->eligibilityProjection($actors, $checkpoint);
            $checkpoint?->__invoke();
            if ($this->connection() !== $c || $c->getPdo() !== $pdo || $this->eligibilityProjection($actors, $checkpoint) !== $out) {
                throw new DomainException('learning_eligibility.changed');
            }
            $checkpoint?->__invoke();
            return $out;
        });
        // Last budget seam before any issuer generation/history mutation.
        $checkpoint?->__invoke();
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

    private function eligibilityProjection(array $actors, ?\Closure $checkpoint = null): array
    {
        $c = $this->connection(); $pdo = $c->getPdo();
        $out = ['jsonl'=>'', 'cases'=>[], 'bindings'=>[], 'exclusions'=>[], 'tombstones'=>['revoked'=>[], 'deleted'=>[]], 'history'=>$this->eligibilityHistory];
        foreach ($actors as $actor) {
            $checkpoint?->__invoke();
            $snapshots = LearningCaseP5Snapshot::query()->where('actor_id', $actor)->orderBy('learning_case_id')->limit(257)->get();
            if ($snapshots->count() > 256) { throw new DomainException('learning_eligibility.limit'); }
            foreach ($snapshots as $snapshot) {
                $checkpoint?->__invoke();
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
            $checkpoint?->__invoke();
            $witness = null;
            $hasHistory = false;
            foreach ($out['history'] as $history) { if ($history['actor'] === $actor) { $hasHistory = true; break; } }
            if ($hasHistory && \App\Models\User::query()->whereKey($actor)->exists()) {
                // Coalesce draft/history owners; delivery must follow this owner's finalizers.
                $current = app(CharacterizationStateTransaction::class)->runForUser($actor,
                    function (?\App\Models\Characterization $source) use ($actor, &$out, &$witness, $c, $checkpoint): ?string {
                        // Budget seams stay outside draft(): its DomainException catches must not translate them.
                        $checkpoint?->__invoke();
                        $witness = $this->eligibilityReadWitness($actor, $c);
                        if ($witness['source'] !== $source?->getRawOriginal()) { throw new DomainException('learning_eligibility.changed'); }
                        $current = $this->eligibilityCurrentForAccount($actor, $out, $checkpoint);
                        $checkpoint?->__invoke();
                        return $current;
                    });
                $checkpoint?->__invoke();
                // AFTER the composed Common owner, not merely after an inner producer.
                $this->assertEligibilityReadWitness($actor, $witness, $c, $pdo);
            } else {
                // No live user lock is required for terminal history or an absent account.
                $current = $this->eligibilityCurrentForAccount($actor, $out, $checkpoint);
            }
            $checkpoint?->__invoke();
            if ($current === null) { continue; }
            // Fresh producer inputs, documents and catalog AFTER the composed Common owner.
            $packet = $this->deliver($actor, null, $checkpoint);
            $checkpoint?->__invoke();
            $line = json_decode($packet['jsonl'], true, 512, JSON_THROW_ON_ERROR);
            $case = LearningCase::query()->where('case_id', $line['reference']['case_id'])->sole();
            $payload = json_decode($case->payload_text, true, 512, JSON_THROW_ON_ERROR);
            $rights = $this->eligibilityLedger->current($actor, $case->learning_company_group_id, 'synthetic:learning');
            $checkpoint?->__invoke();
            if ($rights['status'] !== 'granted' || $rights['generation'] !== $line['reference']['expected_authorization_generation']
                || $rights['current_digest'] !== $line['receipt']['authorization_digest']) {
                throw new DomainException('learning_eligibility.rights_changed');
            }
            // The final rights read has its own Common finalizers; close its last retrieved seam too.
            if ($witness !== null) { $this->assertEligibilityReadWitness($actor, $witness, $c, $pdo); }
            $entry =['case_id'=>$case->case_id, 'case_hash'=>$case->case_hash, 'source_revisions'=>$payload['source_revisions'],
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

    private function eligibilityCurrentForAccount(int $actor, array &$out, ?\Closure $checkpoint = null): ?string
    {
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
            $checkpoint?->__invoke();
            $dto = $this->closure->draft($actor);
            $checkpoint?->__invoke();
            if ($dto['status'] === 'closed') { $current = $dto['receipt']['case_id']; }
        }
        foreach ($out['history'] as $id=>$history) {
            if ($history['actor'] !== $actor) { continue; }
            $checkpoint?->__invoke();
            $rights = $this->eligibilityLedger->current($actor, $history['group'], 'synthetic:learning');
            $terminal = $user === null ? 'deleted' : (in_array($rights['status'], ['revoked', 'deleted'], true) ? $rights['status'] : null);
            if ($terminal !== null) {
                $out['tombstones'][$terminal][] = ['case_id'=>$id, 'case_hash'=>$history['case_hash'], 'at'=>$history['at']];
            }
            if ($current !== $id || $rights['status'] !== 'granted') {
                $out['exclusions'][] = ['case_id'=>$id, 'case_hash'=>$history['case_hash'], 'reason'=>$terminal ?? 'not_current'];
            }
        }
        return $current;
    }

    /** Physical comparison only: these rows never supply a producer or authorization result. */
    private function eligibilityReadWitness(int $actor, \Illuminate\Database\Connection $c): array
    {
        $source = $c->table('characterizations')->where('user_id', $actor)->first();
        $user = $c->table('users')->where('id', $actor)->first(['id', 'email_verified_at']);
        $member = $c->table('learning_company_memberships')->where('user_id', $actor)->where('subject_type', 'account')
            ->where('subject_identifier', (string)$actor)->where('verification_status', 'verified')->whereNotNull('verified_at')->whereNull('revoked_at')->first();
        $group = $member === null ? null : $c->table('learning_company_groups')->where('id', $member->learning_company_group_id)->first(['id', 'company_group_key']);
        $headers = [];
        foreach (['learning_p5_source_revisions', 'learning_p6_base_source_revisions', 'learning_p8_source_revisions', 'learning_p9_source_revisions'] as $table) {
            $header = $source === null ? null : $c->table($table)->where('characterization_id', $source->id)->first();
            $headers[$table] = $header === null ? null : (array) $header;
        }
        return ['source'=>$source === null ? null : (array) $source, 'user'=>$user === null ? null : (array) $user,
            'member'=>$member === null ? null : (array) $member, 'group'=>$group === null ? null : (array) $group, 'headers'=>$headers];
    }

    private function assertEligibilityReadWitness(int $actor, array $witness, \Illuminate\Database\Connection $c, \PDO $pdo): void
    {
        if ($this->connection() !== $c || $c->getPdo() !== $pdo
            || $this->eligibilityReadWitness($actor, $c) !== $witness
            || $this->connection() !== $c || $c->getPdo() !== $pdo) {
            throw new DomainException('learning_eligibility.changed');
        }
    }

    private function eligibilityDigest(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }
}
