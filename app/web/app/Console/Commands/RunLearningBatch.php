<?php
namespace App\Console\Commands;

use App\Models\LearningBatch;
use App\Services\LearningCaseExport;
use DomainException;
use Illuminate\Console\Command;

final class RunLearningBatch extends Command
{
    protected $signature='learning:batch';
    protected $description='Private synthetic raw learning batch (default disabled)';

    private static function assertDeadline(?float $deadline, string $code='learning_batch.timeout'): void {
        if ($deadline!==null && hrtime(true)/1e9>$deadline) { throw new DomainException($code); }
    }
    private function bundle(array $token, ?float $deadline=null): array {
        self::assertDeadline($deadline);
        $result=LearningBatch::locked($token,function ($row) use ($deadline) {
            self::assertDeadline($deadline);
            $export=app(LearningCaseExport::class);
            $export->resumeEligibilityFromBatch($row);
            self::assertDeadline($deadline);
            $bundle=$export->eligibilityBundleForAccounts(config('services.learning_batch.actors'));
            self::assertDeadline($deadline);
            $witness=$row->issuer_state['dataset_witness'] ?? null;
            $export->persistEligibilityInBatch($row);
            if ($witness!==null) { $cursor=$row->issuer_state; $cursor['dataset_witness']=$witness; $row->issuer_state=$cursor; $row->save(); }
            self::assertDeadline($deadline);
            return $bundle;
        });
        self::assertDeadline($deadline);
        return $result;
    }
    private static function authorityContext(array $bundle): string {
        $manifest=json_decode($bundle['manifest_json'],true,512,JSON_THROW_ON_ERROR);
        $bindings=json_decode($bundle['bindings_json'],true,512,JSON_THROW_ON_ERROR);
        unset($bindings['manifest_digest']);
        return hash('sha256',json_encode([$bundle['jsonl'],$manifest['cases'],$manifest['tombstones'],$bindings],JSON_THROW_ON_ERROR));
    }
    public function handle(): int {
        $token=null; $process=null; $exit=null; $artifact=null; $fresh=null; $ackManifest=null;
        try {
            LearningBatch::admit();
            $token=LearningBatch::claim();
            if ($token===null) { return 1; }
            $bundle=$this->bundle($token); $context=self::authorityContext($bundle);
            $state=LearningBatch::locked($token,function ($row) use ($context,$bundle,$token) {
                $prior=$row->dataset_state ?? [];
                $cursor=$row->issuer_state;
                $cursor['dataset_witness']=['token'=>$token,'context_digest'=>$context,'bundle'=>$bundle,'state'=>$prior];
                $row->issuer_state=$cursor; $row->context_digest=$context; $row->save(); return $prior;
            });
            $root=dirname(base_path());
            $python=$root.'/ai-service/.venv-learning/Scripts/python.exe';
            // Fixed trusted local launcher; no executable, shell or path supplied by a user.
            $mode=config('services.learning_batch.adversarial_mode','');
            if (!in_array($mode,['','timeout','crash','malformed','oversized','receipt_generation'],true)) { throw new DomainException('learning_batch.mode_invalid'); }
            foreach (['tombstones','excluded','lineage','current'] as $key) { if (isset($state[$key]) && $state[$key]===[]) { $state[$key]=(object)[]; } }
            $request=['bundle'=>$bundle,'state'=>$state===[]?(object)[]:$state,'token'=>$token,'context_digest'=>$context,
                'cutoffs'=>['train_end'=>'2026-01-09T00:00:00Z','calibration_end'=>'2026-01-11T00:00:00Z','holdout_end'=>'2026-02-01T00:00:00Z']];
            if (config('services.learning_batch.candidate_stage')!==null) {
                $request['candidate_stage']=config('services.learning_batch.candidate_stage');
            }
            $nativeMetadata=[];
            $artifact=dirname(base_path()).'/ai-service/artifacts/learning/05a-batch-'.$token['batch_id'];
            if (config('services.learning_native_fixture') !== null && \Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
                $nativeRoot=config('services.learning_native_artifact_root');
                if (!is_string($nativeRoot) || !\App\Services\CharacterizationStateTransaction::admitsIsolatedConnections([\Illuminate\Support\Facades\DB::connection()],$nativeRoot)) { throw new DomainException('learning_batch.native_artifact_denied'); }
                $nativeMetadata=['I4S_BATCH_NATIVE_ARTIFACT_ROOT'=>$nativeRoot];
                $artifact=$nativeRoot.'/05a-batch-'.$token['batch_id'];
            }
            if (!mkdir($artifact,0700,true)) { throw new DomainException('learning_batch.artifact_failed'); }
            $raw=json_encode($request,JSON_THROW_ON_ERROR)."\n";
            if (strlen($raw)>1048576) { throw new DomainException('learning_batch.input_limit'); }
            file_put_contents($artifact.'/request.json',$raw);
            $started=hrtime(true)/1e9;
            $deadline=$started+($mode==='timeout'?1:20);
            self::assertDeadline($deadline);
            $process=proc_open([$python,'-B',$root.'/ai-service/scripts/learning-case-batch.py'],[
                0=>['file',$artifact.'/request.json','r'],1=>['file',$artifact.'/stdout.log','w'],2=>['file',$artifact.'/stderr.log','w'],
            ],$pipes,$root.'/ai-service',array_replace(array_diff_key(getenv(),['I4S_BATCH_NATIVE_ARTIFACT_ROOT'=>true]),$nativeMetadata,[
                'PYTHONPATH'=>$root.'/ai-service/src'.PATH_SEPARATOR.$root.'/ai-service/src/tests',
                'PYTHONDONTWRITEBYTECODE'=>'1','APP_ENV'=>'testing','I4S_BATCH_SYNTHETIC_CAPABILITY'=>'test-namespace:t07-export',
                'I4S_BATCH_ADVERSARIAL_MODE'=>$mode,'I4S_BATCH_REFRESH_PATH'=>$artifact.'/refresh.json',
            ]),['bypass_shell'=>true]);
            if (!is_resource($process)) { throw new DomainException('learning_batch.start_failed'); }
            self::assertDeadline($deadline);
            $sent=false; $lastPoll=$started;
            while (true) {
                self::assertDeadline($deadline);
                $status=proc_get_status($process);
                self::assertDeadline($deadline);
                if (!$status['running']) { $exit=$status['exitcode']; break; }
                $output=self::readOutput($artifact.'/stdout.log',1048576);
                self::readOutput($artifact.'/stderr.log',65536);
                self::assertDeadline($deadline);
                $polled=false;
                if (hrtime(true)/1e9-$lastPoll>=0.25) {
                    self::assertDeadline($deadline);
                    LearningBatch::heartbeat($token);
                    self::assertDeadline($deadline);
                    $fresh=$this->bundle($token,$deadline); $lastPoll=hrtime(true)/1e9;
                    $output=self::readOutput($artifact.'/stdout.log',1048576);
                    if (self::authorityContext($fresh)!==$context) { throw new DomainException('learning_batch.authority_changed'); }
                    self::assertDeadline($deadline);
                    $polled=true;
                }
                if (!$sent && str_contains($output,"\n")) {
                    $ready=json_decode(explode("\n",$output)[0],true,32,JSON_THROW_ON_ERROR);
                    if ($ready!==['ready'=>true]) { throw new DomainException('learning_batch.output_invalid'); }
                    if (!$polled) {
                        $fresh=$this->bundle($token,$deadline);
                        if (self::authorityContext($fresh)!==$context) { throw new DomainException('learning_batch.authority_changed'); }
                    }
                    $ackManifest=json_decode($fresh['manifest_json'],true,512,JSON_THROW_ON_ERROR);
                    self::assertDeadline($deadline);
                    LearningBatch::locked($token,function ($row) use ($fresh,$deadline) {
                        self::assertDeadline($deadline);
                        $cursor=$row->issuer_state; $cursor['dataset_witness']['refresh']=$fresh;
                        $row->issuer_state=$cursor; $row->save();
                        self::assertDeadline($deadline);
                    });
                    self::assertDeadline($deadline);
                    $refresh=json_encode(['bundle'=>$fresh],JSON_THROW_ON_ERROR)."\n";
                    if (strlen($refresh)>1048576) { throw new DomainException('learning_batch.input_limit'); }
                    // Completion marker prevents the reader from consuming a partial file.
                    self::assertDeadline($deadline);
                    file_put_contents($artifact.'/refresh.json',$refresh);
                    self::assertDeadline($deadline);
                    file_put_contents($artifact.'/refresh-ready','ready'); $sent=true;
                    self::assertDeadline($deadline);
                }
                usleep(20000);
            }
            self::stopChild($process,$artifact,$started); $process=null;
            self::assertDeadline($deadline);
            $output=self::readOutput($artifact.'/stdout.log',1048576);
            self::readOutput($artifact.'/stderr.log',65536);
            self::assertDeadline($deadline);
            if (!$sent || $exit!==0) { throw new DomainException('learning_batch.child_failed'); }
            $lines=explode("\n",trim($output));
            if (count($lines)!==2) { throw new DomainException('learning_batch.output_invalid'); }
            $result=json_decode($lines[1],true,128,JSON_THROW_ON_ERROR);
            $candidatePackage=$result['candidate'] ?? null;
            unset($result['candidate']);
            if (($result['receipt']['authority_generation'] ?? null)!==($ackManifest['generation'] ?? null)
                || ($result['receipt']['authority_digest'] ?? null)!==($ackManifest['canonical_digest'] ?? null)) {
                throw new DomainException('learning_batch.receipt_authority_mismatch');
            }
            // Producer phase ends after successful exit, bounded output parsing and receipt matching.
            self::assertDeadline($deadline);
            self::accept($token,$result,$context,fn()=>$this->bundle($token),$ackManifest);
            if ($candidatePackage!==null) {
                app(\App\Services\LearningCandidateSelection::class)->accept($token,$candidatePackage);
            }
            return 0;
        } catch (\Throwable $error) {
            if (is_resource($process)) { self::stopChild($process,$artifact,$started); $process=null; }
            if ($token!==null && $fresh!==null && isset($context) && self::authorityContext($fresh)!==$context) {
                try { LearningBatch::locked($token,function ($row) use ($fresh) {
                    $state=$row->dataset_state ?? [];
                    foreach (array_keys($state['lineage'] ?? []) as $digest) {
                        if (!in_array($digest,$state['invalidated'] ?? [],true)) { $state['invalidated'][]=$digest; }
                    }
                    $bindings=json_decode($fresh['bindings_json'],true,512,JSON_THROW_ON_ERROR);
                    foreach ($bindings['exclusions'] ?? [] as $exclusion) { $state['excluded'][$exclusion['case_id']]=$exclusion['reason']; }
                    $manifest=json_decode($fresh['manifest_json'],true,512,JSON_THROW_ON_ERROR);
                    foreach ($manifest['tombstones'] as $kind=>$rows) { foreach ($rows as $tombstone) {
                        $state['tombstones'][$tombstone['case_id']]=['case_hash'=>$tombstone['case_hash'],'kind'=>$kind,'at'=>$tombstone['at']];
                    } }
                    $row->dataset_state=$state; $row->save();
                }); } catch (\Throwable) { /* never write through a superseded fence */ }
            }
            if ($token!==null) { try { LearningBatch::abort($token); } catch (\Throwable) { /* foreign/expired owner stays untouched */ } }
            $this->error($error instanceof DomainException ? $error->getMessage() : 'learning_batch.failed');
            return 1;
        }
    }
    private static function readOutput(string $path, int $limit): string {
        $handle=fopen($path,'rb');
        if ($handle===false) { throw new DomainException('learning_batch.output_missing'); }
        try { $raw=fread($handle,$limit+1); } finally { fclose($handle); }
        if ($raw===false || strlen($raw)>$limit) { throw new DomainException('learning_batch.output_limit'); }
        return $raw;
    }
    private static function stopChild($process, string $artifact, float $started): void {
        $status=proc_get_status($process);
        if ($status['running']) { proc_terminate($process); }
        $deadline=hrtime(true)/1e9+0.5;
        do {
            $status=proc_get_status($process);
            if (!$status['running']) { break; }
            usleep(10000);
        } while (hrtime(true)/1e9<$deadline);
        file_put_contents($artifact.'/child-status.json',json_encode(['running'=>$status['running'],'pid'=>$status['pid'],'actual_child_elapsed'=>hrtime(true)/1e9-$started],JSON_THROW_ON_ERROR));
        // proc_close can wait: never call it while a child is known to be running.
        if (!$status['running']) { proc_close($process); }
    }
    private static function assertState(array $state, array $prior, array $fresh, array $receipt): void {
        $bindings=json_decode($fresh['bindings_json'],true,512,JSON_THROW_ON_ERROR);
        $current=array_column($bindings['cases'],null,'case_id');
        if (($state['current'] ?? null)!==$current || !is_array($state['lineage'] ?? null)
            || !is_array($state['invalidated'] ?? null) || !array_is_list($state['invalidated'])
            || !is_array($state['excluded'] ?? null) || !is_array($state['tombstones'] ?? null)
            || ($state['lineage'][$receipt['dataset_digest']] ?? [])===[]
            || !is_int($state['generation'] ?? null) || $state['generation']<1 || $state['generation']>9007199254740991
            || $state['generation']<=($prior['generation'] ?? 0)) { throw new DomainException('learning_batch.state_invalid'); }
        foreach ($prior['invalidated'] ?? [] as $digest) {
            if (!in_array($digest,$state['invalidated'],true)) { throw new DomainException('learning_batch.state_invalid'); }
        }
        foreach (['excluded','tombstones','lineage'] as $key) {
            foreach ($prior[$key] ?? [] as $id=>$value) {
                if (!array_key_exists($id,$state[$key]) || ($key==='lineage' && $state[$key][$id]!==$value)) {
                    throw new DomainException('learning_batch.state_invalid');
                }
                if ($key==='tombstones' && $state[$key][$id]!==$value) { throw new DomainException('learning_batch.state_invalid'); }
            }
        }
        $keys=['case_id','case_hash','source_revisions','rights_digest','policy_digest','export_digest','source_kind','source_revision'];
        $exports=[]; foreach (explode("\n",trim($fresh['jsonl'])) as $raw) { if($raw!=='') { $e=json_decode($raw,true,512,JSON_THROW_ON_ERROR); $exports[$e['reference']['case_id']]=$e; } }
        foreach ($state['lineage'][$receipt['dataset_digest']] as $line) {
            if (!is_array($line) || array_diff(array_keys($line),array_merge($keys,['feature_digest','company_group_key','period_scope']))!==[] || !isset($current[$line['case_id']])
                || array_intersect_key($line,array_flip($keys))!==array_intersect_key($current[$line['case_id']],array_flip($keys))
                || isset($state['excluded'][$line['case_id']]) || isset($state['tombstones'][$line['case_id']])) {
                throw new DomainException('learning_batch.state_invalid');
            }
            if (isset($line['feature_digest']) && ($line['feature_digest']!==($exports[$line['case_id']]['X']['digest'] ?? null) || ($line['company_group_key'] ?? null)!==($exports[$line['case_id']]['company_group_key'] ?? null) || ($line['period_scope'] ?? null)!==($exports[$line['case_id']]['period_scope'] ?? null))) { throw new DomainException('learning_batch.state_invalid'); }
            foreach ($line['source_revisions'] as $revision) {
                foreach (['generation','revision'] as $key) {
                    if (!is_int($revision[$key] ?? null) || $revision[$key]<1 || $revision[$key]>9007199254740991) {
                        throw new DomainException('learning_batch.counter_invalid');
                    }
                }
            }
        }
    }
    public static function accept(array $token, array $result, string $context, callable $current, ?array $ack=null, ?float $deadline=null): void {
        self::assertDeadline($deadline);
        LearningBatch::assertToken($token);
        $replayed=\Illuminate\Support\Facades\DB::transaction(function () use ($token,$result,$context,$current,$deadline) {
            self::assertDeadline($deadline);
            $row=LearningBatch::query()->lockForUpdate()->find(1);
            if ($row) { LearningBatch::assertStored($row); }
            if ($row && $row->status==='raw_complete' && $row->batch_id===$token['batch_id'] && $row->fence===$token['fence']) {
                self::assertCurrentAuthority($context);
                self::assertDeadline($deadline);
                if (array_keys($result)!==['receipt','state','consumable'] || $result['consumable']!==true || $row->receipt!==$result['receipt']
                    || $row->dataset_state!==$result['state'] || $row->context_digest!==$context || self::authorityContext($current())!==$context) {
                    throw new DomainException('learning_batch.replay_mismatch');
                }
                self::assertDeadline($deadline);
                self::assertCurrentAuthority($context);
                self::assertDeadline($deadline);
                return true;
            }
            return false;
        });
        if ($replayed) { return; }
        LearningBatch::locked($token,function ($row) use ($token,$result,$context,$current,$ack,$deadline) {
            self::assertDeadline($deadline);
            $expectedGeneration=$ack['generation'] ?? ($row->issuer_state['generation'] ?? null);
            if ($ack!==null && (($result['receipt']['authority_digest'] ?? null)!==$ack['canonical_digest'])) { throw new DomainException('learning_batch.receipt_authority_mismatch'); }
            self::assertCurrentAuthority($context);
            self::assertDeadline($deadline);
            $fresh=$current();
            self::assertDeadline($deadline);
            $r=$result['receipt'] ?? null;
            $manifest=json_decode($fresh['manifest_json'],true,512,JSON_THROW_ON_ERROR);
            $keys=['batch_id','fence','dataset_digest','context_digest','mode','promotion_allowed','sector_guard','crc_floor','fit_heads','optuna_trials','metrics','development_digest','authority_generation','authority_digest'];
            if (array_keys($result)!==['receipt','state','consumable'] || self::authorityContext($fresh)!==$context || !is_array($r)
                || array_keys($r)!==$keys || ($result['consumable'] ?? null)!==true
                || ($r['batch_id'] ?? null)!==$token['batch_id'] || ($r['fence'] ?? null)!==$token['fence']
                || ($r['context_digest'] ?? null)!==$row->context_digest || $row->context_digest!==$context
                || ($r['mode'] ?? null)!=='raw_masked_only' || ($r['promotion_allowed'] ?? null)!==false
                || ($r['sector_guard'] ?? null)!==false || ($r['crc_floor'] ?? null)!==false
                || !is_int($r['authority_generation'] ?? null) || $r['authority_generation']<1 || $r['authority_generation']!==$expectedGeneration || $r['authority_generation']>=$manifest['generation']
                || !is_int($r['fit_heads'] ?? null) || $r['fit_heads']<1 || !is_int($r['optuna_trials'] ?? null) || $r['optuna_trials']<1
                || !is_array($result['state'] ?? null) || ($result['state']['manifest_digest'] ?? null)!==($r['authority_digest'] ?? null)
                || ($result['state']['generation'] ?? null)!==$r['authority_generation']
                || !is_string($r['dataset_digest']) || !preg_match('/\A[a-f0-9]{64}\z/',$r['dataset_digest'])
                || !is_string($r['development_digest']) || !preg_match('/\A[a-f0-9]{64}\z/',$r['development_digest'])
                || !is_array($r['metrics']) || ($result['state']['invalidated'] ?? null)===null
                || in_array($r['dataset_digest'],$result['state']['invalidated'],true)
                || !isset($result['state']['lineage'][$r['dataset_digest'] ?? ''])) { throw new DomainException('learning_batch.receipt_invalid'); }
            self::assertState($result['state'],$row->dataset_state ?? [],$fresh,$r);
            self::assertDeadline($deadline);
            self::assertCanonicalDataset($row,$token,$result,$context);
            self::assertDeadline($deadline);
            self::assertCurrentAuthority($context);
            self::assertDeadline($deadline);
            // Recheck lease after authority callbacks, before the terminal write.
            if ($row->lease_until<=now()->getTimestamp()) { throw new DomainException('learning_batch.stale_owner'); }
            $row->dataset_state=$result['state']; $row->receipt=$r; $row->status='raw_complete'; $row->lease_until=null; $row->save();
            self::assertDeadline($deadline);
        });
    }
    public static function assertCurrentAuthority(string $context): void {
        LearningBatch::admit();
        $verified=app(LearningCaseExport::class)->eligibilityBundleForAccounts(config('services.learning_batch.actors'));
        if (self::authorityContext($verified)!==$context) { throw new DomainException('learning_batch.authority_changed'); }
    }
    private static function assertCanonicalDataset(LearningBatch $row, array $token, array $result, string $context): void {
        $witness=$row->issuer_state['dataset_witness'] ?? null;
        if (!is_array($witness) || ($witness['token'] ?? null)!==$token || ($witness['context_digest'] ?? null)!==$context
            || !isset($witness['bundle'],$witness['refresh'],$witness['state'])
            || self::authorityContext($witness['bundle'])!==$context || self::authorityContext($witness['refresh'])!==$context) {
            throw new DomainException('learning_batch.witness_invalid');
        }
        $manifest=json_decode($witness['refresh']['manifest_json'],true,512,JSON_THROW_ON_ERROR);
        if ($result['receipt']['authority_generation']!==$manifest['generation'] || $result['receipt']['authority_digest']!==$manifest['canonical_digest']) {
            throw new DomainException('learning_batch.witness_invalid');
        }
        foreach (['tombstones','excluded','lineage','current'] as $key) {
            if (isset($witness['state'][$key]) && $witness['state'][$key]===[]) { $witness['state'][$key]=(object)[]; }
        }
        if ($witness['state']===[]) { $witness['state']=(object)[]; }
        $nativeMetadata=[];
        $artifact=dirname(base_path()).'/ai-service/artifacts/learning/05a-batch-'.$token['batch_id'].'/witness-'.bin2hex(random_bytes(16));
        if (config('services.learning_native_fixture') !== null && \Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'sqlite') {
            $nativeRoot=config('services.learning_native_artifact_root');
            if (!is_string($nativeRoot) || !\App\Services\CharacterizationStateTransaction::admitsIsolatedConnections([\Illuminate\Support\Facades\DB::connection()],$nativeRoot)) { throw new DomainException('learning_batch.native_artifact_denied'); }
            $nativeMetadata=['I4S_BATCH_NATIVE_ARTIFACT_ROOT'=>$nativeRoot];
            $artifact=$nativeRoot.'/05a-batch-'.$token['batch_id'].'/witness-'.bin2hex(random_bytes(16));
        }
        if (!mkdir($artifact,0700,true)) { throw new DomainException('learning_batch.artifact_failed'); }
        $raw=json_encode($witness,JSON_THROW_ON_ERROR)."\n";
        if (strlen($raw)>1048576) { throw new DomainException('learning_batch.input_limit'); }
        file_put_contents($artifact.'/input.json',$raw);
        $root=dirname(base_path()); $started=hrtime(true)/1e9; $deadline=$started+6; $process=null;
        try {
            self::assertDeadline($deadline,'learning_batch.witness_timeout');
            $process=proc_open([$root.'/ai-service/.venv-learning/Scripts/python.exe','-B',$root.'/ai-service/scripts/learning-case-batch.py','--witness'],[
                0=>['file',$artifact.'/input.json','r'],1=>['file',$artifact.'/stdout.log','w'],2=>['file',$artifact.'/stderr.log','w'],
            ],$pipes,$root.'/ai-service',array_replace(array_diff_key(getenv(),['I4S_BATCH_NATIVE_ARTIFACT_ROOT'=>true]),$nativeMetadata,[
                'PYTHONPATH'=>$root.'/ai-service/src'.PATH_SEPARATOR.$root.'/ai-service/src/tests','PYTHONDONTWRITEBYTECODE'=>'1',
                'APP_ENV'=>'testing','I4S_BATCH_SYNTHETIC_CAPABILITY'=>'test-namespace:t07-export',
            ]),['bypass_shell'=>true]);
            if (!is_resource($process)) { throw new DomainException('learning_batch.start_failed'); }
            self::assertDeadline($deadline,'learning_batch.witness_timeout');
            do {
                self::assertDeadline($deadline,'learning_batch.witness_timeout');
                $status=proc_get_status($process);
                self::readOutput($artifact.'/stdout.log',1048576); self::readOutput($artifact.'/stderr.log',65536);
                self::assertDeadline($deadline,'learning_batch.witness_timeout');
                if (!$status['running']) { break; }
                usleep(20000);
            } while (true);
            if ($status['exitcode']!==0) { throw new DomainException('learning_batch.witness_invalid'); }
            $expected=json_decode(self::readOutput($artifact.'/stdout.log',1048576),true,128,JSON_THROW_ON_ERROR);
            self::assertDeadline($deadline,'learning_batch.witness_timeout');
            if ($expected['consumable']!==true || $expected['dataset_digest']!==$result['receipt']['dataset_digest'] || $expected['state']!==$result['state']) {
                throw new DomainException('learning_batch.state_invalid');
            }
            self::assertDeadline($deadline,'learning_batch.witness_timeout');
        } finally { if (is_resource($process)) { self::stopChild($process,$artifact,$started); } }
    }

}
