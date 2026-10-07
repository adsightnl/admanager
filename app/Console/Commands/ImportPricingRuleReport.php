<?php

namespace App\Console\Commands;

use App\Services\PricingRuleCsvImporter;
use Illuminate\Console\Command;

class ImportPricingRuleReport extends Command
{
    protected $signature = 'pricing-rules:import {path : Path to the Ad Manager CSV report}';

    protected $description = 'Import a Pricing rule | OS | Site | Ad unit report from CSV';

    public function handle(PricingRuleCsvImporter $importer): int
    {
        $result = $importer->import($this->argument('path'));

        $this->info("Imported {$result['rows']} rows for {$result['date']} ({$result['rules_created']} new rules).");

        return self::SUCCESS;
    }
}
