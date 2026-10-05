<?php

use App\Models\{Characterization, User};
use App\Services\{CharacterizationStateTransaction, LearningP8SourceRevisionClock};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\{DB, Http};

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    expect(DB::connection()->transactionLevel())->toBe(1);
    DB::connection()->commit();
    RefreshDatabaseState::$migrated = false;
    Http::preventStrayRequests();
    config(['services.learning_p8_source_clock.enabled' => true]);
});

it('rejects synthetic source disappearance after the final P8 header with exact rollback and recovery', function ($adapter) {
    $user = User::factory()->create(['name' => 'SYNTHETIC_T06M', 'email' => 'synthetic-t06m@example.invalid']);
    $row = Characterization::query()->create(['user_id' => $user->id,
        'status' => 'draft', 'form_data' => [], 'submission_generation' => 1])->fresh();
    $common = app(CharacterizationStateTransaction::class);
    $clock = new LearningP8SourceRevisionClock;
    $write = function (string $raw) use ($adapter, $common, $row, $user) {
        $operation = fn ($current) => DB::table('characterizations')->where('id', $current->id)->update(['form_data' => $raw]);
        return $adapter === 'run' ? $common->run($row->id, $operation) : $common->runForUser($user->id, $operation);
    };
    $snapshot = fn () => [
        (array) DB::table('characterizations')->where('id', $row->id)->first(),
        (array) DB::table('learning_p8_source_revisions')->where('characterization_id', $row->id)->first(),
    ];
    $write('{"materiality_confirmation":{"note":"SYNTHETIC_BEFORE"}}');
    $before = $snapshot();
    expect($before[1]['revision'])->toBe(1);
    $pdo = DB::connection()->getPdo();
    $armed = true; $seam = null;
    DB::listen(function (QueryExecuted $query) use (&$armed, &$seam, $row, $pdo) {
        if (! $armed || ! preg_match('/^select\b/i', $query->sql)
            || ! str_contains($query->sql, '"learning_p8_source_revisions"')) { return; }
        // PDO bypasses query events: only the already advanced final header can arm deletion.
        $header = $pdo->prepare('SELECT revision FROM learning_p8_source_revisions WHERE characterization_id = ?');
        $header->execute([$row->id]);
        if ((int) $header->fetchColumn() !== 2) { return; }
        $armed = false;
        $delete = $pdo->prepare('DELETE FROM characterizations WHERE id = ?');
        $delete->execute([$row->id]);
        $absent = $pdo->prepare('SELECT COUNT(*) FROM characterizations WHERE id = ?');
        $absent->execute([$row->id]);
        $seam = ['same_pdo' => $query->connection->getPdo() === $pdo,
            'transaction_level' => $query->connection->transactionLevel(),
            'deleted' => $delete->rowCount(), 'absent' => (int) $absent->fetchColumn()];
    });
    $error = null;
    try { $write('{"materiality_confirmation":{"note":"SYNTHETIC_LATE"}}'); }
    catch (Throwable $caught) { $error = $caught; }
    finally { $armed = false; }
    expect($seam)->toBe(['same_pdo' => true, 'transaction_level' => 1, 'deleted' => 1, 'absent' => 0]);
    expect($snapshot())->toBe($before);
    expect(DB::connection()->transactionLevel())->toBe(0);
    expect($clock->hasActiveScope($user->id))->toBeFalse();
    expect($write('{"materiality_confirmation":{"note":"SYNTHETIC_RECOVERED"}}'))->toBe(1);
    $recovered = $clock->current($row->id);
    expect($recovered['revision'])->toBe(2)->and($recovered['epoch'])->toBe($before[1]['epoch'])
        ->and($recovered['digest'])->not->toBe($before[1]['digest']);
    expect(DB::table('characterizations')->where('id', $row->id)->value('form_data'))
        ->toBe('{"materiality_confirmation":{"note":"SYNTHETIC_RECOVERED"}}');
    expect($clock->hasActiveScope($user->id))->toBeFalse();
    expect(fn () => $error === null ? null : throw $error)
        ->toThrow(DomainException::class, 'learning_p8_clock.readback_mismatch');
})->with(['run', 'runForUser']);