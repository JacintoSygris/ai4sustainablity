<?php

use App\Models\{Characterization, User, EsrsTopic, LearningCase, LearningCaseP5Snapshot, LearningCompanyGroup, LearningCompanyMembership};
use App\Services\{LearningCaseClosure, LearningAuthorizationAuthority, LearningAuthorization, LearningAuthorizationLedger, LearningCaseP5Storage, LearningCaseSnapshot, LearningP5Snapshot, CharacterizationStateTransaction, ApiCharacterizationGateway, CharacterizationPredictionMapper, EsrsDatapointCorpusBuilder, Ar16MatterDrMappingRepository, EsrsDatapointResponseState};
use Illuminate\Support\Facades\{DB, Schema, Http};
use Illuminate\Database\Schema\Blueprint;

// Test namespace only. human_product exercises the closed contract inside this isolated runner.
class T06CloseCorpus extends EsrsDatapointCorpusBuilder
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
    expect(app()->environment())->toBe('testing');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    $mappingPath = storage_path('app/fixtures/t06close.map.json');
    if (! is_dir(dirname($mappingPath))) { mkdir(dirname($mappingPath), 0700, true); }
    file_put_contents($mappingPath, '{"candidate_topics":[]}');
    Http::preventStrayRequests();
    foreach (['learning_case_closure','learning_source_clock','learning_p6_base_source_clock','learning_p8_source_clock','learning_p9_source_clock','learning_p5_live_capture','learning_p6_prepared_request','learning_p6_job_parent_fence','learning_p6_interpretation_context'] as $flag) { config(['services.'.$flag.'.enabled' => true]); }
    config(['services.characterization.api.model_profile' => 't06_close_synthetic', 'services.characterization.prediction_mapping_path' => $mappingPath]);
    Schema::create('learning_p9_source_revisions', function (Blueprint $t) {
        $t->foreignId('characterization_id')->primary()->constrained('characterizations')->cascadeOnDelete();
        $t->unsignedBigInteger('generation'); $t->unsignedBigInteger('revision'); $t->string('epoch',64); $t->string('digest',64);
    });
    app()->instance(CharacterizationPredictionMapper::class, CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}'));
});
function t06CloseFixture(): array {
    $u = User::factory()->create(['name' => 'SYNTHETIC_ACCOUNT']);
    foreach ([1,2,3] as $id) { EsrsTopic::query()->forceCreate(['hash'=>hash('sha256','synthetic-topic-'.$id),'id' => $id,'esrs_code'=>'SYNTHETIC','theme_es'=>'SYNTHETIC','theme_en'=>'SYNTHETIC','subtheme_es'=>'SYNTHETIC','subtheme_en'=>'SYNTHETIC','subtopic_es'=>'SYNTHETIC','subtopic_en'=>'SYNTHETIC']); }
    $g = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256','SYNTHETIC_GROUP')]);
    $m = LearningCompanyMembership::query()->forceCreate(['learning_company_group_id'=>$g->id,'user_id'=>$u->id,'subject_type'=>'account','subject_identifier'=>(string)$u->id,'verification_status'=>'verified','verified_at'=>now(),'evidence_type'=>'synthetic-only','evidence_digest'=>hash('sha256','synthetic-member')]);
    $issuer = new class implements LearningAuthorizationAuthority {
        public bool $denied = false;
        public function references(string $purpose): ?array { return $this->denied ? null : ['issuer_ref'=>'synthetic:issuer','issuer_version'=>'v1','issuer_digest'=>hash('sha256','issuer'),'capability_ref'=>'synthetic:cap','capability_version'=>'v1','capability_digest'=>hash('sha256','cap'),'policy_version'=>'synthetic-policy-v1','policy_digest'=>hash('sha256','synthetic-policy')]; }
        public function resolve(User $actor, LearningCompanyMembership $member, string $purpose): ?array { $r=$this->references($purpose); return $r===null ? null : $r+['actor_id'=>$actor->id,'group_id'=>$member->learning_company_group_id,'membership_id'=>$member->id,'purpose'=>$purpose,'state'=>'available','provenance'=>'synthetic-only','promotion_allowed'=>false]; }
    };
    $tx = new CharacterizationStateTransaction;
    $ledger = new LearningAuthorizationLedger(new LearningAuthorization($issuer),$tx);
    $ledger->grant($u->id,$g->id,'synthetic:learning',0,hash('sha256','grant'));
    $row = $tx->runForUser($u->id, fn()=>Characterization::create(['user_id'=>$u->id,'status'=>'completed','submission_generation'=>1,'esrs_topic_ids'=>[1,2],
        'form_data'=>['company_profile'=>['headquarters_country'=>'Spain','stock_listed'=>false],'operations'=>['employee_count_range'=>'50_249','employee_count'=>150],
            'materiality_confirmation'=>['revision'=>1,'confirmed_topic_ids'=>[1],'reviewed_topic_ids'=>[1,2],'universe_attestation'=>['version'=>1,'reviewed_universe'=>true,'mode'=>'direct'],'p6_snapshot'=>['topic_ids'=>[1,2],'captured_at'=>'2026-10-03T12:00:00Z'],'confirmed_at'=>'2026-10-03T12:00:00Z']],
        'result_data'=>['status'=>'completed','model_profile'=>'t06_close_synthetic','mapping_metadata'=>['python'=>['serving_identity'=>['profile'=>'t06_close_synthetic','artifact_sha256'=>['synthetic.pkl'=>str_repeat('a',64)],'policy_sha256'=>[],'runtime_config'=>['score_threshold'=>0.95,'policy_active'=>['label_thresholds'=>false,'crc_recall_floor'=>false,'sector_guard'=>false]],'serving_identity_sha256'=>str_repeat('b',64)]]]]]));
    $builder = new T06CloseCorpus(new Ar16MatterDrMappingRepository);
    $feedback = ['schema_version'=>'datapoint-feedback-v1','authority_digest'=>(new EsrsDatapointResponseState(new Ar16MatterDrMappingRepository))->learningAuthorityDigest($builder->build($row->fresh())), 'reviewed_datapoint_ids'=>['SYNTHETIC_A'],'decisions'=>[['datapoint_id'=>'SYNTHETIC_A','relevant'=>false,'selected_to_answer'=>false,'reason_codes'=>['scope'],'note'=>null]]];
    $tx->runForUser($u->id, function($fresh)use($feedback){$f=$fresh->form_data; $f['esrs_datapoint_responses']=['learning_feedback'=>$feedback]; $fresh->form_data=$f; $fresh->save();});
    $authority = ['framework_version'=>'synthetic-esrs','catalog_version'=>'synthetic-catalog','catalog_digest'=>hash('sha256','synthetic-catalog'),'mapping_version'=>'synthetic-mapping','mapping_digest'=>hash('sha256','synthetic-mapping'),'topic_ids'=>['1','2','3'],'datapoint_ids'=>['SYNTHETIC_A','SYNTHETIC_B'],'ambiguous_topic_ids'=>[],'ambiguous_datapoint_ids'=>[]];
    $storage=new LearningCaseP5Storage($tx,app(LearningCaseSnapshot::class),$ledger,new LearningP5Snapshot);
    $closure=LearningCaseClosure::forSyntheticTests(['namespace'=>'test-namespace:t06-close','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:learning','period_scope'=>['period_key'=>'synthetic-period','perimeter_key'=>'synthetic-only'],'authority'=>$authority],$ledger,$storage,new ApiCharacterizationGateway(CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}')),$builder);
    app()->instance(LearningCaseClosure::class,$closure);
    return compact('u','g','m','issuer','ledger','row','builder','closure');
}
function t06CloseInput(array $f): array { $d=$f['closure']->draft($f['u']->id); return ['expected_revisions'=>$d['expected_revisions'],'expected_authorization_generation'=>$d['expected_authorization_generation'],'source_token'=>$d['source_token'],'idempotency_key'=>'synthetic-command-1','reviewed_universe'=>true,'final_for_period_scope'=>true,'declaration_version'=>'local-synthetic-closure-v1']; }
function t06CloseRows(): array { $out=[]; foreach (['learning_cases','learning_case_states','learning_case_p5_snapshots','learning_case_p5_storage_receipts','learning_case_closure_receipts','learning_authorization_records'] as $t) { $out[$t]=DB::table($t)->get()->map(fn($r)=>(array)$r)->all(); } return $out; }
it('T06 inventory exposes the closure composition', function () {
    expect(class_exists(App\Services\LearningCaseClosure::class))->toBeTrue();
});
it('T06 closes a complete server case with frozen P5 and authoritative receipt', function () {
    $f=t06CloseFixture(); $out=$f['closure']->close($f['u']->id,t06CloseInput($f));
    expect($out['status'])->toBe('closed')->and($out['promotion_allowed'])->toBeFalse();
    $case=LearningCase::query()->sole(); $p=json_decode($case->payload_text,true);
    expect($p['topic_labels'])->toBe([['observed_mask'=>1,'topic_id'=>'1','value'=>1],['observed_mask'=>1,'topic_id'=>'2','value'=>0]])
        ->and($p['closure_evidence']['server_actor_id'])->toBe((string)$f['u']->id);
    expect(LearningCaseP5Snapshot::query()->sole()->digest)->toBe($p['p5_snapshot']['digest']);
    expect(DB::table('learning_case_p5_storage_receipts')->sole()->source_header_text)->not->toBeNull();
    expect(DB::table('learning_case_closure_receipts')->count())->toBe(1); Http::assertNothingSent();
});
it('T06 denies partial stale and coerced CAS without writes', function () {
    $f=t06CloseFixture(); $input=t06CloseInput($f); $before=t06CloseRows();
    $bad=$input; unset($bad['expected_revisions']['p9']);
    expect(fn()=>$f['closure']->close($f['u']->id,$bad))->toThrow(DomainException::class);
    $bad=$input; $bad['expected_revisions']['p5']['revision']=(string)$bad['expected_revisions']['p5']['revision'];
    expect(fn()=>$f['closure']->close($f['u']->id,$bad))->toThrow(DomainException::class);
    $bad=$input; $bad['source_token']=str_repeat('0',64);
    expect(fn()=>$f['closure']->close($f['u']->id,$bad))->toThrow(DomainException::class);
    expect(t06CloseRows())->toBe($before);
});
it('T06 rejects missing P5 and unknown P8 review', function () {
    $f=t06CloseFixture(); $input=t06CloseInput($f); $before=t06CloseRows();
    (new CharacterizationStateTransaction)->runForUser($f['u']->id,function($r){$f=$r->form_data; unset($f['company_profile']); $r->form_data=$f; $r->save();});
    expect(fn()=>$f['closure']->close($f['u']->id,$input))->toThrow(DomainException::class);
    expect(t06CloseRows())->toBe($before);
});
it('T06 disabled admission denies before PDO and default GET is safe', function () {
    $c=new LearningCaseClosure; $connection=DB::connection(); $pdo=$connection->getPdo(); $property=new ReflectionProperty($connection,'pdo');
    $property->setValue($connection,fn()=>throw new LogicException('PDO forbidden'));
    try { expect($c->draft(1)['status'])->toBe('disabled'); expect(fn()=>$c->close(1,[]))->toThrow(DomainException::class); }
    finally { $property->setValue($connection,$pdo); }
});
it('T06 replay keeps exact bytes and emits no writes or timestamps', function () {
    $f=t06CloseFixture(); $i=t06CloseInput($f); $first=$f['closure']->close($f['u']->id,$i); $before=t06CloseRows();
    DB::enableQueryLog(); DB::flushQueryLog(); expect($f['closure']->close($f['u']->id,$i))->toBe($first);
    expect(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query'])))->toBe([]); DB::disableQueryLog();
    $bad=$i; $bad['reviewed_universe']=false; expect(fn()=>$f['closure']->close($f['u']->id,$bad))->toThrow(DomainException::class);
    expect(t06CloseRows())->toBe($before);
});
it('T06 withdraw atomically revokes tombstones and preserves historical evidence', function () {
    $f=t06CloseFixture(); $i=t06CloseInput($f); $first=$f['closure']->close($f['u']->id,$i);
    $d=$f['closure']->draft($f['u']->id); $out=$f['closure']->withdraw($f['u']->id,['expected_authorization_generation'=>$d['expected_authorization_generation'],'idempotency_key'=>'synthetic-withdraw-1']);
    expect($out['status'])->toBe('withdrawn');
    expect($f['ledger']->current($f['u']->id,$f['g']->id,'synthetic:learning')['status'])->toBe('deleted');
    expect(DB::table('learning_authorization_records')->count())->toBe(3);
    expect(LearningCase::count())->toBe(1)->and(LearningCaseP5Snapshot::count())->toBe(1);
    expect(json_decode(DB::table('learning_case_closure_receipts')->sole()->receipt_text,true))->toBe($first['receipt']);
    expect(fn()=>$f['closure']->close($f['u']->id,$i))->toThrow(DomainException::class);
});
it('T06 replay cannot revive withdrawn rights missing frozen P5 or foreign actors', function () {
    $f=t06CloseFixture(); $i=t06CloseInput($f); $f['closure']->close($f['u']->id,$i); $before=t06CloseRows();
    $f['issuer']->denied=true; expect(fn()=>$f['closure']->close($f['u']->id,$i))->toThrow(DomainException::class); $f['issuer']->denied=false;
    $foreign=User::factory()->create(); expect(fn()=>$f['closure']->close($foreign->id,$i))->toThrow(DomainException::class);
    expect(t06CloseRows())->toBe($before);
    DB::table('learning_case_p5_snapshots')->where('id',LearningCaseP5Snapshot::query()->sole()->id)->delete();
    expect(fn()=>$f['closure']->close($f['u']->id,$i))->toThrow(DomainException::class);
});
it('T06 postCommon source drift rolls back the entire closure and releases scopes', function () {
    $f=t06CloseFixture(); $i=t06CloseInput($f); $before=t06CloseRows(); $raw=$f['row']->fresh()->getRawOriginal();
    $c=DB::connection(); $level=$c->transactionLevel(); $dispatcher=$c->getEventDispatcher(); $c->setEventDispatcher(clone $dispatcher); $hits=0;
    $c->getEventDispatcher()->listen(Illuminate\Database\Events\TransactionCommitted::class,function($e)use($f,$c,$level,&$hits){
        if($hits!==0 || $c->transactionLevel()!==$level+1 || DB::table('learning_case_closure_receipts')->count()!==1){return;}
        $common=array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),fn($x)=>($x['class']??'')===CharacterizationStateTransaction::class && ($x['function']??'')==='runForUser');
        if($common===[]){return;} $hits++; DB::table('characterizations')->where('id',$f['row']->id)->update(['status'=>'draft']);
    });
    try { expect(fn()=>$f['closure']->close($f['u']->id,$i))->toThrow(DomainException::class); } finally { $c->setEventDispatcher($dispatcher); }
    expect($hits)->toBe(1)->and(t06CloseRows())->toBe($before)->and($f['row']->fresh()->getRawOriginal())->toBe($raw)->and($c->transactionLevel())->toBe($level);
    expect($f['closure']->close($f['u']->id,$i)['status'])->toBe('closed');
});
it('T06 postCommon operational or receipt mutation rejects first close and replay atomically', function (bool $replay, string $mutation) {
    $f=t06CloseFixture(); $i=t06CloseInput($f);
    if ($replay) { $f['closure']->close($f['u']->id,$i); }
    $before=t06CloseRows(); $raw=$f['row']->fresh()->getRawOriginal();
    foreach ($mutation==='receipt' ? ['absent','incoherent','invalid_json'] : ['withdrawn'] as $variant) {
        $c=DB::connection(); $pdo=$c->getPdo(); $level=$c->transactionLevel(); $dispatcher=$c->getEventDispatcher(); $c->setEventDispatcher(clone $dispatcher); $hits=0;
        $c->getEventDispatcher()->listen(Illuminate\Database\Events\TransactionCommitted::class,function($e)use($f,$c,$level,$variant,&$hits){
            if($hits!==0 || $c->transactionLevel()!==$level+1 || DB::table('learning_case_closure_receipts')->count()!==1){return;}
            $common=array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),fn($x)=>($x['class']??'')===CharacterizationStateTransaction::class && ($x['function']??'')==='runForUser');
            if($common===[]){return;} $hits++;
            $row=DB::table('learning_case_closure_receipts')->sole();
            if ($variant==='withdrawn') { app(LearningCaseSnapshot::class)->updateOperationalStateForUser($f['u']->id,$row->learning_case_id,'withdrawn'); }
            elseif ($variant==='absent') { DB::table('learning_case_closure_receipts')->where('id',$row->id)->delete(); }
            else { DB::table('learning_case_closure_receipts')->where('id',$row->id)->update($variant==='invalid_json' ? ['receipt_text'=>'null'] : ['receipt_hash'=>str_repeat('0',64)]); }
        });
        try { expect(fn()=>$f['closure']->close($f['u']->id,$i))->toThrow(DomainException::class); }
        finally { $c->setEventDispatcher($dispatcher); }
        expect($hits)->toBe(1)->and(t06CloseRows())->toBe($before)->and($f['row']->fresh()->getRawOriginal())->toBe($raw)
            ->and($c->transactionLevel())->toBe($level)->and($c->getPdo())->toBe($pdo);
    }
    expect($f['closure']->close($f['u']->id,$i)['status'])->toBe('closed');
})->with([[false,'state'],[true,'state'],[false,'receipt'],[true,'receipt']]);
it('T06 stored P8 unknown review and inactive group are blocked without case writes', function () {
    $f=t06CloseFixture(); $i=t06CloseInput($f); $before=t06CloseRows();
    (new CharacterizationStateTransaction)->runForUser($f['u']->id,function($r){$x=$r->form_data; $x['materiality_confirmation']['universe_attestation']['reviewed_universe']=false; $r->form_data=$x; $r->save();});
    expect($f['closure']->draft($f['u']->id)['status'])->toBe('blocked');
    expect(fn()=>$f['closure']->close($f['u']->id,$i))->toThrow(DomainException::class); expect(t06CloseRows())->toBe($before);
    DB::table('learning_company_memberships')->where('id',$f['m']->id)->update(['revoked_at'=>now()]);
    expect(fn()=>$f['closure']->close($f['u']->id,$i))->toThrow(DomainException::class);
});
it('T06 API authenticates and disabled GET exposes only safe state', function () {
    app()->instance(LearningCaseClosure::class,new LearningCaseClosure);
    $this->getJson('/api/learning-case/draft')->assertUnauthorized();
    $u=User::factory()->create(); $this->actingAs($u)->getJson('/api/learning-case/draft')->assertOk()->assertJsonPath('data.status','disabled')->assertJsonPath('data.receipt',null);
    $this->postJson('/api/learning-case/close',[])->assertForbidden();
});
it('T06 API session draft close and withdraw use server actor and source DTO', function () {
    $f=t06CloseFixture(); $this->actingAs($f['u']); $i=t06CloseInput($f);
    $this->getJson('/api/learning-case/draft')->assertOk()->assertJsonPath('data.status','ready');
    $this->putJson('/api/learning-case/draft',$i)->assertOk()->assertJsonPath('data.draft.idempotency_key',$i['idempotency_key']);
    $this->postJson('/api/learning-case/close',$i)->assertOk()->assertJsonPath('data.status','closed');
    $this->postJson('/api/learning-case/withdraw',['expected_authorization_generation'=>1,'idempotency_key'=>'synthetic-revoke-api'])->assertOk()->assertJsonPath('data.status','withdrawn');
});
it('T06 API rejects raw duplicate extra typed oversized and stale commands without writes', function () {
    $f=t06CloseFixture(); $this->actingAs($f['u']); $i=t06CloseInput($f); $before=t06CloseRows();
    $this->postJson('/api/learning-case/close',$i+['actor_id'=>999])->assertUnprocessable();
    $bad=$i; $bad['expected_authorization_generation']='1'; $this->postJson('/api/learning-case/close',$bad)->assertUnprocessable();
    $raw=json_encode($i); $raw=substr($raw,0,-1).',"idempotency_\\u006bey":"other"}';
    $this->call('POST','/api/learning-case/close',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],$raw)->assertUnprocessable();
    $this->call('POST','/api/learning-case/close',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],str_repeat(' ',65537))->assertStatus(413);
    $bad=$i; $bad['source_token']=str_repeat('0',64); $this->postJson('/api/learning-case/close',$bad)->assertConflict();
    expect(t06CloseRows())->toBe($before);
});
it('T06 API retains session CSRF boundary and migration rollback up in memory', function () {
    foreach(['api.learning_case.draft','api.learning_case.save','api.learning_case.close','api.learning_case.withdraw'] as $name){$route=app('router')->getRoutes()->getByName($name);expect($route)->not->toBeNull();expect($route->gatherMiddleware())->toContain('web','auth','verified.required');}
    $f=t06CloseFixture(); $this->actingAs($f['u']);
    app()->instance('env','local'); // Force CSRF middleware to enforce, while positive domain remains disabled.
    try { $this->postJson('/api/learning-case/close',[])->assertStatus(419); } finally { app()->instance('env','testing'); }
    $migration=require database_path('migrations/2026_10_03_000013_create_learning_case_closure_receipts.php');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:'); $migration->down(); expect(Schema::hasTable('learning_case_closure_receipts'))->toBeFalse(); $migration->up(); expect(Schema::hasTable('learning_case_closure_receipts'))->toBeTrue();
});
