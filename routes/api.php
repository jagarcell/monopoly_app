<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\GameInvitationController;
use App\Http\Controllers\PlayerIconController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Unauthenticated guest draw routes — access is gated by the accepted invitation token.
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/join/{token}/chance/draw', [GameInvitationController::class, 'drawGuestChanceCard'])
        ->name('api.join.chance.draw');
    Route::post('/join/{token}/community/draw', [GameInvitationController::class, 'drawGuestCommunityChestCard'])
        ->name('api.join.community.draw');
    Route::post('/join/{token}/roll', [GameInvitationController::class, 'guestRollDice'])
        ->name('api.join.roll');
    Route::post('/join/{token}/turn/end', [GameInvitationController::class, 'guestEndTurn'])
        ->name('api.join.turn.end');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/player-icons', [PlayerIconController::class, 'index'])->name('api.player-icons.index');
    Route::post('/games', [GameController::class, 'store'])->name('api.games.store');
    Route::post('/games/{gameId}/invitations', [GameInvitationController::class, 'store'])->name('api.games.invitations.store');
    Route::post('/games/{gameId}/chance/draw', [GameController::class, 'drawChanceCard'])->name('api.games.chance.draw');
    Route::post('/games/{gameId}/community/draw', [GameController::class, 'drawCommunityChestCard'])->name('api.games.community.draw');
    Route::post('/games/{gameId}/roll', [GameController::class, 'rollDice'])->name('api.games.roll');
    Route::post('/games/{gameId}/turn/end', [GameController::class, 'endTurn'])->name('api.games.turn.end');
});
