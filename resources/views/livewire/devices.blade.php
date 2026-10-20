<?php

use App\Models\DeviceStat;
use App\Services\DomainPerformance;
use Flux\DateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Devices')] class extends Component {
    public ?DateRange $range = null;

    /** '' (all) or a normalised domain. */
    #[Url(as: 'domain')]
    public string $domain = '';

    public function mount(): void
    {
        $yesterday = Carbon::yesterday(config('admanager.time_zone'))->toDateString();
        $this->range = new DateRange($yesterday, $yesterday);
    }

    /** Stats summed over the period, grouped by the given columns. */
    private function grouped(array $columns): Collection
    {
        $from = $this->range?->start()?->toDateString() ?? '1970-01-01';
        $to = $this->range?->end()?->toDateString() ?? '9999-12-31';

        return DeviceStat::whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get()
            ->each(fn ($s) => $s->domain = DomainPerformance::domain($s->site))
            ->when($this->domain !== '', fn ($c) => $c->where('domain', $this->domain))
            ->groupBy(fn ($s) => implode('|', array_map(fn ($c) => $s->{$c}, $columns)))
            ->map(function (Collection $g) use ($columns) {
                $impressions = (int) $g->sum('impressions');
                $revenue = (float) $g->sum('revenue');
                $requests = (int) $g->sum('requests');

                return (object) (collect($columns)->mapWithKeys(fn ($c) => [$c => $g->first()->{$c}])->all() + [
                    'revenue' => $revenue,
                    'impressions' => $impressions,
                    'requests' => $requests,
                    'fill' => $requests > 0 ? $impressions / $requests : null,
                    'ecpm' => $impressions > 0 ? $revenue / $impressions * 1000 : null,
                ]);
            })
            ->sortByDesc('revenue')
            ->values();
    }

    #[Computed]
    public function domains(): Collection
    {
        return DeviceStat::distinct()->pluck('site')
            ->map(fn ($site) => DomainPerformance::domain($site))
            ->unique()->filter()->sort()->values();
    }

    #[Computed]
    public function byOs(): Collection
    {
        return $this->grouped(['os']);
    }

    #[Computed]
    public function byDevice(): Collection
    {
        return $this->grouped(['device']);
    }

    #[Computed]
    public function combined(): Collection
    {
        return $this->grouped(['os', 'device']);
    }

    #[Computed]
    public function totals(): object
    {
        $all = $this->byOs;
        $revenue = (float) $all->sum('revenue');
        $impressions = (int) $all->sum('impressions');

        return (object) [
            'revenue' => $revenue,
            'impressions' => $impressions,
            'ecpm' => $impressions > 0 ? $revenue / $impressions * 1000 : null,
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl">Devices</flux:heading>
        <flux:subheading>Performance per operating system and device type.</flux:subheading>
    </div>

    <div class="flex flex-wrap items-end gap-4">
        <flux:date-picker mode="range" wire:model.live="range" locale="nl-NL" start-day="1" with-presets presets="today yesterday last7Days thisMonth lastMonth allTime" label="Period" />
        <flux:select wire:model.live="domain" label="Domain" class="min-w-52">
            <flux:select.option value="">All domains</flux:select.option>
            @foreach ($this->domains as $d)
                <flux:select.option :value="$d">{{ $d }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="grid grid-cols-3 gap-4">
        <flux:card><flux:subheading>Revenue</flux:subheading><flux:heading size="xl">€ {{ number_format($this->totals->revenue, 2) }}</flux:heading></flux:card>
        <flux:card><flux:subheading>Impressions</flux:subheading><flux:heading size="xl">{{ number_format($this->totals->impressions) }}</flux:heading></flux:card>
        <flux:card><flux:subheading>eCPM</flux:subheading><flux:heading size="xl">{{ $this->totals->ecpm !== null ? '€ '.number_format($this->totals->ecpm, 2) : '—' }}</flux:heading></flux:card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([['Operating system', 'os', $this->byOs], ['Device type', 'device', $this->byDevice]] as [$title, $key, $rows])
            <div class="flex flex-col gap-2">
                <flux:heading>{{ $title }}</flux:heading>
                @include('livewire.partials.device-table', ['label' => $title, 'key' => $key, 'rows' => $rows])
            </div>
        @endforeach
    </div>

    <div class="flex flex-col gap-2">
        <flux:heading>Operating system × device type</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Operating system</flux:table.column>
                <flux:table.column>Device</flux:table.column>
                <flux:table.column align="end">Revenue</flux:table.column>
                <flux:table.column align="end">Impressions</flux:table.column>
                <flux:table.column align="end">Requests</flux:table.column>
                <flux:table.column align="end">Fill</flux:table.column>
                <flux:table.column align="end">eCPM</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->combined as $row)
                    <flux:table.row :key="$row->os.'|'.$row->device">
                        <flux:table.cell variant="strong">{{ $row->os }}</flux:table.cell>
                        <flux:table.cell>{{ $row->device }}</flux:table.cell>
                        <flux:table.cell align="end">€ {{ number_format($row->revenue, 2) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($row->impressions) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($row->requests) }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $row->fill !== null ? number_format($row->fill * 100, 1).'%' : '—' }}</flux:table.cell>
                        <flux:table.cell align="end">{{ $row->ecpm !== null ? '€ '.number_format($row->ecpm, 2) : '—' }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</div>
