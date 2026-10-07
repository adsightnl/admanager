<?php

namespace Tests\Feature;

use App\Models\PricingRuleStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 12:00:00'); // yesterday = 7 Oct
    }

    private function stat(string $date, string $site, string $rule, float $rev, int $imp, int $req): void
    {
        PricingRuleStat::create([
            'date' => $date, 'os' => 'Android', 'rule_name' => $rule, 'site' => $site, 'ad_unit' => 'u',
            'revenue' => $rev, 'impressions' => $imp, 'requests' => $req,
        ]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertOk()->assertSee('No data imported yet.');
    }

    public function test_kpis_are_compared_with_the_previous_period_and_lists_are_ranked(): void
    {
        // Default period = 1-7 Oct (7 days ending yesterday). Previous = 24-30 Sep.
        $this->stat('2026-09-28', 'a.nl', 'AS_0.30_mobile', 50, 5000, 10000);   // previous: €50
        $this->stat('2026-10-02', 'a.nl', 'AS_0.30_mobile', 60, 6000, 10000);
        $this->stat('2026-10-02', 'www.a.nl', PricingRuleStat::NO_RULE, 0, 0, 30000);
        $this->stat('2026-10-03', 'b.nl', 'YIT_0.10', 40, 8000, 10000);          // 80% fill -> orange
        $this->actingAs(User::factory()->create());

        $c = Volt::test('dashboard')->assertOk()->assertSee('Latest data: 03-10-2026')->assertSee('Not up to date');
        $i = $c->instance();

        $this->assertEqualsWithDelta(100.0, $i->summary->revenue, 0.001);
        $this->assertEqualsWithDelta(100.0, $i->change('revenue'), 0.001); // 50 -> 100 = +100%

        // Lists: a.nl earns most; its 30k unmatched requests make it the top lost-traffic domain.
        $this->assertSame(['a.nl', 'b.nl'], $i->topDomains->pluck('domain')->all());
        $this->assertSame(['a.nl'], $i->lostTraffic->pluck('domain')->all());
        $this->assertSame(['red' => 1, 'orange' => 1, 'green' => 0], $i->fillBands); // a.nl 15%, b.nl 80%

        $this->assertCount(2, $i->chartData);
        $c->assertSee('+100.0%');
    }

    public function test_period_without_data_shows_an_empty_state_and_all_time_works(): void
    {
        $this->stat('2026-01-15', 'a.nl', 'AS_0.30_mobile', 10, 1000, 1000);
        $this->actingAs(User::factory()->create());

        $c = Volt::test('dashboard')->assertSee('No data for this period');
        $this->assertNull($c->instance()->previous);

        $c->set('range', null)->assertDontSee('No data for this period')->assertSee('€ 10.00');
    }
}
