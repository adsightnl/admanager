<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\PricingRuleStat;
use App\Models\User;
use App\Services\DomainPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DomainsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 12:00:00');
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

        PricingRule::create(['name' => 'YIT_0.10'] + PricingRule::parseName('YIT_0.10'));

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

    public function test_fill_rate_colour_bands(): void
    {
        $band = fn (?float $f) => DomainPerformance::fillColor($f);

        $this->assertNull($band(null));
        $this->assertSame('red', $band(0.0));
        $this->assertSame('red', $band(0.499));
        $this->assertSame('orange', $band(0.50));
        $this->assertSame('orange', $band(0.599));
        $this->assertSame('green', $band(0.60));
        $this->assertSame('green', $band(0.799));
        $this->assertSame('orange', $band(0.80));
        $this->assertSame('orange', $band(1.0));
    }

    public function test_fill_filter_sits_with_search_and_limits_domains(): void
    {
        // fill: red.nl 40%, orange.nl 55%, green.nl 70%, high.nl 90%
        foreach (['red.nl' => 40, 'orange.nl' => 55, 'green.nl' => 70, 'high.nl' => 90] as $site => $pct) {
            $this->stat($site, 'AS_0.30_mobile', 1, $pct, 100);
        }
        $this->actingAs(User::factory()->create());

        $component = Volt::test('domains');
        $names = fn () => $component->instance()->rows->pluck('domain')->sort()->values()->all();

        $this->assertSame(['green.nl', 'high.nl', 'orange.nl', 'red.nl'], $names());
        $component->set('fillFilter', 'red');
        $this->assertSame(['red.nl'], $names());
        $component->set('fillFilter', 'orange');
        $this->assertSame(['high.nl', 'orange.nl'], $names());
        $component->set('fillFilter', 'green');
        $this->assertSame(['green.nl'], $names());

        $component->set('fillFilter', 'orange')->set('search', 'high');
        $this->assertSame(['high.nl'], $names());
        $component->assertSeeHtml('bg-orange-500');
    }
}
