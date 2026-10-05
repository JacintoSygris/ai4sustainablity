<?php
namespace Tests\Feature\Services;
require_once __DIR__.'/../../Support/learning-synthetic-fixture.php';
use App\Models\{Characterization,User};
use App\Services\{CharacterizationStateTransaction,LearningP5SourceRevisionClock};
use Illuminate\Support\Facades\DB;
final class LearningCaseConcurrencyTest extends \Tests\TestCase
{
 use \T11NativeApplication;
 public function test_T11_admitted_edit_close(): void {
  $u=User::query()->forceCreate(['name'=>'SYNTHETIC','email'=>bin2hex(random_bytes(8)).'@example.invalid','password'=>'synthetic','email_verified_at'=>now()]);
  $r=(new CharacterizationStateTransaction)->runForUser($u->id,fn()=>Characterization::create(['user_id'=>$u->id,'status'=>'completed','submission_generation'=>1,'form_data'=>[],'result_data'=>[],'esrs_topic_ids'=>[]]));
  $this->assertSame(0,DB::connection()->transactionLevel());
  $this->assertSame(1,(new LearningP5SourceRevisionClock)->current($r->id)['revision']);
 }
 private function ids(array $f): array {return ['actor'=>$f['u']->id,'group'=>$f['g']->id];}
 private function pair(array $first,array $second,bool $blocking=true): array {
  $this->assertSame(0,DB::connection()->transactionLevel());
  $b=$GLOBALS['t11Root'].'/barrier-'.bin2hex(random_bytes(8));mkdir($b,0700,true);
  $a=t11Launch($first+['name'=>'A','barrier'=>$b]);$z=t11Launch($second+['name'=>'B','barrier'=>$b]);
  t11Wait($b.'/A.ready');t11Wait($b.'/B.ready');
  $ra=json_decode(file_get_contents($b.'/A.ready'),true);$rb=json_decode(file_get_contents($b.'/B.ready'),true);
  $this->assertNotSame($ra['connection'],$rb['connection'],'two independent native PDO sessions');
  file_put_contents($b.'/A.go','go');t11Wait($b.'/A.entered');
  file_put_contents($b.'/B.go','go');t11Wait($b.'/B.attempt');
  // The second process has passed bootstrap/admission and announced its operation.
  // Observe a whole second before releasing the committed first transaction.
  $until=microtime(true)+1;do{clearstatcache();usleep(10000);}while(microtime(true)<$until&&!is_file($b.'/B.entered')&&!is_file($b.'/B.result'));
  $early=is_file($b.'/B.entered')||is_file($b.'/B.result');
  file_put_contents($b.'/A.release','release');
  $out=[t11Join($a),t11Join($z)];
  file_put_contents($b.'/committed-readback.json',json_encode(['first'=>$out[0],'second'=>$out[1],'second_entered_before_release'=>$early,'form_data'=>Characterization::where('user_id',$first['actor'])->first()?->form_data],JSON_THROW_ON_ERROR));
  if($blocking)$this->assertFalse($early,'second state operation must wait for Common lock; no lost update');
  return $out;
 }
 public function test_native_common_lock_serializes_with_plain_flags_off(): void {
  $f=t11Fixture();$ids=$this->ids($f);
  [$a,$b]=$this->pair($ids+['op'=>'plain','plain'=>true,'hold'=>true],$ids+['op'=>'plain','plain'=>true]);
  $this->assertNull($a['error']);$this->assertNull($b['error']);
  $form=$f['row']->fresh()->form_data;$this->assertTrue($form['A']);$this->assertTrue($form['B']);
 }
 public static function axes(): array {return [['p5'],['p8'],['p9']];}
 #[\PHPUnit\Framework\Attributes\DataProvider('axes')]
 public function test_native_edit_close_rejects_stale_cas(string $axis): void {
  $f=t11Fixture();$input=t11Input($f);$ids=$this->ids($f);
  [$a,$b]=$this->pair($ids+['op'=>'edit','axis'=>$axis,'hold'=>true],$ids+['op'=>'close','input'=>$input]);
  $this->assertNull($a['error']);$this->assertSame('DomainException:learning_closure.stale',$b['error']);
  $this->assertSame(0,DB::table('learning_case_closure_receipts')->count());
  $f['closure']->close($f['u']->id,t11Input($f));$this->assertSame(1,DB::table('learning_case_closure_receipts')->count());
 }
 public function test_native_duplicate_close_has_one_exact_receipt(): void {
  $f=t11Fixture();$input=t11Input($f);$ids=$this->ids($f);
  [$a,$b]=$this->pair($ids+['op'=>'close-held','input'=>$input],$ids+['op'=>'close','input'=>$input]);
  $this->assertNull($a['error']);$this->assertNull($b['error']);$this->assertSame($a['value'],$b['value']);
  $this->assertSame(1,DB::table('learning_cases')->count());$this->assertSame(1,DB::table('learning_case_closure_receipts')->count());
  $this->assertSame($a['value'],$f['closure']->close($f['u']->id,$input));
 }
 public function test_native_revoke_blocks_export_and_readback(): void {
  $f=t11Closed();$ids=$this->ids($f);$ref=t11Exporter($f)->referenceForAccount($f['u']->id);
  [$a,$b]=$this->pair($ids+['op'=>'revoke'],$ids+['op'=>'export','reference'=>$ref]);
  $this->assertNull($a['error']);$this->assertNotNull($b['error']);
  $this->assertSame('revoked',DB::table('learning_authorization_states')->value('status'));
  $before=t11Rows();try{t11Exporter($f)->exportForAccount($f['u']->id,$ref);$this->fail('revoked export must deny');}catch(\DomainException $e){$this->assertStringContainsString('learning_',$e->getMessage());}
  $this->assertSame($before,t11Rows());
 }
 public function test_native_export_commit_precedes_waiting_revoke_then_fresh_deny(): void {
  $f=t11Closed();$ids=$this->ids($f);$ref=t11Exporter($f)->referenceForAccount($f['u']->id);
  [$a,$b]=$this->pair($ids+['op'=>'export-held','reference'=>$ref],$ids+['op'=>'revoke','hold'=>false]);
  $this->assertNull($a['error']);$this->assertNull($b['error']);$this->assertSame('revoked',DB::table('learning_authorization_states')->value('status'));
  $this->expectException(\DomainException::class);t11Exporter($f)->exportForAccount($f['u']->id,$ref);
 }
 public function test_native_profile_declarations_deny_before_pdo(): void {
  $profile=config('services.learning_native_fixture');$declaration=config('database.connections.t11');
  $tripwire=fn($name)=>new \Illuminate\Database\Connection(fn()=>throw new \LogicException('T11 getPdo tripwire'),$profile['database'],$declaration['prefix'],array_replace($declaration,['name'=>$name]));
  $connection=$tripwire('t11');
  foreach(['host'=>'localhost','port'=>1,'database'=>'unapproved','namespace'=>'other','promotion_allowed'=>true,'synthetic_only'=>false,'driver'=>'sqlite']as $field=>$value){config(['services.learning_native_fixture'=>array_replace($profile,[$field=>$value])]);$this->assertFalse(CharacterizationStateTransaction::admitsIsolatedConnections([$connection]),$field);}
  config(['services.learning_native_fixture'=>$profile]);
  foreach(['url','unix_socket','socket','read','write','sticky','hostaddr','service','dsn']as $key){config(['database.connections.t11'=>$declaration+[$key=>'unapproved']]);$this->assertFalse(CharacterizationStateTransaction::admitsIsolatedConnections([$connection]),$key);}
  config(['database.connections.t11'=>$declaration]);
  $other=$tripwire('unregistered');$this->assertFalse(CharacterizationStateTransaction::admitsIsolatedConnections([$other]));
  app()->instance('env','production');$this->assertFalse(CharacterizationStateTransaction::admitsIsolatedConnections([$connection]));app()->instance('env','testing');
  $console=new \ReflectionProperty(app(),'isRunningInConsole');$saved=$console->getValue(app());$console->setValue(app(),false);
  try{$this->assertFalse(CharacterizationStateTransaction::admitsIsolatedConnections([$connection]));}finally{$console->setValue(app(),$saved);}
  config(['services.learning_native_fixture'=>null]);$this->assertFalse(CharacterizationStateTransaction::admitsIsolatedConnections([$connection]));config(['services.learning_native_fixture'=>$profile]);
  $this->assertTrue(CharacterizationStateTransaction::admitsIsolatedConnections([DB::connection()]));
 }
 public static function deletedOperations(): array {return [['close'],['export']];}
 #[\PHPUnit\Framework\Attributes\DataProvider('deletedOperations')]
 public function test_native_supported_profile_destroy_leaves_terminal_history(string $operation): void {
  $f=t11Closed();$ids=$this->ids($f);$ref=t11Exporter($f)->referenceForAccount($f['u']->id);$input=t11Input($f);
  [$result,$export]=$this->pair($ids+['op'=>'destroy','hold'=>true],$ids+['op'=>$operation,'reference'=>$ref,'input'=>$input]);
  $this->assertNotNull($export['error']);
  $this->assertNull($result['error']);$this->assertNull(User::find($ids['actor']));
  $this->assertSame('deleted',DB::table('learning_authorization_states')->value('status'));$this->assertNull(DB::table('learning_authorization_states')->value('user_id'));
  $history=DB::table('learning_authorization_records')->get()->toJson();
  foreach(['close','export']as $op){try{if($op==='close')$f['closure']->close($ids['actor'],$input);else t11Exporter($f)->exportForAccount($ids['actor'],$ref);$this->fail('deleted account must deny');}catch(\DomainException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){$this->assertNotSame('',$e->getMessage());}}
  $this->assertSame($history,DB::table('learning_authorization_records')->get()->toJson());$this->assertSame(0,DB::table('characterizations')->count());
 }
 public function test_native_lease_takeover_fences_completion_and_abort(): void {
  config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export','services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true]);
  $old=\App\Models\LearningBatch::claim();$this->assertSame(1,$old['fence']);
  DB::table('learning_batches')->where('id',1)->update(['lease_until'=>now()->getTimestamp()-1]);
  $b=$GLOBALS['t11Root'].'/lease-'.bin2hex(random_bytes(8));mkdir($b,0700,true);
  $next=t11Launch(['op'=>'claim','name'=>'N','barrier'=>$b]);t11Wait($b.'/N.ready');file_put_contents($b.'/N.go','go');$successor=t11Join($next)['value'];$this->assertSame(2,$successor['fence']);
  foreach(['complete','abort']as $op){$name=$op;$child=t11Launch(['op'=>$op,'name'=>$name,'barrier'=>$b,'token'=>$old]);t11Wait($b.'/'.$name.'.ready');file_put_contents($b.'/'.$name.'.go','go');$result=t11Join($child);$this->assertSame('DomainException:learning_batch.stale_owner',$result['error'],'obsolete owner cannot complete or abort successor');}
  $row=\App\Models\LearningBatch::findOrFail(1);$this->assertSame($successor['batch_id'],$row->batch_id);$this->assertSame('running',$row->status);
  \App\Models\LearningBatch::heartbeat($successor);\App\Models\LearningBatch::abort($successor);$this->assertSame('aborted',$row->fresh()->status);
 }
 public function test_native_artifact_root_requires_existing_exact_uuid_directory(): void {
  $root=config('services.learning_native_artifact_root');
  $this->assertTrue(CharacterizationStateTransaction::admitsIsolatedConnections([DB::connection()],$root));
  foreach([$root.'/..',$root.'/nested',dirname($root),$root.'/',str_replace('tests','elsewhere',$root)]as $bad)$this->assertFalse(CharacterizationStateTransaction::admitsIsolatedConnections([DB::connection()],$bad));
 }
 public function test_native_training_requires_portable_python_root(): void {
  $actors=[];$fixtures=[];
  foreach(range(0,11)as $i){$this->travelTo(\Carbon\Carbon::parse('2026-01-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT).'T12:00:00Z'));$f=t11Fixture($i,$i%2===1);$f['closure']->close($f['u']->id,t11Input($f));$actors[]=$f['u']->id;$fixtures[]=$f;}
  $this->travelTo(\Carbon\Carbon::parse('2026-10-04T00:00:00Z'));
  $export=\App\Services\LearningCaseExport::forSyntheticEligibilityTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false],$fixtures[0]['closure'],$fixtures[0]['ledger']);
  app()->instance(\App\Services\LearningCaseExport::class,$export);
  config(['services.learning_batch.enabled'=>true,'services.learning_batch.namespace'=>'test-namespace:t07-export','services.learning_batch.synthetic_only'=>true,'services.learning_batch.trusted_launcher'=>true,'services.learning_batch.actors'=>$actors,'services.learning_batch.candidate_stage'=>['version'=>'t10-explicit-fixture-v1','namespace'=>'test-namespace:t10-synthetic-shadow','topic_ids'=>['1','2','3'],'filter_keys'=>['esrs_e1_energy','esrs_e2_pollution_of_air','esrs_e3_summary'],'approved_common_axis'=>false]]);
  $exit=\Illuminate\Support\Facades\Artisan::call('learning:batch');
  file_put_contents($GLOBALS['t11Root'].'/training-command.json',json_encode(['exit'=>$exit,'output'=>\Illuminate\Support\Facades\Artisan::output(),'artifact_root'=>config('services.learning_native_artifact_root'),'batch'=>\App\Models\LearningBatch::findOrFail(1)->toArray()],JSON_THROW_ON_ERROR));
  $this->assertSame(0,$exit,\Illuminate\Support\Facades\Artisan::output());
 }
 public function test_native_real_consume_replay_previous_rollback_revoke(): void {
  $this->test_native_training_requires_portable_python_root();
  $service=app(\App\Services\LearningCandidateSelection::class);$previous=DB::table('learning_candidate_selections')->sole();$old=json_decode($previous->technical_metadata,true);
  $features=['data'=>[['Spain','50_249','true']],'index'=>['synthetic-native-unseen'],'columns'=>['headquarters_country','employee_count_range','stock_listed']];
  $before=DB::table('characterizations')->get()->toJson();
  $this->assertSame($old,$service->read($previous->candidate_key));$this->assertSame($previous->candidate_key,$service->accept($old['binding']['token'],$old));$this->assertSame(1,DB::table('learning_candidate_selections')->count());
  $service->select($previous->candidate_key,'synthetic-human-select');$this->assertFalse($service->shadow($previous->candidate_key,$features)['promotion_allowed']);
  $this->assertSame(0,\Illuminate\Support\Facades\Artisan::call('learning:batch'),\Illuminate\Support\Facades\Artisan::output());
  $latest=DB::table('learning_candidate_selections')->where('candidate_key','!=',$previous->candidate_key)->sole();$service->select($latest->candidate_key,'synthetic-human-select');
  try{$service->select($previous->candidate_key,'synthetic-human-select');$this->fail('explicit rollback required');}catch(\DomainException $e){$this->assertSame('learning_candidate.explicit_rollback_required',$e->getMessage());}
  $service->select($previous->candidate_key,'synthetic-human-rollback');$this->assertSame($previous->candidate_key,DB::table('learning_candidate_selections')->where('selected',true)->sole()->candidate_key);$this->assertSame($old,$service->read($previous->candidate_key));$this->assertFalse($service->shadow($previous->candidate_key,$features)['promotion_allowed']);$this->assertSame($before,DB::table('characterizations')->get()->toJson());
  $actor=config('services.learning_batch.actors')[0];$group=DB::table('learning_company_memberships')->where('user_id',$actor)->value('learning_company_group_id');$b=$GLOBALS['t11Root'].'/candidate-revoke-'.bin2hex(random_bytes(8));mkdir($b,0700,true);
  $child=t11Launch(['op'=>'revoke','name'=>'R','barrier'=>$b,'actor'=>$actor,'group'=>$group,'hold'=>false]);t11Wait($b.'/R.ready');file_put_contents($b.'/R.go','go');$this->assertNull(t11Join($child)['error']);$pointers=DB::table('learning_candidate_selections')->get()->toJson();
  foreach([$previous->candidate_key,$latest->candidate_key]as $key)foreach(['read','select','shadow']as $op){DB::enableQueryLog();DB::flushQueryLog();try{match($op){'read'=>$service->read($key),'select'=>$service->select($key,'synthetic-human-rollback'),'shadow'=>$service->shadow($key,$features)};$this->fail('fresh revoke must deny');}catch(\DomainException $e){$this->assertStringContainsString('learning_',$e->getMessage());}finally{$this->assertSame([],array_values(array_filter(DB::getQueryLog(),fn($q)=>preg_match('/^\s*(insert|update|delete)/i',$q['query']))));DB::disableQueryLog();}}
  $this->assertSame($pointers,DB::table('learning_candidate_selections')->get()->toJson());
 }
 public function test_native_actual_train_takeover_late_completion_and_witness(): void {
  $this->test_native_training_requires_portable_python_root();$actors=config('services.learning_batch.actors');$actor=$actors[0];$group=DB::table('learning_company_memberships')->where('user_id',$actor)->value('learning_company_group_id');$b=$GLOBALS['t11Root'].'/train-takeover-'.bin2hex(random_bytes(8));mkdir($b,0700,true);
  $child=t11Launch(['op'=>'train-held','name'=>'T','barrier'=>$b,'actor'=>$actor,'group'=>$group,'actors'=>$actors,'mapping'=>config('services.learning_batch.candidate_stage')]);t11Wait($b.'/T.ready');file_put_contents($b.'/T.go','go');t11Wait($b.'/T.entered',45);$entered=json_decode(file_get_contents($b.'/T.entered'),true);$old=$entered['token'];$this->assertSame('running',\App\Models\LearningBatch::findOrFail(1)->status);
  $fresh=\App\Models\LearningBatch::locked($old,function($row)use($actors){$export=app(\App\Services\LearningCaseExport::class);$export->resumeEligibilityFromBatch($row);$bundle=$export->eligibilityBundleForAccounts($actors);$export->persistEligibilityInBatch($row);return $bundle;});
  DB::table('learning_batches')->where('id',1)->update(['lease_until'=>now()->getTimestamp()-1]);$next=t11Launch(['op'=>'claim','name'=>'N','barrier'=>$b]);t11Wait($b.'/N.ready');file_put_contents($b.'/N.go','go');$nextResult=t11Join($next);$this->assertNull($nextResult['error']);$successor=$nextResult['value'];$this->assertSame($old['fence']+1,$successor['fence']);
  file_put_contents($entered['artifact'].'/refresh.json',json_encode(['bundle'=>$fresh])."\n");file_put_contents($entered['artifact'].'/refresh-ready','ready');
  $deadline=microtime(true)+10;do{$lines=explode("\n",trim(file_get_contents($entered['artifact'].'/stdout.log')));if(count($lines)===2)break;usleep(10000);}while(microtime(true)<$deadline);
  file_put_contents($b.'/T.release','release');$out=t11Join($child);$this->assertNull($out['error']);$this->assertSame(1,$out['value']['exit']);$this->assertStringContainsString('stale_owner',$out['value']['output']);$this->assertCount(2,$lines,'actual producer completes fitted package before stale parent resumes');$result=json_decode($lines[1],true);$this->assertArrayHasKey('receipt',$result['candidate']);unset($result['candidate']);
  $row=\App\Models\LearningBatch::findOrFail(1);$this->assertSame($successor['batch_id'],$row->batch_id);$this->assertSame('running',$row->status);$this->assertSame(1,DB::table('learning_candidate_selections')->count());
  try{\App\Console\Commands\RunLearningBatch::accept($old,$result,$entered['context'],fn()=>$fresh);$this->fail('obsolete actual receipt must deny');}catch(\DomainException $e){$this->assertSame('learning_batch.stale_owner',$e->getMessage());}
  $this->assertArrayNotHasKey('dataset_witness',$row->issuer_state);\App\Models\LearningBatch::heartbeat($successor);$this->assertSame('running',$row->fresh()->status);\App\Models\LearningBatch::abort($successor);
 }
}
