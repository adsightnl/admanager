<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    #[Computed]
    public function passkeys(): Collection
    {
        return auth()->user()->passkeys()->latest()->get();
    }

    public function delete(int $id): void
    {
        // Scoped to the current user, so nobody can remove someone else's passkey.
        auth()->user()->passkeys()->whereKey($id)->delete();

        unset($this->passkeys);
        Flux::toast('Passkey removed.');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Passkeys" subheading="Log in with your fingerprint, face or device PIN instead of a password">
        <div class="flex flex-col gap-6">
            <div class="flex flex-col gap-3">
                @forelse ($this->passkeys as $passkey)
                    <div wire:key="passkey-{{ $passkey->id }}" class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex items-center gap-3">
                            <flux:icon.finger-print class="text-zinc-400" />
                            <div>
                                <div class="font-medium">{{ $passkey->name }}</div>
                                <div class="text-sm text-zinc-500">
                                    Added {{ $passkey->created_at->format('d-m-Y') }} ·
                                    {{ $passkey->last_used_at ? 'Last used '.$passkey->last_used_at->format('d-m-Y') : 'Never used' }}
                                </div>
                            </div>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $passkey->id }})" wire:confirm="Remove this passkey?">Remove</flux:button>
                    </div>
                @empty
                    <flux:text>You have no passkeys yet.</flux:text>
                @endforelse
            </div>

            <div
                x-data="{
                    supported: false,
                    name: '',
                    busy: false,
                    error: null,
                    init() { this.supported = !!window.Passkeys?.isSupported(); },
                    async add() {
                        this.busy = true;
                        this.error = null;
                        try {
                            await window.Passkeys.register({ name: this.name.trim() || 'My device' });
                            this.name = '';
                            $wire.$refresh();
                        } catch (e) {
                            const { UserCancelledError, PasskeyExistsError } = window.PasskeyErrors;
                            if (e instanceof PasskeyExistsError) this.error = 'This device already has a passkey.';
                            else if (!(e instanceof UserCancelledError)) this.error = e?.message ?? 'Could not add the passkey.';
                        } finally {
                            this.busy = false;
                        }
                    },
                }"
                class="flex flex-col gap-3"
            >
                <template x-if="!supported">
                    <flux:text>This browser does not support passkeys.</flux:text>
                </template>
                <template x-if="supported">
                    <form x-on:submit.prevent="add" class="flex items-end gap-3">
                        <div class="flex-1">
                            <flux:input x-model="name" label="Name" placeholder="e.g. MacBook, iPhone" />
                        </div>
                        <flux:button type="submit" variant="primary" icon="plus" x-bind:disabled="busy">Add passkey</flux:button>
                    </form>
                </template>
                <flux:text x-show="error" x-text="error" class="text-sm !text-red-600 dark:!text-red-400" />
            </div>
        </div>
    </x-settings.layout>

    <flux:toast />
</section>
