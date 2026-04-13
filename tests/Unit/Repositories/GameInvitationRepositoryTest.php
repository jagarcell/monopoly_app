<?php

namespace Tests\Unit\Repositories;

use App\Models\Game;
use App\Models\GameInvitation;
use App\Models\User;
use App\Repositories\GameInvitationRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameInvitationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private GameInvitationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new GameInvitationRepository();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeGame(): Game
    {
        $user = User::factory()->create();
        return Game::factory()->create(['user_id' => $user->id, 'max_players' => 4]);
    }

    private function makeInvitation(Game $game, array $overrides = []): GameInvitation
    {
        return GameInvitation::create(array_merge([
            'game_id'    => $game->id,
            'email'      => 'test@example.com',
            'token'      => (string) \Illuminate\Support\Str::uuid(),
            'expires_at' => now()->addDays(7),
        ], $overrides));
    }

    // ── createForGame ─────────────────────────────────────────────────────────

    public function test_creates_invitation_and_returns_model(): void
    {
        $game      = $this->makeGame();
        $token     = (string) \Illuminate\Support\Str::uuid();
        $expiresAt = now()->addDays(7);

        $result = $this->repository->createForGame($game->id, 'invite@example.com', $token, $expiresAt);

        $this->assertInstanceOf(GameInvitation::class, $result);
        $this->assertSame($game->id, $result->game_id);
        $this->assertSame('invite@example.com', $result->email);
        $this->assertSame($token, $result->token);
        $this->assertDatabaseHas('game_invitations', ['token' => $token]);
    }

    // ── findByToken ───────────────────────────────────────────────────────────

    public function test_find_by_token_returns_invitation_with_game_and_user(): void
    {
        $game       = $this->makeGame();
        $invitation = $this->makeInvitation($game);

        $result = $this->repository->findByToken($invitation->token);

        $this->assertNotNull($result);
        $this->assertSame($invitation->id, $result->id);
        $this->assertNotNull($result->game);
        $this->assertNotNull($result->game->user);
    }

    public function test_find_by_token_returns_null_for_unknown_token(): void
    {
        $result = $this->repository->findByToken('non-existent-token');
        $this->assertNull($result);
    }

    // ── markAccepted ──────────────────────────────────────────────────────────

    public function test_mark_accepted_sets_accepted_at(): void
    {
        $game       = $this->makeGame();
        $invitation = $this->makeInvitation($game);

        $result = $this->repository->markAccepted($invitation->id);

        $this->assertNotNull($result->accepted_at);
        $this->assertDatabaseHas('game_invitations', [
            'id' => $invitation->id,
        ]);

        $fresh = GameInvitation::find($invitation->id);
        $this->assertNotNull($fresh->accepted_at);
    }

    // ── countAcceptedByGame ───────────────────────────────────────────────────

    public function test_count_accepted_returns_zero_for_no_accepted_invitations(): void
    {
        $game = $this->makeGame();
        $this->makeInvitation($game); // pending

        $this->assertSame(0, $this->repository->countAcceptedByGame($game->id));
    }

    public function test_count_accepted_returns_correct_count(): void
    {
        $game = $this->makeGame();

        $inv1              = $this->makeInvitation($game, ['email' => 'a@example.com']);
        $inv1->accepted_at = now();
        $inv1->save();

        $inv2              = $this->makeInvitation($game, ['email' => 'b@example.com']);
        $inv2->accepted_at = now();
        $inv2->save();

        $this->makeInvitation($game, ['email' => 'c@example.com']); // pending

        $this->assertSame(2, $this->repository->countAcceptedByGame($game->id));
    }

    public function test_count_accepted_only_counts_own_game(): void
    {
        $gameA = $this->makeGame();
        $gameB = $this->makeGame();

        $inv              = $this->makeInvitation($gameA);
        $inv->accepted_at = now();
        $inv->save();

        $this->assertSame(0, $this->repository->countAcceptedByGame($gameB->id));
    }
}
