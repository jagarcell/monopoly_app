<?php

namespace App\Models;

use App\Enums\ChanceCardAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChanceCard extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'chance_cards';

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
        'spaces',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'action'     => ChanceCardAction::class,
        'amount'     => 'integer',
        'house_cost' => 'integer',
        'hotel_cost' => 'integer',
        'spaces'     => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Get the game that owns this chance card.
     *
     * Logic: Returns the BelongsTo relationship linking chance_cards.game_id → games.id.
     *
     * @return BelongsTo<Game, ChanceCard>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
