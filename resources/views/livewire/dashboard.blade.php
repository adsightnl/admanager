<?php

use App\Services\DomainPerformance;
use Carbon\CarbonInterface;
use Flux\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public ?DateRange $range = null;

    public function mount(): void
    {
        $yesterday = Carbon::yesterday(config('admanager.time_zone'));
        $this->range = new DateRange($yesterday->copy()->subDays(6)->toDateString(), $yesterday->toDateString());
    }

    private function start(): ?CarbonInterface
    {
        return $this->range?->start();
    }

    private function end(): ?CarbonInterface
    {
        return $this->range?->end();
    }

    private function from(): string
    {
        return $this->start()?->toDateString() ?? '1970-01-01';
    }

    private function to(): string
    {
        return $this->end()?->toDateString() ?? '9999-12-31';
    }

    #[Computed]
    public function summary(): object
    {
        return app(DomainPerformance::class)->summary($this->from(), $this->to());
    }

    /** The period of equal length directly before the selected one; null for "all time". */
    #[Computed]
    public function previous(): ?object
    {
        if (! $this->start() || ! $this->end()) {
            return null;
        }

        $days = $this->start()->diffInDays($this->end()) + 1;
        $to = $this->start()->copy()->subDay();
        $from = $to->copy()->subDays($days - 1);
        $summary = app(DomainPerformance::class)->summary($from->toDateString(), $to->toDateString());

        return $summary->requests > 0 ? $summary : null;
    }

    #[Computed]
    public function daily(): Collection
    {
        return app(DomainPerformance::class)->daily($this->from(), $this->to());
    }

    /** @return list<array{date: string, revenue: float, ecpm: float}> */
    #[Computed]
    public function chartData(): array
    {
        return $this->daily
            ->map(fn ($d) => ['date' => $d->date, 'revenue' => round($d->revenue, 2), 'ecpm' => round($d->ecpm ?? 0, 2)])
            ->values()
            ->all();
    }

    #[Computed]
    public function domains(): Collection
    {
        return app(DomainPerformance::class)->domains($this->from(), $this->to());
    }

    #[Computed]
    public function topDomains(): Collection
    {
        return $this->domains->sortByDesc('revenue')->take(5)->values();
    }

    #[Computed]
    public function lostTraffic(): Collection
    {
        return $this->domains->where('unruled', '>', 0)->sortByDesc('unruled')->take(5)->values();
    }

    #[Computed]
    public function fillBands(): array
    {
        $counts = $this->domains->countBy('fillColor');

        return ['red' => $counts['red'] ?? 0, 'orange' => $counts['orange'] ?? 0, 'green' => $counts['green'] ?? 0];
    }

    /** Relative change in percent, or null when there is nothing to compare with. */
    public function change(string $metric): ?float
    {
        $before = $this->previous?->{$metric};

        return $before ? ($this->summary->{$metric} - $before) / $before * 100 : null;
    }

    #[Computed]
    public function latestDate(): ?string
    {
        return app(DomainPerformance::class)->latestDate();
    }

    #[Computed]
    public function isStale(): bool
    {
        return $this->latestDate === null
            || $this->latestDate < Carbon::yesterday(config('admanager.time_zone'))->toDateString();
    }
}; ?>

@php
    $s = $this->summary;
    $dot = fn (?string $c) => match ($c) {
        'red' => 'bg-red-500', 'orange' => 'bg-orange-500', 'green' => 'bg-green-500', default => 'bg-zinc-300 dark:bg-zinc-600',
    };
    $kpis = [
        ['label' => 'Revenue', 'value' => '€ '.number_format($s->revenue, 2), 'change' => $this->change('revenue')],
        ['label' => 'Impressions', 'value' => number_format($s->impressions), 'change' => $this->change('impressions')],
        ['label' => 'eCPM', 'value' => $s->ecpm !== null ? '€ '.number_format($s->ecpm, 2) : '—', 'change' => $this->change('ecpm')],
        ['label' => 'Fill rate', 'value' => $s->fill !== null ? number_format($s->fill * 100, 1).'%' : '—', 'change' => null,
            'points' => $this->previous && $s->fill !== null && $this->previous->fill !== null ? ($s->fill - $this->previous->fill) * 100 : null, 'dot' => $s->fillColor],
    ];
@endphp

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">Dashboard</flux:heading>
            <flux:subheading class="flex items-center gap-2">
                @if ($this->latestDate)
                    Latest data: {{ \Illuminate\Support\Carbon::parse($this->latestDate)->format('d-m-Y') }}
                    @if ($this->isStale)
                        <flux:badge size="sm" color="amber" icon="exclamation-triangle">Not up to date</flux:badge>
                    @endif
                @else
                    No data imported yet.
                @endif
            </flux:subheading>
        </div>
        <flux:date-picker mode="range" wire:model.live="range" locale="nl-NL" start-day="1" with-presets presets="yesterday last7Days thisMonth lastMonth allTime" label="Period" />
    </div>

    @if ($s->requests === 0)
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.heading>No data for this period</flux:callout.heading>
            <flux:callout.text>Pick another period, or run <code>php artisan pricing-rules:sync</code> to import it.</flux:callout.text>
        </flux:callout>
    @else
        {{-- KPIs, each compared with the period of equal length before it --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($kpis as $k)
                <flux:card class="flex flex-col gap-1">
                    <flux:subheading>{{ $k['label'] }}</flux:subheading>
                    <flux:heading size="xl" class="flex items-center gap-2">
                        @if (isset($k['dot']))<span class="inline-block size-2.5 shrink-0 rounded-full {{ $dot($k['dot']) }}"></span>@endif
                        {{ $k['value'] }}
                    </flux:heading>
                    @php($delta = $k['change'] ?? $k['points'] ?? null)
                    @if ($delta !== null)
                        <div class="flex items-center gap-1 text-sm {{ $delta >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                            <flux:icon :icon="$delta >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" variant="micro" />
                            {{ ($delta >= 0 ? '+' : '−').number_format(abs($delta), 1) }}{{ isset($k['points']) ? ' pts' : '%' }}
                            <span class="text-zinc-500">vs previous period</span>
                        </div>
                    @else
                        <div class="text-sm text-zinc-500">No previous period</div>
                    @endif
                </flux:card>
            @endforeach
        </div>

        {{-- Trend: two small charts, each on its own scale --}}
        @if (count($this->chartData) > 1)
            <div class="grid gap-4 lg:grid-cols-2" wire:key="charts-{{ $this->from() }}-{{ $this->to() }}">
                @foreach (['revenue' => ['Revenue per day', '€'], 'ecpm' => ['eCPM per day', '€']] as $field => [$title, $cur])
                    <flux:card>
                        <flux:heading class="mb-3">{{ $title }}</flux:heading>
                        <flux:chart :value="$this->chartData" class="aspect-[3/1]">
                            <flux:chart.svg>
                                <flux:chart.line :field="$field" class="text-blue-600 dark:text-blue-400" />
                                <flux:chart.point :field="$field" class="text-blue-600 dark:text-blue-400" r="3" />
                                <flux:chart.axis axis="x" field="date">
                                    <flux:chart.axis.tick :format="['day' => '2-digit', 'month' => '2-digit']" />
                                    <flux:chart.axis.line />
                                </flux:chart.axis>
                                <flux:chart.axis axis="y" tick-prefix="{{ $cur }} ">
                                    <flux:chart.axis.grid />
                                    <flux:chart.axis.tick />
                                </flux:chart.axis>
                                <flux:chart.cursor />
                            </flux:chart.svg>
                            <flux:chart.tooltip>
                                <flux:chart.tooltip.heading field="date" :format="['day' => '2-digit', 'month' => '2-digit', 'year' => 'numeric']" />
                                <flux:chart.tooltip.value :field="$field" :label="$title" :format="['style' => 'currency', 'currency' => 'EUR']" />
                            </flux:chart.tooltip>
                        </flux:chart>
                    </flux:card>
                @endforeach
            </div>
        @else
            <flux:callout icon="chart-bar" variant="secondary">
                <flux:callout.text>Pick a period of two or more days to see the trend.</flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid gap-4 lg:grid-cols-3">
            {{-- Domains by fill-rate colour --}}
            <flux:card class="flex flex-col gap-3">
                <flux:heading>Domains by fill rate</flux:heading>
                @foreach (['red' => 'Under 50%', 'orange' => '50–60% and 80%+', 'green' => '60–80%'] as $band => $label)
                    <a href="{{ route('domains', ['fill' => $band]) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-white/5">
                        <span class="flex items-center gap-2">
                            <span class="inline-block size-2.5 shrink-0 rounded-full {{ $dot($band) }}"></span>
                            {{ $label }}
                        </span>
                        <span class="font-medium">{{ $this->fillBands[$band] }}</span>
                    </a>
                @endforeach
            </flux:card>

            {{-- Top earners --}}
            <flux:card class="flex flex-col gap-2">
                <flux:heading>Top domains by revenue</flux:heading>
                @foreach ($this->topDomains as $d)
                    <a href="{{ route('domains', ['q' => $d->domain]) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-white/5">
                        <span class="flex items-center gap-2">
                            <span class="inline-block size-2.5 shrink-0 rounded-full {{ $dot($d->fillColor) }}"></span>
                            {{ $d->domain }}
                        </span>
                        <span class="font-medium">€ {{ number_format($d->revenue, 2) }}</span>
                    </a>
                @endforeach
            </flux:card>

            {{-- Requests nobody bid on --}}
            <flux:card class="flex flex-col gap-2">
                <flux:heading>Most requests with no pricing rule</flux:heading>
                @forelse ($this->lostTraffic as $d)
                    <a href="{{ route('domains', ['q' => $d->domain]) }}" wire:navigate class="flex items-center justify-between rounded-lg px-2 py-1.5 hover:bg-zinc-50 dark:hover:bg-white/5">
                        <span>{{ $d->domain }}</span>
                        <span class="text-end">
                            <span class="font-medium">{{ number_format($d->unruled) }}</span>
                            <span class="text-sm text-zinc-500">{{ number_format($d->unruled / $d->requests * 100) }}% of requests</span>
                        </span>
                    </a>
                @empty
                    <flux:text>None in this period.</flux:text>
                @endforelse
            </flux:card>
        </div>
    @endif
</div>
