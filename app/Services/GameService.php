<?php

namespace App\Services;

use App\Events\CardAccepted;
use App\Events\CardDrawn;
use App\Events\DiceRolled;
use App\Events\MortgagedPropertyNotified;
use App\Events\PropertyPurchased;
use App\Events\RentPaid;
use App\Events\TokenMoved;
use App\Events\TurnAdvanced;
use App\Models\Game;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GameInvitationRepository;
use App\Repositories\GamePropertyRepository;
use App\Repositories\GameRepository;
use App\Repositories\PlayerIconRepository;
use App\Repositories\GamePendingBuildRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Events\BuildAllocationFailed;
use InvalidArgumentException;

class GameService
{
    public function __construct(
        private readonly GameRepository $gameRepository,
        private readonly ChanceCardRepository $chanceCardRepository,
        private readonly CommunityChestCardRepository $communityChestCardRepository,
        private readonly PlayerIconRepository $playerIconRepository,
        private readonly GameInvitationRepository $invitationRepository,
        private readonly GamePropertyRepository $propertyRepository,
        private readonly GamePendingBuildRepository $pendingBuildRepository,
    ) {}

    /**
     * Compute bank availability for houses and hotels for a game.
     *
     * @param int $gameId
     * @return array{houses_available:int,hotels_available:int}
     */
    public function computeBankAvailability(int $gameId): array
    {
        $usedHouses = $this->propertyRepository->countTotalHouses($gameId);
        $usedHotels = $this->propertyRepository->countTotalHotels($gameId);
        $pendingHouses = $this->pendingBuildRepository->countPendingHouses($gameId);
        $pendingHotels = $this->pendingBuildRepository->countPendingHotels($gameId);

        $totalBankHouses = config('monopoly.bank.houses');
        $totalBankHotels = config('monopoly.bank.hotels');

        return [
            'houses_available' => max(0, $totalBankHouses - ($usedHouses + $pendingHouses)),
            'hotels_available' => max(0, $totalBankHotels - ($usedHotels + $pendingHotels)),
        ];
    }

    /**
     * Create a new game owned by the given user.
     *
     * Logic: Counts the user's existing games to derive the next sequential
     * number (e.g. "Game #1", "Game #2"), delegates the actual database insert
     * to the game repository, creates a freshly shuffled Chance deck and Community
     * Chest deck for the new game, then assigns the creator's chosen player icon
     * to the game via the game_player_icons pivot table.
     *
     * @param  int  $userId        The authenticated user's ID.
     * @param  int  $maxPlayers    The maximum number of players for the game (2–8).
     * @param  int  $playerIconId  The ID of the PlayerIcon the creator selected.
     * @return Game
     */
    public function createGame(int $userId, int $maxPlayers, int $playerIconId): Game
    {
        $count = $this->gameRepository->countByUser($userId);
        $name  = 'Game #' . ($count + 1);

        $game = $this->gameRepository->create($userId, $name, $maxPlayers);

        $this->chanceCardRepository->createDeckForGame($game->id);
        $this->communityChestCardRepository->createDeckForGame($game->id);
        $this->playerIconRepository->assignToGame($game->id, $userId, $playerIconId);

        return $game;
    }

    /**
     * Return all players for a game ordered by join_order.
     *
     * Logic: Delegates to PlayerIconRepository::getPlayersForGame which performs
     * a single SQL query joining game_player_icons, player_icons, users, and
     * game_invitations. Returns the result unchanged so callers receive a
     * consistent player array ready for JSON serialisation.
     *
     * @param  int  $gameId  The ID of the game whose player list is requested.
     * @return array<int, array<string, mixed>>
     */
    public function getPlayersForGame(int $gameId): array
    {
        return $this->playerIconRepository->getPlayersForGame($gameId);
    }

    /**
     * Draw the next Chance card for the given game.
     *
        * Logic: Delegates to ChanceCardRepository::drawTopCard, which draws the
        * lowest sort_order card that is not currently held by a player. Held
        * get-out-of-jail-free cards remain outside the active deck until used,
        * after which they are returned to the bottom.
     *
     * @param  int  $gameId  The ID of the game.
     * @return array<string, mixed>
     */
    public function drawChanceCard(int $gameId): array
    {
        return $this->chanceCardRepository->drawTopCard($gameId);
    }

    /**
     * Draw the next Community Chest card for the given game.
     *
        * Logic: Delegates to CommunityChestCardRepository::drawTopCard, which draws
        * the lowest sort_order card that is not currently held by a player. Held
        * get-out-of-jail-free cards remain outside the active deck until used,
        * after which they are returned to the bottom.
     *
     * @param  int  $gameId  The ID of the game.
     * @return array<string, mixed>
     */
    public function drawCommunityChestCard(int $gameId): array
    {
        return $this->communityChestCardRepository->drawTopCard($gameId);
    }

    /**
     * Return the ordered Chance deck for debugging purposes.
     *
     * Debug-only: returns the full deck sequence including sort_order so the
     * frontend can show available cards for emulate selection.
     *
     * @param int $gameId
     * @return array<int, array<string,mixed>>
     */
    public function listChanceDeckForGame(int $gameId): array
    {
        return $this->chanceCardRepository->getDeckForGame($gameId)->map(function ($c) {
            return [
                'id' => $c->id,
                'action' => $c->action->value,
                'text' => $c->text,
                'amount' => $c->amount,
                'house_cost' => $c->house_cost,
                'hotel_cost' => $c->hotel_cost,
                'target' => $c->target ?? null,
                'spaces' => $c->spaces ?? null,
                'sort_order' => $c->sort_order,
            ];
        })->all();
    }

    /**
     * Return the ordered Community Chest deck for debugging purposes.
     *
     * @param int $gameId
     * @return array<int, array<string,mixed>>
     */
    public function listCommunityDeckForGame(int $gameId): array
    {
        return $this->communityChestCardRepository->getDeckForGame($gameId)->map(function ($c) {
            return [
                'id' => $c->id,
                'action' => $c->action->value,
                'text' => $c->text,
                'amount' => $c->amount,
                'house_cost' => $c->house_cost,
                'hotel_cost' => $c->hotel_cost,
                'target' => $c->target ?? null,
                'sort_order' => $c->sort_order,
            ];
        })->all();
    }

    /**
     * Return the game's current_turn_join_order or null when the game is missing.
     *
     * @param int $gameId
     * @return int|null
     */
    public function getCurrentTurnJoinOrderForGame(int $gameId): ?int
    {
        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            return null;
        }

        return (int) $game->current_turn_join_order;
    }

    /**
     * Emulate drawing a specific Chance card for a user (debug only).
     *
     * Logic: Validates debug mode, caller participation, and turn ownership,
     * applies the card effect via applyCardEffect, persists held card if needed,
     * moves the selected card to the bottom of the deck, dispatches CardDrawn,
     * and returns the card + computed effect.
     *
     * @param int $gameId
     * @param int $userId
     * @param int $cardId
     * @return array{card: array, effect: array}
     */
    public function emulateChanceCardForUser(int $gameId, int $userId, int $cardId): array
    {
        if (!(bool) config('app.debug_mode')) {
            throw new InvalidArgumentException('Debug mode is disabled.');
        }

        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
        if ($joinOrder === null) {
            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $userId);
        }

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $joinOrder) {
            throw new InvalidArgumentException('It is not your turn to draw a card.');
        }

        $cardModel = \App\Models\ChanceCard::find($cardId);

        if ($cardModel === null) {
            throw new InvalidArgumentException('Card not found.');
        }

        $card = [
            'id' => $cardModel->id,
            'action' => $cardModel->action->value,
            'text' => $cardModel->text,
            'amount' => $cardModel->amount,
            'house_cost' => $cardModel->house_cost,
            'hotel_cost' => $cardModel->hotel_cost,
            'target' => $cardModel->target,
            'spaces' => $cardModel->spaces,
        ];

        $cardSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);
        $cardEffect = $this->applyCardEffect($gameId, $joinOrder, $card, $cardSquareIndex);
        $this->persistHeldCardIfNeeded($gameId, $joinOrder, $card, 'chance');
        $this->chanceCardRepository->moveCardToBottom($gameId, $cardId);

        $playerName = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);
        CardDrawn::dispatch($gameId, 'chance', $card, $joinOrder, $playerName, $cardEffect);

        return ['card' => $card, 'effect' => $cardEffect];
    }

    /**
     * Emulate drawing a specific Community Chest card for a user (debug only).
     *
     * @param int $gameId
     * @param int $userId
     * @param int $cardId
     * @return array{card: array, effect: array}
     */
    public function emulateCommunityCardForUser(int $gameId, int $userId, int $cardId): array
    {
        if (!(bool) config('app.debug_mode')) {
            throw new InvalidArgumentException('Debug mode is disabled.');
        }

        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
        if ($joinOrder === null) {
            $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $userId);
        }

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $joinOrder) {
            throw new InvalidArgumentException('It is not your turn to draw a card.');
        }

        $cardModel = \App\Models\CommunityChestCard::find($cardId);

        if ($cardModel === null) {
            throw new InvalidArgumentException('Card not found.');
        }

        $card = [
            'id' => $cardModel->id,
            'action' => $cardModel->action->value,
            'text' => $cardModel->text,
            'amount' => $cardModel->amount,
            'house_cost' => $cardModel->house_cost,
            'hotel_cost' => $cardModel->hotel_cost,
            'target' => $cardModel->target,
        ];

        $cardSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);
        $cardEffect = $this->applyCardEffect($gameId, $joinOrder, $card, $cardSquareIndex);
        $this->persistHeldCardIfNeeded($gameId, $joinOrder, $card, 'community');
        $this->communityChestCardRepository->moveCardToBottom($gameId, $cardId);

        $playerName = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);
        CardDrawn::dispatch($gameId, 'community', $card, $joinOrder, $playerName, $cardEffect);

        return ['card' => $card, 'effect' => $cardEffect];
    }

    /**
     * Draw and apply the next Chance card for an authenticated player, only when it's their turn.
     *
     * Logic: Verifies the caller is a participant and that it is currently
     * their turn, draws the top Chance card, applies its effect using
     * applyCardEffect (which may move the player, adjust capital, etc.),
     * persists held cards when appropriate, dispatches CardDrawn with the
     * computed effect, and returns both the card and effect for debugging.
     *
     * @param int $gameId
     * @param int $userId
     * @return array{card: array, effect: array}
     */
    public function drawChanceCardForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $joinOrder) {
            throw new InvalidArgumentException('It is not your turn to draw a card.');
        }

        $card = $this->chanceCardRepository->drawTopCard($gameId);

        $cardSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);
        $cardEffect = $this->applyCardEffect($gameId, $joinOrder, $card, $cardSquareIndex);
        $this->persistHeldCardIfNeeded($gameId, $joinOrder, $card, 'chance');

        $playerName = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);
        CardDrawn::dispatch($gameId, 'chance', $card, $joinOrder, $playerName, $cardEffect);

        return ['card' => $card, 'effect' => $cardEffect];
    }

    /**
     * Draw and apply the next Community Chest card for an authenticated player, only when it's their turn.
     *
     * @param int $gameId
     * @param int $userId
     * @return array{card: array, effect: array}
     */
    public function drawCommunityChestCardForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $joinOrder) {
            throw new InvalidArgumentException('It is not your turn to draw a card.');
        }

        $card = $this->communityChestCardRepository->drawTopCard($gameId);

        $cardSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);
        $cardEffect = $this->applyCardEffect($gameId, $joinOrder, $card, $cardSquareIndex);
        $this->persistHeldCardIfNeeded($gameId, $joinOrder, $card, 'community');

        $playerName = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);
        CardDrawn::dispatch($gameId, 'community', $card, $joinOrder, $playerName, $cardEffect);

        return ['card' => $card, 'effect' => $cardEffect];
    }

    /**
     * Draw and apply the next Chance card for a guest player (invitation), only when it's their turn.
     *
     * @param int $gameId
     * @param int $invitationId
     * @return array{card: array, effect: array}
     */
    public function drawChanceCardForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $joinOrder) {
            throw new InvalidArgumentException('It is not your turn to draw a card.');
        }

        $card = $this->chanceCardRepository->drawTopCard($gameId);

        $cardSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);
        $cardEffect = $this->applyCardEffect($gameId, $joinOrder, $card, $cardSquareIndex);
        $this->persistHeldCardIfNeeded($gameId, $joinOrder, $card, 'chance');

        $playerName = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);
        CardDrawn::dispatch($gameId, 'chance', $card, $joinOrder, $playerName, $cardEffect);

        return ['card' => $card, 'effect' => $cardEffect];
    }

    /**
     * Draw and apply the next Community Chest card for a guest player (invitation), only when it's their turn.
     *
     * @param int $gameId
     * @param int $invitationId
     * @return array{card: array, effect: array}
     */
    public function drawCommunityChestCardForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $joinOrder) {
            throw new InvalidArgumentException('It is not your turn to draw a card.');
        }

        $card = $this->communityChestCardRepository->drawTopCard($gameId);

        $cardSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);
        $cardEffect = $this->applyCardEffect($gameId, $joinOrder, $card, $cardSquareIndex);
        $this->persistHeldCardIfNeeded($gameId, $joinOrder, $card, 'community');

        $playerName = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);
        CardDrawn::dispatch($gameId, 'community', $card, $joinOrder, $playerName, $cardEffect);

        return ['card' => $card, 'effect' => $cardEffect];
    }

    /**
     * Return all pending (not yet accepted, not expired) invitations for a game.
     *
     * Logic: Delegates to GameInvitationRepository::getPendingForGame, which
     * returns a plain array of {email} objects ordered by invitation send order.
     * Used at page-load and broadcast after each player joins so the waiting-room
     * list stays accurate without a page reload.
     *
     * @param  int  $gameId  The ID of the game.
     * @return array<int, array{email: string}>
     */
    public function getPendingInvitationsForGame(int $gameId): array
    {
        return $this->invitationRepository->getPendingForGame($gameId);
    }

    /**
     * Notify all other board observers that an authenticated player's token has finished moving.
     *
     * Logic: Looks up the caller's join_order by user ID. If the user is not a
     * participant, throws InvalidArgumentException. Reads the authoritative
     * square_index for that player directly from the database (the value
     * persisted during the preceding roll) and dispatches the TokenMoved
     * broadcast event so all connected observer boards animate the token to the
    * correct final square. The $backward flag is forwarded from the client so
    * observer boards animate in the correct direction (e.g. for move_back cards).
    * The optional $jailAnimationSource preserves whether jail escort should
    * start at square 30 (landing flow) or immediately (card flow).
     *
     * @param  int   $gameId    The ID of the game.
     * @param  int   $userId    The authenticated user's ID.
    * @param  bool  $backward             Whether the token moved backward (default false).
    * @param  string|null  $jailAnimationSource  Escort timing source ('square' or 'card').
    * @return array{join_order: int, square_index: int, isInJail: bool, is_in_jail: bool, jail_animation_source: string|null, show_police_escort: bool}
     *
     * @throws InvalidArgumentException When the user is not a game participant.
     */
    public function notifyTokenMovedForUser(
        int $gameId,
        int $userId,
        bool $backward = false,
        ?string $jailAnimationSource = null,
    ): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $squareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);
        $isInJail = $this->playerIconRepository->getJailState($gameId, $joinOrder);
        $showPoliceEscort = $this->shouldShowPoliceEscort($squareIndex, $isInJail, $backward, $jailAnimationSource);

        TokenMoved::dispatch(
            gameId: $gameId,
            joinOrder: $joinOrder,
            squareIndex: $squareIndex,
            isInJail: $isInJail,
            backward: $backward,
            jailAnimationSource: $jailAnimationSource,
            showPoliceEscort: $showPoliceEscort,
        );

        return [
            'join_order'   => $joinOrder,
            'square_index' => $squareIndex,
            'isInJail'     => $isInJail,
            'is_in_jail'   => $isInJail,
            'jail_animation_source' => $jailAnimationSource,
            'show_police_escort' => $showPoliceEscort,
        ];
    }

    /**
     * Notify all other board observers that a guest player's token has finished moving.
     *
     * Logic: Looks up the guest's join_order via their invitation_id. If no
     * matching row exists, throws InvalidArgumentException. Reads the authoritative
     * square_index for that player directly from the database (the value
     * persisted during the preceding roll) and dispatches the TokenMoved
     * broadcast event so all connected observer boards animate the token to the
    * correct final square. The $backward flag is forwarded from the client so
    * observer boards animate in the correct direction (e.g. for move_back cards).
    * The optional $jailAnimationSource preserves whether jail escort should
    * start at square 30 (landing flow) or immediately (card flow).
     *
     * @param  int   $gameId        The ID of the game.
     * @param  int   $invitationId  The GameInvitation primary key of the guest.
    * @param  bool  $backward             Whether the token moved backward (default false).
    * @param  string|null  $jailAnimationSource  Escort timing source ('square' or 'card').
    * @return array{join_order: int, square_index: int, isInJail: bool, is_in_jail: bool, jail_animation_source: string|null, show_police_escort: bool}
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function notifyTokenMovedForGuest(
        int $gameId,
        int $invitationId,
        bool $backward = false,
        ?string $jailAnimationSource = null,
    ): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $squareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);
        $isInJail = $this->playerIconRepository->getJailState($gameId, $joinOrder);
        $showPoliceEscort = $this->shouldShowPoliceEscort($squareIndex, $isInJail, $backward, $jailAnimationSource);

        TokenMoved::dispatch(
            gameId: $gameId,
            joinOrder: $joinOrder,
            squareIndex: $squareIndex,
            isInJail: $isInJail,
            backward: $backward,
            jailAnimationSource: $jailAnimationSource,
            showPoliceEscort: $showPoliceEscort,
        );

        return [
            'join_order'   => $joinOrder,
            'square_index' => $squareIndex,
            'isInJail'     => $isInJail,
            'is_in_jail'   => $isInJail,
            'jail_animation_source' => $jailAnimationSource,
            'show_police_escort' => $showPoliceEscort,
        ];
    }

    /**
     * Determine whether the token-moved payload should include a police escort indicator.
     *
     * Logic: Escorts are only shown when the final square is Jail (10), the
     * movement is not backward, and either the player is in jail after moving
     * or the animation source explicitly requests jail escort timing.
     *
     * @param  int  $squareIndex  The player's final square index.
     * @param  bool  $isInJail  Whether the player is in jail after the move.
     * @param  bool  $backward  Whether the move traveled backward.
     * @param  string|null  $jailAnimationSource  Escort timing source ('square' or 'card').
     * @return bool
     */
    private function shouldShowPoliceEscort(
        int $squareIndex,
        bool $isInJail,
        bool $backward,
        ?string $jailAnimationSource,
    ): bool {
        return $squareIndex === 10
            && !$backward
            && ($isInJail || $jailAnimationSource !== null);
    }

    /**
     * Signal that the drawing player has accepted their card.
     *
    * Logic: Looks up the calling user's join_order to confirm they are a
    * participant, optionally resolves a deferred card payment, then dispatches
    * a CardAccepted broadcast event on the game channel so all connected
    * observer boards can auto-close their card-drawn notification. Held cards
    * remain assigned to the player so page refreshes re-hydrate player hands
    * with the same card ownership state.
     *
     * @param  int  $gameId  The ID of the game.
    * @param  int  $userId  The authenticated user's ID.
    * @param  array<int, int>  $mortgageSquareIndexes  Mortgage selections for a deferred payment.
    * @param  string|null  $cardPaymentType  The payment type to resolve, when applicable.
    * @param  int|null  $cardPaymentAmount  The amount per payment unit, when applicable.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the user is not a game participant.
     */
    public function acceptCardForUser(
        int $gameId,
        int $userId,
        array $mortgageSquareIndexes = [],
        ?string $cardPaymentType = null,
        ?int $cardPaymentAmount = null,
    ): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $paymentResult = [];
        if ($cardPaymentType !== null && $cardPaymentAmount !== null) {
            $paymentResult = $this->resolveCardPayment(
                $gameId,
                $joinOrder,
                $cardPaymentType,
                $cardPaymentAmount,
                $mortgageSquareIndexes,
            );
        }

        CardAccepted::dispatch($gameId, $paymentResult);

        return $paymentResult;
    }

    /**
     * Signal that a guest drawing player has accepted their card.
     *
    * Logic: Looks up the guest's join_order via their invitation_id to confirm
    * participation, optionally resolves a deferred card payment, then
    * dispatches a CardAccepted broadcast event so all connected observer
    * boards can auto-close their card-drawn notification. Held cards remain
    * assigned to the guest so page refreshes re-hydrate player hands with the
    * same card ownership state.
     *
     * @param  int  $gameId        The ID of the game.
    * @param  int  $invitationId  The GameInvitation primary key of the guest.
    * @param  array<int, int>  $mortgageSquareIndexes  Mortgage selections for a deferred payment.
    * @param  string|null  $cardPaymentType  The payment type to resolve, when applicable.
    * @param  int|null  $cardPaymentAmount  The amount per payment unit, when applicable.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function acceptCardForGuest(
        int $gameId,
        int $invitationId,
        array $mortgageSquareIndexes = [],
        ?string $cardPaymentType = null,
        ?int $cardPaymentAmount = null,
    ): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $paymentResult = [];
        if ($cardPaymentType !== null && $cardPaymentAmount !== null) {
            $paymentResult = $this->resolveCardPayment(
                $gameId,
                $joinOrder,
                $cardPaymentType,
                $cardPaymentAmount,
                $mortgageSquareIndexes,
            );
        }

        CardAccepted::dispatch($gameId, $paymentResult);

        return $paymentResult;
    }

    /**
     * Use a held get-out-of-jail-free card for an authenticated player.
     *
     * Logic: Resolves the caller join_order, verifies participation, then
     * delegates to useGetOutOfJailCard() which validates jail state, returns
     * the held card to the proper deck bottom, and clears jail state.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $userId  The authenticated user's ID.
     * @return array{join_order: int, square_index: int, capital: int, is_in_jail: bool, jail_turns: int, has_paid_jail_release: bool}
     */
    public function useGetOutOfJailCardForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->useGetOutOfJailCard($gameId, $joinOrder);
    }

    /**
     * Use a held get-out-of-jail-free card for a guest player.
     *
     * Logic: Resolves the guest join_order, verifies participation, then
     * delegates to useGetOutOfJailCard() which validates jail state, returns
     * the held card to the proper deck bottom, and clears jail state.
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The accepted invitation ID of the guest.
     * @return array{join_order: int, square_index: int, capital: int, is_in_jail: bool, jail_turns: int, has_paid_jail_release: bool}
     */
    public function useGetOutOfJailCardForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->useGetOutOfJailCard($gameId, $joinOrder);
    }

    /**
     * Pay the $50 jail-release fee for an authenticated player.
     *
     * Logic: Resolves the caller join_order, verifies participation, then
     * delegates to payJailRelease() which validates jail state, deducts $50,
     * and marks the player as paid-for-release before their next roll.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $userId  The authenticated user's ID.
     * @return array{join_order: int, square_index: int, capital: int, is_in_jail: bool, jail_turns: int, has_paid_jail_release: bool, paid_amount: int}
     */
    public function payJailReleaseForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->payJailRelease($gameId, $joinOrder);
    }

    /**
     * Pay the $50 jail-release fee for a guest player.
     *
     * Logic: Resolves the guest join_order, verifies participation, then
     * delegates to payJailRelease() which validates jail state, deducts $50,
     * and marks the player as paid-for-release before their next roll.
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The accepted invitation ID of the guest.
     * @return array{join_order: int, square_index: int, capital: int, is_in_jail: bool, jail_turns: int, has_paid_jail_release: bool, paid_amount: int}
     */
    public function payJailReleaseForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->payJailRelease($gameId, $joinOrder);
    }

    /**
     * Roll the dice on behalf of an authenticated (creator/joined) player.
     *
     * Logic: Looks up the calling user's join_order in the game. If the user
     * is not a participant, throws InvalidArgumentException. Delegates the core
     * roll logic to rollDice().
     *
    * @param  int  $gameId  The ID of the game.
    * @param  int  $userId  The authenticated user's ID.
    * @param  array{die1:int,die2:int}|null  $forcedDice  Optional debug-only forced dice pair.
     * @return array{die1: int, die2: int, total: int, current_turn_join_order: int}
     *
     * @throws InvalidArgumentException When the user is not a game participant or it is not their turn.
     */
    public function rollDiceForUser(int $gameId, int $userId, ?array $forcedDice = null): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->rollDice($gameId, $joinOrder, $forcedDice);
    }

    /**
     * Roll the dice on behalf of a guest player identified by their invitation.
     *
     * Logic: Looks up the guest's join_order via their invitation_id. If no
     * matching row exists, throws InvalidArgumentException. Delegates the core
     * roll logic to rollDice().
     *
    * @param  int  $gameId        The ID of the game.
    * @param  int  $invitationId  The GameInvitation primary key of the guest.
    * @param  array{die1:int,die2:int}|null  $forcedDice  Optional debug-only forced dice pair.
     * @return array{die1: int, die2: int, total: int, current_turn_join_order: int}
     *
     * @throws InvalidArgumentException When the guest is not a participant or it is not their turn.
     */
    public function rollDiceForGuest(int $gameId, int $invitationId, ?array $forcedDice = null): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->rollDice($gameId, $joinOrder, $forcedDice);
    }

    /**
     * Move an authenticated player's token directly to a target square in debug mode.
     *
     * Logic: Resolves the player's join_order and delegates to moveToSquare(),
     * which validates turn ownership, updates square_index, applies GO bonus,
     * resolves landing actions (rent/cards), and marks the turn phase as done.
     *
     * @param  int  $gameId             The ID of the game.
     * @param  int  $userId             The authenticated user's ID.
     * @param  int  $targetSquareIndex  The destination board square index (0-39).
     * @return array{current_turn_join_order: int, square_index: int, total_steps: int, square_action: array|null, passed_go: bool, go_bonus: int, new_capital: int|null}
     * Logic: Uses a debug-only direct move while preserving all normal landing side effects.
     *
     * @throws InvalidArgumentException When the player is not a participant.
     */
    public function debugMoveToSquareForUser(int $gameId, int $userId, int $targetSquareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->moveToSquare($gameId, $joinOrder, $targetSquareIndex);
    }

    /**
     * Move a guest player's token directly to a target square in debug mode.
     *
     * Logic: Resolves the guest join_order via invitation_id and delegates to
     * moveToSquare(), which preserves the normal post-landing server flow.
     *
     * @param  int  $gameId             The ID of the game.
     * @param  int  $invitationId       The accepted invitation ID of the guest.
     * @param  int  $targetSquareIndex  The destination board square index (0-39).
     * @return array{current_turn_join_order: int, square_index: int, total_steps: int, square_action: array|null, passed_go: bool, go_bonus: int, new_capital: int|null}
     * Logic: Uses a debug-only direct move while preserving all normal landing side effects.
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function debugMoveToSquareForGuest(int $gameId, int $invitationId, int $targetSquareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->moveToSquare($gameId, $joinOrder, $targetSquareIndex);
    }

    /**
     * Advance the turn on behalf of an authenticated (creator/joined) player.
     *
     * Logic: Looks up the calling user's join_order. If the user is not a
     * participant, throws InvalidArgumentException. Delegates to advanceTurnCyclic()
     * which validates it is their turn, computes the next join_order, updates the
     * DB, and dispatches TurnAdvanced.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $userId  The authenticated user's ID.
     * @return array{current_turn_join_order: int}
     *
     * @throws InvalidArgumentException When the user is not a participant or it is not their turn.
     */
    public function endTurnForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->advanceTurnCyclic($gameId, $joinOrder);
    }

    /**
     * Advance the turn on behalf of a guest player identified by their invitation.
     *
     * Logic: Looks up the guest's join_order via their invitation_id. If no
     * matching row exists, throws InvalidArgumentException. Delegates to
     * advanceTurnCyclic() which validates it is their turn, computes the next
     * join_order, updates the DB, and dispatches TurnAdvanced.
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The GameInvitation primary key of the guest.
     * @return array{current_turn_join_order: int}
     *
     * @throws InvalidArgumentException When the guest is not a participant or it is not their turn.
     */
    public function endTurnForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->advanceTurnCyclic($gameId, $joinOrder);
    }

    /**
     * Core dice-roll logic shared by authenticated and guest players.
     *
     * Logic:
     *   1. Loads the game and verifies the caller's join_order matches
     *      current_turn_join_order. Throws if it is not their turn.
    *   2. Generates cryptographically-adequate random integers for die1 and die2.
     *   3. Fetches the rolling player's current square_index, computes the new
     *      position as (current + total) % 40, and persists it via the repository.
    *   4. Dispatches the DiceRolled broadcast event with the roller's join_order
    *      and new square_index so all connected boards animate the token movement.
    *      The turn normally does NOT advance here — the player must click Done
    *      to pass the turn, except when any go-to-jail rule immediately ends
    *      the turn.
     *   5. When the new square_index is a Chance square (7, 22, 36) or Community
     *      Chest square (2, 17, 33), automatically draws the top card from the
     *      appropriate deck, overwrites $squareAction with the card data, and
     *      dispatches the CardDrawn broadcast event so all connected boards can
     *      reveal the drawn card simultaneously.
    *   6. Returns die values, current_turn_join_order, square_index, and
    *      square_action so the local client can start movement animation and
    *      show card reveal immediately.
    *   7. When the landed square_action is rent owed to another player, rent is
    *      charged immediately on the server (before any UI acknowledgement),
    *      RentPaid is broadcast, and square_action is transformed to
    *      rent_paid so the frontend only needs to show the confirmation dialog.
     *
    * @param  int  $gameId           The ID of the game.
    * @param  int  $rollerJoinOrder  The join_order of the player attempting to roll.
    * @param  array{die1:int,die2:int}|null  $forcedDice  Optional debug-only forced dice pair.
    * @return array{die1: int, die2: int, total: int, current_turn_join_order: int, square_index: int, square_action: array|null, can_roll_again: bool}
     *
     * @throws InvalidArgumentException When it is not the caller's turn or the game is not found.
     */
    private function rollDice(int $gameId, int $rollerJoinOrder, ?array $forcedDice = null): array
    {
        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $rollerJoinOrder) {
            throw new InvalidArgumentException('It is not your turn to roll.');
        }

        $currentSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $rollerJoinOrder);
        $consecutiveDoublesCount = (int) ($game->consecutive_doubles_count ?? 0);
        $isInJail = $this->playerIconRepository->getJailState($gameId, $rollerJoinOrder);
        $jailTurns = $this->playerIconRepository->getJailTurns($gameId, $rollerJoinOrder);
        $hasPaidJailRelease = $this->playerIconRepository->hasPaidJailRelease($gameId, $rollerJoinOrder);

        if ($isInJail && !$hasPaidJailRelease && $jailTurns >= 2) {
            throw new InvalidArgumentException('You must pay $50 to leave jail before rolling.');
        }

        [$die1, $die2] = $this->resolveDicePair($forcedDice);
        $total = $die1 + $die2;
        $isDouble = $die1 === $die2;
        $isJailReleaseDouble = $isInJail && !$hasPaidJailRelease && $isDouble;

        if ($isInJail && !$hasPaidJailRelease && !$isDouble) {
            $updatedJailTurns = $this->playerIconRepository->incrementJailTurns($gameId, $rollerJoinOrder);
            $this->gameRepository->saveDiceRoll($gameId, $die1, $die2, 0, 'done');
            DiceRolled::dispatch($gameId, $die1, $die2, $total, $rollerJoinOrder, $currentSquareIndex);

            return [
                'die1'                    => $die1,
                'die2'                    => $die2,
                'total'                   => $total,
                'current_turn_join_order' => $rollerJoinOrder,
                'square_index'            => $currentSquareIndex,
                'square_action'           => null,
                'passed_go'               => false,
                'go_bonus'                => 0,
                'new_capital'             => null,
                'moved'                   => false,
                'is_in_jail'              => true,
                'isInJail'                => true,
                'jail_turns'              => $updatedJailTurns,
                'has_paid_jail_release'   => false,
                'can_roll_again'          => false,
            ];
        }

        if ($isDouble && !$isJailReleaseDouble && $consecutiveDoublesCount >= 2) {
            $this->sendPlayerToJail($gameId, $rollerJoinOrder);
            $this->gameRepository->saveDiceRoll($gameId, $die1, $die2, 0, 'done');
            DiceRolled::dispatch($gameId, $die1, $die2, $total, $rollerJoinOrder, 10);

            $nextJoinOrder = $this->advanceTurnFromJoinOrder($gameId, $rollerJoinOrder);

            return [
                'die1'                    => $die1,
                'die2'                    => $die2,
                'total'                   => $total,
                'current_turn_join_order' => $nextJoinOrder,
                'square_index'            => 10,
                'square_action'           => ['type' => 'go_to_jail', 'new_square_index' => 10],
                'passed_go'               => false,
                'go_bonus'                => 0,
                'new_capital'             => null,
                'moved'                   => true,
                'is_in_jail'              => true,
                'isInJail'                => true,
                'jail_turns'              => 0,
                'has_paid_jail_release'   => false,
                'can_roll_again'          => false,
            ];
        }

        // Advance the player's board position by the dice total, wrapping at 40.
        $newSquareIndex     = ($currentSquareIndex + $total) % 40;

        // A player collects $200 when they pass through or land on GO (square 0),
        // i.e. whenever the raw sum of current position and dice total crosses 40.
        $passedGo   = ($currentSquareIndex + $total) >= 40;
        $newCapital = null;
        if ($passedGo) {
            $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, 200);
        }

        $this->playerIconRepository->setJailState($gameId, $rollerJoinOrder, false);
        $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $newSquareIndex);

        // Landing on Go To Jail (square 30) immediately sends the player to the
        // Jail corner (square 10) with no GO bonus and marks them as jailed.
        if ($newSquareIndex === 30) {
            $this->sendPlayerToJail($gameId, $rollerJoinOrder);
            $this->gameRepository->saveDiceRoll($gameId, $die1, $die2, 0, 'done');
            DiceRolled::dispatch($gameId, $die1, $die2, $total, $rollerJoinOrder, 10);

            $nextJoinOrder = $this->advanceTurnFromJoinOrder($gameId, $rollerJoinOrder);

            return [
                'die1'                    => $die1,
                'die2'                    => $die2,
                'total'                   => $total,
                'current_turn_join_order' => $nextJoinOrder,
                'square_index'            => 10,
                'square_action'           => ['type' => 'go_to_jail', 'new_square_index' => 10],
                'passed_go'               => $passedGo,
                'go_bonus'                => $passedGo ? 200 : 0,
                'new_capital'             => $newCapital,
                'moved'                   => true,
                'is_in_jail'              => true,
                'isInJail'                => true,
                'jail_turns'              => 0,
                'has_paid_jail_release'   => false,
                'can_roll_again'          => false,
            ];
        }

        // If landing on a tax square, present a tax action so the frontend
        // can show the Income Tax dialog offering the two official options.
        if (in_array($newSquareIndex, [4, 38], true)) {
            $squareName = $newSquareIndex === 4 ? 'Income Tax' : 'Luxury Tax';
            if ($newSquareIndex === 4) {
                // Compute authoritative total assets and percent amount so
                // the frontend can display exactly the same values as the
                // assets breakdown dialog. The 10% shown in the Income Tax
                // dialog originates here (server-side). We calculate the
                // player's total assets using `computePlayerTotalAssets()`
                // and then apply `floor(totalAssets * (percent / 100))` to
                // produce the integer dollar amount shown to players.
                // This is the single source of truth for the percent tax.
                $percent = 10;
                $totalAssets = $this->computePlayerTotalAssets($gameId, $rollerJoinOrder);
                $percentAmount = (int) floor($totalAssets * ($percent / 100));

                $squareAction = [
                    'type' => 'tax',
                    'square_name' => $squareName,
                    'tax_kind' => 'income',
                    'options' => [
                        'flat' => 200,
                        'percent' => $percent,
                        'percent_amount' => $percentAmount,
                        'total_assets' => $totalAssets,
                    ],
                ];
            } else {
                $squareAction = [
                    'type' => 'tax',
                    'square_name' => $squareName,
                    'tax_kind' => 'luxury',
                    'options' => ['flat' => 75],
                ];
            }
        } else {
            // Compute and immediately resolve all landing consequences.
            $squareAction = $this->resolveLandingSquareAction($gameId, $rollerJoinOrder, $newSquareIndex);
        }
        $sentToJail = $this->containsGoToJailAction($squareAction);

        // A double used to leave jail never starts/continues the doubles streak.
        $canRollAgain = $isDouble && !$isJailReleaseDouble && !$sentToJail;
        $nextConsecutiveDoublesCount = $canRollAgain ? ($consecutiveDoublesCount + 1) : 0;
        $this->gameRepository->saveDiceRoll(
            $gameId,
            $die1,
            $die2,
            $nextConsecutiveDoublesCount,
            $canRollAgain ? 'roll' : 'done',
        );

        // Turn does not advance on roll unless a jail flow ended the turn.
        DiceRolled::dispatch($gameId, $die1, $die2, $total, $rollerJoinOrder, $newSquareIndex);

        $currentTurnJoinOrder = $rollerJoinOrder;
        if ($sentToJail) {
            $currentTurnJoinOrder = $this->advanceTurnFromJoinOrder($gameId, $rollerJoinOrder);
        }

        return [
            'die1'                    => $die1,
            'die2'                    => $die2,
            'total'                   => $total,
            'current_turn_join_order' => $currentTurnJoinOrder,
            'square_index'            => $newSquareIndex,
            'square_action'           => $squareAction,
            'passed_go'               => $passedGo,
            'go_bonus'                => $passedGo ? 200 : 0,
            'new_capital'             => $newCapital,
            'moved'                   => true,
            'is_in_jail'              => $sentToJail,
            'isInJail'                => $sentToJail,
            'jail_turns'              => 0,
            'has_paid_jail_release'   => false,
            'can_roll_again'          => $canRollAgain,
        ];
    }

    /**
     * Generate the pair of dice values for a turn roll.
     *
     * Logic: Produces two independent, cryptographically secure die values in
     * the 1..6 range. Declared as a protected method so tests can override it
     * and provide deterministic roll sequences.
     *
     * @return array{0:int,1:int}
     */
    protected function generateDiceRoll(): array
    {
        return [random_int(1, 6), random_int(1, 6)];
    }

    /**
     * Resolve the dice pair for a roll, honoring debug-only forced values.
     *
     * Logic: Uses the normal random generator when no forced pair is provided.
     * When forced values are provided, requires debug mode, validates both dice
     * are integers in the 1..6 range, and returns that pair unchanged so the
     * normal roll workflow handles all movement and side effects.
     *
     * @param  array{die1:int,die2:int}|null  $forcedDice  Optional debug-only forced dice pair.
     * @return array{0:int,1:int}
     */
    private function resolveDicePair(?array $forcedDice): array
    {
        if ($forcedDice === null) {
            return $this->generateDiceRoll();
        }

        if (!(bool) config('app.debug_mode')) {
            throw new InvalidArgumentException('Forced dice are only allowed in debug mode.');
        }

        $die1 = $forcedDice['die1'] ?? null;
        $die2 = $forcedDice['die2'] ?? null;

        if (!is_int($die1) || !is_int($die2)) {
            throw new InvalidArgumentException('Forced dice must be integers.');
        }

        if ($die1 < 1 || $die1 > 6 || $die2 < 1 || $die2 > 6) {
            throw new InvalidArgumentException('Forced dice must be between 1 and 6.');
        }

        return [$die1, $die2];
    }

    /**
     * Advance the game turn from the given join_order to the next player.
     *
     * Logic: Computes the cyclic next join_order and updates games with an
     * optimistic write guard. For single-player games, resets turn phase
     * in-place. Broadcasts TurnAdvanced in both cases.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $joinOrder  The join_order of the current player.
     * @return int
     */
    private function advanceTurnFromJoinOrder(int $gameId, int $joinOrder): int
    {
        $joinOrders    = $this->gameRepository->getPlayerJoinOrders($gameId);
        $idx           = array_search($joinOrder, $joinOrders, true);
        $nextIdx       = ($idx + 1) % count($joinOrders);
        $nextJoinOrder = $joinOrders[$nextIdx];

        if ($nextJoinOrder !== $joinOrder) {
            $advanced = $this->gameRepository->advanceTurn($gameId, $joinOrder, $nextJoinOrder);

            if (!$advanced) {
                throw new InvalidArgumentException('The turn was already advanced by a concurrent request.');
            }
        } else {
            $this->gameRepository->resetTurnPhase($gameId);
        }

        // Apply any pending builds queued for any owner in this game.
        // If the combined pending requests exceed the bank inventory we
        // allocate available houses/hotels randomly across pending rows and
        // notify owners whose pending builds could not be granted.
        try {
            $pendingRows = $this->pendingBuildRepository->getPendingBuildsForGame($gameId);
            if (!empty($pendingRows)) {
                // Compute current used counts and available bank inventory
                $usedHouses = $this->propertyRepository->countTotalHouses($gameId);
                $usedHotels = $this->propertyRepository->countTotalHotels($gameId);
                $totalBankHouses = config('monopoly.bank.houses');
                $totalBankHotels = config('monopoly.bank.hotels');

                $availableHouses = max(0, $totalBankHouses - $usedHouses);
                $availableHotels = max(0, $totalBankHotels - $usedHotels);

                // Shuffle pending rows to create a random allocation order
                $shuffled = $pendingRows;
                shuffle($shuffled);

                $toApply = [];
                $toDeny = [];

                foreach ($shuffled as $r) {
                    $id = $r['id'] ?? $r->id;
                    $isHotel = (bool) ($r['has_hotel'] ?? $r->has_hotel ?? false);
                    $housesDelta = (int) ($r['houses_delta'] ?? $r->houses_delta ?? 0);

                    if ($isHotel) {
                        if ($availableHotels > 0) {
                            $toApply[$id] = $r;
                            $availableHotels--;
                        } else {
                            $toDeny[$id] = $r;
                        }
                    } else {
                        if ($availableHouses >= $housesDelta && $housesDelta > 0) {
                            $toApply[$id] = $r;
                            $availableHouses -= $housesDelta;
                        } else {
                            $toDeny[$id] = $r;
                        }
                    }
                }

                // Apply approved rows atomically and remove both applied and
                // denied rows from the pending table. Notify owners of denied
                // allocations.
                DB::transaction(function () use ($gameId, $toApply, $toDeny) {
                    $appliedSummaries = [];

                    // Apply each approved pending row
                    foreach ($toApply as $r) {
                        $sq = (int) ($r['square_index'] ?? $r->square_index);
                        $housesDelta = (int) ($r['houses_delta'] ?? $r->houses_delta ?? 0);
                        $isHotel = (bool) ($r['has_hotel'] ?? $r->has_hotel ?? false);

                        $current = DB::table('game_properties')
                            ->where('game_id', $gameId)
                            ->where('square_index', $sq)
                            ->select(['houses_count', 'has_hotel'])
                            ->first();

                        if ($current === null) continue;

                        if ($isHotel) {
                            DB::table('game_properties')
                                ->where('game_id', $gameId)
                                ->where('square_index', $sq)
                                ->update(['houses_count' => 0, 'has_hotel' => true, 'updated_at' => now()]);
                        } else {
                            $newCount = ((int) ($current->houses_count ?? 0)) + $housesDelta;
                            DB::table('game_properties')
                                ->where('game_id', $gameId)
                                ->where('square_index', $sq)
                                ->update(['houses_count' => $newCount, 'updated_at' => now()]);
                        }

                        $appliedSummaries[] = $r;
                    }

                    // Remove all processed pending rows (applied + denied)
                    $ids = array_merge(array_keys($toApply), array_keys($toDeny));
                    if (!empty($ids)) {
                        DB::table('game_pending_builds')
                            ->where('game_id', $gameId)
                            ->whereIn('id', $ids)
                            ->delete();
                    }
                });

                // Broadcast applied builds and denied notifications.
                if (!empty($toApply)) {
                    // Resolve final states for all applied squares
                    $appliedSquares = array_unique(array_map(fn($r) => (int) ($r['square_index'] ?? $r->square_index), $toApply));
                    $final = [];
                    if (!empty($appliedSquares)) {
                        $final = DB::table('game_properties')
                            ->where('game_id', $gameId)
                            ->whereIn('square_index', $appliedSquares)
                            ->select(['square_index', 'houses_count', 'has_hotel'])
                            ->get()
                            ->keyBy(fn($row) => (int) $row->square_index)
                            ->all();
                    }

                    // Recompute available bank counts after application
                    $usedHousesAfter = $this->propertyRepository->countTotalHouses($gameId);
                    $usedHotelsAfter = $this->propertyRepository->countTotalHotels($gameId);
                    $bankHousesAvailable = max(0, config('monopoly.bank.houses') - $usedHousesAfter);
                    $bankHotelsAvailable = max(0, config('monopoly.bank.hotels') - $usedHotelsAfter);

                    foreach ($toApply as $r) {
                        try {
                            $owner = (int) ($r['owner_join_order'] ?? $r->owner_join_order);
                            $sq = (int) ($r['square_index'] ?? $r->square_index);
                            $housesCount = isset($final[$sq]) ? (int) ($final[$sq]->houses_count ?? 0) : null;
                            $hasHotel = isset($final[$sq]) ? (bool) ($final[$sq]->has_hotel ?? false) : null;
                            $ownerCapital = $this->getPlayerCapital($gameId, $owner);

                            event(new \App\Events\PropertyBuilt(
                                $gameId,
                                $owner,
                                $sq,
                                $housesCount,
                                $hasHotel,
                                $ownerCapital,
                                $bankHousesAvailable,
                                $bankHotelsAvailable,
                            ));
                        } catch (\Throwable $e) {
                            // ignore per-build dispatch errors
                        }
                    }
                }

                if (!empty($toDeny)) {
                    // Group denied squares by owner and notify each owner once
                    $deniedByOwner = [];
                    foreach ($toDeny as $r) {
                        $owner = (int) ($r['owner_join_order'] ?? $r->owner_join_order);
                        $sq = (int) ($r['square_index'] ?? $r->square_index);
                        $deniedByOwner[$owner][] = $sq;
                    }

                    foreach ($deniedByOwner as $owner => $squares) {
                        try {
                            event(new BuildAllocationFailed($gameId, $owner, $squares, 'Insufficient bank inventory to fulfill pending builds.'));
                        } catch (\Throwable $e) {
                            Log::error('Failed to dispatch build allocation failure', ['game_id' => $gameId, 'owner' => $owner, 'error' => $e->getMessage()]);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to enumerate pending builds for game', ['game_id' => $gameId, 'error' => $e->getMessage()]);
        }

        TurnAdvanced::dispatch($gameId, $nextJoinOrder);

        return $nextJoinOrder;
    }

    /**
     * Use one held get-out-of-jail-free card and clear jail state.
     *
     * Logic: Validates the player is currently jailed, attempts to release one
     * held Chance card and one held Community Chest card (in that order), and
     * clears jail state only when at least one card was returned to a deck.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return array{join_order: int, square_index: int, capital: int, is_in_jail: bool, jail_turns: int, has_paid_jail_release: bool}
     */
    private function useGetOutOfJailCard(int $gameId, int $joinOrder): array
    {
        if (!$this->playerIconRepository->getJailState($gameId, $joinOrder)) {
            throw new InvalidArgumentException('You are not in jail.');
        }

        $releasedChanceCard = $this->chanceCardRepository->releaseHeldCardFromPlayer($gameId, $joinOrder);
        $releasedCommunityCard = $this->communityChestCardRepository->releaseHeldCardFromPlayer($gameId, $joinOrder);

        if (!$releasedChanceCard && !$releasedCommunityCard) {
            throw new InvalidArgumentException('You do not have a Get Out of Jail Free card.');
        }

        $this->playerIconRepository->setJailState($gameId, $joinOrder, false);

        return [
            'join_order' => $joinOrder,
            'square_index' => $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder),
            'capital' => $this->getPlayerCapital($gameId, $joinOrder),
            'is_in_jail' => false,
            'jail_turns' => 0,
            'has_paid_jail_release' => false,
        ];
    }

    /**
     * Pay the $50 jail-release fee for a jailed player.
     *
     * Logic: Validates the player is jailed and has not paid already, verifies
     * capital is sufficient, deducts $50, and marks has_paid_jail_release so
     * the next roll leaves jail regardless of doubles.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return array{join_order: int, square_index: int, capital: int, is_in_jail: bool, jail_turns: int, has_paid_jail_release: bool, paid_amount: int}
     */
    private function payJailRelease(int $gameId, int $joinOrder): array
    {
        if (!$this->playerIconRepository->getJailState($gameId, $joinOrder)) {
            throw new InvalidArgumentException('You are not in jail.');
        }

        if ($this->playerIconRepository->hasPaidJailRelease($gameId, $joinOrder)) {
            throw new InvalidArgumentException('Jail release payment has already been made for this turn.');
        }

        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        if ($capital < 50) {
            throw new InvalidArgumentException('You do not have enough capital to pay $50 to leave jail.');
        }

        $newCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -50);
        $this->playerIconRepository->setHasPaidJailRelease($gameId, $joinOrder, true);

        return [
            'join_order' => $joinOrder,
            'square_index' => $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder),
            'capital' => $newCapital,
            'is_in_jail' => true,
            'jail_turns' => $this->playerIconRepository->getJailTurns($gameId, $joinOrder),
            'has_paid_jail_release' => true,
            'paid_amount' => 50,
        ];
    }

    /**
     * Core debug-only direct-move logic shared by authenticated and guest players.
     *
     * Logic:
     *   1. Validates targetSquareIndex is within the board range [0, 39].
     *   2. Loads the game and verifies it is currently the caller's turn.
     *   3. Computes forward total_steps from current square to the target square.
     *   4. Applies GO bonus when movement wraps past square 39.
    *   5. Persists a legal debug dice pair, marks the turn as done, and
    *      resolves landing side effects (rent payments, card draws, card
    *      effects) using the same helpers as the normal roll path.
    *   6. Broadcasts DiceRolled so every connected board animates the dice.
    *   7. Returns a payload compatible with board post-move handling.
     *
     * @param  int  $gameId             The ID of the game.
     * @param  int  $moverJoinOrder     The moving player's join_order.
     * @param  int  $targetSquareIndex  The destination board square index (0-39).
    * @return array{die1: int, die2: int, total: int, current_turn_join_order: int, square_index: int, total_steps: int, square_action: array|null, passed_go: bool, go_bonus: int, new_capital: int|null}
    * Logic: Emulates a deterministic roll result from a clicked square target and
    * broadcasts a legal dice pair so every board can animate the same debug move.
     *
     * @throws InvalidArgumentException When the game is missing, target is invalid, or it is not the caller's turn.
     */
    private function moveToSquare(int $gameId, int $moverJoinOrder, int $targetSquareIndex): array
    {
        if ($targetSquareIndex < 0 || $targetSquareIndex > 39) {
            throw new InvalidArgumentException('Target square is out of bounds.');
        }

        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $moverJoinOrder) {
            throw new InvalidArgumentException('It is not your turn to move.');
        }

        $currentSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $moverJoinOrder);
        $totalSteps         = (($targetSquareIndex - $currentSquareIndex) + 40) % 40;
        $debugRollTotal     = max(2, min(12, $totalSteps));
        $die1               = intdiv($debugRollTotal + 1, 2);
        $die2               = $debugRollTotal - $die1;

        $passedGo   = $totalSteps > 0 && ($currentSquareIndex + $totalSteps) >= 40;
        $newCapital = null;
        if ($passedGo) {
            $newCapital = $this->playerIconRepository->adjustCapital($gameId, $moverJoinOrder, 200);
        }

        $this->playerIconRepository->setJailState($gameId, $moverJoinOrder, false);
        $this->playerIconRepository->updateSquareIndex($gameId, $moverJoinOrder, $targetSquareIndex);
        $this->gameRepository->saveDiceRoll($gameId, $die1, $die2);

        DiceRolled::dispatch($gameId, $die1, $die2, $debugRollTotal, $moverJoinOrder, $targetSquareIndex);

        // Landing on Go To Jail (square 30) immediately sends the player to the
        // Jail corner (square 10) with no GO bonus and marks them as jailed.
        if ($targetSquareIndex === 30) {
            $this->sendPlayerToJail($gameId, $moverJoinOrder);
            $nextJoinOrder = $this->advanceTurnFromJoinOrder($gameId, $moverJoinOrder);

            return [
                'die1'                    => $die1,
                'die2'                    => $die2,
                'total'                   => $debugRollTotal,
                'current_turn_join_order' => $nextJoinOrder,
                'square_index'            => 10,
                'total_steps'             => $totalSteps,
                'square_action'           => ['type' => 'go_to_jail', 'new_square_index' => 10],
                'passed_go'               => $passedGo,
                'go_bonus'                => $passedGo ? 200 : 0,
                'new_capital'             => $newCapital,
            ];
        }

        $squareAction = $this->resolveLandingSquareAction($gameId, $moverJoinOrder, $targetSquareIndex);

        return [
            'die1'                    => $die1,
            'die2'                    => $die2,
            'total'                   => $debugRollTotal,
            'current_turn_join_order' => $moverJoinOrder,
            'square_index'            => $targetSquareIndex,
            'total_steps'             => $totalSteps,
            'square_action'           => $squareAction,
            'passed_go'               => $passedGo,
            'go_bonus'                => $passedGo ? 200 : 0,
            'new_capital'             => $newCapital,
        ];
    }

    /**
     * Purchase a property on behalf of an authenticated player.
     *
     * Logic: Resolves the caller's join_order, then delegates to
    * purchasePropertyWithSessionMortgages().
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $userId       The authenticated user's ID.
     * @param  int  $squareIndex  The board square index the player is purchasing.
    * @param  array<int, int>  $mortgageSquareIndexes  Optional session-selected properties to mortgage before payment.
    * @return array{join_order: int, capital: int, property: array{square_index: int, name: string}}
     *
     * @throws InvalidArgumentException When the player is not a participant.
     */
    public function purchasePropertyForUser(
        int $gameId,
        int $userId,
        int $squareIndex,
        array $mortgageSquareIndexes = []
    ): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->purchasePropertyWithSessionMortgages(
            $gameId,
            $joinOrder,
            $squareIndex,
            $mortgageSquareIndexes,
        );
    }

    /**
     * Purchase a property on behalf of a guest player.
     *
     * Logic: Resolves the guest's join_order via invitation_id, then delegates
    * to purchasePropertyWithSessionMortgages().
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The GameInvitation primary key for the guest.
     * @param  int  $squareIndex   The board square index the player is purchasing.
    * @param  array<int, int>  $mortgageSquareIndexes  Optional session-selected properties to mortgage before payment.
    * @return array{join_order: int, capital: int, property: array{square_index: int, name: string}}
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function purchasePropertyForGuest(
        int $gameId,
        int $invitationId,
        int $squareIndex,
        array $mortgageSquareIndexes = []
    ): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->purchasePropertyWithSessionMortgages(
            $gameId,
            $joinOrder,
            $squareIndex,
            $mortgageSquareIndexes,
        );
    }

    /**
     * Pay rent on behalf of an authenticated player.
     *
     * Logic: Resolves the caller's join_order, then delegates to payRent().
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $userId       The authenticated user's ID.
     * @param  int  $squareIndex  The board square index where rent is owed.
    * @param  array<int, int>  $mortgageSquareIndexes  Optional session-selected properties to mortgage before payment.
     * @return array{
     *     payer: array{join_order: int, capital: int},
     *     owner: array{join_order: int, capital: int},
     *     rent_amount: int,
     *     square_name: string,
     * }
     *
     * @throws InvalidArgumentException When the player is not a participant.
     */
    public function payRentForUser(
        int $gameId,
        int $userId,
        int $squareIndex,
        array $mortgageSquareIndexes = []
    ): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->payRentWithSessionMortgages(
            $gameId,
            $joinOrder,
            $squareIndex,
            $mortgageSquareIndexes,
        );
    }

    /**
     * Pay rent on behalf of a guest player.
     *
     * Logic: Resolves the guest's join_order via invitation_id, then delegates
     * to payRent().
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The GameInvitation primary key for the guest.
     * @param  int  $squareIndex   The board square index where rent is owed.
    * @param  array<int, int>  $mortgageSquareIndexes  Optional session-selected properties to mortgage before payment.
     * @return array{
     *     payer: array{join_order: int, capital: int},
     *     owner: array{join_order: int, capital: int},
     *     rent_amount: int,
     *     square_name: string,
     * }
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function payRentForGuest(
        int $gameId,
        int $invitationId,
        int $squareIndex,
        array $mortgageSquareIndexes = []
    ): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->payRentWithSessionMortgages(
            $gameId,
            $joinOrder,
            $squareIndex,
            $mortgageSquareIndexes,
        );
    }

    /**
     * Declare bankruptcy for an authenticated user.
     *
     * Logic: Resolves the user's join_order and transfers their capital,
     * properties, and held cards to either the creditor player (when provided)
     * or to the bank. Properties transferred to a player remain mortgaged.
     * When transferred to the bank the ownership rows are removed and held
     * cards are returned to the deck bottom.
     *
     * @param int $gameId
     * @param int $userId
     * @param int|null $creditorJoinOrder
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the caller is not a participant.
     */
    public function declareBankruptcyForUser(int $gameId, int $userId, ?int $creditorJoinOrder = null): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new \InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->declareBankruptcyByJoinOrder($gameId, $joinOrder, $creditorJoinOrder);
    }

    /**
     * Declare bankruptcy for a guest player (invitation).
     *
     * @param int $gameId
     * @param int $invitationId
     * @param int|null $creditorJoinOrder
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the guest is not a participant.
     */
    public function declareBankruptcyForGuest(int $gameId, int $invitationId, ?int $creditorJoinOrder = null): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new \InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->declareBankruptcyByJoinOrder($gameId, $joinOrder, $creditorJoinOrder);
    }

    /**
     * Core bankruptcy implementation operating on join_order.
     *
     * @param int $gameId
     * @param int $joinOrder
     * @param int|null $creditorJoinOrder
     * @return array<string, mixed>
     */
    private function declareBankruptcyByJoinOrder(int $gameId, int $joinOrder, ?int $creditorJoinOrder = null): array
    {
        try {
            return DB::transaction(function () use ($gameId, $joinOrder, $creditorJoinOrder): array {
                // Transfer capital
                $capital = $this->getPlayerCapital($gameId, $joinOrder);
                $capitalTransferred = 0;

                if ($capital > 0) {
                    if (is_int($creditorJoinOrder)) {
                        $this->playerIconRepository->adjustCapital($gameId, $creditorJoinOrder, $capital);
                    }

                    // Deduct from bankrupt player
                    $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$capital);
                    $capitalTransferred = $capital;
                }

                // Transfer held cards
                $heldChance = $this->chanceCardRepository->getHeldCardsForGame($gameId)[$joinOrder] ?? [];
                $heldCommunity = $this->communityChestCardRepository->getHeldCardsForGame($gameId)[$joinOrder] ?? [];

                $chanceTransferred = 0;
                $communityTransferred = 0;

                if (is_int($creditorJoinOrder)) {
                    foreach ($heldChance as $c) {
                        $this->chanceCardRepository->assignCardToPlayer($gameId, (int) $c['id'], $creditorJoinOrder);
                        $chanceTransferred++;
                    }

                    foreach ($heldCommunity as $c) {
                        $this->communityChestCardRepository->assignCardToPlayer($gameId, (int) $c['id'], $creditorJoinOrder);
                        $communityTransferred++;
                    }
                } else {
                    // Return held cards to deck bottom
                    $this->chanceCardRepository->releaseHeldCardFromPlayer($gameId, $joinOrder);
                    $this->communityChestCardRepository->releaseHeldCardFromPlayer($gameId, $joinOrder);
                    $chanceTransferred = count($heldChance);
                    $communityTransferred = count($heldCommunity);
                }

                // Transfer properties
                $owned = $this->propertyRepository->findPlayerProperties($gameId, $joinOrder);
                $transferredProperties = [];

                if (!empty($owned)) {
                    $squareIndexes = array_column($owned, 'square_index');

                    if (is_int($creditorJoinOrder)) {
                        // Assign all properties to creditor and ensure they remain mortgaged
                        DB::table('game_properties')
                            ->where('game_id', $gameId)
                            ->where('owner_join_order', $joinOrder)
                            ->update(['owner_join_order' => $creditorJoinOrder, 'is_mortgaged' => true, 'updated_at' => now()]);

                        $transferredProperties = $squareIndexes;
                    } else {
                        // Transfer to bank: remove ownership rows so the properties
                        // become available for purchase again (rows deleted).
                        DB::table('game_properties')
                            ->where('game_id', $gameId)
                            ->where('owner_join_order', $joinOrder)
                            ->delete();

                        $transferredProperties = $squareIndexes;
                    }
                }

                // Mark the bankrupt player in the participants table so server-side
                // turn-order and token logic can exclude them from active play.
                DB::table('game_player_icons')
                    ->where('game_id', $gameId)
                    ->where('join_order', $joinOrder)
                    ->update(['is_bankrupt' => true, 'updated_at' => now()]);

                return [
                    'declared_join_order' => $joinOrder,
                    'recipient' => is_int($creditorJoinOrder) ? 'player' : 'bank',
                    'recipient_join_order' => $creditorJoinOrder,
                    'capital_transferred' => $capitalTransferred,
                    'transferred_properties' => $transferredProperties,
                    'chance_transferred' => $chanceTransferred,
                    'community_transferred' => $communityTransferred,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('Failed to process bankruptcy', ['game_id' => $gameId, 'join_order' => $joinOrder, 'exception' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Purchase a property inside a payment-scoped mortgage session.
     *
     * Logic: Runs the entire operation in one DB transaction so selected
     * mortgages and purchase mutation are committed together or rolled back
     * together. Applies mortgage credits first, then performs the purchase.
     *
     * @param  int    $gameId                 The ID of the game.
     * @param  int    $joinOrder              The join_order of the buyer.
     * @param  int    $squareIndex            The board square index being purchased.
     * @param  array  $mortgageSquareIndexes  Property squares selected for this payment session.
     * @return array{join_order: int, capital: int, property: array{square_index: int, name: string}}
     */
    private function purchasePropertyWithSessionMortgages(
        int $gameId,
        int $joinOrder,
        int $squareIndex,
        array $mortgageSquareIndexes
    ): array {
        try {
            return DB::transaction(function () use ($gameId, $joinOrder, $squareIndex, $mortgageSquareIndexes): array {
                $this->applySessionMortgages($gameId, $joinOrder, $mortgageSquareIndexes);

                return $this->purchaseProperty($gameId, $joinOrder, $squareIndex);
            });
        } catch (\Throwable $e) {
            Log::error('Failed purchase in mortgage session', [
                'game_id'                  => $gameId,
                'join_order'               => $joinOrder,
                'square_index'             => $squareIndex,
                'mortgage_square_indexes'  => $mortgageSquareIndexes,
                'exception'                => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Pay rent inside a payment-scoped mortgage session.
     *
     * Logic: Runs the entire operation in one DB transaction so selected
     * mortgages and rent payment are committed together or rolled back together.
     * Applies mortgage credits first, then performs rent payment.
     *
     * @param  int    $gameId                 The ID of the game.
     * @param  int    $joinOrder              The join_order of the payer.
     * @param  int    $squareIndex            The board square index where rent is owed.
     * @param  array  $mortgageSquareIndexes  Property squares selected for this payment session.
     * @return array{
     *     payer: array{join_order: int, capital: int},
     *     owner: array{join_order: int, capital: int},
     *     rent_amount: int,
     *     square_name: string,
     * }
     */
    private function payRentWithSessionMortgages(
        int $gameId,
        int $joinOrder,
        int $squareIndex,
        array $mortgageSquareIndexes
    ): array {
        try {
            return DB::transaction(function () use ($gameId, $joinOrder, $squareIndex, $mortgageSquareIndexes): array {
                $this->applySessionMortgages($gameId, $joinOrder, $mortgageSquareIndexes);

                return $this->payRent($gameId, $joinOrder, $squareIndex);
            });
        } catch (\Throwable $e) {
            Log::error('Failed rent payment in mortgage session', [
                'game_id'                  => $gameId,
                'join_order'               => $joinOrder,
                'square_index'             => $squareIndex,
                'mortgage_square_indexes'  => $mortgageSquareIndexes,
                'exception'                => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Apply selected mortgages for one payment session and credit capital.
     *
     * Logic: Iterates selected square indexes once, mortgages each selected
     * property via repository validation, and credits the player's capital by
     * the returned mortgage value. This helper is transaction-safe and intended
     * only for the immediate payment flow.
     *
     * @param  int    $gameId                 The ID of the game.
     * @param  int    $joinOrder              The join_order of the payer/buyer.
     * @param  array  $mortgageSquareIndexes  Property squares selected for this payment session.
     * @return int
     */
    private function applySessionMortgages(int $gameId, int $joinOrder, array $mortgageSquareIndexes): int
    {
        $totalRaised = 0;
        $uniqueSquareIndexes = array_values(array_unique(array_map('intval', $mortgageSquareIndexes)));

        foreach ($uniqueSquareIndexes as $mortgageSquareIndex) {
            $mortgageValue = $this->propertyRepository->mortgageProperty($gameId, $mortgageSquareIndex, $joinOrder);
            $this->playerIconRepository->adjustCapital($gameId, $joinOrder, $mortgageValue);
            $totalRaised += $mortgageValue;
        }

        return $totalRaised;
    }

    /**
     * Core property purchase logic.
     *
     * Logic:
     *   1. Validates the square is purchasable and has a defined price.
     *   2. Verifies the square is currently unowned; throws if already owned.
     *   3. Records ownership in game_properties.
    *   4. Verifies the buyer can afford the property before mutating state.
    *   5. Deducts the purchase price from the buyer's capital.
    *   6. Returns the buyer's join_order and updated capital.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the purchasing player.
     * @param  int  $squareIndex  The board square index being purchased.
    * @return array{join_order: int, capital: int, property: array{square_index: int, name: string}}
     *
    * @throws InvalidArgumentException When the square is not purchasable,
    *                                 already owned, or the buyer cannot afford it.
     */
    private function purchaseProperty(int $gameId, int $joinOrder, int $squareIndex): array
    {
        $squareData = self::getSquareData($squareIndex);

        if ($squareData === null) {
            throw new InvalidArgumentException('This square cannot be purchased.');
        }

        $existing = $this->propertyRepository->findOwnerBySquare($gameId, $squareIndex);

        if ($existing !== null) {
            throw new InvalidArgumentException('This property is already owned.');
        }

        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        if ($capital < (int) $squareData['price']) {
            throw new InvalidArgumentException('You do not have enough capital to purchase this property.');
        }

        $this->propertyRepository->createOwnership($gameId, $squareIndex, $joinOrder, $squareData['price']);
        $newCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$squareData['price']);
        $buyer = collect($this->playerIconRepository->getPlayersForGame($gameId))
            ->firstWhere('join_order', $joinOrder);

        PropertyPurchased::dispatch(
            $gameId,
            $joinOrder,
            $buyer['name'] ?? 'Player',
            $newCapital,
            $buyer['icon'] ?? null,
            $squareIndex,
            $squareData['name'],
            $squareData['price'],
        );

        return [
            'join_order' => $joinOrder,
            'capital'    => $newCapital,
            'property'   => [
                'square_index' => $squareIndex,
                'name'         => $squareData['name'],
            ],
        ];
    }

    /**
     * Core rent payment logic.
     *
        if ($existing !== null) {
     *   1. Validates the square is purchasable and has a defined rent.
     *   2. Verifies the square is currently owned; throws if unowned.

        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        if ($capital < (int) $squareData['price']) {
            throw new InvalidArgumentException('You do not have enough capital to purchase this property.');
        }
    *   3. Verifies the property is not mortgaged.
    *   4. Verifies the payer can afford the rent before mutating state.
    *   5. Deducts the rent amount from the payer's capital.
    *   6. Adds the rent amount to the property owner's capital.
    *   7. Returns both players' updated capitals.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the paying player.
     * @param  int  $squareIndex  The board square index where rent is owed.
     * @return array{
     *     payer: array{join_order: int, capital: int},
     *     owner: array{join_order: int, capital: int},
     *     rent_amount: int,
     *     square_name: string,
     * }
     *
    * @throws InvalidArgumentException When the square has no rent, is unowned,
    *                                 is mortgaged, or the payer cannot afford it.
     */
    private function payRent(int $gameId, int $joinOrder, int $squareIndex): array
    {
        $squareData = self::getSquareData($squareIndex);

        if ($squareData === null) {
            throw new InvalidArgumentException('No rent applies to this square.');
        }

        $ownerInfo = $this->propertyRepository->findOwnerBySquare($gameId, $squareIndex);

        if ($ownerInfo === null) {
            throw new InvalidArgumentException('This property has no owner.');
        }

        if (!empty($ownerInfo['is_mortgaged'])) {
            throw new InvalidArgumentException('This property is mortgaged and does not charge rent.');
        }

        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        // Compute rent amount taking buildings and monopolies into account.
        $housesCount = isset($ownerInfo['houses_count']) ? (int) $ownerInfo['houses_count'] : 0;
        $hasHotel = isset($ownerInfo['has_hotel']) ? (bool) $ownerInfo['has_hotel'] : false;

        // Default base rent from board metadata
        $rentAmount = (int) $squareData['rent'];

        // Apply building-based adjustments for colour-group properties.
        // Note: railroads and utilities are handled elsewhere; this logic
        // only augments the base rent for properties that may have houses/hotels.
        if ($hasHotel) {
            // Generic hotel multiplier to increase rent when a hotel is present.
            $rentAmount = (int) ($rentAmount * 125);
        } elseif ($housesCount > 0) {
            // Generic multipliers for 1-4 houses. These produce an increasing
            // rent scale so built properties are charged more than base rent.
            $multipliers = [5, 15, 45, 80];
            $idx = min(max($housesCount, 1), 4) - 1;
            $rentAmount = (int) ($rentAmount * $multipliers[$idx]);
        } else {
            // No buildings: check for monopoly (owning full colour set)
            // and double the base rent when the owner controls the full set
            // and none of the set is mortgaged.
            $colourGroups = [
                [1,3],
                [6,8,9],
                [11,13,14],
                [16,18,19],
                [21,23,24],
                [26,27,29],
                [31,32,34],
                [37,39],
            ];

            $groupSquares = null;
            foreach ($colourGroups as $grp) {
                if (in_array($squareIndex, $grp, true)) {
                    $groupSquares = $grp;
                    break;
                }
            }

            if ($groupSquares !== null) {
                $ownsAll = true;
                foreach ($groupSquares as $sq) {
                    $ownerRow = $this->propertyRepository->findOwnerBySquare($gameId, $sq);
                    if ($ownerRow === null || $ownerRow['owner_join_order'] !== $ownerInfo['owner_join_order'] || !empty($ownerRow['is_mortgaged'])) {
                        $ownsAll = false;
                        break;
                    }
                }
                if ($ownsAll) {
                    $rentAmount = $rentAmount * 2;
                }
            }
        }

        if ($capital < $rentAmount) {
            throw new InvalidArgumentException('You do not have enough capital to pay this rent.');
        }
        $payerName    = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);
        $payerCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$rentAmount);
        $ownerCapital = $this->playerIconRepository->adjustCapital($gameId, $ownerInfo['owner_join_order'], $rentAmount);
        $players      = collect($this->playerIconRepository->getPlayersForGame($gameId));
        $payerInfo    = $players->firstWhere('join_order', $joinOrder);
        $ownerInfoRow = $players->firstWhere('join_order', $ownerInfo['owner_join_order']);

        RentPaid::dispatch(
            $gameId,
            $joinOrder,
            $payerName,
            $payerCapital,
            $payerInfo['icon'] ?? null,
            $ownerInfo['owner_join_order'],
            $ownerInfo['owner_name'],
            $ownerCapital,
            $ownerInfoRow['icon'] ?? null,
            $rentAmount,
            $squareData['name'],
        );

        return [
            'payer' => [
                'join_order' => $joinOrder,
                'capital'    => $payerCapital,
            ],
            'owner' => [
                'join_order' => $ownerInfo['owner_join_order'],
                'capital'    => $ownerCapital,
            ],
            'rent_amount' => $rentAmount,
            'square_name' => $squareData['name'],
        ];
    }

    /**
     * Calculate the rent amount for a square without mutating state.
     * Used to surface required payment amounts to the frontend when the payer
     * lacks sufficient capital so the UI can present mortgage options.
     *
     * @param int $gameId
     * @param int $squareIndex
     * @param array|null $ownerInfo Optional owner row to avoid refetching.
     * @return int
     */
    private function calculateRentAmount(int $gameId, int $squareIndex, ?array $ownerInfo = null): int
    {
        $squareData = self::getSquareData($squareIndex);

        if ($squareData === null) {
            return 0;
        }

        $ownerInfo = $ownerInfo ?? $this->propertyRepository->findOwnerBySquare($gameId, $squareIndex);

        if ($ownerInfo === null) {
            return 0;
        }

        $housesCount = isset($ownerInfo['houses_count']) ? (int) $ownerInfo['houses_count'] : 0;
        $hasHotel = isset($ownerInfo['has_hotel']) ? (bool) $ownerInfo['has_hotel'] : false;

        $rentAmount = (int) $squareData['rent'];

        if ($hasHotel) {
            $rentAmount = (int) ($rentAmount * 125);
        } elseif ($housesCount > 0) {
            $multipliers = [5, 15, 45, 80];
            $idx = min(max($housesCount, 1), 4) - 1;
            $rentAmount = (int) ($rentAmount * $multipliers[$idx]);
        } else {
            $colourGroups = [
                [1,3],
                [6,8,9],
                [11,13,14],
                [16,18,19],
                [21,23,24],
                [26,27,29],
                [31,32,34],
                [37,39],
            ];

            $groupSquares = null;
            foreach ($colourGroups as $grp) {
                if (in_array($squareIndex, $grp, true)) {
                    $groupSquares = $grp;
                    break;
                }
            }

            if ($groupSquares !== null) {
                $ownsAll = true;
                foreach ($groupSquares as $sq) {
                    $ownerRow = $this->propertyRepository->findOwnerBySquare($gameId, $sq);
                    if ($ownerRow === null || $ownerRow['owner_join_order'] !== $ownerInfo['owner_join_order'] || !empty($ownerRow['is_mortgaged'])) {
                        $ownsAll = false;
                        break;
                    }
                }
                if ($ownsAll) {
                    $rentAmount = $rentAmount * 2;
                }
            }
        }

        return $rentAmount;
    }

    /**
     * Resolve all server-side consequences for landing on a square.
     *
        if ($ownerInfo === null) {
     *   1. Computes purchase/rent intent for the landed square.
     *   2. Resolves rent immediately server-side so refreshes cannot bypass it.

        if (!empty($ownerInfo['is_mortgaged'])) {
            throw new InvalidArgumentException('This property is mortgaged and does not charge rent.');
        }

        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        if ($capital < (int) $squareData['rent']) {
            throw new InvalidArgumentException('You do not have enough capital to pay this rent.');
        }
     *   3. Auto-draws Chance/Community cards for their squares and applies card
     *      effects immediately, including chained movement and follow-up landing
     *      actions from the card destination square.
     *   4. Dispatches CardDrawn broadcasts with the computed effect payload.
    *   5. When the landing square is mortgaged, dispatches a real-time
    *      mortgaged-property broadcast so every observer board can show the
    *      no-rent notification immediately.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the landing player.
     * @param  int  $squareIndex  The landed square index.

    /**
     * Return the calling player's owned properties for mortgage actions.
     *
     * Logic: Resolves the caller's join_order by user_id and delegates to the
     * property repository so the frontend can render a list of mortgageable
     * properties.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $userId  The authenticated user's ID.
    * @return array<int, array{square_index: int, name: string, color: string|null, purchase_price: int, mortgage_value: int, unmortgage_cost: int, is_mortgaged: bool}>
     *
     * @throws InvalidArgumentException When the user is not a participant.
     */
    public function getPlayerPropertiesForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $properties = $this->propertyRepository->findPlayerProperties($gameId, $joinOrder);

        // Attach any pending build deltas for this owner so the UI can show progress
        $pendingRows = $this->pendingBuildRepository->getPendingBuildsForGame($gameId);
        if (!empty($pendingRows)) {
            $pendingBySquare = [];
            foreach ($pendingRows as $r) {
                // repository returns plain arrays
                $owner = isset($r['owner_join_order']) ? (int) $r['owner_join_order'] : (int) ($r->owner_join_order ?? 0);
                if ($owner !== $joinOrder) continue;
                $sq = isset($r['square_index']) ? (int) $r['square_index'] : (int) ($r->square_index ?? 0);
                $housesDelta = isset($r['houses_delta']) ? (int) $r['houses_delta'] : (int) ($r->houses_delta ?? 0);
                $hasHotel = isset($r['has_hotel']) ? (bool) $r['has_hotel'] : (bool) ($r->has_hotel ?? false);

                if (!isset($pendingBySquare[$sq])) {
                    $pendingBySquare[$sq] = ['houses_delta' => 0, 'has_hotel' => false];
                }

                $pendingBySquare[$sq]['houses_delta'] += $housesDelta;
                if ($hasHotel) {
                    $pendingBySquare[$sq]['has_hotel'] = true;
                }
            }

            foreach ($properties as &$p) {
                $sq = $p['square_index'];
                $pb = $pendingBySquare[$sq] ?? ['houses_delta' => 0, 'has_hotel' => false];
                $p['pending_houses_delta'] = $pb['houses_delta'];
                $p['pending_has_hotel'] = $pb['has_hotel'];
            }
            unset($p);
        }

        try {
            Log::debug('getPlayerPropertiesForUser payload', ['game_id' => $gameId, 'join_order' => $joinOrder, 'properties' => $properties]);
        } catch (\Throwable $e) {
            // ignore logging errors in test environments
        }

        return $properties;
    }

    /**
     * Return the guest player's owned properties for mortgage actions.
     *
     * Logic: Resolves the guest invitation to a join_order and delegates to
     * the property repository so the frontend can render a list of mortgageable
     * properties.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $invitationId  The guest invitation primary key.
    * @return array<int, array{square_index: int, name: string, color: string|null, purchase_price: int, mortgage_value: int, unmortgage_cost: int, is_mortgaged: bool}>
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function getPlayerPropertiesForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $properties = $this->propertyRepository->findPlayerProperties($gameId, $joinOrder);

        // Attach pending builds for this guest owner as well
        $pendingRows = $this->pendingBuildRepository->getPendingBuildsForGame($gameId);
        if (!empty($pendingRows)) {
            $pendingBySquare = [];
            foreach ($pendingRows as $r) {
                $owner = isset($r['owner_join_order']) ? (int) $r['owner_join_order'] : (int) ($r->owner_join_order ?? 0);
                if ($owner !== $joinOrder) continue;
                $sq = isset($r['square_index']) ? (int) $r['square_index'] : (int) ($r->square_index ?? 0);
                $housesDelta = isset($r['houses_delta']) ? (int) $r['houses_delta'] : (int) ($r->houses_delta ?? 0);
                $hasHotel = isset($r['has_hotel']) ? (bool) $r['has_hotel'] : (bool) ($r->has_hotel ?? false);

                if (!isset($pendingBySquare[$sq])) {
                    $pendingBySquare[$sq] = ['houses_delta' => 0, 'has_hotel' => false];
                }

                $pendingBySquare[$sq]['houses_delta'] += $housesDelta;
                if ($hasHotel) {
                    $pendingBySquare[$sq]['has_hotel'] = true;
                }
            }

            foreach ($properties as &$p) {
                $sq = $p['square_index'];
                $pb = $pendingBySquare[$sq] ?? ['houses_delta' => 0, 'has_hotel' => false];
                $p['pending_houses_delta'] = $pb['houses_delta'];
                $p['pending_has_hotel'] = $pb['has_hotel'];
            }
            unset($p);
        }

        return $properties;
    }

    /**
     * Apply a tax payment choice for a user in a game.
     *
     * Logic:
     *   - Resolves the caller's join_order and current capital.
     *   - Computes the owed amount: a flat amount for 'flat' choice, or
     *     a percentage of the player's total assets for 'percent' choice.
     *   - Throws InvalidArgumentException when the player does not have
     *     sufficient capital to pay (frontend will surface mortgage options).
     *   - On success adjusts the player's capital atomically and returns
     *     the updated player payload for the response.
     *
     * @param int $gameId
     * @param int $userId
     * @param int $squareIndex
     * @param string $choice
     * @param int|null $amount
     * @param int|null $percent
     * @return array{player: array}
     *
     * @throws InvalidArgumentException
     */
    public function applyTaxChoiceForUser(int $gameId, int $userId, int $squareIndex, string $choice, ?int $amount = null, ?int $percent = null): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);
        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        // Compute player's total assets using the richer helper that
        // includes purchase prices, building values and mortgage values.
        $totalAssets = $this->computePlayerTotalAssets($gameId, $joinOrder);

        if ($choice === 'flat') {
            $charge = $amount ?? 200;
        } elseif ($choice === 'percent') {
            $pct = $percent ?? 10;
            $charge = (int) floor($totalAssets * ($pct / 100));
        } else {
            throw new InvalidArgumentException('Invalid tax choice.');
        }

        if ($capital < $charge) {
            throw new InvalidArgumentException('You do not have enough capital to pay this tax.');
        }

        $newCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$charge);

        return ['player' => ['join_order' => $joinOrder, 'capital' => $newCapital]];
    }

    /**
     * Apply a tax payment choice for a guest player identified by invitation.
     * Mirrors applyTaxChoiceForUser but resolves join_order via the invitation id.
     *
     * @param int $gameId
     * @param int $invitationId
     * @param int $squareIndex
     * @param string $choice
     * @param int|null $amount
     * @param int|null $percent
     * @return array{player: array}
     *
     * @throws InvalidArgumentException
     */
    public function applyTaxChoiceForGuest(int $gameId, int $invitationId, int $squareIndex, string $choice, ?int $amount = null, ?int $percent = null): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);
        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        // Compute player's total assets using the richer helper that
        // includes purchase prices, building values and mortgage values.
        $totalAssets = $this->computePlayerTotalAssets($gameId, $joinOrder);

        if ($choice === 'flat') {
            $charge = $amount ?? 200;
        } elseif ($choice === 'percent') {
            $pct = $percent ?? 10;
            $charge = (int) floor($totalAssets * ($pct / 100));
        } else {
            throw new InvalidArgumentException('Invalid tax choice.');
        }

        if ($capital < $charge) {
            throw new InvalidArgumentException('You do not have enough capital to pay this tax.');
        }

        $newCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$charge);

        return ['player' => ['join_order' => $joinOrder, 'capital' => $newCapital]];
    }

    /**
     * Mortgage one property for an authenticated player.
     *
     * Logic: Resolves the caller's join_order, mortgages the selected property
     * via the repository, then credits the player's capital by the mortgage
     * value and returns the updated balance.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $userId       The authenticated user's ID.
     * @param  int  $squareIndex  The board square index to mortgage.
     * @return array{join_order: int, capital: int, mortgage_value: int}
     *
     * @throws InvalidArgumentException When the user is not a participant.
     */
    public function mortgagePropertyForUser(int $gameId, int $userId, int $squareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->mortgageProperty($gameId, $joinOrder, $squareIndex);
    }

    /**
     * Mortgage one property for a guest player.
     *
     * Logic: Resolves the guest invitation to a join_order, mortgages the
     * selected property, then credits the guest player's capital by the
     * mortgage value and returns the updated balance.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $invitationId  The guest invitation primary key.
     * @param  int  $squareIndex  The board square index to mortgage.
     * @return array{join_order: int, capital: int, mortgage_value: int}
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function mortgagePropertyForGuest(int $gameId, int $invitationId, int $squareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->mortgageProperty($gameId, $joinOrder, $squareIndex);
    }

    /**
     * Unmortgage one property for an authenticated player.
     *
     * Logic: Resolves the caller's join_order, validates they can afford the
     * unmortgage cost, marks the property as unmortgaged, then deducts the
     * unmortgage amount from the player's capital and returns the updated
     * balance.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $userId       The authenticated user's ID.
     * @param  int  $squareIndex  The board square index to unmortgage.
     * @return array{join_order: int, capital: int, unmortgage_cost: int}
     *
     * @throws InvalidArgumentException When the user is not a participant.
     */
    public function unmortgagePropertyForUser(int $gameId, int $userId, int $squareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->unmortgageProperty($gameId, $joinOrder, $squareIndex);
    }

    /**
     * Unmortgage one property for a guest player.
     *
     * Logic: Resolves the guest invitation to a join_order, validates they can
     * afford the unmortgage cost, marks the property as unmortgaged, then
     * deducts the unmortgage amount from the guest player's capital and returns
     * the updated balance.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $invitationId  The guest invitation primary key.
     * @param  int  $squareIndex  The board square index to unmortgage.
     * @return array{join_order: int, capital: int, unmortgage_cost: int}
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function unmortgagePropertyForGuest(int $gameId, int $invitationId, int $squareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->unmortgageProperty($gameId, $joinOrder, $squareIndex);
    }

    /**
     * Mortgage a property and credit the player's capital.
     *
     * Logic: Delegates the property mutation to the repository, then adds the
     * mortgage value to the player's capital using the player repository.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the player.
     * @param  int  $squareIndex  The board square index to mortgage.
     * @return array{join_order: int, capital: int, mortgage_value: int}
     */
    private function mortgageProperty(int $gameId, int $joinOrder, int $squareIndex): array
    {
        $mortgageValue = $this->propertyRepository->mortgageProperty($gameId, $squareIndex, $joinOrder);
        $newCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, $mortgageValue);

        return [
            'join_order'     => $joinOrder,
            'capital'        => $newCapital,
            'mortgage_value' => $mortgageValue,
        ];
    }

    /**
     * Unmortgage a property and deduct the player's capital.
     *
     * Logic: Resolves the unmortgage cost from the property repository,
     * validates the player has enough capital to cover the payment, marks the
     * property as unmortgaged, then deducts the cost from player capital.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the player.
     * @param  int  $squareIndex  The board square index to unmortgage.
     * @return array{join_order: int, capital: int, unmortgage_cost: int}
     */
    private function unmortgageProperty(int $gameId, int $joinOrder, int $squareIndex): array
    {
        $unmortgageCost = $this->propertyRepository->getUnmortgageCost($gameId, $squareIndex, $joinOrder);
        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        if ($capital < $unmortgageCost) {
            throw new InvalidArgumentException('You do not have enough capital to unmortgage this property.');
        }

        $this->propertyRepository->unmortgageProperty($gameId, $squareIndex, $joinOrder);
        $newCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$unmortgageCost);

        return [
            'join_order'      => $joinOrder,
            'capital'         => $newCapital,
            'unmortgage_cost' => $unmortgageCost,
        ];
    }

    /**
     * Resolve the current capital for a player in a game.
     *
     * Logic: Reads the authoritative player roster and returns the capital for
     * the requested join_order. Used before purchase and rent to guard against
     * negative balances.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
    * @return int
    */
    private function getPlayerCapital(int $gameId, int $joinOrder): int
    {
        $player = collect($this->playerIconRepository->getPlayersForGame($gameId))
            ->firstWhere('join_order', $joinOrder);

        return (int) ($player['capital'] ?? 0);
    }

    /**
     * Compute a richer total-assets estimate for a player.
     *
     * Logic: Includes current capital, owned property purchase prices,
     * the notional value of buildings (houses and hotels) and the
     * mortgage values for owned properties to give a more complete
     * "total assets" figure used for percent-based charges.
     *
     * @param int $gameId
     * @param int $joinOrder
     * @return int
     */
    private function computePlayerTotalAssets(int $gameId, int $joinOrder): int
    {
        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        $properties = $this->propertyRepository->findPlayerProperties($gameId, $joinOrder);

        // Mirror the frontend's contribution logic: for mortgaged properties
        // count only the mortgage value; for unmortgaged properties count
        // purchase price + building value. This keeps server-side totals
        // consistent with the client-side assets breakdown computation.
        $propertiesTotal = 0;

        foreach ($properties as $p) {
            $purchasePrice = (int) ($p['purchase_price'] ?? 0);
            $houses = (int) ($p['houses_count'] ?? 0);
            $hasHotel = (bool) ($p['has_hotel'] ?? false);

            $buildUnit = intdiv($purchasePrice, 2); // build cost per house/hotel unit
            $buildingValue = $hasHotel ? (5 * $buildUnit) : ($houses * $buildUnit);

            // Count mortgaged properties at their purchase price per product
            // decision; include building value for unmortgaged properties so
            // percent-based charges include building investments.
            if (!empty($p['is_mortgaged'])) {
                $propertiesTotal += $purchasePrice;
            } else {
                $propertiesTotal += $purchasePrice + $buildingValue;
            }
        }

        // total assets: cash + properties contribution
        return $capital + $propertiesTotal;
    }

    /**
     * Public: return a detailed breakdown of a player's assets suitable for
     * the assets dialog and authoritative tax calculation.
     *
     * @param int $gameId
     * @param int $joinOrder
     * @param int $percent
     * @return array<string, mixed>
     */
    public function getPlayerAssetsBreakdown(int $gameId, int $joinOrder, int $percent = 10): array
    {
        $capital = $this->getPlayerCapital($gameId, $joinOrder);

        $properties = $this->propertyRepository->findPlayerProperties($gameId, $joinOrder);

        $props = [];
        $propertiesTotal = 0;

        foreach ($properties as $p) {
            $purchasePrice = (int) ($p['purchase_price'] ?? 0);
            $mortgageValue = (int) ($p['mortgage_value'] ?? intdiv($purchasePrice, 2));
            $houses = (int) ($p['houses_count'] ?? 0);
            $hasHotel = (bool) ($p['has_hotel'] ?? false);

            $buildUnit = intdiv($purchasePrice, 2);
            $buildingValue = $hasHotel ? (5 * $buildUnit) : ($houses * $buildUnit);

            // For the assets breakdown used by the tax flow, include building
            // value for unmortgaged properties so the dialog and percent
            // calculations reflect invested building costs. Mortgaged
            // properties are counted at purchase price per product decision.
            if (!empty($p['is_mortgaged'])) {
                $contribution = $purchasePrice;
            } else {
                $contribution = $purchasePrice + $buildingValue;
            }

            $propertiesTotal += $contribution;

            $props[] = array_merge($p, [
                'purchase_price' => $purchasePrice,
                'mortgage_value' => $mortgageValue,
                'houses_count'   => $houses,
                'has_hotel'      => $hasHotel,
                'building_value' => $buildingValue,
                'contribution'   => $contribution,
            ]);
        }

        $totalAssets = $capital + $propertiesTotal;
        // Authoritative percent amount calculation used by the assets
        // breakdown endpoint and the Income Tax dialog. The frontend may
        // display a computed percent locally, but this server-side value
        // is the single source of truth: `percent_amount = floor(totalAssets * (percent/100))`.
        $percentAmount = (int) floor($totalAssets * ($percent / 100));

        return [
            'capital' => $capital,
            'properties' => $props,
            'properties_total' => $propertiesTotal,
            'total_assets' => $totalAssets,
            'percent' => $percent,
            'percent_amount' => $percentAmount,
        ];
    }

    /**
     * Resolve all server-side consequences for landing on a square.
     *
     * Logic:
     *   1. Computes purchase/rent intent for the landed square.
     *   2. Resolves rent immediately server-side so refreshes cannot bypass it.
     *   3. Auto-draws Chance/Community cards for their squares and applies card
     *      effects immediately, including chained movement and follow-up landing
     *      actions from the card destination square.
     *   4. Dispatches CardDrawn broadcasts with the computed effect payload.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the landing player.
     * @param  int  $squareIndex  The landed square index.
     * @return array<string, mixed>|null
     */
    private function resolveLandingSquareAction(int $gameId, int $joinOrder, int $squareIndex): ?array
    {
        $squareAction = $this->computeSquareAction($gameId, $joinOrder, $squareIndex);

        // If computeSquareAction returned null for non-purchasable squares,
        // handle tax squares here so every landing flow (rolls, card moves)
        // presents the income/luxury tax choice dialog to the frontend.
        if ($squareAction === null && in_array($squareIndex, [4, 38], true)) {
            $squareName = $squareIndex === 4 ? 'Income Tax' : 'Luxury Tax';
            if ($squareIndex === 4) {
                // Use the authoritative assets breakdown so the landing-action
                // payload and the assets endpoint return identical totals and
                // percent amounts. This guarantees both UIs pull the same
                // server-provided values for tax flows.
                $breakdown = $this->getPlayerAssetsBreakdown($gameId, $joinOrder, 10);

                return [
                    'type' => 'tax',
                    'square_name' => $squareName,
                    'tax_kind' => 'income',
                    'options' => [
                        'flat' => 200,
                        'percent' => $breakdown['percent'] ?? 10,
                        'percent_amount' => $breakdown['percent_amount'] ?? 0,
                        'total_assets' => $breakdown['total_assets'] ?? 0,
                    ],
                ];
            }

            return [
                'type' => 'tax',
                'square_name' => $squareName,
                'tax_kind' => 'luxury',
                'options' => ['flat' => 75],
            ];
        }

        if (($squareAction['type'] ?? null) === 'rent') {
            // Compute the rent amount first so we can detect an inability to
            // pay and let the frontend present mortgage options without
            // aborting the entire roll flow. If the payer has enough
            // capital, perform the immediate payment as before.
            $ownerInfo = $this->propertyRepository->findOwnerBySquare($gameId, $squareIndex);
            $rentAmount = $this->calculateRentAmount($gameId, $squareIndex, $ownerInfo);

            $capital = $this->getPlayerCapital($gameId, $joinOrder);

            if ($capital < $rentAmount) {
                // Signal to the frontend that rent is due and how much is
                // required so the UI can open the mortgage/payment dialog.
                $squareAction = [
                    'type' => 'rent',
                    'square_name' => $squareAction['square_name'] ?? null,
                    'rent' => $rentAmount,
                    'owner_join_order' => $ownerInfo['owner_join_order'] ?? null,
                    'owner_name' => $ownerInfo['owner_name'] ?? ($squareAction['owner_name'] ?? null),
                ];
            } else {
                $rentResult  = $this->payRent($gameId, $joinOrder, $squareIndex);
                $squareAction = [
                    'type'             => 'rent_paid',
                    'square_name'      => $rentResult['square_name'],
                    'rent_amount'      => $rentResult['rent_amount'],
                    'payer_join_order' => $rentResult['payer']['join_order'],
                    'payer_capital'    => $rentResult['payer']['capital'],
                    'owner_join_order' => $rentResult['owner']['join_order'],
                    'owner_name'       => $squareAction['owner_name'] ?? null,
                    'owner_capital'    => $rentResult['owner']['capital'],
                ];
            }
        }

        if (in_array($squareIndex, [7, 22, 36], true)) {
            $card       = $this->chanceCardRepository->drawTopCard($gameId);
            $cardEffect = $this->applyCardEffect($gameId, $joinOrder, $card, $squareIndex);
            $this->persistHeldCardIfNeeded($gameId, $joinOrder, $card, 'chance');

            $squareAction = ['type' => 'chance', 'card' => $card, 'effect' => $cardEffect];
            $playerName   = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);

            CardDrawn::dispatch($gameId, 'chance', $card, $joinOrder, $playerName, $cardEffect);
        } elseif (in_array($squareIndex, [2, 17, 33], true)) {
            $card       = $this->communityChestCardRepository->drawTopCard($gameId);
            $cardEffect = $this->applyCardEffect($gameId, $joinOrder, $card, $squareIndex);
            $this->persistHeldCardIfNeeded($gameId, $joinOrder, $card, 'community');

            $squareAction = ['type' => 'community', 'card' => $card, 'effect' => $cardEffect];
            $playerName   = $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder);

            CardDrawn::dispatch($gameId, 'community', $card, $joinOrder, $playerName, $cardEffect);
        }

        return $squareAction;
    }

    /**
     * Determine what action (if any) the rolling player must take after landing.
     *
     * Logic:
     *   1. Looks up the square's purchasable metadata; returns null for non-
     *      purchasable squares (GO, Tax, Jail, Chance, Community Chest, etc.).
     *   2. Checks whether the square is already owned in this game.
     *   3. If unowned, returns a 'purchase' action so the player may buy it.
    *   4. If owned by another player, returns a 'rent' action showing how much
    *      the landing player owes and to whom, unless the property is mortgaged.
     *   5. If owned by the landing player themselves, returns null (no action).
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the player who just landed.
     * @param  int  $squareIndex  The board square index they landed on.
     * @return array{type: string, square_name: string, price: int|null, rent: int, owner_join_order: int|null, owner_name: string|null}|null
     */
    private function computeSquareAction(int $gameId, int $joinOrder, int $squareIndex): ?array
    {
        $squareData = self::getSquareData($squareIndex);

        if ($squareData === null) {
            return null;
        }

        $ownerInfo = $this->propertyRepository->findOwnerBySquare($gameId, $squareIndex);

        if ($ownerInfo === null) {
            // Square is purchasable and unowned — offer to buy.
            return [
                'type'             => 'purchase',
                'square_name'      => $squareData['name'],
                'price'            => $squareData['price'],
                'rent'             => $squareData['rent'],
                'owner_join_order' => null,
                'owner_name'       => null,
            ];
        }

        if ($ownerInfo['owner_join_order'] === $joinOrder) {
            // Player owns this square already — nothing to do.
            return null;
        }

        if (!empty($ownerInfo['is_mortgaged'])) {
            // Property is mortgaged — no rent due, but show notification to all players.
            $players = collect($this->playerIconRepository->getPlayersForGame($gameId));
            $payerInfo = $players->firstWhere('join_order', $joinOrder);
            $ownerInfoRow = $players->firstWhere('join_order', $ownerInfo['owner_join_order']);

            MortgagedPropertyNotified::dispatch(
                $gameId,
                $joinOrder,
                $payerInfo['name'] ?? $this->playerIconRepository->getNameByJoinOrder($gameId, $joinOrder),
                $payerInfo['icon'] ?? null,
                $ownerInfo['owner_join_order'],
                $ownerInfo['owner_name'],
                $ownerInfoRow['icon'] ?? null,
                $squareData['name'],
            );

            return [
                'type'             => 'mortgaged',
                'square_name'      => $squareData['name'],
                'payer_join_order' => $joinOrder,
                'owner_join_order' => $ownerInfo['owner_join_order'],
                'owner_name'       => $ownerInfo['owner_name'],
            ];
        }

        // Square is owned by another player — player must pay rent.
        return [
            'type'             => 'rent',
            'square_name'      => $squareData['name'],
            'price'            => null,
            'rent'             => $squareData['rent'],
            'owner_join_order' => $ownerInfo['owner_join_order'],
            'owner_name'       => $ownerInfo['owner_name'],
        ];
    }

    /**
     * Resolve the destination square index for an advance_to card target string.
     *
     * Logic: Maps the named target used in the Chance deck definition to its
     * corresponding board square index. Only named advance_to targets (not
     * advance_to_nearest) are handled here.
     *
     * @param  string  $target  The target string from the card data.
     * @return int  The board square index (0–39).
     */
    private static function targetSquareForCard(string $target): int
    {
        return match ($target) {
            'illinois_avenue'  => 24,
            'st_charles_place' => 11,
            'reading_railroad' => 5,
            default            => 0, // 'go' and any unrecognised target → GO
        };
    }

    /**
     * Return the nearest candidate square moving forward from $from.
     *
     * Logic: For each candidate computes the forward distance
     * ($sq - $from + 40) % 40. A distance of 0 is skipped (already there).
     * Returns the candidate with the smallest positive forward distance.
     * If all candidates are equidistant (edge case) the first entry is returned.
     *
     * @param  list<int>  $squares  Candidate square indices.
     * @param  int        $from     Current square index.
     * @return int  The nearest candidate square index moving forward.
     */
    private static function nearestSquare(array $squares, int $from): int
    {
        $best     = $squares[0];
        $bestDist = PHP_INT_MAX;

        foreach ($squares as $sq) {
            $dist = ($sq - $from + 40) % 40;
            if ($dist > 0 && $dist < $bestDist) {
                $bestDist = $dist;
                $best     = $sq;
            }
        }

        return $best;
    }

    /**
     * Apply the game-state effect of a drawn card and return an effect descriptor.
     *
     * Logic: Dispatches on the card's action string to apply the appropriate change:
     *   - collect                  → adds the card amount to the roller's capital.
     *   - pay                      → deducts the card amount from the roller's capital.
     *   - advance_to               → moves to the named destination square; awards
     *                                $200 GO bonus when the move crosses square 0.
     *   - advance_to_nearest       → advances to the nearest railroad (5,15,25,35) or
     *                                utility (12,28) in the forward direction; awards
     *                                $200 GO bonus when wrapping past square 0.
     *   - go_to_jail               → moves the roller to square 10 with no GO bonus.
     *   - get_out_of_jail_free     → no immediate monetary or movement effect.
     *   - move_back                → moves backward N spaces; no GO bonus when going
     *                                backward per standard Monopoly rules.
     *   - pay_each_player          → charges the roller (amount × other-player count)
     *                                and credits each other player by the card amount.
     *   - collect_from_each_player → credits the roller and charges each other player.
     *   - property_repairs         → $0 cost while houses/hotels are unimplemented.
     *
     *   Movement cards call updateSquareIndex so the DB always holds the final
     *   authoritative position. Capital calls use adjustCapital for atomicity.
     *
     * @param  int    $gameId           The ID of the game.
     * @param  int    $rollerJoinOrder  The join_order of the player who drew the card.
     * @param  array  $card             The card data returned by drawTopCard.
     * @param  int    $cardSquareIndex  The board square the player landed on when drawing.
    * @return array<string, mixed>  Effect descriptor included in the API response and broadcast.
     */
    private function applyCardEffect(int $gameId, int $rollerJoinOrder, array $card, int $cardSquareIndex): array
    {
        $action = $card['action'] ?? '';

        switch ($action) {
            case 'collect':
                $amount     = (int) ($card['amount'] ?? 0);
                $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, $amount);
                return ['type' => 'collect', 'amount' => $amount, 'new_capital' => $newCapital];

            case 'pay':
                $amount = (int) ($card['amount'] ?? 0);

                return [
                    'type'            => 'pay',
                    'amount'          => $amount,
                    'required_amount' => $amount,
                    'payment_type'    => 'pay',
                ];

            case 'advance_to':
                $target       = $card['target'] ?? 'go';
                $targetSquare = self::targetSquareForCard($target);
                $steps        = ($targetSquare - $cardSquareIndex + 40) % 40;
                $passedGo     = ($cardSquareIndex + $steps) >= 40;
                $newCapital   = null;
                if ($passedGo) {
                    $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, 200);
                }
                $this->playerIconRepository->setJailState($gameId, $rollerJoinOrder, false);
                $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $targetSquare);
                $landingSquareAction = $this->resolveLandingSquareAction($gameId, $rollerJoinOrder, $targetSquare);
                return [
                    'type'             => 'advance_to',
                    'new_square_index' => $targetSquare,
                    'passed_go'        => $passedGo,
                    'go_bonus'         => $passedGo ? 200 : 0,
                    'new_capital'      => $newCapital,
                    'square_action'    => $landingSquareAction,
                ];

            case 'advance_to_nearest':
                $target       = $card['target'] ?? 'railroad';
                $candidates   = $target === 'railroad' ? [5, 15, 25, 35] : [12, 28];
                $targetSquare = self::nearestSquare($candidates, $cardSquareIndex);
                $steps        = ($targetSquare - $cardSquareIndex + 40) % 40;
                $passedGo     = ($cardSquareIndex + $steps) >= 40;
                $newCapital   = null;
                if ($passedGo) {
                    $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, 200);
                }
                $this->playerIconRepository->setJailState($gameId, $rollerJoinOrder, false);
                $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $targetSquare);

                // Special handling for utilities per Chance card rules: when
                // advancing to the nearest utility and it is owned, the roller
                // throws dice and pays owner 10× the roll. For other cases
                // (unowned, mortgaged, owned-by-self) fall back to normal flow.
                //
                // Additionally, for the "nearest railroad" card the roller
                // must pay the owner twice the rental to which they are
                // otherwise entitled. Implement that special-case here so the
                // doubled payment is applied immediately instead of falling
                // through to the normal landing/rent flow which charges only
                // the base rent.
                if ($target === 'utility') {
                    $ownerInfo = $this->propertyRepository->findOwnerBySquare($gameId, $targetSquare);

                    if ($ownerInfo === null) {
                        $landingSquareAction = $this->resolveLandingSquareAction($gameId, $rollerJoinOrder, $targetSquare);
                    } elseif ($ownerInfo['owner_join_order'] === $rollerJoinOrder) {
                        $landingSquareAction = $this->resolveLandingSquareAction($gameId, $rollerJoinOrder, $targetSquare);
                    } elseif (!empty($ownerInfo['is_mortgaged'])) {
                        $players = collect($this->playerIconRepository->getPlayersForGame($gameId));
                        $payerInfo = $players->firstWhere('join_order', $rollerJoinOrder);
                        $ownerInfoRow = $players->firstWhere('join_order', $ownerInfo['owner_join_order']);

                        MortgagedPropertyNotified::dispatch(
                            $gameId,
                            $rollerJoinOrder,
                            $payerInfo['name'] ?? $this->playerIconRepository->getNameByJoinOrder($gameId, $rollerJoinOrder),
                            $payerInfo['icon'] ?? null,
                            $ownerInfo['owner_join_order'],
                            $ownerInfo['owner_name'],
                            $ownerInfoRow['icon'] ?? null,
                            self::getSquareData($targetSquare)['name'],
                        );

                        $landingSquareAction = [
                            'type'             => 'mortgaged',
                            'square_name'      => self::getSquareData($targetSquare)['name'],
                            'payer_join_order' => $rollerJoinOrder,
                            'owner_join_order' => $ownerInfo['owner_join_order'],
                            'owner_name'       => $ownerInfo['owner_name'],
                        ];
                    } else {
                        $die1 = random_int(1, 6);
                        $die2 = random_int(1, 6);
                        $roll = $die1 + $die2;
                        $rentAmount = $roll * 10;

                        // Broadcast the dice roll so clients animate the dice
                        DiceRolled::dispatch($gameId, $die1, $die2, $roll, $rollerJoinOrder, $targetSquare);

                        $capital = $this->getPlayerCapital($gameId, $rollerJoinOrder);

                        if ($capital < $rentAmount) {
                            // Defer to the mortgage/payment dialog like normal rent
                            // landings instead of throwing an exception.
                            $landingSquareAction = [
                                'type'             => 'rent',
                                'square_name'      => self::getSquareData($targetSquare)['name'],
                                'rent'             => $rentAmount,
                                'owner_join_order' => $ownerInfo['owner_join_order'],
                                'owner_name'       => $ownerInfo['owner_name'],
                            ];
                        } else {
                            $payerName    = $this->playerIconRepository->getNameByJoinOrder($gameId, $rollerJoinOrder);
                            $payerCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, -$rentAmount);
                            $ownerCapital = $this->playerIconRepository->adjustCapital($gameId, $ownerInfo['owner_join_order'], $rentAmount);
                            $players      = collect($this->playerIconRepository->getPlayersForGame($gameId));
                            $payerInfo    = $players->firstWhere('join_order', $rollerJoinOrder);
                            $ownerInfoRow = $players->firstWhere('join_order', $ownerInfo['owner_join_order']);

                            RentPaid::dispatch(
                                $gameId,
                                $rollerJoinOrder,
                                $payerName,
                                $payerCapital,
                                $payerInfo['icon'] ?? null,
                                $ownerInfo['owner_join_order'],
                                $ownerInfo['owner_name'],
                                $ownerCapital,
                                $ownerInfoRow['icon'] ?? null,
                                $rentAmount,
                                self::getSquareData($targetSquare)['name'],
                            );

                            $landingSquareAction = [
                                'type'             => 'rent_paid',
                                'square_name'      => self::getSquareData($targetSquare)['name'],
                                'rent_amount'      => $rentAmount,
                                'payer_join_order' => $rollerJoinOrder,
                                'payer_capital'    => $payerCapital,
                                'owner_join_order' => $ownerInfo['owner_join_order'],
                                'owner_name'       => $ownerInfo['owner_name'],
                                'owner_capital'    => $ownerCapital,
                                'dice_roll'        => $roll,
                            ];
                        }
                    }
                } elseif ($target === 'railroad') {
                    $ownerInfo = $this->propertyRepository->findOwnerBySquare($gameId, $targetSquare);

                    if ($ownerInfo === null) {
                        $landingSquareAction = $this->resolveLandingSquareAction($gameId, $rollerJoinOrder, $targetSquare);
                    } elseif ($ownerInfo['owner_join_order'] === $rollerJoinOrder) {
                        $landingSquareAction = $this->resolveLandingSquareAction($gameId, $rollerJoinOrder, $targetSquare);
                    } elseif (!empty($ownerInfo['is_mortgaged'])) {
                        $players = collect($this->playerIconRepository->getPlayersForGame($gameId));
                        $payerInfo = $players->firstWhere('join_order', $rollerJoinOrder);
                        $ownerInfoRow = $players->firstWhere('join_order', $ownerInfo['owner_join_order']);

                        MortgagedPropertyNotified::dispatch(
                            $gameId,
                            $rollerJoinOrder,
                            $payerInfo['name'] ?? $this->playerIconRepository->getNameByJoinOrder($gameId, $rollerJoinOrder),
                            $payerInfo['icon'] ?? null,
                            $ownerInfo['owner_join_order'],
                            $ownerInfo['owner_name'],
                            $ownerInfoRow['icon'] ?? null,
                            self::getSquareData($targetSquare)['name'],
                        );

                        $landingSquareAction = [
                            'type'             => 'mortgaged',
                            'square_name'      => self::getSquareData($targetSquare)['name'],
                            'payer_join_order' => $rollerJoinOrder,
                            'owner_join_order' => $ownerInfo['owner_join_order'],
                            'owner_name'       => $ownerInfo['owner_name'],
                        ];
                    } else {
                        // Compute railroad rent based on how many railroads the
                        // owner currently holds (mortgaged railroads do not
                        // count). Base rent is 25, doubling for each additional
                        // railroad owned (25,50,100,200). The Chance card then
                        // requires the roller to pay twice that rental.
                        $ownerProps = $this->propertyRepository->findPlayerProperties($gameId, $ownerInfo['owner_join_order']);
                        $railroadIndices = [5, 15, 25, 35];
                        $ownedCount = 0;
                        foreach ($ownerProps as $p) {
                            if (in_array($p['square_index'], $railroadIndices, true) && !$p['is_mortgaged']) {
                                $ownedCount++;
                            }
                        }

                        $ownedCount = max(1, $ownedCount);
                        $baseRent = 25;
                        $calculatedRent = $baseRent * (2 ** ($ownedCount - 1));

                        // Chance card demands double the normal rent.
                        $rentAmount = $calculatedRent * 2;

                        $capital = $this->getPlayerCapital($gameId, $rollerJoinOrder);

                        if ($capital < $rentAmount) {
                            // Defer to mortgage/payment dialog for railroad double-rent
                            // when the payer cannot cover the amount.
                            $landingSquareAction = [
                                'type'             => 'rent',
                                'square_name'      => self::getSquareData($targetSquare)['name'],
                                'rent'             => $rentAmount,
                                'owner_join_order' => $ownerInfo['owner_join_order'],
                                'owner_name'       => $ownerInfo['owner_name'],
                            ];
                        } else {
                            $payerName    = $this->playerIconRepository->getNameByJoinOrder($gameId, $rollerJoinOrder);
                            $payerCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, -$rentAmount);
                            $ownerCapital = $this->playerIconRepository->adjustCapital($gameId, $ownerInfo['owner_join_order'], $rentAmount);
                            $players      = collect($this->playerIconRepository->getPlayersForGame($gameId));
                            $payerInfo    = $players->firstWhere('join_order', $rollerJoinOrder);
                            $ownerInfoRow = $players->firstWhere('join_order', $ownerInfo['owner_join_order']);

                            RentPaid::dispatch(
                                $gameId,
                                $rollerJoinOrder,
                                $payerName,
                                $payerCapital,
                                $payerInfo['icon'] ?? null,
                                $ownerInfo['owner_join_order'],
                                $ownerInfo['owner_name'],
                                $ownerCapital,
                                $ownerInfoRow['icon'] ?? null,
                                $rentAmount,
                                self::getSquareData($targetSquare)['name'],
                            );

                            $landingSquareAction = [
                                'type'             => 'rent_paid',
                                'square_name'      => self::getSquareData($targetSquare)['name'],
                                'rent_amount'      => $rentAmount,
                                'payer_join_order' => $rollerJoinOrder,
                                'payer_capital'    => $payerCapital,
                                'owner_join_order' => $ownerInfo['owner_join_order'],
                                'owner_name'       => $ownerInfo['owner_name'],
                                'owner_capital'    => $ownerCapital,
                            ];
                        }
                    }
                } else {
                    $landingSquareAction = $this->resolveLandingSquareAction($gameId, $rollerJoinOrder, $targetSquare);
                }
                return [
                    'type'             => 'advance_to_nearest',
                    'target'           => $target,
                    'new_square_index' => $targetSquare,
                    'passed_go'        => $passedGo,
                    'go_bonus'         => $passedGo ? 200 : 0,
                    'new_capital'      => $newCapital,
                    'square_action'    => $landingSquareAction,
                ];

            case 'go_to_jail':
                $this->sendPlayerToJail($gameId, $rollerJoinOrder);
                return ['type' => 'go_to_jail', 'new_square_index' => 10, 'square_action' => null];

            case 'get_out_of_jail_free':
                return ['type' => 'get_out_of_jail_free'];

            case 'move_back':
                $spaces    = (int) ($card['spaces'] ?? 3);
                $newSquare = ($cardSquareIndex - $spaces + 40) % 40;
                $this->playerIconRepository->setJailState($gameId, $rollerJoinOrder, false);
                $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $newSquare);
                $landingSquareAction = $this->resolveLandingSquareAction($gameId, $rollerJoinOrder, $newSquare);
                return [
                    'type'             => 'move_back',
                    'spaces'           => $spaces,
                    'new_square_index' => $newSquare,
                    'square_action'    => $landingSquareAction,
                ];

            case 'pay_each_player':
                $amount    = (int) ($card['amount'] ?? 0);
                $allOrders = $this->playerIconRepository->getAllJoinOrders($gameId);
                $others    = array_values(array_filter($allOrders, fn ($jo) => $jo !== $rollerJoinOrder));

                return [
                    'type'               => 'pay_each_player',
                    'amount'             => $amount,
                    'required_amount'    => $amount * count($others),
                    'payment_type'       => 'pay_each_player',
                    'other_player_count' => count($others),
                ];

            case 'collect_from_each_player':
                $amount     = (int) ($card['amount'] ?? 0);
                $allOrders  = $this->playerIconRepository->getAllJoinOrders($gameId);
                $others     = array_values(array_filter($allOrders, fn ($jo) => $jo !== $rollerJoinOrder));
                $totalGain  = $amount * count($others);
                $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, $totalGain);
                $otherCaps  = [];
                foreach ($others as $jo) {
                    $otherCaps[] = [
                        'join_order' => $jo,
                        'capital'    => $this->playerIconRepository->adjustCapital($gameId, $jo, -$amount),
                    ];
                }
                return [
                    'type'                  => 'collect_from_each_player',
                    'amount'                => $amount,
                    'new_capital'           => $newCapital,
                    'other_player_capitals' => $otherCaps,
                ];

            case 'property_repairs':
                // Compute total repair cost based on owned properties' buildings.
                $houseCost = (int) ($card['house_cost'] ?? 0);
                $hotelCost = (int) ($card['hotel_cost'] ?? 0);

                // Gather the player's owned properties and their building counts.
                $owned = $this->propertyRepository->findPlayerProperties($gameId, $rollerJoinOrder);

                $totalHouses = 0;
                $totalHotels = 0;

                foreach ($owned as $p) {
                    $totalHouses += $p['houses_count'] ?? 0;
                    $totalHotels += $p['has_hotel'] ? 1 : 0;
                }

                $requiredAmount = ($houseCost * $totalHouses) + ($hotelCost * $totalHotels);

                return [
                    'type'            => 'property_repairs',
                    'house_cost'      => $houseCost,
                    'hotel_cost'      => $hotelCost,
                    'houses_count'    => $totalHouses,
                    'hotels_count'    => $totalHotels,
                    'required_amount' => $requiredAmount,
                    'payment_type'    => $requiredAmount === 0 ? null : 'pay',
                ];

            default:
                return [];
        }
    }

    /**
     * Persist a drawn get-out-of-jail-free card in the holder's hand.
     *
     * Logic: Checks whether the drawn card action is get_out_of_jail_free.
     * When true, updates the corresponding game deck pivot row with
     * holder_join_order so the card remains assigned to the player after page
     * refreshes and across reconnects.
     *
     * @param  int    $gameId           The ID of the game.
     * @param  int    $rollerJoinOrder  The join_order of the drawing player.
     * @param  array  $card             The drawn card payload.
     * @param  string $deckType         The deck source: 'chance' or 'community'.
     * @return void
     */
    private function persistHeldCardIfNeeded(int $gameId, int $rollerJoinOrder, array $card, string $deckType): void
    {
        if (($card['action'] ?? null) !== 'get_out_of_jail_free') {
            return;
        }

        $cardId = (int) ($card['id'] ?? 0);

        if ($cardId <= 0) {
            return;
        }

        if ($deckType === 'chance') {
            $this->chanceCardRepository->assignCardToPlayer($gameId, $cardId, $rollerJoinOrder);

            return;
        }

        $this->communityChestCardRepository->assignCardToPlayer($gameId, $cardId, $rollerJoinOrder);
    }

    /**
     * Mark a player as jailed and move their token to the Jail corner.
     *
     * Logic: Persists square_index = 10 and jailed state using a shared helper
     * so all go-to-jail scenarios reset jail counters identically.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $joinOrder  The join_order of the player being jailed.
     * @return void
     */
    private function sendPlayerToJail(int $gameId, int $joinOrder): void
    {
        $this->playerIconRepository->updateSquareIndex($gameId, $joinOrder, 10);
        $this->playerIconRepository->setJailState($gameId, $joinOrder, true);
    }

    /**
     * Determine whether a square-action payload contains a go_to_jail result.
     *
     * Logic: Recursively inspects action type and nested effect/square_action
     * payloads so card chains that eventually send a player to jail are
     * detected and can trigger immediate turn advancement.
     *
     * @param  array<string, mixed>|null  $action  The resolved square action payload.
     * @return bool
     */
    private function containsGoToJailAction(?array $action): bool
    {
        if (!is_array($action)) {
            return false;
        }

        if (($action['type'] ?? null) === 'go_to_jail') {
            return true;
        }

        $effect = $action['effect'] ?? null;
        if (is_array($effect) && $this->containsGoToJailAction($effect)) {
            return true;
        }

        $squareAction = $action['square_action'] ?? null;

        return is_array($squareAction) && $this->containsGoToJailAction($squareAction);
    }

    /**
     * Resolve a deferred card payment and apply any selected mortgages first.
     *
     * Logic: Runs the mortgage and payment mutation in a transaction so the
     * card can be finalized atomically. Supports both flat card charges and
     * per-opponent card charges, returning the updated balances for the payer
     * and any other affected players.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $joinOrder  The join_order of the paying player.
     * @param  string  $cardPaymentType  The card payment action name.
     * @param  int  $cardPaymentAmount  The amount per payment unit.
     * @param  array<int, int>  $mortgageSquareIndexes  Selected properties to mortgage.
     * @return array<string, mixed>
     */
    private function resolveCardPayment(
        int $gameId,
        int $joinOrder,
        string $cardPaymentType,
        int $cardPaymentAmount,
        array $mortgageSquareIndexes
    ): array {
        try {
            return DB::transaction(function () use ($gameId, $joinOrder, $cardPaymentType, $cardPaymentAmount, $mortgageSquareIndexes): array {
                $this->applySessionMortgages($gameId, $joinOrder, $mortgageSquareIndexes);

                if ($cardPaymentType === 'pay') {
                    $currentCapital = $this->getPlayerCapital($gameId, $joinOrder);

                    if ($currentCapital < $cardPaymentAmount) {
                        throw new InvalidArgumentException('You do not have enough capital to pay this card payment.');
                    }

                    $payerCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$cardPaymentAmount);

                    return [
                        'payer' => [
                            'join_order' => $joinOrder,
                            'capital'    => $payerCapital,
                        ],
                        'payment_type' => $cardPaymentType,
                        'amount'       => $cardPaymentAmount,
                    ];
                }

                $allOrders = $this->playerIconRepository->getAllJoinOrders($gameId);
                $others = array_values(array_filter($allOrders, fn ($playerJoinOrder) => $playerJoinOrder !== $joinOrder));
                $totalDue = $cardPaymentAmount * count($others);
                $currentCapital = $this->getPlayerCapital($gameId, $joinOrder);

                if ($currentCapital < $totalDue) {
                    throw new InvalidArgumentException('You do not have enough capital to pay this card payment.');
                }

                $payerCapital = $this->playerIconRepository->adjustCapital($gameId, $joinOrder, -$totalDue);
                $otherPlayerCapitals = [];

                foreach ($others as $otherJoinOrder) {
                    $otherPlayerCapitals[] = [
                        'join_order' => $otherJoinOrder,
                        'capital'    => $this->playerIconRepository->adjustCapital($gameId, $otherJoinOrder, $cardPaymentAmount),
                    ];
                }

                return [
                    'payer' => [
                        'join_order' => $joinOrder,
                        'capital'    => $payerCapital,
                    ],
                    'other_player_capitals' => $otherPlayerCapitals,
                    'payment_type'          => $cardPaymentType,
                    'amount'                => $cardPaymentAmount,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('Failed to resolve card payment', [
                'game_id'                => $gameId,
                'join_order'             => $joinOrder,
                'payment_type'           => $cardPaymentType,
                'payment_amount'         => $cardPaymentAmount,
                'mortgage_square_indexes' => $mortgageSquareIndexes,
                'exception'              => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Return the static purchase/rent metadata for a board square by index.
     *
     * Logic: Maps square indices 0–39 to their purchasable metadata
     * (name, price, rent). Only the 22 purchasable squares (properties,
     * railroads, utilities) are included; all other indices return null.
     * Rent values follow standard Monopoly base rent (no houses/hotels).
     *
     * @param  int  $squareIndex  The board square index (0–39).
     * @return array{name: string, price: int, rent: int}|null
     */
    private static function getSquareData(int $squareIndex): ?array
    {
        return match ($squareIndex) {
            1  => ['name' => 'Mediterranean Ave',      'price' => 60,  'rent' => 2],
            3  => ['name' => 'Baltic Ave',              'price' => 60,  'rent' => 4],
            5  => ['name' => 'Reading Railroad',        'price' => 200, 'rent' => 25],
            6  => ['name' => 'Oriental Ave',            'price' => 100, 'rent' => 6],
            8  => ['name' => 'Vermont Ave',             'price' => 100, 'rent' => 6],
            9  => ['name' => 'Connecticut Ave',         'price' => 120, 'rent' => 8],
            11 => ['name' => 'St. Charles Place',       'price' => 140, 'rent' => 10],
            12 => ['name' => 'Electric Company',        'price' => 150, 'rent' => 50],
            13 => ['name' => 'States Ave',              'price' => 140, 'rent' => 10],
            14 => ['name' => 'Virginia Ave',            'price' => 160, 'rent' => 12],
            15 => ['name' => 'Pennsylvania Railroad',   'price' => 200, 'rent' => 25],
            16 => ['name' => 'St. James Place',         'price' => 180, 'rent' => 14],
            18 => ['name' => 'Tennessee Ave',           'price' => 180, 'rent' => 14],
            19 => ['name' => 'New York Ave',            'price' => 200, 'rent' => 16],
            21 => ['name' => 'Kentucky Ave',            'price' => 220, 'rent' => 18],
            23 => ['name' => 'Indiana Ave',             'price' => 220, 'rent' => 18],
            24 => ['name' => 'Illinois Ave',            'price' => 240, 'rent' => 20],
            25 => ['name' => 'B&O Railroad',            'price' => 200, 'rent' => 25],
            26 => ['name' => 'Atlantic Ave',            'price' => 260, 'rent' => 22],
            27 => ['name' => 'Ventnor Ave',             'price' => 260, 'rent' => 22],
            28 => ['name' => 'Water Works',             'price' => 150, 'rent' => 50],
            29 => ['name' => 'Marvin Gardens',          'price' => 280, 'rent' => 24],
            31 => ['name' => 'Pacific Ave',             'price' => 300, 'rent' => 26],
            32 => ['name' => 'North Carolina Ave',      'price' => 300, 'rent' => 26],
            34 => ['name' => 'Pennsylvania Ave',        'price' => 320, 'rent' => 28],
            35 => ['name' => 'Short Line Railroad',     'price' => 200, 'rent' => 25],
            37 => ['name' => 'Park Place',              'price' => 350, 'rent' => 35],
            39 => ['name' => 'Boardwalk',               'price' => 400, 'rent' => 50],
            default => null,
        };
    }

    /**
     * Cyclically advance the turn from the given player to the next player.
     *
     * Logic:
     *   1. Loads the game and verifies the caller's join_order matches
     *      current_turn_join_order. Throws if it is not their turn.
    *   2. Enforces jail-release payment before ending turn on/after the third
    *      failed jailed roll attempt. Players in jail with jail_turns >= 3 and
    *      no paid release must pay $50 before their turn can advance.
    *   3. Computes the next join_order by finding the caller's position in the
     *      sorted join_order list and wrapping around to index 0 after the last
     *      player.
    *   4. Calls advanceTurn() with an optimistic WHERE guard; if a concurrent
     *      request already advanced the turn the guard returns false and an
     *      exception is thrown.
    *   5. Dispatches the TurnAdvanced broadcast event so all connected clients
     *      update their turn indicator reactively.
    *   6. Returns the new current_turn_join_order.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player ending their turn.
     * @return array{current_turn_join_order: int}
     *
     * @throws InvalidArgumentException When it is not the caller's turn or the game is not found.
     */
    private function advanceTurnCyclic(int $gameId, int $joinOrder): array
    {
        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $joinOrder) {
            throw new InvalidArgumentException('It is not your turn.');
        }

        if ((int) ($game->consecutive_doubles_count ?? 0) > 0) {
            throw new InvalidArgumentException('You rolled doubles and must roll again.');
        }

        $nextJoinOrder = $this->advanceTurnFromJoinOrder($gameId, $joinOrder);

        return [
            'current_turn_join_order' => $nextJoinOrder,
        ];
    }
}
