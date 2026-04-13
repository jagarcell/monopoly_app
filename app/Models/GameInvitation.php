<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameInvitation extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'game_invitations';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'game_id',
        'email',
        'token',
        'accepted_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at'  => 'datetime',
    ];

    /**
     * Get the game this invitation belongs to.
     *
     * Logic: Returns the BelongsTo relationship linking game_invitations.game_id
     * → games.id so callers can eagerly load the associated game.
     *
     * @return BelongsTo<Game, GameInvitation>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
