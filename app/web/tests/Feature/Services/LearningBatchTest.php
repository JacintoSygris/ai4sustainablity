<?php

use App\Models\LearningBatch;
use App\Services\LearningCaseExport;
use Illuminate\Support\Facades\{Artisan, DB};

beforeEach(function () {
    $portableRoot=base_path('artifacts/learning-tests'); if (!is_dir($portableRoot)) mkdir($portableRoot,0700,true);
    $portableMap=$portableRoot.'/03-t06-close-mapping.json'; if (!is_file($portableMap)) file_put_contents($portableMap,'{"candidate_topics":[]}');

    Illuminate\Support\Facades\Http::preventStrayRequests();
    foreach (['learning_case_closure','learning_source_clock','learning_p6_base_source_clock','learning_p8_source_clock','learning_p9_source_clock','learning_p5_live_capture','learning_p6_prepared_request','learning_p6_job_parent_fence','learning_p6_interpretation_context'] as $flag) { config(['services.'.$flag.'.enabled'=>true]); }
    config(['services.characterization.api.model_profile'=>'t06_close_synthetic','services.characterization.prediction_mapping_path'=>base_path('artifacts/learning-tests').'/03-t06-close-mapping.json']);
    Illuminate\Support\Facades\Schema::create('learning_p9_source_revisions', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->foreignId('characterization_id')->primary()->constrained('characterizations')->cascadeOnDelete();
        $t->unsignedBigInteger('generation'); $t->unsignedBigInteger('revision'); $t->string('epoch',64); $t->string('digest',64);
    });
    app()->instance(App\Services\CharacterizationPredictionMapper::class,App\Services\CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}'));
});

it('T09 default batch is inert before database or child access', function () {
    DB::listen(function ($query) { throw new LogicException('batch must not query'); });
    expect(Artisan::call('learning:batch'))->toBe(1);
});

function t09Configure($test): array {
    $actors=[]; $fixtures=[];
    foreach (range(0,11) as $i) {
        $test->travelTo(Carbon\Carbon::parse('2026-01-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT).'T12:00:00Z'));
        $f=t07ExportFixture($i, $i%2===1);
        $f['closure']->close($f['u']->id,t07ExportInput($f));
        $actors[]=$f['u']->id; $fixtures[]=$f;
    }
    $test->travelTo(Carbon\Carbon::parse('2026-10-04T00:00:00Z'));
    $ledgers=array_column($fixtures,'ledger');
    // The same synthetic authority object resolves all stored account memberships.
    $export=LearningCaseExport::forSyntheticEligibilityTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$fixtures[0]['closure'],$ledgers[0]);
    app()->instance(LearningCaseExport::class,$export);
    config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export',
        'services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true,
        'services.learning_batch.actors'=>$actors]);
    return compact('actors','fixtures','export');
}

it('T09 native Artisan T08 pipeline returns fenced raw receipt', function () {
    extract(t09Configure($this));
    $exit=Artisan::call('learning:batch'); $this->assertSame(0,$exit,Artisan::output());
    $row=LearningBatch::query()->sole(); $receipt=$row->receipt;
    expect($row->status)->toBe('raw_complete')->and($receipt['fit_heads'])->toBeGreaterThan(0)
        ->and($receipt['optuna_trials'])->toBeGreaterThan(0)->and($receipt['promotion_allowed'])->toBeFalse()
        ->and($receipt['mode'])->toBe('raw_masked_only')->and($receipt['fence'])->toBe($row->fence)
        ->and($receipt['batch_id'])->toBe($row->batch_id)->and($row->issuer_state['generation'])->toBeGreaterThan(1);
    $result=['receipt'=>$receipt,'state'=>$row->dataset_state,'consumable'=>true];
    $token=['batch_id'=>$row->batch_id,'fence'=>$row->fence];
    $current=fn()=>$export->eligibilityBundleForAccounts($actors);
    DB::enableQueryLog(); DB::flushQueryLog();
    App\Console\Commands\RunLearningBatch::accept($token,$result,$row->context_digest,$current);
    $writes=array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])); DB::disableQueryLog();
    expect($writes)->toBe([]);
    $bad=$result; $bad['receipt']['promotion_allowed']=true;
    expect(fn()=>App\Console\Commands\RunLearningBatch::accept($token,$bad,$row->context_digest,$current))->toThrow(DomainException::class);
});

it('T09 bounded child timeout crash malformed and oversized output never complete', function () {
    t09Configure($this);
    foreach (['timeout','crash','malformed','oversized'] as $mode) {
        config(['services.learning_batch.adversarial_mode'=>$mode]);
        $started=microtime(true); expect(Artisan::call('learning:batch'))->toBe(1);
        expect(microtime(true)-$started)->toBeLessThan(6.0);
        expect(LearningBatch::query()->sole()->status)->toBe('aborted')->and(LearningBatch::query()->sole()->receipt)->toBeNull();
    }
});

it('T09 authority revoked during native child prevents raw completion and persists cursor', function () {
    $f=t09Configure($this); $calls=0;
    LearningBatch::saved(function ($row) use ($f,&$calls) {
        if ($row->status==='running' && $row->context_digest!==null && ++$calls===2) {
            $first=$f['fixtures'][0]; $first['ledger']->revoke($first['u']->id,$first['g']->id,'synthetic:learning',1,hash('sha256','t09-revoke'));
        }
    });
    expect(Artisan::call('learning:batch'))->toBe(1);
    $row=LearningBatch::query()->sole(); expect($row->status)->toBe('aborted')->and($row->receipt)->toBeNull();
    $generation=$row->issuer_state['generation'];
    config(['services.learning_batch.adversarial_mode'=>'crash']);
    expect(Artisan::call('learning:batch'))->toBe(1);
    expect(LearningBatch::query()->sole()->issuer_state['generation'])->toBeGreaterThan($generation);
});

it('T09 singleton rivals expiry and late old owner cannot mutate successor', function () {
    config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export','services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true]);
    $a=LearningBatch::claim(); DB::enableQueryLog(); DB::flushQueryLog();
    expect(LearningBatch::claim())->toBeNull();
    expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]); DB::disableQueryLog();
    $this->travel(61)->seconds();
    expect(fn()=>LearningBatch::heartbeat($a))->toThrow(DomainException::class);
    $b=LearningBatch::claim(); expect($b['fence'])->toBe($a['fence']+1); $before=LearningBatch::query()->sole()->getRawOriginal();
    foreach ([$a,['batch_id'=>$b['batch_id'],'fence'=>true],['batch_id'=>$b['batch_id'],'fence'=>1.5]] as $bad) {
        expect(fn()=>LearningBatch::heartbeat($bad))->toThrow(DomainException::class);
        expect(fn()=>LearningBatch::abort($bad))->toThrow(DomainException::class);
        expect(LearningBatch::query()->sole()->getRawOriginal())->toBe($before);
    }
});
it('T09 repair admission trips before PDO on production bad scope and metadata', function () {
    config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export','services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true]);
    $c=DB::connection(); $pdo=$c->getPdo(); $prop=new ReflectionProperty($c,'pdo');
    $prop->setValue($c,fn()=>throw new LogicException('PDO forbidden'));
    try {
        app()->instance('env','production');
        expect(fn()=>LearningBatch::claim())->toThrow(DomainException::class,'learning_batch.disabled');
        app()->instance('env','testing'); config(['services.learning_batch.namespace'=>'invalid']);
        expect(fn()=>LearningBatch::claim())->toThrow(DomainException::class,'learning_batch.disabled');
        config(['services.learning_batch.namespace'=>'test-namespace:t07-export']); $c->setDatabaseName('invalid');
        expect(fn()=>LearningBatch::claim())->toThrow(DomainException::class,'learning_batch.isolation_required');
    } finally { app()->instance('env','testing'); $c->setDatabaseName(':memory:'); $prop->setValue($c,$pdo); }
});

it('T09 repair actual PDO file database cannot impersonate memory metadata', function () {
    config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export','services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true]);
    $dir=dirname(base_path()).'/ai-service/artifacts/learning/05a-batch-'.bin2hex(random_bytes(16)); mkdir($dir,0700,true);
    $c=DB::connection(); $pdo=$c->getPdo(); $fake=new PDO('sqlite:'.$dir.'/synthetic.sqlite'); $level=$c->transactionLevel(); $c->setPdo($fake);
    try { expect(fn()=>LearningBatch::claim())->toThrow(DomainException::class,'learning_batch.isolation_required');
        expect($fake->query("SELECT count(*) FROM sqlite_master WHERE type='table'")->fetchColumn())->toBe(0);
    } finally { $c->setPdo($pdo); (new ReflectionProperty($c,'transactions'))->setValue($c,$level); }
});

it('T09 repair persisted fractional and unsafe fence lease reject before writes', function () {
    config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export','services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true]);
    $token=LearningBatch::claim();
    foreach ([['fence',1.5],['fence',9007199254740992],['lease_until',1.5],['heartbeat_at',1.5],['issuer_state','{"generation":true,"history":{}}']] as [$key,$value]) {
        DB::beginTransaction();
        try {
            DB::table('learning_batches')->where('id',1)->update([$key=>$value,'status'=>'aborted']);
            $before=LearningBatch::query()->sole()->getRawOriginal(); DB::enableQueryLog(); DB::flushQueryLog();
            expect(fn()=>LearningBatch::claim())->toThrow(DomainException::class,'learning_batch.counter_invalid');
            expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
            DB::disableQueryLog(); expect(LearningBatch::query()->sole()->getRawOriginal())->toBe($before);
        } finally { DB::disableQueryLog(); DB::rollBack(); }
    }
});
function t09RepairContext(array $bundle): string {
    $manifest=json_decode($bundle['manifest_json'],true); $bindings=json_decode($bundle['bindings_json'],true); unset($bindings['manifest_digest']);
    return hash('sha256',json_encode([$bundle['jsonl'],$manifest['cases'],$manifest['tombstones'],$bindings],JSON_THROW_ON_ERROR));
}

it('T09 repair fake current lineage and stale grant cursor cannot complete CAS', function () {
    $f=t09Configure($this); $token=LearningBatch::claim();
    $method=new ReflectionMethod(App\Console\Commands\RunLearningBatch::class,'bundle');
    $bundle=$method->invoke(new App\Console\Commands\RunLearningBatch,$token); $context=t09RepairContext($bundle);
    LearningBatch::locked($token,function($row)use($context){$row->context_digest=$context;$row->save();});
    $m=json_decode($bundle['manifest_json'],true); $b=json_decode($bundle['bindings_json'],true); $digest=str_repeat('a',64);
    $r=['batch_id'=>$token['batch_id'],'fence'=>$token['fence'],'dataset_digest'=>$digest,'context_digest'=>$context,
        'mode'=>'raw_masked_only','promotion_allowed'=>false,'sector_guard'=>false,'crc_floor'=>false,'fit_heads'=>2,'optuna_trials'=>4,
        'metrics'=>[],'development_digest'=>str_repeat('b',64),'authority_generation'=>$m['generation'],'authority_digest'=>$m['canonical_digest']];
    $lineage=array_map(fn($v)=>array_intersect_key($v,array_flip(['case_id','case_hash','source_revisions','rights_digest','policy_digest','export_digest','source_kind','source_revision'])),$b['cases']);
    $state=['generation'=>$m['generation'],'issued_at'=>$m['issued_at'],'manifest_digest'=>$m['canonical_digest'],
        'invalidated'=>[],'excluded'=>[],'tombstones'=>[],'current'=>array_column($b['cases'],null,'case_id'),'lineage'=>[$digest=>$lineage]];
    foreach (['fake_current','empty_lineage','altered_lineage','unsafe_revision','stale_generation','lost_invalidations'] as $variant) {
        $result=['receipt'=>$r,'state'=>$state,'consumable'=>true];
        if($variant==='fake_current') { $result['state']['current']=[]; }
        if($variant==='empty_lineage') { $result['state']['lineage'][$digest]=[]; }
        if($variant==='altered_lineage') { $result['state']['lineage'][$digest][0]['case_hash']=str_repeat('0',64); }
        if($variant==='unsafe_revision') { $result['state']['lineage'][$digest][0]['source_revisions']['p5']['revision']=true; }
        if($variant==='stale_generation') { $result['receipt']['authority_generation']=0; $result['state']['generation']=0; }
        DB::beginTransaction();
        try { if($variant==='lost_invalidations') { LearningBatch::locked($token,function($row){$row->dataset_state=['generation'=>0,'invalidated'=>[str_repeat('c',64)]];$row->save();}); } $before=LearningBatch::query()->sole()->getRawOriginal();
            expect(fn()=>App\Console\Commands\RunLearningBatch::accept($token,$result,$context,fn()=>$method->invoke(new App\Console\Commands\RunLearningBatch,$token)))->toThrow(DomainException::class);
            expect(LearningBatch::query()->sole()->getRawOriginal())->toBe($before);
        } finally { DB::rollBack(); }
    }
});

it('T09 repair before CAS current tuple membership and grant drift deny unchanged receipt', function () {
    $f=t09Configure($this); $token=LearningBatch::claim(); $method=new ReflectionMethod(App\Console\Commands\RunLearningBatch::class,'bundle');
    $bundle=$method->invoke(new App\Console\Commands\RunLearningBatch,$token); $context=t09RepairContext($bundle);
    LearningBatch::locked($token,function($row)use($context){$row->context_digest=$context;$row->save();});
    foreach(['p5','p6','p8','p9','member','rights'] as $variant) {
        DB::beginTransaction();
        try {
            $first=$f['fixtures'][0];
            if($variant==='member') { $first['m']->revoked_at=now(); $first['m']->save(); }
            elseif($variant==='rights') { $first['ledger']->revoke($first['u']->id,$first['g']->id,'synthetic:learning',1,hash('sha256','repair-revoke')); }
            else { $table=['p5'=>'learning_p5_source_revisions','p6'=>'learning_p6_base_source_revisions','p8'=>'learning_p8_source_revisions','p9'=>'learning_p9_source_revisions'][$variant];
                DB::table($table)->where('characterization_id',$first['row']->id)->increment('revision'); }
            $fresh=$method->invoke(new App\Console\Commands\RunLearningBatch,$token);
            expect(t09RepairContext($fresh))->not->toBe($context);
            expect(fn()=>App\Console\Commands\RunLearningBatch::accept($token,[],$context,fn()=>$method->invoke(new App\Console\Commands\RunLearningBatch,$token)))->toThrow(DomainException::class);
            expect(LearningBatch::query()->sole()->receipt)->toBeNull();
        } finally { DB::rollBack(); }
    }
});

it('T09 repair native fit source drift stops actual child', function () {
    $f=t09Configure($this); $hits=0;
    LearningBatch::saved(function($row)use($f,&$hits){
        if($row->status==='running' && $row->context_digest!==null && ++$hits===2) {
            DB::table('learning_p8_source_revisions')->where('characterization_id',$f['fixtures'][0]['row']->id)->increment('revision');
        }
    });
    $start=microtime(true); expect(Artisan::call('learning:batch'))->toBe(1);
    expect(microtime(true)-$start)->toBeLessThan(6.0);
    $row=LearningBatch::query()->sole(); expect($row->status)->toBe('aborted')->and($row->receipt)->toBeNull();
    $path=dirname(base_path()).'/ai-service/artifacts/learning/05a-batch-'.$row->batch_id.'/child-status.json';
    expect(json_decode(file_get_contents($path),true)['running'])->toBeFalse();
});
it('T09 repair native malicious receipt generation rejected without terminal receipt', function () {
    t09Configure($this); config(['services.learning_batch.adversarial_mode'=>'receipt_generation']);
    expect(Artisan::call('learning:batch'))->toBe(1);
    $row=LearningBatch::query()->sole(); expect($row->status)->toBe('aborted')->and($row->receipt)->toBeNull();
});


it('T09 G1 canonical selection and digest bind every mandatory lineage field', function () {
    $f=t09Configure($this); $this->assertSame(0,Artisan::call('learning:batch'),Artisan::output());
    $row=LearningBatch::query()->sole(); $token=['batch_id'=>$row->batch_id,'fence'=>$row->fence]; $context=$row->context_digest;
    $result=['receipt'=>$row->receipt,'state'=>$row->dataset_state,'consumable'=>true]; $digest=$row->receipt['dataset_digest'];
    $ack=['generation'=>$row->receipt['authority_generation'],'canonical_digest'=>$row->receipt['authority_digest']];
    $current=fn()=>$f['export']->eligibilityBundleForAccounts($f['actors']); $observed=[];
    foreach (['complete','missing','extra','duplicate','foreign','swapped','feature_missing','feature_changed','group_missing','group_changed','period_missing','period_changed','digest_changed','sticky_exclusion','sticky_tombstone'] as $variant) {
        DB::beginTransaction();
        try {
            $row=LearningBatch::query()->sole(); $row->status='running';$row->lease_until=now()->getTimestamp()+60;$row->receipt=null;$row->dataset_state=[];$row->save();
            $received=$result; $lines=&$received['state']['lineage'][$digest];
            if($variant==='missing') { array_pop($lines); }
            if(in_array($variant,['extra','duplicate'],true)) { $lines[]=$lines[0]; }
            if($variant==='foreign') { $lines[0]['case_id']='synthetic-foreign'; }
            if($variant==='swapped') { [$lines[0],$lines[1]]=[$lines[1],$lines[0]]; }
            foreach(['feature'=>'feature_digest','group'=>'company_group_key','period'=>'period_scope'] as $prefix=>$key) {
                if($variant===$prefix.'_missing') { unset($lines[0][$key]); }
                if($variant===$prefix.'_changed') { $lines[0][$key]=$key==='period_scope'?['period_key'=>str_repeat('0',64),'perimeter_key'=>str_repeat('0',64)]:str_repeat('0',64); }
            }
            if($variant==='digest_changed') { $changed=str_repeat('0',64);$received['receipt']['dataset_digest']=$changed;$received['state']['lineage'][$changed]=$lines;unset($received['state']['lineage'][$digest]); }
            if($variant==='sticky_exclusion') { $row->dataset_state=['excluded'=>[$lines[0]['case_id']=>'not_current']];$row->save(); }
            if($variant==='sticky_tombstone') { $row->dataset_state=['tombstones'=>[$lines[0]['case_id']=>['case_hash'=>$lines[0]['case_hash'],'kind'=>'revoked','at'=>'2026-01-01T00:00:00Z']]];$row->save(); }
            $before=LearningBatch::query()->sole()->getRawOriginal(); DB::enableQueryLog();DB::flushQueryLog();$denied=false;
            try { App\Console\Commands\RunLearningBatch::accept($token,$received,$context,$current,$ack); } catch(DomainException) { $denied=true; }
            $observed[$variant]=$denied;
            if($variant!=='complete') {
                expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
                expect(LearningBatch::query()->sole()->getRawOriginal())->toBe($before);
            }
        } finally { DB::disableQueryLog();DB::rollBack();unset($lines); }
    }
    expect($observed)->toBe(array_merge(['complete'=>false],array_fill_keys(['missing','extra','duplicate','foreign','swapped','feature_missing','feature_changed','group_missing','group_changed','period_missing','period_changed','digest_changed','sticky_exclusion','sticky_tombstone'],true)));
});

it('T09 G2 terminal replay requires fresh server authority with retained callback', function () {
    $f=t09Configure($this); $this->assertSame(0,Artisan::call('learning:batch'),Artisan::output());
    $row=LearningBatch::query()->sole();$token=['batch_id'=>$row->batch_id,'fence'=>$row->fence];$context=$row->context_digest;
    $result=['receipt'=>$row->receipt,'state'=>$row->dataset_state,'consumable'=>true];
    $cached=$f['export']->eligibilityBundleForAccounts($f['actors']);$current=fn()=>$cached;$observed=[];
    foreach(['identical','mismatch','revoke','regrant','member','issuer','issuer_outage','policy','p5','p6','p8','p9','expired_capability','disabled','scope','production','actual_pdo'] as $variant) {
        DB::beginTransaction();$pdo=DB::connection()->getPdo();$level=DB::connection()->transactionLevel();
        try {
            $first=$f['fixtures'][0];$received=$result;
            if($variant==='mismatch') { $received['receipt']['promotion_allowed']=true; }
            if(in_array($variant,['revoke','regrant'],true)) {
                $first['ledger']->revoke($first['u']->id,$first['g']->id,'synthetic:learning',1,hash('sha256','g12-revoke'));
                if($variant==='regrant') { $first['ledger']->grant($first['u']->id,$first['g']->id,'synthetic:learning',2,hash('sha256','g12-regrant')); }
            }
            if($variant==='member') { $first['m']->revoked_at=now();$first['m']->save(); }
            if($variant==='issuer') { $first['issuer']->denied=true; }
            if($variant==='issuer_outage') { $first['issuer']->onResolve=function() { throw new RuntimeException('synthetic-outage'); }; }
            if($variant==='policy') { [,,$member,$fake,$ledger]=t05bContext();$fake->policy='synthetic-v2';app()->instance(LearningCaseExport::class,LearningCaseExport::forSyntheticEligibilityTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$first['closure'],$ledger)); }
            if(in_array($variant,['p5','p6','p8','p9'],true)) {
                $table=['p5'=>'learning_p5_source_revisions','p6'=>'learning_p6_base_source_revisions','p8'=>'learning_p8_source_revisions','p9'=>'learning_p9_source_revisions'][$variant];
                DB::table($table)->where('characterization_id',$first['row']->id)->increment('revision');
            }
            if($variant==='expired_capability') { $expires=now()->getTimestamp()+60;$this->travel(61)->seconds();$first['issuer']->onResolve=function()use($first,$expires){if(now()->getTimestamp()>=$expires){$first['issuer']->denied=true;}}; }
            if($variant==='disabled') { config(['services.learning_batch.enabled'=>false]); }
            if($variant==='scope') { config(['services.learning_batch.namespace'=>'invalid']); }
            if($variant==='production') { app()->instance('env','production'); }
            $before=LearningBatch::query()->sole()->getRawOriginal();
            if($variant==='actual_pdo') {
                $dir=dirname(base_path()).'/ai-service/artifacts/learning/05a-batch-'.bin2hex(random_bytes(16));mkdir($dir,0700,true);
                DB::connection()->setPdo(new PDO('sqlite:'.$dir.'/synthetic.sqlite'));
            }
            DB::enableQueryLog();DB::flushQueryLog();$denied=false;
            try { App\Console\Commands\RunLearningBatch::accept($token,$received,$context,$current); } catch(DomainException|RuntimeException) { $denied=true; }
            $observed[$variant]=$denied;
            expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
            DB::connection()->setPdo($pdo);(new ReflectionProperty(DB::connection(),'transactions'))->setValue(DB::connection(),$level);
            expect(LearningBatch::query()->sole()->getRawOriginal())->toBe($before);
        } finally {
            DB::disableQueryLog();DB::connection()->setPdo($pdo);(new ReflectionProperty(DB::connection(),'transactions'))->setValue(DB::connection(),$level);
            $f['fixtures'][0]['issuer']->denied=false;$f['fixtures'][0]['issuer']->onResolve=null;app()->instance(LearningCaseExport::class,$f['export']);
            app()->instance('env','testing');config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export']);
            $this->travelTo(Carbon\Carbon::parse('2026-10-04T00:00:00Z'));DB::rollBack();
        }
    }
    expect($observed)->toBe(array_merge(['identical'=>false],array_fill_keys(['mismatch','revoke','regrant','member','issuer','issuer_outage','policy','p5','p6','p8','p9','expired_capability','disabled','scope','production','actual_pdo'],true)));
});

it('T10 technical publication alone and revoked authority cannot create accepted pointer', function () {
    $f=t09Configure($this);
    config(['services.learning_batch.candidate_stage'=>['version'=>'t10-explicit-fixture-v1','namespace'=>'test-namespace:t10-synthetic-shadow','topic_ids'=>['1','2','3'],'filter_keys'=>['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],'approved_common_axis'=>false]]);
    expect(Artisan::call('learning:batch'))->toBe(0);
    $row=LearningBatch::query()->sole();
    $path=dirname(base_path()).'/ai-service/artifacts/learning/05a-batch-'.$row->batch_id.'/stdout.log';
    $packet=json_decode(explode("\n",trim(file_get_contents($path)))[1],true)['candidate'];
    $token=['batch_id'=>$row->batch_id,'fence'=>$row->fence];
    $first=$f['fixtures'][0]; $first['ledger']->revoke($first['u']->id,$first['g']->id,'synthetic:learning',1,hash('sha256','t10-revoke'));
    DB::enableQueryLog();DB::flushQueryLog();
    expect(fn()=>app(App\Services\LearningCandidateSelection::class)->accept($token,$packet))->toThrow(DomainException::class);
    expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);DB::disableQueryLog();
});
