<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\GameInvitationController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Auth/Login', [
        'canResetPassword' => Route::has('password.request'),
        'status' => session('status'),
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/games/in-progress', [GameController::class, 'inProgress'])
    ->middleware(['auth', 'verified'])
    ->name('games.in-progress');

// Unauthenticated game join routes — access is gated by the invitation token.
Route::get('/join/{token}', [GameInvitationController::class, 'show'])->name('game.join.show');
Route::post('/join/{token}/accept', [GameInvitationController::class, 'accept'])
    ->middleware('throttle:10,1')
    ->name('game.join.accept');
Route::get('/join/{token}/game', [GameInvitationController::class, 'game'])->name('game.join.board');

Route::middleware('auth')->group(function () {
    Route::get('/games/{gameId}', [GameController::class, 'show'])->name('game.board');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
