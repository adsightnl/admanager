<?php

namespace App\Livewire\Concerns;

use Flux\DateRange;
use Flux\DateRangePreset;
use Illuminate\Support\Carbon;

/** Picker presets "Last 7/30 days" and "This month", which end yesterday for a `$range` date picker. They end yesterday, since today is still running. */
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
        match ($this->range?->preset()) {
            DateRangePreset::Last7Days => $this->lastDays(7),
            DateRangePreset::Last30Days => $this->lastDays(30),
            DateRangePreset::ThisMonth => $this->thisMonth(),
            default => null,
        };
    }

    /** From the 1st of the month through yesterday (on the 1st itself that is the whole previous month). */
    public function thisMonth(): void
    {
        $yesterday = Carbon::yesterday(config('admanager.time_zone'));

        $this->range = new DateRange($yesterday->copy()->startOfMonth()->toDateString(), $yesterday->toDateString());
    }
}
