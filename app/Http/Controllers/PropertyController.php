<?php

namespace App\Http\Controllers;

use App\Events\PropertyBuilt;
use App\Repositories\GameRepository;
use App\Repositories\PlayerIconRepository;
use App\Repositories\GamePropertyRepository;
use App\Repositories\GamePendingBuildRepository;
use App\Services\GameService;
use App\Services\BuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PropertyController extends Controller
{
    public function __construct(
        private readonly GameService $gameService,
        private readonly GameRepository $gameRepository,
        private readonly BuildService $buildService,
        private readonly PlayerIconRepository $playerIconRepository,
        private readonly GamePropertyRepository $gamePropertyRepository,
        private readonly GamePendingBuildRepository $pendingBuildRepository,
    ) {}

    /**
     * Purchase a property for the authenticated player.
     *
     * @param Request $request
     * @param int $gameId
        * @return JsonResponse
        *
        * Logic: Validates input, verifies game and participant membership,
        * delegates purchase processing to GameService::purchasePropertyForUser,
        * and returns the updated player payload. Logs and returns appropriate
        * HTTP errors on failure.
     */
    public function purchaseProperty(Request $request, int $gameId): JsonResponse
    {
        // Copied from GameController::purchaseProperty
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->purchasePropertyForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('square_index'),
                (array) $request->input('mortgage_square_indices', []),
            );

            return response()->json(['player' => $result]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to purchase property', [
                'game_id'      => $gameId,
                'user_id'      => $request->user()?->id,
                'square_index' => $request->input('square_index'),
                'exception'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to purchase property.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Build houses or hotels on a property.
     *
     * @param Request $request
     * @param int $gameId
        * @return JsonResponse
        *
        * Logic: Validates build payload, derives default unit price when
        * unspecified, resolves caller join_order, delegates to BuildService to
        * queue or perform the build, broadcasts a PropertyBuilt event with
        * updated bank availability, and returns the build result.
     */
    public function buildProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'action' => ['required', 'in:house,hotel'],
            'price_per_unit' => ['sometimes', 'integer', 'min:0'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $userId = $request->user()->id;
            $sq = (int) $request->input('square_index');
            $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
            if ($joinOrder === null) {
                throw new \InvalidArgumentException('You are not a participant of this game.');
            }

            $action = $request->input('action');
            $price = $request->filled('price_per_unit') ? (int) $request->input('price_per_unit') : 0;

            if ($price === 0) {
                $row = DB::table('game_properties')
                    ->where('game_id', $gameId)
                    ->where('square_index', $sq)
                    ->select(['purchase_price'])
                    ->first();

                if ($row !== null) {
                    $price = (int) intdiv((int) $row->purchase_price, 2);
                }
            }

            if ($action === 'house') {
                $result = $this->buildService->buildHouse($gameId, $joinOrder, $sq, $price);
            } else {
                $result = $this->buildService->buildHotel($gameId, $joinOrder, $sq, $price);
            }

            if (isset($joinOrder)) {
                $usedHouses = $this->gamePropertyRepository->countTotalHouses($gameId);
                $usedHotels = $this->gamePropertyRepository->countTotalHotels($gameId);
                $pendingHouses = $this->pendingBuildRepository->countPendingHouses($gameId);
                $pendingHotels = $this->pendingBuildRepository->countPendingHotels($gameId);

                $totalBankHouses = config('monopoly.bank.houses');
                $totalBankHotels = config('monopoly.bank.hotels');

                $housesAvailable = max(0, $totalBankHouses - ($usedHouses + $pendingHouses));
                $hotelsAvailable = max(0, $totalBankHotels - ($usedHotels + $pendingHotels));

                event(new PropertyBuilt(
                    $gameId,
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
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to build property', [
                'game_id' => $gameId,
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to build property.', 'errors' => []], 500);
        }
    }

    /**
     * Sell houses or hotels on a property back to the bank.
     *
     * @param Request $request
     * @param int $gameId
        * @return JsonResponse
        *
        * Logic: Validates request, ensures caller is the active player, delegates
        * sale logic to BuildService, computes updated building counts, broadcasts
        * a PropertyBuilt event, and returns the sale result including new capital.
     */
    public function sellProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'action' => ['required', 'in:house,hotel'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $userId = $request->user()->id;
            $sq = (int) $request->input('square_index');
            $action = $request->input('action');

            $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
            if ($joinOrder === null) {
                throw new \InvalidArgumentException('You are not a participant of this game.');
            }

            if ((int) ($game->current_turn_join_order ?? 0) !== (int) $joinOrder) {
                throw new \InvalidArgumentException('It is not your turn.');
            }

            if ($action === 'house') {
                $result = $this->buildService->sellHouse($gameId, $joinOrder, $sq);
            } else {
                $result = $this->buildService->sellHotel($gameId, $joinOrder, $sq);
            }

            $row = DB::table('game_properties')
                ->where('game_id', $gameId)
                ->where('square_index', $sq)
                ->select(['houses_count', 'has_hotel'])
                ->first();

            $housesCount = $row?->houses_count ?? null;
            $hasHotel = isset($row->has_hotel) ? (bool) $row->has_hotel : null;

            if (isset($joinOrder)) {
                $usedHouses = $this->gamePropertyRepository->countTotalHouses($gameId);
                $usedHotels = $this->gamePropertyRepository->countTotalHotels($gameId);
                $pendingHouses = $this->pendingBuildRepository->countPendingHouses($gameId);
                $pendingHotels = $this->pendingBuildRepository->countPendingHotels($gameId);

                $totalBankHouses = config('monopoly.bank.houses');
                $totalBankHotels = config('monopoly.bank.hotels');

                $housesAvailable = max(0, $totalBankHouses - ($usedHouses + $pendingHouses));
                $hotelsAvailable = max(0, $totalBankHotels - ($usedHotels + $pendingHotels));

                event(new PropertyBuilt(
                    $gameId,
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
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to sell property', [
                'game_id' => $gameId,
                'user_id' => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to sell property.', 'errors' => []], 500);
        }
    }

    /**
     * Return the authenticated player's owned properties for mortgage actions.
     *
     * @param Request $request
     * @param int $gameId
        * @return JsonResponse
        *
        * Logic: Delegates to GameService to fetch the player's properties and
        * computes current bank availability to assist the frontend mortgage UI.
     */
    public function getPlayerProperties(Request $request, int $gameId): JsonResponse
    {
        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $properties = $this->gameService->getPlayerPropertiesForUser($gameId, $request->user()->id);

            $placedHouses = $this->gamePropertyRepository->countTotalHouses($gameId);
            $placedHotels = $this->gamePropertyRepository->countTotalHotels($gameId);

            $pendingHouses = $this->pendingBuildRepository->countPendingHouses($gameId);
            $pendingHotels = $this->pendingBuildRepository->countPendingHotels($gameId);

            $totalBankHouses = config('monopoly.bank.houses');
            $totalBankHotels = config('monopoly.bank.hotels');

            $housesAvailable = max(0, $totalBankHouses - ($placedHouses + $pendingHouses));
            $hotelsAvailable = max(0, $totalBankHotels - ($placedHotels + $pendingHotels));

            return response()->json([
                'properties' => $properties,
                'houses_available' => $housesAvailable,
                'hotels_available' => $hotelsAvailable,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to load player properties', [
                'game_id'   => $gameId,
                'user_id'   => $request->user()?->id,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to load player properties.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Mortgage one of the authenticated player's properties.
     *
     * @param Request $request
     * @param int $gameId
        * @return JsonResponse
        *
        * Logic: Validates input and delegates to GameService::mortgagePropertyForUser
        * to perform the domain mutation and return the updated player payload.
     */
    public function mortgageProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate(['square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $player = $this->gameService->mortgagePropertyForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('square_index'),
            );

            return response()->json(['player' => $player]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to mortgage property', [
                'game_id'      => $gameId,
                'user_id'      => $request->user()?->id,
                'square_index' => $request->input('square_index'),
                'exception'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to mortgage property.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Unmortgage one of the authenticated player's properties.
     *
     * @param Request $request
     * @param int $gameId
        * @return JsonResponse
        *
        * Logic: Validates input and delegates to GameService::unmortgagePropertyForUser
        * to perform the domain mutation and return the updated player payload.
     */
    public function unmortgageProperty(Request $request, int $gameId): JsonResponse
    {
        $request->validate(['square_index' => ['required', 'integer', 'min:0', 'max:39']]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $player = $this->gameService->unmortgagePropertyForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('square_index'),
            );

            return response()->json(['player' => $player]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to unmortgage property', [
                'game_id'      => $gameId,
                'user_id'      => $request->user()?->id,
                'square_index' => $request->input('square_index'),
                'exception'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to unmortgage property.',
                'errors'  => [],
            ], 500);
        }
    }

    /**
     * Pay rent for the authenticated player landing on an owned property.
     *
     * @param Request $request
     * @param int $gameId
        * @return JsonResponse
        *
        * Logic: Validates input, delegates rent processing to GameService::payRentForUser,
        * and returns both payer and owner updated capitals for UI refresh.
     */
    public function payRent(Request $request, int $gameId): JsonResponse
    {
        $request->validate([
            'square_index' => ['required', 'integer', 'min:0', 'max:39'],
            'mortgage_square_indices' => ['sometimes', 'array'],
            'mortgage_square_indices.*' => ['integer', 'min:0', 'max:39', 'distinct'],
        ]);

        try {
            $game = $this->gameRepository->findById($gameId);

            if ($game === null) {
                return response()->json(['message' => 'Game not found.', 'errors' => []], 404);
            }

            $result = $this->gameService->payRentForUser(
                $gameId,
                $request->user()->id,
                (int) $request->input('square_index'),
                (array) $request->input('mortgage_square_indices', []),
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => []], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to pay rent', [
                'game_id'      => $gameId,
                'user_id'      => $request->user()?->id,
                'square_index' => $request->input('square_index'),
                'exception'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to pay rent.',
                'errors'  => [],
            ], 500);
        }
    }
}
