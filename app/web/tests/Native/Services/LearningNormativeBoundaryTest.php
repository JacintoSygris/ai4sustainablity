<?php
namespace App\Services {
 // Refuse, without reading, the official source in this native-only test process.
 if (isset($GLOBALS['t11Root'], $GLOBALS['t11Profile'])) {
 function file_get_contents($path,...$args) {
  if(str_ends_with(str_replace('\\','/',$path),'data/esrs_datapoints_ig3.json'))throw new \RuntimeException('T11 tiny input ignored: official corpus access refused');
  return \file_get_contents($path,...$args);
 }
 }
}
namespace Tests\Feature\Services {
require_once __DIR__.'/../../Support/learning-synthetic-fixture.php';
final class LearningNormativeBoundaryTest extends \Tests\TestCase
{
 use \T11NativeApplication;
 public function test_native_raw_bigint_identity(): void {
  $c=\Illuminate\Support\Facades\DB::connection();
  $this->assertSame($GLOBALS['t11Engine'],$c->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
  $type=$GLOBALS['t11Engine']==='mysql'?'SIGNED':'BIGINT';
  $this->assertSame(42,$c->selectOne('SELECT CAST(42 AS '.$type.') AS n')->n);
 }
 public function test_native_actual_builder_learning_feedback_does_not_change_obligations_or_facts(): void {
  $f=t11Fixture();$row=$f['row'];
  $map=$GLOBALS['t11Root'].'/tiny-map-'.bin2hex(random_bytes(8)).'.json';
  file_put_contents($map,json_encode(['version'=>'T11-fictional-map','source'=>['name'=>'SYNTHETIC','status'=>'approved','approved_at'=>'2026-01-01'],'mappings'=>[['ar16_topic_id'=>1,'esrs_code'=>'SYNTHETIC','disclosure_requirements'=>['SYN-DR']]]],JSON_THROW_ON_ERROR));
  config(['services.esrs_datapoints.matter_dr_mapping_path'=>$map]);
  $dp=fn($id,$inclusion,$voluntary=false)=>['id'=>$id,'esrs'=>'SYNTHETIC','dr'=>'SYN-DR','inclusion_type'=>$inclusion,'paragraph'=>'synthetic','related_ar'=>null,'name'=>'FICTIONAL','data_type'=>'narrative','conditional_or_alternative'=>null,'may_disclose'=>$voluntary,'appendix_b'=>null,'phase_in_less_than_750'=>null,'phase_in_all'=>null];
  $source=['source'=>['name'=>'SYNTHETIC','url'=>'synthetic:tiny','sha256'=>hash('sha256','tiny'),'workbook_version'=>'fictional-v1','downloaded_at'=>'2026-01-01','note'=>'SYNTHETIC ONLY'],'datapoints'=>[$dp('BASE','always_required'),$dp('TOPIC','materiality_based'),$dp('VOLUNTARY','materiality_based',true),$dp('MDR','minimum_disclosure_requirement')]];
  $repo=new \App\Services\Ar16MatterDrMappingRepository;
  $builder=new \App\Services\EsrsDatapointCorpusBuilder($repo,$source);
  $state=new \App\Services\EsrsDatapointResponseState($repo);
  $before=$builder->build($row->fresh());
  $this->assertSame('dr_level',$before['generation']['coverage_status']);
  $this->assertSame(['BASE','TOPIC','MDR'],$state->requiredDatapointIds($before));
  $tx=new \App\Services\CharacterizationStateTransaction;
  $tx->runForUser($f['u']->id,function($r){$form=$r->form_data;$form['esrs_datapoint_responses']['responses']=['BASE'=>['datapoint_id'=>'BASE','status'=>'completed','value'=>'SYNTHETIC FACT','evidence_refs'=>[]]];$r->form_data=$form;$r->save();});
  $f['closure']->close($f['u']->id,t11Input($f));
  $f['ledger']->grant($f['u']->id,$f['g']->id,'synthetic:curation',0,hash('sha256','T11-curation'));
  $export=t11Exporter($f);$packet=$export->exportForAccount($f['u']->id,$export->referenceForAccount($f['u']->id));
  $curation=\App\Services\LearningCaseCuration::forSyntheticTests(['namespace'=>'test-namespace:t07-export','synthetic_only'=>true,'promotion_allowed'=>false,'purpose'=>'synthetic:curation'],$export,$f['ledger']);
  $command=['schema_version'=>'learning-case-annotation-command-v1','export_digest'=>$packet['digest'],'reference'=>$packet['proofs']['reference'],'expected_annotation_revision'=>0,'command_id'=>hash('sha256','T11-annotation'),'topic_labels'=>[['topic_id'=>'1','value'=>0,'observed_mask'=>1]],'notes'=>['status'=>'withheld','reason'=>'free_text_transport_disabled']];
  \Illuminate\Support\Facades\DB::table('reporting_facts')->insert(['characterization_id'=>$row->id,'fact_id'=>'synthetic-fact','datapoint_id'=>'BASE','value'=>json_encode(['text'=>'SYNTHETIC FACT']),'approval_status'=>'reviewed']);
  $row=$row->fresh();$facts=\Illuminate\Support\Facades\DB::table('reporting_facts')->get()->toJson();
  $projector=new \App\Services\Report\ReportingFactProjector($builder);
  $readiness=new \App\Services\Report\ReportContentReadiness(new \App\Services\Report\ReportClaimBuilder);
  $projected=$projector->projectLegacy($row);$ready=$readiness->assess($projected,$state->requiredDatapointIds($before));$summary=$state->reportSummary($row,$before);
  $annotation=$curation->importForAccount($f['u']->id,$f['u']->id,$packet['jsonl'],json_encode($command,JSON_THROW_ON_ERROR));
  $this->assertSame([['observed_mask'=>1,'topic_id'=>'1','value'=>0]],$annotation['topic_labels']);$this->assertSame(1,\Illuminate\Support\Facades\DB::table('learning_case_annotations')->count());
  $feedback=['schema_version'=>'datapoint-feedback-v1','authority_digest'=>$state->learningAuthorityDigest($before),'reviewed_datapoint_ids'=>['BASE','TOPIC'],'decisions'=>[['datapoint_id'=>'BASE','relevant'=>false,'selected_to_answer'=>false,'reason_codes'=>['scope'],'note'=>null],['datapoint_id'=>'TOPIC','relevant'=>false,'selected_to_answer'=>false,'reason_codes'=>['scope'],'note'=>null]]];
  $state->validateLearningFeedback($feedback,$before);
  $tx->runForUser($f['u']->id,function($r)use($feedback){$form=$r->form_data;$form['esrs_datapoint_responses']['learning_feedback']=$feedback;$r->form_data=$form;$r->save();});
  $row=$row->fresh();$after=$builder->build($row);
  $this->assertSame($before,$after);$this->assertSame($summary,$state->reportSummary($row,$after));
  $this->assertSame($projected,$projector->projectLegacy($row));$this->assertSame($ready,$readiness->assess($projector->projectLegacy($row),$state->requiredDatapointIds($after)));
  $this->assertSame($facts,\Illuminate\Support\Facades\DB::table('reporting_facts')->get()->toJson());
  $other=$source;$other['datapoints']=[$dp('SECOND','always_required')];
  $second=(new \App\Services\EsrsDatapointCorpusBuilder($repo,$other))->build($row);
  $this->assertSame(['SECOND'],$state->corpusDatapointIds($second),'instance fixture precedes legacy static source cache');
  $profile=config('services.learning_native_fixture');
  config(['services.learning_native_fixture'=>null]);
  try{$builder->build($row);$this->fail('withdrawn native profile must deny captured fixture');}catch(\DomainException $e){$this->assertSame('esrs_builder.native_fixture_denied',$e->getMessage());}
  config(['services.learning_native_fixture'=>$profile]);
  app()->instance('env','production');
  try{new \App\Services\EsrsDatapointCorpusBuilder($repo,$source);$this->fail('production fixture must deny');}catch(\DomainException $e){$this->assertSame('esrs_builder.native_fixture_denied',$e->getMessage());}finally{app()->instance('env','testing');}
 }
}
}
