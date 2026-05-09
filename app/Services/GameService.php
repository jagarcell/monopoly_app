<?php

namespace App\Services;

use App\Events\DiceRolled;
use App\Events\TokenMoved;
use App\Events\TurnAdvanced;
use App\Models\Game;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GameInvitationRepository;
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
     * correct final square. This is called by the rolling player's board after
     * the local step-by-step animation completes.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $userId  The authenticated user's ID.
     * @return array{join_order: int, square_index: int}
     *
     * @throws InvalidArgumentException When the user is not a game participant.
     */
    public function notifyTokenMovedForUser(int $gameId, int $userId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForUser($gameId, $userId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $squareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);

        TokenMoved::dispatch($gameId, $joinOrder, $squareIndex);

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
     * correct final square. This is called by the guest's board after the local
     * step-by-step animation completes.
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The GameInvitation primary key of the guest.
     * @return array{join_order: int, square_index: int}
     *
     * @throws InvalidArgumentException When the guest is not a participant.
     */
    public function notifyTokenMovedForGuest(int $gameId, int $invitationId): array
    {
        $joinOrder = $this->playerIconRepository->getJoinOrderForGuest($gameId, $invitationId);

        if ($joinOrder === null) {
            throw new InvalidArgumentException('You are not a participant of this game.');
        }

        $squareIndex = $this->playerIconRepository->getSquareIndexForPlayer($gameId, $joinOrder);

        TokenMoved::dispatch($gameId, $joinOrder, $squareIndex);

        return [
            'join_order'   => $joinOrder,
            'square_index' => $squareIndex,
        ];
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
     *   5. Returns die values, the unchanged current_turn_join_order, and the new
     *      square_index so the local client can start the animation immediately.
     *
     * @param  int  $gameId           The ID of the game.
     * @param  int  $rollerJoinOrder  The join_order of the player attempting to roll.
     * @return array{die1: int, die2: int, total: int, current_turn_join_order: int, square_index: int}
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

        $this->playerIconRepository->updateSquareIndex($gameId, $rollerJoinOrder, $newSquareIndex);

        // Turn does not advance on roll — the player must click Done to pass the turn.
        DiceRolled::dispatch($gameId, $die1, $die2, $total, $rollerJoinOrder, $newSquareIndex);

        return [
            'die1'                    => $die1,
            'die2'                    => $die2,
            'total'                   => $total,
            'current_turn_join_order' => $rollerJoinOrder,
            'square_index'            => $newSquareIndex,
        ];
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
        }

        TurnAdvanced::dispatch($gameId, $nextJoinOrder);

        return [
            'current_turn_join_order' => $nextJoinOrder,
        ];
    }
}
