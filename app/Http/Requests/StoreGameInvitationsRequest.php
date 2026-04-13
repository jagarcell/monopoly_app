<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGameInvitationsRequest extends FormRequest
{
    /**
     * Determine if the user is authorised to make this request.
     *
     * Logic: Returns true because route-level auth:sanctum middleware already
     * guarantees the request is authenticated. Ownership of the game is
     * verified inside the service layer.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules for sending game invitations.
     *
     * Logic: Validates that emails is a non-empty array of distinct, valid
     * email addresses with a maximum of 7 entries (max_players − 1 for the
     * minimum game of 2). Actual capacity relative to max_players is enforced
     * in the service layer because it requires a database lookup.
     *
     * @return array<string, list<string|\Illuminate\Contracts\Validation\Rule>>
     */
    public function rules(): array
    {
        return [
            'emails'   => ['required', 'array', 'min:1', 'max:7'],
            'emails.*' => ['required', 'email', 'distinct'],
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
            'emails.required'   => 'At least one email address is required.',
            'emails.array'      => 'Emails must be provided as a list.',
            'emails.min'        => 'Please add at least one email address.',
            'emails.max'        => 'You can invite at most 7 players.',
            'emails.*.required' => 'Each email address is required.',
            'emails.*.email'    => 'Each entry must be a valid email address.',
            'emails.*.distinct' => 'Duplicate email addresses are not allowed.',
        ];
    }
}
