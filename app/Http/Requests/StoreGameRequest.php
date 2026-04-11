<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGameRequest extends FormRequest
{
    /**
     * Determine if the user is authorised to make this request.
     *
     * Logic: Returns true because the route is already protected by the
     * auth:sanctum middleware; any authenticated user may create a game.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Logic: Validates max_players as a required integer strictly between
     * 2 and 8 (inclusive), which is the supported player range for a Monopoly
     * game session.
     *
     * @return array<string, list<string|\Illuminate\Contracts\Validation\Rule>>
     */
    public function rules(): array
    {
        return [
            'max_players'    => ['required', 'integer', 'min:2', 'max:8'],
            'player_icon_id' => ['required', 'integer', 'exists:player_icons,id'],
        ];
    }

    /**
     * Get human-readable error messages for validation failures.
     *
     * Logic: Provides a clear message when max_players falls outside the 2–8
     * range so the API consumer receives actionable feedback.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_players.required' => 'A player count is required.',
            'max_players.integer'  => 'The player count must be a whole number.',
            'max_players.min'      => 'A minimum of 2 players is required.',
            'max_players.max'      => 'A maximum of 8 players is allowed.',
            'player_icon_id.required' => 'A player icon must be selected.',
            'player_icon_id.integer'  => 'The player icon ID must be a whole number.',
            'player_icon_id.exists'   => 'The selected player icon does not exist.',
        ];
    }
}
