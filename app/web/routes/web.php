<?php

use App\Http\Controllers\CharacterizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\AuthenticatePrivateDevUser;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('characterization.create')
        : redirect()->route('login');
})->middleware(AuthenticatePrivateDevUser::class);

Route::get('/dashboard', function () {
    return redirect()->route('characterization.create');
})->middleware([AuthenticatePrivateDevUser::class, 'auth'])->name('dashboard');

Route::middleware([AuthenticatePrivateDevUser::class, 'auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/characterization', [CharacterizationController::class, 'create'])
        ->name('characterization.create');
    Route::post('/characterization', [CharacterizationController::class, 'store'])
        ->name('characterization.store');
    Route::post('/characterization/retry', [CharacterizationController::class, 'retry'])
        ->name('characterization.retry');
    Route::get('/characterization/summary', [CharacterizationController::class, 'summary'])
        ->name('characterization.summary');
});

require __DIR__.'/auth.php';
