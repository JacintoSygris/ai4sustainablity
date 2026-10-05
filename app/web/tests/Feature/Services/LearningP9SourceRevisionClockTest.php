<?php

use App\Models\{Characterization, User};
use App\Services\{CharacterizationStateTransaction, LearningP9SourceRevisionClock};
use Illuminate\Support\Facades\{DB, Http, Schema};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

beforeEach(function () {
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    expect(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->commit();
    RefreshDatabaseState::$migrated = false;
    Http::preventStrayRequests();
    config(['services.learning_source_clock.enabled' => false,
        'services.learning_p6_base_source_clock.enabled' => false,
        'services.learning_p8_source_clock.enabled' => false,
        'services.learning_p9_source_clock.enabled' => true]);
    Schema::create('learning_p9_source_revisions', function (Blueprint $table) {
        $table->foreignId('characterization_id')->primary()->constrained('characterizations')->cascadeOnDelete();
        $table->unsignedBigInteger('generation');
        $table->unsignedBigInteger('revision');
        $table->string('epoch', 64);
        $table->string('digest', 64);
    });
});

function t06j_row(): Characterization
{
    return Characterization::query()->create(['user_id' => User::factory()->create()->id,
        'status' => 'draft', 'submission_generation' => 1, 'form_data' => []])->fresh();
}

function t06j_write(Characterization $row, array $changes): mixed
{
    return DB::transaction(fn () => (new LearningP9SourceRevisionClock)->observe($row->user_id,
        fn () => Characterization::query()->find($row->id),
        fn () => DB::table('characterizations')->where('id', $row->id)->update($changes)));
}

function t06j_form(string $value): string
{
    return '{"esrs_datapoint_responses":'.$value.'}';
}

function t06j_snapshot(): array
{
    $out = [];
    foreach (['characterizations', 'learning_p5_source_revisions', 'learning_p6_base_source_revisions',
        'learning_p8_source_revisions', 'learning_p9_source_revisions'] as $table) {
        $out[$table] = DB::table($table)->orderBy($table === 'characterizations' ? 'id' : 'characterization_id')
            ->get()->map(fn ($row) => (array) $row)->all();
    }
    return $out;
}

it('tracer1 passive absent OFF testing gate and same-source noop never seed', function () {
    $row = t06j_row(); $clock = new LearningP9SourceRevisionClock;
    foreach ([null, false, 'true', 1] as $flag) {
        config(['services.learning_p9_source_clock.enabled' => $flag]);
        expect(LearningP9SourceRevisionClock::enabled())->toBeFalse();
        expect($clock->current($row->id))->toBeNull();
        expect($clock->observe($row->user_id, fn () => throw new RuntimeException('lookup'), fn () => 'OFF'))->toBe('OFF');
    }
    config(['services.learning_p9_source_clock.enabled' => true]);
    app()->instance('env', 'production');
    expect(LearningP9SourceRevisionClock::enabled())->toBeFalse();
    app()->instance('env', 'testing');
    expect($clock->current($row->id))->toBeNull();
    $before = t06j_snapshot();
    expect(t06j_write($row, ['form_data' => '[]']))->toBe(1);
    expect(t06j_snapshot())->toBe($before);
    expect(DB::connection()->transactionLevel())->toBe(0);
});

it('tracer2 committed ABA and nested observation record only final persisted history', function () {
    $row = t06j_row(); $clock = new LearningP9SourceRevisionClock;
    t06j_write($row, ['form_data' => t06j_form('{"synthetic":"A"}')]);
    $a = $clock->current($row->id);
    expect($a['revision'])->toBe(1);
    t06j_write($row, ['form_data' => t06j_form('{"synthetic":"B"}')]);
    t06j_write($row, ['form_data' => t06j_form('{"synthetic":"A"}')]);
    $again = $clock->current($row->id);
    expect($again['revision'])->toBe(3)->and($again['digest'])->toBe($a['digest']);
    DB::transaction(fn () => $clock->observe($row->user_id, fn () => $row->fresh(), function () use ($row) {
        t06j_write($row, ['form_data' => t06j_form('{"synthetic":"B"}')]);
        t06j_write($row, ['form_data' => t06j_form('{"synthetic":"A"}')]);
    }));
    expect($clock->current($row->id))->toBe($again);
    expect($clock->hasActiveScope($row->user_id))->toBeFalse();
    expect(DB::connection()->transactionLevel())->toBe(0);
});

it('tracer2 distinguishes complete typed raw member and root provenance', function ($left, $right) {
    $row = t06j_row(); $clock = new LearningP9SourceRevisionClock;
    t06j_write($row, ['form_data' => $left]); $a = $clock->current($row->id);
    t06j_write($row, ['form_data' => $right]); $b = $clock->current($row->id);
    expect($b['revision'])->toBe(($a['revision'] ?? 0) + 1);
    if ($a !== null) { expect($b['digest'])->not->toBe($a['digest']); }
})->with([[null, 'null'], ['null', '[]'], ['[]', '{}'], ['{}', t06j_form('null')],
    [t06j_form('{}'), t06j_form('[]')], [t06j_form('true'), t06j_form('"true"')],
    [t06j_form('1'), t06j_form('1.0')], [t06j_form('1'), t06j_form('"1"')],
    [t06j_form('[1,2]'), t06j_form('[2,1]')]]);

it('tracer2 canonical keys foreign changes and raw historical feedback stay independent', function () {
    $row = t06j_row(); $clock = new LearningP9SourceRevisionClock;
    t06j_write($row, ['form_data' => '{"esrs_datapoint_responses":{"b":2,"a":{"z":0,"x":1}},"other":0}']);
    $a = $clock->current($row->id);
    t06j_write($row, ['form_data' => '{"other":9,"esrs_datapoint_responses":{"a":{"x":1,"z":0},"b":2}}']);
    expect($clock->current($row->id))->toBe($a);
    $corpus = ['blocks' => [['datapoints' => [['id' => 'SYNTHETIC_DP']]]]];
    $response = new \App\Services\EsrsDatapointResponseState(Mockery::mock(\App\Services\Ar16MatterDrMappingRepository::class));
    foreach (['revision', 'updated_at', 'responses', 'learning_feedback'] as $field) {
        t06j_write($row, ['form_data' => t06j_form(json_encode([$field => ['synthetic' => $field]]))]);
        $next = $clock->current($row->id);
        expect($next['revision'])->toBe($a['revision'] + 1);
        $a = $next;
        expect($response->state($row->fresh(), $corpus)['learning_feedback']['decisions'])->toBe([]);
    }
    $raw = t06j_form('{"learning_feedback":{"authority_digest":"stale","decisions":"malformed"}}');
    t06j_write($row, ['form_data' => $raw]);
    expect($row->fresh()->getRawOriginal('form_data'))->toBe($raw);
    expect($response->state($row->fresh(), $corpus)['learning_feedback']['decisions'])->toBe([]);
});

it('tracer2 observes creation without reconstructing unknown history', function () {
    $user = User::factory()->create(); $clock = new LearningP9SourceRevisionClock;
    DB::transaction(fn () => $clock->observe($user->id,
        fn () => Characterization::query()->where('user_id', $user->id)->first(),
        fn () => Characterization::query()->create(['user_id' => $user->id, 'status' => 'draft',
            'submission_generation' => 0, 'form_data' => []])));
    $row = Characterization::query()->where('user_id', $user->id)->first();
    expect($clock->current($row->id)['revision'])->toBe(1);
});

it('tracer3 rejects malformed source and generation with full rollback recovery', function ($field, $value) {
    $row = t06j_row(); t06j_write($row, ['form_data' => t06j_form('"A"')]); $before = t06j_snapshot();
    expect(fn () => t06j_write($row, [$field => $value]))->toThrow(DomainException::class);
    expect(t06j_snapshot())->toBe($before);
    t06j_write($row, ['form_data' => t06j_form('"RECOVER"')]);
    expect((new LearningP9SourceRevisionClock)->current($row->id)['revision'])->toBe(2);
})->with([['form_data','{'], 'invalid-utf8-form-json' => ['form_data', "\"\xff\""], ['form_data', str_repeat('[', 513).str_repeat(']', 513)],
    ['form_data', 'true'], ['form_data','[1]'], ['form_data','1e999'],
    ['form_data',t06j_form('{"x":18446744073709551617}')],
    ['form_data',t06j_form('{"x":-18446744073709551617}')],
    ['submission_generation',0], ['submission_generation','1x'], ['submission_generation',1.5],
    ['submission_generation',9007199254740992]]);

it('tracer3 validates raw identities before callback without seeding', function ($field, $value, $missing) {
    $row = t06j_row(); $raw = $row->getRawOriginal();
    if ($missing) { unset($raw[$field]); } else { $raw[$field] = $value; }
    $fake = new Characterization; $fake->setRawAttributes($raw, true); $calls = 0;
    expect(fn () => DB::transaction(fn () => (new LearningP9SourceRevisionClock)->observe($row->user_id,
        fn () => $fake, function () use (&$calls) { $calls++; })))->toThrow(DomainException::class);
    expect($calls)->toBe(0)->and((new LearningP9SourceRevisionClock)->current($row->id))->toBeNull();
})->with([['id','1x',false], ['id',0,false], ['id',null,true], ['user_id',null,true],
    ['user_id',1.5,false], ['submission_generation',null,true], ['form_data',[],false]]);

it('tracer3 validates headers and refuses passive repair', function ($field, $value) {
    $row = t06j_row(); t06j_write($row, ['form_data' => t06j_form('"A"')]);
    DB::table('learning_p9_source_revisions')->where('characterization_id', $row->id)->update([$field => $value]);
    $before = t06j_snapshot();
    expect(fn () => (new LearningP9SourceRevisionClock)->current($row->id))->toThrow(DomainException::class);
    expect(t06j_snapshot())->toBe($before);
})->with([['revision',0], ['revision',1.5], ['revision',9007199254740992], ['revision','1x'],
    ['generation','1x'], ['epoch','bad'], ['digest','bad'], ['epoch',str_repeat('a',64)."\n"], ['digest',str_repeat('a',64)]]);

it('tracer3 guards actor identity generation exhaustion tampering and true cascade deletion', function ($kind) {
    $row = t06j_row(); $other = User::factory()->create();
    if ($kind !== 'identity') { t06j_write($row, ['form_data' => t06j_form('"A"')]); }
    if ($kind === 'exhaustion') { DB::table('learning_p9_source_revisions')->where('characterization_id',$row->id)->update(['revision'=>9007199254740991]); }
    $before = t06j_snapshot(); $clock = new LearningP9SourceRevisionClock;
    $invoke = fn () => DB::transaction(fn () => $clock->observe($row->user_id,
        fn () => Characterization::query()->where('user_id', $row->user_id)->first(), function () use ($kind,$row,$other) {
            if ($kind === 'delete') { return DB::table('characterizations')->where('id',$row->id)->delete(); }
            if ($kind === 'header') { return DB::table('learning_p9_source_revisions')->where('characterization_id',$row->id)->update(['revision'=>999]); }
            return DB::table('characterizations')->where('id',$row->id)->update(match ($kind) {
                'actor' => ['user_id'=>$other->id], 'identity' => ['id'=>$row->id+1000],
                default => ['form_data'=>t06j_form('"B"')],
            });
        }));
    if ($kind === 'delete') { expect($invoke())->toBe(1)->and($clock->current($row->id))->toBeNull(); }
    else { expect($invoke)->toThrow(DomainException::class); expect(t06j_snapshot())->toBe($before); }
    expect($clock->hasActiveScope($row->user_id))->toBeFalse()->and(DB::connection()->transactionLevel())->toBe(0);
})->with(['actor','identity','header','exhaustion','delete']);

it('tracer3 metadata seam rejects late raw actor nullable source and valid header mutations', function ($tracked,$nullable,$fault) {
    $row = t06j_row(); $other = User::factory()->create(); $clock = new LearningP9SourceRevisionClock;
    if ($tracked) { t06j_write($row,['form_data'=>t06j_form('"A"')]); }
    $before=t06j_snapshot(); $hits=0; $connection=DB::connection(); $dispatcher=$connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $q) use ($row,$other,$fault,&$hits) {
        if ($hits === 0 && preg_match('/^(insert|update) /i',$q->sql) && str_contains($q->sql,'"learning_p9_source_revisions"')) {
            $hits++;
            if ($fault === 'header') { DB::table('learning_p9_source_revisions')->where('characterization_id',$row->id)->update(['revision'=>999]); }
            else { DB::table('characterizations')->where('id',$row->id)->update(match ($fault) {
                'actor'=>['user_id'=>$other->id], 'invalid_actor'=>['user_id'=>$other->id],
                'delete'=>['user_id'=>$other->id], default=>['form_data'=>t06j_form('"LATE"')],
            }); }
        }
    });
    $caught=null;
    try {
        try { DB::transaction(fn () => $clock->observe($row->user_id,
            $nullable ? fn () => Characterization::query()->where('user_id',$row->user_id)->first() : fn () => Characterization::query()->find($row->id),
            fn () => DB::table('characterizations')->where('id',$row->id)->update(['form_data'=>t06j_form('"B"')]))); }
        catch (Throwable $e) { $caught=$e; }
    } finally { $connection->setEventDispatcher($dispatcher); }
    expect($hits)->toBe(1)->and($caught)->toBeInstanceOf(DomainException::class)->and(t06j_snapshot())->toBe($before);
    t06j_write($row,['form_data'=>t06j_form('"RECOVER"')]);
    expect($clock->current($row->id)['revision'])->toBe($tracked ? 2 : 1);
    expect(DB::connection()->transactionLevel())->toBe(0);
})->with([[false,false,'actor'],[true,false,'actor'],[false,true,'actor'],[true,true,'actor'],
    [false,false,'invalid_actor'],[true,true,'invalid_actor'],[false,false,'delete'],[true,true,'delete'],
    [false,false,'source'],[true,true,'source'],[false,false,'header'],[true,true,'header']]);

it('tracer3 controls representable numbers foreign integers raw strings mode and disposable admission', function () {
    $row=t06j_row(); $clock=new LearningP9SourceRevisionClock;
    $raw='{"other":18446744073709551617,"esrs_datapoint_responses":{"min":-9223372036854775808,"max":9223372036854775807,"quoted":"18446744073709551617","float":1e20}}';
    t06j_write($row,['form_data'=>$raw]); $a=$clock->current($row->id);
    t06j_write($row,['form_data'=>$raw]); expect($clock->current($row->id))->toBe($a);
    $fake=new Characterization; $attributes=$row->fresh()->getRawOriginal();
    foreach (['id','user_id','submission_generation'] as $key) { $attributes[$key]=(string)$attributes[$key]; }
    $fake->setRawAttributes($attributes,true);
    expect(DB::transaction(fn ()=>$clock->observe($row->user_id,fn ()=>$fake,fn ()=>'OK')))->toBe('OK');
    expect(fn ()=>$clock->observe($row->user_id,fn ()=>$row,fn ()=>'NO'))->toThrow(DomainException::class,'transaction_required');
    $name=DB::connection()->getDatabaseName(); DB::connection()->setDatabaseName('not-memory'); $calls=0;
    try { expect(fn ()=>$clock->observe($row->user_id,fn ()=>$row,function () use (&$calls) {$calls++;}))->toThrow(DomainException::class,'disposable_transaction_required'); }
    finally { DB::connection()->setDatabaseName($name); }
    expect($calls)->toBe(0);
});

it('tracer4 common observes P9 alone creation nesting and final mode', function () {
    $row=t06j_row(); $tx=new CharacterizationStateTransaction; $clock=new LearningP9SourceRevisionClock;
    $tx->run($row->id,fn ()=>DB::table('characterizations')->where('id',$row->id)->update(['form_data'=>t06j_form('"A"')]));
    expect($clock->current($row->id)['revision'])->toBe(1);
    $before=t06j_snapshot();
    expect(fn ()=>$tx->run($row->id,fn ()=>config(['services.learning_p9_source_clock.enabled'=>false])))->toThrow(DomainException::class);
    config(['services.learning_p9_source_clock.enabled'=>true]); expect(t06j_snapshot())->toBe($before);
    $tx->run($row->id,fn ()=>$tx->run($row->id,fn ()=>DB::table('characterizations')->where('id',$row->id)->update(['form_data'=>t06j_form('"B"')])));
    expect($clock->current($row->id)['revision'])->toBe(2);
});

it('tracer4 outer metadata seam verifies exact nullable P9 witness and positive composition', function ($outer,$fault) {
    config(['services.learning_source_clock.enabled'=>true,'services.learning_p6_base_source_clock.enabled'=>true,'services.learning_p8_source_clock.enabled'=>true]);
    $row=t06j_row(); $tx=new CharacterizationStateTransaction; $clock=new LearningP9SourceRevisionClock;
    if ($fault==='absentheader') { DB::table('characterizations')->where('id',$row->id)->update(['form_data'=>'{}']); }
    $before=t06j_snapshot(); $hits=0; $connection=DB::connection(); $dispatcher=$connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $q) use ($row,$outer,$fault,&$hits) {
        if ($hits===0 && preg_match('/^(insert|update) /i',$q->sql) && str_contains($q->sql,'"'.$outer.'"')) {
            $hits++;
            if ($fault==='source') { DB::table('characterizations')->where('id',$row->id)->update(['form_data'=>t06j_form('"LATE"')]); }
            if ($fault==='validheader') { DB::table('learning_p9_source_revisions')->where('characterization_id',$row->id)->update(['revision'=>999]); }
            if ($fault==='absentheader') { expect(DB::table('learning_p9_source_revisions')->where('characterization_id',$row->id)->first())->toBeNull(); DB::table('learning_p9_source_revisions')->insert(['characterization_id'=>$row->id,'generation'=>1,'revision'=>1,'epoch'=>str_repeat('a',64),'digest'=>str_repeat('b',64)]); }
        }
    });
    $invoke=fn ()=>$tx->run($row->id,fn ()=>DB::table('characterizations')->where('id',$row->id)->update(['form_data'=>'{"company_profile":{"synthetic":"B"},"materiality_proposal_review":{"synthetic":"B"},"materiality_confirmation":{"synthetic":"B"}'.($fault==='absentheader'?'':',"esrs_datapoint_responses":"B"').'}']));
    try { if ($fault==='positive') { $invoke(); } else { expect($invoke)->toThrow(DomainException::class); } }
    finally { $connection->setEventDispatcher($dispatcher); }
    expect($hits)->toBe(1);
    if ($fault!=='positive') { expect(t06j_snapshot())->toBe($before); }
    $tx->run($row->id,fn ()=>DB::table('characterizations')->where('id',$row->id)->update(['form_data'=>t06j_form('"RECOVER"')]));
    expect($clock->current($row->id)['revision'])->toBe($fault==='positive'?2:1);
    expect(DB::connection()->transactionLevel())->toBe(0);
})->with(collect(['learning_p5_source_revisions','learning_p6_base_source_revisions','learning_p8_source_revisions'])->flatMap(fn ($table)=>array_map(fn ($fault)=>[$table,$fault],['source','validheader','absentheader','positive']))->all());


it('R1 late own metadata readback qualifies malformed lookup admission and real cascade deletion', function ($tracked, $nullable, $fault) {
    $row = t06j_row(); $clock = new LearningP9SourceRevisionClock;
    if ($tracked) { t06j_write($row, ['form_data' => t06j_form('"A"')]); }
    $before = t06j_snapshot(); $hits = 0;
    $connection = DB::connection(); $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $q) use ($row, $fault, &$hits) {
        if ($hits === 0 && preg_match('/^(insert|update) /i', $q->sql)
            && str_contains($q->sql, '"learning_p9_source_revisions"')) {
            $hits++;
            expect(DB::table('characterizations')->where('id', $row->id)->first())->not->toBeNull();
            expect(DB::table('learning_p9_source_revisions')->where('characterization_id', $row->id)->first())->not->toBeNull();
            if ($fault === 'delete') {
                expect(DB::table('characterizations')->where('id', $row->id)->delete())->toBe(1);
                expect(DB::table('characterizations')->where('id', $row->id)->first())->toBeNull();
                expect(DB::table('learning_p9_source_revisions')->where('characterization_id', $row->id)->first())->toBeNull();
            }
        }
    });
    // FK stays enabled: malformed raw actor is supplied by lookup, never persisted.
    $lookup = function () use ($row, $nullable, $fault, &$hits) {
        $found = $nullable
            ? Characterization::query()->where('user_id', $row->user_id)->first()
            : Characterization::query()->find($row->id);
        if ($hits === 1 && $fault !== 'delete') {
            expect($found)->not->toBeNull();
            $raw = $found->getRawOriginal();
            $raw['user_id'] = $fault === 'suffix' ? $row->user_id.'x' : $row->user_id + 0.5;
            $fake = new Characterization; $fake->setRawAttributes($raw, true);
            return $fake;
        }
        return $found;
    };
    try {
        expect(fn () => DB::transaction(fn () => $clock->observe($row->user_id, $lookup,
            fn () => DB::table('characterizations')->where('id', $row->id)
                ->update(['form_data' => t06j_form('"B"')]))))->toThrow(DomainException::class);
    } finally { $connection->setEventDispatcher($dispatcher); }
    expect($hits)->toBe(1)->and(t06j_snapshot())->toBe($before);
    expect($clock->hasActiveScope($row->user_id))->toBeFalse();
    expect(DB::connection()->transactionLevel())->toBe(0);
    t06j_write($row, ['form_data' => t06j_form('"RECOVER"')]);
    expect($clock->current($row->id)['revision'])->toBe($tracked ? 2 : 1);
    expect($row->fresh()->getRawOriginal('form_data'))->toBe(t06j_form('"RECOVER"'));
    expect($clock->hasActiveScope($row->user_id))->toBeFalse();
    expect(DB::connection()->transactionLevel())->toBe(0);
})->with(collect([false, true])->flatMap(fn ($tracked) => collect([false, true])
    ->flatMap(fn ($nullable) => array_map(fn ($fault) => [$tracked, $nullable, $fault],
        ['suffix', 'fraction', 'delete'])))->all());

it('R1 outer metadata rejects P9 source overwrite while its real header remains absent', function ($outer) {
    config(['services.learning_source_clock.enabled' => true,
        'services.learning_p6_base_source_clock.enabled' => true,
        'services.learning_p8_source_clock.enabled' => true]);
    $row = t06j_row(); $tx = new CharacterizationStateTransaction; $clock = new LearningP9SourceRevisionClock;
    DB::table('characterizations')->where('id', $row->id)->update(['form_data' => '{}']);
    expect($clock->current($row->id))->toBeNull();
    $before = t06j_snapshot(); $hits = 0;
    $connection = DB::connection(); $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $q) use ($row, $outer, &$hits) {
        if ($hits === 0 && preg_match('/^(insert|update) /i', $q->sql)
            && str_contains($q->sql, '"'.$outer.'"')) {
            $hits++;
            expect(DB::table('learning_p9_source_revisions')->where('characterization_id', $row->id)->first())->toBeNull();
            $raw = DB::table('characterizations')->where('id', $row->id)->value('form_data');
            $form = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            expect(array_key_exists('esrs_datapoint_responses', $form))->toBeFalse();
            $form['esrs_datapoint_responses'] = 'LATE';
            expect(DB::table('characterizations')->where('id', $row->id)
                ->update(['form_data' => json_encode($form, JSON_THROW_ON_ERROR)]))->toBe(1);
            expect(DB::table('learning_p9_source_revisions')->where('characterization_id', $row->id)->first())->toBeNull();
        }
    });
    try {
        expect(fn () => $tx->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)
            ->update(['form_data' => '{"company_profile":{"synthetic":"B"},"materiality_proposal_review":{"synthetic":"B"},"materiality_confirmation":{"synthetic":"B"}}'])))
            ->toThrow(DomainException::class);
    } finally { $connection->setEventDispatcher($dispatcher); }
    expect($hits)->toBe(1)->and(t06j_snapshot())->toBe($before);
    expect($clock->current($row->id))->toBeNull();
    expect($clock->hasActiveScope($row->user_id))->toBeFalse();
    expect(DB::connection()->transactionLevel())->toBe(0);
    $tx->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)
        ->update(['form_data' => t06j_form('"RECOVER"')]));
    expect($clock->current($row->id)['revision'])->toBe(1);
    expect($row->fresh()->getRawOriginal('form_data'))->toBe(t06j_form('"RECOVER"'));
    expect($clock->hasActiveScope($row->user_id))->toBeFalse();
    expect(DB::connection()->transactionLevel())->toBe(0);
})->with(['learning_p5_source_revisions', 'learning_p6_base_source_revisions', 'learning_p8_source_revisions']);
