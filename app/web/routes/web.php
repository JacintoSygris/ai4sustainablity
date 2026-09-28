<?php

use App\Http\Controllers\CharacterizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\AuthenticatePrivateDevUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * Unauthenticated liveness/readiness probe for external uptime monitoring.
 *
 * Design constraints (see app/docs/production-secrets-audit.md and
 * app/docs/deployment-i4s.md):
 * - No auth: intentionally reachable without a Laravel session so an uptime
 *   monitor can poll it. Behind the Apache Basic Auth outer gate it still needs
 *   the folder credential OR a scoped Apache exception for /healthz (see the
 *   deployment doc for the recommended vhost snippet).
 * - No secret / framework / version leakage: the payload exposes only a
 *   non-sensitive deploy release id (the release directory name) plus coarse
 *   dependency states. No stack traces, no config values, no framework name.
 * - Dependency-light and fail-safe: every probe is wrapped so a probe failure
 *   degrades the reported state instead of throwing a 500.
 */
Route::get('/healthz', function () {
    // Release id: prefer an explicit APP_RELEASE env (read via getenv so it
    // survives config caching); otherwise derive it from the resolved release
    // directory name (app/current -> app/releases/<release-id> on the VPS).
    $release = getenv('APP_RELEASE');
    if (! is_string($release) || trim($release) === '') {
        $resolved = realpath(base_path());
        $release = $resolved ? basename($resolved) : 'unknown';
    }

    // Critical dependency: database connectivity.
    $db = 'ok';
    try {
        DB::connection()->getPdo();
        DB::select('select 1');
    } catch (\Throwable $e) {
        $db = 'fail';
    }

    // Best-effort queue signal: positively "ok" only when the database queue
    // table is reachable and no unreserved job has been waiting past the stale
    // threshold (worker likely keeping up); otherwise "unknown" (never a false
    // alarm, never leaks internals).
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
        'app_release' => $release,
        'db' => $db,
        'queue_recent' => $queueRecent,
        'time' => now()->toIso8601String(),
    ], $db === 'ok' ? 200 : 503);
})->name('healthz');

Route::get('/', function () {
    return auth()->check()
        ? redirect('/dashboard')
        : redirect()->route('login');
})->middleware(AuthenticatePrivateDevUser::class);

Route::get('/dashboard', function () {
    return redirect('/wizard/step-1');
})->middleware([AuthenticatePrivateDevUser::class, 'auth'])->name('dashboard');

Route::middleware([AuthenticatePrivateDevUser::class, 'auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/characterization', [CharacterizationController::class, 'create'])
        ->middleware('verified.required')
        ->name('characterization.create');
    Route::post('/characterization/retry', [CharacterizationController::class, 'retry'])
        ->middleware('verified.required')
        ->name('characterization.retry');
    Route::get('/characterization/summary', [CharacterizationController::class, 'summary'])
        ->middleware('verified.required')
        ->name('characterization.summary');
});

require __DIR__.'/auth.php';
