<?php
use App\Models\Characterization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);
beforeEach(function () {
    config(['services.private_dev.auto_login' => false]);
    $this->user = User::factory()->create();
    $this->characterization = Characterization::factory()->create(['user_id'=>$this->user->id, 'esrs_topic_ids'=>[], 'form_data'=>[]]);
    $this->actingAs($this->user);
});
function t04Packet($authority) { return ['schema_version'=>'datapoint-feedback-v1','authority_digest'=>$authority,'reviewed_datapoint_ids'=>['BP-1_01'],'decisions'=>[['datapoint_id'=>'BP-1_01','relevant'=>false,'selected_to_answer'=>false,'reason_codes'=>['other'],'note'=>'Synthetic review']]]; }
it('roundtrips explicit negative review independently of missing metrics and preserves legacy evidence', function () {
    $initial=$this->getJson('/api/esrs-datapoints/responses')->assertOk()->json('data');
    $packet=t04Packet($initial['learning_authority_digest'] ?? str_repeat('a',64));
    $put=$this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>0,'responses'=>[['datapoint_id'=>'BP-1_01','status'=>'draft']], 'learning_feedback'=>$packet])->assertOk()->json('data');
    expect($put['learning_feedback'] ?? null)->toBe($packet);
    expect($put['summary']['completed_count'])->toBe(0);
    expect($put['source_digest'])->not->toBe($initial['source_digest']);
    $this->getJson('/api/esrs-datapoints/responses')->assertJsonPath('data.learning_feedback.decisions.0.relevant',false);
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>1,'responses'=>[]])->assertOk()->assertJsonPath('data.learning_feedback.decisions.0.note','Synthetic review');
    $before=$this->characterization->fresh()->form_data;
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>1,'responses'=>[],'learning_feedback'=>$packet])->assertStatus(409);
    expect($this->characterization->fresh()->form_data)->toBe($before);
});
it('rejects malformed explicit review without writes', function ($kind) {
    $initial=$this->getJson('/api/esrs-datapoints/responses')->json('data');
    $packet=t04Packet($initial['learning_authority_digest'] ?? str_repeat('a',64));
    if($kind==='string') $packet['decisions'][0]['relevant']='false';
    if($kind==='integer') $packet['decisions'][0]['selected_to_answer']=0;
    if($kind==='missing') unset($packet['decisions'][0]['relevant']);
    if($kind==='unknown') { $packet['reviewed_datapoint_ids']=['UNKNOWN']; $packet['decisions'][0]['datapoint_id']='UNKNOWN'; }
    if($kind==='duplicate') $packet['decisions'][]=$packet['decisions'][0];
    if($kind==='extra') $packet['reviewed_datapoint_ids']=[];
    if($kind==='stale') $packet['authority_digest']=str_repeat('b',64);
    $before=$this->characterization->fresh()->form_data;
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>0,'responses'=>[],'learning_feedback'=>$packet])->assertStatus(422);
    expect($this->characterization->fresh()->form_data)->toBe($before);
})->with(['string','integer','missing','unknown','duplicate','extra','stale']);

it('keeps empty review unobserved and normative completion unchanged', function () {
    $initial=$this->getJson('/api/esrs-datapoints/responses')->json('data');
    expect($initial['learning_feedback']['reviewed_datapoint_ids'])->toBe([]);
    $corpus=$this->getJson('/api/esrs-datapoints')->json('data');
    $packet=t04Packet($initial['learning_authority_digest']);
    $result=$this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>0,'responses'=>[], 'learning_feedback'=>$packet])->assertOk()->json('data');
    expect($result['summary'])->toBe($initial['summary']);
    expect($this->getJson('/api/esrs-datapoints')->json('data'))->toBe($corpus);
    $packet['reviewed_datapoint_ids']=[]; $packet['decisions']=[];
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>1,'responses'=>[], 'learning_feedback'=>$packet])->assertOk()->assertJsonPath('data.learning_feedback.decisions',[]);
});
it('preserves stored evidence but hides labels after catalog snapshot changes', function () {
    $state=app(\App\Services\EsrsDatapointResponseState::class);
    $builder=app(\App\Services\EsrsDatapointCorpusBuilder::class);
    $corpus=$builder->build($this->characterization);
    $packet=t04Packet($state->learningAuthorityDigest($corpus));
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>0,'responses'=>[],'learning_feedback'=>$packet])->assertOk();
    $stored=$this->characterization->fresh(); $before=$stored->form_data;
    $corpus['generation']['workbook_version']='synthetic-next-version';
    expect($state->state($stored,$corpus)['learning_feedback']['decisions'])->toBe([]);
    expect($stored->fresh()->form_data)->toBe($before);
});
it('rejects ambiguous legacy selection without erasing explicit review evidence', function () {
    $initial=$this->getJson('/api/esrs-datapoints/responses')->json('data');
    $packet=t04Packet($initial['learning_authority_digest']);
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>0,'responses'=>[],'learning_feedback'=>$packet])->assertOk();
    $before=$this->characterization->fresh()->form_data;
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>1,'responses'=>[['datapoint_id'=>'BP-1_01','status'=>'draft','selected_to_answer'=>true]]])->assertStatus(422);
    expect($this->characterization->fresh()->form_data)->toBe($before);
});

it('rejects JSON objects where explicit universe lists are required', function () {
    $initial=$this->getJson('/api/esrs-datapoints/responses')->json('data');
    $packet=t04Packet($initial['learning_authority_digest']);
    $packet['reviewed_datapoint_ids']=(object)[]; $packet['decisions']=[];
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>0,'responses'=>[],'learning_feedback'=>$packet])->assertStatus(422);
});
it('never projects stored unknown review ids as current observations', function () {
    $state=app(\App\Services\EsrsDatapointResponseState::class);
    $corpus=app(\App\Services\EsrsDatapointCorpusBuilder::class)->build($this->characterization);
    $packet=t04Packet($state->learningAuthorityDigest($corpus));
    $packet['reviewed_datapoint_ids']=['UNKNOWN']; $packet['decisions'][0]['datapoint_id']='UNKNOWN';
    $this->characterization->forceFill(['form_data'=>['esrs_datapoint_responses'=>['learning_feedback'=>$packet]]])->save();
    expect($state->state($this->characterization->fresh(),$corpus)['learning_feedback']['decisions'])->toBe([]);
});

it('returns corpus and response state from one captured normalized mapping read', function () {
    $calls=0;
    $repo=Mockery::mock(\App\Services\Ar16MatterDrMappingRepository::class);
    $repo->shouldReceive('approved')->andReturnUsing(function () use (&$calls) {
        $calls++;
        return ['version'=>'synthetic-'.$calls,'source'=>['name'=>'Synthetic','status'=>'approved','approved_at'=>null],'by_topic_id'=>[999=>['ar16_topic_id'=>999,'esrs_code'=>'E1','disclosure_requirements'=>['E1-1']]],'duplicate_topic_ids'=>[]];
    });
    app()->instance(\App\Services\Ar16MatterDrMappingRepository::class,$repo);
    $snapshot=$this->getJson('/api/esrs-datapoints')->assertOk()->json();
    expect($calls)->toBe(1);
    expect($snapshot['snapshot_version'] ?? null)->toBe('p9-workspace-v1');
    expect($snapshot['response_state']['revision'])->toBe(0);
    $corpus=$snapshot['data'];
    expect($snapshot['response_state']['learning_authority_digest'])->toBe(app(\App\Services\EsrsDatapointResponseState::class)->learningAuthorityDigest($corpus));
    expect($calls)->toBe(1);
});
it('renews a coherent namespace under phase-in drift without erasing stored negatives', function () {
    $a=$this->getJson('/api/esrs-datapoints')->assertOk()->json();
    $packet=t04Packet($a['data']['learning_authority_digest'] ?? str_repeat('a',64));
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>0,'responses'=>[],'learning_feedback'=>$packet])->assertOk();
    $stored=$this->characterization->fresh()->form_data;
    $changed=$stored; $changed['operations']['employee_count']=1000;
    $this->characterization->forceFill(['form_data'=>$changed])->save();
    $b=$this->getJson('/api/esrs-datapoints')->assertOk()->json();
    expect($b['response_state']['revision'])->toBe(1);
    expect($b['data']['learning_authority_digest'])->toBe($b['response_state']['learning_authority_digest']);
    expect($b['data']['learning_authority_digest'])->not->toBe($a['data']['learning_authority_digest']);
    expect($b['response_state']['learning_feedback']['decisions'])->toBe([]);
    expect($this->characterization->fresh()->form_data['esrs_datapoint_responses']['learning_feedback'])->toBe($packet);
});

it('rejects old authority for a shared id after scope drift and returns one fresh revision', function () {
    $a=$this->getJson('/api/esrs-datapoints')->assertOk()->json();
    $packet=t04Packet($a['data']['learning_authority_digest']);
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>0,'responses'=>[],'learning_feedback'=>$packet])->assertOk();
    $stored=$this->characterization->fresh()->form_data;
    $stored['materiality_confirmation']['confirmed_topic_ids']=[999];
    $this->characterization->forceFill(['form_data'=>$stored])->save();
    $b=$this->getJson('/api/esrs-datapoints')->assertOk()->json();
    expect(collect($b['data']['blocks']['always_required']['datapoints'])->pluck('id')->all())->toContain('BP-1_01');
    expect($b['response_state']['revision'])->toBe(1);
    expect($b['response_state']['learning_feedback']['decisions'])->toBe([]);
    expect($b['data']['learning_authority_digest'])->toBe($b['response_state']['learning_authority_digest']);
    $this->putJson('/api/esrs-datapoints/responses',['expected_revision'=>1,'responses'=>[],'learning_feedback'=>$packet])->assertStatus(422);
    expect($this->characterization->fresh()->form_data)->toBe($stored);
});
