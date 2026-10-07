<?php

use App\Models\PricingRule;
use App\Models\PricingRuleStat;
use Flux\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    public ?DateRange $range = null;

    public string $search = '';

    public string $sortBy = 'revenue';

    public string $sortDirection = 'desc';

    public ?string $selected = null;

    public function mount(): void
    {
        $yesterday = Carbon::yesterday(config('admanager.time_zone'))->toDateString();
        $this->range = new DateRange($yesterday, $yesterday);
    }

    public function sort(string $column): void
    {
        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'desc' ? 'asc' : 'desc';
        $this->sortBy = $column;
    }

    public function select(string $domain): void
    {
        $this->selected = $this->selected === $domain ? null : $domain;
    }

    private static function domain(string $site): string
    {
        return strtolower(preg_replace('/^www\./i', '', $site));
    }

    /** Stats for the period, one row per site/rule, with the domain normalised. */
    private function stats(): Collection
    {
        return PricingRuleStat::query()
            ->selectRaw('site, rule_name, SUM(revenue) as revenue, SUM(impressions) as impressions, SUM(requests) as requests')
            ->whereDate('date', '>=', $this->range?->start()?->toDateString() ?? '1970-01-01')
            ->whereDate('date', '<=', $this->range?->end()?->toDateString() ?? '9999-12-31')
            ->groupBy('site', 'rule_name')
            ->get()
            ->each(fn ($r) => $r->domain = self::domain($r->site));
    }

    #[Computed]
    public function rows(): Collection
    {
        return $this->stats()
            ->groupBy('domain')
            ->map(function (Collection $g, string $domain) {
                $revenue = (float) $g->sum('revenue');
                $impressions = (int) $g->sum('impressions');
                $requests = (int) $g->sum('requests');
                $unruled = (int) $g->where('rule_name', PricingRuleStat::NO_RULE)->sum('requests');

                return (object) [
                    'domain' => $domain,
                    'revenue' => $revenue,
                    'impressions' => $impressions,
                    'requests' => $requests,
                    'ecpm' => $impressions > 0 ? $revenue / $impressions * 1000 : null,
                    'fill' => $requests > 0 ? $impressions / $requests : null,
                    'unruled' => $unruled,
                ];
            })
            ->when($this->search !== '', fn ($c) => $c->filter(fn ($r) => str_contains($r->domain, strtolower(trim($this->search)))))
            ->sortBy(fn ($r) => $r->{$this->sortBy} ?? 0, SORT_REGULAR, $this->sortDirection === 'desc')
            ->values();
    }

    #[Computed]
    public function totals(): object
    {
        $rows = $this->rows;
        $impressions = $rows->sum('impressions');
        $requests = $rows->sum('requests');

        return (object) [
            'revenue' => $rows->sum('revenue'),
            'impressions' => $impressions,
            'requests' => $requests,
            'ecpm' => $impressions > 0 ? $rows->sum('revenue') / $impressions * 1000 : null,
            'fill' => $requests > 0 ? $impressions / $requests : null,
        ];
    }

    /** Per-rule breakdown for the selected domain. */
    #[Computed]
    public function breakdown(): Collection
    {
        if ($this->selected === null) {
            return collect();
        }

        $floors = PricingRule::pluck('floor', 'name');

        return $this->stats()
            ->where('domain', $this->selected)
            ->groupBy('rule_name')
            ->map(function (Collection $g, string $rule) use ($floors) {
                $impressions = (int) $g->sum('impressions');
                $revenue = (float) $g->sum('revenue');
                $requests = (int) $g->sum('requests');
                $ecpm = $impressions > 0 ? $revenue / $impressions * 1000 : null;
                $floor = $floors[$rule] ?? null;

                return (object) [
                    'rule' => $rule,
                    'floor' => $floor,
                    'revenue' => $revenue,
                    'impressions' => $impressions,
                    'requests' => $requests,
                    'ecpm' => $ecpm,
                    'headroom' => $ecpm !== null && $floor > 0 ? $ecpm / (float) $floor : null,
                ];
            })
            ->sortByDesc('revenue')
            ->values();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Domains</flux:heading>
        <flux:subheading>Performance per domain. Sites with and without “www.” are combined.</flux:subheading>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:date-picker mode="range" wire:model.live="range" locale="nl-NL" start-day="1" with-presets presets="yesterday last7Days thisMonth lastMonth allTime" label="Period" />
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search domain" label="Domain" />
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <flux:card><flux:subheading>Revenue</flux:subheading><flux:heading size="xl">€ {{ number_format($this->totals->revenue, 2) }}</flux:heading></flux:card>
        <flux:card><flux:subheading>Impressions</flux:subheading><flux:heading size="xl">{{ number_format($this->totals->impressions) }}</flux:heading></flux:card>
        <flux:card><flux:subheading>eCPM</flux:subheading><flux:heading size="xl">{{ $this->totals->ecpm !== null ? '€ '.number_format($this->totals->ecpm, 2) : '—' }}</flux:heading></flux:card>
        <flux:card><flux:subheading>Fill rate</flux:subheading><flux:heading size="xl">{{ $this->totals->fill !== null ? number_format($this->totals->fill * 100, 1).'%' : '—' }}</flux:heading></flux:card>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Domain</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'revenue'" :direction="$sortDirection" wire:click="sort('revenue')">Revenue</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'impressions'" :direction="$sortDirection" wire:click="sort('impressions')">Impressions</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'requests'" :direction="$sortDirection" wire:click="sort('requests')">Requests</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'fill'" :direction="$sortDirection" wire:click="sort('fill')">Fill</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'ecpm'" :direction="$sortDirection" wire:click="sort('ecpm')">eCPM</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'unruled'" :direction="$sortDirection" wire:click="sort('unruled')">No-rule requests</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->rows as $row)
                <flux:table.row :key="$row->domain" class="cursor-pointer" wire:click="select('{{ $row->domain }}')">
                    <flux:table.cell variant="strong">
                        <span class="flex items-center gap-2">
                            <flux:icon.chevron-right variant="micro" class="transition-transform {{ $selected === $row->domain ? 'rotate-90' : '' }}" />
                            {{ $row->domain }}
                        </span>
                    </flux:table.cell>
                    <flux:table.cell align="end">€ {{ number_format($row->revenue, 2) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($row->impressions) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($row->requests) }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $row->fill !== null ? number_format($row->fill * 100, 1).'%' : '—' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $row->ecpm !== null ? '€ '.number_format($row->ecpm, 2) : '—' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($row->unruled) }}</flux:table.cell>
                </flux:table.row>
                @if ($selected === $row->domain)
                    <flux:table.row :key="$row->domain.'-rules'" class="bg-zinc-50 dark:bg-white/5">
                        <flux:table.cell colspan="7" class="!py-3 !pl-10">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-zinc-500">
                                        <th class="py-1 font-medium">Pricing rule</th>
                                        <th class="py-1 text-end font-medium">Floor (€ CPM)</th>
                                        <th class="py-1 text-end font-medium">Revenue</th>
                                        <th class="py-1 text-end font-medium">Impressions</th>
                                        <th class="py-1 text-end font-medium">Requests</th>
                                        <th class="py-1 text-end font-medium">eCPM</th>
                                        <th class="py-1 text-end font-medium">eCPM / floor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->breakdown as $b)
                                        <tr wire:key="{{ $row->domain }}-{{ $b->rule }}" class="border-t border-zinc-200 dark:border-white/10">
                                            <td class="py-1.5">{{ $b->rule }}</td>
                                            <td class="py-1.5 text-end">{{ $b->floor !== null ? number_format((float) $b->floor, 2) : '—' }}</td>
                                            <td class="py-1.5 text-end">€ {{ number_format($b->revenue, 2) }}</td>
                                            <td class="py-1.5 text-end">{{ number_format($b->impressions) }}</td>
                                            <td class="py-1.5 text-end">{{ number_format($b->requests) }}</td>
                                            <td class="py-1.5 text-end">{{ $b->ecpm !== null ? '€ '.number_format($b->ecpm, 2) : '—' }}</td>
                                            <td class="py-1.5 text-end">
                                                @if ($b->headroom !== null)
                                                    <flux:badge size="sm" :color="$b->headroom >= 3 ? 'amber' : 'zinc'">{{ number_format($b->headroom, 1) }}×</flux:badge>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </flux:table.cell>
                    </flux:table.row>
                @endif
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
