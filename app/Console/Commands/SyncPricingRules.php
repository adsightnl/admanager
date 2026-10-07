<?php

namespace App\Console\Commands;

use App\Services\AdManagerPricingRuleSync;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncPricingRules extends Command
{
    protected $signature = 'pricing-rules:sync {--date= : Day to pull (Y-m-d); defaults to yesterday}';

    protected $description = 'Pull pricing rule stats from Google Ad Manager';

    public function handle(AdManagerPricingRuleSync $sync): int
    {
        $day = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday(config('admanager.time_zone'));

        $result = $sync->sync($day);

        $this->info("Synced {$result['rows']} rows for {$day->toDateString()} ({$result['rules_created']} new rules).");

        return self::SUCCESS;
    }
}
