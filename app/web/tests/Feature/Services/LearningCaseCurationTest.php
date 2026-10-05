<?php

use App\Models\{Characterization, User, EsrsTopic, LearningCase, LearningCaseP5Snapshot, LearningCompanyGroup, LearningCompanyMembership};
use App\Services\{LearningCaseClosure, LearningAuthorizationAuthority, LearningAuthorization, LearningAuthorizationLedger, LearningCaseP5Storage, LearningCaseSnapshot, LearningP5Snapshot, CharacterizationStateTransaction, ApiCharacterizationGateway, CharacterizationPredictionMapper, EsrsDatapointCorpusBuilder, Ar16MatterDrMappingRepository, EsrsDatapointResponseState};
use Illuminate\Support\Facades\{DB, Schema, Http};
use Illuminate\Database\Schema\Blueprint;

// Test namespace only. human_product exercises the closed contract inside this isolated runner.
class T07CurationCorpus extends EsrsDatapointCorpusBuilder
{
    public string $version = 'synthetic-corpus-v1';
    public function build(Characterization $row): array {
        return ['synthetic_only' => true, 'version' => $this->version,
            'material_topic_ids' => $row->form_data['materiality_confirmation']['confirmed_topic_ids'],
            'activated_esrs_standards' => [],
            'generation' => ['mapping_granularity' => 'synthetic', 'coverage_status' => 'synthetic'],
            'summary' => array_fill_keys(['total_datapoint_count','always_required_datapoint_count','topical_datapoint_count','minimum_disclosure_requirement_datapoint_count','voluntary_datapoint_count','conditional_datapoint_count','phase_in_datapoint_count'], 0),
            'blocks' => [['datapoints' => [['id' => 'SYNTHETIC_A'], ['id' => 'SYNTHETIC_B']]]]];
    }
}
beforeEach(function () {
    $portableRoot=base_path('artifacts/learning-tests'); if (!is_dir($portableRoot)) mkdir($portableRoot,0700,true);
    $portableMap=$portableRoot.'/03-t06-close-mapping.json'; if (!is_file($portableMap)) file_put_contents($portableMap,'{"candidate_topics":[]}');

    expect(app()->environment())->toBe('testing');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    Http::preventStrayRequests();
    foreach (['learning_case_closure','learning_source_clock','learning_p6_base_source_clock','learning_p8_source_clock','learning_p9_source_clock','learning_p5_live_capture','learning_p6_prepared_request','learning_p6_job_parent_fence','learning_p6_interpretation_context'] as $flag) { config(['services.'.$flag.'.enabled' => true]); }
    config(['services.characterization.api.model_profile' => 't06_close_synthetic', 'services.characterization.prediction_mapping_path' => base_path('artifacts/learning-tests').'/03-t06-close-mapping.json']);
    Schema::create('learning_p9_source_revisions', function (Blueprint $t) {
        $t->foreignId('characterization_id')->primary()->constrained('characterizations')->cascadeOnDelete();
        $t->unsignedBigInteger('generation'); $t->unsignedBigInteger('revision'); $t->string('epoch',64); $t->string('digest',64);
    });
    app()->instance(CharacterizationPredictionMapper::class, CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}'));
});

function t07bReady(): array {
    $f=t07CurationClosed(); $e=t07CurationExporter($f);
    $f['ledger']->grant($f['u']->id,$f['g']->id,'synthetic:curation',0,hash('sha256','curation-grant'));
    $packet=$e->exportForAccount($f['u']->id,$e->referenceForAccount($f['u']->id));
    $s=App\Services\LearningCaseCuration::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:curation'],$e,$f['ledger']);
    $cmd=['schema_version'=>'learning-case-annotation-command-v1','export_digest'=>$packet['digest'],'reference'=>$packet['proofs']['reference'],'expected_annotation_revision'=>0,'command_id'=>hash('sha256','annotation-1'),'topic_labels'=>[['topic_id'=>'1','value'=>0,'observed_mask'=>1]],'notes'=>['status'=>'withheld','reason'=>'free_text_transport_disabled']];
    return $f+compact('s','packet','cmd');
}
function t07bImport(array $f, ?array $cmd=null, ?int $curator=null): array { return $f['s']->importForAccount($f['u']->id,$curator??$f['u']->id,$f['packet']['jsonl'],json_encode($cmd??$f['cmd'],JSON_THROW_ON_ERROR)); }

it('T07b F1 revoke regrant denies original replay but permits a fresh command', function () {
    $f=t07bReady(); $first=t07bImport($f);
    expect(t07bImport($f))->toBe($first);
    $f['ledger']->revoke($f['u']->id,$f['g']->id,'synthetic:curation',1,hash('sha256','F1-revoke'));
    $f['ledger']->grant($f['u']->id,$f['g']->id,'synthetic:curation',2,hash('sha256','F1-regrant'));
    t07bRepairDenied($f, $f['cmd'], 'learning_curation.replay_authorization_changed');
    $cmd=$f['cmd']; $cmd['command_id']=hash('sha256','F1-fresh'); $cmd['expected_annotation_revision']=1;
    $second=t07bImport($f,$cmd);
    expect($second['annotation_revision'])->toBe(2)->and(t07bImport($f,$cmd))->toBe($second);
});

function t07bRepairDenied(array $f, array $cmd, string $message): void {
    $before=t07CurationRows(); $annotations=DB::table('learning_case_annotations')->get()->all();
    $ledger=DB::table('learning_authorization_states')->get()->all(); $author=$f['row']->fresh()->getRawOriginal();
    $c=DB::connection(); $pdo=$c->getPdo(); $level=$c->transactionLevel();
    app()->forgetInstance(CharacterizationStateTransaction::class);
    DB::enableQueryLog(); DB::flushQueryLog();
    try { expect(fn()=>t07bImport($f,$cmd))->toThrow(DomainException::class,$message); }
    finally { $queries=DB::getQueryLog(); DB::disableQueryLog(); }
    $writes=array_filter($queries,fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query']));
    expect($writes)->toBe([])->and(t07CurationRows())->toBe($before)
        ->and(DB::table('learning_case_annotations')->get()->all())->toEqual($annotations)
        ->and(DB::table('learning_authorization_states')->get()->all())->toEqual($ledger)
        ->and($f['row']->fresh()->getRawOriginal())->toBe($author)
        ->and($c->getPdo())->toBe($pdo)->and($c->transactionLevel())->toBe($level);
}

it('T07b F2 raw persisted fractional revisions deny replay and append', function () {
    $f=t07bReady(); t07bImport($f); $c=DB::connection();
    foreach (['expected_revision'=>0.5,'annotation_revision'=>1.5,'curator_authorization_generation'=>1.5] as $column=>$value) {
        foreach (['replay','append'] as $operation) {
            $c->beginTransaction();
            try {
                // Privileged SQL prepares corruption only; the reader does not claim WORM.
                DB::table('learning_case_annotations')->update([$column=>$value]);
                expect(App\Models\LearningCaseAnnotation::query()->sole()->getRawOriginal($column))->toBe($value);
                $cmd=$f['cmd'];
                if ($operation==='append') { $cmd['command_id']=hash('sha256','F2-append'); $cmd['expected_annotation_revision']=1; }
                t07bRepairDenied($f,$cmd,'learning_curation.annotation_corrupt');
            } finally { $c->rollBack(); }
        }
    }
});

it('T07b F2 malformed raw bounds and rehashed binding controls deny without DML', function () {
    $f=t07bReady(); t07bImport($f); $c=DB::connection();
    $vectors=[];
    foreach (['expected_revision','annotation_revision','curator_authorization_generation'] as $column) {
        foreach (['malformed',-1,9007199254740992] as $value) { $vectors[]=[$column=>$value]; }
    }
    $vectors[]=['annotation_revision'=>0]; $vectors[]=['curator_authorization_generation'=>0];
    $row=DB::table('learning_case_annotations')->sole();
    $cmd=json_decode($row->command_text,true); $cmd['expected_annotation_revision']=1;
    $text=json_encode($cmd,JSON_THROW_ON_ERROR); $vectors[]=['command_text'=>$text,'command_digest'=>hash('sha256',$text)];
    $annotation=json_decode($row->annotation_text,true); $annotation['annotation_revision']=2;
    $text=json_encode($annotation,JSON_THROW_ON_ERROR); $vectors[]=['annotation_text'=>$text,'annotation_digest'=>hash('sha256',$text)];
    $vectors[]=['command_id'=>hash('sha256','F2-wrong-binding')];
    foreach ($vectors as $values) {
        foreach (['replay','append'] as $operation) {
            $c->beginTransaction();
            try {
                DB::table('learning_case_annotations')->update($values);
                $cmd=$f['cmd'];
                if ($operation==='append') { $cmd['command_id']=hash('sha256','F2-bounds-append'); $cmd['expected_annotation_revision']=1; }
                t07bRepairDenied($f,$cmd,'learning_curation.annotation_corrupt');
            } finally { $c->rollBack(); }
        }
    }
});

it('T07b postCommon source receipt rights annotation and opt-in changes roll back', function () {
    $f=t07bReady(); $c=DB::connection();
    foreach(['source','receipt','rights','annotation','mode'] as $variant) {
        $before=t07CurationRows(); $raw=$f['row']->fresh()->getRawOriginal(); $level=$c->transactionLevel(); $pdo=$c->getPdo();
        $dispatcher=$c->getEventDispatcher(); $c->setEventDispatcher(clone $dispatcher); $hits=0;
        $ctx=new ReflectionProperty($f['s'],'context'); $original=$ctx->getValue($f['s']);
        $c->getEventDispatcher()->listen(Illuminate\Database\Events\TransactionCommitted::class,function()use($c,$level,$variant,$f,$ctx,&$hits){
            if ($hits!==0 || $c->transactionLevel()!==$level+1) { return; }
            $common=array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),fn($x)=>($x['class']??'')===CharacterizationStateTransaction::class && ($x['function']??'')==='runForUser');
            if($common===[]) { return; } $hits++;
            match($variant) {
                'source'=>DB::table('characterizations')->where('id',$f['row']->id)->update(['status'=>'draft']),
                'receipt'=>DB::table('learning_case_closure_receipts')->update(['receipt_hash'=>str_repeat('0',64)]),
                'rights'=>$f['ledger']->revoke($f['u']->id,$f['g']->id,'synthetic:curation',1,hash('sha256','late-revoke')),
                'annotation'=>DB::table('learning_case_annotations')->update(['annotation_text'=>'{}']),
                'mode'=>$ctx->setValue($f['s'],[]),
            };
        });
        try { expect(fn()=>t07bImport($f))->toThrow(DomainException::class); }
        finally { $c->setEventDispatcher($dispatcher); $ctx->setValue($f['s'],$original); }
        expect($hits)->toBe(1)->and(DB::table('learning_case_annotations')->count())->toBe(0)->and(t07CurationRows())->toBe($before)
            ->and($f['row']->fresh()->getRawOriginal())->toBe($raw)->and($c->getPdo())->toBe($pdo)->and($c->transactionLevel())->toBe($level);
    }
});

it('T07b fully persisted replay is exact no DML with CAS and command conflicts', function () {
    $f=t07bReady(); $first=t07bImport($f); $before=t07CurationRows();
    DB::enableQueryLog(); DB::flushQueryLog(); $out=t07bImport($f);
    $writes=array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])); DB::disableQueryLog();
    expect($out)->toBe($first)->and($writes)->toBe([])->and(t07CurationRows())->toBe($before);
    $cmd=$f['cmd']; $cmd['command_id']=hash('sha256','annotation-2');
    expect(fn()=>t07bImport($f,$cmd))->toThrow(DomainException::class,'learning_curation.stale_annotation');
    $cmd['expected_annotation_revision']=1; $cmd['topic_labels'][0]=['topic_id'=>'1','value'=>null,'observed_mask'=>0];
    expect(t07bImport($f,$cmd)['annotation_revision'])->toBe(2);
    $changed=$f['cmd']; $changed['topic_labels'][0]['value']=1;
    expect(fn()=>t07bImport($f,$changed))->toThrow(DomainException::class,'learning_curation.command_conflict');
    DB::table('learning_case_annotations')->where('annotation_revision',1)->update(['command_text'=>'{}']);
    expect(fn()=>t07bImport($f))->toThrow(DomainException::class,'learning_curation.annotation_corrupt');
});

it('T07b default and invalid context deny before PDO including serialized consumer', function () {
    $c=DB::connection(); $pdo=$c->getPdo(); $prop=new ReflectionProperty($c,'pdo'); $prop->setValue($c,fn()=>throw new LogicException('PDO forbidden'));
    try { expect(fn()=>(new App\Services\LearningCaseCuration)->importForAccount(1,1,'','{}'))->toThrow(DomainException::class,'learning_curation.disabled'); }
    finally { $prop->setValue($c,$pdo); }
    $f=t07bReady(); $prop->setValue($c,fn()=>throw new LogicException('PDO forbidden'));
    try {
        foreach(['namespace'=>'client-flag','synthetic_only'=>false,'promotion_allowed'=>true,'purpose'=>'client-name'] as $key=>$value) {
            $ctx=['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:curation']; $ctx[$key]=$value;
            $s=App\Services\LearningCaseCuration::forSyntheticTests($ctx,t07CurationExporter($f),$f['ledger']);
            expect(fn()=>$s->importForAccount(1,1,'','{}'))->toThrow(DomainException::class,'learning_curation.disabled');
        }
    } finally { $prop->setValue($c,$pdo); }
});

it('T07b current author and curator authority deny stale and rehashed commands without DML', function () {
    $f=t07bReady(); t07bImport($f); $c=DB::connection();
    $mutations=[
        fn()=>$f['ledger']->revoke($f['u']->id,$f['g']->id,'synthetic:curation',1,hash('sha256','revoke-curator')),
        fn()=>$f['ledger']->revoke($f['u']->id,$f['g']->id,'synthetic:learning',1,hash('sha256','revoke-author')),
        fn()=>$f['closure']->withdraw($f['u']->id,['expected_authorization_generation'=>1,'idempotency_key'=>'withdraw-curation']),
        fn()=>DB::table('learning_company_memberships')->where('id',$f['m']->id)->update(['revoked_at'=>'2026-10-03 12:00:00']),
        fn()=>DB::table('learning_cases')->update(['case_hash'=>str_repeat('0',64)]),
        fn()=>DB::table('learning_case_p5_snapshots')->update(['values_text'=>'{"stock_listed":0}']),
        fn()=>DB::table('characterizations')->where('id',$f['row']->id)->update(['status'=>'draft']),
        function(){ $r=DB::table('learning_case_closure_receipts')->sole(); $receipt=json_decode($r->receipt_text,true); unset($receipt['receipt_hash']);$receipt['authorization_generation']++;$receipt['receipt_hash']=hash('sha256',json_encode($receipt,JSON_UNESCAPED_SLASHES));DB::table('learning_case_closure_receipts')->update(['receipt_text'=>json_encode($receipt,JSON_UNESCAPED_SLASHES),'receipt_hash'=>$receipt['receipt_hash']]); },
    ];
    foreach($mutations as $mutate) {
        $c->beginTransaction(); try { $mutate(); $before=t07CurationRows(); $rows=DB::table('learning_case_annotations')->get()->all();
            expect(fn()=>t07bImport($f))->toThrow(DomainException::class);expect(t07CurationRows())->toBe($before)->and(DB::table('learning_case_annotations')->get()->all())->toEqual($rows);
        } finally { $c->rollBack(); }
    }
    foreach(['case_hash','source_token'] as $key) { $cmd=$f['cmd'];$cmd['reference'][$key]=str_repeat('0',64);expect(fn()=>t07bImport($f,$cmd))->toThrow(DomainException::class); }
    $cmd=$f['cmd'];$cmd['reference']['expected_revisions']['p9']['revision']++;expect(fn()=>t07bImport($f,$cmd))->toThrow(DomainException::class);
    $cmd=$f['cmd'];$cmd['export_digest']=str_repeat('0',64);expect(fn()=>t07bImport($f,$cmd))->toThrow(DomainException::class);
});

it('T07b foreign curator is denied before any case read and verified membership alone grants nothing', function () {
    $f=t07bReady(); $curator=User::factory()->create(['name'=>'SYNTHETIC_CURATOR']);
    DB::enableQueryLog(); DB::flushQueryLog();
    expect(fn()=>t07bImport($f,null,$curator->id))->toThrow(DomainException::class);
    $reads=array_filter(DB::getQueryLog(),fn($q)=>str_contains($q['query'],'learning_cases'));DB::disableQueryLog();expect($reads)->toBe([]);
    LearningCompanyMembership::query()->forceCreate(['learning_company_group_id'=>$f['g']->id,'user_id'=>$curator->id,'subject_type'=>'account','subject_identifier'=>(string)$curator->id,'verification_status'=>'verified','verified_at'=>now(),'evidence_type'=>'synthetic-only','evidence_digest'=>hash('sha256','curator-member')]);
    expect(fn()=>t07bImport($f,null,$curator->id))->toThrow(DomainException::class,'learning_curation.rights_denied');
    $f['ledger']->grant($curator->id,$f['g']->id,'synthetic:curation',0,hash('sha256','curator-grant'));
    expect(t07bImport($f,null,$curator->id)['annotation_revision'])->toBe(1);
});

it('T07b raw duplicate root list scalar labels and injected rights reject before persistence', function () {
    $f=t07bReady();$valid=json_encode($f['cmd'],JSON_THROW_ON_ERROR);
    $raws=['[]','null',str_replace('"schema_version":','"schema_version":"x","schema_version":',$valid),str_replace('"command_id":','"command_id":"x","command\\u005fid":',$valid)];
    foreach(['grant'=>true,'note'=>'SYNTHETIC_FREE_TEXT','actor_id'=>1] as $key=>$value) {$cmd=$f['cmd'];$cmd[$key]=$value;$raws[]=json_encode($cmd);}
    foreach([false,1.0,'1',null] as $v) {$cmd=$f['cmd'];$cmd['topic_labels'][0]['value']=$v;$raws[]=json_encode($cmd,JSON_PRESERVE_ZERO_FRACTION);}
    $cmd=$f['cmd'];$cmd['topic_labels'][0]['observed_mask']=0;$raws[]=json_encode($cmd);
    $cmd=$f['cmd'];$cmd['topic_labels'][]=$cmd['topic_labels'][0];$raws[]=json_encode($cmd);
    $cmd=$f['cmd'];$cmd['reference']['expected_revisions']=[];$raws[]=json_encode($cmd);
    foreach($raws as $raw) {expect(fn()=>$f['s']->importForAccount($f['u']->id,$f['u']->id,$f['packet']['jsonl'],$raw))->toThrow(InvalidArgumentException::class);}
    expect(DB::table('learning_case_annotations')->count())->toBe(0);
});

it('T07b annotation model builder is immutable and migration up down is supported', function () {
    $f=t07bReady();t07bImport($f);$row=App\Models\LearningCaseAnnotation::query()->sole();
    foreach([fn()=>$row->forceFill(['annotation_text'=>'{}'])->saveQuietly(),fn()=>$row->increment('annotation_revision'),fn()=>App\Models\LearningCaseAnnotation::query()->update(['annotation_text'=>'{}']),fn()=>App\Models\LearningCaseAnnotation::query()->increment('annotation_revision'),fn()=>App\Models\LearningCaseAnnotation::query()->upsert([['id'=>$row->id]],['id'],['annotation_text'])] as $write) {expect($write)->toThrow(LogicException::class);}
    $migration=require base_path('database/migrations/2026_10_02_000005_create_learning_case_annotations.php');$migration->down();expect(Schema::hasTable('learning_case_annotations'))->toBeFalse();$migration->up();expect(Schema::hasTable('learning_case_annotations'))->toBeTrue();
});

it('T07b real Laravel export Python command and import retain author bytes with direct guided parity', function () {
    $f=t07CurationFixture(); $c=DB::connection(); $labels=[];
    foreach(['direct','guided'] as $mode) {
        $c->beginTransaction();
        try {
            (new CharacterizationStateTransaction)->runForUser($f['u']->id,function($r)use($mode){
                $form=$r->form_data; $confirmation=&$form['materiality_confirmation']; $confirmation['universe_attestation']['mode']=$mode;
                if($mode==='guided') {
                    foreach([1,2] as $topic) { $confirmation['guided_answers'][$topic]=['impacto'=>'alto','financiero'=>'alto','confianza'=>'alta','exposicion'=>'alta','suggested_result'=>'material','final_result'=>$topic===1?'material':'no_material','revisar'=>false,'note'=>'SYNTHETIC_FREE_NOTE']; }
                }
                $r->form_data=$form;$r->save();
            });
            if ($mode==='guided') {
                // Invalid stored exposure remains denied; this is a fixture correction, not product TDD.
                expect(fn()=>$f['closure']->close($f['u']->id,t07CurationInput($f)))
                    ->toThrow(DomainException::class,'learning_closure.review_missing');
                (new CharacterizationStateTransaction)->runForUser($f['u']->id,function($r){
                    $form=$r->form_data;
                    foreach([1,2] as $topic) { $form['materiality_confirmation']['guided_answers'][$topic]['exposicion']='fuerte'; }
                    $r->form_data=$form;$r->save();
                });
            }
            $f['closure']->close($f['u']->id,t07CurationInput($f)); $e=t07CurationExporter($f);
            $f['ledger']->grant($f['u']->id,$f['g']->id,'synthetic:curation',0,hash('sha256','curation-grant'));
            $packet=$e->exportForAccount($f['u']->id,$e->referenceForAccount($f['u']->id));
            $native=new Symfony\Component\Process\Process([base_path('../ai-service/.venv-learning/Scripts/python.exe'),'-B','-c',
                'import json,sys; from learning_case_curation_adapter import annotation_command; v=json.load(sys.stdin); print(json.dumps(annotation_command(v["export"],v["input"]),separators=(",",":")))'],base_path('../ai-service/src'));
            $input=json_encode(['schema_version'=>'learning-case-curation-input-v1','expected_annotation_revision'=>0,'command_id'=>hash('sha256','annotation-roundtrip'),'topic_labels'=>[['topic_id'=>'1','value'=>0,'observed_mask'=>1]],'note'=>'SYNTHETIC_FREE_NOTE']);
            $native->setInput(json_encode(['export'=>$packet['jsonl'],'input'=>$input]));$native->setTimeout(10);$native->run();
            expect($native->getExitCode())->toBe(0)->and($native->getErrorOutput())->toBe('');
            $command=$native->getOutput();$before=t07CurationRows();
            $service=App\Services\LearningCaseCuration::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:curation'],$e,$f['ledger']);
            $out=$service->importForAccount($f['u']->id,$f['u']->id,$packet['jsonl'],$command);
            expect($out['topic_labels'])->toBe([['observed_mask'=>1,'topic_id'=>'1','value'=>0]])->and($out['notes']['status'])->toBe('withheld')->and(t07CurationRows())->toBe($before);
            $labels[$mode]=json_decode($packet['jsonl'],true)['topic_labels'];
            file_put_contents(base_path('artifacts/learning-tests').'/04-t07b-export-smoke.jsonl',$packet['jsonl']);
            file_put_contents(base_path('artifacts/learning-tests').'/04-t07b-import-smoke.json',$command);
        } finally { $c->rollBack(); }
    }
    expect($labels['guided'])->toBe($labels['direct']);
});
function t07CurationFixture(): array {
    $u = User::factory()->create(['name' => 'SYNTHETIC_ACCOUNT']);
    foreach ([1,2,3] as $id) { EsrsTopic::query()->forceCreate(['hash'=>hash('sha256','synthetic-topic-'.$id),'id' => $id,'esrs_code'=>'SYNTHETIC','theme_es'=>'SYNTHETIC','theme_en'=>'SYNTHETIC','subtheme_es'=>'SYNTHETIC','subtheme_en'=>'SYNTHETIC','subtopic_es'=>'SYNTHETIC','subtopic_en'=>'SYNTHETIC']); }
    $g = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256','SYNTHETIC_GROUP')]);
    $m = LearningCompanyMembership::query()->forceCreate(['learning_company_group_id'=>$g->id,'user_id'=>$u->id,'subject_type'=>'account','subject_identifier'=>(string)$u->id,'verification_status'=>'verified','verified_at'=>now(),'evidence_type'=>'synthetic-only','evidence_digest'=>hash('sha256','synthetic-member')]);
    $issuer = new class implements LearningAuthorizationAuthority {
        public bool $denied = false;
        public ?Closure $onResolve = null;
        public function references(string $purpose): ?array { return $this->denied ? null : ['issuer_ref'=>'synthetic:issuer','issuer_version'=>'v1','issuer_digest'=>hash('sha256','issuer'),'capability_ref'=>'synthetic:cap','capability_version'=>'v1','capability_digest'=>hash('sha256','cap'),'policy_version'=>'synthetic-policy-v1','policy_digest'=>hash('sha256','synthetic-policy')]; }
        public function resolve(User $actor, LearningCompanyMembership $member, string $purpose): ?array { $callback=$this->onResolve; $this->onResolve=null; $callback?->__invoke(); $r=$this->references($purpose); return $r===null ? null : $r+['actor_id'=>$actor->id,'group_id'=>$member->learning_company_group_id,'membership_id'=>$member->id,'purpose'=>$purpose,'state'=>'available','provenance'=>'synthetic-only','promotion_allowed'=>false]; }
    };
    $tx = new CharacterizationStateTransaction;
    $ledger = new LearningAuthorizationLedger(new LearningAuthorization($issuer),$tx);
    $ledger->grant($u->id,$g->id,'synthetic:learning',0,hash('sha256','grant'));
    $row = $tx->runForUser($u->id, fn()=>Characterization::create(['user_id'=>$u->id,'status'=>'completed','submission_generation'=>1,'esrs_topic_ids'=>[1,2],
        'form_data'=>['company_profile'=>['headquarters_country'=>'Spain','stock_listed'=>false],'operations'=>['employee_count_range'=>'50_249','employee_count'=>150],
            'materiality_confirmation'=>['revision'=>1,'confirmed_topic_ids'=>[1],'reviewed_topic_ids'=>[1,2],'universe_attestation'=>['version'=>1,'reviewed_universe'=>true,'mode'=>'direct'],'p6_snapshot'=>['topic_ids'=>[1,2],'captured_at'=>'2026-10-03T12:00:00Z'],'confirmed_at'=>'2026-10-03T12:00:00Z']],
        'result_data'=>['status'=>'completed','model_profile'=>'t06_close_synthetic','mapping_metadata'=>['python'=>['serving_identity'=>['profile'=>'t06_close_synthetic','artifact_sha256'=>['synthetic.pkl'=>str_repeat('a',64)],'policy_sha256'=>[],'runtime_config'=>['score_threshold'=>0.95,'policy_active'=>['label_thresholds'=>false,'crc_recall_floor'=>false,'sector_guard'=>false]],'serving_identity_sha256'=>str_repeat('b',64)]]]]]));
    $builder = new T07CurationCorpus(new Ar16MatterDrMappingRepository);
    $feedback = ['schema_version'=>'datapoint-feedback-v1','authority_digest'=>(new EsrsDatapointResponseState(new Ar16MatterDrMappingRepository))->learningAuthorityDigest($builder->build($row->fresh())), 'reviewed_datapoint_ids'=>['SYNTHETIC_A'],'decisions'=>[['datapoint_id'=>'SYNTHETIC_A','relevant'=>false,'selected_to_answer'=>false,'reason_codes'=>['scope'],'note'=>null]]];
    $tx->runForUser($u->id, function($fresh)use($feedback){$f=$fresh->form_data; $f['esrs_datapoint_responses']=['learning_feedback'=>$feedback]; $fresh->form_data=$f; $fresh->save();});
    $authority = ['framework_version'=>'synthetic-esrs','catalog_version'=>'synthetic-catalog','catalog_digest'=>hash('sha256','synthetic-catalog'),'mapping_version'=>'synthetic-mapping','mapping_digest'=>hash('sha256','synthetic-mapping'),'topic_ids'=>['1','2','3'],'datapoint_ids'=>['SYNTHETIC_A','SYNTHETIC_B'],'ambiguous_topic_ids'=>[],'ambiguous_datapoint_ids'=>[]];
    $storage=new LearningCaseP5Storage($tx,app(LearningCaseSnapshot::class),$ledger,new LearningP5Snapshot);
    $closure=LearningCaseClosure::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:learning','period_scope'=>['period_key'=>'synthetic-period','perimeter_key'=>'synthetic-only'],'authority'=>$authority],$ledger,$storage,new ApiCharacterizationGateway(CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}')),$builder);
    app()->instance(LearningCaseClosure::class,$closure);
    return compact('u','g','m','issuer','ledger','row','builder','closure');
}
function t07CurationInput(array $f): array { $d=$f['closure']->draft($f['u']->id); return ['expected_revisions'=>$d['expected_revisions'],'expected_authorization_generation'=>$d['expected_authorization_generation'],'source_token'=>$d['source_token'],'idempotency_key'=>'synthetic-command-1','reviewed_universe'=>true,'final_for_period_scope'=>true,'declaration_version'=>'local-synthetic-closure-v1']; }
function t07CurationRows(): array { $out=[]; foreach (['learning_cases','learning_case_states','learning_case_p5_snapshots','learning_case_p5_storage_receipts','learning_case_closure_receipts','learning_authorization_records'] as $t) { $out[$t]=DB::table($t)->get()->map(fn($r)=>(array)$r)->all(); } return $out; }
function t07CurationExporter(array $f): App\Services\LearningCaseExport {
    return App\Services\LearningCaseExport::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$f['closure']);
}
function t07CurationClosed(): array {
    $f=t07CurationFixture(); $f['closure']->close($f['u']->id,t07CurationInput($f)); return $f;
}
it('T07b imports into a separate immutable annotation without changing author labels', function () {
    $f=t07CurationClosed(); $e=t07CurationExporter($f); $packet=$e->exportForAccount($f['u']->id,$e->referenceForAccount($f['u']->id));
    $f['ledger']->grant($f['u']->id,$f['g']->id,'synthetic:curation',0,hash('sha256','curation-grant'));
    $service=App\Services\LearningCaseCuration::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:curation'],$e,$f['ledger']);
    $command=['schema_version'=>'learning-case-annotation-command-v1','export_digest'=>$packet['digest'],'reference'=>$packet['proofs']['reference'],'expected_annotation_revision'=>0,'command_id'=>hash('sha256','annotation-1'),'topic_labels'=>[['topic_id'=>'1','value'=>0,'observed_mask'=>1]],'notes'=>['status'=>'withheld','reason'=>'free_text_transport_disabled']];
    $before=t07CurationRows();
    file_put_contents(base_path('artifacts/learning-tests').'/04-t07b-export-smoke.jsonl',$packet['jsonl']);
    $out=$service->importForAccount($f['u']->id,$f['u']->id,$packet['jsonl'],json_encode($command,JSON_THROW_ON_ERROR));
    expect($out['annotation_revision'])->toBe(1)->and($out['synthetic_only'])->toBeTrue()->and($out['promotion_allowed'])->toBeFalse()
        ->and(DB::table('learning_case_annotations')->count())->toBe(1)->and(t07CurationRows())->toBe($before);
});
