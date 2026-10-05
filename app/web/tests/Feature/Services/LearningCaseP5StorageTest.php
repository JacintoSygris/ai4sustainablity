<?php
use App\Models\{Characterization, LearningCase, LearningCompanyGroup, LearningCompanyMembership, LearningCaseP5Snapshot, User};
use App\Services\{LearningP5Snapshot, LearningCaseP5Storage, LearningCaseSnapshot, LearningCaseContract, LearningAuthorization, LearningAuthorizationAuthority, LearningAuthorizationLedger};
use Illuminate\Support\Facades\DB;
function t06cContext(): array
{
    $user = User::factory()->create();
    $group = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256', 't06c-synthetic')]);
    $member = LearningCompanyMembership::query()->forceCreate([
        'learning_company_group_id' => $group->id, 'user_id' => $user->id,
        'subject_type' => 'account', 'subject_identifier' => (string) $user->id,
        'evidence_digest' => hash('sha256', 't06c-membership'), 'evidence_type' => 'synthetic-only',
        'verification_status' => 'verified', 'verified_at' => now(),
    ]);
    $fake = new class implements LearningAuthorizationAuthority {
        public int $calls = 0;
        public bool $denied = false;
        public ?Closure $hook = null;
        public string $policy = 'learning-rights-v1';
        public function references(string $purpose): ?array
        {
            return $this->denied ? null : [
                'issuer_ref' => 'synthetic:issuer', 'issuer_version' => 'learning-rights-v1', 'issuer_digest' => hash('sha256', 'issuer'),
                'capability_ref' => 'synthetic:capability', 'capability_version' => 'learning-rights-v1', 'capability_digest' => hash('sha256', 'capability'),
                'policy_version' => $this->policy, 'policy_digest' => hash('sha256', $this->policy),
            ];
        }
        public function resolve(User $actor, LearningCompanyMembership $membership, string $purpose): ?array
        {
            $this->calls++;
            $refs = $this->references($purpose);
            if ($this->hook) { ($this->hook)($this); }
            return $refs === null ? null : array_merge($refs, [
                'actor_id' => $actor->id, 'group_id' => $membership->learning_company_group_id,
                'membership_id' => $membership->id, 'purpose' => $purpose, 'state' => 'available',
                'provenance' => 'synthetic-only', 'promotion_allowed' => false,
            ]);
        }
    };
    return [$user, $group, $member, $fake, new LearningAuthorizationLedger(new LearningAuthorization($fake), app(App\Services\CharacterizationStateTransaction::class))];
}
function t06cGroup(string $seed): LearningCompanyGroup {
    return LearningCompanyGroup::query()->forceCreate(['company_group_key'=>hash('sha256',$seed)]);
}

/** @param array<string, mixed> $payload */
function t06cCaseJsonFromPayload(array $payload): string
{
    unset($payload['case_hash']);
    $payload['case_hash'] = hash('sha256', t06cCanonicalJson($payload));

    return t06cEncode($payload);
}

/** @return array<string, mixed> */
function t06cCasePayload(
    string $companyGroupKey,
    string $sourceKind = 'human_product',
    ?string $sourceDigest = null,
): array {
    return [
        'schema_version' => 'learning-case-v1',
        'case_id' => 'case-t06c-001',
        'case_hash' => str_repeat('0', 64),
        'company_group_key' => $companyGroupKey,
        'period_scope' => [
            'period_key' => '2025',
            'perimeter_key' => 'entity-only',
        ],
        'authority' => [
            'framework_version' => 'esrs-2023',
            'catalog_version' => 'ar16-v1',
            'catalog_digest' => str_repeat('a', 64),
            'mapping_version' => 'ar16-python-v1',
            'mapping_digest' => str_repeat('b', 64),
        ],
        'provenance' => [
            'source_kind' => $sourceKind,
            'source_record_digest' => $sourceDigest ?? str_repeat('c', 64),
            'source_revision' => 'source-r1',
        ],
        'source_revisions' => [
            'p5' => ['generation' => 1, 'revision' => 2, 'digest' => str_repeat('d', 64)],
            'p6' => ['generation' => 2, 'revision' => 3, 'digest' => str_repeat('e', 64)],
            'p8' => ['generation' => 3, 'revision' => 4, 'digest' => str_repeat('f', 64)],
            'p9' => ['generation' => 4, 'revision' => 5, 'digest' => str_repeat('0', 64)],
        ],
        'p5_snapshot' => [
            'schema_version' => 'p5-learning-input-v1',
            'digest' => str_repeat('4', 64),
        ],
        'p6_snapshot' => [
            'model_profile' => 'candidate-profile',
            'model_digest' => str_repeat('5', 64),
            'policy_digest' => str_repeat('6', 64),
        ],
        'topic_universe' => [
            'reviewed_topic_ids' => ['101', '102'],
            'outside_scope_topic_ids' => ['103'],
        ],
        'topic_labels' => [
            ['topic_id' => '101', 'value' => 1, 'observed_mask' => 1],
            ['topic_id' => '102', 'value' => 0, 'observed_mask' => 1],
        ],
        'datapoint_universe' => [
            'reviewed_datapoint_ids' => ['E1.IRO-1_01'],
            'outside_scope_datapoint_ids' => ['E1.IRO-1_02'],
        ],
        'datapoint_decisions' => [[
            'datapoint_id' => 'E1.IRO-1_01',
            'relevant' => true,
            'selected_to_answer' => false,
            'reason_codes' => ['scope'],
            'note' => null,
        ]],
        'rights' => [
            'policy_version' => 'learning-rights-v1',
            'policy_digest' => str_repeat('7', 64),
            'policy_status' => 'approved',
            'state' => 'granted',
            'authorization_generation' => 4,
        ],
        'closure_evidence' => [
            'declaration_version' => 'technical-closure-v1',
            'declaration_status' => 'accepted',
            'reviewed_universe' => true,
            'final_for_period_scope' => true,
            'server_actor_id' => 'system:laravel',
            'recorded_at' => '2026-10-02T09:00:00Z',
        ],
    ];
}

function t06cAuthorityJson(): string
{
    return t06cEncode([
        'framework_version' => 'esrs-2023',
        'catalog_version' => 'ar16-v1',
        'catalog_digest' => str_repeat('a', 64),
        'mapping_version' => 'ar16-python-v1',
        'mapping_digest' => str_repeat('b', 64),
        'topic_ids' => ['101', '102', '103'],
        'datapoint_ids' => ['E1.IRO-1_01', 'E1.IRO-1_02'],
        'ambiguous_topic_ids' => [],
        'ambiguous_datapoint_ids' => [],
    ]);
}

function t06cCanonicalJson(mixed $value): string
{
    if (is_array($value) && ! array_is_list($value)) {
        ksort($value, SORT_STRING);
        $members = [];
        foreach ($value as $key => $member) {
            $members[] = t06cEncode((string) $key).':'.t06cCanonicalJson($member);
        }

        return '{'.implode(',', $members).'}';
    }

    if (is_array($value)) {
        return '['.implode(',', array_map(t06cCanonicalJson(...), $value)).']';
    }

    return t06cEncode($value);
}

function t06cEncode(mixed $value): string
{
    return json_encode(
        $value,
        JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_PRESERVE_ZERO_FRACTION,
    );
}

function t06cStorageContext(): array {
    [$u,$g,$m,$fake,$ledger] = t06cContext();
    $grant=$ledger->grant($u->id,$g->id,'synthetic:learning',0,hash('sha256','t06c-first'));
    $c=new Characterization;
    $c->setRawAttributes(['form_data'=>t06cEncode(['company_profile'=>['headquarters_country'=>'Spain','stock_listed'=>false],'operations'=>['employee_count_range'=>'50_249']])]);
    $p=app(LearningP5Snapshot::class)->project($c);
    $case=t06cCasePayload($g->company_group_key);
    $case['p5_snapshot']=array_intersect_key($p,array_flip(['schema_version','digest']));
    $case['closure_evidence']['server_actor_id']=(string)$u->id;
    $case['rights']['policy_digest']=hash('sha256',$fake->policy);
    $case['rights']['authorization_generation']=$grant['generation'];
    $service=new LearningCaseP5Storage(app(App\Services\CharacterizationStateTransaction::class),app(LearningCaseSnapshot::class),$ledger,app(LearningP5Snapshot::class));
    return [$u,$g,$m,$fake,$ledger,$service,$p,$case];
}
function t06cStore(array $x): LearningCase {
    [$u,$g,,,$l,$s,$p,$case]=$x;
    return $s->createForAccount($u->id,$g->id,'synthetic:learning',t06cCaseJsonFromPayload($case),t06cAuthorityJson(),$p);
}
it('T06c vertical actual values remain private and case stays stored',function(){
    $x=t06cStorageContext(); $case=t06cStore($x);
    $row=LearningCaseP5Snapshot::query()->sole();
    expect(json_decode($row->values_text,true))->toBe($x[6]['values'])
      ->and($row->learning_case_id)->toBe($case->id)
      ->and(json_decode($case->payload_text,true)['p5_snapshot'])->toBe(['digest'=>$x[6]['digest'],'schema_version'=>$x[6]['schema_version']])
      ->and($case->state->status)->toBe('stored')->and($case->state->state_version)->toBe(0);
});


it('T06c strict projection shape and digest reject without writes', function (string $mutation) {
    $x=t06cStorageContext();
    switch($mutation) {
        case 'extra': $x[6]['private_notes']='secret'; break;
        case 'missing': unset($x[6]['values']['stock_listed']); break;
        case 'list': $x[6]['values']=array_values($x[6]['values']); break;
        case 'value-extra': $x[6]['values']['notes']='private'; break;
        case 'stock-type': $x[6]['values']['stock_listed']=0; break;
        case 'range-type': $x[6]['values']['employee_count_range']=150; break;
        case 'country': $x[6]['values']['headquarters_country']='ES'; break;
        case 'schema': $x[6]['schema_version']='v2'; break;
        case 'digest': $x[6]['digest']=str_repeat('a',64); break;
        case 'newline': $x[6]['digest'].="\n"; break;
        case 'uppercase': $x[6]['digest']=strtoupper($x[6]['digest']); break;
        case 'public': $x[7]['p5_snapshot']['digest']=str_repeat('a',64); break;
    }
    expect(fn()=>t06cStore($x))->toThrow(InvalidArgumentException::class);
    expect(LearningCase::count())->toBe(0)->and(DB::table('learning_case_states')->count())->toBe(0)->and(LearningCaseP5Snapshot::count())->toBe(0);
})->with(['extra','missing','list','value-extra','stock-type','range-type','country','schema','digest','newline','uppercase','public']);
it('T06c rejects scalar coercion at the internal boundary', function(string $which,mixed $value){
    $x=t06cStorageContext(); [$u,$g,,,,$s,$p,$case]=$x;
    $args=[$u->id,$g->id,'synthetic:learning',t06cCaseJsonFromPayload($case),t06cAuthorityJson(),$p];
    $args[['actor'=>0,'group'=>1,'purpose'=>2,'case'=>3,'authority'=>4,'projection'=>5][$which]]=$value;
    expect(fn()=>$s->createForAccount(...$args))->toThrow(InvalidArgumentException::class);
    expect(LearningCase::count())->toBe(0);
})->with([['actor','1'],['actor',true],['actor',1.0],['actor',0],['group','1'],['group',0],['purpose',1],['purpose',"synthetic:learning\n"],['purpose',' bad'],['case',[]],['authority',[]],['projection',null]]);
it('T06c binds actor and current grant policy and generation',function(string $mutation){
    $x=t06cStorageContext();
    match($mutation){
      'actor'=>$x[7]['closure_evidence']['server_actor_id']='999999',
      'policy'=>$x[7]['rights']['policy_version']='other',
      'policy-digest'=>$x[7]['rights']['policy_digest']=str_repeat('a',64),
      'generation'=>$x[7]['rights']['authorization_generation']=2,
    };
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(LearningCase::count())->toBe(0)->and(LearningCaseP5Snapshot::count())->toBe(0);
})->with(['actor','policy','policy-digest','generation']);

function t06cBytes(): array {
    return array_map(fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),['learning_cases','learning_case_states','learning_case_p5_snapshots','learning_authorization_states','learning_authorization_records']);
}
it('T06c rejects denied raw claims and changed current scope',function(string $mutation){
    $x=t06cStorageContext(); [$u,$g,$m,$f,$l]=$x;
    switch($mutation){
      case 'binding': $x[5]=app(LearningCaseP5Storage::class); break;
      case 'denied': $f->denied=true; break;
      case 'revoked': $l->revoke($u->id,$g->id,'synthetic:learning',1,hash('sha256','revoke')); break;
      case 'deleted': $l->tombstone($u->id,$g->id,'synthetic:learning',1,hash('sha256','delete')); break;
      case 'membership': $m->forceFill(['revoked_at'=>now()])->save(); break;
      case 'email': DB::table('users')->where('id',$u->id)->update(['email_verified_at'=>null]); break;
      case 'othergroup': $x[1]=t06cGroup('other'); break;
      case 'otheractor': $x[0]=User::factory()->create(); break;
      case 'policy': $f->policy='other'; break;
      case 'newgrant': $l->grant($u->id,$g->id,'synthetic:learning',1,hash('sha256','grant2')); break;
    }
    $before=t06cBytes();
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(t06cBytes())->toBe($before);
})->with(['binding','denied','revoked','deleted','membership','email','othergroup','otheractor','policy','newgrant']);
it('T06c exact replay keeps every byte timestamp and generation',function(){
    $x=t06cStorageContext(); $c=t06cStore($x); $before=t06cBytes();
    $this->travel(3)->minutes(); $r=t06cStore($x); $this->travelBack();
    expect($r->id)->toBe($c->id)->and(t06cBytes())->toBe($before);
});
it('T06c changed case identity or source cannot replace stored values',function(string $field){
    $x=t06cStorageContext(); t06cStore($x); $before=t06cBytes();
    switch($field){
      case 'case': $x[7]['case_id']='changed'; break;
      case 'source': $x[7]['provenance']['source_record_digest']=hash('sha256','changed'); break;
      case 'projection': $x[6]['values']['stock_listed']=true; $x[6]['digest']=hash('sha256',t06cEncode($x[6]['values'])); $x[7]['p5_snapshot']['digest']=$x[6]['digest']; break;
    }
    expect(fn()=>t06cStore($x))->toThrow(InvalidArgumentException::class);
    expect(t06cBytes())->toBe($before);
})->with(['case','source','projection']);
it('T06c reconciles corrupt actual persisted values and identity',function(string $field,mixed $value){
    $x=t06cStorageContext(); t06cStore($x);
    DB::table('learning_case_p5_snapshots')->update([$field=>$value]); $before=t06cBytes();
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(t06cBytes())->toBe($before);
})->with([
 ['values_text','{'],['values_text','{}'],['values_text','{"employee_count_range":"50_249","headquarters_country":"Spain","stock_listed":true}'],
 ['values_text','{ "employee_count_range":"50_249","headquarters_country":"Spain","stock_listed":false}'],
 ['digest',str_repeat('a',64)],['schema_version','bad'],['actor_id',999999],['group_id',999999],['purpose','other'],
 ['authorization_digest',str_repeat('a',64)],['authorization_generation',2],['feature_schema_version','bad'],['transform_version','bad'],
]);
it('T06c legacy matching passive case can fill but advanced case cannot',function(bool $advanced){
    $x=t06cStorageContext(); [$u,$g,,,,$s,$p,$case]=$x;
    $c=app(LearningCaseSnapshot::class)->createForAccount($u->id,t06cCaseJsonFromPayload($case),t06cAuthorityJson());
    if($advanced){ DB::table('learning_case_states')->update(['state_version'=>1]); }
    if($advanced){ expect(fn()=>t06cStore($x))->toThrow(DomainException::class); expect(LearningCaseP5Snapshot::count())->toBe(0); }
    else { expect(t06cStore($x)->id)->toBe($c->id)->and(LearningCaseP5Snapshot::count())->toBe(1); }
})->with([false,true]);
it('T06c SQL failure before or after private insert rolls back the entire birth',function(string $timing){
    $x=t06cStorageContext(); $before=t06cBytes();
    DB::unprepared('CREATE TRIGGER t06c_fault '.$timing.' INSERT ON learning_case_p5_snapshots BEGIN SELECT RAISE(ABORT, "t06c fault"); END');
    expect(fn()=>t06cStore($x))->toThrow(Illuminate\Database\QueryException::class);
    expect(t06cBytes())->toBe($before);
})->with(['BEFORE','AFTER']);
it('T06c late authority drift before writes or commit rolls back fresh birth and replay',function(int $call,bool $replay,string $drift){
    $x=t06cStorageContext(); if($replay){t06cStore($x);}
    [$u,$g,$m,$f,$l]=$x; $before=t06cBytes(); $f->calls=0;
    $f->hook=function($fake)use($call,$replay,$drift,$m,$u,$g,$l){
       $scheduled = $call === 4 && $replay ? 3 : $call;
       if($fake->calls!==$scheduled){return;}
       $fake->hook=null;
       match($drift){
        'policy'=>$fake->policy='other',
        'membership'=>$m->forceFill(['revoked_at'=>now()])->save(),
        'revoke'=>$l->revoke($u->id,$g->id,'synthetic:learning',1,hash('sha256','late-revoke')),
        'newgrant'=>$l->grant($u->id,$g->id,'synthetic:learning',1,hash('sha256','late-grant')),
       };
    };
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(t06cBytes())->toBe($before);
})->with([2,3,4])->with([false,true])->with(['policy','membership','revoke','newgrant']);
it('T06c ordinary UPDATE guard matrix preserves row and attributes',function(string $op){
    $x=t06cStorageContext(); t06cStore($x); $model=LearningCaseP5Snapshot::sole();
    $q=LearningCaseP5Snapshot::query()->whereKey($model->id); $before=t06cBytes();
    $extra=['values_text'=>'{}']; $row=(array)DB::table($model->getTable())->first();
    $target=str_starts_with($op,'new-')?new LearningCaseP5Snapshot:$model; $attributes=$target->getAttributes();
    $error=null;
    try {match($op){
      'update'=>$q->update($extra), 'save'=>$model->forceFill($extra)->save(), 'quiet'=>$model->forceFill($extra)->saveQuietly(),
      'updateOrInsert'=>$q->updateOrInsert(['id'=>$model->id],$extra), 'upsert'=>$q->upsert([array_replace($row,$extra)],['id'],['values_text']),
      'updateFrom'=>$q->updateFrom($extra),'incrementEach'=>$q->incrementEach(['id'=>0],$extra),'decrementEach'=>$q->decrementEach(['id'=>0],$extra),
      'mixed'=>$q->InCrEmEnTeAcH(['id'=>0],$extra),'touch'=>$q->touch('created_at'),
      'builder-increment'=>$q->increment('id',0,$extra),'builder-decrement'=>$q->decrement('id',0,$extra),
      'static-increment'=>LearningCaseP5Snapshot::increment('id',0,$extra),'static-decrement'=>LearningCaseP5Snapshot::decrement('id',0,$extra),
      'static-incrementQuietly'=>LearningCaseP5Snapshot::incrementQuietly('id',0,$extra),'static-decrementQuietly'=>LearningCaseP5Snapshot::decrementQuietly('id',0,$extra),
      default=>$target->{str_replace(['new-','instance-'],'',$op)}('id',0,$extra),
    };}catch(Throwable $e){$error=[$e::class,$e->getMessage()];}
    expect($error)->toBe([LogicException::class,'learning_p5.snapshot_immutable'])->and(t06cBytes())->toBe($before);
    if(!in_array($op,['save','quiet'],true)){expect($target->getAttributes())->toBe($attributes);}
})->with(['update','save','quiet','updateOrInsert','upsert','updateFrom','incrementEach','decrementEach','mixed','touch','builder-increment','builder-decrement',
'instance-increment','instance-decrement','instance-incrementQuietly','instance-decrementQuietly','static-increment','static-decrement','static-incrementQuietly','static-decrementQuietly',
'new-increment','new-decrement','new-incrementQuietly','new-decrementQuietly']);

it('T06c preserves three explicit nulls without inferring negatives',function(){
    $x=t06cStorageContext(); $p=app(LearningP5Snapshot::class)->project(new Characterization);
    $x[6]=$p; $x[7]['p5_snapshot']=array_intersect_key($p,array_flip(['schema_version','digest']));
    t06cStore($x);
    expect(json_decode(LearningCaseP5Snapshot::sole()->values_text,true))->toBe(['employee_count_range'=>null,'headquarters_country'=>null,'stock_listed'=>null]);
});

it('T06c R1 completed missing snapshot denies rather than recreates', function () {
    $x=t06cStorageContext(); t06cStore($x);
    LearningCaseP5Snapshot::sole()->delete(); $before=t06cBytes();
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(t06cBytes())->toBe($before)->and(LearningCaseP5Snapshot::count())->toBe(0);
});
it('T06c R1 rejects lossy persisted numeric identities', function (string $field, mixed $value) {
    $x=t06cStorageContext(); t06cStore($x);
    DB::table('learning_case_p5_snapshots')->update([$field=>$value]); $before=t06cBytes();
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(t06cBytes())->toBe($before);
})->with(['actor_id','group_id','authorization_generation'])->with(['1oops',1.5]);
function t06cR1Bytes(): array {
    return [...t06cBytes(), DB::table('learning_case_p5_storage_receipts')->orderBy('learning_case_id')->get()->toJson()];
}
it('T06c R1 marker siblings deny and preserve all stores', function (string $fault) {
    $x=t06cStorageContext(); $c=t06cStore($x);
    switch ($fault) {
        case 'marker-only': LearningCaseP5Snapshot::sole()->delete(); break;
        case 'value-only': DB::table('learning_case_p5_storage_receipts')->delete(); break;
        case 'wrongrow': DB::table('learning_case_p5_storage_receipts')->update(['snapshot_id'=>999]); break;
        case 'replacementrow':
            $raw=LearningCaseP5Snapshot::sole()->getRawOriginal();
            LearningCaseP5Snapshot::sole()->delete(); unset($raw['id']);
            LearningCaseP5Snapshot::query()->forceCreate($raw); break;
        case 'wrongcase':
            $other=$x; $other[7]['period_scope']['period_key']='2024'; $other[7]['case_id']='other';
            $parent=app(LearningCaseSnapshot::class)->createForAccount($x[0]->id,t06cCaseJsonFromPayload($other[7]),t06cAuthorityJson());
            DB::table('learning_case_p5_storage_receipts')->update(['learning_case_id'=>$parent->id]); break;
        case 'invalidref': DB::table('learning_case_p5_storage_receipts')->update(['completion_reference'=>'bad']); break;
        case 'modifiedref': DB::table('learning_case_p5_storage_receipts')->update(['completion_reference'=>str_repeat('a',64)]); break;
        case 'corrupteddate': DB::table('learning_case_p5_snapshots')->update(['created_at'=>'not-a-date']); break;
        case 'normalizeddate': DB::table('learning_case_p5_snapshots')->update(['created_at'=>'2026-02-30 01:02:03']); break;
        case 'modifieddate': DB::table('learning_case_p5_snapshots')->update(['created_at'=>'2020-01-01 00:00:00']); break;
        case 'receipt-suffix': DB::table('learning_case_p5_storage_receipts')->update(['snapshot_id'=>'1oops']); break;
        case 'receipt-fraction': DB::table('learning_case_p5_storage_receipts')->update(['snapshot_id'=>1.5]); break;
        case 'receipt-newline': DB::table('learning_case_p5_storage_receipts')->update(['snapshot_id'=>"1oops\n"]); break;
        case 'receipt-overflow': DB::table('learning_case_p5_storage_receipts')->update(['snapshot_id'=>'9223372036854775808']); break;
    }
    $before=t06cR1Bytes();
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(t06cR1Bytes())->toBe($before)->and($c->fresh()->state->state_version)->toBe(0);
})->with(['marker-only','value-only','wrongrow','replacementrow','wrongcase','invalidref','modifiedref','corrupteddate','normalizeddate','modifieddate','receipt-suffix','receipt-fraction','receipt-newline','receipt-overflow']);
it('T06c R1 receipt faults roll back birth and retain history', function (string $timing) {
    $x=t06cStorageContext(); $before=t06cR1Bytes();
    DB::unprepared('CREATE TRIGGER t06c_r1_fault '.$timing.' INSERT ON learning_case_p5_storage_receipts BEGIN SELECT RAISE(ABORT, "receipt fault"); END');
    expect(fn()=>t06cStore($x))->toThrow(Illuminate\Database\QueryException::class);
    expect(t06cR1Bytes())->toBe($before)->and(LearningCase::count())->toBe(0)->and(DB::table('learning_case_states')->count())->toBe(0)
        ->and(LearningCaseP5Snapshot::count())->toBe(0)->and(DB::table('learning_case_p5_storage_receipts')->count())->toBe(0);
})->with(['BEFORE','AFTER']);
it('T06c R1 readback rejects receipt and snapshot corruption at birth', function (string $field) {
    $x=t06cStorageContext(); $before=t06cR1Bytes();
    $sql=match($field){
        'reference'=>"UPDATE learning_case_p5_storage_receipts SET completion_reference='bad';",
        'date'=>"UPDATE learning_case_p5_snapshots SET created_at='bad';",
        'actor'=>"UPDATE learning_case_p5_snapshots SET actor_id='1oops';",
    };
    DB::unprepared('CREATE TRIGGER t06c_r1_corrupt AFTER INSERT ON learning_case_p5_storage_receipts BEGIN '.$sql.' END');
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(t06cR1Bytes())->toBe($before);
})->with(['reference','date','actor']);
it('T06c R1 legacy first fill and exact replay preserve completion bytes', function () {
    $x=t06cStorageContext(); $case=app(LearningCaseSnapshot::class)->createForAccount($x[0]->id,t06cCaseJsonFromPayload($x[7]),t06cAuthorityJson());
    expect(DB::table('learning_case_p5_storage_receipts')->count())->toBe(0);
    expect(t06cStore($x)->id)->toBe($case->id);
    $before=t06cR1Bytes(); $this->travel(5)->minutes(); t06cStore($x); $this->travelBack();
    expect(t06cR1Bytes())->toBe($before)->and(LearningCaseP5Snapshot::count())->toBe(1)
        ->and(DB::table('learning_case_p5_storage_receipts')->count())->toBe(1)->and($case->fresh()->state->state_version)->toBe(0);
});
it('T06c R1 issuer-only late reference drift denies birth and replay', function (int $call, bool $replay) {
    $x=t06cStorageContext(); $base=$x[3];
    $authority=new class($base) implements LearningAuthorizationAuthority {
        public string $issuer='synthetic:issuer';
        public function __construct(public object $base) {}
        public function references(string $purpose): ?array { return array_replace($this->base->references($purpose),['issuer_ref'=>$this->issuer]); }
        public function resolve(User $actor, LearningCompanyMembership $membership, string $purpose): ?array {
            $refs=$this->references($purpose);
            return array_replace($this->base->resolve($actor,$membership,$purpose),$refs);
        }
    };
    $ledger=new LearningAuthorizationLedger(new LearningAuthorization($authority),app(App\Services\CharacterizationStateTransaction::class));
    $x[5]=new LearningCaseP5Storage(app(App\Services\CharacterizationStateTransaction::class),app(LearningCaseSnapshot::class),$ledger,app(LearningP5Snapshot::class));
    if($replay){t06cStore($x);} $base->calls=0; $before=t06cR1Bytes();
    $base->hook=function($fake)use($authority,$call,$replay){
        if($fake->calls===($replay && $call===4 ? 3 : $call)){ $authority->issuer='synthetic:changed-issuer'; }
    };
    expect(fn()=>t06cStore($x))->toThrow(DomainException::class);
    expect(t06cR1Bytes())->toBe($before);
})->with([2,3,4])->with([false,true]);
it('T06c R1 raw numeric boundary rejects missing null noncanonical and overflowing values', function (string $field, mixed $bad) {
    $x=t06cStorageContext(); t06cStore($x); $row=LearningCaseP5Snapshot::sole(); $raw=$row->getRawOriginal();
    $expected=array_diff_key($raw,array_flip(['id','created_at']));
    if($bad==='missing'){unset($raw[$field]);}else{$raw[$field]=$bad;}
    $row->setRawAttributes($raw,true);
    $method=new ReflectionMethod(LearningCaseP5Storage::class,'assertStored');
    expect(fn()=>$method->invoke($x[5],$row,$expected))->toThrow(DomainException::class,'learning_p5.persisted_incoherent');
})->with(['id','learning_case_id','actor_id','group_id','authorization_generation'])->with(['missing',null,'1oops',"1\n",1.5,'9223372036854775808']);

it('T06c R1 exact native decimal raw representation remains admissible', function () {
    $x=t06cStorageContext(); t06cStore($x); $row=LearningCaseP5Snapshot::sole(); $raw=$row->getRawOriginal();
    $expected=array_diff_key($raw,array_flip(['id','created_at']));
    foreach(['id','learning_case_id','actor_id','group_id','authorization_generation'] as $field){$raw[$field]=(string)$raw[$field];}
    $row->setRawAttributes($raw,true);
    (new ReflectionMethod(LearningCaseP5Storage::class,'assertStored'))->invoke($x[5],$row,$expected);
    (new ReflectionMethod(LearningCaseP5Storage::class,'assertRawInteger'))->invoke($x[5],'1',1);
    expect(true)->toBeTrue();
});
it('T06c R1 receipt numeric raw boundary denies corruption', function (mixed $raw) {
    $x=t06cStorageContext(); $method=new ReflectionMethod(LearningCaseP5Storage::class,'assertRawInteger');
    expect(fn()=>$method->invoke($x[5],$raw,1))->toThrow(DomainException::class,'learning_p5.persisted_incoherent');
})->with([null,'1oops',"1\n",1.5,'9223372036854775808','01','+1','1.0',' 1']);
it('T06c R1 malformed raw dates have a stable domain failure', function (mixed $date) {
    $x=t06cStorageContext(); t06cStore($x); $row=LearningCaseP5Snapshot::sole(); $raw=$row->getRawOriginal();
    $expected=array_diff_key($raw,array_flip(['id','created_at']));
    if($date==='missing'){unset($raw['created_at']);}else{$raw['created_at']=$date;}
    $row->setRawAttributes($raw,true);
    expect(fn()=>(new ReflectionMethod(LearningCaseP5Storage::class,'assertStored'))->invoke($x[5],$row,$expected))
        ->toThrow(DomainException::class,'learning_p5.persisted_incoherent');
})->with(['missing',null,'bad',"2026-10-03 01:02:03\0",'2026-02-30 01:02:03']);
it('T06c R1 receipt follows parent cascade without blocking snapshot deletion', function () {
    $x=t06cStorageContext(); $case=t06cStore($x); LearningCaseP5Snapshot::sole()->delete();
    expect(DB::table('learning_case_p5_storage_receipts')->count())->toBe(1);
    DB::table('learning_cases')->where('id',$case->id)->delete();
    expect(DB::table('learning_case_p5_storage_receipts')->count())->toBe(0);
});