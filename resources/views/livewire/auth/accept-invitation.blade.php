<?php

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $token = '';
    public string $name = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    private function invitation(): ?Invitation
    {
        $invitation = Invitation::findByToken($this->token);

        return $invitation?->isUsable() ? $invitation : null;
    }

    public function accept(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () {
            // Lock the row so a link can't be used twice at the same moment.
            $invitation = Invitation::where('token_hash', hash('sha256', $this->token))->lockForUpdate()->first();

            if (! $invitation?->isUsable() || User::where('email', $invitation->email)->exists()) {
                return null;
            }

            $user = new User(['name' => $this->name, 'email' => $invitation->email, 'password' => Hash::make($this->password)]);
            $user->email_verified_at = now();
            $user->save();

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        if (! $user) {
            $this->addError('token', 'This invitation is no longer valid.');

            return;
        }

        Auth::login($user);
        session()->regenerate();

        $this->redirect(route('dashboard', absolute: false), navigate: true);
    }

    public function with(): array
    {
        return ['invitation' => $this->invitation()];
    }
}; ?>

<div class="flex flex-col gap-6">
    @if ($invitation)
        <x-auth-header title="Accept your invitation" description="Choose a name and password for {{ $invitation->email }}" />

        <flux:error name="token" />

        <form wire:submit="accept" class="flex flex-col gap-6">
            <flux:input wire:model="name" label="Name" type="text" required autofocus autocomplete="name" />
            <flux:input :value="$invitation->email" label="Email address" type="email" readonly disabled />
            <flux:input wire:model="password" label="Password" type="password" required autocomplete="new-password" viewable />
            <flux:input wire:model="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" viewable />
            <flux:button type="submit" variant="primary" class="w-full">Create account</flux:button>
        </form>
    @else
        <x-auth-header title="Invitation not valid" description="This invitation link has expired or was already used. Ask a colleague to send you a new one." />
        <div class="text-center text-sm">
            <x-text-link href="{{ route('login') }}">Back to log in</x-text-link>
        </div>
    @endif
</div>
