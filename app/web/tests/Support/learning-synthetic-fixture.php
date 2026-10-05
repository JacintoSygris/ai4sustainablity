<?php
use App\Models\{Characterization, User, EsrsTopic, LearningCase, LearningCaseP5Snapshot, LearningCompanyGroup, LearningCompanyMembership};
use App\Services\{LearningCaseClosure, LearningAuthorizationAuthority, LearningAuthorization, LearningAuthorizationLedger, LearningCaseP5Storage, LearningCaseSnapshot, LearningP5Snapshot, CharacterizationStateTransaction, ApiCharacterizationGateway, CharacterizationPredictionMapper, EsrsDatapointCorpusBuilder, Ar16MatterDrMappingRepository, EsrsDatapointResponseState};
use Illuminate\Support\Facades\{DB, Schema, Http};
use Illuminate\Database\Schema\Blueprint;

class T11CompositionCorpus extends EsrsDatapointCorpusBuilder
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

function t11Schema(): void {
 Schema::create('password_reset_tokens',function(Blueprint $t){$t->string('email')->primary();$t->string('token');$t->timestamp('created_at')->nullable();});
 Schema::create('reporting_facts',function(Blueprint $t){$t->id();$t->unsignedBigInteger('characterization_id');$t->string('fact_id');$t->string('datapoint_id');$t->json('value');$t->string('approval_status');$t->timestamps();});
 Schema::create('users',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->unique(DB::connection()->getTablePrefix().'ix1');$t->timestamp('email_verified_at')->nullable();$t->string('password');$t->rememberToken();$t->unsignedBigInteger('auth_version')->default(0);$t->unsignedBigInteger('password_reset_generation')->default(0);$t->timestamps();});
 Schema::create('characterizations',function(Blueprint $t){$t->id();$t->foreignId('user_id')->unique(DB::connection()->getTablePrefix().'ix2')->constrained('users', indexName: DB::connection()->getTablePrefix().'fk1')->cascadeOnDelete();$t->string('status');$t->unsignedBigInteger('submission_generation')->default(1);$t->json('form_data')->nullable();$t->json('result_data')->nullable();$t->json('esrs_topic_ids')->nullable();$t->string('nace_code')->nullable();$t->timestamps();});
 Schema::create('esrs_topics',function(Blueprint $t){$t->id();$t->string('hash');foreach(['esrs_code','theme_es','theme_en','subtheme_es','subtheme_en','subtopic_es','subtopic_en','examples_es','examples_en','competency_1','competency_2','competency_3','regulation_1','regulation_2','regulation_3','internal_strategy','internal_activities','internal_policies','internal_regulations','consolidated'] as $f)$t->string($f)->nullable();$t->timestamps();});
 Schema::create('nace_codes',function(Blueprint $t){$t->id();$t->string('code')->nullable();$t->string('description')->nullable();$t->timestamps();});
 Schema::create('characterization_documents',function(Blueprint $t){$t->id();$t->foreignId('characterization_id')->constrained('characterizations',indexName:DB::connection()->getTablePrefix().'docs_fk')->cascadeOnDelete();foreach(['sha256','mime','status','extraction_lease_token'] as $f)$t->string($f)->nullable();foreach(['size_bytes','extraction_generation','merged_state_version'] as $f)$t->unsignedBigInteger($f)->nullable();foreach(['extraction_dispatched_at','extraction_started_at'] as $f)$t->timestamp($f)->nullable();$t->json('extraction_json')->nullable();$t->timestamps();});

        Schema::create('learning_company_groups', function (Blueprint $table) {
            $table->id();
            $table->char('company_group_key', 64)->unique(DB::connection()->getTablePrefix().'ix3');
            $table->timestamp('created_at')->useCurrent();
        });


        Schema::create('learning_company_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_company_group_id')
                ->constrained('learning_company_groups', indexName: DB::connection()->getTablePrefix().'fk3')
                ->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: DB::connection()->getTablePrefix().'fk4')->nullOnDelete();
            $table->string('subject_type', 16);
            $table->string('subject_identifier', 64);
            $table->char('evidence_digest', 64);
            $table->string('evidence_type', 64);
            $table->char('revocation_evidence_digest', 64)->nullable();
            $table->string('revocation_evidence_type', 64)->nullable();
            $table->string('verification_status', 16);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_identifier'],
                DB::connection()->getTablePrefix().'3bb7c269469a',
            );
            $table->index(
                ['learning_company_group_id', 'verification_status'],
                DB::connection()->getTablePrefix().'247ff1750c16',
            );
        });


        Schema::create('learning_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_company_group_id')
                ->constrained('learning_company_groups', indexName: DB::connection()->getTablePrefix().'fk5')
                ->restrictOnDelete();
            $table->string('case_id', 128)->index(DB::connection()->getTablePrefix().'ix4');
            $table->string('period_key', 64);
            $table->string('perimeter_key', 64);
            $table->char('revision_tuple_digest', 64);
            $table->char('identity_digest', 64);
            $table->char('case_hash', 64)->index(DB::connection()->getTablePrefix().'ix5');
            $table->longText('payload_text');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                [
                    'learning_company_group_id',
                    'identity_digest',
                ],
                DB::connection()->getTablePrefix().'b0f70401a443',
            );
        });

        Schema::create('learning_case_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_case_id')
                ->unique(DB::connection()->getTablePrefix().'ix6')
                ->constrained('learning_cases', indexName: DB::connection()->getTablePrefix().'fk6')
                ->cascadeOnDelete();
            $table->string('status', 64);
            $table->unsignedBigInteger('state_version')->default(0);
            $table->timestamps();
        });


        Schema::create('learning_authorization_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users', indexName: DB::connection()->getTablePrefix().'fk7')->nullOnDelete();
            $table->foreignId('learning_company_group_id')->constrained('learning_company_groups', indexName: DB::connection()->getTablePrefix().'fk8')->restrictOnDelete();
            $table->char('subject_digest', 64)->unique(DB::connection()->getTablePrefix().'ix7');
            $table->string('holder_ref', 96);
            $table->string('purpose', 128);
            $table->string('status', 16);
            $table->unsignedBigInteger('generation');
            $table->char('event_digest', 64);
            $table->string('provenance', 32)->default('synthetic-only');
            $table->boolean('promotion_allowed')->default(false);
            $table->timestamps();
        });
        Schema::create('learning_authorization_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_authorization_state_id')->constrained('learning_authorization_states', indexName: DB::connection()->getTablePrefix().'fk9')->restrictOnDelete();
            $table->char('command_id', 64)->unique(DB::connection()->getTablePrefix().'ix8');
            $table->char('intent_digest', 64);
            $table->unsignedBigInteger('previous_generation');
            $table->unsignedBigInteger('generation');
            $table->char('previous_digest', 64)->nullable();
            $table->char('event_digest', 64)->unique(DB::connection()->getTablePrefix().'ix9');
            $table->longText('payload_text');
            $table->string('provenance', 32)->default('synthetic-only');
            $table->boolean('promotion_allowed')->default(false);
            $table->timestamp('created_at');
            $table->unique(['learning_authorization_state_id', 'generation'], DB::connection()->getTablePrefix().'0684fcb2a6c1');
        });


        Schema::create('learning_case_annotations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('learning_case_id')->constrained('learning_cases', indexName: DB::connection()->getTablePrefix().'fk10')->cascadeOnDelete();
            $t->foreignId('curator_id')->constrained('users', indexName: DB::connection()->getTablePrefix().'fk11')->cascadeOnDelete();
            $t->unsignedBigInteger('annotation_revision');
            $t->unsignedBigInteger('expected_revision');
            $t->char('command_id', 64);
            $t->longText('command_text'); $t->char('command_digest', 64);
            $t->longText('annotation_text'); $t->char('annotation_digest', 64);
            $t->unsignedBigInteger('curator_authorization_generation');
            $t->char('curator_authorization_digest', 64);
            $t->unique(['learning_case_id','annotation_revision'],DB::connection()->getTablePrefix().'ix10');
            $t->unique(['learning_case_id','command_id'],DB::connection()->getTablePrefix().'ix11');
        });


        Schema::create('learning_batches', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedBigInteger('fence')->default(0);
            $t->string('batch_id',32)->nullable(); $t->string('status')->default('idle');
            $t->unsignedBigInteger('lease_until')->nullable(); $t->unsignedBigInteger('heartbeat_at')->nullable();
            $t->json('issuer_state')->nullable(); $t->json('dataset_state')->nullable();
            $t->json('receipt')->nullable(); $t->string('context_digest',64)->nullable();
        });


        Schema::create('learning_candidate_selections',function (Blueprint $t) {
            $t->string('candidate_key',64)->primary();
            $t->string('batch_id',32); $t->unsignedBigInteger('fence');
            $t->unsignedBigInteger('authority_generation');
            $t->string('context_digest',64); $t->string('authority_digest',64);
            $t->text('technical_metadata');
            $t->boolean('selected')->default(false);
        });


        Schema::create('learning_case_p5_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_case_id')->unique(DB::connection()->getTablePrefix().'ix12')->constrained('learning_cases', indexName: DB::connection()->getTablePrefix().'fk12')->cascadeOnDelete();
            $table->longText('values_text');
            $table->string('schema_version',64);
            $table->char('digest',64);
            $table->string('feature_schema_version',64);
            $table->string('transform_version',64);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('group_id');
            $table->string('purpose',128);
            $table->char('authorization_digest',64);
            $table->unsignedBigInteger('authorization_generation');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('learning_case_p5_storage_receipts', function (Blueprint $table) {
            $table->foreignId('learning_case_id')->unique(DB::connection()->getTablePrefix().'ix13')->constrained('learning_cases', indexName: DB::connection()->getTablePrefix().'fk13')->cascadeOnDelete();
            // Deliberately no snapshot FK: ordinary snapshot deletion remains allowed.
            $table->unsignedBigInteger('snapshot_id');
            $table->char('completion_reference',64);
        });


        Schema::create('learning_p5_source_revisions', function (Blueprint $table) {
            $table->foreignId('characterization_id')->primary()->constrained('characterizations', indexName: DB::connection()->getTablePrefix().'fk14')->cascadeOnDelete();
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('revision');
            $table->string('epoch', 64);
            $table->string('digest', 64);
        });


        Schema::create('learning_p6_base_source_revisions', function (Blueprint $table) {
            $table->foreignId('characterization_id')->primary()->constrained('characterizations', indexName: DB::connection()->getTablePrefix().'fk15')->cascadeOnDelete();
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('revision');
            $table->string('epoch', 64);
            $table->string('digest', 64);
        });


        Schema::create('learning_p8_source_revisions', function (Blueprint $table) {
            $table->foreignId('characterization_id')->primary()->constrained('characterizations', indexName: DB::connection()->getTablePrefix().'fk16')->cascadeOnDelete();
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('revision');
            $table->string('epoch', 64);
            $table->string('digest', 64);
        });


        Schema::table('learning_case_p5_storage_receipts', function (Blueprint $table) {
            $table->longText('source_header_text')->nullable();
        });


        Schema::create('learning_case_closure_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained('users', indexName: DB::connection()->getTablePrefix().'fk17')->cascadeOnDelete();
            $t->foreignId('learning_case_id')->unique(DB::connection()->getTablePrefix().'ix14')->constrained('learning_cases', indexName: DB::connection()->getTablePrefix().'fk18')->cascadeOnDelete();
            $t->string('idempotency_key',128); $t->char('intent_digest',64);
            $t->char('source_token',64); $t->longText('source_text');
            $t->longText('receipt_text'); $t->char('receipt_hash',64);
            $t->timestamp('created_at'); $t->unique(['user_id','idempotency_key'],DB::connection()->getTablePrefix().'ix15');
        });
        Schema::create('learning_case_closure_drafts', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users', indexName: DB::connection()->getTablePrefix().'fk19')->cascadeOnDelete();
            $t->longText('draft_text');
        });

Schema::create('learning_p9_source_revisions',function(Blueprint $t){$t->foreignId('characterization_id')->primary()->constrained('characterizations', indexName: DB::connection()->getTablePrefix().'fk20')->cascadeOnDelete();$t->unsignedBigInteger('generation');$t->unsignedBigInteger('revision');$t->string('epoch',64);$t->string('digest',64);});
}

trait T11NativeApplication {
 public function createApplication() {
  if (!isset($GLOBALS['t11Root'], $GLOBALS['t11Profile'], $GLOBALS['t11Engine'])) return parent::createApplication();
  $app=require __DIR__.'/../../bootstrap/app.php';
  $app->useEnvironmentPath($GLOBALS['t11Root']);$app->loadEnvironmentFrom('absent-synthetic.env');
  $app->instance('env','testing');
  $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $app->instance('env','testing');
  $engine=$GLOBALS['t11Engine'];$profile=$GLOBALS['t11Profile'];$p=$profile['profiles'][$engine];
  $app['config']->set('database.default','t11');
  $app['config']->set('database.connections.t11',['driver'=>$engine,'host'=>$p['host'],'port'=>$p['port'],'database'=>$p['database'],'username'=>$p['username'],'password'=>'','charset'=>'utf8','prefix'=>$GLOBALS['t11Prefix'],'schema'=>'public','search_path'=>'public','sslmode'=>'disable','options'=>[PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_EMULATE_PREPARES=>false]]);
  $app['config']->set('services.learning_native_fixture',['namespace'=>'test-namespace:t11-native','synthetic_only'=>true,'promotion_allowed'=>false,'driver'=>$engine,'host'=>$p['host'],'port'=>$p['port'],'database'=>$p['database']]);
  $app['config']->set('services.learning_native_artifact_root',dirname($GLOBALS['t11Root']));
  $app['config']->set('app.key','base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
  $app['config']->set('cache.default','array');$app['config']->set('session.driver','array');
  $app['config']->set('logging.default','null');
  foreach(['learning_case_closure','learning_source_clock','learning_p6_base_source_clock','learning_p8_source_clock','learning_p9_source_clock','learning_p5_live_capture','learning_p6_prepared_request','learning_p6_job_parent_fence','learning_p6_interpretation_context'] as $flag)$app['config']->set('services.'.$flag.'.enabled',true);
  $map=$GLOBALS['t11Root'].'/prediction-map.json';if(!is_file($map))file_put_contents($map,'{"candidate_topics":[]}');
  $app['config']->set('services.characterization.api.model_profile','t06_close_synthetic');$app['config']->set('services.characterization.prediction_mapping_path',$map);
  $app->instance(CharacterizationPredictionMapper::class,CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}'));
  Http::preventStrayRequests();
  return $app;
 }
 protected function setUp(): void {if (!isset($GLOBALS['t11Root'], $GLOBALS['t11Profile'], $GLOBALS['t11Engine'])) $this->markTestSkipped('Native concurrency requires explicit disposable fixture permission and profile.');parent::setUp();$GLOBALS['t11Prefix']='t11_'.bin2hex(random_bytes(5)).'_';config(['database.connections.t11.prefix'=>$GLOBALS['t11Prefix']]);DB::purge('t11');t11Schema();}
}
function t11Fixture(?int $batchIndex = null, bool $batchPositive = true): array {
    $u = User::query()->forceCreate(['name'=>'SYNTHETIC_ACCOUNT','email'=>bin2hex(random_bytes(8)).'@example.invalid','password'=>password_hash('synthetic',PASSWORD_BCRYPT),'email_verified_at'=>now()]);
    foreach ([1,2,3] as $id) { if (!EsrsTopic::query()->whereKey($id)->exists()) { EsrsTopic::query()->forceCreate(['hash'=>hash('sha256','synthetic-topic-'.$id),'id' => $id,'esrs_code'=>'SYNTHETIC','theme_es'=>'SYNTHETIC','theme_en'=>'SYNTHETIC','subtheme_es'=>'SYNTHETIC','subtheme_en'=>'SYNTHETIC','subtopic_es'=>'SYNTHETIC','subtopic_en'=>'SYNTHETIC']); } }
    $g = LearningCompanyGroup::query()->forceCreate(['company_group_key' => hash('sha256',bin2hex(random_bytes(16)))]);
    $m = LearningCompanyMembership::query()->forceCreate(['learning_company_group_id'=>$g->id,'user_id'=>$u->id,'subject_type'=>'account','subject_identifier'=>(string)$u->id,'verification_status'=>'verified','verified_at'=>now(),'evidence_type'=>'synthetic-only','evidence_digest'=>hash('sha256','synthetic-member')]);
    $issuer = new class implements LearningAuthorizationAuthority {
        public bool $denied = false;
        public ?Closure $onResolve = null;
        public function references(string $purpose): ?array { return $this->denied ? null : ['issuer_ref'=>'synthetic:issuer','issuer_version'=>'v1','issuer_digest'=>hash('sha256','issuer'),'capability_ref'=>'synthetic:cap','capability_version'=>'v1','capability_digest'=>hash('sha256','cap'),'policy_version'=>'synthetic-policy-v1','policy_digest'=>hash('sha256','synthetic-policy')]; }
        public function resolve(User $actor, LearningCompanyMembership $member, string $purpose): ?array { $callback=$this->onResolve; $this->onResolve=null; $callback?->__invoke(); $r=$this->references($purpose); return $r===null ? null : $r+['actor_id'=>$actor->id,'group_id'=>$member->learning_company_group_id,'membership_id'=>$member->id,'purpose'=>$purpose,'state'=>'available','provenance'=>'synthetic-only','promotion_allowed'=>false]; }
    };
    $tx = new CharacterizationStateTransaction;
    $ledger = new LearningAuthorizationLedger(new LearningAuthorization($issuer),$tx);
    $ledger->grant($u->id,$g->id,'synthetic:learning',0,hash('sha256','grant:'.$u->id));
    $row = $tx->runForUser($u->id, fn()=>Characterization::create(['user_id'=>$u->id,'status'=>'completed','submission_generation'=>1,'esrs_topic_ids'=>[1,2],
        'form_data'=>['company_profile'=>['headquarters_country'=>'Spain','stock_listed'=>$batchIndex!==null && $batchPositive],'operations'=>['employee_count_range'=>$batchPositive?'50_249':'1_9','employee_count'=>$batchPositive?150:5],
            'materiality_confirmation'=>['revision'=>1,'confirmed_topic_ids'=>$batchPositive?[1]:[2],'reviewed_topic_ids'=>[1,2],'universe_attestation'=>['version'=>1,'reviewed_universe'=>true,'mode'=>'direct'],'p6_snapshot'=>['topic_ids'=>[1,2],'captured_at'=>now()->utc()->format('Y-m-d\TH:i:s\Z')],'confirmed_at'=>now()->utc()->format('Y-m-d\TH:i:s\Z')]],
        'result_data'=>['status'=>'completed','model_profile'=>'t06_close_synthetic','mapping_metadata'=>['python'=>['serving_identity'=>['profile'=>'t06_close_synthetic','artifact_sha256'=>['synthetic.pkl'=>str_repeat('a',64)],'policy_sha256'=>[],'runtime_config'=>['score_threshold'=>0.95,'policy_active'=>['label_thresholds'=>false,'crc_recall_floor'=>false,'sector_guard'=>false]],'serving_identity_sha256'=>str_repeat('b',64)]]]]]));
    $builder = new T11CompositionCorpus(new Ar16MatterDrMappingRepository);
    $feedback = ['schema_version'=>'datapoint-feedback-v1','authority_digest'=>(new EsrsDatapointResponseState(new Ar16MatterDrMappingRepository))->learningAuthorityDigest($builder->build($row->fresh())), 'reviewed_datapoint_ids'=>['SYNTHETIC_A'],'decisions'=>[['datapoint_id'=>'SYNTHETIC_A','relevant'=>false,'selected_to_answer'=>false,'reason_codes'=>['scope'],'note'=>null]]];
    $tx->runForUser($u->id, function($fresh)use($feedback){$f=$fresh->form_data; $f['esrs_datapoint_responses']=['learning_feedback'=>$feedback]; $fresh->form_data=$f; $fresh->save();});
    $authority = ['framework_version'=>'synthetic-esrs','catalog_version'=>'synthetic-catalog','catalog_digest'=>hash('sha256','synthetic-catalog'),'mapping_version'=>'synthetic-mapping','mapping_digest'=>hash('sha256','synthetic-mapping'),'topic_ids'=>['1','2','3'],'datapoint_ids'=>['SYNTHETIC_A','SYNTHETIC_B'],'ambiguous_topic_ids'=>[],'ambiguous_datapoint_ids'=>[]];
    $storage=new LearningCaseP5Storage($tx,app(LearningCaseSnapshot::class),$ledger,new LearningP5Snapshot);
    $closure=LearningCaseClosure::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:learning','period_scope'=>['period_key'=>'synthetic-period','perimeter_key'=>'synthetic-only'],'authority'=>$authority],$ledger,$storage,new ApiCharacterizationGateway(CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}')),$builder);
    app()->instance(LearningCaseClosure::class,$closure);
    return compact('u','g','m','issuer','ledger','row','builder','closure');
}
function t11Input(array $f): array { $d=$f['closure']->draft($f['u']->id); return ['expected_revisions'=>$d['expected_revisions'],'expected_authorization_generation'=>$d['expected_authorization_generation'],'source_token'=>$d['source_token'],'idempotency_key'=>'synthetic-command-1','reviewed_universe'=>true,'final_for_period_scope'=>true,'declaration_version'=>'local-synthetic-closure-v1']; }
function t11Rows(): array { $out=[]; foreach (['learning_cases','learning_case_states','learning_case_p5_snapshots','learning_case_p5_storage_receipts','learning_case_closure_receipts','learning_authorization_records'] as $t) { $out[$t]=DB::table($t)->get()->map(fn($r)=>(array)$r)->all(); } return $out; }
function t11Exporter(array $f): App\Services\LearningCaseExport {
    return App\Services\LearningCaseExport::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$f['closure']);
}
function t11Closed(): array {
    $f=t11Fixture(); $f['closure']->close($f['u']->id,t11Input($f)); return $f;
}

function t11Rebind(array $ids): array {
 $u=User::findOrFail($ids['actor']);$g=LearningCompanyGroup::findOrFail($ids['group']);$m=LearningCompanyMembership::where('user_id',$u->id)->firstOrFail();$row=Characterization::where('user_id',$u->id)->first();
    $issuer = new class implements LearningAuthorizationAuthority {
        public bool $denied = false;
        public ?Closure $onResolve = null;
        public function references(string $purpose): ?array { return $this->denied ? null : ['issuer_ref'=>'synthetic:issuer','issuer_version'=>'v1','issuer_digest'=>hash('sha256','issuer'),'capability_ref'=>'synthetic:cap','capability_version'=>'v1','capability_digest'=>hash('sha256','cap'),'policy_version'=>'synthetic-policy-v1','policy_digest'=>hash('sha256','synthetic-policy')]; }
        public function resolve(User $actor, LearningCompanyMembership $member, string $purpose): ?array { $callback=$this->onResolve; $this->onResolve=null; $callback?->__invoke(); $r=$this->references($purpose); return $r===null ? null : $r+['actor_id'=>$actor->id,'group_id'=>$member->learning_company_group_id,'membership_id'=>$member->id,'purpose'=>$purpose,'state'=>'available','provenance'=>'synthetic-only','promotion_allowed'=>false]; }
    };
    $tx = new CharacterizationStateTransaction;
    $ledger = new LearningAuthorizationLedger(new LearningAuthorization($issuer),$tx);
    $builder = new T11CompositionCorpus(new Ar16MatterDrMappingRepository);
    $authority = ['framework_version'=>'synthetic-esrs','catalog_version'=>'synthetic-catalog','catalog_digest'=>hash('sha256','synthetic-catalog'),'mapping_version'=>'synthetic-mapping','mapping_digest'=>hash('sha256','synthetic-mapping'),'topic_ids'=>['1','2','3'],'datapoint_ids'=>['SYNTHETIC_A','SYNTHETIC_B'],'ambiguous_topic_ids'=>[],'ambiguous_datapoint_ids'=>[]];
    $storage=new LearningCaseP5Storage($tx,app(LearningCaseSnapshot::class),$ledger,new LearningP5Snapshot);
    $closure=LearningCaseClosure::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:learning','period_scope'=>['period_key'=>'synthetic-period','perimeter_key'=>'synthetic-only'],'authority'=>$authority],$ledger,$storage,new ApiCharacterizationGateway(CharacterizationPredictionMapper::fromCapturedRaw('{"candidate_topics":[]}')),$builder);
    app()->instance(LearningCaseClosure::class,$closure);
 return compact('u','g','m','row','issuer','ledger','closure','builder');
}

function t11Wait(string $path, float $seconds=15): void {
 $deadline=microtime(true)+$seconds;
 do {clearstatcache(true,$path);if(is_file($path))return;usleep(10000);}while(microtime(true)<$deadline);
 throw new RuntimeException('T11 barrier timeout: '.basename($path));
}
function t11Launch(array $job): array {
 $root=$GLOBALS['t11Root'].'/role-'.bin2hex(random_bytes(8));mkdir($root,0700,true);
 $job['barrier']=$job['barrier']??$root;
 file_put_contents($root.'/job.json',json_encode($job,JSON_THROW_ON_ERROR));
 $argv=[PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','extension=pdo_mysql','-d','extension=pdo_pgsql',base_path('tests/scripts/t11-native-child.php'),'--profile='.$GLOBALS['t11ProfilePath'],'--engine='.$GLOBALS['t11Engine'],'--root='.$GLOBALS['t11Root'],'--prefix='.$GLOBALS['t11Prefix'],'--suite=role','--job='.$root.'/job.json'];
 // Canonical copies only; propagated into every participating child.
 if(isset($GLOBALS['t11Mutant']))$argv[]='--mutant='.$GLOBALS['t11Mutant'];
 $p=proc_open($argv,[0=>['pipe','r'],1=>['file',$root.'/stdout.txt','w'],2=>['file',$root.'/stderr.txt','w']],$pipes,base_path(),null,['bypass_shell'=>true]);
 if(!is_resource($p))throw new RuntimeException('T11 process launch failed');fclose($pipes[0]);
 return compact('p','root','argv')+['job'=>$job];
}
function t11Join(array $child): array {
 $deadline=microtime(true)+20;
 do {$s=proc_get_status($child['p']);if(!$s['running'])break;usleep(10000);}while(microtime(true)<$deadline);
 if($s['running']){proc_terminate($child['p']);throw new RuntimeException('T11 child timeout');}
 proc_close($child['p']);
 if($s['exitcode']!==0)throw new RuntimeException('T11 child error: '.file_get_contents($child['root'].'/stderr.txt'));
 return json_decode(file_get_contents($child['job']['barrier'].'/'.$child['job']['name'].'.result'),true,128,JSON_THROW_ON_ERROR);
}
function t11NativeRole(array $job): void {
 $b=$job['barrier'];$name=$job['name'];
 $c=DB::connection();if($c->transactionLevel()!==0)throw new RuntimeException('T11 requires committed starting frame');
 $pid=$c->getPdo()->query($GLOBALS['t11Engine']==='mysql'?'SELECT CONNECTION_ID()':'SELECT pg_backend_pid()')->fetchColumn();
 file_put_contents($b.'/'.$name.'.ready',json_encode(['connection'=>$pid,'transaction_level'=>0]));
 t11Wait($b.'/'.$name.'.go');
 $f=isset($job['actor'])?t11Rebind($job):null;
 if(($job['plain']??false)===true)foreach(['learning_source_clock','learning_p6_base_source_clock','learning_p8_source_clock','learning_p9_source_clock']as $flag)config(['services.'.$flag.'.enabled'=>false]);
 config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export','services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true]);
 file_put_contents($b.'/'.$name.'.attempt','attempt');
 $run=function()use($job,$f,$b,$name){
  $tx=new CharacterizationStateTransaction;
  return match($job['op']) {
   'edit','plain'=> $tx->runForUser($f['u']->id,function($row)use($job,$b,$name){
    file_put_contents($b.'/'.$name.'.entered','entered');
    if($job['hold']??false)t11Wait($b.'/'.$name.'.release');
    $form=$row->form_data;
    if($job['op']==='plain')$form[$name]=true;
    elseif($job['axis']==='p5')$form['operations']['employee_count']=151;
    elseif($job['axis']==='p8')$form['materiality_confirmation']['revision']=2;
    else $form['esrs_datapoint_responses']['learning_feedback']['decisions'][0]['relevant']=true;
    $row->form_data=$form;$row->save();return 'committed-edit';
   }),
   'close'=>$f['closure']->close($f['u']->id,$job['input']),
   'close-held'=>$tx->runForUser($f['u']->id,function()use($f,$job,$b,$name){$result=$f['closure']->close($f['u']->id,$job['input']);file_put_contents($b.'/'.$name.'.entered','entered');t11Wait($b.'/'.$name.'.release');return $result;}),
   'revoke'=>$tx->runForUser($f['u']->id,function()use($f,$job,$b,$name){$f['ledger']->revoke($f['u']->id,$f['g']->id,'synthetic:learning',1,hash('sha256','T11-revoke'));file_put_contents($b.'/'.$name.'.entered','entered');if($job['hold']??true)t11Wait($b.'/'.$name.'.release');return 'revoked';}),
   'export'=>t11Exporter($f)->exportForAccount($f['u']->id,$job['reference']),
   'export-held'=>$tx->runForUser($f['u']->id,function()use($f,$job,$b,$name){$result=t11Exporter($f)->exportForAccount($f['u']->id,$job['reference']);file_put_contents($b.'/'.$name.'.entered','entered');t11Wait($b.'/'.$name.'.release');return $result;}),
   'train-held'=> (function()use($job,$f,$b,$name){
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-10-04T00:00:00Z'));
    $export=\App\Services\LearningCaseExport::forSyntheticEligibilityTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$f['closure'],$f['ledger']);
    app()->instance(\App\Services\LearningCaseExport::class,$export);
    config(['services.learning_batch.actors'=>$job['actors'],'services.learning_batch.candidate_stage'=>$job['mapping']]);
    $callback=null;
    $callback=function()use(&$callback,$f,$b,$name){
     $f['issuer']->onResolve=$callback;
     $row=\App\Models\LearningBatch::find(1);
     if(!$row || $row->status!=='running')return;
     $artifact=config('services.learning_native_artifact_root').'/05a-batch-'.$row->batch_id;
     if(!is_file($artifact.'/stdout.log') || !str_contains(file_get_contents($artifact.'/stdout.log'),'"ready": true'))return;
     $f['issuer']->onResolve=null;
     DB::afterCommit(function()use($b,$name,$row,$artifact){
      file_put_contents($b.'/'.$name.'.entered',json_encode(['token'=>['batch_id'=>$row->batch_id,'fence'=>$row->fence],'artifact'=>$artifact,'context'=>$row->context_digest]));
      t11Wait($b.'/'.$name.'.release');
     });
    };
    $f['issuer']->onResolve=$callback;
    $exit=\Illuminate\Support\Facades\Artisan::call('learning:batch');
    return ['exit'=>$exit,'output'=>\Illuminate\Support\Facades\Artisan::output()];
   })(),
   'claim'=>\App\Models\LearningBatch::claim(),
   'abort'=> (function()use($job){\App\Models\LearningBatch::abort($job['token']);return 'aborted';})(),
   'complete'=>\App\Models\LearningBatch::locked($job['token'],function($row){$row->status='raw_complete';$row->lease_until=null;$row->save();return 'completed';}),
   'destroy'=> (function()use($f,$job,$b,$name){
    app()->instance(LearningAuthorizationLedger::class,$f['ledger']);
    \Illuminate\Support\Facades\Auth::login($f['u']);
    $request=\Illuminate\Http\Request::create('/profile','DELETE',['password'=>'synthetic']);
    $session=app('session')->driver();$session->start();$session->put('auth_version',0);$request->setLaravelSession($session);$request->setUserResolver(fn()=>$f['u']);
    return DB::transaction(function()use($f,$job,$b,$name,$request){
     User::whereKey($f['u']->id)->lockForUpdate()->firstOrFail();
     app(\App\Http\Controllers\ProfileController::class)->destroy($request);
     file_put_contents($b.'/'.$name.'.entered','entered');
     if($job['hold']??false)t11Wait($b.'/'.$name.'.release');
     return 'deleted';
    });
   })(),
   default=>throw new RuntimeException('unknown T11 role'),
  };
 };
 try {$value=$run();$out=['value'=>$value,'error'=>null];}catch(\DomainException|\InvalidArgumentException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){$out=['value'=>null,'error'=>get_class($e).':'.$e->getMessage()];}
 if($c->transactionLevel()!==0)throw new RuntimeException('T11 uncommitted ending frame');
 file_put_contents($b.'/'.$name.'.result',json_encode($out+['transaction_level'=>0,'connection'=>$pid],JSON_THROW_ON_ERROR));
}
