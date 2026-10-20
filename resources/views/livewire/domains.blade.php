<?php

use App\Models\PricingRule;
use App\Models\PricingRuleStat;
use App\Services\DomainPerformance;
use Flux\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Domains')] class extends Component {
    public ?DateRange $range = null;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' (all) or a fill colour: red, orange, green. */
    #[Url(as: 'fill')]
    public string $fillFilter = '';

    /** '' (all) or a pricing rule name. */
    #[Url(as: 'rule')]
    public string $rule = '';

    public string $sortBy = 'revenue';

    public string $sortDirection = 'desc';

    public ?string $selected = null;

    /** Pricing rule for the expanded domain's chart; '' means all rules. */
    public string $selectedRule = '';

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
        $this->selectedRule = '';
    }

    private function from(): string
    {
        return $this->range?->start()?->toDateString() ?? '1970-01-01';
    }

    private function to(): string
    {
        return $this->range?->end()?->toDateString() ?? '9999-12-31';
    }

    #[Computed]
    public function rows(): Collection
    {
        return app(DomainPerformance::class)->domains($this->from(), $this->to(), $this->rule ?: null)
            ->when($this->search !== '', fn ($c) => $c->filter(fn ($r) => str_contains($r->domain, strtolower(trim($this->search)))))
            ->when($this->fillFilter !== '', fn ($c) => $c->filter(fn ($r) => $r->fillColor === $this->fillFilter))
            ->sortBy(fn ($r) => $r->{$this->sortBy} ?? 0, SORT_REGULAR, $this->sortDirection === 'desc')
            ->values();
    }

    #[Computed]
    public function rules(): Collection
    {
        return app(DomainPerformance::class)->rules($this->from(), $this->to());
    }

    #[Computed]
    public function chartData(): array
    {
        return $this->dailySeries($this->rows->pluck('domain')->all());
    }

    /** Daily points for the expanded domain. */
    #[Computed]
    public function selectedChartData(): array
    {
        return $this->selected === null ? [] : $this->dailySeries([$this->selected], $this->selectedRule ?: null);
    }

    /** @param  list<string>  $domains */
    private function dailySeries(array $domains, ?string $rule = null): array
    {
        $days = app(DomainPerformance::class)
            ->daily($this->from(), $this->to(), $domains, $rule ?? ($this->rule ?: null))
            ->map(fn ($d) => [
                'date' => $d->date,
                'revenue' => round($d->revenue, 2),
                'fill' => round(($d->fill ?? 0) * 100, 1),
                'requestEcpm' => round($d->requestEcpm ?? 0, 4),
            ]);

        $max = fn (string $field) => max($days->max($field), 0.0001);

        return $days->map(fn ($d) => $d + [
            'revenueIndex' => round($d['revenue'] / $max('revenue') * 100, 1),
            'fillIndex' => round($d['fill'] / $max('fill') * 100, 1),
            'requestEcpmIndex' => round($d['requestEcpm'] / $max('requestEcpm') * 100, 1),
        ])->values()->all();
    }

    #[Computed]
    public function totals(): object
    {
        return app(DomainPerformance::class)->summary($this->from(), $this->to(), $this->rule ?: null);
    }

    /** Per-rule breakdown for the selected domain. */
    #[Computed]
    public function breakdown(): Collection
    {
        if ($this->selected === null) {
            return collect();
        }

        $floors = PricingRule::pluck('floor', 'name');

        return app(DomainPerformance::class)->stats($this->from(), $this->to(), $this->rule ?: null)
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
                    'requestEcpm' => $requests > 0 ? (float) $g->sum('ecpm_weighted') / $requests : null,
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
        <flux:date-picker mode="range" wire:model.live="range" locale="nl-NL" start-day="1" with-presets presets="today yesterday last7Days thisMonth lastMonth allTime" label="Period" />
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search domain" label="Domain" />
        <flux:select wire:model.live="rule" label="Pricing rule" class="min-w-52">
            <flux:select.option value="">All rules</flux:select.option>
            @foreach ($this->rules as $r)
                <flux:select.option :value="$r">{{ $r }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="fillFilter" label="Status" class="min-w-52">
            <flux:select.option value="">All</flux:select.option>
            <flux:select.option value="red">Red (under 30%)</flux:select.option>
            <flux:select.option value="orange">Orange (30–45% and 80%+)</flux:select.option>
            <flux:select.option value="green">Green (45–80%)</flux:select.option>
        </flux:select>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <flux:card><flux:subheading>Revenue</flux:subheading><flux:heading size="xl">€ {{ number_format($this->totals->revenue, 2) }}</flux:heading></flux:card>
        <flux:card><flux:subheading>Impressions</flux:subheading><flux:heading size="xl">{{ number_format($this->totals->impressions) }}</flux:heading></flux:card>
        <flux:card><flux:subheading>eCPM</flux:subheading><flux:heading size="xl">{{ $this->totals->ecpm !== null ? '€ '.number_format($this->totals->ecpm, 2) : '—' }}</flux:heading></flux:card>
        <flux:card><flux:subheading>Fill rate</flux:subheading><flux:heading size="xl" class="flex items-center gap-2">{{ $this->totals->fill !== null ? number_format($this->totals->fill * 100, 1).'%' : '—' }}</flux:heading></flux:card>
    </div>

    @if (count($this->chartData) > 1)
        @include('livewire.partials.domain-chart', ['data' => $this->chartData, 'title' => 'Daily trend — all shown domains', 'key' => 'all'])
    @else
        <flux:callout icon="chart-bar" variant="secondary">
            <flux:callout.text>Pick a period of two or more days to see the daily trend.</flux:callout.text>
        </flux:callout>
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Domain</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'revenue'" :direction="$sortDirection" wire:click="sort('revenue')">Revenue</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'impressions'" :direction="$sortDirection" wire:click="sort('impressions')">Impressions</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'requests'" :direction="$sortDirection" wire:click="sort('requests')">Requests</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'fill'" :direction="$sortDirection" wire:click="sort('fill')">Fill</flux:table.column>
            <flux:table.column align="center">Status</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'ecpm'" :direction="$sortDirection" wire:click="sort('ecpm')">eCPM</flux:table.column>
            <flux:table.column align="end" sortable :sorted="$sortBy === 'requestEcpm'" :direction="$sortDirection" wire:click="sort('requestEcpm')">Request eCPM</flux:table.column>
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
                    <flux:table.cell align="center"><span @class(['inline-block size-2.5 shrink-0 rounded-full', 'bg-red-500' => $row->fillColor === 'red', 'bg-orange-400' => $row->fillColor === 'orange', 'bg-green-500' => $row->fillColor === 'green', 'bg-zinc-300 dark:bg-zinc-600' => $row->fillColor === null]) title="Fill rate"></span></flux:table.cell>
                    <flux:table.cell align="end">{{ $row->ecpm !== null ? '€ '.number_format($row->ecpm, 2) : '—' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $row->requestEcpm !== null ? '€ '.number_format($row->requestEcpm, 2) : '—' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($row->unruled) }}</flux:table.cell>
                </flux:table.row>
                @if ($selected === $row->domain)
                    <flux:table.row :key="$row->domain.'-rules'" class="bg-zinc-50 dark:bg-white/5">
                        <flux:table.cell colspan="9" class="!py-3 !pl-10 !pr-10">
                            @if (count($this->selectedChartData) > 1 || $selectedRule !== '')
                                <flux:select wire:model.live="selectedRule" label="Chart pricing rule" class="mb-3 max-w-xs">
                                    <flux:select.option value="">All rules</flux:select.option>
                                    @foreach ($this->breakdown->pluck('rule')->reject(fn ($r) => $r === PricingRuleStat::NO_RULE) as $r)
                                        <flux:select.option :value="$r">{{ $r }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @if (count($this->selectedChartData) > 1)
                                    <div class="mb-4">
                                        @include('livewire.partials.domain-chart', ['data' => $this->selectedChartData, 'title' => 'Daily trend — '.$row->domain, 'key' => $row->domain])
                                    </div>
                                @endif
                            @endif
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-zinc-500">
                                        <th class="py-1 font-medium">Pricing rule</th>
                                        <th class="py-1 text-end font-medium">Floor (€ CPM)</th>
                                        <th class="py-1 text-end font-medium">Revenue</th>
                                        <th class="py-1 text-end font-medium">Impressions</th>
                                        <th class="py-1 text-end font-medium">Requests</th>
                                        <th class="py-1 text-end font-medium">eCPM</th>
                                        <th class="py-1 text-end font-medium">Request eCPM</th>
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
                                            <td class="py-1.5 text-end">{{ $b->requestEcpm !== null ? '€ '.number_format($b->requestEcpm, 2) : '—' }}</td>
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
