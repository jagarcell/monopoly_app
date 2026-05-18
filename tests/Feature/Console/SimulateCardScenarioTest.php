<?php

namespace Tests\Feature\Console;

use App\Events\CardAccepted;
use App\Events\CardDrawn;
use App\Events\DiceRolled;
use App\Events\TokenMoved;
use App\Events\TurnAdvanced;
use App\Models\PlayerIcon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimulateCardScenarioTest extends TestCase
{
    use RefreshDatabase;

    private string $statePath;

    private bool $stateFileExisted = false;

    private ?string $originalStateFileContents = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = storage_path('app/sim_state.json');
        $this->stateFileExisted = file_exists($this->statePath);
        $this->originalStateFileContents = $this->stateFileExisted
            ? file_get_contents($this->statePath)
            : null;
    }

    protected function tearDown(): void
    {
        if ($this->stateFileExisted) {
            file_put_contents($this->statePath, $this->originalStateFileContents ?? '');
        } elseif (file_exists($this->statePath)) {
            unlink($this->statePath);
        }

        parent::tearDown();
    }

    public function test_command_uses_live_db_state_and_does_not_award_go_bonus_on_synthetic_draw_setup(): void
    {
        Event::fake([
            DiceRolled::class,
            CardDrawn::class,
            CardAccepted::class,
            TokenMoved::class,
            TurnAdvanced::class,
        ]);

        $this->seedScenarioFiveLiveState();

        file_put_contents($this->statePath, json_encode([
            'scenario' => 5,
            'turn' => 1,
            'players' => [
                '1' => ['capital' => 9999, 'square_index' => 39],
                '2' => ['capital' => 1500, 'square_index' => 24],
                '3' => ['capital' => 9999, 'square_index' => 39],
            ],
        ], JSON_PRETTY_PRINT));

        $this->artisan('sim:card-scenario')
            ->expectsOutputToContain('SCENARIO 5/32')
            ->assertExitCode(0);

        $this->assertDatabaseHas('game_player_icons', [
            'game_id' => 61,
            'join_order' => 2,
            'capital' => 1550,
            'square_index' => 15,
        ]);

        $this->assertDatabaseHas('games', [
            'id' => 61,
            'current_turn_join_order' => 3,
            'turn_phase' => 'roll',
        ]);

        Event::assertDispatched(DiceRolled::class, function (DiceRolled $event): bool {
            return $event->currentTurnJoinOrder === 2 && $event->squareIndex === 7;
        });

        Event::assertDispatched(CardDrawn::class, function (CardDrawn $event): bool {
            $payload = $event->broadcastWith();

            return $payload['drawn_by_join_order'] === 2
                && $payload['card']['action'] === 'advance_to_nearest'
                && $payload['card_effect']['new_square_index'] === 15
                && $payload['card_effect']['passed_go'] === false
                && $payload['card_effect']['new_capital'] === null;
        });

        Event::assertDispatched(TurnAdvanced::class, function (TurnAdvanced $event): bool {
            return $event->currentTurnJoinOrder === 3;
        });

        $state = json_decode((string) file_get_contents($this->statePath), true);

        $this->assertSame(6, $state['scenario']);
        $this->assertSame(3, $state['turn']);
        $this->assertSame(1550, $state['players'][2]['capital']);
        $this->assertSame(15, $state['players'][2]['square_index']);
    }

    /**
     * Seed the live DB state that should exist immediately before Scenario 5.
     *
     * Logic: Creates game 61, one authenticated creator, two accepted guest
     * invitations, and the three game_player_icons rows matching the known-good
     * pre-Scenario-5 board state captured during the manual simulation session.
     *
     * @return void
     */
    private function seedScenarioFiveLiveState(): void
    {
        $creator = User::factory()->create([
            'name' => 'Creator',
            'email' => 'creator@example.com',
        ]);

        $hat = PlayerIcon::create([
            'name' => 'Top Hat',
            'image_url' => '/icons/hat.svg',
            'sort_order' => 1,
        ]);
        $car = PlayerIcon::create([
            'name' => 'Race Car',
            'image_url' => '/icons/car.svg',
            'sort_order' => 2,
        ]);
        $iron = PlayerIcon::create([
            'name' => 'Iron',
            'image_url' => '/icons/iron.svg',
            'sort_order' => 3,
        ]);

        DB::table('games')->insert([
            'id' => 61,
            'name' => 'Scenario Test Game',
            'user_id' => $creator->id,
            'status' => 'in_progress',
            'max_players' => 3,
            'current_turn_join_order' => 2,
            'turn_phase' => 'roll',
            'last_die1' => null,
            'last_die2' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('game_invitations')->insert([
            [
                'id' => 60,
                'game_id' => 61,
                'email' => 'guest2@example.com',
                'token' => (string) Str::uuid(),
                'accepted_at' => now(),
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 61,
                'game_id' => 61,
                'email' => 'guest3@example.com',
                'token' => (string) Str::uuid(),
                'accepted_at' => now(),
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('game_player_icons')->insert([
            [
                'game_id' => 61,
                'user_id' => $creator->id,
                'player_icon_id' => $hat->id,
                'invitation_id' => null,
                'join_order' => 1,
                'capital' => 1700,
                'square_index' => 12,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'game_id' => 61,
                'user_id' => null,
                'player_icon_id' => $car->id,
                'invitation_id' => 60,
                'join_order' => 2,
                'capital' => 1550,
                'square_index' => 24,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'game_id' => 61,
                'user_id' => null,
                'player_icon_id' => $iron->id,
                'invitation_id' => 61,
                'join_order' => 3,
                'capital' => 1280,
                'square_index' => 11,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
