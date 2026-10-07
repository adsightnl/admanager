<?php

namespace Tests\Feature;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_page_requires_login(): void
    {
        $this->get('/team')->assertRedirect('/login');
    }

    public function test_member_can_invite_by_email_and_the_link_is_mailed(): void
    {
        Mail::fake();
        $this->actingAs($admin = User::factory()->create());

        Volt::test('team')->set('email', 'New@Example.com')->call('invite')->assertHasNoErrors();

        $invitation = Invitation::firstWhere('email', 'new@example.com');
        $this->assertNotNull($invitation);
        $this->assertSame($admin->id, $invitation->invited_by);
        Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('new@example.com') && str_contains($m->url, '/invitations/'));
    }

    public function test_cannot_invite_an_existing_user(): void
    {
        Mail::fake();
        $this->actingAs($user = User::factory()->create());

        Volt::test('team')->set('email', $user->email)->call('invite')->assertHasErrors('email');
        Mail::assertNothingSent();
    }

    public function test_token_is_not_stored_in_plain_text(): void
    {
        [$invitation, $token] = Invitation::issue('a@example.com', null);

        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
    }

    public function test_invited_person_can_set_a_password_and_log_in(): void
    {
        [$invitation, $token] = Invitation::issue('new@example.com', null);

        $this->get("/invitations/{$token}")->assertOk()->assertSee('new@example.com');

        Volt::test('auth.accept-invitation', ['token' => $token])
            ->set('name', 'New Person')
            ->set('password', 'a-Strong-pass-123')
            ->set('password_confirmation', 'a-Strong-pass-123')
            ->call('accept')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::firstWhere('email', 'new@example.com');
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_link_cannot_be_used_twice(): void
    {
        [, $token] = Invitation::issue('new@example.com', null);
        $fill = fn () => Volt::test('auth.accept-invitation', ['token' => $token])
            ->set('name', 'N')->set('password', 'a-Strong-pass-123')->set('password_confirmation', 'a-Strong-pass-123')
            ->call('accept');

        $fill()->assertHasNoErrors();
        auth()->logout();

        $fill()->assertHasErrors('token');
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
    }

    public function test_expired_or_unknown_links_are_rejected(): void
    {
        [$invitation, $token] = Invitation::issue('old@example.com', null);
        $invitation->update(['expires_at' => now()->subMinute()]);

        $this->get("/invitations/{$token}")->assertOk()->assertSee('Invitation not valid');
        $this->get('/invitations/does-not-exist')->assertOk()->assertSee('Invitation not valid');

        Volt::test('auth.accept-invitation', ['token' => $token])
            ->set('name', 'N')->set('password', 'a-Strong-pass-123')->set('password_confirmation', 'a-Strong-pass-123')
            ->call('accept')->assertHasErrors('token');
        $this->assertSame(0, User::where('email', 'old@example.com')->count());
    }

    public function test_resend_issues_a_new_link_and_invalidates_the_old_one(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());
        [$invitation, $oldToken] = Invitation::issue('new@example.com', null);

        Volt::test('team')->call('resend', $invitation->id);

        $this->assertNull(Invitation::findByToken($oldToken));
        Mail::assertSent(InvitationMail::class);
    }

    public function test_revoked_invitation_stops_working(): void
    {
        $this->actingAs(User::factory()->create());
        [$invitation, $token] = Invitation::issue('new@example.com', null);

        Volt::test('team')->call('revoke', $invitation->id);

        $this->assertNull(Invitation::findByToken($token));
    }
}
