<?php

namespace App\Models;

use App\Enums\CommunityChestCardAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'game_id',
        'action',
        'text',
        'amount',
        'house_cost',
        'hotel_cost',
        'target',
        'sort_order',
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
        'sort_order' => 'integer',
    ];

    /**
     * Get the game that owns this community chest card.
     *
     * Logic: Returns the BelongsTo relationship linking community_chest_cards.game_id → games.id.
     *
     * @return BelongsTo<Game, CommunityChestCard>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
