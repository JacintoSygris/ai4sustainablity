<?php
use App\Models\Characterization;
use App\Services\LearningP6Snapshot;
// Fixture namespace: t06b-synthetic-only; synthetic_only=true; promotion_allowed=false.
function t06bFrame(): array {
    return ['status'=>'completed','model_profile'=>'synthetic_v1','mapping_metadata'=>['python'=>['serving_identity'=>[
        'profile'=>'synthetic_v1','artifact_sha256'=>['z.pkl'=>str_repeat('b',64),'a.pkl'=>str_repeat('a',64)],
        'policy_sha256'=>[], 'runtime_config'=>['score_threshold'=>null,'policy_active'=>['label_thresholds'=>false,'crc_recall_floor'=>false,'sector_guard'=>false]],
        'serving_identity_sha256'=>str_repeat('c',64),
    ]]]];
}
function t06bModel(array $frame): Characterization {
    $m=new Characterization; $m->setRawAttributes(['status'=>'completed','result_data'=>json_encode($frame,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)]); return $m;
}
it('projects a private three-field snapshot', function () {
    $p=(new LearningP6Snapshot)->project(t06bModel(t06bFrame()));
    expect(array_keys($p))->toBe(['schema_version','p6_snapshot','serving_identity'])
        ->and($p['schema_version'])->toBe('p6-learning-provenance-v1')
        ->and(array_keys($p['p6_snapshot']))->toBe(['model_profile','model_digest','policy_digest']);
});
it('denies corrupt raw storage', function (string $vector) {
    $raw=match($vector) {'missing','null'=>null,'jsonnull'=>'null','truncated'=>'{','utf8'=>"{\"x\":\"\xff\"}",'depth'=>str_repeat('[',513).'0'.str_repeat(']',513),'array'=>[],'bool'=>false,'integer'=>0,'empty'=>'','scalar'=>'"scalar"','object'=>'{}','list'=>'[]'};
    $m=new Characterization; $attrs=['status'=>'completed']; if($vector!=='missing') {$attrs['result_data']=$raw;} $m->setRawAttributes($attrs);
    expect(fn()=>(new LearningP6Snapshot)->project($m))->toThrow(InvalidArgumentException::class);
})->with(['missing','null','jsonnull','truncated','utf8','depth','array','bool','integer','empty','scalar','object','list']);
it('requires completed statuses and associative sections', function (string $path, mixed $value) {
    $f=t06bFrame(); $m=t06bModel($f);
    if($path==='modelstatus') {$m->status=$value;} else {
        $parts=explode('.', $path); $ref=&$f; foreach($parts as $part) {$ref=&$ref[$part];} $ref=$value; unset($ref); $m=t06bModel($f);
    }
    expect(fn()=>(new LearningP6Snapshot)->project($m))->toThrow(InvalidArgumentException::class);
})->with([
    ['modelstatus','processing'],['modelstatus',null],['status','failed'],['status',true],
    ['mapping_metadata',[]],['mapping_metadata','x'],['mapping_metadata.python',[]],['mapping_metadata.python',[1]],
    ['mapping_metadata.python.serving_identity',[]],['mapping_metadata.python.serving_identity',true],
]);
it('denies closed identity and literal profile violations', function (string $vector) {
    $f=t06bFrame(); $i=&$f['mapping_metadata']['python']['serving_identity'];
    switch($vector) {
        case 'extra': $i['extra']=null; break;
        case 'missing': unset($i['serving_identity_sha256']); break;
        case 'mismatch': $f['model_profile']='Synthetic_v1'; break;
        case 'rootmissing': unset($f['model_profile']); break;
        default: $i['profile']=match($vector) {'blank'=>'','space'=>' synthetic_v1','newline'=>"synthetic_v1\n",'unicode'=>'sintético','long'=>str_repeat('a',129),'bool'=>true,'number'=>1}; $f['model_profile']=$i['profile'];
    }
    expect(fn()=>(new LearningP6Snapshot)->project(t06bModel($f)))->toThrow(InvalidArgumentException::class);
})->with(['extra','missing','mismatch','rootmissing','blank','space','newline','unicode','long','bool','number']);
it('denies malformed dictionaries and SHA boundaries', function (string $vector) {
    $f=t06bFrame(); $i=&$f['mapping_metadata']['python']['serving_identity'];
    if(str_starts_with($vector,'sha-')) {
        $sha=match(substr($vector,4)) {'short'=>str_repeat('a',63),'long'=>str_repeat('a',65),'newline'=>str_repeat('a',64)."\n",'upper'=>str_repeat('A',64),'bad'=>str_repeat('g',64),'bool'=>false,'int'=>1,'null'=>null,'array'=>[],'empty'=>''};
        $i['artifact_sha256']['a.pkl']=$sha; $i['policy_sha256']=['sector_guard'=>$sha]; $i['serving_identity_sha256']=$sha;
    } else { switch($vector) {
        case 'art-empty': $i['artifact_sha256']=[]; break;
        case 'art-list': $i['artifact_sha256']=[str_repeat('a',64)]; break;
        case 'art-scalar': $i['artifact_sha256']='x'; break;
        case 'policy-list': $i['policy_sha256']=[str_repeat('a',64)]; break;
        case 'policy-scalar': $i['policy_sha256']='x'; break;
        case 'policy-unknown': $i['policy_sha256']=['unknown'=>str_repeat('a',64)]; break;
        default: $key=match($vector) {'dot'=>'.','dotdot'=>'..','slash'=>'a/b.pkl','backslash'=>'a\\b.pkl','colon'=>'a:b.pkl','space'=>'a b.pkl','newline'=>"a.pkl\n",'unicode'=>'á.pkl','long'=>str_repeat('a',129),'numeric'=>0}; $i['artifact_sha256']=[$key=>str_repeat('a',64)];
    }}
    expect(fn()=>(new LearningP6Snapshot)->project(t06bModel($f)))->toThrow(InvalidArgumentException::class);
})->with(['art-empty','art-list','art-scalar','policy-list','policy-scalar','policy-unknown','dot','dotdot','slash','backslash','colon','space','newline','unicode','long','numeric','sha-short','sha-long','sha-newline','sha-upper','sha-bad','sha-bool','sha-int','sha-null','sha-array','sha-empty']);
it('checks each SHA slot separately', function (string $slot, string $value) {
    $f=t06bFrame(); $i=&$f['mapping_metadata']['python']['serving_identity'];
    if($slot==='source') {$i['serving_identity_sha256']=$value;} elseif($slot==='policy') {$i['policy_sha256']=['sector_guard'=>$value];} else {$i['artifact_sha256']['a.pkl']=$value;}
    expect(fn()=>(new LearningP6Snapshot)->project(t06bModel($f)))->toThrow(InvalidArgumentException::class);
})->with(['artifact','policy','source'])->with([str_repeat('a',63),str_repeat('a',65),str_repeat('a',64)."\n",str_repeat('A',64)]);
it('denies runtime shapes and coercion', function (string $vector) {
    $f=t06bFrame(); $r=&$f['mapping_metadata']['python']['serving_identity']['runtime_config'];
    switch($vector) {
        case 'extra': $r['extra']=null; break;
        case 'missing': unset($r['score_threshold']); break;
        case 'list': $r=[1]; break;
        case 'null': $r=null; break;
        case 'flags-extra': $r['policy_active']['extra']=false; break;
        case 'flags-missing': unset($r['policy_active']['sector_guard']); break;
        case 'flags-list': $r['policy_active']=[false,false,false]; break;
        case 'flags-null': $r['policy_active']=null; break;
        case 'active-nohash': $r['policy_active']['sector_guard']=true; break;
        case 'hash-inactive': $f['mapping_metadata']['python']['serving_identity']['policy_sha256']=['sector_guard'=>str_repeat('d',64)]; break;
        case 'flag-int': $r['policy_active']['sector_guard']=0; break;
        case 'flag-string': $r['policy_active']['sector_guard']='false'; break;
        default: $r['score_threshold']=match($vector) {'bool'=>false,'string'=>'0.95','low'=>-0.01,'high'=>1.01,'array'=>[]};
    }
    expect(fn()=>(new LearningP6Snapshot)->project(t06bModel($f)))->toThrow(InvalidArgumentException::class);
})->with(['extra','missing','list','null','flags-extra','flags-missing','flags-list','flags-null','active-nohash','hash-inactive','flag-int','flag-string','bool','string','low','high','array']);
it('denies raw overflow rather than coercing it', function () {
    $m=t06bModel(t06bFrame()); $raw=str_replace('"score_threshold":null','"score_threshold":1e999',$m->getAttributes()['result_data']); $m->setRawAttributes(['status'=>'completed','result_data'=>$raw]);
    expect(fn()=>(new LearningP6Snapshot)->project($m))->toThrow(InvalidArgumentException::class);
});

it('matches exact producer AST synthetic goldens', function (string $json, string $model, string $policy) {
    $p=(new LearningP6Snapshot)->project(t06bModel(json_decode($json,true,512,JSON_THROW_ON_ERROR)));
    expect($p['p6_snapshot'])->toBe(['model_profile'=>'synthetic_v1','model_digest'=>$model,'policy_digest'=>$policy]);
})->with([
    'legacy'=>['{"status":"completed","model_profile":"synthetic_v1","mapping_metadata":{"python":{"serving_identity":{"profile":"synthetic_v1","artifact_sha256":{"z.pkl":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","a.pkl":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"policy_sha256":{},"runtime_config":{"score_threshold":null,"policy_active":{"label_thresholds":false,"crc_recall_floor":false,"sector_guard":false}},"serving_identity_sha256":"f8dd9db08fcf691eb67bc67ed981df9eed5d0fdc416000232a697b20ee094c31"}}}}','fbdb12d67c8af2315cfd163c1758f49ae03c9328ac2b4872b8cf8c93e2d85e9f','647a54e1fbf7890733dfedb88eb65cd845ea5ba325e8239f894d45d286b67421'],
    'current'=>['{"status":"completed","model_profile":"synthetic_v1","mapping_metadata":{"python":{"serving_identity":{"profile":"synthetic_v1","artifact_sha256":{"z.pkl":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","a.pkl":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"policy_sha256":{"label_thresholds":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","crc_recall_floor":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","sector_guard":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd"},"runtime_config":{"score_threshold":0.95,"policy_active":{"label_thresholds":true,"crc_recall_floor":true,"sector_guard":true}},"serving_identity_sha256":"e05a090d69ef9f4350d9d6b86f3c3f7b7fa2558c98f68b0288503eb378d3725b"}}}}','fbdb12d67c8af2315cfd163c1758f49ae03c9328ac2b4872b8cf8c93e2d85e9f','5af59c85710002d7e1dc11b8ea68fb369fd6801ff9f0946039a4e7b74098c2e4'],
    'zero'=>['{"status":"completed","model_profile":"synthetic_v1","mapping_metadata":{"python":{"serving_identity":{"profile":"synthetic_v1","artifact_sha256":{"z.pkl":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","a.pkl":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"policy_sha256":{},"runtime_config":{"score_threshold":0,"policy_active":{"label_thresholds":false,"crc_recall_floor":false,"sector_guard":false}},"serving_identity_sha256":"52f9b0850a65758d637c723973997d461b7ad527b46b03aaf8e76c290031a76a"}}}}','fbdb12d67c8af2315cfd163c1758f49ae03c9328ac2b4872b8cf8c93e2d85e9f','b30d505b19d9d7875d9a4fd15204347b1a9b0b5ecdc4feaffdb205e8fb59e0e4'],
    'one'=>['{"status":"completed","model_profile":"synthetic_v1","mapping_metadata":{"python":{"serving_identity":{"profile":"synthetic_v1","artifact_sha256":{"z.pkl":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","a.pkl":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"},"policy_sha256":{},"runtime_config":{"score_threshold":1.0,"policy_active":{"label_thresholds":false,"crc_recall_floor":false,"sector_guard":false}},"serving_identity_sha256":"169757754756fe6ee342f08e15c91538d228fba21404cbbbb28218a847f0c64b"}}}}','fbdb12d67c8af2315cfd163c1758f49ae03c9328ac2b4872b8cf8c93e2d85e9f','a9ad8aadb5350aea9993291b768f72058556514fcb1a098315bc3cd228a13f77'],
]);
it('isolates metadata order and detached output', function () {
    $f=t06bFrame(); $m=t06bModel($f); $before=$m->getAttributes(); $s=new LearningP6Snapshot; $expected=$s->project($m);
    $i=&$f['mapping_metadata']['python']['serving_identity']; $i=array_reverse($i,true); $i['artifact_sha256']=array_reverse($i['artifact_sha256'],true); $i['runtime_config']=array_reverse($i['runtime_config'],true); $i['runtime_config']['policy_active']=array_reverse($i['runtime_config']['policy_active'],true);
    $f['request_payload']=['name'=>'ENTITY_SYNTHETIC','notes'=>'SYNTHETIC']; $f['feature_metadata']=['SourceMetadata'=>'SYNTHETIC']; $f['mapping_metadata']['laravel']=['unrelated'=>true]; $f['summary']='SYNTHETIC'; $f['id']=999; $f['timestamp']='SYNTHETIC';
    $other=t06bModel($f); $other->form_data=['notes'=>'SYNTHETIC']; $other->id=123;
    expect($s->project($other))->toBe($expected);
    $output=$s->project($m); $output['serving_identity']['artifact_sha256']['a.pkl']='changed'; $output['serving_identity']['runtime_config']['policy_active']['sector_guard']=true; $output['p6_snapshot']['model_digest']='changed';
    expect($m->getAttributes())->toBe($before)->and($s->project($m))->toBe($expected);
});
it('references current unsaved attributes and component changes', function () {
    $s=new LearningP6Snapshot; $f=t06bFrame(); $m=t06bModel($f); $m->syncOriginal(); $original=$m->getRawOriginal('result_data'); $base=$s->project($m)['p6_snapshot'];
    $f['mapping_metadata']['python']['serving_identity']['runtime_config']['score_threshold']=0.5; $m->result_data=$f;
    expect($m->getRawOriginal('result_data'))->toBe($original)->and($s->project($m)['p6_snapshot']['model_digest'])->toBe($base['model_digest'])->and($s->project($m)['p6_snapshot']['policy_digest'])->not->toBe($base['policy_digest']);
    $policy=$s->project($m)['p6_snapshot']['policy_digest']; $f['mapping_metadata']['python']['serving_identity']['policy_sha256']=['sector_guard'=>str_repeat('d',64)]; $f['mapping_metadata']['python']['serving_identity']['runtime_config']['policy_active']['sector_guard']=true;
    $p=$s->project(t06bModel($f))['p6_snapshot']; expect($p['model_digest'])->toBe($base['model_digest'])->and($p['policy_digest'])->not->toBe($policy);
    $f['mapping_metadata']['python']['serving_identity']['policy_sha256']['sector_guard']=str_repeat('e',64); $q=$s->project(t06bModel($f))['p6_snapshot']; expect($q['model_digest'])->toBe($p['model_digest'])->and($q['policy_digest'])->not->toBe($p['policy_digest']);
    $f['mapping_metadata']['python']['serving_identity']['artifact_sha256']['a.pkl']=str_repeat('f',64); $f['mapping_metadata']['python']['serving_identity']['serving_identity_sha256']=str_repeat('f',64); $q=$s->project(t06bModel($f))['p6_snapshot']; expect($q['model_digest'])->not->toBe($p['model_digest'])->and($q['policy_digest'])->not->toBe($p['policy_digest']);
    $f['model_profile']='synthetic_v2'; $f['mapping_metadata']['python']['serving_identity']['profile']='synthetic_v2'; expect($s->project(t06bModel($f))['p6_snapshot']['model_digest'])->not->toBe($q['model_digest']);
});
it('retains strict decode causes', function (string $raw) {
    $m=new Characterization; $m->setRawAttributes(['status'=>'completed','result_data'=>$raw]);
    try { (new LearningP6Snapshot)->project($m); $this->fail('admitted'); }
    catch(InvalidArgumentException $e) { expect($e->getMessage())->toBe('learning_p6.result_data_invalid')->and($e->getCode())->toBe(0)->and($e->getPrevious())->toBeInstanceOf(JsonException::class); }
})->with(['{','invalid-utf8-raw-result' => "{\"x\":\"\xff\"}",str_repeat('[',513).'0'.str_repeat(']',513)]);
it('accepts literal length and numeric boundaries without inventory claims', function () {
    $f=t06bFrame(); $f['model_profile']=str_repeat('a',128); $i=&$f['mapping_metadata']['python']['serving_identity']; $i['profile']=$f['model_profile']; $i['artifact_sha256']=[str_repeat('b',128)=>str_repeat('0',64)];
    foreach([null,0,1,0.0,1.0] as $score) { $i['runtime_config']['score_threshold']=$score; $p=(new LearningP6Snapshot)->project(t06bModel($f)); expect($p['serving_identity']['runtime_config']['score_threshold'])->toBe($score)->and($p['serving_identity']['policy_sha256'])->toBe([]); }
});
