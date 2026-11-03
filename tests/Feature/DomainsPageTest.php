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

    public function test_ctr_counts_only_rows_whose_clicks_are_known(): void
    {
        $this->stat('a.nl', 'AS_0.30_mobile', 10, 1000, 1000);
        PricingRuleStat::where('site', 'a.nl')->update(['clicks' => 20]);
        $this->stat('www.a.nl', 'YIT_0.10', 5, 500, 500); // synced before clicks existed
        $this->stat('b.nl', 'AS_0.30_mobile', 1, 100, 100);

        $this->actingAs(User::factory()->create());

        $component = Volt::test('domains');
        $rows = $component->instance()->rows;

        $this->assertEqualsWithDelta(20 / 1000, $rows->firstWhere('domain', 'a.nl')->ctr, 0.0001);
        $this->assertNull($rows->firstWhere('domain', 'b.nl')->ctr);
        $this->assertEqualsWithDelta(20 / 1000, $component->instance()->totals->ctr, 0.0001);

        $component->call('select', 'a.nl');
        $this->assertNull($component->instance()->breakdown->firstWhere('rule', 'YIT_0.10')->ctr);
        $component->assertSee('2.00%');
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
        $table = strpos($html, 'No-rule requests'); // skip the rule filter's options above the table
        $this->assertTrue(strpos($html, 'a.nl', $table) < strpos($html, 'YIT_0.10', $table));
        $this->assertTrue(strpos($html, 'YIT_0.10', $table) < strpos($html, 'b.nl', $table), 'breakdown should sit directly under its domain, before the next domain');

        $component->call('select', 'a.nl')->assertDontSee('Floor (€ CPM)');
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
        $this->assertSame('red', $band(0.299));
        $this->assertSame('orange', $band(0.30));
        $this->assertSame('orange', $band(0.449));
        $this->assertSame('green', $band(0.45));
        $this->assertSame('green', $band(0.799));
        $this->assertSame('orange', $band(0.80));
        $this->assertSame('orange', $band(1.0));
    }

    public function test_fill_filter_sits_with_search_and_limits_domains(): void
    {
        // fill: red.nl 20%, orange.nl 40%, green.nl 70%, high.nl 90%
        foreach (['red.nl' => 20, 'orange.nl' => 40, 'green.nl' => 70, 'high.nl' => 90] as $site => $pct) {
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
        $component->assertSeeHtml('bg-orange-400');
    }

    public function test_daily_chart_data_follows_the_period_and_domain_filter(): void
    {
        foreach (['2026-10-04', '2026-10-05'] as $day) {
            foreach (['a.nl' => 10, 'b.nl' => 100] as $site => $rev) {
                PricingRuleStat::create(['date' => $day, 'os' => 'iOS', 'rule_name' => 'AS_0.30_mobile', 'site' => $site, 'ad_unit' => 'u', 'revenue' => $rev, 'impressions' => 50, 'requests' => 100, 'ecpm' => 2]);
            }
        }
        $this->actingAs(User::factory()->create());

        $component = Volt::test('domains')->set('range', ['start' => '2026-10-04', 'end' => '2026-10-05']);
        $data = $component->instance()->chartData;
        $this->assertSame(['2026-10-04', '2026-10-05'], array_column($data, 'date'));
        $this->assertSame(110.0, $data[0]['revenue']);
        $this->assertSame(50.0, $data[0]['fill']);
        $this->assertSame(2.0, $data[0]['requestEcpm']);

        $this->assertSame([], $component->instance()->selectedChartData);
        $component->call('select', 'b.nl');
        $this->assertSame(100.0, $component->instance()->selectedChartData[0]['revenue']);
        $component->assertSee('Daily trend — b.nl')->assertSeeHtml('interval="day"');

        $component->set('search', 'a.nl');
        $this->assertSame(10.0, $component->instance()->chartData[0]['revenue']);
        $component->assertSee('Daily trend');
    }

    public function test_pricing_rule_filter_limits_table_and_chart(): void
    {
        foreach (['AS_0.30_mobile' => 10, 'YIT_0.10' => 40] as $rule => $rev) {
            foreach (['2026-10-04', '2026-10-05'] as $day) {
                PricingRuleStat::create(['date' => $day, 'os' => 'iOS', 'rule_name' => $rule, 'site' => 'a.nl', 'ad_unit' => 'u', 'revenue' => $rev, 'impressions' => 50, 'requests' => 100]);
            }
        }
        $this->actingAs(User::factory()->create());

        $component = Volt::test('domains')->set('range', ['start' => '2026-10-04', 'end' => '2026-10-05']);
        $this->assertSame(['AS_0.30_mobile', 'YIT_0.10'], $component->instance()->rules->all());
        $this->assertSame(100.0, $component->instance()->rows->first()->revenue);

        $component->set('rule', 'YIT_0.10');
        $this->assertSame(80.0, $component->instance()->rows->first()->revenue);
        $this->assertSame(40.0, $component->instance()->chartData[0]['revenue']);
        $this->assertSame(100.0, $component->instance()->chartData[0]['revenueIndex']);
        $this->assertSame(80.0, $component->instance()->totals->revenue);
    }

    public function test_expanded_domain_chart_can_be_limited_to_one_pricing_rule(): void
    {
        foreach (['AS_0.30_mobile' => 10, 'YIT_0.10' => 40] as $rule => $rev) {
            foreach (['2026-10-04', '2026-10-05'] as $day) {
                PricingRuleStat::create(['date' => $day, 'os' => 'iOS', 'rule_name' => $rule, 'site' => 'a.nl', 'ad_unit' => 'u', 'revenue' => $rev, 'impressions' => 50, 'requests' => 100]);
            }
        }
        $this->actingAs(User::factory()->create());

        $component = Volt::test('domains')->set('range', ['start' => '2026-10-04', 'end' => '2026-10-05'])->call('select', 'a.nl');
        $this->assertSame(50.0, $component->instance()->selectedChartData[0]['revenue']);

        $component->set('selectedRule', 'AS_0.30_mobile');
        $this->assertSame(10.0, $component->instance()->selectedChartData[0]['revenue']);
        $component->assertSee('Chart pricing rule');

        // Single-day period: no chart, so no dropdown either.
        $single = Volt::test('domains')->set('range', ['start' => '2026-10-05', 'end' => '2026-10-05'])->call('select', 'a.nl');
        $single->assertDontSee('Chart pricing rule');

        // Collapsing and re-opening resets the dropdown.
        $component->call('select', 'a.nl')->call('select', 'a.nl');
        $this->assertSame('', $component->get('selectedRule'));
    }
}
