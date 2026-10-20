<?php

namespace App\Console\Commands;

use App\Services\AdManagerDeviceSync;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncDeviceStats extends Command
{
    protected $signature = 'device-stats:sync {--date= : Day to pull (Y-m-d); defaults to yesterday}';

    protected $description = 'Pull OS category and device category stats from Google Ad Manager';

    public function handle(AdManagerDeviceSync $sync): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date'), config('admanager.time_zone')) : Carbon::yesterday(config('admanager.time_zone'));

        $result = $sync->sync($day);

        $this->info("Synced {$result['rows']} rows for {$day->toDateString()}.");

        return self::SUCCESS;
    }
}
