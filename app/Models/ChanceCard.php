<?php

namespace App\Models;

use App\Enums\ChanceCardAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        'action',
        'text',
        'amount',
        'house_cost',
        'hotel_cost',
        'target',
        'spaces',
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
    ];

    /**
     * Get all games that include this chance card.
     *
     * Logic: Returns the BelongsToMany relationship through the game_chance_cards
     * pivot table, exposing sort_order as a pivot column so callers can determine
     * the draw position for each specific game.
     *
     * @return BelongsToMany<Game, ChanceCard>
     */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_chance_cards', 'chance_card_id', 'game_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }
}
