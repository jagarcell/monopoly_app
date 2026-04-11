<?php

namespace Database\Factories;

use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Game>
     */
    protected $model = Game::class;

    /**
     * Define the model's default state.
     *
     * Logic: Generates a game record with a sequential-style name, associating
     * it with a randomly created user; status defaults to in_progress.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'name'        => 'Game #' . fake()->numberBetween(1, 100),
            'status'      => GameStatus::InProgress->value,
            'max_players' => fake()->numberBetween(2, 8),
        ];
    }
}
