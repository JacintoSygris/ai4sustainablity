<?php
use App\Models\LearningBatch;
use App\Services\LearningCandidateSelection;
use Illuminate\Support\Facades\{Artisan,DB,Schema,Http};

beforeEach(function () {
    $portableRoot=base_path('artifacts/learning-tests'); if (!is_dir($portableRoot)) mkdir($portableRoot,0700,true);
    $portableMap=$portableRoot.'/03-t06-close-mapping.json'; if (!is_file($portableMap)) file_put_contents($portableMap,'{"candidate_topics":[]}');

    Http::preventStrayRequests();
    foreach (['learning_case_closure','learning_source_clock','learning_p6_base_source_clock','learning_p8_source_clock','learning_p9_source_clock','learning_p5_live_capture','learning_p6_prepared_request','learning_p6_job_parent_fence','learning_p6_interpretation_context'] as $flag) { config(['services.'.$flag.'.enabled'=>true]); }
    config(['services.characterization.api.model_profile'=>'t06_close_synthetic','services.characterization.prediction_mapping_path'=>base_path('artifacts/learning-tests').'/03-t06-close-mapping.json']);
    Schema::create('learning_p9_source_revisions',function (Illuminate\Database\Schema\Blueprint $t) {
        $t->foreignId('characterization_id')->primary()->constrained('characterizations')->cascadeOnDelete();
        $t->unsignedBigInteger('generation');$t->unsignedBigInteger('revision');$t->string('epoch',64);$t->string('digest',64);
    });
    app()->instance(App\Services\CharacterizationPredictionMapper::class,App\Services\CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}'));
});

it('T10 default stage and actual PDO attachment deny before pointer DML',function () {
    config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export',
        'services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true]);
    $service=app(LearningCandidateSelection::class);
    expect(fn()=>$service->read(str_repeat('a',64)))->toThrow(DomainException::class);
    config(['services.learning_batch.candidate_stage'=>[]]);
    DB::connection()->getPdo()->exec("ATTACH DATABASE ':memory:' AS synthetic_other");
    DB::enableQueryLog();DB::flushQueryLog();
    try {
        expect(fn()=>$service->read(str_repeat('a',64)))->toThrow(DomainException::class);
        expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
    } finally { DB::disableQueryLog();DB::connection()->getPdo()->exec('DETACH DATABASE synthetic_other'); }
});

it('T10 explicit synthetic selection consumes actual cold model on unseen company without normative writes',function () {
    $f=t09Configure($this);
    config(['services.learning_batch.candidate_stage'=>['version'=>'t10-explicit-fixture-v1','namespace'=>'test-namespace:t10-synthetic-shadow','topic_ids'=>['1','2','3'],'filter_keys'=>['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],'approved_common_axis'=>false]]);
    $exit=Artisan::call('learning:batch');$this->assertSame(0,$exit,Artisan::output());
    $entry=DB::table('learning_candidate_selections')->sole();$service=app(LearningCandidateSelection::class);
    $features=['data'=>[['Spain','50_249','true']],'index'=>['synthetic-unseen-company'],'columns'=>['headquarters_country','employee_count_range','stock_listed']];
    expect(fn()=>$service->shadow($entry->candidate_key,$features))->toThrow(DomainException::class);
    $before=DB::table('characterizations')->get()->map(fn($r)=>(array)$r)->all();
    $service->select($entry->candidate_key,'synthetic-human-select');
    $proposal=$service->shadow($entry->candidate_key,$features);
    expect($proposal['mode'])->toBe('synthetic-shadow-proposal-only')->and($proposal['promotion_allowed'])->toBeFalse()
        ->and($proposal['raw_labels'])->toHaveCount(1)->and($proposal['mapping']['approved_common_axis'])->toBeFalse();
    DB::enableQueryLog();DB::flushQueryLog();
    $service->accept(['batch_id'=>$entry->batch_id,'fence'=>$entry->fence],json_decode($entry->technical_metadata,true));
    $service->select($entry->candidate_key,'synthetic-human-rollback');
    expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);DB::disableQueryLog();
    expect(DB::table('characterizations')->get()->map(fn($r)=>(array)$r)->all())->toBe($before);
    $first=$f['fixtures'][0];$first['issuer']->denied=true;
    DB::enableQueryLog();DB::flushQueryLog();
    foreach (['read','select','shadow'] as $op) {
        expect(fn()=>match($op) {'read'=>$service->read($entry->candidate_key),'select'=>$service->select($entry->candidate_key,'synthetic-human-rollback'),'shadow'=>$service->shadow($entry->candidate_key,$features)})->toThrow(DomainException::class);
    }
    expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);DB::disableQueryLog();
});

it('T10 repair two native accepted versions permit explicit previous rollback under unchanged authority',function () {
    $f=t09Configure($this);
    config(['services.learning_batch.candidate_stage'=>['version'=>'t10-explicit-fixture-v1','namespace'=>'test-namespace:t10-synthetic-shadow','topic_ids'=>['1','2','3'],'filter_keys'=>['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],'approved_common_axis'=>false]]);
    $service=app(LearningCandidateSelection::class);
    $features=['data'=>[['Spain','50_249','true']],'index'=>['synthetic-unseen-rollback-company'],'columns'=>['headquarters_country','employee_count_range','stock_listed']];
    $normative=fn()=>[DB::table('characterizations')->get()->toJson(),t07ExportRows()];
    $before=$normative();
    $exit=Artisan::call('learning:batch');$this->assertSame(0,$exit,Artisan::output());
    $previous=DB::table('learning_candidate_selections')->sole();
    $old=json_decode($previous->technical_metadata,true);
    $service->select($previous->candidate_key,'synthetic-human-select');
    $exit=Artisan::call('learning:batch');$this->assertSame(0,$exit,Artisan::output());
    $latest=DB::table('learning_candidate_selections')->where('candidate_key','!=',$previous->candidate_key)->sole();
    $new=json_decode($latest->technical_metadata,true);
    expect($old['binding']['context_digest'])->toBe($new['binding']['context_digest'])
        ->and($previous->candidate_key)->not->toBe($latest->candidate_key)
        ->and($old['receipt']['generation'])->not->toBe($new['receipt']['generation'])
        ->and($old['receipt']['run_id'])->not->toBe($new['receipt']['run_id'])
        ->and($old['binding']['token'])->not->toBe($new['binding']['token']);
    $service->select($latest->candidate_key,'synthetic-human-select');
    // Prospective positive: the previous accepted publication is no longer the singleton owner.
    $service->select($previous->candidate_key,'synthetic-human-rollback');
    expect(DB::table('learning_candidate_selections')->where('selected',true)->sole()->candidate_key)->toBe($previous->candidate_key);
    expect($service->read($previous->candidate_key))->toBe($old);
    $proposal=$service->shadow($previous->candidate_key,$features);
    expect($proposal['mode'])->toBe('synthetic-shadow-proposal-only')->and($proposal['promotion_allowed'])->toBeFalse();
    expect($normative())->toBe($before);
    $service->select($latest->candidate_key,'synthetic-human-select');
    $first=$f['fixtures'][0];
    $vectors=[
        'invalidation'=>function()use($old){$r=LearningBatch::query()->sole();$s=$r->dataset_state;$s['invalidated'][]=$old['receipt']['artifact_sha256'];$s['invalidated'][]=json_decode(file_get_contents(dirname(base_path()).'/ai-service/artifacts/learning/05b-candidate-'.$old['binding']['token']['batch_id'].'/private/test-namespace-t10-synthetic-shadow/'.$old['receipt']['generation'].'/manifest.json'),true)['dataset_digest'];$r->dataset_state=$s;$r->save();},
        'source'=>fn()=>DB::table('characterizations')->where('id',$first['row']->id)->update(['submission_generation'=>2]),
        'member'=>fn()=>DB::table('learning_company_memberships')->where('id',$first['m']->id)->update(['verification_status'=>'revoked']),
        'revoke'=>fn()=>$first['ledger']->revoke($first['u']->id,$first['g']->id,'synthetic:learning',1,hash('sha256','t10-repair-revoke')),
        'outage'=>function()use($first){$first['issuer']->denied=true;},
        'G1G2'=>function()use($first){$first['ledger']->revoke($first['u']->id,$first['g']->id,'synthetic:learning',1,hash('sha256','t10-repair-r2'));$first['ledger']->grant($first['u']->id,$first['g']->id,'synthetic:learning',2,hash('sha256','t10-repair-g3'));},
        'policy'=>function()use($first){[, , ,$fake,$ledger]=t05bContext();$fake->policy='synthetic-policy-drift';app()->instance(App\Services\LearningCaseExport::class,App\Services\LearningCaseExport::forSyntheticEligibilityTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$first['closure'],$ledger));},
        'expiry'=>function(){ $tick=0;$base=Carbon\Carbon::parse('2026-10-04T00:00:00Z');Carbon\Carbon::setTestNow(function()use(&$tick,$base){return $base->copy()->addSeconds(61*$tick++);}); },
        'grantgeneration'=>fn()=>DB::table('learning_candidate_selections')->where('candidate_key',$previous->candidate_key)->update(['authority_generation'=>0]),
        'lease'=>function(){ $r=LearningBatch::query()->sole();$r->status='running';$r->lease_until=now()->getTimestamp()-1;$r->save(); },
        'wrongpacket'=>fn()=>DB::table('learning_candidate_selections')->where('candidate_key',$previous->candidate_key)->update(['technical_metadata'=>$latest->technical_metadata]),
        'tuple'=>fn()=>DB::table('learning_candidate_selections')->where('candidate_key',$previous->candidate_key)->update(['fence'=>$latest->fence]),
    ];
    foreach($vectors as $name=>$mutate) {
        DB::beginTransaction();
        try {
            $mutate();$selected=DB::table('learning_candidate_selections')->where('selected',true)->sole();
            DB::enableQueryLog();DB::flushQueryLog();
            if($name==='expiry') expect(fn()=>$service->select($previous->candidate_key,'synthetic-human-rollback'))->toThrow(InvalidArgumentException::class,'learning_manifest.expired');
            else expect(fn()=>$service->select($previous->candidate_key,'synthetic-human-rollback'))->toThrow(DomainException::class);
            expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
            DB::disableQueryLog();
            expect((array)DB::table('learning_candidate_selections')->where('selected',true)->sole())->toBe((array)$selected);
        } finally { DB::disableQueryLog();DB::rollBack();$first['issuer']->denied=false;app()->instance(App\Services\LearningCaseExport::class,$f['export']);$this->travelTo(Carbon\Carbon::parse('2026-10-04T00:00:00Z')); }
    }
    expect($service->read($latest->candidate_key))->toBe($new);
    DB::enableQueryLog();DB::flushQueryLog();
    expect(fn()=>$service->accept($old['binding']['token'],$old))->toThrow(DomainException::class);
    expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);DB::disableQueryLog();
    expect($normative())->toBe($before);
});


it('T10 authority acceptance first insert and replay share current consume class vectors',function () {
    $f=t09Configure($this);
    config(['services.learning_batch.candidate_stage'=>['version'=>'t10-explicit-fixture-v1','namespace'=>'test-namespace:t10-synthetic-shadow','topic_ids'=>['1','2','3'],'filter_keys'=>['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],'approved_common_axis'=>false]]);
    $normative=fn()=>[DB::table('characterizations')->get()->toJson(),t07ExportRows()];
    $before=$normative();$intercept=true;$deniedState=null;$deniedPointers=null;
    // Natural producer seam after raw completion, before the first accepted pointer.
    app()->beforeResolving(LearningCandidateSelection::class,function () use (&$intercept,&$deniedState,&$deniedPointers) {
        if (!$intercept) { return; } $intercept=false;
        $row=LearningBatch::query()->sole();expect($row->status)->toBe('raw_complete');
        expect(DB::table('learning_candidate_selections')->count())->toBe(0);
        $state=$row->dataset_state;$state['invalidated'][]=$row->receipt['dataset_digest'];$row->dataset_state=$state;$row->save();
        $deniedState=$row->fresh()->getRawOriginal();$deniedPointers=DB::table('learning_candidate_selections')->get()->toJson();
        DB::enableQueryLog();DB::flushQueryLog();
    });
    DB::beginTransaction();
    try {
        expect(Artisan::call('learning:batch'))->toBe(1);
        expect(Artisan::output())->toContain('learning_candidate.consume_ineligible');
        expect($intercept)->toBeFalse();
        expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
        DB::disableQueryLog();
        expect(LearningBatch::query()->sole()->getRawOriginal())->toBe($deniedState);
        expect(DB::table('learning_candidate_selections')->get()->toJson())->toBe($deniedPointers);
        expect($normative())->toBe($before);
    } finally { DB::disableQueryLog();DB::rollBack(); }
    $this->assertSame(0,Artisan::call('learning:batch'),Artisan::output());
    $entry=DB::table('learning_candidate_selections')->sole();$package=json_decode($entry->technical_metadata,true);
    $token=['batch_id'=>$entry->batch_id,'fence'=>$entry->fence];$service=app(LearningCandidateSelection::class);
    print("T10_AUTHORITY_PASS_EXISTING_FIRST_INSERT\n");
    DB::enableQueryLog();DB::flushQueryLog();
    try {
        expect($service->accept($token,$package))->toBe($entry->candidate_key);
        expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
    } finally { DB::disableQueryLog(); }
    print("T10_AUTHORITY_PASS_EXISTING_VALID_REPLAY_ZERO_DML\n");
    $first=$f['fixtures'][0];$digest=LearningBatch::query()->sole()->receipt['dataset_digest'];
    $vectors=[
        'invalidated'=>function()use($digest){$r=LearningBatch::query()->sole();$s=$r->dataset_state;$s['invalidated'][]=$digest;$r->dataset_state=$s;$r->save();},
        'missinglineage'=>function()use($digest){$r=LearningBatch::query()->sole();$s=$r->dataset_state;unset($s['lineage'][$digest]);$r->dataset_state=$s;$r->save();},
        'excluded'=>function()use($digest){$r=LearningBatch::query()->sole();$s=$r->dataset_state;$s['excluded'][$s['lineage'][$digest][0]['case_id']]='synthetic-denied';$r->dataset_state=$s;$r->save();},
        'tombstone'=>function()use($digest){$r=LearningBatch::query()->sole();$s=$r->dataset_state;$s['tombstones'][$s['lineage'][$digest][0]['case_id']]=['kind'=>'synthetic-deleted'];$r->dataset_state=$s;$r->save();},
        'fulltuple'=>function()use($digest){$r=LearningBatch::query()->sole();$s=$r->dataset_state;$id=$s['lineage'][$digest][0]['case_id'];$s['current'][$id]['source_revision']++;$r->dataset_state=$s;$r->save();},
        'source'=>fn()=>DB::table('characterizations')->where('id',$first['row']->id)->update(['submission_generation'=>2]),
        'member'=>fn()=>DB::table('learning_company_memberships')->where('id',$first['m']->id)->update(['verification_status'=>'revoked']),
        'revoke'=>fn()=>$first['ledger']->revoke($first['u']->id,$first['g']->id,'synthetic:learning',1,hash('sha256','t10-authority-revoke')),
        'G1G2'=>function()use($first){$first['ledger']->revoke($first['u']->id,$first['g']->id,'synthetic:learning',1,hash('sha256','t10-authority-r2'));$first['ledger']->grant($first['u']->id,$first['g']->id,'synthetic:learning',2,hash('sha256','t10-authority-g3'));},
        'outage'=>function()use($first){$first['issuer']->denied=true;},
        'policy'=>function()use($first){[, , ,$fake,$ledger]=t05bContext();$fake->policy='synthetic-policy-drift';app()->instance(App\Services\LearningCaseExport::class,App\Services\LearningCaseExport::forSyntheticEligibilityTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$first['closure'],$ledger));},
        'expiry'=>function(){ $tick=0;$base=Carbon\Carbon::parse('2026-10-04T00:00:00Z');Carbon\Carbon::setTestNow(function()use(&$tick,$base){return $base->copy()->addSeconds(61*$tick++);}); },
    ];
    foreach ($vectors as $name=>$mutate) {
        DB::beginTransaction();
        try {
            $mutate();$state=LearningBatch::query()->sole()->getRawOriginal();$pointers=DB::table('learning_candidate_selections')->get()->toJson();$rows=$normative();
            DB::enableQueryLog();DB::flushQueryLog();
            if ($name==='expiry') expect(fn()=>$service->accept($token,$package))->toThrow(InvalidArgumentException::class,'learning_manifest.expired');
            else expect(fn()=>$service->accept($token,$package))->toThrow(DomainException::class);
            expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
            DB::disableQueryLog();
            expect(LearningBatch::query()->sole()->getRawOriginal())->toBe($state);
            expect(DB::table('learning_candidate_selections')->get()->toJson())->toBe($pointers);
            expect($normative())->toBe($rows);
            print('T10_AUTHORITY_REPLAY_DENIED_'.$name."\n");
        } finally { DB::disableQueryLog();DB::rollBack();$first['issuer']->denied=false;app()->instance(App\Services\LearningCaseExport::class,$f['export']);$this->travelTo(Carbon\Carbon::parse('2026-10-04T00:00:00Z')); }
    }
    expect($service->read($entry->candidate_key))->toBe($package);
    expect($normative())->toBe($before);
});

it('T10 authority historical selection requires explicit rollback before writes or idempotent return',function () {
    t09Configure($this);
    config(['services.learning_batch.candidate_stage'=>['version'=>'t10-explicit-fixture-v1','namespace'=>'test-namespace:t10-synthetic-shadow','topic_ids'=>['1','2','3'],'filter_keys'=>['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],'approved_common_axis'=>false]]);
    $normative=fn()=>[DB::table('characterizations')->get()->toJson(),t07ExportRows()];$before=$normative();
    $service=app(LearningCandidateSelection::class);
    $this->assertSame(0,Artisan::call('learning:batch'),Artisan::output());$old=DB::table('learning_candidate_selections')->sole();$a=json_decode($old->technical_metadata,true);
    $this->assertSame(0,Artisan::call('learning:batch'),Artisan::output());$latest=DB::table('learning_candidate_selections')->where('candidate_key','!=',$old->candidate_key)->sole();$b=json_decode($latest->technical_metadata,true);
    expect($old->candidate_key)->not->toBe($latest->candidate_key)->and($old->fence)->toBeLessThan($latest->fence)
        ->and($a['binding']['token'])->not->toBe($b['binding']['token'])->and($a['receipt']['run_id'])->not->toBe($b['receipt']['run_id'])
        ->and($a['receipt']['generation'])->not->toBe($b['receipt']['generation']);
    $oldRegistry=dirname(base_path()).'/ai-service/artifacts/learning/05b-candidate-'.$old->batch_id;
    $newRegistry=dirname(base_path()).'/ai-service/artifacts/learning/05b-candidate-'.$latest->batch_id;
    expect($oldRegistry)->not->toBe($newRegistry)->and(is_dir($oldRegistry))->toBeTrue()->and(is_dir($newRegistry))->toBeTrue();
    $service->select($latest->candidate_key,'synthetic-human-select');
    foreach ([$old->candidate_key,$latest->candidate_key] as $key) {
        foreach (['synthetic-auto-select','',true,false,'synthetic-human-select'] as $decision) {
            if ($key===$latest->candidate_key && $decision==='synthetic-human-select') { continue; }
            $pointers=DB::table('learning_candidate_selections')->get()->toJson();$state=LearningBatch::query()->sole()->getRawOriginal();
            DB::enableQueryLog();DB::flushQueryLog();
            try {
                expect(fn()=>$service->select($key,$decision))->toThrow(DomainException::class);
                expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
                expect(DB::table('learning_candidate_selections')->get()->toJson())->toBe($pointers);
                expect(LearningBatch::query()->sole()->getRawOriginal())->toBe($state);
            } finally { DB::disableQueryLog(); }
        }
    }
    DB::enableQueryLog();DB::flushQueryLog();
    try {
        $service->select($latest->candidate_key,'synthetic-human-select');
        expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
    } finally { DB::disableQueryLog(); }
    $service->select($old->candidate_key,'synthetic-human-rollback');
    expect(DB::table('learning_candidate_selections')->where('selected',true)->sole()->candidate_key)->toBe($old->candidate_key);
    print("T10_AUTHORITY_PASS_EXISTING_EXPLICIT_PREVIOUS_ROLLBACK\n");
    $pointers=DB::table('learning_candidate_selections')->get()->toJson();DB::enableQueryLog();DB::flushQueryLog();
    try {
        expect(fn()=>$service->select($old->candidate_key,'synthetic-human-select'))->toThrow(DomainException::class);
        $service->select($old->candidate_key,'synthetic-human-rollback');
        expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]);
        expect(DB::table('learning_candidate_selections')->get()->toJson())->toBe($pointers);
    } finally { DB::disableQueryLog(); }
    $features=['data'=>[['Spain','50_249','true']],'index'=>['synthetic-authority-rollback-company'],'columns'=>['headquarters_country','employee_count_range','stock_listed']];
    expect($service->shadow($old->candidate_key,$features)['mode'])->toBe('synthetic-shadow-proposal-only');
    expect($normative())->toBe($before);
});
