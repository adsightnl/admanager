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

    public function test_page_requires_login(): void
    {
        $this->get('/devices')->assertRedirect('/login');
    }
}
