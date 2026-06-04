<?php

namespace Tests\Feature\Api;

use App\Models\GameInvitation;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use App\Repositories\GamePropertyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChanceUtilityPaymentTest extends TestCase
{
    use RefreshDatabase;

    private int $icon1;
    private int $icon2;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable debug helpers used by the test
        config(['app.debug_mode' => true]);

        app(ChanceCardRepository::class)->seedMasterDeck();
        app(CommunityChestCardRepository::class)->seedMasterDeck();

        $iconA = PlayerIcon::create(['name' => 'Hat A', 'image_url' => '/images/a.svg', 'sort_order' => 1]);
        $iconB = PlayerIcon::create(['name' => 'Hat B', 'image_url' => '/images/b.svg', 'sort_order' => 2]);

        $this->icon1 = $iconA->id;
        $this->icon2 = $iconB->id;
    }

    public function test_emulated_chance_advance_to_nearest_utility_rolls_and_pays_owner_ten_times(): void
    {
        // Create game as user1 (creator)
        $user1 = User::factory()->create();
        $game = $this->actingAs($user1)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->icon1])
            ->json('game');

        $gameId = $game['id'];

        // Create an invitation for a guest and accept it (guest becomes player)
        $token = (string) Str::uuid();

        $invitation = GameInvitation::create([
            'game_id' => $gameId,
            'email' => 'guest@example.com',
            'token' => $token,
            'expires_at' => now()->addDays(7),
        ]);

        // Accept the invitation (pick the second icon)
        $acceptResp = $this->postJson("/join/{$token}/accept", ['player_icon_id' => $this->icon2]);
        $acceptResp->assertOk();

        // Resolve join orders using repository helpers to avoid assumptions
        $playerIconRepo = app(\App\Repositories\PlayerIconRepository::class);
        $creatorJoinOrder = $playerIconRepo->getJoinOrderForUser($gameId, $user1->id);
        $guestJoinOrder = $playerIconRepo->getJoinOrderForGuest($gameId, $invitation->id);

        $this->assertNotNull($creatorJoinOrder, 'Creator join order not found');
        $this->assertNotNull($guestJoinOrder, 'Guest join order not found');

        // Make it the guest's turn
        \App\Models\Game::find($gameId)->update(['current_turn_join_order' => $guestJoinOrder]);

        $current = \App\Models\Game::find($gameId)->current_turn_join_order;
        $this->assertEquals($guestJoinOrder, (int) $current, 'Failed to set current_turn_join_order to guest');

        // Move guest to Chance square 7 so nearest utility is 12
        $moveResp = $this->postJson("/api/join/{$token}/debug/move", ['target_square_index' => 7]);
        $moveResp->assertOk();

        // Create ownership of Electric Company (12) by the creator
        app(GamePropertyRepository::class)->createOwnership($gameId, 12, $creatorJoinOrder, 150);

        $ownerInfo = app(\App\Repositories\GamePropertyRepository::class)->findOwnerBySquare($gameId, 12);
        $this->assertNotNull($ownerInfo, 'Owner info for square 12 should exist');
        $this->assertEquals($creatorJoinOrder, $ownerInfo['owner_join_order']);

        // Find the Chance card with target 'utility'
        $card = \App\Models\ChanceCard::where('target', 'utility')->first();
        $this->assertNotNull($card, 'Utility chance card not found');

        // Directly invoke the private applyCardEffect to exercise the utility rule
        $gameService = app(\App\Services\GameService::class);

        $cardArray = [
            'id' => $card->id,
            'action' => $card->action->value,
            'text' => $card->text,
            'amount' => $card->amount,
            'house_cost' => $card->house_cost,
            'hotel_cost' => $card->hotel_cost,
            'target' => $card->target,
            'spaces' => $card->spaces,
        ];

        $ref = new \ReflectionClass($gameService);
        $method = $ref->getMethod('applyCardEffect');
        $method->setAccessible(true);

        $effect = $method->invokeArgs($gameService, [$gameId, $guestJoinOrder, $cardArray, 7]);

        $this->assertIsArray($effect);
        $this->assertSame('advance_to_nearest', $effect['type']);

        $squareAction = $effect['square_action'] ?? null;
        $this->assertNotNull($squareAction, 'No square_action returned');

        // If owned, our implementation returns a rent_paid payload with dice_roll
        if ($squareAction['type'] === 'rent_paid') {
            $this->assertArrayHasKey('dice_roll', $squareAction);
            $this->assertArrayHasKey('rent_amount', $squareAction);
            $this->assertEquals($squareAction['rent_amount'], $squareAction['dice_roll'] * 10);
        } else {
            $this->fail('Expected rent_paid square_action for owned utility');
        }
    }
}
