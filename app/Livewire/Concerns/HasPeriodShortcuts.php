<?php

namespace App\Livewire\Concerns;

use Flux\DateRange;
use Flux\DateRangePreset;
use Illuminate\Support\Carbon;

/**
 * Picker presets "Last 7/30 days" and "This month", which end yesterday for a `$range` date picker. They end yesterday, since today is still running.
 * The chosen period is kept in the session, so it carries over between pages.
 */
trait HasPeriodShortcuts
{
    public function lastDays(int $days): void
    {
        $yesterday = Carbon::yesterday(config('admanager.time_zone'));

        $this->range = new DateRange($yesterday->copy()->subDays($days - 1)->toDateString(), $yesterday->toDateString());
    }

    /** The picker's rolling presets include today; cut them off at yesterday. */
    public function updatedRange(): void
    {
        $preset = $this->range?->preset();

        $this->applyShortcut($preset);

        session()->put('period', $this->range ? [
            'start' => $this->range->start()?->toDateString(),
            'end' => $this->range->end()?->toDateString(),
            'preset' => $preset?->value,
        ] : null);
    }

    /** From the 1st of the month through yesterday (on the 1st itself that is the whole previous month). */
    public function thisMonth(): void
    {
        $yesterday = Carbon::yesterday(config('admanager.time_zone'));

        $this->range = new DateRange($yesterday->copy()->startOfMonth()->toDateString(), $yesterday->toDateString());
    }

    /** The period last picked on any page, or the page's default. Presets are rebuilt, so "Yesterday" stays yesterday. */
    protected function restoreRange(DateRange $default): void
    {
        if (! session()->has('period')) {
            $this->range = $default;

            return;
        }

        $saved = session('period');
        $preset = DateRangePreset::tryFrom($saved['preset'] ?? '');

        $this->range = match (true) {
            $saved === null => null,
            $preset === DateRangePreset::AllTime => DateRange::allTime($saved['start']),
            $preset !== null && $preset !== DateRangePreset::Custom => DateRange::{$preset->value}(),
            default => new DateRange($saved['start'], $saved['end']),
        };

        $this->applyShortcut($preset);
    }

    private function applyShortcut(?DateRangePreset $preset): void
    {
        match ($preset) {
            DateRangePreset::Last7Days => $this->lastDays(7),
            DateRangePreset::Last30Days => $this->lastDays(30),
            DateRangePreset::ThisMonth => $this->thisMonth(),
            default => null,
        };
    }
}
