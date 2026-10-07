<?php

namespace Tests\Feature;

use App\Models\PricingRuleStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DomainsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-06 12:00:00');
    }

    private function stat(string $site, string $rule, float $rev, int $imp, int $req): void
    {
        PricingRuleStat::create([
            'date' => '2026-10-05', 'os' => 'Android', 'rule_name' => $rule, 'site' => $site, 'ad_unit' => 'u',
            'revenue' => $rev, 'impressions' => $imp, 'requests' => $req,
        ]);
    }

    public function test_www_variants_are_merged_and_unruled_requests_are_counted(): void
    {
        $this->stat('a.nl', 'AS_0.30_mobile', 10, 1000, 1000);
        $this->stat('www.a.nl', 'YIT_0.10', 5, 500, 500);
        $this->stat('a.nl', PricingRuleStat::NO_RULE, 0, 0, 2000);
        $this->stat('b.nl', 'AS_0.30_mobile', 1, 100, 100);

        $this->actingAs(User::factory()->create());

        $component = Volt::test('domains');
        $rows = $component->instance()->rows;

        $this->assertCount(2, $rows);
        $a = $rows->firstWhere('domain', 'a.nl');
        $this->assertSame(15.0, $a->revenue);
        $this->assertSame(1500, $a->impressions);
        $this->assertSame(3500, $a->requests);
        $this->assertSame(2000, $a->unruled);
        $this->assertEqualsWithDelta(1500 / 3500, $a->fill, 0.0001);

        \App\Models\PricingRule::create(['name' => 'YIT_0.10'] + \App\Models\PricingRule::parseName('YIT_0.10'));

        $component->call('select', 'a.nl');
        $breakdown = $component->instance()->breakdown->firstWhere('rule', 'YIT_0.10');
        $this->assertSame('0.1000', (string) $breakdown->floor);
        $this->assertEqualsWithDelta(10.0 / 0.10, $breakdown->headroom, 0.0001); // eCPM 10.00 / floor 0.10
        $this->assertNull($component->instance()->breakdown->firstWhere('rule', PricingRuleStat::NO_RULE)->floor);

        $html = $component->html();
        $this->assertStringContainsString('YIT_0.10', $html);
        $this->assertTrue(strpos($html, 'a.nl') < strpos($html, 'YIT_0.10'));
        $this->assertTrue(strpos($html, 'YIT_0.10') < strpos($html, 'b.nl'), 'breakdown should sit directly under its domain, before the next domain');

        $component->call('select', 'a.nl')->assertDontSee('YIT_0.10');
    }

    public function test_date_range_picker_filters_the_period_and_is_formatted_for_dutch(): void
    {
        $this->stat('a.nl', 'AS_0.30_mobile', 10, 1000, 1000);
        $this->actingAs(User::factory()->create());

        $component = Volt::test('domains')->assertSeeHtml('locale="nl-NL"');
        $this->assertCount(1, $component->instance()->rows);

        // Same payload shape the browser sends from the picker.
        $component->set('range', ['start' => '2026-10-06', 'end' => '2026-10-07']);
        $this->assertCount(0, $component->instance()->rows);

        $component->set('range', ['start' => '2026-10-01', 'end' => '2026-10-31']);
        $this->assertCount(1, $component->instance()->rows);

        // "All time" / cleared picker must not break the page.
        $component->set('range', null)->assertOk();
        $this->assertCount(1, $component->instance()->rows);
    }
}
