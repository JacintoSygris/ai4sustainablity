<?php

use App\Models\{Characterization, User, EsrsTopic, LearningCase, LearningCaseP5Snapshot, LearningCompanyGroup, LearningCompanyMembership};
use App\Services\{LearningCaseClosure, LearningAuthorizationAuthority, LearningAuthorization, LearningAuthorizationLedger, LearningCaseP5Storage, LearningCaseSnapshot, LearningP5Snapshot, CharacterizationStateTransaction, ApiCharacterizationGateway, CharacterizationPredictionMapper, EsrsDatapointCorpusBuilder, Ar16MatterDrMappingRepository, EsrsDatapointResponseState};
use Illuminate\Support\Facades\{DB, Schema, Http};
use Illuminate\Database\Schema\Blueprint;

// Test namespace only. human_product exercises the closed contract inside this isolated runner.
class T07ExportCorpus extends EsrsDatapointCorpusBuilder
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
function t07ExportFixture(?int $batchIndex = null, bool $batchPositive = true): array {
    $u = User::factory()->create(['name' => 'SYNTHETIC_ACCOUNT']);
    foreach ([1,2,3] as $id) { if (!EsrsTopic::query()->whereKey($id)->exists()) { EsrsTopic::query()->forceCreate(['hash'=>hash('sha256','synthetic-topic-'.$id),'id' => $id,'esrs_code'=>'SYNTHETIC','theme_es'=>'SYNTHETIC','theme_en'=>'SYNTHETIC','subtheme_es'=>'SYNTHETIC','subtheme_en'=>'SYNTHETIC','subtopic_es'=>'SYNTHETIC','subtopic_en'=>'SYNTHETIC']); } }
    $g = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256',$batchIndex===null?'SYNTHETIC_GROUP':'SYNTHETIC_GROUP:'.$batchIndex)]);
    $m = LearningCompanyMembership::query()->forceCreate(['learning_company_group_id'=>$g->id,'user_id'=>$u->id,'subject_type'=>'account','subject_identifier'=>(string)$u->id,'verification_status'=>'verified','verified_at'=>now(),'evidence_type'=>'synthetic-only','evidence_digest'=>hash('sha256','synthetic-member')]);
    $issuer = new class implements LearningAuthorizationAuthority {
        public bool $denied = false;
        public ?Closure $onResolve = null;
        public function references(string $purpose): ?array { return $this->denied ? null : ['issuer_ref'=>'synthetic:issuer','issuer_version'=>'v1','issuer_digest'=>hash('sha256','issuer'),'capability_ref'=>'synthetic:cap','capability_version'=>'v1','capability_digest'=>hash('sha256','cap'),'policy_version'=>'synthetic-policy-v1','policy_digest'=>hash('sha256','synthetic-policy')]; }
        public function resolve(User $actor, LearningCompanyMembership $member, string $purpose): ?array { $callback=$this->onResolve; $this->onResolve=null; $callback?->__invoke(); $r=$this->references($purpose); return $r===null ? null : $r+['actor_id'=>$actor->id,'group_id'=>$member->learning_company_group_id,'membership_id'=>$member->id,'purpose'=>$purpose,'state'=>'available','provenance'=>'synthetic-only','promotion_allowed'=>false]; }
    };
    $tx = new CharacterizationStateTransaction;
    $ledger = new LearningAuthorizationLedger(new LearningAuthorization($issuer),$tx);
    $ledger->grant($u->id,$g->id,'synthetic:learning',0,hash('sha256',$batchIndex===null?'grant':'grant:'.$batchIndex));
    $row = $tx->runForUser($u->id, fn()=>Characterization::create(['user_id'=>$u->id,'status'=>'completed','submission_generation'=>1,'esrs_topic_ids'=>[1,2],
        'form_data'=>['company_profile'=>['headquarters_country'=>'Spain','stock_listed'=>$batchIndex!==null && $batchPositive],'operations'=>['employee_count_range'=>$batchPositive?'50_249':'1_9','employee_count'=>$batchPositive?150:5],
            'materiality_confirmation'=>['revision'=>1,'confirmed_topic_ids'=>$batchPositive?[1]:[2],'reviewed_topic_ids'=>[1,2],'universe_attestation'=>['version'=>1,'reviewed_universe'=>true,'mode'=>'direct'],'p6_snapshot'=>['topic_ids'=>[1,2],'captured_at'=>now()->utc()->format('Y-m-d\TH:i:s\Z')],'confirmed_at'=>now()->utc()->format('Y-m-d\TH:i:s\Z')]],
        'result_data'=>['status'=>'completed','model_profile'=>'t06_close_synthetic','mapping_metadata'=>['python'=>['serving_identity'=>['profile'=>'t06_close_synthetic','artifact_sha256'=>['synthetic.pkl'=>str_repeat('a',64)],'policy_sha256'=>[],'runtime_config'=>['score_threshold'=>0.95,'policy_active'=>['label_thresholds'=>false,'crc_recall_floor'=>false,'sector_guard'=>false]],'serving_identity_sha256'=>str_repeat('b',64)]]]]]));
    $builder = new T07ExportCorpus(new Ar16MatterDrMappingRepository);
    $feedback = ['schema_version'=>'datapoint-feedback-v1','authority_digest'=>(new EsrsDatapointResponseState(new Ar16MatterDrMappingRepository))->learningAuthorityDigest($builder->build($row->fresh())), 'reviewed_datapoint_ids'=>['SYNTHETIC_A'],'decisions'=>[['datapoint_id'=>'SYNTHETIC_A','relevant'=>false,'selected_to_answer'=>false,'reason_codes'=>['scope'],'note'=>null]]];
    $tx->runForUser($u->id, function($fresh)use($feedback){$f=$fresh->form_data; $f['esrs_datapoint_responses']=['learning_feedback'=>$feedback]; $fresh->form_data=$f; $fresh->save();});
    $authority = ['framework_version'=>'synthetic-esrs','catalog_version'=>'synthetic-catalog','catalog_digest'=>hash('sha256','synthetic-catalog'),'mapping_version'=>'synthetic-mapping','mapping_digest'=>hash('sha256','synthetic-mapping'),'topic_ids'=>['1','2','3'],'datapoint_ids'=>['SYNTHETIC_A','SYNTHETIC_B'],'ambiguous_topic_ids'=>[],'ambiguous_datapoint_ids'=>[]];
    $storage=new LearningCaseP5Storage($tx,app(LearningCaseSnapshot::class),$ledger,new LearningP5Snapshot);
    $closure=LearningCaseClosure::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:learning','period_scope'=>['period_key'=>'synthetic-period','perimeter_key'=>'synthetic-only'],'authority'=>$authority],$ledger,$storage,new ApiCharacterizationGateway(CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}')),$builder);
    app()->instance(LearningCaseClosure::class,$closure);
    return compact('u','g','m','issuer','ledger','row','builder','closure');
}
function t07ExportInput(array $f): array { $d=$f['closure']->draft($f['u']->id); return ['expected_revisions'=>$d['expected_revisions'],'expected_authorization_generation'=>$d['expected_authorization_generation'],'source_token'=>$d['source_token'],'idempotency_key'=>'synthetic-command-1','reviewed_universe'=>true,'final_for_period_scope'=>true,'declaration_version'=>'local-synthetic-closure-v1']; }
function t07ExportRows(): array { $out=[]; foreach (['learning_cases','learning_case_states','learning_case_p5_snapshots','learning_case_p5_storage_receipts','learning_case_closure_receipts','learning_authorization_records'] as $t) { $out[$t]=DB::table($t)->get()->map(fn($r)=>(array)$r)->all(); } return $out; }
function t07Exporter(array $f): App\Services\LearningCaseExport {
    return App\Services\LearningCaseExport::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$f['closure']);
}
function t07Closed(): array {
    $f=t07ExportFixture(); $f['closure']->close($f['u']->id,t07ExportInput($f)); return $f;
}
it('T07 exports an eligible closed case from persisted P5 with projected proofs', function () {
    $f=t07Closed(); $e=t07Exporter($f); $ref=$e->referenceForAccount($f['u']->id);
    $before=t07ExportRows(); $packet=$e->exportForAccount($f['u']->id,$ref);
    $line=json_decode($packet['jsonl'],true,512,JSON_THROW_ON_ERROR);
    expect($line['schema_version'])->toBe('learning-case-export-v1')->and($line['X']['values'])->toBe(json_decode(LearningCaseP5Snapshot::query()->sole()->values_text,true))
        ->and($line['X']['values']['stock_listed'])->toBeFalse()->and($line['receipt'])->not->toHaveKey('actor_id')
        ->and($line['synthetic_only'])->toBeTrue()->and($line['promotion_allowed'])->toBeFalse()
        ->and($packet['digest'])->toBe(hash('sha256',$packet['jsonl']))
        ->and($packet['proofs']['receipt_projection_digest'])->toBe(hash('sha256',json_encode($line['receipt'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)))
        ->and(t07ExportRows())->toBe($before);
});
it('T07 denies stored and legacy closed state without a completion receipt', function () {
    $f=t07Closed(); $e=t07Exporter($f); $ref=$e->referenceForAccount($f['u']->id);
    DB::table('learning_case_closure_receipts')->delete();
    foreach(['stored','closed'] as $status) {
        DB::table('learning_case_states')->update(['status'=>$status]); $before=t07ExportRows();
        expect(fn()=>$e->exportForAccount($f['u']->id,$ref))->toThrow(DomainException::class);
        expect(t07ExportRows())->toBe($before);
    }
});
it('T07 rejects a coherently rehashed receipt with false authorization fields', function () {
    $f=t07Closed(); $e=t07Exporter($f); $ref=$e->referenceForAccount($f['u']->id);
    $r=DB::table('learning_case_closure_receipts')->sole(); $receipt=json_decode($r->receipt_text,true);
    unset($receipt['receipt_hash']); $receipt['authorization_generation']++;
    $receipt['receipt_hash']=hash('sha256',json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
    DB::table('learning_case_closure_receipts')->where('id',$r->id)->update(['receipt_text'=>json_encode($receipt,JSON_UNESCAPED_SLASHES),'receipt_hash'=>$receipt['receipt_hash']]);
    expect($f['closure']->draft($f['u']->id)['status'])->toBe('closed');
    expect(fn()=>$e->exportForAccount($f['u']->id,$ref))->toThrow(DomainException::class);
});
it('T07 corrupt P5 receipt source membership and unavailable issuer deny without consumer writes', function () {
    $f=t07Closed(); $e=t07Exporter($f); $ref=$e->referenceForAccount($f['u']->id); $c=DB::connection();
    $mutations=[
        fn()=>DB::table('learning_case_p5_snapshots')->update(['values_text'=>'{"stock_listed":0}']),
        fn()=>DB::table('learning_case_closure_receipts')->update(['receipt_hash'=>str_repeat('0',64)]),
        fn()=>DB::table('characterizations')->where('id',$f['row']->id)->update(['status'=>'draft']),
        fn()=>DB::table('learning_company_memberships')->where('id',$f['m']->id)->update(['revoked_at'=>'2026-10-03 12:00:00']),
        fn()=>$f['ledger']->revoke($f['u']->id,$f['g']->id,'synthetic:learning',1,hash('sha256','t07-revoke')),
        fn()=>$f['ledger']->tombstone($f['u']->id,$f['g']->id,'synthetic:learning',1,hash('sha256','t07-delete')),
    ];
    foreach($mutations as $mutate) {
        $c->beginTransaction(); try { $mutate(); $before=t07ExportRows(); expect(fn()=>$e->exportForAccount($f['u']->id,$ref))->toThrow(DomainException::class); expect(t07ExportRows())->toBe($before); } finally { $c->rollBack(); }
    }
    $f['issuer']->denied=true; expect(fn()=>$e->exportForAccount($f['u']->id,$ref))->toThrow(DomainException::class); $f['issuer']->denied=false;
    $foreign=User::factory()->create(); expect(fn()=>$e->exportForAccount($foreign->id,$ref))->toThrow(DomainException::class);
});
it('T07 JSONL roundtrips with strict privacy projection and three persisted features', function () {
    $f=t07ExportFixture();
    (new CharacterizationStateTransaction)->runForUser($f['u']->id,function($r){
        $form=$r->form_data; $form['company_profile']['name']='SENTINEL_NAME'; $form['company_profile']['email']='SENTINEL_EMAIL';
        $form['raw_characterization']='SENTINEL_RAW'; $form['esrs_datapoint_responses']['learning_feedback']['decisions'][0]['note']='SENTINEL_NOTE';
        $r->form_data=$form; $r->save();
    });
    $f['closure']->close($f['u']->id,t07ExportInput($f)); $e=t07Exporter($f); $packet=$e->exportForAccount($f['u']->id,$e->referenceForAccount($f['u']->id));
    $text=$packet['jsonl']; $line=json_decode($text,true,512,JSON_THROW_ON_ERROR);
    expect(str_starts_with($text,"\xEF\xBB\xBF"))->toBeFalse()->and(substr($text,-1))->toBe("\n")->and(substr_count($text,"\n"))->toBe(1)
        ->and(json_decode(json_encode($line,JSON_THROW_ON_ERROR),true))->toBe($line)
        ->and(array_keys($line['X']['values']))->toBe(['employee_count_range','headquarters_country','stock_listed'])
        ->and($line['X']['values']['stock_listed'])->toBeFalse();
    foreach(['SENTINEL_NAME','SENTINEL_EMAIL','SENTINEL_RAW','SENTINEL_NOTE','actor_id','user_id','characterization_id','source_text','raw_characterization','"note"','"email"','"name"'] as $forbidden) { expect($text)->not->toContain($forbidden); }
    foreach($line['period_scope'] as $key) { expect($key)->toMatch('/\A[a-f0-9]{64}\z/'); }
});
it('T07 default consumer denies before PDO', function () {
    $c=DB::connection(); $pdo=$c->getPdo(); $prop=new ReflectionProperty($c,'pdo'); $prop->setValue($c,fn()=>throw new LogicException('PDO forbidden'));
    try { expect(fn()=>(new App\Services\LearningCaseExport)->exportForAccount(1,[]))->toThrow(DomainException::class,'learning_export.disabled'); }
    finally { $prop->setValue($c,$pdo); }
});
it('T07 serialized job reconstructs current eligibility and exact replay has no DML', function () {
    $f=t07Closed(); $e=t07Exporter($f); $ref=$e->referenceForAccount($f['u']->id); app()->instance(App\Services\LearningCaseExport::class,$e);
    $job=new App\Jobs\ExportLearningCaseJob($f['u']->id,$ref); $serialized=serialize($job);
    $bad=$ref; $bad['grant']='forbidden'; expect(fn()=>new App\Jobs\ExportLearningCaseJob($f['u']->id,$bad))->toThrow(InvalidArgumentException::class);
    $bad=$ref; $bad['expected_revisions']['p5']['grant']='forbidden'; expect(fn()=>new App\Jobs\ExportLearningCaseJob($f['u']->id,$bad))->toThrow(InvalidArgumentException::class);
    expect(array_keys((array)$job))->toBe(['actor','expectedReference']);
    foreach(['grant','issuer','values_text','stock_listed','ledger','snapshot'] as $forbidden) { expect($serialized)->not->toContain($forbidden); }
    $first=unserialize($serialized)->handle(); $before=t07ExportRows();
    DB::enableQueryLog(); DB::flushQueryLog(); $next=unserialize($serialized)->handle();
    $writes=array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])); DB::disableQueryLog();
    expect($next)->toBe($first)->and($writes)->toBe([])->and(t07ExportRows())->toBe($before)
        ->and($first['proofs']['closure_receipt_hash'])->toBe(DB::table('learning_case_closure_receipts')->sole()->receipt_hash)
        ->and($first['digest'])->toBe(hash('sha256',$first['jsonl']));
});
it('T07 serialized old command cannot revive revoked withdrawn or changed cases', function () {
    $f=t07Closed(); $e=t07Exporter($f); $ref=$e->referenceForAccount($f['u']->id); app()->instance(App\Services\LearningCaseExport::class,$e);
    $bytes=serialize(new App\Jobs\ExportLearningCaseJob($f['u']->id,$ref)); $c=DB::connection();
    $mutations=[
        fn()=>$f['ledger']->revoke($f['u']->id,$f['g']->id,'synthetic:learning',1,hash('sha256','job-revoke')),
        fn()=>$f['closure']->withdraw($f['u']->id,['expected_authorization_generation'=>1,'idempotency_key'=>'job-withdraw']),
        fn()=>DB::table('learning_cases')->update(['case_hash'=>str_repeat('0',64)]),
    ];
    foreach($mutations as $mutate) { $c->beginTransaction(); try { $mutate(); $before=t07ExportRows(); expect(fn()=>unserialize($bytes)->handle())->toThrow(DomainException::class); expect(t07ExportRows())->toBe($before); } finally { $c->rollBack(); } }
    $changed=$ref; $changed['expected_revisions']['p5']['epoch']=str_repeat('0',64);
    expect(fn()=>(new App\Jobs\ExportLearningCaseJob($f['u']->id,$changed))->handle())->toThrow(DomainException::class);
    app()->instance(App\Services\LearningCaseExport::class,new App\Services\LearningCaseExport);
    $pdo=$c->getPdo(); $prop=new ReflectionProperty($c,'pdo'); $prop->setValue($c,fn()=>throw new LogicException('PDO forbidden'));
    try { expect(fn()=>unserialize($bytes)->handle())->toThrow(DomainException::class,'learning_export.disabled'); } finally { $prop->setValue($c,$pdo); }
});
it('T07 finite postCommon guards reject receipt P5 membership and source mutations atomically', function () {
    $f=t07Closed(); $e=t07Exporter($f); $ref=$e->referenceForAccount($f['u']->id); $c=DB::connection();
    foreach(['receipt','p5','member','source'] as $variant) {
        $before=t07ExportRows(); $raw=$f['row']->fresh()->getRawOriginal(); $membership=$f['m']->fresh()->getRawOriginal();
        $pdo=$c->getPdo(); $level=$c->transactionLevel(); $dispatcher=$c->getEventDispatcher(); $c->setEventDispatcher(clone $dispatcher); $hits=0;
        $c->getEventDispatcher()->listen(Illuminate\Database\Events\TransactionCommitted::class,function()use($c,$level,$variant,$f,&$hits){
            if($hits!==0 || $c->transactionLevel()!==$level+1) { return; }
            $common=array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),fn($x)=>($x['class']??'')===CharacterizationStateTransaction::class && ($x['function']??'')==='runForUser');
            if($common===[]) { return; } $hits++;
            match($variant) {
                'receipt'=>DB::table('learning_case_closure_receipts')->update(['receipt_hash'=>str_repeat('0',64)]),
                'p5'=>DB::table('learning_case_p5_snapshots')->update(['transform_version'=>'corrupt']),
                'member'=>DB::table('learning_company_memberships')->where('id',$f['m']->id)->update(['revoked_at'=>'2026-10-03 12:00:00']),
                'source'=>DB::table('characterizations')->where('id',$f['row']->id)->update(['status'=>'draft']),
            };
        });
        try { expect(fn()=>$e->exportForAccount($f['u']->id,$ref))->toThrow(DomainException::class); } finally { $c->setEventDispatcher($dispatcher); }
        expect($hits)->toBe(1)->and(t07ExportRows())->toBe($before)->and($f['row']->fresh()->getRawOriginal())->toBe($raw)
            ->and($f['m']->fresh()->getRawOriginal())->toBe($membership)->and($c->transactionLevel())->toBe($level)->and($c->getPdo())->toBe($pdo);
    }
});
it('T07 supported fake issuer callbacks cannot change source or membership while authorizing delivery', function () {
    $f=t07Closed(); $e=t07Exporter($f); $ref=$e->referenceForAccount($f['u']->id); $raw=$f['row']->fresh()->getRawOriginal(); $member=$f['m']->fresh()->getRawOriginal();
    foreach(['source','member','rights'] as $variant) {
        $before=t07ExportRows();
        $f['issuer']->onResolve=function()use($f,$variant){
            if($variant==='source') { DB::table('characterizations')->where('id',$f['row']->id)->update(['status'=>'draft']); }
            elseif($variant==='member') { DB::table('learning_company_memberships')->where('id',$f['m']->id)->update(['revoked_at'=>'2026-10-03 12:00:00']); }
            else { $f['issuer']->denied=true; }
        };
        try { expect(fn()=>$e->exportForAccount($f['u']->id,$ref))->toThrow(DomainException::class); } finally { $f['issuer']->denied=false; $f['issuer']->onResolve=null; }
        expect(t07ExportRows())->toBe($before)->and($f['row']->fresh()->getRawOriginal())->toBe($raw)->and($f['m']->fresh()->getRawOriginal())->toBe($member);
    }
});

function t08Exporter(array $f): App\Services\LearningCaseExport {
    return App\Services\LearningCaseExport::forSyntheticEligibilityTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$f['closure'],$f['ledger']);
}
it('T08 issuer binds actual T07 bytes and composite P6 from current persistence', function () {
    $f=t07Closed(); $old=t07Exporter($f); $packet=$old->exportForAccount($f['u']->id,$old->referenceForAccount($f['u']->id));
    $e=t08Exporter($f); $bundle=$e->eligibilityBundleForAccounts([$f['u']->id]);
    $manifest=json_decode($bundle['manifest_json'],true,512,JSON_THROW_ON_ERROR);
    $bindings=json_decode($bundle['bindings_json'],true,512,JSON_THROW_ON_ERROR);
    $line=json_decode($packet['jsonl'],true,512,JSON_THROW_ON_ERROR);
    expect($bundle['jsonl'])->toBe($packet['jsonl'])->and($manifest['cases'][0]['source_revisions'])->toBe($line['source_revisions'])
        ->and($manifest['cases'][0]['source_revisions']['p6']['digest'])->not->toBe($line['reference']['expected_revisions']['p6_base']['digest'])
        ->and($bindings['cases'][0]['export_digest'])->toBe($packet['digest'])
        ->and($bindings['cases'][0]['source_kind'])->toBe('human_product')->and($manifest['eligible_case_ids'])->toBe([$line['reference']['case_id']]);
    (new App\Services\LearningCaseContract)->assertEligibilityManifest($bundle['manifest_json'],0,new DateTimeImmutable($manifest['issued_at']));
    $sample=base_path('artifacts/learning-tests').'/04-t08-issued.json'; if(!file_exists($sample)){file_put_contents($sample,json_encode($bundle,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));}
});it('T08 exclusions retain terminal tombstones and reject corrupt history', function () {
    $f=t07Closed(); $e=t08Exporter($f); $first=$e->eligibilityBundleForAccounts([$f['u']->id]); $a=json_decode($first['manifest_json'],true);
    $f['ledger']->revoke($f['u']->id,$f['g']->id,'synthetic:learning',1,hash('sha256','t08-revoke'));
    $second=$e->eligibilityBundleForAccounts([$f['u']->id]); $b=json_decode($second['manifest_json'],true);
    expect($b['eligible_case_ids'])->toBe([])->and($b['tombstones']['revoked'][0]['case_id'])->toBe($a['cases'][0]['case_id'])->and($b['generation'])->toBeGreaterThan($a['generation']);
    $replay=$f['ledger']->grant($f['u']->id,$f['g']->id,'synthetic:learning',0,hash('sha256','grant'));
    expect($replay['generation'])->toBe(2)->and($replay['receipt_generation'])->toBe(1);
    expect(json_decode($e->eligibilityBundleForAccounts([$f['u']->id])['manifest_json'],true)['eligible_case_ids'])->toBe([]);
    DB::table('learning_cases')->update(['payload_text'=>'{"case_id":"corrupt"}']);
    expect(fn()=>$e->eligibilityBundleForAccounts([$f['u']->id]))->toThrow(InvalidArgumentException::class);
});
it('T08 fresh source withdrawal deletion and raw input never resurrect a saved export', function () {
    $f=t07Closed(); $e=t08Exporter($f); $first=$e->eligibilityBundleForAccounts([$f['u']->id]); $c=DB::connection();
    $c->beginTransaction(); try {
        (new CharacterizationStateTransaction)->runForUser($f['u']->id,function($r){$form=$r->form_data;$form['operations']['employee_count_range']='250_499';$r->form_data=$form;$r->save();});
        expect(json_decode($e->eligibilityBundleForAccounts([$f['u']->id])['manifest_json'],true)['eligible_case_ids'])->toBe([]);
    } finally {$c->rollBack();}
    $f['closure']->withdraw($f['u']->id,['expected_authorization_generation'=>1,'idempotency_key'=>'t08-withdraw']);
    $last=$e->eligibilityBundleForAccounts([$f['u']->id]); expect(json_decode($last['manifest_json'],true)['tombstones']['deleted'])->toHaveCount(1);
    foreach([[true],['1'],[1.0],[],array_fill(0,33,1)] as $bad){expect(fn()=>$e->eligibilityBundleForAccounts($bad))->toThrow(DomainException::class);}
    $pdo=$c->getPdo();$prop=new ReflectionProperty($c,'pdo');$prop->setValue($c,fn()=>throw new LogicException('PDO forbidden'));
    try{expect(fn()=>(new App\Services\LearningCaseExport)->eligibilityBundleForAccounts([1]))->toThrow(DomainException::class,'learning_eligibility.disabled');}finally{$prop->setValue($c,$pdo);}
    $sample=base_path('artifacts/learning-tests').'/04-t08-withdrawal.json';if(!file_exists($sample)){file_put_contents($sample,json_encode(['build'=>$first,'publication'=>$last],JSON_THROW_ON_ERROR));}
});
it('T08 direct guided parity uses the real exposure enum and excludes superseded case', function () {
    $f=t07Closed();$e=t08Exporter($f);$first=$e->eligibilityBundleForAccounts([$f['u']->id]);$direct=json_decode($first['jsonl'],true);
    (new CharacterizationStateTransaction)->runForUser($f['u']->id,function($r){$form=$r->form_data;$confirmation=$form['materiality_confirmation'];$confirmation['revision']=2;$confirmation['universe_attestation']['mode']='guided';
        foreach([1=>'material',2=>'no_material'] as $id=>$result){$confirmation['guided_answers'][(string)$id]=['impacto'=>$id===1?'alto':'bajo','financiero'=>'bajo','confianza'=>'alta','exposicion'=>'normal','suggested_result'=>$result,'final_result'=>$result,'revisar'=>false];}
        $form['materiality_confirmation']=$confirmation;$r->form_data=$form;$r->save();});
    $input=t07ExportInput($f);$input['idempotency_key']='synthetic-guided-command';$f['closure']->close($f['u']->id,$input);$second=$e->eligibilityBundleForAccounts([$f['u']->id]);$guided=json_decode($second['jsonl'],true);
    expect($guided['topic_labels'])->toBe($direct['topic_labels'])->and($guided['X'])->toBe($direct['X'])
        ->and(json_decode($second['bindings_json'],true)['exclusions'][0]['case_id'])->toBe($direct['reference']['case_id']);
    $sample=base_path('artifacts/learning-tests').'/04-t08-parity.json';if(!file_exists($sample)){file_put_contents($sample,json_encode(['direct'=>$first,'guided'=>$second],JSON_THROW_ON_ERROR));}
});
it('T08 multiple accounts are bounded ordered and rechecked on the same PDO', function () {
    $f=t07Closed();$u=User::factory()->create(['name'=>'SYNTHETIC_SECOND']);
    LearningCompanyMembership::query()->forceCreate(['learning_company_group_id'=>$f['g']->id,'user_id'=>$u->id,'subject_type'=>'account','subject_identifier'=>(string)$u->id,'verification_status'=>'verified','verified_at'=>now(),'evidence_type'=>'synthetic-only','evidence_digest'=>hash('sha256','synthetic-second')]);
    $f['ledger']->grant($u->id,$f['g']->id,'synthetic:learning',0,hash('sha256','second-grant'));
    (new CharacterizationStateTransaction)->runForUser($u->id,fn()=>Characterization::create(['user_id'=>$u->id,'status'=>'completed','submission_generation'=>1,'esrs_topic_ids'=>[1,2],'form_data'=>$f['row']->fresh()->form_data,'result_data'=>$f['row']->fresh()->result_data]));
    $second=$f;$second['u']=$u;$f['closure']->close($u->id,t07ExportInput($second));$e=t08Exporter($f);$pdo=DB::connection()->getPdo();
    $b=$e->eligibilityBundleForAccounts([$u->id,$f['u']->id]);$m=json_decode($b['manifest_json'],true);
    expect($m['cases'])->toHaveCount(2)->and(DB::connection()->getPdo())->toBe($pdo)->and(substr_count($b['jsonl'],"\n"))->toBe(2);
});
it('T08 final bundle recheck rejects postCommon corruption with exact rollback', function () {
    $f=t07Closed();$e=t08Exporter($f);$c=DB::connection();$level=$c->transactionLevel();$before=t07ExportRows();$dispatcher=$c->getEventDispatcher();$c->setEventDispatcher(clone $dispatcher);$hits=0;
    $c->getEventDispatcher()->listen(Illuminate\Database\Events\TransactionCommitted::class,function()use($c,$level,&$hits){
        if($hits || $c->transactionLevel()!==$level+1){return;}$common=array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),fn($x)=>($x['class']??'')===CharacterizationStateTransaction::class && ($x['function']??'')==='runForUser');
        if($common){$hits++;DB::table('learning_case_p5_snapshots')->update(['transform_version'=>'corrupt']);}
    });
    try{expect(fn()=>$e->eligibilityBundleForAccounts([$f['u']->id]))->toThrow(DomainException::class);}finally{$c->setEventDispatcher($dispatcher);}
    expect($hits)->toBe(1)->and(t07ExportRows())->toBe($before);
});

it('T08 composition keeps existing owner scopes around live actor draft frames', function () {
    $f = t07Closed(); $e = t08Exporter($f); $before = t07ExportRows(); $frames = [];
    $dispatcher = Characterization::getEventDispatcher(); Characterization::setEventDispatcher(clone $dispatcher);
    Characterization::retrieved(function () use ($f, &$frames) {
        $stack = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
        if (! in_array('frame', $stack, true) || ! in_array('eligibilityProjection', $stack, true)
            || (! in_array('eligibilityPacketForAccount', $stack, true) && ! in_array('eligibilityCurrentForAccount', $stack, true))) { return; }
        $frames[] = [(new App\Services\LearningP6BaseSourceRevisionClock)->hasActiveScope($f['u']->id),
            (new App\Services\LearningP8SourceRevisionClock)->hasActiveScope($f['u']->id),
            (new App\Services\LearningP9SourceRevisionClock)->hasActiveScope($f['u']->id)];
    });
    try { $bundle = $e->eligibilityBundleForAccounts([$f['u']->id, 9999999]); }
    finally { Characterization::setEventDispatcher($dispatcher); }
    expect(json_decode($bundle['manifest_json'], true)['cases'])->toHaveCount(1);
    expect($frames)->not->toBeEmpty();
    foreach ($frames as $scopes) { expect($scopes)->toBe([true, true, true]); }
    expect(t07ExportRows())->toBe($before);
    foreach ([new App\Services\LearningP6BaseSourceRevisionClock, new App\Services\LearningP8SourceRevisionClock,
        new App\Services\LearningP9SourceRevisionClock] as $clock) { expect($clock->hasActiveScope($f['u']->id))->toBeFalse(); }
});

it('T08 composition fences physical mutation from the last retrieved and recovers', function (string $kind) {
    $f = t07Closed(); $e = t08Exporter($f); $actor = $f['u']->id; $c = DB::connection(); $pdo = $c->getPdo();
    $capture = function () use ($f): array {
        $out = t07ExportRows();
        foreach (['characterizations', 'learning_company_memberships', 'learning_p5_source_revisions',
            'learning_p6_base_source_revisions', 'learning_p8_source_revisions', 'learning_p9_source_revisions'] as $table) {
            $out[$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all();
        }
        return $out;
    };
    $dispatcher = Characterization::getEventDispatcher(); Characterization::setEventDispatcher(clone $dispatcher);
    $reads = 0;
    Characterization::retrieved(function () use (&$reads) { $reads++; });
    try { $expected = $e->eligibilityBundleForAccounts([$actor]); }
    finally { Characterization::setEventDispatcher($dispatcher); }
    $before = $capture(); $calls = 0; $physical = false; $level = $c->transactionLevel();
    Characterization::setEventDispatcher(clone $dispatcher);
    Characterization::retrieved(function () use ($f, $kind, $reads, &$calls, &$physical) {
        if (++$calls !== $reads) { return; }
        $physical = match ($kind) {
            'source' => DB::table('characterizations')->where('id', $f['row']->id)->update(['status' => 'failed']) === 1,
            'membership' => DB::table('learning_company_memberships')->where('id', $f['m']->id)->update(['verification_status' => 'revoked']) === 1,
            'header' => DB::table('learning_p6_base_source_revisions')->where('characterization_id', $f['row']->id)->update(['digest' => str_repeat('f', 64)]) === 1,
        };
    });
    try { expect(fn () => $e->eligibilityBundleForAccounts([$actor]))->toThrow(DomainException::class); }
    finally { Characterization::setEventDispatcher($dispatcher); }
    expect($calls)->toBe($reads)->and($physical)->toBeTrue()->and($capture())->toBe($before);
    expect($c->getPdo())->toBe($pdo)->and($c->transactionLevel())->toBe($level);
    foreach ([new App\Services\LearningP6BaseSourceRevisionClock, new App\Services\LearningP8SourceRevisionClock,
        new App\Services\LearningP9SourceRevisionClock] as $clock) { expect($clock->hasActiveScope($actor))->toBeFalse(); }
    $recovered = $e->eligibilityBundleForAccounts([$actor]);
    expect($recovered['jsonl'])->toBe($expected['jsonl']);
    expect(json_decode($recovered['manifest_json'], true)['cases'])->toBe(json_decode($expected['manifest_json'], true)['cases']);
})->with(['source', 'membership', 'header']);

it('T08 composition retains corrupt history denial after account deletion', function () {
    $f = t07Closed(); $e = t08Exporter($f); $e->eligibilityBundleForAccounts([$f['u']->id]);
    User::query()->whereKey($f['u']->id)->delete();
    expect(fn () => $e->eligibilityBundleForAccounts([$f['u']->id]))
        ->toThrow(DomainException::class, 'learning_eligibility.corrupt_history');
});

it('T08 composition rechecks producer authority after the outer Common owner', function (string $kind) {
    $f = t07ExportFixture();
    $doc = App\Models\CharacterizationDocument::create(['characterization_id'=>$f['row']->id,
        'original_filename'=>'SYNTHETIC_NO_FILE', 'stored_path'=>'SYNTHETIC_NO_FILE',
        'sha256'=>str_repeat('d',64), 'size_bytes'=>12, 'mime'=>'application/pdf',
        'status'=>'extracted', 'extraction_generation'=>1, 'extraction_json'=>['synthetic_only'=>true], 'merged_state_version'=>null]);
    $f['closure']->close($f['u']->id, t07ExportInput($f)); $e = t08Exporter($f);
    $before = t07ExportRows(); $docRaw = $doc->fresh()->getRawOriginal(); $catalog = EsrsTopic::query()->findOrFail(1)->getRawOriginal();
    $dispatcher = Characterization::getEventDispatcher(); Characterization::setEventDispatcher(clone $dispatcher);
    $draftSeen = false; $finalizers = 0; $physical = false;
    Characterization::retrieved(function () use ($kind, $doc, &$draftSeen, &$finalizers, &$physical) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS); $stack = array_column($trace, 'function');
        $inDraft = in_array('eligibilityPacketForAccount', $stack, true) || in_array('eligibilityCurrentForAccount', $stack, true);
        if ($inDraft && in_array('frame', $stack, true)) { $draftSeen = true; return; }
        $owner = array_filter($trace, fn ($f) => ($f['class'] ?? '') === App\Services\LearningP9SourceRevisionClock::class && ($f['function'] ?? '') === 'observe');
        if (! $draftSeen || $inDraft || $owner === [] || ! in_array('eligibilityProjection', $stack, true)) { return; }
        $draftSeen = false;
        // Mutate only after the second overall projection's composed callback has returned.
        if (++$finalizers !== 2) { return; }
        $physical = $kind === 'documents'
            ? DB::table('characterization_documents')->where('id', $doc->id)->update(['size_bytes'=>55]) === 1
            : DB::table('esrs_topics')->where('id', 1)->update(['theme_es'=>'SYNTHETIC_LATE']) === 1;
    });
    try { expect(fn () => $e->eligibilityBundleForAccounts([$f['u']->id]))->toThrow(DomainException::class); }
    finally { Characterization::setEventDispatcher($dispatcher); }
    expect($finalizers)->toBe(2)->and($physical)->toBeTrue()->and(t07ExportRows())->toBe($before);
    expect($doc->fresh()->getRawOriginal())->toBe($docRaw)->and(EsrsTopic::query()->findOrFail(1)->getRawOriginal())->toBe($catalog);
    expect(json_decode($e->eligibilityBundleForAccounts([$f['u']->id])['manifest_json'], true)['cases'])->toHaveCount(1);
})->with(['documents', 'catalog']);

it('T08 deadline expiring inside the first actor aborts before the remaining population with exact rollback', function () {
    $f = t07Closed(); $c = DB::connection(); $u = User::factory()->create(['name' => 'SYNTHETIC_DEADLINE_SECOND']);
    LearningCompanyMembership::query()->forceCreate(['learning_company_group_id'=>$f['g']->id,'user_id'=>$u->id,'subject_type'=>'account','subject_identifier'=>(string)$u->id,'verification_status'=>'verified','verified_at'=>now(),'evidence_type'=>'synthetic-only','evidence_digest'=>hash('sha256','synthetic-deadline-second')]);
    $f['ledger']->grant($u->id, $f['g']->id, 'synthetic:learning', 0, hash('sha256', 'deadline-second-grant'));
    (new CharacterizationStateTransaction)->runForUser($u->id, fn () => Characterization::create(['user_id'=>$u->id,'status'=>'completed','submission_generation'=>1,'esrs_topic_ids'=>[1,2],'form_data'=>$f['row']->fresh()->form_data,'result_data'=>$f['row']->fresh()->result_data]));
    $second = $f; $second['u'] = $u; $f['closure']->close($u->id, t07ExportInput($second));
    $first = $f['u']->id; $rest = $u->id; expect($first)->toBeLessThan($rest);
    $e = t08Exporter($f); $expected = $e->eligibilityBundleForAccounts([$first, $rest]);
    expect(json_decode($expected['manifest_json'], true)['cases'])->toHaveCount(2);
    $generation = new ReflectionProperty($e, 'eligibilityGeneration'); $history = new ReflectionProperty($e, 'eligibilityHistory');
    $cursor = [$generation->getValue($e), $history->getValue($e)];
    $capture = function (): array {
        $out = t07ExportRows();
        foreach (['users', 'characterizations', 'learning_company_memberships'] as $table) { $out[$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all(); }
        return $out;
    };
    $before = $capture(); $pdo = $c->getPdo(); $level = $c->transactionLevel();
    $deadline = INF; $passed = 0; $expiredAt = null; $late = []; $physical = false; $error = null;
    // Trusted monotonic budget: valid at entry, expires only during the first actor's real work.
    $budget = function () use (&$deadline, &$passed): void {
        if (hrtime(true) / 1e9 > $deadline) { throw new DomainException('learning_batch.timeout'); }
        $passed++;
    };
    $dispatcher = Characterization::getEventDispatcher(); Characterization::setEventDispatcher(clone $dispatcher);
    Characterization::retrieved(function (Characterization $m) use ($first, $rest, &$deadline, &$expiredAt, &$late, &$physical) {
        if ($expiredAt !== null) { if ((int) $m->user_id === $rest) { $late[] = 'source'; } return; }
        if ((int) $m->user_id !== $first || ! in_array('eligibilityProjection', array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function'), true)) { return; }
        $deadline = hrtime(true) / 1e9; do { $now = hrtime(true) / 1e9; } while ($now <= $deadline); $expiredAt = $now;
        $physical = DB::table('users')->where('id', $first)->update(['name' => 'SYNTHETIC_DEADLINE_WRITE']) === 1;
    });
    LearningCaseP5Snapshot::retrieved(function (LearningCaseP5Snapshot $m) use ($rest, &$expiredAt, &$late) {
        if ($expiredAt !== null && (int) $m->actor_id === $rest) { $late[] = 'p5'; }
    });
    try { $e->eligibilityBundleForAccounts([$first, $rest], $budget); }
    catch (DomainException $caught) { $error = $caught->getMessage(); }
    finally { Characterization::setEventDispatcher($dispatcher); }
    expect($error)->toBe('learning_batch.timeout')->and($expiredAt)->not->toBeNull()->and($physical)->toBeTrue()
        ->and($passed)->toBeGreaterThanOrEqual(1)->and($late)->toBe([]);
    expect($capture())->toBe($before)->and($c->getPdo())->toBe($pdo)->and($c->transactionLevel())->toBe($level)
        ->and([$generation->getValue($e), $history->getValue($e)])->toBe($cursor);
    foreach ([$first, $rest] as $actor) {
        foreach ([new App\Services\LearningP6BaseSourceRevisionClock, new App\Services\LearningP8SourceRevisionClock,
            new App\Services\LearningP9SourceRevisionClock] as $clock) { expect($clock->hasActiveScope($actor))->toBeFalse(); }
    }
    $recovered = $e->eligibilityBundleForAccounts([$first, $rest]); $manifest = json_decode($recovered['manifest_json'], true);
    expect($recovered['jsonl'])->toBe($expected['jsonl'])->and($manifest['cases'])->toBe(json_decode($expected['manifest_json'], true)['cases'])
        ->and($manifest['generation'])->toBe($cursor[0] + 1);
});

it('T08 followup terminal child observed live at a checkpoint stops the partial poll with exact rollback', function () {
    $f = t07Closed(); $c = DB::connection(); $u = User::factory()->create(['name' => 'SYNTHETIC_TERMINAL_SECOND']);
    LearningCompanyMembership::query()->forceCreate(['learning_company_group_id'=>$f['g']->id,'user_id'=>$u->id,'subject_type'=>'account','subject_identifier'=>(string)$u->id,'verification_status'=>'verified','verified_at'=>now(),'evidence_type'=>'synthetic-only','evidence_digest'=>hash('sha256','synthetic-terminal-second')]);
    $f['ledger']->grant($u->id, $f['g']->id, 'synthetic:learning', 0, hash('sha256', 'terminal-second-grant'));
    (new CharacterizationStateTransaction)->runForUser($u->id, fn () => Characterization::create(['user_id'=>$u->id,'status'=>'completed','submission_generation'=>1,'esrs_topic_ids'=>[1,2],'form_data'=>$f['row']->fresh()->form_data,'result_data'=>$f['row']->fresh()->result_data]));
    $second = $f; $second['u'] = $u; $f['closure']->close($u->id, t07ExportInput($second));
    $first = $f['u']->id; $rest = $u->id; expect($first)->toBeLessThan($rest);
    $e = t08Exporter($f); $expected = $e->eligibilityBundleForAccounts([$first, $rest]);
    $ack = json_decode($expected['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
    $helper = new ReflectionMethod(App\Console\Commands\RunLearningBatch::class, 'terminalCheckpoint');
    $dir = sys_get_temp_dir().'/t08-terminal-'.bin2hex(random_bytes(8)); expect(mkdir($dir, 0700))->toBeTrue();
    try {
        $receipt = fn (array $r): string => json_encode(['receipt'=>$r, 'state'=>['synthetic_only'=>true], 'consumable'=>true], JSON_THROW_ON_ERROR);
        $valid = "{\"ready\":true}\n".$receipt(['authority_generation'=>$ack['generation'], 'authority_digest'=>$ack['canonical_digest']])."\n";
        $write = function (string $stdout, string $stderr = '') use ($dir): void { file_put_contents($dir.'/stdout.log', $stdout); file_put_contents($dir.'/stderr.log', $stderr); };
        $build = function (float $deadline, Closure $status, ?array &$terminal) use ($helper, $dir, $ack): Closure {
            $args = [$deadline, $status, $dir, $ack]; $args[] = &$terminal;
            return $helper->invokeArgs(null, $args);
        };
        // Simulated proc_get_status rows only; no native child is launched.
        $running = ['running'=>true, 'exitcode'=>-1, 'pid'=>4242];
        $exited = fn (int $code): array => ['running'=>false, 'exitcode'=>$code, 'pid'=>4242];
        $far = hrtime(true) / 1e9 + 3600;
        $cases = [
            'running' => [$running, $far, $valid, '', null],
            'late' => [$exited(0), hrtime(true) / 1e9 - 1, $valid, '', 'learning_batch.timeout'],
            'exit' => [$exited(1), $far, $valid, '', 'learning_batch.child_failed'],
            'unknown_exit' => [$exited(-1), $far, $valid, '', 'learning_batch.child_failed'],
            'ack_generation' => [$exited(0), $far, "{\"ready\":true}\n".$receipt(['authority_generation'=>$ack['generation'] + 1, 'authority_digest'=>$ack['canonical_digest']])."\n", '', 'learning_batch.receipt_authority_mismatch'],
            'ack_digest' => [$exited(0), $far, "{\"ready\":true}\n".$receipt(['authority_generation'=>$ack['generation'], 'authority_digest'=>str_repeat('0', 64)])."\n", '', 'learning_batch.receipt_authority_mismatch'],
            'ready_only' => [$exited(0), $far, "{\"ready\":true}\n", '', 'learning_batch.output_invalid'],
            'extra_line' => [$exited(0), $far, $valid."{\"extra\":true}\n", '', 'learning_batch.output_invalid'],
            'bad_ready' => [$exited(0), $far, "{\"ready\":false}\n".$receipt(['authority_generation'=>$ack['generation'], 'authority_digest'=>$ack['canonical_digest']])."\n", '', 'learning_batch.output_invalid'],
            'stdout_limit' => [$exited(0), $far, $valid.str_repeat(' ', 1048576), '', 'learning_batch.output_limit'],
            'stderr_limit' => [$exited(0), $far, $valid, str_repeat('e', 65537), 'learning_batch.output_limit'],
        ];
        foreach ($cases as $label => [$status, $deadline, $stdout, $stderr, $code]) {
            $write($stdout, $stderr); $calls = 0; $terminal = null; $error = null;
            $checkpoint = $build($deadline, function () use (&$calls, $status): array { $calls++; return $status; }, $terminal);
            try { $checkpoint(); } catch (DomainException $caught) { $error = $caught->getMessage(); }
            expect([$label, $error, $terminal])->toBe([$label, $code, null]);
            // An expired budget is never extended by a terminal observation.
            if ($label === 'late') { expect($calls)->toBe(0); } else { expect($calls)->toBe(1); }
        }

        $write($valid);
        $generation = new ReflectionProperty($e, 'eligibilityGeneration'); $history = new ReflectionProperty($e, 'eligibilityHistory');
        $cursor = [$generation->getValue($e), $history->getValue($e)];
        $capture = function (): array {
            $out = t07ExportRows();
            foreach (['users', 'characterizations', 'learning_company_memberships'] as $table) { $out[$table] = DB::table($table)->get()->map(fn ($r) => (array) $r)->all(); }
            return $out;
        };
        $before = $capture(); $pdo = $c->getPdo(); $level = $c->transactionLevel();
        $exitedNow = false; $observed = 0; $polls = 0; $pollsAtExit = null; $late = []; $physical = false; $terminal = null; $error = null;
        // Real PHP reports the exit code once; later observations return -1.
        $status = function () use (&$exitedNow, &$observed, &$polls): array {
            $polls++;
            if (! $exitedNow) { return ['running'=>true, 'exitcode'=>-1, 'pid'=>4242]; }
            return ['running'=>false, 'exitcode'=>$observed++ === 0 ? 0 : -1, 'pid'=>4242];
        };
        $checkpoint = $build($far, $status, $terminal);
        $dispatcher = Characterization::getEventDispatcher(); Characterization::setEventDispatcher(clone $dispatcher);
        Characterization::retrieved(function (Characterization $m) use ($first, $rest, &$exitedNow, &$polls, &$pollsAtExit, &$late, &$physical) {
            if ($exitedNow) { if ((int) $m->user_id === $rest) { $late[] = 'source'; } return; }
            if ((int) $m->user_id !== $first || ! in_array('eligibilityProjection', array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function'), true)) { return; }
            // The child exits validly DURING the first actor's real work, before the deadline.
            $pollsAtExit = $polls; $exitedNow = true;
            $physical = DB::table('users')->where('id', $first)->update(['name' => 'SYNTHETIC_TERMINAL_WRITE']) === 1;
        });
        LearningCaseP5Snapshot::retrieved(function (LearningCaseP5Snapshot $m) use ($rest, &$exitedNow, &$late) {
            if ($exitedNow && (int) $m->actor_id === $rest) { $late[] = 'p5'; }
        });
        try { $e->eligibilityBundleForAccounts([$first, $rest], $checkpoint); }
        catch (DomainException $caught) { $error = $caught->getMessage(); }
        finally { Characterization::setEventDispatcher($dispatcher); }
        expect($error)->toBe('learning_batch.child_terminal')->and($terminal)->toBe(['exitcode'=>0, 'stdout'=>$valid])
            ->and($pollsAtExit)->toBeGreaterThanOrEqual(1)->and($observed)->toBe(1)->and($physical)->toBeTrue()->and($late)->toBe([]);
        // A later checkpoint keeps the first real exit code instead of trusting a -1 re-read.
        $again = null; try { $checkpoint(); } catch (DomainException $caught) { $again = $caught->getMessage(); }
        expect($again)->toBe('learning_batch.child_terminal')->and($observed)->toBe(1)->and($terminal['exitcode'])->toBe(0);
        expect($capture())->toBe($before)->and($c->getPdo())->toBe($pdo)->and($c->transactionLevel())->toBe($level)
            ->and([$generation->getValue($e), $history->getValue($e)])->toBe($cursor);
        foreach ([$first, $rest] as $actor) {
            foreach ([new App\Services\LearningP6BaseSourceRevisionClock, new App\Services\LearningP8SourceRevisionClock,
                new App\Services\LearningP9SourceRevisionClock] as $clock) { expect($clock->hasActiveScope($actor))->toBeFalse(); }
        }
        // Final authority is a full fresh bundle: identical when unchanged, and a later revoke is never cached as positive.
        $recovered = $e->eligibilityBundleForAccounts([$first, $rest]); $manifest = json_decode($recovered['manifest_json'], true);
        expect($recovered['jsonl'])->toBe($expected['jsonl'])->and($manifest['cases'])->toBe($ack['cases'])->and($manifest['generation'])->toBe($cursor[0] + 1);
        $f['ledger']->revoke($first, $f['g']->id, 'synthetic:learning', 1, hash('sha256', 't08-terminal-revoke'));
        $revoked = json_decode($e->eligibilityBundleForAccounts([$first, $rest])['manifest_json'], true);
        expect($revoked['eligible_case_ids'])->toHaveCount(1)->and($revoked['tombstones']['revoked'])->toHaveCount(1);
    } finally {
        foreach (['stdout.log', 'stderr.log'] as $name) { if (is_file($dir.'/'.$name)) { unlink($dir.'/'.$name); } }
        rmdir($dir);
    }
});

it('T09 denial persists a fenced negative P8 drift without rebuilding a positive bundle', function () {
    $f = t07Closed(); $old = t07ExportFixture(901);
    $old['closure']->close($old['u']->id, t07ExportInput($old));
    $old['ledger']->revoke($old['u']->id, $old['g']->id, 'synthetic:learning', 1, hash('sha256', 't09-denial-prior-revoke'));
    $e = t08Exporter($f); app()->instance(App\Services\LearningCaseExport::class, $e);
    config(['services.learning_batch.enabled'=>true, 'services.learning_batch.namespace'=>'test-namespace:t07-export',
        'services.learning_batch.synthetic_only'=>true, 'services.learning_batch.trusted_launcher'=>true,
        'services.learning_batch.actors'=>[$f['u']->id, $old['u']->id]]);
    $token = App\Models\LearningBatch::claim(); expect($token)->toBeArray();
    $command = new App\Console\Commands\RunLearningBatch;
    $bundleMethod = new ReflectionMethod($command, 'bundle');
    $baseline = $bundleMethod->invoke($command, $token);
    $manifest = json_decode($baseline['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
    $bindings = json_decode($baseline['bindings_json'], true, 512, JSON_THROW_ON_ERROR);
    $line = json_decode(trim($baseline['jsonl']), true, 512, JSON_THROW_ON_ERROR);
    $id = $line['reference']['case_id']; $expected = $line['reference']['expected_revisions'];
    $clock = new App\Services\LearningP8SourceRevisionClock;
    $current = $clock->current($f['row']->id);
    expect($manifest['eligible_case_ids'])->toBe([$id])
        ->and($current['characterization_id'])->toBe($f['row']->id)
        ->and(array_intersect_key($current, $expected['p8']))->toBe($expected['p8']);
    // expected_revisions is the actual closure header map, never the composite payload P6 digest.
    expect(array_keys($expected['p8']))->toBe(['generation', 'revision', 'epoch', 'digest']);
    $context = (new ReflectionMethod($command, 'authorityContext'))->invoke(null, $baseline);
    $digest = hash('sha256', $baseline['jsonl']); $priorDigest = hash('sha256', 't09-denial-prior-dataset');
    $tombstones = [];
    foreach ($manifest['tombstones'] as $kind=>$rows) { foreach ($rows as $tombstone) {
        $tombstones[$tombstone['case_id']] = ['case_hash'=>$tombstone['case_hash'], 'kind'=>$kind, 'at'=>$tombstone['at']];
    } }
    expect($tombstones)->toHaveCount(1)->and($bindings['exclusions'])->toHaveCount(1);
    $prior = ['generation'=>$manifest['generation'], 'manifest_digest'=>$manifest['canonical_digest'],
        'current'=>array_column($bindings['cases'], null, 'case_id'),
        'lineage'=>[$priorDigest=>$bindings['cases'], $digest=>$bindings['cases']],
        'invalidated'=>[$priorDigest], 'excluded'=>array_column($bindings['exclusions'], 'reason', 'case_id'),
        'tombstones'=>$tombstones];
    App\Models\LearningBatch::locked($token, function ($row) use ($token, $context, $baseline, $prior) {
        $cursor = $row->issuer_state;
        $cursor['dataset_witness'] = ['token'=>$token, 'context_digest'=>$context, 'bundle'=>$baseline, 'state'=>$prior];
        $row->issuer_state = $cursor; $row->dataset_state = $prior; $row->context_digest = $context; $row->save();
    });
    $before = App\Models\LearningBatch::query()->sole(); $cursor = $before->issuer_state;
    $rights = $f['ledger']->current($f['u']->id, $f['g']->id, 'synthetic:learning');
    // Same real post-heartbeat drift as the native regression: a valid revision mismatch, no fabricated terminal event.
    App\Models\LearningBatch::heartbeat($token);
    expect(DB::table('learning_p8_source_revisions')->where('characterization_id', $f['row']->id)->increment('revision'))->toBe(1);
    $current = $clock->current($f['row']->id);
    expect($current['characterization_id'])->toBe($f['row']->id)
        ->and($current['revision'])->toBe($expected['p8']['revision'] + 1)
        ->and(array_diff_assoc(array_intersect_key($current, $expected['p8']), $expected['p8']))->toBe(['revision'=>$current['revision']])
        ->and($f['ledger']->current($f['u']->id, $f['g']->id, 'synthetic:learning'))->toBe($rights);
    $sourceRows = t07ExportRows(); $c = DB::connection(); $pdo = $c->getPdo(); $level = $c->transactionLevel();
    $producerReads = []; $dispatcher = Characterization::getEventDispatcher();
    Characterization::setEventDispatcher(clone $dispatcher);
    Characterization::retrieved(function () use (&$producerReads) {
        $stack = array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function');
        foreach (['eligibilityProjection', 'eligibilityCurrentForAccount', 'frame', 'draft', 'completed', 'deliver'] as $producer) {
            if (in_array($producer, $stack, true)) { $producerReads[] = $producer; }
        }
    });
    foreach ([LearningCaseP5Snapshot::class, EsrsTopic::class, App\Models\CharacterizationDocument::class] as $model) {
        $model::retrieved(function () use (&$producerReads, $model) { $producerReads[] = $model; });
    }
    try {
        // GREEN seam: reads the persisted, token-bound full-bundle witness; null only means no trusted denial.
        // Today this assertion is the intended RED, after all real baseline/drift setup has succeeded.
        expect(method_exists($command, 'denySourceDrift'))->toBeTrue();
        $deny = new ReflectionMethod($command, 'denySourceDrift');
        $negative = $deny->invoke($command, $token, hrtime(true) / 1e9 + 1.0);
        expect($negative)->toBe(['denied'=>true, 'exclusions'=>[
            ['case_id'=>$id, 'case_hash'=>$line['reference']['case_hash'], 'reason'=>'not_current'],
        ]])->and($producerReads)->toBe([]);
    } finally { Characterization::setEventDispatcher($dispatcher); }
    $after = App\Models\LearningBatch::query()->sole(); $state = $after->dataset_state;
    expect($after->batch_id)->toBe($token['batch_id'])->and($after->fence)->toBe($token['fence'])
        ->and($after->status)->toBe('running')->and($after->receipt)->toBeNull()->and($after->context_digest)->toBe($context)
        ->and($after->issuer_state['generation'])->toBe($cursor['generation'] + 1)
        ->and($after->issuer_state['history'])->toBe($cursor['history'])
        ->and($after->issuer_state['dataset_witness'])->toBe($cursor['dataset_witness'])
        ->and($after->issuer_state['dataset_witness'])->not->toHaveKey('refresh')
        ->and($state['lineage'])->toBe($prior['lineage'])
        ->and($state['invalidated'])->toBe([$priorDigest, $digest])
        ->and($state['excluded'])->toBe($prior['excluded'] + [$id=>'not_current'])
        ->and($state['tombstones'])->toBe($prior['tombstones'])
        ->and($state['tombstones'])->not->toHaveKey($id)
        ->and($c->getPdo())->toBe($pdo)->and($c->transactionLevel())->toBe($level)->and(t07ExportRows())->toBe($sourceRows);
    $stored = $after->getRawOriginal(); $foreign = $token; $foreign['fence']++;
    expect(fn () => $deny->invoke($command, $foreign, hrtime(true) / 1e9 + 1.0))
        ->toThrow(DomainException::class, 'learning_batch.stale_owner');
    expect(App\Models\LearningBatch::query()->sole()->getRawOriginal())->toBe($stored);
});

it('T09 denial leaves unchanged headers on the full producer path and rejects a corrupt witness', function () {
    $f = t07Closed(); $e = t08Exporter($f); app()->instance(App\Services\LearningCaseExport::class, $e);
    config(['services.learning_batch.enabled'=>true, 'services.learning_batch.namespace'=>'test-namespace:t07-export',
        'services.learning_batch.synthetic_only'=>true, 'services.learning_batch.trusted_launcher'=>true,
        'services.learning_batch.actors'=>[$f['u']->id]]);
    $token = App\Models\LearningBatch::claim(); $command = new App\Console\Commands\RunLearningBatch;
    $bundle = (new ReflectionMethod($command, 'bundle'))->invoke($command, $token);
    $context = (new ReflectionMethod($command, 'authorityContext'))->invoke(null, $bundle);
    App\Models\LearningBatch::locked($token, function ($row) use ($token, $context, $bundle) {
        $cursor = $row->issuer_state;
        $cursor['dataset_witness'] = ['token'=>$token, 'context_digest'=>$context, 'bundle'=>$bundle, 'state'=>[]];
        $row->issuer_state = $cursor; $row->dataset_state = []; $row->context_digest = $context; $row->save();
    });
    $stored = App\Models\LearningBatch::query()->sole()->getRawOriginal();
    expect($command->denySourceDrift($token))->toBeNull();
    expect(App\Models\LearningBatch::query()->sole()->getRawOriginal())->toBe($stored);
    App\Models\LearningBatch::locked($token, function ($row) {
        $cursor = $row->issuer_state; $cursor['dataset_witness']['token']['fence']++;
        $row->issuer_state = $cursor; $row->save();
    });
    $corrupt = App\Models\LearningBatch::query()->sole()->getRawOriginal();
    expect(fn () => $command->denySourceDrift($token))->toThrow(DomainException::class, 'learning_batch.witness_invalid');
    expect(App\Models\LearningBatch::query()->sole()->getRawOriginal())->toBe($corrupt);
});
