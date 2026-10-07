<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\PricingRuleFloorChange;
use App\Models\PricingRuleStat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PricingRulesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-10-06 12:00:00');
    }

    private function seedRule(string $name, float $revenue, int $impressions): PricingRule
    {
        $rule = PricingRule::create(['name' => $name] + PricingRule::parseName($name));
        PricingRuleStat::create([
            'date' => '2026-10-05', 'os' => 'Android', 'rule_name' => $name, 'site' => 'a.nl', 'ad_unit' => 'u',
            'revenue' => $revenue, 'impressions' => $impressions, 'requests' => $impressions,
        ]);

        return $rule;
    }

    public function test_partner_filter_limits_rules_and_floors_can_be_edited(): void
    {
        $this->seedRule('AS_0.30_mobile', 100, 50000);
        $yit = $this->seedRule('YIT_0.10', 10, 5000);

        $this->actingAs(User::factory()->create());

        $component = Volt::test('pricing-rules')
            ->assertSee('AS_0.30_mobile')
            ->assertSee('YIT_0.10')
            ->set('partners', ['YIT'])
            ->assertDontSee('AS_0.30_mobile')
            ->assertSee('YIT_0.10');

        $component->set("floors.{$yit->id}", '0.15')->call('saveFloor', $yit->id);

        $this->assertSame(0.15, (float) $yit->fresh()->floor);
        $this->assertSame(1, PricingRuleFloorChange::count());
    }
}
