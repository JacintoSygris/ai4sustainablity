<?php
use App\Models\{Characterization, LearningCase, LearningCompanyGroup, LearningCompanyMembership, LearningCaseP5Snapshot, User};
use App\Services\{LearningP5Snapshot, LearningCaseP5Storage, LearningCaseSnapshot, LearningCaseContract, LearningAuthorization, LearningAuthorizationAuthority, LearningAuthorizationLedger};
use Illuminate\Support\Facades\DB;
function t06lContext(): array
{
    $user = User::factory()->create();
    $group = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256', 't06l-synthetic')]);
    $member = LearningCompanyMembership::query()->forceCreate([
        'learning_company_group_id' => $group->id, 'user_id' => $user->id,
        'subject_type' => 'account', 'subject_identifier' => (string) $user->id,
        'evidence_digest' => hash('sha256', 't06l-membership'), 'evidence_type' => 'synthetic-only',
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

/** @param array<string, mixed> $payload */
function t06lCaseJsonFromPayload(array $payload): string
{
    unset($payload['case_hash']);
    $payload['case_hash'] = hash('sha256', t06lCanonicalJson($payload));

    return t06lEncode($payload);
}

/** @return array<string, mixed> */
function t06lCasePayload(
    string $companyGroupKey,
    string $sourceKind = 'human_product',
    ?string $sourceDigest = null,
): array {
    return [
        'schema_version' => 'learning-case-v1',
        'case_id' => 'case-t06l-001',
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

function t06lAuthorityJson(): string
{
    return t06lEncode([
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

function t06lCanonicalJson(mixed $value): string
{
    if (is_array($value) && ! array_is_list($value)) {
        ksort($value, SORT_STRING);
        $members = [];
        foreach ($value as $key => $member) {
            $members[] = t06lEncode((string) $key).':'.t06lCanonicalJson($member);
        }

        return '{'.implode(',', $members).'}';
    }

    if (is_array($value)) {
        return '['.implode(',', array_map(t06lCanonicalJson(...), $value)).']';
    }

    return t06lEncode($value);
}

function t06lEncode(mixed $value): string
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

it('T06l schema fresh has nullable private source header', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('learning_case_p5_storage_receipts', 'source_header_text'))->toBeTrue();
});

function t06lLive(): array {
    config(['services.learning_p5_live_capture.enabled'=>true, 'services.learning_source_clock.enabled'=>true,
        'services.learning_p6_base_source_clock.enabled'=>false, 'services.learning_p8_source_clock.enabled'=>false,
        'services.learning_p9_source_clock.enabled'=>false]);
    [$u,$g,$m,$fake,$ledger]=t06lContext();
    $grant=$ledger->grant($u->id,$g->id,'synthetic:learning',0,hash('sha256','t06l-first'));
    app(App\Services\CharacterizationStateTransaction::class)->runForUser($u->id, function () use ($u) {
        Characterization::query()->forceCreate(['user_id'=>$u->id,'submission_generation'=>1,'form_data'=>[
            'company_profile'=>['headquarters_country'=>null,'stock_listed'=>false,'name'=>'PII_CANARY'],
            'operations'=>['employee_count_range'=>'not_sure','notes'=>'NOTE_CANARY'],
            'materiality'=>['secret'=>'P8_CANARY']]]);
    });
    $source=Characterization::query()->sole();
    $projection=app(LearningP5Snapshot::class)->project($source);
    $header=app(App\Services\LearningP5SourceRevisionClock::class)->current($source->id);
    $case=t06lCasePayload($g->company_group_key);
    $case['source_revisions']['p5']=array_intersect_key($header,array_flip(['generation','revision','digest']));
    $case['p5_snapshot']=array_intersect_key($projection,array_flip(['schema_version','digest']));
    $case['closure_evidence']['server_actor_id']=(string)$u->id;
    $case['rights']['policy_digest']=hash('sha256',$fake->policy);
    $case['rights']['authorization_generation']=$grant['generation'];
    $service=new LearningCaseP5Storage(app(App\Services\CharacterizationStateTransaction::class),app(LearningCaseSnapshot::class),$ledger,app(LearningP5Snapshot::class));
    return [$u,$g,$fake,$service,$case,$source,$header,$projection];
}
function t06lCapture(array $x): LearningCase {
    return $x[3]->captureForAccount($x[0]->id,$x[1]->id,'synthetic:learning',t06lCaseJsonFromPayload($x[4]),t06lAuthorityJson());
}
it('T06l captures fresh P5 only and exact replay preserves private bytes', function () {
    $x=t06lLive(); $case=t06lCapture($x);
    $row=LearningCaseP5Snapshot::query()->sole()->fresh();
    $receipt=DB::table('learning_case_p5_storage_receipts')->sole();
    expect(json_decode($row->values_text,true))->toBe($x[7]['values'])
        ->and($row->values_text)->not->toContain('CANARY')
        ->and($receipt->source_header_text)->toBe(t06lEncode($x[6]))
        ->and($x[6]['digest'])->not->toBe($row->digest)
        ->and($case->state->status)->toBe('stored')->and($case->state->state_version)->toBe(0);
    $fields=array_diff_key($row->getRawOriginal(),array_flip(['id','created_at']));
    $fields=array_intersect_key($fields,array_flip(['learning_case_id','values_text','schema_version','digest','feature_schema_version','transform_version','actor_id','group_id','purpose','authorization_digest','authorization_generation']));
    expect($receipt->completion_reference)->toBe(hash('sha256', "learning-p5-live-capture-completion-v1\0".t06lEncode([
        'source_header_text'=>$receipt->source_header_text, 'snapshot_id'=>$row->id, 'fields'=>$fields,
        'created_at'=>$row->getRawOriginal('created_at'),
    ])));
    $before=$row->getRawOriginal();
    $again=t06lCapture($x);
    expect($again->id)->toBe($case->id)->and($again->payload_text)->toBe($case->payload_text)
        ->and($row->fresh()->getRawOriginal())->toBe($before)
        ->and((array)DB::table('learning_case_p5_storage_receipts')->sole())->toBe((array)$receipt);
});

it('T06l upgrade via normal migrator preserves real legacy receipt as NULL', function () {
    $path='database/migrations/2026_10_03_000012_add_source_header_to_learning_p5_storage_receipts.php';
    $original=database_path('migrations/2026_10_02_000008_create_learning_case_p5_snapshots.php');
    $hash=hash_file('sha256',$original);
    $migration=pathinfo($path, PATHINFO_FILENAME);
    $batch=DB::table('migrations')->where('migration',$migration)->sole()->batch;
    $closureMigration='2026_10_03_000013_create_learning_case_closure_receipts';
    $closureBefore=(array)DB::table('migrations')->where('migration',$closureMigration)->sole();
    $closureTables=['learning_case_closure_receipts','learning_case_closure_drafts'];
    $closureBeforeTables=[];
    foreach ($closureTables as $table) {
        expect(\Illuminate\Support\Facades\Schema::hasTable($table))->toBeTrue();
        $closureBeforeTables[$table]=[\Illuminate\Support\Facades\Schema::getColumnListing($table),DB::table($table)->get()->map(fn($row)=>(array)$row)->all()];
    }
    // Migrator selects the recorded batch before applying the exact path filter.
    $this->artisan('migrate:rollback',['--path'=>[$path],'--batch'=>$batch,'--force'=>true])->assertExitCode(0);
    expect(DB::table('migrations')->where('migration',$migration)->exists())->toBeFalse();
    expect((array)DB::table('migrations')->where('migration',$closureMigration)->sole())->toBe($closureBefore);
    foreach ($closureTables as $table) {
        expect(\Illuminate\Support\Facades\Schema::hasTable($table))->toBeTrue();
        expect([\Illuminate\Support\Facades\Schema::getColumnListing($table),DB::table($table)->get()->map(fn($row)=>(array)$row)->all()])->toBe($closureBeforeTables[$table]);
    }

    expect(\Illuminate\Support\Facades\Schema::hasColumn('learning_case_p5_storage_receipts','source_header_text'))->toBeFalse();
    $x=t06lLive();
    $case=$x[3]->createForAccount($x[0]->id,$x[1]->id,'synthetic:learning',t06lCaseJsonFromPayload($x[4]),t06lAuthorityJson(),$x[7]);
    $before=(array)DB::table('learning_case_p5_storage_receipts')->sole();
    $this->artisan('migrate',['--path'=>[$path],'--force'=>true])->assertExitCode(0);
    expect(\Illuminate\Support\Facades\Schema::hasColumn('learning_case_p5_storage_receipts','source_header_text'))->toBeTrue();
    expect((array)DB::table('migrations')->where('migration',$closureMigration)->sole())->toBe($closureBefore);
    foreach ($closureTables as $table) {
        expect(\Illuminate\Support\Facades\Schema::hasTable($table))->toBeTrue();
        expect([\Illuminate\Support\Facades\Schema::getColumnListing($table),DB::table($table)->get()->map(fn($row)=>(array)$row)->all()])->toBe($closureBeforeTables[$table]);
    }
    $after=(array)DB::table('learning_case_p5_storage_receipts')->sole();
    expect($after['source_header_text'])->toBeNull(); unset($after['source_header_text']);
    expect($after)->toBe($before)->and(hash_file('sha256',$original))->toBe($hash);
    expect(fn()=>t06lCapture($x))->toThrow(DomainException::class);
    expect(LearningCase::query()->sole()->id)->toBe($case->id)
        ->and(DB::table('learning_case_p5_storage_receipts')->sole()->source_header_text)->toBeNull();
});
it('T06l rejects stale source or public references without creating storage', function (string $kind) {
    $x=t06lLive();
    if ($kind==='absent') { DB::table('learning_p5_source_revisions')->where('characterization_id',$x[5]->id)->delete(); }
    if ($kind==='reference') { $x[4]['source_revisions']['p5']['revision']++; }
    if ($kind==='digest') { $x[4]['p5_snapshot']['digest']=str_repeat('8',64); }
    if ($kind==='drift') { DB::table('characterizations')->where('id',$x[5]->id)->update(['nace_code'=>'synthetic-drift']); }
    expect(fn()=>t06lCapture($x))->toThrow(DomainException::class);
    expect(LearningCase::query()->count())->toBe(0)->and(LearningCaseP5Snapshot::query()->count())->toBe(0)
        ->and(DB::table('learning_case_p5_storage_receipts')->count())->toBe(0);
})->with(['absent','reference','digest','drift']);
it('T06l historical storage survives advance and ABA but old case cannot replay', function (bool $aba) {
    $x=t06lLive(); t06lCapture($x);
    $row=LearningCaseP5Snapshot::query()->sole()->getRawOriginal();
    $receipt=(array)DB::table('learning_case_p5_storage_receipts')->sole();
    $mutate=function ($form) use ($x) {
        app(App\Services\CharacterizationStateTransaction::class)->runForUser($x[0]->id,function ($source) use ($form) {
            $source->forceFill(['form_data'=>$form])->save();
        });
    };
    $form=$x[5]->form_data; $changed=$form; $changed['company_profile']['stock_listed']=true;
    $mutate($changed); if ($aba) { $mutate($form); }
    expect(fn()=>t06lCapture($x))->toThrow(DomainException::class);
    expect(LearningCaseP5Snapshot::query()->sole()->getRawOriginal())->toBe($row)
        ->and((array)DB::table('learning_case_p5_storage_receipts')->sole())->toBe($receipt);
})->with([false,true]);
it('T06l tampering is rejected without repairing stored rows', function (string $kind) {
    $x=t06lLive(); t06lCapture($x);
    if ($kind==='values') { DB::table('learning_case_p5_snapshots')->update(['values_text'=>'{}']); }
    if ($kind==='metadata') { DB::table('learning_case_p5_snapshots')->update(['purpose'=>'synthetic:other']); }
    if ($kind==='receipt') { DB::table('learning_case_p5_storage_receipts')->update(['snapshot_id'=>999]); }
    if ($kind==='header') { DB::table('learning_case_p5_storage_receipts')->update(['source_header_text'=>t06lEncode(array_merge($x[6],['epoch'=>str_repeat('9',64)]))]); }
    if ($kind==='reference') { DB::table('learning_case_p5_storage_receipts')->update(['completion_reference'=>str_repeat('9',64)]); }
    if ($kind==='null') { DB::table('learning_case_p5_storage_receipts')->update(['source_header_text'=>null]); }
    $row=LearningCaseP5Snapshot::query()->sole()->getRawOriginal();
    $receipt=(array)DB::table('learning_case_p5_storage_receipts')->sole();
    expect(fn()=>t06lCapture($x))->toThrow(DomainException::class);
    expect(LearningCaseP5Snapshot::query()->sole()->getRawOriginal())->toBe($row)
        ->and((array)DB::table('learning_case_p5_storage_receipts')->sole())->toBe($receipt);
})->with(['values','metadata','receipt','header','reference','null']);
it('T06l persistence SQL failure rolls back case state values and receipt', function () {
    $x=t06lLive();
    DB::statement("CREATE TEMP TRIGGER t06l_fail BEFORE INSERT ON learning_case_p5_storage_receipts BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
    expect(fn()=>t06lCapture($x))->toThrow(\Illuminate\Database\QueryException::class);
    expect(LearningCase::query()->count())->toBe(0)->and(DB::table('learning_case_states')->count())->toBe(0)
        ->and(LearningCaseP5Snapshot::query()->count())->toBe(0)->and(DB::table('learning_case_p5_storage_receipts')->count())->toBe(0);
    DB::statement('DROP TRIGGER t06l_fail');
});
it('T06l P5 mutation during birth or replay rolls back own boundary', function (bool $replay) {
    $x=t06lLive(); if ($replay) { t06lCapture($x); }
    $before=DB::table('learning_case_p5_snapshots')->get()->toArray();
    $original=$x[5]->getRawOriginal('form_data');
    if (! $replay) {
        DB::statement("CREATE TEMP TRIGGER t06l_drift AFTER INSERT ON learning_case_p5_storage_receipts BEGIN UPDATE characterizations SET form_data='{}'; END");
    } else {
        $armed=true;
        DB::connection()->beforeExecuting(function ($query) use ($x, &$armed) {
            if ($armed && str_contains($query, 'learning_case_p5_storage_receipts')) {
                $armed=false;
                DB::table('characterizations')->where('id',$x[5]->id)->update(['form_data'=>'{}']);
            }
        });
    }
    expect(fn()=>t06lCapture($x))->toThrow(DomainException::class);
    expect(DB::table('characterizations')->where('id',$x[5]->id)->value('form_data'))->toBe($original)
        ->and(DB::table('learning_case_p5_snapshots')->get()->toArray())->toEqual($before)
        ->and(LearningCase::query()->count())->toBe($replay ? 1 : 0);
    if (! $replay) { DB::statement('DROP TRIGGER t06l_drift'); }
})->with([false,true]);
it('T06l guards before queries PDO or issuer', function (string $kind) {
    $x=t06lLive(); $calls=$x[2]->calls;
    $connection=DB::connection(); $pdo=$connection->getRawPdo();
    $queries=0; $connection->beforeExecuting(function () use (&$queries) { $queries++; });
    $resolver=Characterization::getConnectionResolver();
    if ($kind==='off') { config(['services.learning_p5_live_capture.enabled'=>false]); }
    if ($kind==='absent') { config(['services.learning_p5_live_capture.enabled'=>null]); }
    if ($kind==='clock') { config(['services.learning_source_clock.enabled'=>false]); }
    if ($kind==='environment') { app()->instance('env','production'); }
    if ($kind==='file') {
        config(['database.connections.t06l_file'=>['driver'=>'sqlite','database'=>'DO_NOT_OPEN.sqlite'],'database.default'=>'t06l_file']);
    }
    if ($kind==='model') {
        Characterization::setConnectionResolver(new class($resolver) implements \Illuminate\Database\ConnectionResolverInterface {
            public function __construct(private $original) {}
            public function connection($name=null) { return $name===null ? DB::connection('t06l_other') : $this->original->connection($name); }
            public function getDefaultConnection() { return $this->original->getDefaultConnection(); }
            public function setDefaultConnection($name) { $this->original->setDefaultConnection($name); }
        });
        config(['database.connections.t06l_other'=>['driver'=>'sqlite','database'=>':memory:']]);
    }
    try {
        expect(fn()=>t06lCapture($x))->toThrow(DomainException::class);
        expect($queries)->toBe(0)->and($x[2]->calls)->toBe($calls)->and($connection->getRawPdo())->toBe($pdo);
        if (in_array($kind,['file','model'],true)) {
            $other=DB::connection($kind==='file'?'t06l_file':'t06l_other');
            expect($other->getRawPdo())->toBeInstanceOf(Closure::class);
        }
    } finally {
        Characterization::setConnectionResolver($resolver); app()->instance('env','testing'); config(['database.default'=>'sqlite']);
    }
})->with(['off','absent','clock','environment','file','model']);
