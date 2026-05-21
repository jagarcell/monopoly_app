<?php

namespace App\Services;

use App\Events\CardAccepted;
use App\Events\CardDrawn;
use App\Events\DiceRolled;
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
    ) {}

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
     * card with the lowest sort_order and moves it to the bottom (sort_order 16)
     * so the deck cycles correctly over repeated plays.
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
     * the card with the lowest sort_order and moves it to the bottom (sort_order 16)
     * so the deck cycles correctly over repeated plays.
     *
     * @param  int  $gameId  The ID of the game.
     * @return array<string, mixed>
     */
    public function drawCommunityChestCard(int $gameId): array
    {
        return $this->communityChestCardRepository->drawTopCard($gameId);
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
     *
     * @param  int   $gameId    The ID of the game.
     * @param  int   $userId    The authenticated user's ID.
     * @param  bool  $backward  Whether the token moved backward (default false).
     * @return array{join_order: int, square_index: int}
     *
     * @throws InvalidArgumentException When the user is not a game participant.
     */
    public function notifyTokenMovedForUser(int $gameId, int $userId, bool $backward = false): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $squareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);

        TokenMoved::dispatch($gameId, $joinOrder, $squareIndex, $backward);

        return [
            'join_order'   => $joinOrder,
            'square_index' => $squareIndex,
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
     *
     * @param  int   $gameId        The ID of the game.
     * @param  int   $invitationId  The GameInvitation primary key of the guest.
     * @param  bool  $backward      Whether the token moved backward (default false).
     * @return array{join_order: int, square_index: int}
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function notifyTokenMovedForGuest(int $gameId, int $invitationId, bool $backward = false): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $squareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);

        TokenMoved::dispatch($gameId, $joinOrder, $squareIndex, $backward);

        return [
            'join_order'   => $joinOrder,
            'square_index' => $squareIndex,
        ];
    }

    /**
     * Signal that the drawing player has accepted their card.
     *
     * Logic: Looks up the calling user's join_order to confirm they are a
     * participant, then dispatches a CardAccepted broadcast event on the game
     * channel so all connected observer boards can auto-close their card-drawn
     * notification.  Only participation is validated — no card state is changed
     * because the card effect was already applied during rollDice.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $userId  The authenticated user's ID.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the user is not a game participant.
     */
    public function acceptCardForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        CardAccepted::dispatch($gameId);

        return [];
    }

    /**
     * Signal that a guest drawing player has accepted their card.
     *
     * Logic: Looks up the guest's join_order via their invitation_id to confirm
     * participation, then dispatches a CardAccepted broadcast event so all
     * connected observer boards can auto-close their card-drawn notification.
     * Only participation is validated — no card state is changed because the
     * card effect was already applied during rollDiceForGuest.
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The GameInvitation primary key of the guest.
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function acceptCardForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        CardAccepted::dispatch($gameId);

        return [];
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
     * @return array{die1: int, die2: int, total: int, current_turn_join_order: int}
     *
     * @throws InvalidArgumentException When the user is not a game participant or it is not their turn.
     */
    public function rollDiceForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->rollDice($gameId, $joinOrder);
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
     * @return array{die1: int, die2: int, total: int, current_turn_join_order: int}
     *
     * @throws InvalidArgumentException When the guest is not a participant or it is not their turn.
     */
    public function rollDiceForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->rollDice($gameId, $joinOrder);
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
     *      The turn does NOT advance here — the player must click Done to pass
     *      the turn to the next player.
     *   5. When the new square_index is a Chance square (7, 22, 36) or Community
     *      Chest square (2, 17, 33), automatically draws the top card from the
     *      appropriate deck, overwrites $squareAction with the card data, and
     *      dispatches the CardDrawn broadcast event so all connected boards can
     *      reveal the drawn card simultaneously.
     *   6. Returns die values, the unchanged current_turn_join_order, the new
    *      square_index, and the square_action so the local client can start the
    *      animation and show the card reveal immediately.
    *   7. When the landed square_action is rent owed to another player, rent is
    *      charged immediately on the server (before any UI acknowledgement),
    *      RentPaid is broadcast, and square_action is transformed to
    *      rent_paid so the frontend only needs to show the confirmation dialog.
     *
     * @param  int  $gameId           The ID of the game.
     * @param  int  $rollerJoinOrder  The join_order of the player attempting to roll.
     * @return array{die1: int, die2: int, total: int, current_turn_join_order: int, square_index: int, square_action: array|null}
     *
     * @throws InvalidArgumentException When it is not the caller's turn or the game is not found.
     */
    private function rollDice(int $gameId, int $rollerJoinOrder): array
    {
        $game = $this->gameRepository->findById($gameId);

        if ($game === null) {
            throw new InvalidArgumentException('Game not found.');
        }

        if ((int) $game->current_turn_join_order !== $rollerJoinOrder) {
            throw new InvalidArgumentException('It is not your turn to roll.');
        }

        $die1  = random_int(1, 6);
        $die2  = random_int(1, 6);
        $total = $die1 + $die2;

        // Advance the player's board position by the dice total, wrapping at 40.
        $currentSquareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $rollerJoinOrder);
        $newSquareIndex     = ($currentSquareIndex + $total) % 40;

        // A player collects $200 when they pass through or land on GO (square 0),
        // i.e. whenever the raw sum of current position and dice total crosses 40.
        $passedGo   = ($currentSquareIndex + $total) >= 40;
        $newCapital = null;
        if ($passedGo) {
            $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, 200);
        }

        $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $newSquareIndex);

        // Persist dice values and advance the turn phase to 'done' so a page
        // refresh correctly restores the dice display and Roll/Done button state.
        $this->gameRepository->saveDiceRoll($gameId, $die1, $die2);

        // Turn does not advance on roll — the player must click Done to pass the turn.
        DiceRolled::dispatch($gameId, $die1, $die2, $total, $rollerJoinOrder, $newSquareIndex);

        // Compute what action the player must take now that they have landed.
        $squareAction = $this->computeSquareAction($gameId, $rollerJoinOrder, $newSquareIndex);

        // Rent is resolved server-side immediately so a refresh cannot skip it.
        if (($squareAction['type'] ?? null) === 'rent') {
            $rentResult  = $this->payRent($gameId, $rollerJoinOrder, $newSquareIndex);
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

        // Auto-draw a card when landing on a Chance or Community Chest square.
        // The drawn card overwrites $squareAction. The card effect is applied
        // immediately (capital and/or token movement) so the DB is always in the
        // authoritative final state. Both the effect descriptor and the card are
        // broadcast so observer boards can update reactively.
        if (in_array($newSquareIndex, [7, 22, 36], true)) {
            $card         = $this->chanceCardRepository->drawTopCard($gameId);
            $cardEffect   = $this->applyCardEffect($gameId, $rollerJoinOrder, $card, $newSquareIndex);
            $this->persistHeldCardIfNeeded($gameId, $rollerJoinOrder, $card, 'chance');
            $squareAction = ['type' => 'chance', 'card' => $card, 'effect' => $cardEffect];
            $rollerName   = $this->playerIconRepository->getNameByJoinOrder($gameId, $rollerJoinOrder);
            CardDrawn::dispatch($gameId, 'chance', $card, $rollerJoinOrder, $rollerName, $cardEffect);
        } elseif (in_array($newSquareIndex, [2, 17, 33], true)) {
            $card         = $this->communityChestCardRepository->drawTopCard($gameId);
            $cardEffect   = $this->applyCardEffect($gameId, $rollerJoinOrder, $card, $newSquareIndex);
            $this->persistHeldCardIfNeeded($gameId, $rollerJoinOrder, $card, 'community');
            $squareAction = ['type' => 'community', 'card' => $card, 'effect' => $cardEffect];
            $rollerName   = $this->playerIconRepository->getNameByJoinOrder($gameId, $rollerJoinOrder);
            CardDrawn::dispatch($gameId, 'community', $card, $rollerJoinOrder, $rollerName, $cardEffect);
        }

        return [
            'die1'                    => $die1,
            'die2'                    => $die2,
            'total'                   => $total,
            'current_turn_join_order' => $rollerJoinOrder,
            'square_index'            => $newSquareIndex,
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
     * purchaseProperty().
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $userId       The authenticated user's ID.
     * @param  int  $squareIndex  The board square index the player is purchasing.
    * @return array{join_order: int, capital: int, property: array{square_index: int, name: string}}
     *
     * @throws InvalidArgumentException When the player is not a participant.
     */
    public function purchasePropertyForUser(int $gameId, int $userId, int $squareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->purchaseProperty($gameId, $joinOrder, $squareIndex);
    }

    /**
     * Purchase a property on behalf of a guest player.
     *
     * Logic: Resolves the guest's join_order via invitation_id, then delegates
     * to purchaseProperty().
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The GameInvitation primary key for the guest.
     * @param  int  $squareIndex   The board square index the player is purchasing.
    * @return array{join_order: int, capital: int, property: array{square_index: int, name: string}}
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function purchasePropertyForGuest(int $gameId, int $invitationId, int $squareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->purchaseProperty($gameId, $joinOrder, $squareIndex);
    }

    /**
     * Pay rent on behalf of an authenticated player.
     *
     * Logic: Resolves the caller's join_order, then delegates to payRent().
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $userId       The authenticated user's ID.
     * @param  int  $squareIndex  The board square index where rent is owed.
     * @return array{
     *     payer: array{join_order: int, capital: int},
     *     owner: array{join_order: int, capital: int},
     *     rent_amount: int,
     *     square_name: string,
     * }
     *
     * @throws InvalidArgumentException When the player is not a participant.
     */
    public function payRentForUser(int $gameId, int $userId, int $squareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->payRent($gameId, $joinOrder, $squareIndex);
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
     * @return array{
     *     payer: array{join_order: int, capital: int},
     *     owner: array{join_order: int, capital: int},
     *     rent_amount: int,
     *     square_name: string,
     * }
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function payRentForGuest(int $gameId, int $invitationId, int $squareIndex): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        return $this->payRent($gameId, $joinOrder, $squareIndex);
    }

    /**
     * Core property purchase logic.
     *
     * Logic:
     *   1. Validates the square is purchasable and has a defined price.
     *   2. Verifies the square is currently unowned; throws if already owned.
     *   3. Records ownership in game_properties.
     *   4. Deducts the purchase price from the buyer's capital.
     *   5. Returns the buyer's join_order and updated capital.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the purchasing player.
     * @param  int  $squareIndex  The board square index being purchased.
    * @return array{join_order: int, capital: int, property: array{square_index: int, name: string}}
     *
     * @throws InvalidArgumentException When the square is not purchasable or already owned.
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
     * Logic:
     *   1. Validates the square is purchasable and has a defined rent.
     *   2. Verifies the square is currently owned; throws if unowned.
     *   3. Deducts the rent amount from the payer's capital.
     *   4. Adds the rent amount to the property owner's capital.
     *   5. Returns both players' updated capitals.
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
     * @throws InvalidArgumentException When the square has no rent or is unowned.
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

        $rentAmount   = $squareData['rent'];
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
     * Determine what action (if any) the rolling player must take after landing.
     *
     * Logic:
     *   1. Looks up the square's purchasable metadata; returns null for non-
     *      purchasable squares (GO, Tax, Jail, Chance, Community Chest, etc.).
     *   2. Checks whether the square is already owned in this game.
     *   3. If unowned, returns a 'purchase' action so the player may buy it.
     *   4. If owned by another player, returns a 'rent' action showing how much
     *      the landing player owes and to whom.
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
                $amount     = (int) ($card['amount'] ?? 0);
                $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, -$amount);
                return ['type' => 'pay', 'amount' => $amount, 'new_capital' => $newCapital];

            case 'advance_to':
                $target       = $card['target'] ?? 'go';
                $targetSquare = self::targetSquareForCard($target);
                $steps        = ($targetSquare - $cardSquareIndex + 40) % 40;
                $passedGo     = ($cardSquareIndex + $steps) >= 40;
                $newCapital   = null;
                if ($passedGo) {
                    $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, 200);
                }
                $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $targetSquare);
                return [
                    'type'             => 'advance_to',
                    'new_square_index' => $targetSquare,
                    'passed_go'        => $passedGo,
                    'go_bonus'         => $passedGo ? 200 : 0,
                    'new_capital'      => $newCapital,
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
                $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $targetSquare);
                return [
                    'type'             => 'advance_to_nearest',
                    'target'           => $target,
                    'new_square_index' => $targetSquare,
                    'passed_go'        => $passedGo,
                    'go_bonus'         => $passedGo ? 200 : 0,
                    'new_capital'      => $newCapital,
                ];

            case 'go_to_jail':
                $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, 10);
                return ['type' => 'go_to_jail', 'new_square_index' => 10];

            case 'get_out_of_jail_free':
                return ['type' => 'get_out_of_jail_free'];

            case 'move_back':
                $spaces    = (int) ($card['spaces'] ?? 3);
                $newSquare = ($cardSquareIndex - $spaces + 40) % 40;
                $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $newSquare);
                return ['type' => 'move_back', 'spaces' => $spaces, 'new_square_index' => $newSquare];

            case 'pay_each_player':
                $amount     = (int) ($card['amount'] ?? 0);
                $allOrders  = $this->playerIconRepository->getAllJoinOrders($gameId);
                $others     = array_values(array_filter($allOrders, fn ($jo) => $jo !== $rollerJoinOrder));
                $totalPaid  = $amount * count($others);
                $newCapital = $this->playerIconRepository->adjustCapital($gameId, $rollerJoinOrder, -$totalPaid);
                $otherCaps  = [];
                foreach ($others as $jo) {
                    $otherCaps[] = [
                        'join_order' => $jo,
                        'capital'    => $this->playerIconRepository->adjustCapital($gameId, $jo, $amount),
                    ];
                }
                return [
                    'type'                  => 'pay_each_player',
                    'amount'                => $amount,
                    'new_capital'           => $newCapital,
                    'other_player_capitals' => $otherCaps,
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
                // No houses or hotels are implemented yet — repair cost is $0.
                return ['type' => 'property_repairs', 'amount' => 0, 'new_capital' => null];

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
     *   2. Computes the next join_order by finding the caller's position in the
     *      sorted join_order list and wrapping around to index 0 after the last
     *      player.
     *   3. Calls advanceTurn() with an optimistic WHERE guard; if a concurrent
     *      request already advanced the turn the guard returns false and an
     *      exception is thrown.
     *   4. Dispatches the TurnAdvanced broadcast event so all connected clients
     *      update their turn indicator reactively.
     *   5. Returns the new current_turn_join_order.
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

        $joinOrders    = $this->gameRepository->getPlayerJoinOrders($gameId);
        $idx           = array_search($joinOrder, $joinOrders, true);
        $nextIdx       = ($idx + 1) % count($joinOrders);
        $nextJoinOrder = $joinOrders[$nextIdx];

        // When there is only one player the turn stays with them; no DB write needed.
        if ($nextJoinOrder !== $joinOrder) {
            $advanced = $this->gameRepository->advanceTurn($gameId, $joinOrder, $nextJoinOrder);

            if (!$advanced) {
                throw new InvalidArgumentException('The turn was already advanced by a concurrent request.');
            }
        } else {
            // Single-player game: turn stays with the same player. Reset the phase
            // so the Roll button appears again on the next turn (or page refresh).
            $this->gameRepository->resetTurnPhase($gameId);
        }

        TurnAdvanced::dispatch($gameId, $nextJoinOrder);

        return [
            'current_turn_join_order' => $nextJoinOrder,
        ];
    }
}
