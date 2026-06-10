<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use App\Models\GameInvitation;
use App\Models\PlayerIcon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ensure sell endpoints enforce that only the active turn-holder may sell buildings.
 */
class SellPropertyTurnEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private int $iconId;
    private int $guestIconId;

    protected function setUp(): void
    {
        parent::setUp();

        $icon = PlayerIcon::create(['name' => 'Hat', 'image_url' => '/icons/hat.svg', 'sort_order' => 1]);
        $guestIcon = PlayerIcon::create(['name' => 'Car', 'image_url' => '/icons/car.svg', 'sort_order' => 2]);
        $this->iconId = $icon->id;
        $this->guestIconId = $guestIcon->id;
    }

    private function makeUserAndGame(): array
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/games', [
            'max_players' => 4,
            'player_icon_id' => $this->iconId,
        ]);
        $response->assertCreated();

        $game = Game::find($response->json('game.id'));
        return compact('user', 'game');
    }

    private function inviteAndAcceptGuest(Game $game): string
    {
        $token = (string) \Illuminate\Support\Str::uuid();
        $invitation = GameInvitation::create([
            'game_id' => $game->id,
            'email' => 'guest@example.com',
            'token' => $token,
            'status' => 'accepted',
            'accepted_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        DB::table('game_player_icons')->insert([
            'game_id' => $game->id,
            'user_id' => null,
            'player_icon_id' => $this->guestIconId,
            'invitation_id' => $invitation->id,
            'join_order' => 2,
            'capital' => 1500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    public function test_creator_cannot_sell_when_it_is_not_their_turn(): void
    {
        ['user' => $user, 'game' => $game] = $this->makeUserAndGame();
        $token = $this->inviteAndAcceptGuest($game);

        // Move turn to guest
        DB::table('games')->where('id', $game->id)->update(['current_turn_join_order' => 2]);

        // Sanity-check preconditions: creator should be join_order 1 and current turn is 2
        $creatorJoinOrder = (int) DB::table('game_player_icons')->where('game_id', $game->id)->whereNotNull('user_id')->value('join_order');
        $this->assertSame(1, $creatorJoinOrder);
        $this->assertSame(2, (int) DB::table('games')->where('id', $game->id)->value('current_turn_join_order'));

        // Ensure the creator owns the full colour group for Mediterranean Ave (1) and Baltic Ave (3)
        DB::table('game_properties')->insert([
            [
                'game_id' => $game->id,
                'owner_join_order' => 1,
                'square_index' => 1,
                'purchase_price' => 60,
                'houses_count' => 1,
                'has_hotel' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'game_id' => $game->id,
                'owner_join_order' => 1,
                'square_index' => 3,
                'purchase_price' => 60,
                'houses_count' => 0,
                'has_hotel' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($user)->postJson("/api/games/{$game->id}/property/sell", [
            'square_index' => 1,
            'action' => 'house',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'It is not your turn.');
    }

    public function test_guest_cannot_sell_when_it_is_not_their_turn(): void
    {
        ['game' => $game] = $this->makeUserAndGame();
        $token = $this->inviteAndAcceptGuest($game);

        // current_turn_join_order defaults to 1, guest is join_order 2
        // Sanity-check preconditions: guest should be join_order 2 and current turn is 1
        $guestJoinOrder = (int) DB::table('game_player_icons')->where('game_id', $game->id)->whereNull('user_id')->value('join_order');
        $this->assertSame(2, $guestJoinOrder);
        $this->assertSame(1, (int) DB::table('games')->where('id', $game->id)->value('current_turn_join_order'));
        // Ensure the guest owns a full colour group so sell preconditions would otherwise pass.
        DB::table('game_properties')->insert([
            [
                'game_id' => $game->id,
                'owner_join_order' => 2,
                'square_index' => 1,
                'purchase_price' => 60,
                'houses_count' => 1,
                'has_hotel' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'game_id' => $game->id,
                'owner_join_order' => 2,
                'square_index' => 3,
                'purchase_price' => 60,
                'houses_count' => 0,
                'has_hotel' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $response = $this->postJson("/api/join/{$token}/property/sell", [
            'square_index' => 1,
            'action' => 'house',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'It is not your turn.');
    }
}
