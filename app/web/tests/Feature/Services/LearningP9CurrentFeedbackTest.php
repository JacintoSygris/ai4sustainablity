<?php

use App\Models\{Characterization, User, EsrsTopic};
use App\Services\{EsrsDatapointResponseState, EsrsDatapointCorpusBuilder, Ar16MatterDrMappingRepository, CharacterizationStateTransaction, LearningP5SourceRevisionClock, LearningP6BaseSourceRevisionClock, LearningP8SourceRevisionClock, LearningP9SourceRevisionClock};
use Illuminate\Support\Facades\{DB, Schema, Http};
use Illuminate\Database\Schema\Blueprint;

// Synthetic inline corpus port ONLY: no normative builder/T11 or integrated API qualification.
// Declared authority: P5 phase-in, P8 scope, P6 baseline, generation and synthetic map/catalog.
class T06pSyntheticBuilder extends EsrsDatapointCorpusBuilder
{
    public string $catalog = 'synthetic-v1';
    public string $map = 'synthetic-map-v1';
    public int $calls = 0;
    public ?Closure $during = null;
    public function build(Characterization $characterization): array
    {
        $this->calls++;
        $form = $characterization->form_data;
        $corpus = ['generation' => $characterization->submission_generation,
            'catalog' => $this->catalog, 'map' => $this->map,
            'phase_in' => ($form['operations']['employee_count'] ?? 0) < 750,
            'p8' => $form['materiality_confirmation']['confirmed_topic_ids'] ?? [],
            'p6' => $characterization->esrs_topic_ids ?? [],
            'blocks' => [['datapoints' => [['id' => 'SYNTHETIC_A'], ['id' => 'SYNTHETIC_B']]]]];
        if ($this->during !== null) { ($this->during)($this, $characterization); }
        return $corpus;
    }
}

beforeEach(function () {
    Http::preventStrayRequests();
    foreach (['learning_source_clock', 'learning_p6_base_source_clock', 'learning_p8_source_clock', 'learning_p9_source_clock'] as $flag) {
        config(['services.'.$flag.'.enabled' => true]);
    }
    Schema::create('learning_p9_source_revisions', function (Blueprint $table) {
        $table->foreignId('characterization_id')->primary()->constrained('characterizations')->cascadeOnDelete();
        $table->unsignedBigInteger('generation'); $table->unsignedBigInteger('revision');
        $table->string('epoch', 64); $table->string('digest', 64);
    });
});
function t06pState(): EsrsDatapointResponseState { return new EsrsDatapointResponseState(new Ar16MatterDrMappingRepository); }
function t06pClocks(): array { return ['p5' => new LearningP5SourceRevisionClock, 'p6_base' => new LearningP6BaseSourceRevisionClock, 'p8' => new LearningP8SourceRevisionClock, 'p9' => new LearningP9SourceRevisionClock]; }
function t06pHeaders(Characterization $row): array { return array_map(fn ($clock) => $clock->current($row->id), t06pClocks()); }
function t06pSource(): array
{
    $builder = new T06pSyntheticBuilder(new Ar16MatterDrMappingRepository);
    $user = User::factory()->create(['name' => 'SYNTHETIC_ACCOUNT', 'email' => 't06p-'.User::count().'@example.invalid']);
    $row = (new CharacterizationStateTransaction)->runForUser($user->id, fn () => Characterization::create([
        'user_id' => $user->id, 'status' => 'completed', 'submission_generation' => 1, 'esrs_topic_ids' => [1, 2],
        'form_data' => ['operations' => ['employee_count' => 10], 'materiality_confirmation' => ['confirmed_topic_ids' => [1, 2]], 'esrs_datapoint_responses' => []],
        'result_data' => ['status' => 'completed', 'synthetic' => true]]));
    $feedback = ['schema_version' => 'datapoint-feedback-v1', 'authority_digest' => t06pState()->learningAuthorityDigest($builder->build($row->fresh())),
        'reviewed_datapoint_ids' => ['SYNTHETIC_B', 'SYNTHETIC_A'], 'decisions' => [
            ['datapoint_id' => 'SYNTHETIC_B', 'relevant' => false, 'selected_to_answer' => false, 'reason_codes' => ['SYNTHETIC_REASON'], 'note' => 'SYNTHETIC_NOTE'],
            ['datapoint_id' => 'SYNTHETIC_A', 'relevant' => true, 'selected_to_answer' => true, 'reason_codes' => [], 'note' => null]]];
    (new CharacterizationStateTransaction)->run($row->id, function ($fresh) use ($feedback) {
        $form = $fresh->form_data; $form['esrs_datapoint_responses'] = ['learning_feedback' => $feedback, 'responses' => ['SYNTHETIC_A' => ['status' => 'completed', 'value' => 'SYNTHETIC_VALUE']]];
        $fresh->form_data = $form; $fresh->save();
    });
    $row = $row->fresh(); $builder->calls = 0;
    return [$row, t06pHeaders($row), $builder, $feedback];
}
function t06pRead(array $fixture): array { return t06pState()->projectLearningFeedbackForAccount($fixture[0]->user_id, $fixture[1], $fixture[2]); }
function t06pSnapshot(): array
{
    $out = [];
    foreach (['characterizations', 'learning_p5_source_revisions', 'learning_p6_base_source_revisions', 'learning_p8_source_revisions', 'learning_p9_source_revisions'] as $table) {
        $out[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
    }
    return $out;
}
it('C1 inventory private feedback signature', function () { expect(method_exists(EsrsDatapointResponseState::class, 'projectLearningFeedbackForAccount'))->toBeTrue(); });
it('C1 exact feedback false and semantic list order survive passive current projection', function () {
    [$row, $headers, $builder, $feedback] = $fixture = t06pSource(); $before = t06pSnapshot();
    DB::enableQueryLog(); DB::flushQueryLog(); $result = t06pRead($fixture);
    expect($result)->toBe(['source_headers' => $headers, 'learning_authority_digest' => $feedback['authority_digest'], 'learning_feedback' => $feedback]);
    expect(array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query'])))->toBe([]);
    DB::disableQueryLog(); expect(t06pSnapshot())->toBe($before); Http::assertNothingSent();
});
it('C1 exact native headers reject partial extra typed foreign and missing sources without seed', function () {
    [$row, $headers, $builder] = $fixture = t06pSource(); $before = t06pSnapshot();
    $bad = [[], $headers + ['unknown' => []]];
    foreach ($headers as $name => $header) {
        $extra = $headers; $extra[$name]['extra'] = 1; $bad[] = $extra;
        foreach ($header as $key => $value) {
            $partial = $headers; unset($partial[$name][$key]); $bad[] = $partial;
            $typed = $headers; $typed[$name][$key] = is_int($value) ? (string) $value : 'bad'; $bad[] = $typed;
        }
    }
    foreach ($bad as $expected) { expect(fn () => t06pState()->projectLearningFeedbackForAccount($row->user_id, $expected, $builder))->toThrow(DomainException::class); }
    $foreign = User::factory()->create();
    expect(fn () => t06pState()->projectLearningFeedbackForAccount($foreign->id, $headers, $builder))->toThrow(DomainException::class);
    expect(t06pSnapshot())->toBe($before);
    $reverse = array_map(fn ($h) => array_reverse($h, true), array_reverse($headers, true));
    expect(t06pState()->projectLearningFeedbackForAccount($row->user_id, $reverse, $builder)['source_headers'])->toBe($headers);
    $absent = User::factory()->create();
    expect(fn () => t06pState()->projectLearningFeedbackForAccount($absent->id, $headers, $builder))->toThrow(DomainException::class);
    expect(t06pSnapshot())->toBe($before);
});
it('C1 strict flags environment metadata precede PDO and SQL', function () {
    $connection = DB::connection(); $pdo = $connection->getPdo(); $property = new ReflectionProperty($connection, 'pdo');
    $builder = new T06pSyntheticBuilder(new Ar16MatterDrMappingRepository);
    foreach (['learning_source_clock', 'learning_p6_base_source_clock', 'learning_p8_source_clock', 'learning_p9_source_clock'] as $flag) {
        foreach ([false, null, 'true', 1] as $value) {
            config(['services.'.$flag.'.enabled' => $value]); $property->setValue($connection, fn () => throw new LogicException('PDO forbidden'));
            try { expect(fn () => t06pState()->projectLearningFeedbackForAccount(1, [], $builder))->toThrow(DomainException::class); }
            finally { $property->setValue($connection, $pdo); config(['services.'.$flag.'.enabled' => true]); }
        }
    }
    app()->instance('env', 'production'); $property->setValue($connection, fn () => throw new LogicException('PDO forbidden'));
    try { expect(fn () => t06pState()->projectLearningFeedbackForAccount(1, [], $builder))->toThrow(DomainException::class); }
    finally { app()->instance('env', 'testing'); $property->setValue($connection, $pdo); }
    $database = $connection->getDatabaseName(); $connection->setDatabaseName('synthetic-disk');
    $property->setValue($connection, fn () => throw new LogicException('PDO forbidden'));
    try { expect(fn () => t06pState()->projectLearningFeedbackForAccount(1, [], $builder))->toThrow(DomainException::class); }
    finally { $connection->setDatabaseName($database); $property->setValue($connection, $pdo); }
});
it('C1 unknown empty unreviewed and stale evidence never become negatives', function () {
    [$row, $headers, $builder] = t06pSource();
    foreach ([[], ['authority_digest' => 'stale'], ['schema_version' => 'datapoint-feedback-v1', 'authority_digest' => t06pState()->learningAuthorityDigest($builder->build($row)), 'reviewed_datapoint_ids' => [], 'decisions' => []]] as $feedback) {
        (new CharacterizationStateTransaction)->run($row->id, function ($fresh) use ($feedback) {
            $form = $fresh->form_data; $form['esrs_datapoint_responses']['learning_feedback'] = $feedback; $fresh->form_data = $form; $fresh->save();
        });
        $before = t06pSnapshot();
        expect(t06pRead([$row, t06pHeaders($row), $builder])['learning_feedback']['decisions'])->toBe([]);
        expect(t06pSnapshot())->toBe($before);
    }
});
it('C2 authority lifecycle before call invalidates labels without altering evidence', function () {
    foreach (['p5', 'p8', 'p6', 'generation', 'map', 'catalog', 'list_order'] as $kind) {
        [$row, $headers, $builder] = t06pSource();
        if (in_array($kind, ['map', 'catalog'], true)) { $builder->$kind = 'SYNTHETIC_CHANGED'; }
        else { (new CharacterizationStateTransaction)->run($row->id, function ($fresh) use ($kind) {
            $form = $fresh->form_data;
            if ($kind === 'p5') { $form['operations']['employee_count'] = 900; }
            if ($kind === 'p8') { $form['materiality_confirmation']['confirmed_topic_ids'] = [1]; }
            if ($kind === 'list_order') { $form['materiality_confirmation']['confirmed_topic_ids'] = [2, 1]; }
            if ($kind === 'p6') { $fresh->esrs_topic_ids = [2]; }
            if ($kind === 'generation') { $fresh->submission_generation = 2; }
            $fresh->form_data = $form; $fresh->save();
        }); }
        $before = t06pSnapshot();
        expect(t06pRead([$row, t06pHeaders($row), $builder])['learning_feedback']['decisions'])->toBe([]);
        expect(t06pSnapshot())->toBe($before);
    }
});
it('C2 malformed typed reviewed universe preserves stored unknown evidence', function () {
    [$row, $headers, $builder, $feedback] = t06pSource();
    $bad = [];
    $x = $feedback; $x['reviewed_datapoint_ids'][0] = 1; $bad[] = $x;
    $x = $feedback; $x['reviewed_datapoint_ids'][0] = 'OUTSIDE_SYNTHETIC'; $bad[] = $x;
    $x = $feedback; $x['decisions'][0]['relevant'] = 'false'; $bad[] = $x;
    $x = $feedback; $x['decisions'][0]['selected_to_answer'] = 0; $bad[] = $x;
    $x = $feedback; $x['decisions'][0]['reason_codes'] = ['']; $bad[] = $x;
    $x = $feedback; $x['decisions'][0]['note'] = []; $bad[] = $x;
    $x = $feedback; $x['reviewed_datapoint_ids'] = ['SYNTHETIC_A', 'SYNTHETIC_A']; $bad[] = $x;
    $x = $feedback; $x['decisions'] = []; $bad[] = $x;
    foreach ($bad as $stored) {
        (new CharacterizationStateTransaction)->run($row->id, function ($fresh) use ($stored) {
            $form = $fresh->form_data; $form['esrs_datapoint_responses']['learning_feedback'] = $stored; $fresh->form_data = $form; $fresh->save();
        });
        $before = t06pSnapshot();
        expect(t06pRead([$row, t06pHeaders($row), $builder])['learning_feedback']['decisions'])->toBe([]);
        expect(t06pSnapshot())->toBe($before);
    }
});
it('C2 authority and physical source drift during builder rejects rolls back and recovers', function () {
    foreach (['catalog', 'source', 'positive'] as $kind) {
        [$row, $headers, $builder] = $fixture = t06pSource(); $before = t06pSnapshot(); $hits = 0;
        $builder->during = function ($port) use ($row, $kind, &$hits) {
            if ($hits++ !== 0) { return; }
            if ($kind === 'catalog') { $port->catalog = 'SYNTHETIC_DRIFT'; }
            if ($kind === 'source') { DB::table('characterizations')->where('id', $row->id)->update(['status' => 'draft']); }
        };
        if ($kind === 'positive') { expect(t06pRead($fixture)['source_headers'])->toBe($headers); }
        else { expect(fn () => t06pRead($fixture))->toThrow(DomainException::class); }
        expect($hits)->toBeGreaterThan(0)->and(t06pSnapshot())->toBe($before);
        $builder->during = null; $builder->catalog = 'synthetic-v1';
        expect(t06pRead($fixture)['source_headers'])->toBe($headers);
        foreach (t06pClocks() as $clock) { if (method_exists($clock, 'hasActiveScope')) { expect($clock->hasActiveScope($row->user_id))->toBeFalse(); } }
    }
});
it('C2 relevant model metadata and shared PDO aliases have explicit admission', function () {
    [$row, $headers, $builder] = $fixture = t06pSource(); $resolver = Characterization::getConnectionResolver(); $default = DB::connection();
    foreach (['unsafe', 'separate', 'shared'] as $kind) {
        $alias = new Illuminate\Database\SQLiteConnection($kind === 'unsafe' ? fn () => throw new LogicException('unsafe PDO opened') : ($kind === 'shared' ? $default->getPdo() : new PDO('sqlite::memory:')), $kind === 'unsafe' ? 'synthetic-disk' : ':memory:', '', ['driver' => 'sqlite']);
        Characterization::setConnectionResolver(new class($alias) implements Illuminate\Database\ConnectionResolverInterface {
            public function __construct(private $alias) {}
            public function connection($name = null) { return $this->alias; }
            public function getDefaultConnection() { return 'synthetic'; }
            public function setDefaultConnection($name) {}
        });
        try {
            if ($kind === 'shared') { expect(t06pRead($fixture)['source_headers'])->toBe($headers); }
            else { expect(fn () => t06pRead($fixture))->toThrow(DomainException::class); }
        } finally { Characterization::setConnectionResolver($resolver); }
    }
});
it('C3 physical postCommon commit seam rejects all late frames with exact rollback scopes and next read', function () {
    foreach (['p9', 'p8', 'actor', 'p5', 'p6', 'header', 'generation', 'mode', 'positive'] as $kind) {
        [$row, $headers, $builder] = $fixture = t06pSource();
        $other = User::factory()->create(); $before = t06pSnapshot();
        $connection = DB::connection(); $level = $connection->transactionLevel();
        $dispatcher = $connection->getEventDispatcher(); $connection->setEventDispatcher(clone $dispatcher); $hits = 0; $effects = 0;
        $connection->getEventDispatcher()->listen(Illuminate\Database\Events\TransactionCommitted::class,
            function ($event) use ($row, $other, $kind, $connection, &$hits, &$effects) {
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                $common = array_filter($trace, fn ($f) => ($f['class'] ?? '') === CharacterizationStateTransaction::class && ($f['function'] ?? '') === 'runForUser');
                $owners = array_filter($trace, fn ($f) => ($f['class'] ?? '') === CharacterizationStateTransaction::class && ($f['function'] ?? '') === 'observeOwners');
                if ($hits !== 0 || $common === [] || $owners !== []) { return; }
                $hits++; expect($event->connection->getPdo())->toBe($connection->getPdo());
                expect($connection->transactionLevel())->toBeGreaterThan(0);
                if ($kind === 'positive') { return; }
                if ($kind === 'mode') { config(['services.learning_p9_source_clock.enabled' => false]); $effects++; return; }
                if ($kind === 'header') { $effects += DB::table('learning_p9_source_revisions')->where('characterization_id', $row->id)->update(['revision' => 999]); return; }
                $fresh = $row->fresh(); $form = $fresh->form_data;
                $changes = [];
                if ($kind === 'p9') { $form['esrs_datapoint_responses']['learning_feedback']['decisions'][0]['relevant'] = true; }
                if ($kind === 'p8') { $form['materiality_confirmation']['confirmed_topic_ids'] = [2]; }
                if ($kind === 'p5') { $form['operations']['employee_count'] = 900; }
                if ($kind === 'p6') { $changes['result_data'] = '{"status":"completed","synthetic":"LATE"}'; }
                if ($kind === 'actor') { $changes['user_id'] = $other->id; }
                if ($kind === 'generation') { $changes['submission_generation'] = 2; }
                $changes['form_data'] = json_encode($form, JSON_THROW_ON_ERROR);
                $effects += DB::table('characterizations')->where('id', $row->id)->update($changes);
            });
        try {
            if ($kind === 'positive') { expect(t06pRead($fixture)['source_headers'])->toBe($headers); }
            else { expect(fn () => t06pRead($fixture))->toThrow(DomainException::class); }
        } finally { $connection->setEventDispatcher($dispatcher); config(['services.learning_p9_source_clock.enabled' => true]); }
        expect($hits)->toBe(1)->and($effects)->toBe($kind === 'positive' ? 0 : 1);
        expect(t06pSnapshot())->toBe($before)->and($connection->transactionLevel())->toBe($level);
        foreach (t06pClocks() as $clock) {
            if (method_exists($clock, 'hasActiveScope')) { expect($clock->hasActiveScope($row->user_id))->toBeFalse(); }
        }
        // P5 has no hasActiveScope port: a fresh Common write/noop proves its scope was released.
        (new CharacterizationStateTransaction)->run($row->id, fn () => null);
        expect(t06pRead($fixture)['source_headers'])->toBe($headers);
    }
});
