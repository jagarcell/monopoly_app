<?php

namespace Tests\Feature\Events;

use App\Events\PlayerJoined;
use App\Models\GameInvitation;
use App\Models\PlayerIcon;
use App\Models\User;
use App\Repositories\ChanceCardRepository;
use App\Repositories\CommunityChestCardRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests for the PlayerJoined broadcast event.
 *
 * Verifies that PlayerJoined is dispatched on the correct public channel with
 * the expected players payload whenever a guest successfully accepts an
 * invitation via POST /api/join/{token}/accept.
 */
class PlayerJoinedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /** @var int The ID of the creator's player icon. */
    private int $creatorIconId;

    /** @var int The ID of the guest's player icon. */
    private int $guestIconId;

    protected function setUp(): void
    {
        parent::setUp();

        app(ChanceCardRepository::class)->seedMasterDeck();
        app(CommunityChestCardRepository::class)->seedMasterDeck();

        $creator           = PlayerIcon::create(['name' => 'Top Hat',  'image_url' => '/top-hat.svg', 'sort_order' => 1]);
        $guest             = PlayerIcon::create(['name' => 'Iron',     'image_url' => '/iron.svg',    'sort_order' => 2]);
        $this->creatorIconId = $creator->id;
        $this->guestIconId   = $guest->id;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Create a user, game, and a pending invitation ready for acceptance.
     *
     * @return array{user: User, gameId: int, token: string, invitation: GameInvitation}
     */
    private function makeGameAndPendingInvitation(): array
    {
        $user = User::factory()->create();

        $gameData = $this->actingAs($user)
            ->postJson('/api/games', ['max_players' => 4, 'player_icon_id' => $this->creatorIconId])
            ->json('game');

        $token      = (string) Str::uuid();
        $invitation = GameInvitation::create([
            'game_id'    => $gameData['id'],
            'email'      => 'guest@example.com',
            'token'      => $token,
            'expires_at' => now()->addDays(7),
        ]);

        return [
            'user'       => $user,
            'gameId'     => $gameData['id'],
            'token'      => $token,
            'invitation' => $invitation,
        ];
    }

    // ── Broadcast assertions ───────────────────────────────────────────────────

    public function test_player_joined_is_dispatched_when_guest_accepts_invitation(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token] = $this->makeGameAndPendingInvitation();

        $this->postJson("/join/{$token}/accept", [
            'player_icon_id' => $this->guestIconId,
        ])->assertOk();

        Event::assertDispatched(PlayerJoined::class);
    }

    public function test_player_joined_is_broadcast_on_correct_game_channel(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token, 'gameId' => $gameId] = $this->makeGameAndPendingInvitation();

        $this->postJson("/join/{$token}/accept", [
            'player_icon_id' => $this->guestIconId,
        ])->assertOk();

        Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event) use ($gameId) {
            $channels = $event->broadcastOn();
            $channel  = is_array($channels) ? $channels[0] : $channels;
            return $channel->name === 'game.' . $gameId;
        });
    }

    public function test_player_joined_payload_contains_all_joined_players(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token] = $this->makeGameAndPendingInvitation();

        $this->postJson("/join/{$token}/accept", [
            'player_icon_id' => $this->guestIconId,
        ])->assertOk();

        Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event) {
            $payload = $event->broadcastWith();

            return isset($payload['players'])
                && count($payload['players']) === 2
                && collect($payload['players'])->contains(fn ($p) => (bool) $p['is_creator'] === true)
                && collect($payload['players'])->contains(fn ($p) => (bool) $p['is_creator'] === false);
        });
    }

    public function test_player_joined_payload_players_have_required_keys(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token] = $this->makeGameAndPendingInvitation();

        $this->postJson("/join/{$token}/accept", [
            'player_icon_id' => $this->guestIconId,
        ])->assertOk();

        Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event) {
            foreach ($event->broadcastWith()['players'] as $player) {
                $keys = ['user_id', 'name', 'is_creator', 'join_order', 'icon',
                         'properties', 'chance_cards', 'community_chest_cards'];

                foreach ($keys as $key) {
                    if (!array_key_exists($key, $player)) {
                        return false;
                    }
                }
            }

            return true;
        });
    }

    public function test_player_joined_is_dispatched_once_per_successful_accept(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token, 'gameId' => $gameId] = $this->makeGameAndPendingInvitation();

        // Create a second invitation for the same game.
        $secondToken = (string) Str::uuid();
        $firstInv    = GameInvitation::where('token', $token)->first();
        GameInvitation::create([
            'game_id'    => $firstInv->game_id,
            'email'      => 'other@example.com',
            'token'      => $secondToken,
            'expires_at' => now()->addDays(7),
        ]);

        $thirdIcon = PlayerIcon::create(['name' => 'Dog', 'image_url' => '/dog.svg', 'sort_order' => 3]);

        // Accept both invitations with different icons.
        $this->postJson("/join/{$token}/accept",       ['player_icon_id' => $this->guestIconId])->assertOk();
        $this->postJson("/join/{$secondToken}/accept", ['player_icon_id' => $thirdIcon->id])->assertOk();

        // PlayerJoined dispatched exactly once per successful accept.
        Event::assertDispatchedTimes(PlayerJoined::class, 2);
    }

    public function test_player_joined_payload_contains_pending_invitations_key(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token] = $this->makeGameAndPendingInvitation();

        $this->postJson("/join/{$token}/accept", [
            'player_icon_id' => $this->guestIconId,
        ])->assertOk();

        Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event): bool {
            return array_key_exists('pending_invitations', $event->broadcastWith());
        });
    }

    public function test_player_joined_payload_excludes_accepted_invitation_from_pending(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token, 'invitation' => $invitation] = $this->makeGameAndPendingInvitation();

        $this->postJson("/join/{$token}/accept", [
            'player_icon_id' => $this->guestIconId,
        ])->assertOk();

        Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event) use ($invitation): bool {
            $emails = array_column($event->broadcastWith()['pending_invitations'], 'email');
            return ! in_array($invitation->email, $emails, true);
        });
    }

    public function test_player_joined_payload_includes_remaining_pending_invitations(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token, 'gameId' => $gameId] = $this->makeGameAndPendingInvitation();

        // Create a second invitation that will remain pending after the first is accepted.
        $secondToken = (string) Str::uuid();
        GameInvitation::create([
            'game_id'    => $gameId,
            'email'      => 'still-pending@example.com',
            'token'      => $secondToken,
            'expires_at' => now()->addDays(7),
        ]);

        $this->postJson("/join/{$token}/accept", [
            'player_icon_id' => $this->guestIconId,
        ])->assertOk();

        Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event): bool {
            $emails = array_column($event->broadcastWith()['pending_invitations'], 'email');
            return in_array('still-pending@example.com', $emails, true);
        });
    }

    public function test_player_joined_is_dispatched_when_guest_reopens_accepted_game_page(): void
    {
        Event::fake([PlayerJoined::class]);

        ['token' => $token, 'invitation' => $invitation] = $this->makeGameAndPendingInvitation();

        $this->postJson("/join/{$token}/accept", [
            'player_icon_id' => $this->guestIconId,
        ])->assertOk();

        Event::fake([PlayerJoined::class]);

        $this->get("/join/{$token}/game")->assertOk();

        Event::assertDispatched(PlayerJoined::class, function (PlayerJoined $event) use ($invitation): bool {
            $payload = $event->broadcastWith();
            $emails = array_column($payload['pending_invitations'], 'email');

            return collect($payload['players'])->contains(
                fn (array $player): bool => ($player['invitation_id'] ?? null) === $invitation->id
            ) && ! in_array($invitation->email, $emails, true);
        });
    }
}
