<?php

use App\Http\Controllers\GameController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/games', [GameController::class, 'store'])->name('api.games.store');
    Route::post('/games/{gameId}/chance/draw', [GameController::class, 'drawChanceCard'])->name('api.games.chance.draw');
    Route::post('/games/{gameId}/community/draw', [GameController::class, 'drawCommunityChestCard'])->name('api.games.community.draw');
});
