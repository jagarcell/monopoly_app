<?php

namespace App\Http\Controllers;

use App\Events\PropertyBuilt;
use App\Repositories\PlayerIconRepository;
use App\Repositories\GameRepository;
use App\Repositories\GamePropertyRepository;
use App\Repositories\GamePendingBuildRepository;
use App\Services\BuildService;
use App\Services\GameInvitationService;
use App\Services\GameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GuestInvitationController extends Controller
{
    public function __construct(
        private readonly GameInvitationService $invitationService,
        private readonly GameService $gameService,
        private readonly PlayerIconRepository $playerIconRepository,
        private readonly BuildService $buildService,
        private readonly GameRepository $gameRepository,
        private readonly GamePropertyRepository $gamePropertyRepository,
        private readonly GamePendingBuildRepository $pendingBuildRepository,
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

    public function drawGuestCommunityChestCard(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            try {
                $result = $this->gameService->drawCommunityChestCardForGuest($invitation->game_id, $invitation->id);
                return response()->json($result);
            } catch (InvalidArgumentException $e) {
                if ($e->getMessage() === 'You are not a participant of this game.') {
                    $result = $this->gameService->drawCommunityChestCard($invitation->game_id);
                    return response()->json(['card' => $result, 'effect' => null]);
                }

                throw $e;
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to draw guest community chest card', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to draw card.', 'errors' => []], 500);
        }
    }

    public function guestListChanceDeck(string $token): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $deck = $this->gameService->listChanceDeckForGame($invitation->game_id);
            return response()->json(['cards' => $deck]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to list guest chance deck', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to list cards.', 'errors' => []], 500);
        }
    }

    public function guestEmulateChanceCard(Request $request, string $token): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        $request->validate(['card_id' => ['required', 'integer', 'min:1']]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result = $this->gameService->emulateChanceCardForUser($invitation->game_id, $invitation->id, (int) $request->input('card_id'));
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to emulate guest chance card', ['token' => $token, 'card_id' => $request->input('card_id'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to emulate card.', 'errors' => []], 500);
        }
    }

    public function guestListCommunityDeck(string $token): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $deck = $this->gameService->listCommunityDeckForGame($invitation->game_id);
            return response()->json(['cards' => $deck]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to list guest community deck', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to list cards.', 'errors' => []], 500);
        }
    }

    public function guestEmulateCommunityCard(Request $request, string $token): JsonResponse
    {
        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        $request->validate(['card_id' => ['required', 'integer', 'min:1']]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result = $this->gameService->emulateCommunityCardForUser($invitation->game_id, $invitation->id, (int) $request->input('card_id'));
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to emulate guest community card', ['token' => $token, 'card_id' => $request->input('card_id'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to emulate card.', 'errors' => []], 500);
        }
    }

    /**
     * Guest declares bankruptcy via invitation token.
     *
     * Logic: Validates optional `owner_join_order` (creditor when bankruptcy
     * resulted from a rent). Delegates to GameService::declareBankruptcyForGuest
     * and returns the result payload.
     *
     * @param Request $request
     * @param string $token
     * @return JsonResponse
     */
    public function guestDeclareBankruptcy(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'owner_join_order' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);

            $ownerJoinOrder = $request->filled('owner_join_order') ? (int) $request->input('owner_join_order') : null;

            $result = $this->gameService->declareBankruptcyForGuest(
                $invitation->game_id,
                $invitation->id,
                $ownerJoinOrder,
            );

            return response()->json(['result' => $result]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to declare bankruptcy for guest', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to declare bankruptcy.', 'errors' => []], 500);
        }
    }

    public function guestRollDice(Request $request, string $token): JsonResponse
    {
        try {
            $validated = $request->validate([
                'forced_die1' => ['nullable', 'integer', 'min:1', 'max:6', 'required_with:forced_die2'],
                'forced_die2' => ['nullable', 'integer', 'min:1', 'max:6', 'required_with:forced_die1'],
            ]);

            $hasForcedDice = array_key_exists('forced_die1', $validated) || array_key_exists('forced_die2', $validated);

            if ($hasForcedDice && !(bool) config('app.debug_mode')) {
                return response()->json(['message' => 'Forced dice are only allowed in debug mode.', 'errors' => []], 403);
            }

            $forcedDice = $hasForcedDice ? ['die1' => (int) $validated['forced_die1'], 'die2' => (int) $validated['forced_die2']] : null;

            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result = $this->gameService->rollDiceForGuest($invitation->game_id, $invitation->id, $forcedDice);

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to roll dice for guest', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to roll dice.', 'errors' => []], 500);
        }
    }

    public function guestDebugMoveToSquare(Request $request, string $token): JsonResponse
    {
        $request->validate(['target_square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        if (!(bool) config('app.debug_mode')) {
            return response()->json(['message' => 'Debug mode is disabled.', 'errors' => []], 403);
        }

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result = $this->gameService->debugMoveToSquareForGuest($invitation->game_id, $invitation->id, (int) $request->input('target_square_index'));
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed debug move to square for guest', ['token' => $token, 'target_square_index' => $request->input('target_square_index'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to move token.', 'errors' => []], 500);
        }
    }

    public function guestEndTurn(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result = $this->gameService->endTurnForGuest($invitation->game_id, $invitation->id);
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to end turn for guest', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to end turn.', 'errors' => []], 500);
        }
    }

    public function guestNotifyTokenMoved(Request $request, string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $backward = $request->boolean('backward', false);
            $jailAnimationSource = $request->input('jail_animation_source');
            if (!in_array($jailAnimationSource, ['square', 'card'], true)) {
                $jailAnimationSource = null;
            }

            $result = $this->gameService->notifyTokenMovedForGuest($invitation->game_id, $invitation->id, $backward, $jailAnimationSource);
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to notify guest token moved', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to notify token movement.', 'errors' => []], 500);
        }
    }

    public function guestPurchaseProperty(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result = $this->gameService->purchasePropertyForGuest($invitation->game_id, $invitation->id, (int) $request->input('square_index'), (array) $request->input('mortgage_square_indices', []));
            return response()->json(['player' => $result]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to purchase property for guest', ['token' => $token, 'square_index' => $request->input('square_index'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to purchase property.', 'errors' => []], 500);
        }
    }

    public function guestPayTax(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'choice' => ['required', 'string', 'in:flat,percent'],
            'amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result = $this->gameService->applyTaxChoiceForGuest(
                $invitation->game_id,
                $invitation->id,
                (int) $request->input('square_index'),
                (string) $request->input('choice'),
                $request->filled('amount') ? (int) $request->input('amount') : null,
                $request->filled('percent') ? (int) $request->input('percent') : null,
            );

            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to apply guest tax payment', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to apply tax payment.', 'errors' => []], 500);
        }
    }

    public function guestBuildProperty(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'action' => ['required', 'in:house,hotel'],
            'price_per_unit' => ['sometimes', 'integer', 'min:0'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);

            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                throw new \InvalidArgumentException('You are not a participant of this game.');
            }

            $game = $this->gameRepository->findById($invitation->game_id);
            if ($game === null) {
                throw new \InvalidArgumentException('Game not found.');
            }

            $sq = (int) $request->input('square_index');
            $action = $request->input('action');
            $price = $request->filled('price_per_unit') ? (int) $request->input('price_per_unit') : 0;

            if ($price === 0) {
                $row = DB::table('game_properties')
                    ->where('game_id', $invitation->game_id)
                    ->where('square_index', $sq)
                    ->select(['purchase_price'])
                    ->first();

                if ($row !== null) {
                    $price = (int) intdiv((int) $row->purchase_price, 2);
                }
            }

            if ($action === 'house') {
                $result = $this->buildService->buildHouse($invitation->game_id, $joinOrder, $sq, $price);
            } else {
                $result = $this->buildService->buildHotel($invitation->game_id, $joinOrder, $sq, $price);
            }

            if (isset($joinOrder)) {
                $avail = $this->gameService->computeBankAvailability($invitation->game_id);
                $housesAvailable = $avail['houses_available'];
                $hotelsAvailable = $avail['hotels_available'];

                event(new PropertyBuilt(
                    $invitation->game_id,
                    $joinOrder,
                    $sq,
                    null,
                    null,
                    $result['new_capital'] ?? null,
                    $housesAvailable,
                    $hotelsAvailable,
                ));
            }

            return response()->json(['result' => $result]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to build property for guest', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to build property.', 'errors' => []], 500);
        }
    }

    public function guestSellProperty(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'action' => ['required', 'in:house,hotel'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);

            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                throw new \InvalidArgumentException('You are not a participant of this game.');
            }

            $game = $this->gameRepository->findById($invitation->game_id);
            if ($game === null) {
                throw new \InvalidArgumentException('Game not found.');
            }

            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }

            $sq = (int) $request->input('square_index');
            $action = $request->input('action');

            if ($action === 'house') {
                $result = $this->buildService->sellHouse($invitation->game_id, $joinOrder, $sq);
            } else {
                $result = $this->buildService->sellHotel($invitation->game_id, $joinOrder, $sq);
            }

            $row = DB::table('game_properties')
                ->where('game_id', $invitation->game_id)
                ->where('square_index', $sq)
                ->select(['houses_count', 'has_hotel'])
                ->first();

            $housesCount = $row?->houses_count ?? null;
            $hasHotel = isset($row->has_hotel) ? (bool) $row->has_hotel : null;

            if (isset($joinOrder)) {
                $avail = $this->gameService->computeBankAvailability($invitation->game_id);
                $housesAvailable = $avail['houses_available'];
                $hotelsAvailable = $avail['hotels_available'];

                event(new PropertyBuilt(
                    $invitation->game_id,
                    $joinOrder,
                    $sq,
                    $housesCount,
                    $hasHotel,
                    $result['new_capital'] ?? null,
                    $housesAvailable,
                    $hotelsAvailable,
                ));
            }

            return response()->json(['result' => $result]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to sell property for guest', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to sell property.', 'errors' => []], 500);
        }
    }

    public function guestGetPlayerProperties(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $properties = $this->gameService->getPlayerPropertiesForGuest($invitation->game_id, $invitation->id);

            $avail = $this->gameService->computeBankAvailability($invitation->game_id);
            $housesAvailable = $avail['houses_available'];
            $hotelsAvailable = $avail['hotels_available'];

            return response()->json([
                'properties' => $properties,
                'houses_available' => $housesAvailable,
                'hotels_available' => $hotelsAvailable,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to load guest player properties', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to load player properties.', 'errors' => []], 500);
        }
    }

    public function guestGetPlayerAssets(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($invitation->game_id, $invitation->id);
            if ($joinOrder === null) {
                throw new InvalidArgumentException('You are not a participant of this game.');
            }

            $breakdown = $this->gameService->getPlayerAssetsBreakdown($invitation->game_id, $joinOrder, 10);

            return response()->json(['assets' => $breakdown]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to load guest player assets', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to load player assets.', 'errors' => []], 500);
        }
    }

    public function guestMortgageProperty(Request $request, string $token): JsonResponse
    {
        $request->validate(['square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $player = $this->gameService->mortgagePropertyForGuest($invitation->game_id, $invitation->id, (int) $request->input('square_index'));
            return response()->json(['player' => $player]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to mortgage property for guest', ['token' => $token, 'square_index' => $request->input('square_index'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to mortgage property.', 'errors' => []], 500);
        }
    }

    public function guestUnmortgageProperty(Request $request, string $token): JsonResponse
    {
        $request->validate(['square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $player = $this->gameService->unmortgagePropertyForGuest($invitation->game_id, $invitation->id, (int) $request->input('square_index'));
            return response()->json(['player' => $player]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to unmortgage property for guest', ['token' => $token, 'square_index' => $request->input('square_index'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to unmortgage property.', 'errors' => []], 500);
        }
    }

    public function guestUseGetOutOfJailCard(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $jailRelease = $this->gameService->useGetOutOfJailCardForGuest($invitation->game_id, $invitation->id);
            return response()->json(['jail_release' => $jailRelease]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to use get out of jail card for guest', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to use get out of jail card.', 'errors' => []], 500);
        }
    }

    public function guestPayJailRelease(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $jailRelease = $this->gameService->payJailReleaseForGuest($invitation->game_id, $invitation->id);
            return response()->json(['jail_release' => $jailRelease]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to pay jail release for guest', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to pay jail release.', 'errors' => []], 500);
        }
    }

    public function guestPayRent(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
        ]);

                request()->validate([
                    'mortgage_square_indices' => ['sometimes', 'array'],
                    'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
                ]);

        try {
                $jailRelease = $this->gameService->payJailReleaseForGuest(
                    $invitation->game_id,
                    $invitation->id,
                    (array) request()->input('mortgage_square_indices', []),
                );
            $result = $this->gameService->payRentForGuest($invitation->game_id, $invitation->id, (int) $request->input('square_index'), (array) $request->input('mortgage_square_indices', []));
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to pay rent for guest', ['token' => $token, 'square_index' => $request->input('square_index'), 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to pay rent.', 'errors' => []], 500);
        }
    }

    public function guestAcceptCard(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
            'card_payment_type' => ['sometimes', 'nullable', 'in:pay,pay_each_player'],
            'card_payment_amount' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        try {
            $invitation = $this->invitationService->findAcceptedInvitation($token);
            $result = $this->gameService->acceptCardForGuest($invitation->game_id, $invitation->id, (array) $request->input('mortgage_square_indices', []), $request->input('card_payment_type'), $request->filled('card_payment_amount') ? (int) $request->input('card_payment_amount') : null);
            return response()->json($result);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to accept card for guest', ['token' => $token, 'exception' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to accept card.', 'errors' => []], 500);
        }
    }
}
