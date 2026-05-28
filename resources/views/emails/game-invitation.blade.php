<x-mail::message>
# You've been invited to play Monopoly!

**{{ $creatorName }}** has invited you to join **{{ $gameName }}**.

{{ $instructionText }}

<x-mail::button :url="$joinUrl" color="green">
{{ $buttonLabel }}
</x-mail::button>

This invitation expires on **{{ $expiresAt }}**.

If you did not expect this email, you can safely ignore it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
