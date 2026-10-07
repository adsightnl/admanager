<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    private function passkeyFor(User $user, string $name): Passkey
    {
        return Passkey::forceCreate([
            'user_id' => $user->id,
            'name' => $name,
            'credential_id' => 'cred-'.$name.'-'.$user->id,
            'credential' => ['id' => 'x'],
        ]);
    }

    public function test_user_is_passkey_capable(): void
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(PasskeyUser::class, $user);
        $this->assertFalse($user->hasPasskeysEnabled());

        $this->passkeyFor($user, 'MacBook');
        $this->assertTrue($user->fresh()->hasPasskeysEnabled());
    }

    public function test_login_page_offers_passkey_login_and_the_options_endpoint_works_for_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in with a passkey');

        $this->getJson('/passkeys/login/options')
            ->assertOk()
            ->assertJsonStructure(['options' => ['challenge', 'rpId', 'timeout']]);
    }

    public function test_registration_options_require_login(): void
    {
        $this->getJson('/user/passkeys/options')->assertUnauthorized();
    }

    public function test_settings_page_needs_login_and_a_recent_password_confirmation(): void
    {
        $this->get('/settings/passkeys')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get('/settings/passkeys')
            ->assertRedirect(route('password.confirm'));
    }

    public function test_settings_page_lists_own_passkeys_only(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->passkeyFor($me, 'My MacBook');
        $this->passkeyFor($other, 'Their phone');

        $this->actingAs($me)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get('/settings/passkeys')
            ->assertOk()
            ->assertSee('My MacBook')
            ->assertDontSee('Their phone');
    }

    public function test_user_can_remove_own_passkey_but_not_someone_elses(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->passkeyFor($me, 'mine');
        $theirs = $this->passkeyFor($other, 'theirs');

        $this->actingAs($me);
        Volt::test('settings.passkeys')->call('delete', $mine->id)->call('delete', $theirs->id);

        $this->assertDatabaseMissing('passkeys', ['id' => $mine->id]);
        $this->assertDatabaseHas('passkeys', ['id' => $theirs->id]);
    }
}
