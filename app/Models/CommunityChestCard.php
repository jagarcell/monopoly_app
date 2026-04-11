<?php

namespace App\Models;

use App\Enums\CommunityChestCardAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CommunityChestCard extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'community_chest_cards';

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
        'action',
        'text',
        'amount',
        'house_cost',
        'hotel_cost',
        'target',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'action'     => CommunityChestCardAction::class,
        'amount'     => 'integer',
        'house_cost' => 'integer',
        'hotel_cost' => 'integer',
    ];

    /**
     * Get all games that include this community chest card.
     *
     * Logic: Returns the BelongsToMany relationship through the
     * game_community_chest_cards pivot table, exposing sort_order as a pivot
     * column so callers can determine the draw position for each specific game.
     *
     * @return BelongsToMany<Game, CommunityChestCard>
     */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(
            Game::class,
            'game_community_chest_cards',
            'community_chest_card_id',
            'game_id'
        )
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
