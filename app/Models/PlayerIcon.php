<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PlayerIcon extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'player_icons';

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
        'name',
        'image_url',
        'sort_order',
    ];

    /**
     * Get the games this icon has been assigned to.
     *
     * Logic: Returns the BelongsToMany relationship through the game_player_icons
     * pivot table, allowing reverse lookups (which games use this icon).
     *
     * @return BelongsToMany<Game, PlayerIcon>
     */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(
            Game::class,
            'game_player_icons',
            'player_icon_id',
            'game_id'
        )
            ->withPivot('user_id')
            ->withTimestamps();
    }
}
