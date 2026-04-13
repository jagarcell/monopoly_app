<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptGameInvitationRequest extends FormRequest
{
    /**
     * Determine if the user is authorised to make this request.
     *
     * Logic: Returns true unconditionally — accepting a game invitation is an
     * unauthenticated action gated solely by possession of the valid token.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for accepting a game invitation.
     *
     * Logic: Validates that player_icon_id is a required integer that references
     * an existing row in the player_icons table. The service layer additionally
     * verifies the token is valid, not yet accepted, and not expired.
     *
     * @return array<string, list<string|\Illuminate\Contracts\Validation\Rule>>
     */
    public function rules(): array
    {
        return [
            'player_icon_id' => ['required', 'integer', 'exists:player_icons,id'],
        ];
    }

    /**
     * Get human-readable error messages for validation failures.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'player_icon_id.required' => 'A player icon must be selected.',
            'player_icon_id.integer'  => 'The player icon ID must be a whole number.',
            'player_icon_id.exists'   => 'The selected player icon does not exist.',
        ];
    }
}
