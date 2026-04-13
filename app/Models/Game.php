<?php

namespace App\Models;

use App\Enums\GameStatus;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'games';

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
        'user_id',
        'status',
        'max_players',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => GameStatus::class,
    ];

    /**
     * Get the user who created this game.
     *
     * Logic: Returns the BelongsTo relationship linking games.user_id → users.id.
     *
     * @return BelongsTo<User, Game>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the shuffled Chance deck for this game.
     *
     * Logic: Returns the BelongsToMany relationship through the game_chance_cards
     * pivot table. The sort_order pivot column represents the draw sequence (1–16)
     * assigned when the deck was created for this game.
     *
     * @return BelongsToMany<ChanceCard, Game>
     */
    public function chanceCards(): BelongsToMany
    {
        return $this->belongsToMany(ChanceCard::class, 'game_chance_cards', 'game_id', 'chance_card_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * Get the shuffled Community Chest deck for this game.
     *
     * Logic: Returns the BelongsToMany relationship through the
     * game_community_chest_cards pivot table. The sort_order pivot column
     * represents the draw sequence (1–16) assigned when the deck was created.
     *
     * @return BelongsToMany<CommunityChestCard, Game>
     */
    public function communityChestCards(): BelongsToMany
    {
        return $this->belongsToMany(
            CommunityChestCard::class,
            'game_community_chest_cards',
            'game_id',
            'community_chest_card_id'
        )
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * Get the player icons assigned to this game (one per participating user).
     *
     * Logic: Returns the BelongsToMany relationship through the game_player_icons
     * pivot table. The pivot also carries user_id so callers can determine which
     * user selected which icon.
     *
     * @return BelongsToMany<PlayerIcon, Game>
     */
    public function playerIcons(): BelongsToMany
    {
        return $this->belongsToMany(
            PlayerIcon::class,
            'game_player_icons',
            'game_id',
            'player_icon_id'
        )
            ->withPivot('user_id', 'invitation_id')
            ->withTimestamps();
    }

    /**
     * Get the invitations sent out for this game.
     *
     * Logic: Returns the HasMany relationship so callers can query pending or
     * accepted invitations for a given game.
     *
     * @return HasMany<GameInvitation, Game>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(GameInvitation::class);
    }
}
