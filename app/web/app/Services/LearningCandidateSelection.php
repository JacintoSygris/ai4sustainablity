<?php
namespace App\Services;

use App\Models\LearningBatch;
use App\Console\Commands\RunLearningBatch;
use DomainException;
use Illuminate\Support\Facades\DB;

final class LearningCandidateSelection
{
    private function admit(): void {
        LearningBatch::admit();
        if (config('services.learning_batch.candidate_stage')===null) {
            throw new DomainException('learning_candidate.disabled');
        }
    }
    private function current(array $token, array $package): LearningBatch {
        $this->admit(); LearningBatch::assertToken($token);
        $row=LearningBatch::query()->lockForUpdate()->find(1);
        if (!$row) { throw new DomainException('learning_candidate.unaccepted'); }
        LearningBatch::assertStored($row);
        $b=$package['binding'];
        if ($row->status!=='raw_complete' || $row->batch_id!==$token['batch_id'] || $row->fence!==$token['fence']
            || $b['token']!==$token || $b['context_digest']!==$row->context_digest
            || $b['authority_generation']!==$row->receipt['authority_generation']
            || $b['authority_digest']!==$row->receipt['authority_digest']
            || $b['mapping']!==config('services.learning_batch.candidate_stage')) {
            throw new DomainException('learning_candidate.batch_mismatch');
        }
        RunLearningBatch::assertCurrentAuthority($row->context_digest);
        return $row;
    }
    private function consume(array $package, array $checked): void {
        $this->admit();
        $row=LearningBatch::query()->lockForUpdate()->find(1);
        if (!$row) { throw new DomainException('learning_candidate.unaccepted'); }
        LearningBatch::assertStored($row);
        $b=$package['binding']; $state=$row->dataset_state ?? [];
        $digest=$checked['dataset_digest'];
        if ($row->status!=='raw_complete' || $b['context_digest']!==$row->context_digest
            || $b['mapping']!==config('services.learning_batch.candidate_stage')
            || $b['authority_generation']>$row->receipt['authority_generation']
            || in_array($digest,$state['invalidated'] ?? [],true)
            || empty($state['lineage'][$digest])) {
            throw new DomainException('learning_candidate.consume_ineligible');
        }
        foreach ($state['lineage'][$digest] as $line) {
            $id=$line['case_id'];
            if (isset($state['excluded'][$id]) || isset($state['tombstones'][$id])
                || !isset($state['current'][$id])
                || array_intersect_key($line,$state['current'][$id])!==array_intersect_key($state['current'][$id],$line)) {
                throw new DomainException('learning_candidate.lineage_ineligible');
            }
        }
        // Fresh server-owned full source and ledger projection; old publication is provenance only.
        $fresh=app(LearningCaseExport::class)->eligibilityBundleForAccounts(config('services.learning_batch.actors'));
        (new LearningCaseContract)->assertEligibilityManifest($fresh['manifest_json'],$row->issuer_state['generation'],now()->toDateTimeImmutable());
        $manifest=json_decode($fresh['manifest_json'],true,512,JSON_THROW_ON_ERROR);
        $bindings=json_decode($fresh['bindings_json'],true,512,JSON_THROW_ON_ERROR); unset($bindings['manifest_digest']);
        $context=hash('sha256',json_encode([$fresh['jsonl'],$manifest['cases'],$manifest['tombstones'],$bindings],JSON_THROW_ON_ERROR));
        if ($context!==$b['context_digest']) { throw new DomainException('learning_batch.authority_changed'); }
    }
    public function accept(array $token, array $package): string {
        $this->admit(); LearningBatch::assertToken($token);
        $checked=$this->native($package);
        return DB::transaction(function () use ($token,$package,$checked) {
            $row=$this->current($token,$package);
            if ($checked['dataset_digest']!==$row->receipt['dataset_digest']
                || $checked['development_digest']!==$row->receipt['development_digest']) {
                throw new DomainException('learning_candidate.model_lineage_mismatch');
            }
            $this->consume($package,$checked);
            $raw=json_encode($package,JSON_THROW_ON_ERROR); $key=hash('sha256',$raw);
            $existing=DB::table('learning_candidate_selections')->where('candidate_key',$key)->lockForUpdate()->first();
            if ($existing!==null) {
                if ($existing->technical_metadata!==$raw) { throw new DomainException('learning_candidate.replay_mismatch'); }
                return $key;
            }
            // Native technical publication and fresh server authority are distinct gates.
            $this->current($token,$package);
            DB::table('learning_candidate_selections')->insert([
                'candidate_key'=>$key,'batch_id'=>$token['batch_id'],'fence'=>$token['fence'],
                'authority_generation'=>$package['binding']['authority_generation'],
                'authority_digest'=>$package['binding']['authority_digest'],
                'context_digest'=>$package['binding']['context_digest'],
                'technical_metadata'=>$raw,'selected'=>false,
            ]);
            return $key;
        });
    }
    private function lockedPackage(string $key): array {
        $this->admit();
        $p=DB::table('learning_candidate_selections')->where('candidate_key',$key)->lockForUpdate()->first();
        if (!$p || hash('sha256',$p->technical_metadata)!==$key) { throw new DomainException('learning_candidate.unaccepted'); }
        $package=json_decode($p->technical_metadata,true,32,JSON_THROW_ON_ERROR);
        $binding=$package['binding'];
        if ($p->batch_id!==$binding['token']['batch_id'] || $p->fence!==$binding['token']['fence']
            || $p->context_digest!==$binding['context_digest'] || $p->authority_digest!==$binding['authority_digest']
            || $p->authority_generation!==$binding['authority_generation']) { throw new DomainException('learning_candidate.pointer_mismatch'); }
        return $package;
    }
    public function read(string $key): array {
        return DB::transaction(function () use ($key) {
            $package=$this->lockedPackage($key);
            $checked=$this->native($package);
            $this->consume($package,$checked);
            return $package;
        });
    }
    public function select(string $key, string $decision): void {
        if (!in_array($decision,['synthetic-human-select','synthetic-human-rollback'],true)) {
            throw new DomainException('learning_candidate.explicit_decision_required');
        }
        DB::transaction(function () use ($key,$decision) {
            $package=$this->read($key);
            $row=LearningBatch::query()->lockForUpdate()->findOrFail(1);
            LearningBatch::assertStored($row);
            if ($package['binding']['token']['fence']<$row->fence && $decision!=='synthetic-human-rollback') {
                throw new DomainException('learning_candidate.explicit_rollback_required');
            }
            if (DB::table('learning_candidate_selections')->where('candidate_key',$key)->value('selected')) { return; }
            DB::table('learning_candidate_selections')->where('selected',true)->update(['selected'=>false]);
            DB::table('learning_candidate_selections')->where('candidate_key',$key)->update(['selected'=>true]);
        });
    }
    public function shadow(string $key, array $features): array {
        return DB::transaction(function () use ($key,$features) {
            $package=$this->lockedPackage($key);
            if (!DB::table('learning_candidate_selections')->where('candidate_key',$key)->value('selected')) {
                throw new DomainException('learning_candidate.explicit_selection_required');
            }
            $result=$this->native($package,$features);
            $this->consume($package,$result);
            return $result['proposal'];
        });
    }
    private function native(array $package, ?array $features=null): array {
        $this->admit();
        if (array_keys($package)!==['binding','receipt']) { throw new DomainException('learning_candidate.package_invalid'); }
        LearningBatch::assertToken($package['binding']['token'] ?? []);
        $root=dirname(base_path());
        $nativeMetadata=[];
        $artifact=dirname(base_path()).'/ai-service/artifacts/learning/05a-batch-'.$package['binding']['token']['batch_id'].'/candidate-check-'.bin2hex(random_bytes(16));
        if (config('services.learning_native_fixture') !== null && DB::connection()->getDriverName() !== 'sqlite') {
            $nativeRoot=config('services.learning_native_artifact_root');
            if (!is_string($nativeRoot) || !CharacterizationStateTransaction::admitsIsolatedConnections([DB::connection()],$nativeRoot)) { throw new DomainException('learning_candidate.native_artifact_denied'); }
            $nativeMetadata=['I4S_BATCH_NATIVE_ARTIFACT_ROOT'=>$nativeRoot];
            $artifact=$nativeRoot.'/05a-batch-'.$package['binding']['token']['batch_id'].'/candidate-check-'.bin2hex(random_bytes(16));
        }
        if (!mkdir($artifact,0700,true)) { throw new DomainException('learning_candidate.artifact_failed'); }
        $request=['package'=>$package];
        if ($features!==null) { $request['features']=$features; }
        $raw=json_encode($request,JSON_THROW_ON_ERROR)."\n";
        if (strlen($raw)>1048576) { throw new DomainException('learning_candidate.input_limit'); }
        file_put_contents($artifact.'/input.json',$raw);
        // Cold launch and imports share the owner-approved 20-second isolated synthetic budget.
        $started=hrtime(true)/1e9; $deadline=$started+20; $process=null;
        try {
            self::assertNativeDeadline($deadline);
            $process=proc_open([$root.'/ai-service/.venv-learning/'.(PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python'),'-B',$root.'/ai-service/scripts/learning-case-batch.py','--candidate-check'],[
                0=>['file',$artifact.'/input.json','r'],1=>['file',$artifact.'/stdout.log','w'],2=>['file',$artifact.'/stderr.log','w'],
            ],$pipes,$root.'/ai-service',array_replace(array_diff_key(getenv(),['I4S_BATCH_NATIVE_ARTIFACT_ROOT'=>true]),$nativeMetadata,[
                'PYTHONPATH'=>$root.'/ai-service/src','PYTHONDONTWRITEBYTECODE'=>'1',
                'APP_ENV'=>'testing','I4S_BATCH_SYNTHETIC_CAPABILITY'=>'test-namespace:t07-export',
            ]),['bypass_shell'=>true]);
            if (!is_resource($process)) { throw new DomainException('learning_candidate.start_failed'); }
            self::assertNativeDeadline($deadline);
            do {
                self::assertNativeDeadline($deadline);
                $status=proc_get_status($process);
                clearstatcache();
                if (filesize($artifact.'/stdout.log')>1048576 || filesize($artifact.'/stderr.log')>65536) {
                    throw new DomainException('learning_candidate.child_bound');
                }
                self::assertNativeDeadline($deadline);
                if (!$status['running']) { break; }
                usleep(20000); clearstatcache();
            } while (true);
            if ($status['exitcode']!==0) { throw new DomainException('learning_candidate.technical_denied'); }
            self::assertNativeDeadline($deadline);
            $bytes=file_get_contents($artifact.'/stdout.log',false,null,0,1048577);
            if ($bytes===false || strlen($bytes)>1048576) { throw new DomainException('learning_candidate.output_limit'); }
            self::assertNativeDeadline($deadline);
            $checked=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
            if (($checked['package'] ?? null)!==$package) { throw new DomainException('learning_candidate.package_mismatch'); }
            self::assertNativeDeadline($deadline);
            return $checked;
        } finally {
            if (is_resource($process)) {
                $status=proc_get_status($process);
                if ($status['running']) { proc_terminate($process); }
                $stopDeadline=hrtime(true)/1e9+0.5;
                do { $status=proc_get_status($process); if (!$status['running']) { break; } usleep(10000); } while (hrtime(true)/1e9<$stopDeadline);
                file_put_contents($artifact.'/child-status.json',json_encode(['running'=>$status['running'],'elapsed'=>hrtime(true)/1e9-$started],JSON_THROW_ON_ERROR));
                if (!$status['running']) { proc_close($process); }
            }
        }
    }
    private static function assertNativeDeadline(float $deadline): void {
        if (hrtime(true)/1e9>$deadline) { throw new DomainException('learning_candidate.child_bound'); }
    }
}
