<?php

use App\Models\{Characterization, User};
use App\Services\{CharacterizationStateTransaction, LearningP8SourceRevisionClock};
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Foundation\Testing\RefreshDatabaseState;

beforeEach(function () {
    expect(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->commit();
    RefreshDatabaseState::$migrated = false;
    Http::preventStrayRequests();
    config(['services.learning_p8_source_clock.enabled' => true]);
});

function t06hRow(): Characterization
{
    return Characterization::query()->create(['user_id' => User::factory()->create()->id,
        'status' => 'draft', 'form_data' => [], 'submission_generation' => 1])->fresh();
}

function t06hWrite(Characterization $row, ?string $raw): mixed
{
    return app(CharacterizationStateTransaction::class)->run($row->id, fn () =>
        DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]));
}

it('owns committed P8 changes through the common transaction with passive noops and coalesced ABA', function () {
    $row = t06hRow(); $clock = new LearningP8SourceRevisionClock;
    expect($clock->current($row->id))->toBeNull();
    t06hWrite($row, '[]');
    expect($clock->current($row->id))->toBeNull();
    t06hWrite($row, '{"materiality_confirmation":{"note":"A"}}');
    $a = $clock->current($row->id);
    expect($a['revision'])->toBe(1);
    t06hWrite($row, '{"materiality_confirmation":{"note":"B"}}');
    t06hWrite($row, '{"materiality_confirmation":{"note":"A"}}');
    expect(DB::connection()->transactionLevel())->toBe(0);
    $again = $clock->current($row->id);
    expect($again['revision'])->toBe(3)->and($again['digest'])->toBe($a['digest']);
    app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row) {
        t06hWrite($row, '{"materiality_confirmation":{"note":"B"}}');
        t06hWrite($row, '{"materiality_confirmation":{"note":"A"}}');
    });
    expect($clock->current($row->id))->toBe($again);
});

// R1 qualification: passing original bytes is PASS_EXISTING, never retroactive RED.
function t06hSnapshot(Characterization $row): array
{
    $result = [(array) DB::table('characterizations')->where('id', $row->id)->first()];
    foreach (['learning_p5_source_revisions', 'learning_p6_base_source_revisions', 'learning_p8_source_revisions'] as $table) {
        $result[$table] = DB::table($table)->where('characterization_id', $row->id)->get()->map(fn ($r) => (array) $r)->all();
    }
    return $result;
}

function t06hAllClocks(): void
{
    config(['services.learning_source_clock.enabled' => true, 'services.learning_p6_base_source_clock.enabled' => true]);
}

function t06hSeed(Characterization $row, bool $all = false): array
{
    if ($all) { t06hAllClocks(); }
    t06hWrite($row, '{"company_profile":{"company_name":"SYNTHETIC_A"},"esg_focus":{"topic_ids":[]},"materiality_confirmation":{"note":"A"}}');
    return (new LearningP8SourceRevisionClock)->current($row->id);
}

it('tracks each complete stored P8 field without assigning label authority', function ($field) {
    $row = t06hRow(); $a = t06hSeed($row);
    $form = $row->fresh()->form_data;
    $form['materiality_confirmation'][$field] = ['synthetic' => 1];
    t06hWrite($row, json_encode($form, JSON_THROW_ON_ERROR));
    $b = (new LearningP8SourceRevisionClock)->current($row->id);
    expect($b['revision'])->toBe(2)->and($b['digest'])->not->toBe($a['digest'])->and($b['epoch'])->toBe($a['epoch']);
})->with(['revision', 'change_reasons', 'change_reason_notes', 'guided_answers', 'dimensions', 'p6_snapshot', 'reviewed_topic_ids', 'universe_attestation', 'decision_basis', 'confirmed_topic_ids', 'e1_not_material_explanation', 'confirmed_at']);

it('distinguishes typed legacy P8 source values', function ($left, $right) {
    $row = t06hRow();
    t06hWrite($row, '{"materiality_confirmation":'.$left.'}');
    $a = (new LearningP8SourceRevisionClock)->current($row->id);
    t06hWrite($row, '{"materiality_confirmation":'.$right.'}');
    $b = (new LearningP8SourceRevisionClock)->current($row->id);
    expect($b['revision'])->toBe(2)->and($b['digest'])->not->toBe($a['digest']);
})->with([['true','"true"'], ['1','"1"'], ['1','1.0'], ['1.0','1.5'], ['{}','[]'], ['null','{}'], ['[1,2]','[2,1]']]);

it('canonicalizes object keys but ignores unrelated sections of the same root', function () {
    $row = t06hRow();
    t06hWrite($row, '{"materiality_confirmation":{"b":2,"a":{"z":0,"x":1}},"other":0}');
    $a = (new LearningP8SourceRevisionClock)->current($row->id);
    t06hWrite($row, '{"other":99,"materiality_confirmation":{"a":{"x":1,"z":0},"b":2}}');
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBe($a);
    t06hWrite($row, '{}'); $missing = (new LearningP8SourceRevisionClock)->current($row->id);
    t06hWrite($row, '{"materiality_confirmation":null}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe($missing['revision'] + 1);
});

it('admits legacy root provenance and leaves passive unknown source unseeded', function ($raw) {
    $row = t06hRow(); DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]);
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBeNull();
    t06hWrite($row, '{"materiality_confirmation":null}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(1);
})->with([[null], ['null'], ['[]'], ['{}']]);

it('rejects corrupt raw source with complete rollback and scope recovery', function ($field, $value) {
    $row = t06hRow(); t06hSeed($row); $before = t06hSnapshot($row);
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, fn () => DB::table('characterizations')->where('id', $row->id)->update([$field => $value])))->toThrow($value === null ? \Illuminate\Database\QueryException::class : DomainException::class);
    expect(t06hSnapshot($row))->toBe($before);
    t06hWrite($row, '{"materiality_confirmation":{"note":"RECOVERED"}}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(2);
})->with([['form_data','{'], ['form_data','1e999'], ['form_data','true'], ['submission_generation','1x'], ['submission_generation',1.5], ['submission_generation',null], ['submission_generation',9007199254740992], ['submission_generation',0]]);

it('validates raw model identities and nonstring JSON before observation', function ($field, $value, $missing) {
    $row = t06hRow(); $raw = $row->getRawOriginal();
    if ($missing) { unset($raw[$field]); } else { $raw[$field] = $value; }
    $fake = new Characterization; $fake->setRawAttributes($raw, true);
    expect(fn () => DB::transaction(fn () => (new LearningP8SourceRevisionClock)->observe($row->user_id, fn () => $fake, fn () => null)))->toThrow(DomainException::class);
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBeNull();
})->with([['id','1x',false], ['id',null,false], ['id',0,false], ['id',1.5,false], ['id',null,true], ['user_id',null,true], ['submission_generation',null,true], ['submission_generation',null,false], ['submission_generation','9007199254740992',false], ['form_data',[],false]]);

it('preserves canonical PDO integer strings as valid positive identities', function () {
    $row = t06hRow(); $raw = $row->getRawOriginal();
    foreach (['id','user_id','submission_generation'] as $key) { $raw[$key] = (string) $raw[$key]; }
    $fake = new Characterization; $fake->setRawAttributes($raw, true);
    expect(DB::transaction(fn () => (new LearningP8SourceRevisionClock)->observe($row->user_id, fn () => $fake, fn () => 'metadata-return')))->toBe('metadata-return');
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBeNull();
});

it('rejects tracked header corruption without passive repair', function ($field, $value) {
    $row = t06hRow(); t06hSeed($row);
    DB::table('learning_p8_source_revisions')->where('characterization_id', $row->id)->update([$field => $value]);
    $before = t06hSnapshot($row);
    expect(fn () => (new LearningP8SourceRevisionClock)->current($row->id))->toThrow(DomainException::class);
    expect(t06hSnapshot($row))->toBe($before);
})->with([['revision',0], ['revision','2x'], ['revision',1.5], ['revision',9007199254740992], ['generation','1x'], ['digest','corrupt'], ['digest',str_repeat('a',64)."\n"], ['epoch','corrupt'], ['epoch',str_repeat('a',64)."\n"], ['epoch',str_repeat('a',64)]]);

it('rejects tracked source drift and exhausted revisions', function ($kind) {
    $row = t06hRow(); t06hSeed($row);
    if ($kind === 'drift') {
        DB::table('characterizations')->where('id', $row->id)->update(['form_data' => '{"materiality_confirmation":false}']);
        expect(fn () => (new LearningP8SourceRevisionClock)->current($row->id))->toThrow(DomainException::class);
    } else { DB::table('learning_p8_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 9007199254740991]); }
    $before = t06hSnapshot($row);
    expect(fn () => t06hWrite($row, '{"materiality_confirmation":true}'))->toThrow(DomainException::class);
    expect(t06hSnapshot($row))->toBe($before);
})->with(['drift', 'exhaustion']);

it('rejects operation header tampering and actor change with recovery', function ($kind) {
    $row = t06hRow(); t06hSeed($row); $other = User::factory()->create(); $before = t06hSnapshot($row);
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row, $kind, $other) {
        if ($kind === 'actor') { DB::table('characterizations')->where('id', $row->id)->update(['user_id' => $other->id]); }
        else { DB::table('learning_p8_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 2]); }
    }))->toThrow(DomainException::class);
    expect(t06hSnapshot($row))->toBe($before);
    t06hWrite($row, '{"materiality_confirmation":false}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(2);
})->with(['actor','header']);

it('coalesces all three owners once for a persistent nested change and preserves returns', function () {
    $row = t06hRow(); t06hSeed($row, true);
    $result = app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row) {
        t06hWrite($row, '{"company_profile":{"company_name":"SYNTHETIC_B"},"materiality_proposal_review":{"x":1},"materiality_confirmation":{"note":"B"}}');
        t06hWrite($row, '{"company_profile":{"company_name":"SYNTHETIC_C"},"materiality_proposal_review":{"x":2},"materiality_confirmation":{"note":"C"}}');
        return ['synthetic_return' => 1];
    });
    expect($result)->toBe(['synthetic_return' => 1])->and(DB::connection()->transactionLevel())->toBe(0);
    foreach (['learning_p5_source_revisions','learning_p6_base_source_revisions','learning_p8_source_revisions'] as $table) {
        expect((int) DB::table($table)->where('characterization_id', $row->id)->value('revision'))->toBe(2);
    }
});

it('rolls back late outer owner faults using full raw row and all nullable headers', function ($owner, $fault) {
    $row = t06hRow(); t06hAllClocks();
    if ($fault !== 'absent') { t06hSeed($row); }
    else { DB::table('characterizations')->where('id', $row->id)->update(['form_data' => '{}']); }
    $before = t06hSnapshot($row); $armed = true; $hit = false;
    // Fixture-local listener, never global dispatcher removal or recursion.
    DB::listen(function ($query) use (&$armed, &$hit, $row, $owner, $fault) {
        if (! $armed || ! str_contains($query->sql, 'learning_'.$owner.'_source_revisions') || ! preg_match('/^(insert|update)/i', $query->sql)) { return; }
        $armed = false; $hit = true;
        if ($fault === 'counter') { DB::table('learning_p8_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 77]); }
        else { DB::table('characterizations')->where('id', $row->id)->update(['form_data' => '{"materiality_confirmation":{"note":"FAULT"}}']); }
    });
    try {
        expect(fn () => t06hWrite($row, $fault === 'absent'
            ? '{"company_profile":{"company_name":"SYNTHETIC_B"},"materiality_proposal_review":{"x":1}}'
            : '{"company_profile":{"company_name":"SYNTHETIC_B"},"materiality_proposal_review":{"x":1},"materiality_confirmation":{"note":"B"}}'))->toThrow(DomainException::class);
    } finally { $armed = false; }
    expect($hit)->toBeTrue()->and(t06hSnapshot($row))->toBe($before);
    if ($fault === 'absent') { expect((new LearningP8SourceRevisionClock)->current($row->id))->toBeNull(); }
    t06hWrite($row, '{"company_profile":{"company_name":"RECOVERED"},"materiality_proposal_review":{"x":2},"materiality_confirmation":{"note":"RECOVERED"}}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe($fault === 'absent' ? 1 : 2);
})->with([['p5','source'], ['p5','counter'], ['p5','absent'], ['p6_base','source'], ['p6_base','counter'], ['p6_base','absent']]);

it('rejects P8 readback tampering at its own metadata write', function ($fault) {
    $row = t06hRow(); t06hSeed($row); $before = t06hSnapshot($row); $armed = true;
    DB::listen(function ($query) use (&$armed, $row, $fault) {
        if (! $armed || ! str_starts_with($query->sql, 'update "learning_p8_source_revisions"')) { return; }
        $armed = false;
        if ($fault === 'source') { DB::table('characterizations')->where('id', $row->id)->update(['submission_generation' => 2]); }
        else { DB::table('learning_p8_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 77]); }
    });
    try { expect(fn () => t06hWrite($row, '{"materiality_confirmation":true}'))->toThrow(DomainException::class); }
    finally { $armed = false; }
    expect(t06hSnapshot($row))->toBe($before);
    t06hWrite($row, '{"materiality_confirmation":true}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(2);
})->with(['source','header']);

it('preserves each participant late flag guard and rollback', function ($flag) {
    $row = t06hRow(); t06hSeed($row, true); $before = t06hSnapshot($row);
    try {
        expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function () use ($flag) { config([$flag => false]); }))->toThrow(DomainException::class);
    } finally { config([$flag => true]); }
    expect(t06hSnapshot($row))->toBe($before);
    t06hWrite($row, '{"materiality_confirmation":true}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(2);
})->with(['services.learning_source_clock.enabled','services.learning_p6_base_source_clock.enabled','services.learning_p8_source_clock.enabled']);

it('makes disabled flags and non-testing mode perform zero P8 queries', function ($flag, $environment) {
    $row = t06hRow(); config(['services.learning_p8_source_clock.enabled' => $flag]);
    $old = app()->environment(); app()->instance('env', $environment); $queries = 0;
    DB::listen(function ($query) use (&$queries) { $queries++; });
    try {
        expect((new LearningP8SourceRevisionClock)->current($row->id))->toBeNull();
        expect((new LearningP8SourceRevisionClock)->observe($row->user_id, fn () => throw new RuntimeException('lookup must not run'), fn () => 42))->toBe(42);
    } finally { app()->instance('env', $old); }
    expect($queries)->toBe(0);
})->with([[false,'testing'], [null,'testing'], ['true','testing'], [true,'production']]);

it('contains unsupported connections using metadata before any PDO access', function ($driver, $database) {
    $pdoCalls = 0;
    $connection = new class($driver, $database, $pdoCalls) extends \Illuminate\Database\Connection {
        public function __construct(private string $driver, string $database, private mixed &$calls) { parent::__construct(fn () => throw new RuntimeException('PDO opened'), $database); }
        public function getDriverName() { return $this->driver; }
        public function getPdo() { $this->calls++; throw new RuntimeException('PDO opened'); }
    };
    DB::shouldReceive('connection')->andReturn($connection);
    expect(fn () => (new LearningP8SourceRevisionClock)->current(1))->toThrow(DomainException::class, 'disposable_transaction_required');
    expect(fn () => (new LearningP8SourceRevisionClock)->observe(1, fn () => null, fn () => null))->toThrow(DomainException::class, 'disposable_transaction_required');
    expect($pdoCalls)->toBe(0);
})->with([['sqlite','synthetic-never-opened.sqlite'], ['mysql',':memory:'], ['pgsql',':memory:']]);

class T06hSyntheticCorpus extends \App\Services\EsrsDatapointCorpusBuilder
{
    public function __construct() {}
    public function build(Characterization $characterization): array
    {
        return ['material_topic_ids' => $characterization->esrs_topic_ids ?? [], 'activated_esrs_standards' => ['S1'],
            'summary' => array_fill_keys(['total_datapoint_count','always_required_datapoint_count','topical_datapoint_count','minimum_disclosure_requirement_datapoint_count','voluntary_datapoint_count','conditional_datapoint_count','phase_in_datapoint_count'], 0),
            'generation' => ['mapping_granularity' => 'synthetic', 'coverage_status' => 'synthetic']];
    }
}

it('qualifies real controller PUT GET stale and invalid requests preserving rejected added evidence', function () {
    $row = t06hRow();
    $topics = [];
    foreach (['SYNTHETIC_A','SYNTHETIC_B'] as $label) {
        $topics[] = \App\Models\EsrsTopic::query()->create(['esrs_code'=>'S1','theme_es'=>$label,'theme_en'=>$label,'subtheme_es'=>$label,'subtheme_en'=>$label,'subtopic_es'=>$label,'subtopic_en'=>$label,'hash'=>hash('sha256',$label)])->id;
    }
    $row->update(['status'=>'completed','esrs_topic_ids'=>[$topics[0]]]);
    $this->app->instance(\App\Services\EsrsDatapointCorpusBuilder::class, new T06hSyntheticCorpus);
    $this->actingAs(User::findOrFail($row->user_id));
    $controller = new \App\Http\Controllers\Api\MaterialityConfirmationController;
    // Isolated test routes retain the real controller and request validation.
    \Illuminate\Support\Facades\Route::put('/t06h-synthetic-confirmation', [\App\Http\Controllers\Api\MaterialityConfirmationController::class,'update']);
    \Illuminate\Support\Facades\Route::get('/t06h-synthetic-confirmation', [\App\Http\Controllers\Api\MaterialityConfirmationController::class,'show']);
    $answer = ['impacto'=>'bajo','financiero'=>'bajo','confianza'=>'alta','exposicion'=>'descartada','suggested_result'=>'no_material','final_result'=>'no_material','revisar'=>false,'note'=>'SYNTHETIC_REJECTED'];
    $payload = ['expected_revision'=>0,'confirmed_topic_ids'=>[$topics[0]],'reviewed_topic_ids'=>$topics,
        'universe_attestation'=>['version'=>1,'reviewed_universe'=>true,'mode'=>'direct'],
        'change_reasons'=>[(string)$topics[1]=>['other']], 'change_reason_notes'=>[(string)$topics[1]=>'SYNTHETIC_REJECTED'],
        'dimensions'=>[(string)$topics[1]=>'both'], 'guided_answers'=>[(string)$topics[1]=>$answer]];
    $this->putJson('/t06h-synthetic-confirmation',$payload)->assertOk()->assertJsonPath('data.confirmation.revision',1)
        ->assertJsonPath('data.confirmation.guided_answers.'.$topics[1].'.final_result','no_material');
    $header = (new LearningP8SourceRevisionClock)->current($row->id);
    $this->getJson('/t06h-synthetic-confirmation')->assertOk()->assertJsonPath('data.confirmation.reviewed_topic_ids',$topics)
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$topics[1],'SYNTHETIC_REJECTED')->assertJsonPath('data.preview.coverage_status','synthetic');
    $stored = $row->fresh()->form_data['materiality_confirmation'];
    expect($stored['guided_answers'][(string)$topics[1]])->toBe($answer);
    $this->putJson('/t06h-synthetic-confirmation',['expected_revision'=>1,'confirmed_topic_ids'=>[$topics[0]]])->assertOk()
        ->assertJsonPath('data.confirmation.change_reason_notes.'.$topics[1],'SYNTHETIC_REJECTED')
        ->assertJsonPath('data.confirmation.guided_answers.'.$topics[1].'.final_result','no_material')
        ->assertJsonPath('data.confirmation.reviewed_topic_ids',$topics);
    $header = (new LearningP8SourceRevisionClock)->current($row->id);
    expect($header['revision'])->toBe(2)->and($row->fresh()->form_data['materiality_confirmation']['guided_answers'][(string)$topics[1]])->toBe($answer);
    $this->putJson('/t06h-synthetic-confirmation',$payload)->assertStatus(409);
    $this->putJson('/t06h-synthetic-confirmation',['expected_revision'=>'1','confirmed_topic_ids'=>[]])->assertStatus(422);
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBe($header);
});

it('distinguishes each legacy root provenance on a tracked row', function () {
    $row = t06hRow(); t06hSeed($row); $digests = [];
    foreach ([null,'null','[]','{}'] as $raw) {
        t06hWrite($row,$raw); $digests[] = (new LearningP8SourceRevisionClock)->current($row->id)['digest'];
    }
    expect(count(array_unique($digests)))->toBe(4)->and((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(5);
});

it('requires an owned transaction and rejects a mismatched actor before callback', function () {
    $row = t06hRow(); $clock = new LearningP8SourceRevisionClock; $called = false;
    expect(fn () => $clock->observe($row->user_id, fn () => $row, fn () => null))->toThrow(DomainException::class,'transaction_required');
    expect(fn () => DB::transaction(fn () => $clock->observe($row->user_id + 1, fn () => $row, function () use (&$called) { $called = true; })))->toThrow(DomainException::class,'actor_mismatch');
    expect($called)->toBeFalse()->and(DB::connection()->transactionLevel())->toBe(0);
    t06hSeed($row); expect($clock->current($row->id)['revision'])->toBe(1);
});

it('passively returns null for an untracked unknown corrupt legacy value', function () {
    $row = t06hRow(); DB::table('characterizations')->where('id',$row->id)->update(['form_data'=>'{']);
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBeNull();
    expect(DB::table('learning_p8_source_revisions')->count())->toBe(0);
});

it('actual recorder submit advances generation while preserving P8 evidence with fake dispatch', function () {
    \Illuminate\Support\Facades\Bus::fake();
    $row = t06hRow(); t06hSeed($row, true); $p8 = $row->fresh()->form_data['materiality_confirmation'];
    $result = app(\App\Services\CharacterizationRecorder::class)->save(User::findOrFail($row->user_id), ['status'=>'submitted']);
    expect($result->submission_generation)->toBe(2)->and($result->form_data['materiality_confirmation'])->toBe($p8);
    $header = (new LearningP8SourceRevisionClock)->current($row->id);
    expect($header['generation'])->toBe(2)->and($header['revision'])->toBe(2);
    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SubmitCharacterizationJob::class);
});

it('actual migration FK cascade removes all headers and rolls back a rejected delete', function () {
    $row = t06hRow(); t06hSeed($row, true); $before = t06hSnapshot($row);
    $fk = DB::select('PRAGMA foreign_key_list(learning_p8_source_revisions)');
    expect(collect($fk)->contains(fn ($f) => $f->table === 'characterizations' && strtoupper($f->on_delete) === 'CASCADE'))->toBeTrue();
    expect(fn () => app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row) {
        DB::table('characterizations')->where('id',$row->id)->delete(); throw new DomainException('synthetic rejected deletion');
    }))->toThrow(DomainException::class);
    expect(t06hSnapshot($row))->toBe($before);
    app(CharacterizationStateTransaction::class)->run($row->id, fn () => DB::table('characterizations')->where('id',$row->id)->delete());
    expect(DB::table('characterizations')->where('id',$row->id)->exists())->toBeFalse();
    foreach (['learning_p5_source_revisions','learning_p6_base_source_revisions','learning_p8_source_revisions'] as $table) {
        expect(DB::table($table)->where('characterization_id',$row->id)->exists())->toBeFalse();
    }
});


// R2 finite residuals; existing product passes remain PASS_EXISTING.
it('accepts SQLite normalized integer revision with exact positive storage readback', function () {
    $row = t06hRow(); $header = t06hSeed($row);
    DB::table('learning_p8_source_revisions')->where('characterization_id', $row->id)->update(['revision' => "1\n"]);
    $raw = DB::selectOne('select revision, typeof(revision) as storage_type from learning_p8_source_revisions where characterization_id = ?', [$row->id]);
    expect($raw->revision)->toBe(1)->and($raw->storage_type)->toBe('integer');
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBe($header);
});

it('rejects an uncoerced raw header revision newline at the service boundary', function () {
    $row = t06hRow(); $header = t06hSeed($row); $before = t06hSnapshot($row);
    $raw = (object) $header; $raw->revision = "1\n";
    $query = new class($raw) {
        public function __construct(private object $raw) {}
        public function where(string $field, int $id): self { return $this; }
        public function first(): object { return $this->raw; }
    };
    $manager = DB::getFacadeRoot();
    $connection = $manager->connection();
    $mock = DB::partialMock();
    $mock->shouldReceive('connection')->andReturn($connection);
    $mock->shouldReceive('table')->with('learning_p8_source_revisions')->once()->andReturn($query);
    try {
        expect($raw->revision)->toBe("1\n");
        expect(fn () => (new LearningP8SourceRevisionClock)->current($row->id))->toThrow(DomainException::class, 'learning_p8_clock.invalid_integer');
    } finally { DB::swap($manager); }
    expect(t06hSnapshot($row))->toBe($before);
    t06hWrite($row, '{"materiality_confirmation":true}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(2);
});

it('rejects insertion of an absent P8 header after each outer owner write without source overwrite', function ($owner) {
    $row = t06hRow(); t06hAllClocks();
    DB::table('characterizations')->where('id', $row->id)->update(['form_data' => '{}']);
    $before = t06hSnapshot($row); $armed = true; $hits = 0; $inserted = null; $source = null;
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBeNull();
    DB::listen(function ($query) use (&$armed, &$hits, &$inserted, &$source, $row, $owner) {
        if (! $armed || ! str_contains($query->sql, 'learning_'.$owner.'_source_revisions') || ! preg_match('/^(insert|update)/i', $query->sql)) { return; }
        $armed = false; $hits++;
        $source = DB::table('characterizations')->where('id', $row->id)->value('form_data');
        DB::table('learning_p8_source_revisions')->insert(['characterization_id' => $row->id,
            'generation' => 1, 'revision' => 1, 'epoch' => str_repeat('a', 64), 'digest' => str_repeat('b', 64)]);
        $inserted = (array) DB::table('learning_p8_source_revisions')->where('characterization_id', $row->id)->first();
        expect(DB::table('characterizations')->where('id', $row->id)->value('form_data'))->toBe($source);
    });
    $write = '{"company_profile":{"company_name":"SYNTHETIC_R2"},"materiality_proposal_review":{"x":1}}';
    try { expect(fn () => t06hWrite($row, $write))->toThrow(DomainException::class, 'learning_p8_clock.finalization_mismatch'); }
    finally { $armed = false; }
    expect($hits)->toBe(1)->and($source)->toBe($write)->and($inserted)->toBe([
        'characterization_id' => $row->id, 'generation' => 1, 'revision' => 1,
        'epoch' => str_repeat('a', 64), 'digest' => str_repeat('b', 64),
    ])->and(t06hSnapshot($row))->toBe($before);
    t06hWrite($row, '{"company_profile":{"company_name":"RECOVERED"},"materiality_proposal_review":{"x":2},"materiality_confirmation":true}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(1);
})->with(['p5', 'p6_base']);

it('rejects each initially disabled participant late enable and recovers with restored modes', function ($flag) {
    $flags = ['services.learning_source_clock.enabled', 'services.learning_p6_base_source_clock.enabled', 'services.learning_p8_source_clock.enabled'];
    $original = array_combine($flags, array_map(fn ($key) => config($key), $flags));
    t06hAllClocks(); config([$flag => false]);
    $row = t06hRow(); $before = t06hSnapshot($row); $reached = false;
    try {
        try {
            expect(function () use ($row, $flag, &$reached) { return app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row, $flag, &$reached) {
                config([$flag => true]);
                DB::table('characterizations')->where('id', $row->id)->update(['status' => 'submitted']);
                $reached = DB::table('characterizations')->where('id', $row->id)->value('status') === 'submitted';
            }); })->toThrow(DomainException::class, 'learning_source_clock.mode_changed');
        } finally { config([$flag => false]); }
        expect($reached)->toBeTrue()->and(config($flag))->toBeFalse()->and(t06hSnapshot($row))->toBe($before);
        $write = '{"company_profile":{"company_name":"RECOVERED"},"materiality_proposal_review":{"x":2},"materiality_confirmation":true}';
        expect(t06hWrite($row, $write))->toBe(1);
        expect(DB::table('characterizations')->where('id', $row->id)->value('form_data'))->toBe($write);
        foreach (array_combine($flags, ['learning_p5_source_revisions', 'learning_p6_base_source_revisions', 'learning_p8_source_revisions']) as $key => $table) {
            expect(DB::table($table)->where('characterization_id', $row->id)->count())->toBe($key === $flag ? 0 : 1);
        }
    } finally { config($original); }
})->with(['services.learning_source_clock.enabled', 'services.learning_p6_base_source_clock.enabled', 'services.learning_p8_source_clock.enabled']);

it('rejects a reached persisted identity mutation inside runForUser with rollback and recovery', function () {
    $row = t06hRow(); t06hAllClocks(); $before = t06hSnapshot($row);
    $newId = $row->id + 1000000; $reached = null;
    expect(DB::table('characterizations')->where('id', $newId)->exists())->toBeFalse();
    expect(function () use ($row, $newId, &$reached) { return app(CharacterizationStateTransaction::class)->runForUser($row->user_id, function ($current) use ($row, $newId, &$reached) {
        DB::table('characterizations')->where('id', $current->id)->update(['id' => $newId, 'form_data' => '{"materiality_confirmation":true}']);
        $reached = (array) DB::table('characterizations')->where('user_id', $row->user_id)->first();
    }); })->toThrow(DomainException::class, 'learning_p8_clock.identity_or_generation_regression');
    expect($reached['id'])->toBe($newId)->and($reached['form_data'])->toBe('{"materiality_confirmation":true}');
    expect(t06hSnapshot($row))->toBe($before)->and(DB::table('characterizations')->where('id', $newId)->exists())->toBeFalse();
    expect(app(CharacterizationStateTransaction::class)->runForUser($row->user_id, fn ($current) => DB::table('characterizations')->where('id', $current->id)->update(['form_data' => '{"materiality_confirmation":true}'])))->toBe(1);
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(1);
});

// R3 prospective admission regression: no float authority for unsupported integer tokens.
it('R3 integer admission rejects unsupported P8 tokens with full rollback and recovery', function ($value) {
    $row = t06hRow(); t06hAllClocks(); $before = t06hSnapshot($row); $reached = false;
    $raw = '{"materiality_confirmation":'.$value.'}';
    expect(function () use ($row, $raw, &$reached) {
        return app(CharacterizationStateTransaction::class)->run($row->id, function () use ($row, $raw, &$reached) {
            DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]);
            $reached = DB::table('characterizations')->where('id', $row->id)->value('form_data') === $raw;
        });
    })->toThrow(DomainException::class, 'learning_p8_clock.unsupported_integer');
    expect($reached)->toBeTrue()->and(t06hSnapshot($row))->toBe($before);
    t06hWrite($row, '{"materiality_confirmation":17}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(1);
})->with(['18446744073709551616', '18446744073709551617', '-9223372036854775809',
    '[0,{"nested":[18446744073709551617]}]', '{"nested":{"value":-9223372036854775809}}']);

it('R3 actor disappearance rejects untracked runForUser reassignment with rollback and recovery', function () {
    $row = t06hRow(); t06hAllClocks(); $before = t06hSnapshot($row);
    $newUser = User::factory()->create()->id; $reached = null;
    expect(function () use ($row, $newUser, &$reached) {
        return app(CharacterizationStateTransaction::class)->runForUser($row->user_id, function ($current) use ($newUser, &$reached) {
            DB::table('characterizations')->where('id', $current->id)->update(['user_id' => $newUser, 'form_data' => '{"materiality_confirmation":true}']);
            $reached = (array) DB::table('characterizations')->where('id', $current->id)->first();
        });
    })->toThrow(DomainException::class, 'learning_p8_clock.identity_or_generation_regression');
    expect($reached['user_id'])->toBe($newUser)->and($reached['form_data'])->toBe('{"materiality_confirmation":true}')
        ->and(t06hSnapshot($row))->toBe($before);
    expect(app(CharacterizationStateTransaction::class)->runForUser($row->user_id, fn ($current) =>
        DB::table('characterizations')->where('id', $current->id)->update(['form_data' => '{"materiality_confirmation":true}'])))->toBe(1);
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(1);
});

it('R3 integer controls preserve native bounds literal strings and finite exponents', function ($left, $right) {
    $row = t06hRow(); t06hAllClocks();
    t06hWrite($row, '{"materiality_confirmation":'.$left.'}');
    $a = (new LearningP8SourceRevisionClock)->current($row->id);
    t06hWrite($row, '{"materiality_confirmation":'.$right.'}');
    $b = (new LearningP8SourceRevisionClock)->current($row->id);
    expect($b['revision'])->toBe(2)->and($b['digest'])->not->toBe($a['digest']);
    expect(DB::table('characterizations')->where('id', $row->id)->value('form_data'))->toBe('{"materiality_confirmation":'.$right.'}');
})->with([[(string) PHP_INT_MIN, (string) PHP_INT_MAX], ['"18446744073709551616"', '"18446744073709551617"'],
    ['"-9223372036854775809"', '-1'], ['1', '1.0'], ['1e30', '1e31']]);

it('R3 integer controls ignore huge integers in unrelated root sections', function () {
    $row = t06hRow(); t06hAllClocks();
    t06hWrite($row, '{"materiality_confirmation":{"value":17},"other":18446744073709551616}');
    $before = (new LearningP8SourceRevisionClock)->current($row->id);
    $raw = '{"other":{"nested":[18446744073709551617,-9223372036854775809]},"materiality_confirmation":{"value":17}}';
    t06hWrite($row, $raw);
    expect((new LearningP8SourceRevisionClock)->current($row->id))->toBe($before)
        ->and(DB::table('characterizations')->where('id', $row->id)->value('form_data'))->toBe($raw);
});

it('R3 stored unsupported integer blocks observation before mutation without passive repair', function ($tracked, $value) {
    $row = t06hRow(); t06hAllClocks();
    if ($tracked) { t06hSeed($row); }
    $raw = '{"materiality_confirmation":{"nested":['.$value.']}}';
    DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $raw]);
    $before = t06hSnapshot($row); $reached = false;
    $clock = new LearningP8SourceRevisionClock;
    if ($tracked) { expect(fn () => $clock->current($row->id))->toThrow(DomainException::class, 'unsupported_integer'); }
    else { expect($clock->current($row->id))->toBeNull(); }
    expect(function () use ($row, &$reached) {
        return DB::transaction(fn () => (new LearningP8SourceRevisionClock)->observe($row->user_id, fn () => $row->fresh(), function () use ($row, &$reached) {
            $reached = true;
            DB::table('characterizations')->where('id', $row->id)->update(['form_data' => '{}']);
        }));
    })->toThrow(DomainException::class, 'unsupported_integer');
    expect($reached)->toBeFalse()->and(t06hSnapshot($row))->toBe($before);
    // Explicit synthetic restoration; passive observation never repairs unsupported source.
    DB::table('characterizations')->where('id', $row->id)->update(['form_data' => $tracked
        ? '{"company_profile":{"company_name":"SYNTHETIC_A"},"esg_focus":{"topic_ids":[]},"materiality_confirmation":{"note":"A"}}' : '[]']);
    t06hWrite($row, '{"materiality_confirmation":19}');
    expect($clock->current($row->id)['revision'])->toBe($tracked ? 2 : 1);
})->with([[false, '18446744073709551617'], [true, '18446744073709551617'],
    [false, '-9223372036854775809'], [true, '-9223372036854775809']]);

it('R3 tracked integer rejection restores all raw metadata and recovers', function () {
    $row = t06hRow(); t06hSeed($row, true); $before = t06hSnapshot($row);
    expect(fn () => t06hWrite($row, '{"materiality_confirmation":{"nested":[18446744073709551617]}}'))
        ->toThrow(DomainException::class, 'unsupported_integer');
    expect(t06hSnapshot($row))->toBe($before);
    t06hWrite($row, '{"materiality_confirmation":18}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe(2);
});

it('R3 identity controls reject reachable single identity changes and recover', function ($owner, $field, $tracked) {
    $row = t06hRow(); t06hAllClocks(); if ($tracked) { t06hSeed($row); }
    $before = t06hSnapshot($row); $reached = null;
    $target = $field === 'id' ? $row->id + 1000000 : User::factory()->create()->id;
    $callback = function ($current) use ($field, $target, &$reached) {
        DB::table('characterizations')->where('id', $current->id)->update([$field => $target]);
        $reached = DB::table('characterizations')->where($field, $target)->exists();
    };
    expect(fn () => $owner === 'run'
        ? app(CharacterizationStateTransaction::class)->run($row->id, $callback)
        : app(CharacterizationStateTransaction::class)->runForUser($row->user_id, $callback))->toThrow(DomainException::class);
    expect($reached)->toBeTrue()->and(t06hSnapshot($row))->toBe($before)
        ->and(DB::table('characterizations')->where($field, $target)->exists())->toBeFalse();
    t06hWrite($row, '{"materiality_confirmation":true}');
    expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe($tracked ? 2 : 1);
})->with([['run', 'id', false], ['run', 'user_id', false], ['runForUser', 'id', false],
    ['runForUser', 'user_id', true]]);

it('R3 deletion controls preserve physical delete cascade rollback and next operation', function ($owner, $tracked, $reject) {
    $row = t06hRow(); t06hAllClocks(); if ($tracked) { t06hSeed($row); }
    $before = t06hSnapshot($row); $reached = false;
    $callback = function ($current) use ($reject, &$reached) {
        DB::table('characterizations')->where('id', $current->id)->delete();
        $reached = ! DB::table('characterizations')->where('id', $current->id)->exists();
        if ($reject) { throw new DomainException('synthetic rejected physical deletion'); }
        return 'deleted';
    };
    $operation = fn () => $owner === 'run'
        ? app(CharacterizationStateTransaction::class)->run($row->id, $callback)
        : app(CharacterizationStateTransaction::class)->runForUser($row->user_id, $callback);
    if ($reject) {
        expect($operation)->toThrow(DomainException::class, 'synthetic rejected physical deletion');
        expect(t06hSnapshot($row))->toBe($before);
        t06hWrite($row, '{"materiality_confirmation":true}');
        expect((new LearningP8SourceRevisionClock)->current($row->id)['revision'])->toBe($tracked ? 2 : 1);
    } else {
        expect($operation())->toBe('deleted')->and((new LearningP8SourceRevisionClock)->current($row->id))->toBeNull();
        expect(t06hSnapshot($row))->toBe([[], 'learning_p5_source_revisions' => [],
            'learning_p6_base_source_revisions' => [], 'learning_p8_source_revisions' => []]);
        $next = t06hRow(); t06hWrite($next, '{"materiality_confirmation":true}');
        expect((new LearningP8SourceRevisionClock)->current($next->id)['revision'])->toBe(1);
    }
    expect($reached)->toBeTrue();
})->with([['run', false, false], ['run', true, false], ['runForUser', false, false], ['runForUser', true, false],
    ['run', false, true], ['run', true, true], ['runForUser', false, true], ['runForUser', true, true]]);
