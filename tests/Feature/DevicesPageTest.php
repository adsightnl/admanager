<?php

namespace Tests\Feature;

use App\Models\DeviceStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DevicesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_are_grouped_by_os_and_device(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');
        foreach ([['Android', 'Smartphone', 10], ['Android', 'Tablet', 5], ['iOS', 'Smartphone', 20]] as [$os, $device, $rev]) {
            DeviceStat::create(['date' => '2026-10-05', 'os' => $os, 'device' => $device, 'site' => 'a.nl', 'revenue' => $rev, 'impressions' => 1000, 'requests' => 2000]);
        }
        $this->actingAs(User::factory()->create());

        $component = Volt::test('devices')->assertOk();
        $instance = $component->instance();

        $this->assertSame(['iOS', 'Android'], $instance->byOs->pluck('os')->all());
        $this->assertSame(15.0, $instance->byOs->firstWhere('os', 'Android')->revenue);
        $this->assertSame('Smartphone', $instance->byDevice->first()->device);
        $this->assertSame(30.0, $instance->byDevice->first()->revenue);
        $this->assertCount(3, $instance->combined);
        $this->assertSame(35.0, $instance->totals->revenue);
        $this->assertEqualsWithDelta(0.5, $instance->byOs->first()->fill, 0.0001);
    }

    public function test_ctr_counts_only_rows_whose_clicks_are_known(): void
    {
        DeviceStat::create(['date' => '2026-10-05', 'os' => 'Android', 'device' => 'Smartphone', 'site' => 'a.nl', 'revenue' => 1, 'impressions' => 1000, 'requests' => 2000, 'clicks' => 30]);
        DeviceStat::create(['date' => '2026-10-05', 'os' => 'Android', 'device' => 'Tablet', 'site' => 'a.nl', 'revenue' => 1, 'impressions' => 500, 'requests' => 1000]);

        $this->actingAs(User::factory()->create());

        $component = Volt::test('devices')->set('range', ['start' => '2026-10-05', 'end' => '2026-10-05']);
        $i = $component->instance();

        $this->assertEqualsWithDelta(0.03, $i->byOs->firstWhere('os', 'Android')->ctr, 0.0001);
        $this->assertNull($i->byDevice->firstWhere('device', 'Tablet')->ctr);
        $this->assertEqualsWithDelta(0.03, $i->totals->ctr, 0.0001);
        $component->assertSee('3.00%');
    }

    public function test_domain_filter_merges_www_and_limits_stats(): void
    {
        Carbon::setTestNow('2026-10-06 12:00:00');
        foreach ([['a.nl', 10], ['www.a.nl', 5], ['b.nl', 20]] as [$site, $rev]) {
            DeviceStat::create(['date' => '2026-10-05', 'os' => 'iOS', 'device' => 'Tablet', 'site' => $site, 'revenue' => $rev, 'impressions' => 100, 'requests' => 200]);
        }
        $this->actingAs(User::factory()->create());

        $component = Volt::test('devices');
        $this->assertSame(['a.nl', 'b.nl'], $component->instance()->domains->all());
        $this->assertSame(35.0, $component->instance()->totals->revenue);

        $component->set('domain', 'a.nl');
        $this->assertSame(15.0, $component->instance()->totals->revenue);
    }

    public function test_last_days_shortcuts_end_yesterday(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->actingAs(User::factory()->create());

        $component = Volt::test('devices')->call('lastDays', 7);
        $this->assertSame('2026-10-13', $component->get('range')->start()->toDateString());
        $this->assertSame('2026-10-19', $component->get('range')->end()->toDateString());

        $component->call('lastDays', 30);
        $this->assertSame('2026-09-20', $component->get('range')->start()->toDateString());
        $this->assertSame('2026-10-19', $component->get('range')->end()->toDateString());
    }

    public function test_rolling_picker_presets_end_yesterday(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->actingAs(User::factory()->create());

        $component = Volt::test('devices')->set('range', ['start' => '2026-10-14', 'end' => '2026-10-20', 'preset' => 'last7Days']);
        $this->assertSame('2026-10-13', $component->get('range')->start()->toDateString());
        $this->assertSame('2026-10-19', $component->get('range')->end()->toDateString());

        $component->set('range', ['start' => '2026-09-21', 'end' => '2026-10-20', 'preset' => 'last30Days']);
        $this->assertSame('2026-09-20', $component->get('range')->start()->toDateString());
        $this->assertSame('2026-10-19', $component->get('range')->end()->toDateString());
    }

    public function test_this_month_shortcut_ends_yesterday(): void
    {
        $this->actingAs(User::factory()->create());

        Carbon::setTestNow('2026-10-20 12:00:00');
        $component = Volt::test('devices')->call('thisMonth');
        $this->assertSame('2026-10-01', $component->get('range')->start()->toDateString());
        $this->assertSame('2026-10-19', $component->get('range')->end()->toDateString());

        // The picker's "This month" preset is cut off at yesterday as well.
        Carbon::setTestNow('2026-10-20 12:00:00');
        $picked = Volt::test('devices')->set('range', ['start' => '2026-10-01', 'end' => '2026-10-31', 'preset' => 'thisMonth']);
        $this->assertSame('2026-10-19', $picked->get('range')->end()->toDateString());

        Carbon::setTestNow('2026-11-01 12:00:00');
        $component->call('thisMonth');
        $this->assertSame('2026-10-01', $component->get('range')->start()->toDateString());
        $this->assertSame('2026-10-31', $component->get('range')->end()->toDateString());
    }

    public function test_page_requires_login(): void
    {
        $this->get('/devices')->assertRedirect('/login');
    }
}
