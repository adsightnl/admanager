<?php

use App\Models\PricingRule;
use App\Models\PricingRuleFloorChange;
use App\Models\PricingRuleStat;
use Flux\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    /** @var list<string> Selected partner prefixes (AS, YIT); empty means all. */
    public array $partners = [];

    public ?DateRange $range = null;

    public string $sortBy = 'revenue';

    public string $sortDirection = 'desc';

    /** @var array<int, string> Floor inputs keyed by rule id. */
    public array $floors = [];

    public function mount(): void
    {
        $yesterday = Carbon::yesterday(config('admanager.time_zone'))->toDateString();
        $this->range = new DateRange($yesterday, $yesterday);
        $this->partners = array_keys(config('pricing.partners'));
        $this->loadFloors();
    }

    public function sort(string $column): void
    {
        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'desc' ? 'asc' : 'desc';
        $this->sortBy = $column;
    }

    public function saveFloor(int $ruleId): void
    {
        $this->validate(['floors.'.$ruleId => ['nullable', 'numeric', 'min:0', 'max:1000']]);

        $rule = PricingRule::findOrFail($ruleId);
        $new = $this->floors[$ruleId] === '' ? null : round((float) $this->floors[$ruleId], 4);

        if ($new === ($rule->floor === null ? null : (float) $rule->floor)) {
            return;
        }

        PricingRuleFloorChange::create([
            'pricing_rule_id' => $rule->id,
            'old_floor' => $rule->floor,
            'new_floor' => $new,
            'user_id' => auth()->id(),
        ]);
        $rule->update(['floor' => $new, 'floor_is_override' => true]);

        Flux::toast("Floor for {$rule->name} saved.", variant: 'success');
    }

    #[Computed]
    public function rows()
    {
        $stats = PricingRuleStat::query()
            ->select('rule_name', DB::raw('SUM(revenue) as revenue'), DB::raw('SUM(impressions) as impressions'), DB::raw('SUM(requests) as requests'))
            ->whereDate('date', '>=', $this->range?->start()?->toDateString() ?? '1970-01-01')
            ->whereDate('date', '<=', $this->range?->end()?->toDateString() ?? '9999-12-31')
            ->where('rule_name', '!=', PricingRuleStat::NO_RULE)
            ->groupBy('rule_name')
            ->get()
            ->keyBy('rule_name');

        $rules = PricingRule::query()
            ->whereIn('name', $stats->keys())
            ->when($this->partners !== [], fn ($q) => $q->whereIn('prefix', $this->partners))
            ->get();

        return $rules->map(function (PricingRule $rule) use ($stats) {
            $s = $stats[$rule->name];
            $impressions = (int) $s->impressions;
            $ecpm = $impressions > 0 ? $s->revenue / $impressions * 1000 : null;

            return (object) [
                'rule' => $rule,
                'revenue' => (float) $s->revenue,
                'impressions' => $impressions,
                'requests' => (int) $s->requests,
                'ecpm' => $ecpm,
                'fill' => $s->requests > 0 ? $impressions / $s->requests : null,
                'headroom' => $ecpm !== null && $rule->floor > 0 ? $ecpm / (float) $rule->floor : null,
            ];
        })->sortBy(fn ($r) => $r->{$this->sortBy} ?? $r->rule->{$this->sortBy} ?? 0, SORT_REGULAR, $this->sortDirection === 'desc')->values();
    }

    private function loadFloors(): void
    {
        $this->floors = PricingRule::pluck('floor', 'id')
            ->map(fn ($f) => $f === null ? '' : rtrim(rtrim((string) $f, '0'), '.'))
            ->all();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Pricing rules</flux:heading>
        <flux:subheading>Floors and realized performance per rule. Editing a floor here records the change; apply it in Ad Manager afterwards.</flux:subheading>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:checkbox.group wire:model.live="partners" label="Partner">
            @foreach (config('pricing.partners') as $prefix => $label)
                <flux:checkbox value="{{ $prefix }}" label="{{ $label }}" />
            @endforeach
        </flux:checkbox.group>
        <flux:date-picker mode="range" wire:model.live="range" locale="nl-NL" start-day="1" with-presets presets="yesterday last7Days thisMonth lastMonth allTime" label="Period" />
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Rule</flux:table.column>
            <flux:table.column>Partner</flux:table.column>
            <flux:table.column>Floor (€ CPM)</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'revenue'" :direction="$sortDirection" wire:click="sort('revenue')">Revenue</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'impressions'" :direction="$sortDirection" wire:click="sort('impressions')">Impressions</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'ecpm'" :direction="$sortDirection" wire:click="sort('ecpm')">eCPM</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'headroom'" :direction="$sortDirection" wire:click="sort('headroom')">eCPM / floor</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->rows as $row)
                <flux:table.row :key="$row->rule->id">
                    <flux:table.cell variant="strong">{{ $row->rule->name }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm">{{ $row->rule->partner() ?? $row->rule->prefix ?? '—' }}</flux:badge></flux:table.cell>
                    <flux:table.cell>
                        <flux:input size="sm" class="max-w-24" inputmode="decimal"
                            wire:model="floors.{{ $row->rule->id }}" wire:change="saveFloor({{ $row->rule->id }})" />
                    </flux:table.cell>
                    <flux:table.cell align="end">€ {{ number_format($row->revenue, 2) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($row->impressions) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $row->ecpm !== null ? '€ '.number_format($row->ecpm, 2) : '—' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($row->headroom !== null)
                            <flux:badge size="sm" :color="$row->headroom >= 3 ? 'amber' : 'zinc'">{{ number_format($row->headroom, 1) }}×</flux:badge>
                        @else
                            —
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    <flux:toast />
</div>
