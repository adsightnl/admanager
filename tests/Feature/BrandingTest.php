<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_uses_the_adsight_mark_title_and_favicons(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('adsight-mark', false)
            ->assertSee('<title>Log in - Adsight</title>', false)
            ->assertSee('favicon.svg', false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('Or continue with email')
            ->assertDontSee('Laravel Starter Kit');
    }

    public function test_app_pages_show_the_full_logo_and_a_page_title(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('images/logo.png', false)
            ->assertSee('<title>Dashboard - Adsight</title>', false);
    }

    public function test_brand_assets_exist(): void
    {
        foreach (['favicon.ico', 'favicon.svg', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png', 'images/logo.png', 'images/wordmark.png'] as $file) {
            $this->assertGreaterThan(0, filesize(public_path($file)), "{$file} is missing or empty");
        }
    }
}
