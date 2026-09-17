<?php

use App\Http\Controllers\CharacterizationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * Unauthenticated liveness/readiness probe for public uptime monitoring.
 *
 * The response contains only coarse product health signals: overall status,
 * database reachability, recent queue availability, and current server time.
 * Every dependency probe is wrapped so probe failures degrade the reported
 * state instead of exposing stack traces or configuration details.
 */
Route::get('/healthz', function () {
    $db = 'ok';
    try {
        DB::connection()->getPdo();
        DB::select('select 1');
    } catch (\Throwable $e) {
        $db = 'fail';
    }

    // Best-effort queue signal: "ok" only when the database queue table is
    // reachable and no unreserved job has been waiting past the stale threshold.
    $queueRecent = 'unknown';
    try {
        if ($db === 'ok' && Schema::hasTable('jobs')) {
            $oldestPending = DB::table('jobs')
                ->whereNull('reserved_at')
                ->min('available_at');

            if ($oldestPending === null) {
                $queueRecent = 'ok';
            } else {
                $queueRecent = (time() - (int) $oldestPending) < 300 ? 'ok' : 'unknown';
            }
        }
    } catch (\Throwable $e) {
        $queueRecent = 'unknown';
    }

    $status = $db === 'ok' ? 'ok' : 'degraded';

    return response()->json([
        'status' => $status,
        'db' => $db,
        'queue_recent' => $queueRecent,
        'time' => now()->toIso8601String(),
    ], $db === 'ok' ? 200 : 503);
})->name('healthz');

Route::get('/', function () {
    return auth()->check()
        ? redirect('/dashboard')
        : redirect()->route('login');
});

Route::get('/dashboard', function () {
    return redirect('/wizard/step-1');
})->middleware('auth')->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/characterization', [CharacterizationController::class, 'create'])
        ->name('characterization.create');
    Route::post('/characterization/retry', [CharacterizationController::class, 'retry'])
        ->name('characterization.retry');
    Route::get('/characterization/summary', [CharacterizationController::class, 'summary'])
        ->name('characterization.summary');
});

require __DIR__.'/auth.php';
