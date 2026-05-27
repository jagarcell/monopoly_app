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
    Route::post('/join/{token}/debug/move', [GameInvitationController::class, 'guestDebugMoveToSquare'])
        ->name('api.join.debug.move');
    Route::post('/join/{token}/turn/end', [GameInvitationController::class, 'guestEndTurn'])
        ->name('api.join.turn.end');
    Route::post('/join/{token}/token-moved', [GameInvitationController::class, 'guestNotifyTokenMoved'])
        ->name('api.join.token.moved');
    Route::post('/join/{token}/property/purchase', [GameInvitationController::class, 'guestPurchaseProperty'])
        ->name('api.join.property.purchase');
    Route::post('/join/{token}/property/pay-rent', [GameInvitationController::class, 'guestPayRent'])
        ->name('api.join.property.pay-rent');
    Route::get('/join/{token}/properties/player', [GameInvitationController::class, 'guestGetPlayerProperties'])
        ->name('api.join.properties.player');
    Route::post('/join/{token}/property/mortgage', [GameInvitationController::class, 'guestMortgageProperty'])
        ->name('api.join.property.mortgage');
    Route::post('/join/{token}/property/unmortgage', [GameInvitationController::class, 'guestUnmortgageProperty'])
        ->name('api.join.property.unmortgage');
    Route::post('/join/{token}/card/accept', [GameInvitationController::class, 'guestAcceptCard'])
        ->name('api.join.card.accept');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/player-icons', [PlayerIconController::class, 'index'])->name('api.player-icons.index');
    Route::post('/games', [GameController::class, 'store'])->name('api.games.store');
    Route::post('/games/{gameId}/invitations', [GameInvitationController::class, 'store'])->name('api.games.invitations.store');
    Route::post('/games/{gameId}/chance/draw', [GameController::class, 'drawChanceCard'])->name('api.games.chance.draw');
    Route::post('/games/{gameId}/community/draw', [GameController::class, 'drawCommunityChestCard'])->name('api.games.community.draw');
    Route::post('/games/{gameId}/roll', [GameController::class, 'rollDice'])->name('api.games.roll');
    Route::post('/games/{gameId}/debug/move', [GameController::class, 'debugMoveToSquare'])->name('api.games.debug.move');
    Route::post('/games/{gameId}/turn/end', [GameController::class, 'endTurn'])->name('api.games.turn.end');
    Route::post('/games/{gameId}/token-moved', [GameController::class, 'notifyTokenMoved'])->name('api.games.token.moved');
    Route::post('/games/{gameId}/property/purchase', [GameController::class, 'purchaseProperty'])->name('api.games.property.purchase');
    Route::post('/games/{gameId}/property/pay-rent', [GameController::class, 'payRent'])->name('api.games.property.pay-rent');
    Route::get('/games/{gameId}/properties/player', [GameController::class, 'getPlayerProperties'])->name('api.games.properties.player');
    Route::post('/games/{gameId}/property/mortgage', [GameController::class, 'mortgageProperty'])->name('api.games.property.mortgage');
    Route::post('/games/{gameId}/property/unmortgage', [GameController::class, 'unmortgageProperty'])->name('api.games.property.unmortgage');
    Route::post('/games/{gameId}/card/accept', [GameController::class, 'acceptCard'])->name('api.games.card.accept');
});
