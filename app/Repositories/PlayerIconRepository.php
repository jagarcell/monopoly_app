<?php

namespace App\Repositories;

use App\Models\PlayerIcon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlayerIconRepository
{
    /**
     * Create a new repository instance with held-card dependencies.
     *
     * Logic: Injects ChanceCardRepository and CommunityChestCardRepository so
     * player payload hydration can include held cards grouped by join_order.
     *
     * @param  ChanceCardRepository          $chanceCardRepository
     * @param  CommunityChestCardRepository  $communityChestCardRepository
     * @return void
     */
    public function __construct(
        private readonly ChanceCardRepository $chanceCardRepository,
        private readonly CommunityChestCardRepository $communityChestCardRepository,
    ) {}

    /**
     * Return all player icons ordered by sort_order ascending.
     *
     * Logic: Selects only the columns needed for the icon picker UI and orders
     * the result by sort_order so the display sequence matches the catalogue
     * definition without requiring the caller to sort.
     *
     * @return Collection<int, PlayerIcon>
     */
    public function getAll(): Collection
    {
        return PlayerIcon::select(['id', 'name', 'image_url', 'sort_order'])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Return player icons not yet assigned in a given game.
     *
     * Logic: Selects only columns required by the icon-picker UI and excludes
     * any icon whose ID already appears in game_player_icons for the given
     * game_id, so the list reflects real-time availability. Ordered by
     * sort_order for consistent display.
     *
     * @param  int  $gameId  The ID of the game whose taken icons are excluded.
     * @return Collection<int, PlayerIcon>
     */
    public function getAvailableForGame(int $gameId): Collection
    {
        $taken = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->pluck('player_icon_id');

        return PlayerIcon::select(['id', 'name', 'image_url', 'sort_order'])
            ->whereNotIn('id', $taken)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Assign a player icon to a user (or guest via invitation) within a game.
     *
     * Logic: Computes the next join_order value as MAX(join_order) + 1 for the
     * given game (defaulting to 1 when no rows exist yet) so that each player
     * receives a unique, monotonically increasing position. Inserts a row into
     * the game_player_icons pivot table with the computed join_order. For
     * authenticated creators, user_id is set and invitation_id is null.
     * For guests, user_id is null and invitation_id links back to the
     * GameInvitation row. Uses insertOrIgnore so duplicate calls for the same
     * (game_id, user_id) pair are safe. The unique constraint on
     * (game_id, player_icon_id) prevents two players from sharing the same
     * icon — that conflict surfaces as a QueryException for the caller to handle.
     *
     * @param  int       $gameId        The ID of the game.
     * @param  int|null  $userId        The authenticated user's ID, or null for guests.
     * @param  int       $playerIconId  The ID of the chosen PlayerIcon.
     * @param  int|null  $invitationId  The GameInvitation ID for guest players, or null.
     * @return void
     */
    public function assignToGame(int $gameId, ?int $userId, int $playerIconId, ?int $invitationId = null): void
    {
        $nextOrder = (int) DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->max('join_order') + 1;

        DB::table('game_player_icons')->insertOrIgnore([
            'game_id'        => $gameId,
            'user_id'        => $userId,
            'player_icon_id' => $playerIconId,
            'invitation_id'  => $invitationId,
            'join_order'     => $nextOrder,
            'capital'        => 1500,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Log::info('Player icon assigned to game', [
            'game_id'        => $gameId,
            'user_id'        => $userId,
            'player_icon_id' => $playerIconId,
            'invitation_id'  => $invitationId,
            'join_order'     => $nextOrder,
        ]);
    }

    /**
     * Return all players that have joined a game, ordered by join_order.
     *
     * Logic: Queries game_player_icons joined with player_icons to get the icon
     * shape, and left-joined with users (for authenticated players) and
     * game_invitations (for guests). The display name is the user's name for
     * authenticated players or the invitation email for guests. The is_creator
     * flag is true for the row whose user_id matches games.user_id. Returns a
     * plain array of associative arrays ready for JSON serialisation; empty
        * Properties are hydrated from game_properties and grouped by
        * owner_join_order so ownership survives page refreshes. Held Chance and
        * Community Chest cards are hydrated from their per-game pivot tables
        * using holder_join_order, ensuring card ownership is restored after
        * refreshes.
     *
     * @param  int  $gameId  The ID of the game whose player list is requested.
     * @return array<int, array{
     *     user_id: int|null,
     *     invitation_id: int|null,
     *     name: string,
     *     is_creator: bool,
    *     isInJail: bool,
    *     jail_turns: int,
    *     has_paid_jail_release: bool,
     *     join_order: int,
     *     capital: int,
     *     icon: array{id: int, name: string, image_url: string},
     *     properties: array<int, array{square_index: int, name: string, color: string|null}>,
     *     chance_cards: array,
     *     community_chest_cards: array,
     * }>
     */
    public function getPlayersForGame(int $gameId): array
    {
        $heldChanceCardsByOwner = $this->chanceCardRepository->getHeldCardsForGame($gameId);
        $heldCommunityCardsByOwner = $this->communityChestCardRepository->getHeldCardsForGame($gameId);

        $ownedPropertyRows = DB::table('game_properties')
            ->where('game_id', $gameId)
            ->orderBy('square_index')
            ->select(['owner_join_order', 'square_index', 'houses_count', 'has_hotel'])
            ->get();

        $propertiesByOwner = [];

        foreach ($ownedPropertyRows as $ownedPropertyRow) {
            $ownerJoinOrder = (int) $ownedPropertyRow->owner_join_order;
            $squareIndex    = (int) $ownedPropertyRow->square_index;

            if (!isset($propertiesByOwner[$ownerJoinOrder])) {
                $propertiesByOwner[$ownerJoinOrder] = [];
            }

            $propertiesByOwner[$ownerJoinOrder][] = [
                'square_index' => $squareIndex,
                'name'         => self::propertyNameForSquareIndex($squareIndex),
                'color'        => self::propertyColorForSquareIndex($squareIndex),
                'houses_count' => isset($ownedPropertyRow->houses_count) ? (int) $ownedPropertyRow->houses_count : 0,
                'has_hotel'    => isset($ownedPropertyRow->has_hotel) ? (bool) $ownedPropertyRow->has_hotel : false,
            ];
        }

        $rows = DB::table('game_player_icons as gpi')
            ->join('player_icons as pi', 'pi.id', '=', 'gpi.player_icon_id')
            ->join('games as g', 'g.id', '=', 'gpi.game_id')
            ->leftJoin('users as u', 'u.id', '=', 'gpi.user_id')
            ->leftJoin('game_invitations as gi', 'gi.id', '=', 'gpi.invitation_id')
            ->where('gpi.game_id', $gameId)
            ->orderBy('gpi.join_order')
            ->select([
                'gpi.user_id',
                'gpi.invitation_id',
                'gpi.join_order',
                'gpi.capital',
                'gpi.square_index',
                'gpi.is_in_jail',
                'gpi.jail_turns',
                'gpi.has_paid_jail_release',
                'g.user_id as creator_user_id',
                'u.name as user_name',
                'gi.email as guest_email',
                'pi.id as icon_id',
                'pi.name as icon_name',
                'pi.image_url as icon_image_url',
            ])
            ->get();

        return $rows->map(function (object $row) use (
            $propertiesByOwner,
            $heldChanceCardsByOwner,
            $heldCommunityCardsByOwner,
        ): array {
            $name = $row->user_name ?? $row->guest_email ?? 'Player';

            return [
                'user_id'               => $row->user_id,
                'invitation_id'         => $row->invitation_id,
                'name'                  => $name,
                'is_creator'            => $row->user_id !== null && (int) $row->user_id === (int) $row->creator_user_id,
                'isInJail'              => (bool) $row->is_in_jail,
                'jail_turns'            => (int) $row->jail_turns,
                'has_paid_jail_release' => (bool) $row->has_paid_jail_release,
                'join_order'            => (int) $row->join_order,
                'capital'               => (int) $row->capital,
                'square_index'          => (int) $row->square_index,
                'icon'                  => [
                    'id'        => $row->icon_id,
                    'name'      => $row->icon_name,
                    'image_url' => $row->icon_image_url,
                ],
                'properties'            => $propertiesByOwner[(int) $row->join_order] ?? [],
                'chance_cards'          => $heldChanceCardsByOwner[(int) $row->join_order] ?? [],
                'community_chest_cards' => $heldCommunityCardsByOwner[(int) $row->join_order] ?? [],
            ];
        })->values()->all();
    }

    /**
     * Resolve the display name for a purchasable square index.
     *
     * Logic: Uses the static Monopoly board mapping to translate a stored
     * game_properties square_index into a stable card label for UI rendering
     * in player hand panels. Falls back to a generic label when an index is
     * unknown.
     *
     * @param  int  $squareIndex  The board square index (0-39).
     * @return string
     */
    private static function propertyNameForSquareIndex(int $squareIndex): string
    {
        $propertyNames = [
            1  => 'Mediterranean Ave',
            3  => 'Baltic Ave',
            5  => 'Reading Railroad',
            6  => 'Oriental Ave',
            8  => 'Vermont Ave',
            9  => 'Connecticut Ave',
            11 => 'St. Charles Place',
            12 => 'Electric Company',
            13 => 'States Ave',
            14 => 'Virginia Ave',
            15 => 'Pennsylvania Railroad',
            16 => 'St. James Place',
            18 => 'Tennessee Ave',
            19 => 'New York Ave',
            21 => 'Kentucky Ave',
            23 => 'Indiana Ave',
            24 => 'Illinois Ave',
            25 => 'B&O Railroad',
            26 => 'Atlantic Ave',
            27 => 'Ventnor Ave',
            28 => 'Water Works',
            29 => 'Marvin Gardens',
            31 => 'Pacific Ave',
            32 => 'North Carolina Ave',
            34 => 'Pennsylvania Ave',
            35 => 'Short Line Railroad',
            37 => 'Park Place',
            39 => 'Boardwalk',
        ];

        return $propertyNames[$squareIndex] ?? "Property {$squareIndex}";
    }

    /**
     * Resolve the color for a purchasable square index.
     *
     * Logic: Maps board square indices to their standard Monopoly property
     * group colours, using the same hex codes as the frontend board display.
     * Returns a hex colour string or null when the square has no colour
     * (e.g. railroads, utilities, special squares).
     *
     * @param  int  $squareIndex  The board square index (0-39).
     * @return string|null
     */
    private static function propertyColorForSquareIndex(int $squareIndex): ?string
    {
        $propertyColors = [
            // Brown
            1  => '#955436',  // Mediterranean Ave
            3  => '#955436',  // Baltic Ave
            // Light Blue
            6  => '#aae0fa',  // Oriental Ave
            8  => '#aae0fa',  // Vermont Ave
            9  => '#aae0fa',  // Connecticut Ave
            // Pink
            11 => '#d93a96',  // St. Charles Place
            13 => '#d93a96',  // States Ave
            14 => '#d93a96',  // Virginia Ave
            // Orange
            16 => '#f7941d',  // St. James Place
            18 => '#f7941d',  // Tennessee Ave
            19 => '#f7941d',  // New York Ave
            // Red
            21 => '#ed1b24',  // Kentucky Ave
            23 => '#ed1b24',  // Indiana Ave
            24 => '#ed1b24',  // Illinois Ave
            // Yellow
            26 => '#fef200',  // Atlantic Ave
            27 => '#fef200',  // Ventnor Ave
            29 => '#fef200',  // Marvin Gardens
            // Green
            31 => '#1fb25a',  // Pacific Ave
            32 => '#1fb25a',  // North Carolina Ave
            34 => '#1fb25a',  // Pennsylvania Ave
            // Dark Blue
            37 => '#0072bb',  // Park Place
            39 => '#0072bb',  // Boardwalk
        ];

        return $propertyColors[$squareIndex] ?? null;
    }

    /**
     * Return the join_order for an authenticated user within a game.
     *
     * Logic: Queries game_player_icons for the row matching both game_id and
     * user_id, selecting only join_order. Returns null when the user is not a
     * participant of the given game.
     *
     * @param  int  $gameId  The ID of the game.
     * @param  int  $userId  The authenticated user's ID.
     * @return int|null
     */
    public function getJoinOrderForUser(int $gameId, int $userId): ?int
    {
        $row = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('user_id', $userId)
            ->select(['join_order'])
            ->first();

        return $row ? (int) $row->join_order : null;
    }

    /**
     * Return the join_order for a guest player identified by their invitation ID.
     *
     * Logic: Queries game_player_icons for the row matching both game_id and
     * invitation_id, selecting only join_order. Returns null when no matching row
     * exists (e.g. the invitation was not yet accepted or belongs to a different
     * game).
     *
     * @param  int  $gameId        The ID of the game.
     * @param  int  $invitationId  The GameInvitation primary key for the guest.
     * @return int|null
     */
    public function getJoinOrderForGuest(int $gameId, int $invitationId): ?int
    {
        $row = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('invitation_id', $invitationId)
            ->select(['join_order'])
            ->first();

        return $row ? (int) $row->join_order : null;
    }

    /**
     * Return the current board square index for a player in a game.
     *
     * Logic: Queries game_player_icons for the row matching game_id and
     * join_order, selecting only square_index. Returns 0 (GO) when no
     * matching row exists, which is safe as a fallback.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return int  The current square index (0–39).
     */
    public function getSquareIndexForPlayer(int $gameId, int $joinOrder): int
    {
        $row = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->select(['square_index'])
            ->first();

        return $row ? (int) $row->square_index : 0;
    }

    /**
     * Persist the new board square index for a player.
     *
     * Logic: Updates the square_index column in game_player_icons for the row
     * matching game_id and join_order, and bumps updated_at so change-tracking
     * is accurate. Logs the update with enough context to audit token movement.
     *
     * @param  int  $gameId       The ID of the game.
     * @param  int  $joinOrder    The join_order of the player whose position changed.
     * @param  int  $squareIndex  The new board square index (0–39).
     * @return void
     */
    public function updateSquareIndex(int $gameId, int $joinOrder, int $squareIndex): void
    {
        DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->update([
                'square_index' => $squareIndex,
                'updated_at'   => now(),
            ]);

        Log::info('Player square index updated', [
            'game_id'      => $gameId,
            'join_order'   => $joinOrder,
            'square_index' => $squareIndex,
        ]);
    }

    /**
     * Atomically adjust a player's capital by a signed delta and return the new balance.
     *
     * Logic: Issues a single UPDATE with an inline expression (capital + delta)
     * so the adjustment is race-condition-safe without a separate SELECT. After
     * updating, fetches the new capital value via a SELECT so the caller can
     * return the updated balance to the client. Logs the adjustment for
     * auditing. A negative delta deducts capital (e.g. purchase or rent
     * payment); a positive delta adds capital (e.g. receiving rent).
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player whose capital changes.
     * @param  int  $delta      The signed amount to add (positive) or deduct (negative).
     * @return int  The new capital balance after the adjustment.
     */
    public function adjustCapital(int $gameId, int $joinOrder, int $delta): int
    {
        $query = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder);

        if ($delta >= 0) {
            $query->increment('capital', $delta, ['updated_at' => now()]);
        } else {
            $query->decrement('capital', abs($delta), ['updated_at' => now()]);
        }

        $row = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->select(['capital'])
            ->first();

        $newCapital = $row ? (int) $row->capital : 0;

        Log::info('Player capital adjusted', [
            'game_id'     => $gameId,
            'join_order'  => $joinOrder,
            'delta'       => $delta,
            'new_capital' => $newCapital,
        ]);

        return $newCapital;
    }

    /**
     * Return the display name for a player identified by join_order within a game.
     *
     * Logic: Queries game_player_icons joined with users and game_invitations
     * to resolve the display name. Returns the authenticated user's name when
     * available, falling back to the guest invitation email, and ultimately to
     * the literal string 'Player' when neither is present.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return string  The player's display name.
     */
    public function getNameByJoinOrder(int $gameId, int $joinOrder): string
    {
        $row = DB::table('game_player_icons as gpi')
            ->leftJoin('users as u', 'u.id', '=', 'gpi.user_id')
            ->leftJoin('game_invitations as gi', 'gi.id', '=', 'gpi.invitation_id')
            ->where('gpi.game_id', $gameId)
            ->where('gpi.join_order', $joinOrder)
            ->select(['u.name as user_name', 'gi.email as guest_email'])
            ->first();

        if ($row === null) {
            return 'Player';
        }

        return $row->user_name ?? $row->guest_email ?? 'Player';
    }

    /**
     * Return all join_order values for participants of a game, ordered ascending.
     *
     * Logic: Queries game_player_icons for all rows matching game_id, selecting
     * only join_order. Used by card effects that affect every player (e.g.
     * pay_each_player, collect_from_each_player) so the caller can iterate over
     * each participant without needing the full player record.
     *
     * @param  int  $gameId  The ID of the game.
     * @return list<int>  Ascending list of join_order values.
     */
    public function getAllJoinOrders(int $gameId): array
    {
        return DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->orderBy('join_order')
            ->pluck('join_order')
            ->map(fn ($jo) => (int) $jo)
            ->all();
    }

    /**
     * Return the jail-state flag for a single player within a game.
     *
     * Logic: Reads the `is_in_jail` column directly from the matching
     * game_player_icons row and casts it to a boolean. Returns false when the
     * player row cannot be found so callers can treat missing participants as
     * not jailed only after their own membership checks have passed.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return bool
     */
    public function getJailState(int $gameId, int $joinOrder): bool
    {
        $isInJail = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->value('is_in_jail');

        return (bool) $isInJail;
    }

    /**
     * Return the failed jailed-roll count for a player.
     *
     * Logic: Reads the jail_turns column directly and returns zero when the
     * player row does not exist.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return int
     */
    public function getJailTurns(int $gameId, int $joinOrder): int
    {
        $jailTurns = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->value('jail_turns');

        return (int) ($jailTurns ?? 0);
    }

    /**
     * Increment and return the failed jailed-roll count for a player.
     *
     * Logic: Uses an atomic SQL increment on jail_turns, then reads and
     * returns the updated value.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return int
     */
    public function incrementJailTurns(int $gameId, int $joinOrder): int
    {
        DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->increment('jail_turns');

        return $this->getJailTurns($gameId, $joinOrder);
    }

    /**
     * Set the failed jailed-roll count for a player.
     *
     * Logic: Writes jail_turns and updates updated_at for audit consistency.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @param  int  $jailTurns  The failed jailed-roll attempt count.
     * @return void
     */
    public function setJailTurns(int $gameId, int $joinOrder, int $jailTurns): void
    {
        DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->update([
                'jail_turns' => max(0, $jailTurns),
                'updated_at' => now(),
            ]);
    }

    /**
     * Return whether the player already paid the $50 jail-release fee this turn.
     *
     * Logic: Reads has_paid_jail_release directly and casts to boolean.
     *
     * @param  int  $gameId     The ID of the game.
     * @param  int  $joinOrder  The join_order of the player.
     * @return bool
     */
    public function hasPaidJailRelease(int $gameId, int $joinOrder): bool
    {
        $hasPaid = DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->value('has_paid_jail_release');

        return (bool) $hasPaid;
    }

    /**
     * Set whether the player has paid the $50 jail-release fee this turn.
     *
     * Logic: Updates has_paid_jail_release and updated_at for the target player row.
     *
     * @param  int   $gameId     The ID of the game.
     * @param  int   $joinOrder  The join_order of the player.
     * @param  bool  $hasPaid    True when the release fee is paid for this turn.
     * @return void
     */
    public function setHasPaidJailRelease(int $gameId, int $joinOrder, bool $hasPaid): void
    {
        DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->update([
                'has_paid_jail_release' => $hasPaid,
                'updated_at' => now(),
            ]);
    }

    /**
     * Set or clear the jail flag for a player within a game.
     *
     * Logic: Updates the is_in_jail column for the matching game_player_icons
     * row. Pass true when the player is sent to jail (Go To Jail square or card),
     * and false when they are released. Logs the state change for audit purposes.
     *
     * @param  int   $gameId     The ID of the game.
     * @param  int   $joinOrder  The join_order of the player.
     * @param  bool  $inJail     True to mark the player as jailed, false to release.
     * @return void
     */
    public function setJailState(int $gameId, int $joinOrder, bool $inJail): void
    {
        DB::table('game_player_icons')
            ->where('game_id', $gameId)
            ->where('join_order', $joinOrder)
            ->update([
                'is_in_jail' => $inJail,
                'jail_turns' => 0,
                'has_paid_jail_release' => false,
                'updated_at' => now(),
            ]);

        Log::info('Player jail state updated', [
            'game_id'    => $gameId,
            'join_order' => $joinOrder,
            'is_in_jail' => $inJail,
            'jail_turns' => 0,
            'has_paid_jail_release' => false,
        ]);
    }
}
