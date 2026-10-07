<?php

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    public string $email = '';

    /** Link of the invitation just created, shown once so it can be shared when mail is not set up. */
    public ?string $freshLink = null;

    public ?string $freshEmail = null;

    public function invite(): void
    {
        $this->email = strtolower(trim($this->email));

        $this->validate([
            'email' => ['required', 'email:rfc', 'max:255', 'unique:'.User::class.',email'],
        ], [
            'email.unique' => 'This person already has an account.',
        ]);

        $this->send($this->email);
        $this->reset('email');
    }

    public function resend(int $id): void
    {
        $this->send(Invitation::whereNull('accepted_at')->findOrFail($id)->email);
    }

    public function revoke(int $id): void
    {
        Invitation::whereNull('accepted_at')->whereKey($id)->delete();
        $this->freshLink = $this->freshEmail = null;
        Flux::toast('Invitation revoked.');
    }

    private function send(string $email): void
    {
        [$invitation, $token] = Invitation::issue($email, auth()->user());
        $url = $invitation->url($token);

        Mail::to($invitation->email)->send(new InvitationMail($invitation->load('inviter'), $url));

        $this->freshLink = $url;
        $this->freshEmail = $invitation->email;
        Flux::toast("Invitation sent to {$invitation->email}.", variant: 'success');
    }

    #[Computed]
    public function members()
    {
        return User::orderBy('name')->get();
    }

    #[Computed]
    public function pending()
    {
        return Invitation::with('inviter')->whereNull('accepted_at')->orderByDesc('created_at')->get();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Team</flux:heading>
        <flux:subheading>Signup is closed. Invite colleagues by email; the link is valid for {{ Invitation::VALID_DAYS }} days.</flux:subheading>
    </div>

    <form wire:submit="invite" class="flex max-w-xl items-start gap-3">
        <div class="flex-1">
            <flux:input wire:model="email" type="email" placeholder="colleague@company.com" label="Invite by email" />
            <flux:error name="email" />
        </div>
        <flux:button type="submit" variant="primary" class="mt-6">Send invite</flux:button>
    </form>

    @if ($freshLink)
        <flux:callout icon="link" variant="secondary" class="max-w-3xl">
            <flux:callout.heading>Invitation link for {{ $freshEmail }}</flux:callout.heading>
            <flux:callout.text>
                An email was sent. You can also share this link yourself; it is only shown now.
                <flux:input readonly copyable value="{{ $freshLink }}" class="mt-2" />
            </flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->pending->isNotEmpty())
        <div class="flex flex-col gap-2">
            <flux:heading size="lg">Pending invitations</flux:heading>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Email</flux:table.column>
                    <flux:table.column>Invited by</flux:table.column>
                    <flux:table.column>Expires</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->pending as $invitation)
                        <flux:table.row :key="'inv-'.$invitation->id">
                            <flux:table.cell variant="strong">{{ $invitation->email }}</flux:table.cell>
                            <flux:table.cell>{{ $invitation->inviter?->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($invitation->isUsable())
                                    {{ $invitation->expires_at->format('d-m-Y') }}
                                @else
                                    <flux:badge size="sm" color="red">Expired</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" wire:click="resend({{ $invitation->id }})">Resend</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="revoke({{ $invitation->id }})" wire:confirm="Revoke this invitation?">Revoke</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <div class="flex flex-col gap-2">
        <flux:heading size="lg">Members</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Email</flux:table.column>
                <flux:table.column>Joined</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->members as $member)
                    <flux:table.row :key="'user-'.$member->id">
                        <flux:table.cell variant="strong">{{ $member->name }}</flux:table.cell>
                        <flux:table.cell>{{ $member->email }}</flux:table.cell>
                        <flux:table.cell>{{ $member->created_at?->format('d-m-Y') }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:toast />
</div>
