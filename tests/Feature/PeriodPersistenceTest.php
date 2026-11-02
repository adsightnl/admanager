<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PeriodPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_picked_period_carries_over_to_other_pages(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('domains')->set('range', ['start' => '2026-10-01', 'end' => '2026-10-05']);

        $range = Volt::test('devices')->get('range');
        $this->assertSame('2026-10-01', $range->start()->toDateString());
        $this->assertSame('2026-10-05', $range->end()->toDateString());
    }

    public function test_a_rolling_preset_is_rebuilt_and_still_ends_yesterday(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('devices')->set('range', ['start' => '2026-10-01', 'end' => '2026-10-07', 'preset' => 'last7Days']);

        $range = Volt::test('dashboard')->get('range');
        $yesterday = Carbon::yesterday(config('admanager.time_zone'));
        $this->assertSame($yesterday->toDateString(), $range->end()->toDateString());
        $this->assertSame($yesterday->copy()->subDays(6)->toDateString(), $range->start()->toDateString());
    }

    public function test_pages_use_their_own_default_until_a_period_is_picked(): void
    {
        $this->actingAs(User::factory()->create());

        $yesterday = Carbon::yesterday(config('admanager.time_zone'))->toDateString();
        $range = Volt::test('domains')->get('range');
        $this->assertSame($yesterday, $range->start()->toDateString());
        $this->assertSame($yesterday, $range->end()->toDateString());
    }
}
