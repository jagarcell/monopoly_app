<x-mail::message>
# You've been invited to play Monopoly!

**{{ $creatorName }}** has invited you to join **{{ $gameName }}**.

Click the button below to pick your player token and join the game. No account required.

<x-mail::button :url="$joinUrl" color="green">
Join the Game
</x-mail::button>

This invitation expires on **{{ $expiresAt }}**.

If you did not expect this email, you can safely ignore it.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
