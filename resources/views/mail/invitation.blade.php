<x-mail::message>
# You're invited

{{ $inviter ? $inviter.' has invited' : 'You have been invited' }} you to join {{ config('app.name') }}.

<x-mail::button :url="$url">
Accept invitation
</x-mail::button>

This link is valid for {{ $days }} days. If you weren't expecting this, you can ignore this email.
</x-mail::message>
