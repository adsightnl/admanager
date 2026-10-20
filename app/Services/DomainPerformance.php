<?php

namespace App\Services;

use App\Models\PricingRuleStat;
use Illuminate\Support\Collection;

/**
 * Read model over pricing_rule_stats: performance per domain, daily trend and
 * period totals. "www." variants of a site are merged into one domain.
 */
class DomainPerformance
{
    /** Fill-rate colour band: <30% red, 30-45% orange, 45-80% green, 80%+ orange. */
    public static function fillColor(?float $fill): ?string
    {
        return match (true) {
            $fill === null => null,
            $fill < 0.30 => 'red',
            $fill < 0.45 => 'orange',
            $fill < 0.80 => 'green',
            default => 'orange',
        };
    }

    public static function domain(string $site): string
    {
        return strtolower(preg_replace('/^www\./i', '', $site));
    }

    /** One row per site and rule for the period, with the normalised domain attached. */
    public function stats(string $from, string $to, ?string $rule = null): Collection
    {
        return PricingRuleStat::query()
            ->selectRaw('site, rule_name, SUM(revenue) as revenue, SUM(impressions) as impressions, SUM(requests) as requests, SUM(ecpm * requests) as ecpm_weighted')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($rule, fn ($q) => $q->where('rule_name', $rule))
            ->groupBy('site', 'rule_name')
            ->get()
            ->each(fn ($r) => $r->domain = self::domain($r->site));
    }

    /** One row per domain. */
    public function domains(string $from, string $to, ?string $rule = null): Collection
    {
        return $this->stats($from, $to, $rule)
            ->groupBy('domain')
            ->map(function (Collection $g, string $domain) {
                $row = $this->metrics($g);
                $row->domain = $domain;
                $row->unruled = (int) $g->where('rule_name', PricingRuleStat::NO_RULE)->sum('requests');

                return $row;
            })
            ->values();
    }

    /** Totals for the period. */
    public function summary(string $from, string $to, ?string $rule = null): object
    {
        return $this->metrics($this->stats($from, $to, $rule));
    }

    /**
     * One row per day: date, revenue, impressions, requests, ecpm, requestEcpm, fill.
     *
     * @param  list<string>|null  $domains  Limit to these normalised domains; null means all.
     */
    public function daily(string $from, string $to, ?array $domains = null, ?string $rule = null): Collection
    {
        return PricingRuleStat::query()
            ->selectRaw('date(date) as day, site, SUM(revenue) as revenue, SUM(impressions) as impressions, SUM(requests) as requests, SUM(ecpm * requests) as ecpm_weighted')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($rule, fn ($q) => $q->where('rule_name', $rule))
            ->groupBy('day', 'site')
            ->get()
            ->when($domains !== null, fn ($c) => $c->filter(fn ($r) => in_array(self::domain($r->site), $domains, true)))
            ->groupBy('day')
            ->sortKeys()
            ->map(function (Collection $g, string $day) {
                $row = $this->metrics($g);
                $row->date = $day;

                return $row;
            })
            ->values();
    }

    /** Distinct pricing rules with stats in the period, without the "no rule" bucket. */
    public function rules(string $from, string $to): Collection
    {
        return PricingRuleStat::query()
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->where('rule_name', '!=', PricingRuleStat::NO_RULE)
            ->distinct()
            ->orderBy('rule_name')
            ->pluck('rule_name');
    }

    /** Date of the most recent imported day, or null when nothing is imported. */
    public function latestDate(): ?string
    {
        $latest = PricingRuleStat::max('date');

        return $latest ? substr($latest, 0, 10) : null;
    }

    private function metrics(Collection $rows): object
    {
        $revenue = (float) $rows->sum('revenue');
        $impressions = (int) $rows->sum('impressions');
        $requests = (int) $rows->sum('requests');
        $fill = $requests > 0 ? $impressions / $requests : null;

        return (object) [
            'revenue' => $revenue,
            'impressions' => $impressions,
            'requests' => $requests,
            'ecpm' => $impressions > 0 ? $revenue / $impressions * 1000 : null,
            'requestEcpm' => $requests > 0 ? (float) $rows->sum('ecpm_weighted') / $requests : null,
            'fill' => $fill,
            'fillColor' => self::fillColor($fill),
        ];
    }
}
