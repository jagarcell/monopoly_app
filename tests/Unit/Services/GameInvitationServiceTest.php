<?php

namespace Tests\Unit\Services;

use App\Exceptions\IconConflictException;
use App\Mail\GameInvitationMail;
use App\Models\Game;
use App\Models\GameInvitation;
use App\Repositories\GameInvitationRepository;
use App\Repositories\GameRepository;
use App\Repositories\PlayerIconRepository;
use App\Services\GameInvitationService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GameInvitationServiceTest extends TestCase
{
    private GameInvitationService $service;
    private MockInterface $gameRepository;
    private MockInterface $invitationRepository;
    private MockInterface $playerIconRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gameRepository       = Mockery::mock(GameRepository::class);
        $this->invitationRepository = Mockery::mock(GameInvitationRepository::class);
        $this->playerIconRepository = Mockery::mock(PlayerIconRepository::class);

        $this->service = new GameInvitationService(
            $this->gameRepository,
            $this->invitationRepository,
            $this->playerIconRepository,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ── sendInvitations ───────────────────────────────────────────────────────

    public function test_send_invitations_creates_records_and_queues_mail(): void
    {
        Mail::fake();

        $game            = new Game(['name' => 'Game #1', 'max_players' => 4]);
        $game->id        = 10;
        $game->user_id   = 5;

        $this->gameRepository->shouldReceive('findById')->once()->with(10)->andReturn($game);

        $invitation      = new GameInvitation(['email' => 'a@example.com', 'token' => 'uuid-1']);
        $invitation->id  = 1;
        $invitation->game_id = 10;

        $this->invitationRepository
            ->shouldReceive('createForGame')
            ->once()
            ->andReturn($invitation);

        $result = $this->service->sendInvitations(10, 5, ['a@example.com']);

        $this->assertCount(1, $result);
        Mail::assertSent(GameInvitationMail::class, function ($mail) {
            return $mail->invitation->email === 'a@example.com';
        });
    }

    public function test_send_invitations_throws_when_game_not_found(): void
    {
        $this->gameRepository->shouldReceive('findById')->once()->with(99)->andReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Game not found or you do not own this game');

        $this->service->sendInvitations(99, 5, ['a@example.com']);
    }

    public function test_send_invitations_throws_when_user_does_not_own_game(): void
    {
        $game          = new Game(['name' => 'Game #1', 'max_players' => 4]);
        $game->id      = 10;
        $game->user_id = 99; // different user

        $this->gameRepository->shouldReceive('findById')->once()->with(10)->andReturn($game);

        $this->expectException(InvalidArgumentException::class);
        $this->service->sendInvitations(10, 5, ['a@example.com']);
    }

    public function test_send_invitations_throws_when_email_count_exceeds_capacity(): void
    {
        $game          = new Game(['name' => 'Game #1', 'max_players' => 2]);
        $game->id      = 10;
        $game->user_id = 5;

        $this->gameRepository->shouldReceive('findById')->once()->with(10)->andReturn($game);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 1 player');

        $this->service->sendInvitations(10, 5, ['a@example.com', 'b@example.com']);
    }

    public function test_send_invitations_sends_to_multiple_emails(): void
    {
        Mail::fake();

        $game          = new Game(['name' => 'Game #1', 'max_players' => 4]);
        $game->id      = 10;
        $game->user_id = 5;

        $this->gameRepository->shouldReceive('findById')->once()->with(10)->andReturn($game);

        $this->invitationRepository
            ->shouldReceive('createForGame')
            ->twice()
            ->andReturnUsing(function (int $gameId, string $email) {
                $inv          = new GameInvitation(['email' => $email, 'token' => 'tok']);
                $inv->game_id = $gameId;
                $inv->id      = rand(1, 9999);
                return $inv;
            });

        $result = $this->service->sendInvitations(10, 5, ['a@example.com', 'b@example.com']);

        $this->assertCount(2, $result);
        Mail::assertSentCount(2);
    }

    // ── findPendingInvitation ─────────────────────────────────────────────────

    public function test_find_pending_invitation_throws_when_not_found(): void
    {
        $this->invitationRepository->shouldReceive('findByToken')->once()->with('bad')->andReturn(null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invitation not found');

        $this->service->findPendingInvitation('bad');
    }

    public function test_find_pending_invitation_throws_when_already_accepted(): void
    {
        $inv              = new GameInvitation(['token' => 'tok']);
        $inv->accepted_at = now()->subDay();
        $inv->expires_at  = now()->addDay();

        $this->invitationRepository->shouldReceive('findByToken')->once()->andReturn($inv);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been used');

        $this->service->findPendingInvitation('tok');
    }

    public function test_find_pending_invitation_throws_when_expired(): void
    {
        $inv              = new GameInvitation(['token' => 'tok']);
        $inv->accepted_at = null;
        $inv->expires_at  = now()->subDay();

        $this->invitationRepository->shouldReceive('findByToken')->once()->andReturn($inv);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expired');

        $this->service->findPendingInvitation('tok');
    }

    // ── acceptInvitation ──────────────────────────────────────────────────────

    public function test_accept_invitation_assigns_icon_and_marks_accepted(): void
    {
        $game             = new Game(['name' => 'Game #1', 'max_players' => 4]);
        $game->id         = 10;
        $game->user_id    = 5;

        $inv              = new GameInvitation(['token' => 'tok', 'game_id' => 10]);
        $inv->id          = 2;
        $inv->accepted_at = null;
        $inv->expires_at  = now()->addDay();
        $inv->setRelation('game', $game);

        $accepted              = clone $inv;
        $accepted->accepted_at = now();

        $this->invitationRepository->shouldReceive('findByToken')->once()->with('tok')->andReturn($inv);
        $this->invitationRepository->shouldReceive('findByToken')->once()->with('tok')->andReturn($accepted);
        $this->playerIconRepository->shouldReceive('assignToGame')->once()->with(10, null, 3, 2);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with(10)->andReturn([]);
        $this->invitationRepository->shouldReceive('markAccepted')->once()->with(2)->andReturn($accepted);
        $this->invitationRepository->shouldReceive('getPendingForGame')->once()->with(10)->andReturn([]);

        // DB::transaction wraps the call — use real transaction via TestCase (no DB touch in service mock test)
        // We mock the DB facade for the lockForUpdate query.
        DB::shouldReceive('transaction')->once()->andReturnUsing(function (callable $cb) {
            $cb();
        });
        DB::shouldReceive('table')->once()->with('game_player_icons')->andReturnSelf();
        DB::shouldReceive('where')->once()->with('game_id', 10)->andReturnSelf();
        DB::shouldReceive('lockForUpdate')->once()->andReturnSelf();
        DB::shouldReceive('get')->once()->with(['player_icon_id'])->andReturn(collect());

        $result = $this->service->acceptInvitation('tok', 3);

        $this->assertNotNull($result->accepted_at);
    }

    public function test_accept_invitation_throws_icon_conflict_exception_on_query_exception(): void
    {
        $game             = new Game(['name' => 'Game #1', 'max_players' => 4]);
        $game->id         = 10;

        $inv              = new GameInvitation(['token' => 'tok', 'game_id' => 10]);
        $inv->id          = 2;
        $inv->accepted_at = null;
        $inv->expires_at  = now()->addDay();
        $inv->setRelation('game', $game);

        $this->invitationRepository->shouldReceive('findByToken')->once()->with('tok')->andReturn($inv);

        DB::shouldReceive('transaction')->once()->andThrow(
            new QueryException('mysql', 'INSERT ...', [], new \Exception('Duplicate entry'))
        );

        $this->expectException(IconConflictException::class);

        $this->service->acceptInvitation('tok', 3);
    }

    public function test_accept_invitation_dispatches_player_joined_with_pending_invitations(): void
    {
        $game             = new Game(['name' => 'Game #1', 'max_players' => 4]);
        $game->id         = 10;
        $game->user_id    = 5;

        $inv              = new GameInvitation(['token' => 'tok', 'game_id' => 10]);
        $inv->id          = 2;
        $inv->accepted_at = null;
        $inv->expires_at  = now()->addDay();
        $inv->setRelation('game', $game);

        $accepted              = clone $inv;
        $accepted->accepted_at = now();

        $pending = [['email' => 'other@example.com']];

        $this->invitationRepository->shouldReceive('findByToken')->once()->with('tok')->andReturn($inv);
        $this->invitationRepository->shouldReceive('findByToken')->once()->with('tok')->andReturn($accepted);
        $this->playerIconRepository->shouldReceive('assignToGame')->once()->with(10, null, 3, 2);
        $this->playerIconRepository->shouldReceive('getPlayersForGame')->once()->with(10)->andReturn([]);
        $this->invitationRepository->shouldReceive('markAccepted')->once()->with(2)->andReturn($accepted);
        $this->invitationRepository->shouldReceive('getPendingForGame')->once()->with(10)->andReturn($pending);

        DB::shouldReceive('transaction')->once()->andReturnUsing(function (callable $cb) {
            $cb();
        });
        DB::shouldReceive('table')->once()->with('game_player_icons')->andReturnSelf();
        DB::shouldReceive('where')->once()->with('game_id', 10)->andReturnSelf();
        DB::shouldReceive('lockForUpdate')->once()->andReturnSelf();
        DB::shouldReceive('get')->once()->with(['player_icon_id'])->andReturn(collect());

        \Illuminate\Support\Facades\Event::fake();

        $this->service->acceptInvitation('tok', 3);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\PlayerJoined::class,
            function ($event) use ($pending): bool {
                return $event->gameId === 10
                    && $event->pendingInvitations === $pending;
            }
        );
    }
}
