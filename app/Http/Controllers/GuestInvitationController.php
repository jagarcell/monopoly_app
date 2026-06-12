<?php

namespace App\Http\Controllers;

use App\Services\GameInvitationService;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class GuestInvitationController extends Controller
{
    public function __construct(
        private readonly GameInvitationService $invitationService,
        private readonly GameService $gameService,
    ) {}

    public function show(string $token): JsonResponse
    {
        return response()->json(['message' => 'Use GameInvitationController@show for web join page.'], 400);
    }

    public function game(string $token): JsonResponse
    {
        return response()->json(['message' => 'Use GameInvitationController@game for web guest board.'], 400);
    }

    public function drawGuestChanceCard(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            try {
                $result = $this->gameService->drawChanceCardForGuest($invitation->game_id, $invitation->id);
                return response()->json($result);
            } catch (InvalidArgumentException $e) {
                if ($e->getMessage() === 'You are not a participant of this game.') {
                    $result = $this->gameService->drawChanceCard($invitation->game_id);
                    return response()->json(['card' => $result, 'effect' => null]);
                }

                throw $e;
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to draw guest chance card', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to draw card.', 'errors' => []], 500);
        }
    }
}
