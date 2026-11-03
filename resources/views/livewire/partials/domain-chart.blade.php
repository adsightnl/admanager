@php
    $series = [
        'revenue' => ['Revenue', 'text-blue-600 dark:text-blue-400', ['style' => 'currency', 'currency' => 'EUR']],
        'fill' => ['Fill rate (%)', 'text-orange-500 dark:text-orange-400', ['maximumFractionDigits' => 1]],
        'requestEcpm' => ['Request eCPM', 'text-emerald-600 dark:text-emerald-400', ['style' => 'currency', 'currency' => 'EUR', 'maximumFractionDigits' => 4]],
        'ctr' => ['CTR (%)', 'text-violet-600 dark:text-violet-400', ['maximumFractionDigits' => 2]],
    ];
    // Hide CTR when no day in the period has clicks synced yet.
    if (collect($data)->whereNotNull('ctr')->isEmpty()) {
        unset($series['ctr']);
    }
@endphp
<flux:card wire:key="chart-{{ $key }}-{{ md5(json_encode($data)) }}">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <flux:heading>{{ $title }}</flux:heading>
        <div class="flex flex-wrap items-center gap-4 text-sm">
            @foreach ($series as [$label, $color])
                <span class="flex items-center gap-1.5"><span class="inline-block h-0.5 w-4 rounded-full bg-current {{ $color }}"></span>{{ $label }}</span>
            @endforeach
        </div>
    </div>
    <flux:chart :value="$data" class="aspect-[4/1] min-h-56">
        <flux:chart.svg>
            @foreach ($series as $field => [$label, $color])
                <flux:chart.line :field="$field.'Index'" :class="$color" />
                <flux:chart.point :field="$field.'Index'" :class="$color" r="3" />
            @endforeach
            {{-- Short periods would otherwise get weekday + hour labels; force one label per day. --}}
            <flux:chart.axis axis="x" field="date" :format="['day' => '2-digit', 'month' => '2-digit']" :interval="count($data) <= 14 ? 'day' : null">
                <flux:chart.axis.tick />
                <flux:chart.axis.line />
            </flux:chart.axis>
            <flux:chart.axis axis="y" tick-suffix="%">
                <flux:chart.axis.grid />
                <flux:chart.axis.tick />
            </flux:chart.axis>
            <flux:chart.cursor />
        </flux:chart.svg>
        <flux:chart.tooltip>
            <flux:chart.tooltip.heading field="date" :format="['day' => '2-digit', 'month' => '2-digit', 'year' => 'numeric']" />
            @foreach ($series as $field => [$label, $color, $format])
                <flux:chart.tooltip.value :field="$field" :label="$label" :format="$format" />
            @endforeach
        </flux:chart.tooltip>
    </flux:chart>
    <flux:subheading class="mt-2">Each line is scaled to its own highest day in the period (100%); hover for the real values.</flux:subheading>
</flux:card>
