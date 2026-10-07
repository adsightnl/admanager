<?php

namespace App\Services;

use App\Models\PricingRule;
use App\Models\PricingRuleStat;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Imports an Ad Manager historical report exported as CSV with the columns:
 * Operating system category, Pricing rule, Site, Ad unit, Ad Exchange revenue,
 * Ad Exchange match rate, Ad Exchange impressions, Ad Exchange total requests.
 */
class PricingRuleCsvImporter
{
    /** @return array{rows: int, rules_created: int, date: string} */
    public function import(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("Cannot open {$path}");
        }

        $currency = 'EUR';
        $date = null;
        $header = null;
        $rows = 0;
        $rulesCreated = 0;

        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            if ($header === null) {
                match ($line[0]) {
                    'Report currency' => $currency = $line[1],
                    'Date range' => $date = Carbon::parse($line[1])->toDateString(),
                    'Operating system category' => $header = $line,
                    default => null,
                };

                continue;
            }

            if ($date === null) {
                throw new InvalidArgumentException('No single-day "Date range" found in report header.');
            }

            [$os, $rule, $site, $adUnit, $revenue, , $impressions, $requests] = $line;

            if ($rule !== PricingRuleStat::NO_RULE && ! PricingRule::where('name', $rule)->exists()) {
                PricingRule::create(['name' => $rule, 'currency' => $currency] + PricingRule::parseName($rule));
                $rulesCreated++;
            }

            PricingRuleStat::upsert(
                [[
                    'date' => $date, 'os' => $os, 'rule_name' => $rule, 'site' => $site, 'ad_unit' => $adUnit,
                    'revenue' => $revenue, 'impressions' => (int) $impressions, 'requests' => (int) $requests,
                    'currency' => $currency,
                ]],
                ['date', 'os', 'rule_name', 'site', 'ad_unit'],
                ['revenue', 'impressions', 'requests', 'currency'],
            );
            $rows++;
        }

        fclose($handle);

        if ($header === null) {
            throw new InvalidArgumentException('Header row "Operating system category,..." not found.');
        }

        return ['rows' => $rows, 'rules_created' => $rulesCreated, 'date' => (string) $date];
    }
}
