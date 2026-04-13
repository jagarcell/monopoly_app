<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a player attempts to claim an icon that another player
 * has already taken in the same game (unique-constraint violation on
 * game_player_icons.player_icon_id + game_id). Maps to HTTP 409.
 */
class IconConflictException extends RuntimeException
{
    /**
     * @param  string  $message  Human-readable conflict description.
     * @param  int     $code     Exception code (default 0).
     */
    public function __construct(string $message = 'That icon was just taken — please choose another.', int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
