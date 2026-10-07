<?php

namespace App\Services;

use App\Models\PricingRule;
use App\Models\PricingRuleStat;
use Carbon\CarbonInterface;
use Google\Ads\AdManager\V1\Client\ReportServiceClient;
use Google\Ads\AdManager\V1\CreateReportRequest;
use Google\Ads\AdManager\V1\FetchReportResultRowsRequest;
use Google\Ads\AdManager\V1\Report;
use Google\Ads\AdManager\V1\ReportDefinition;
use Google\Ads\AdManager\V1\ReportDefinition\DateRange;
use Google\Ads\AdManager\V1\ReportDefinition\DateRange\FixedDateRange;
use Google\Ads\AdManager\V1\ReportDefinition\Dimension;
use Google\Ads\AdManager\V1\ReportDefinition\Metric;
use Google\Ads\AdManager\V1\ReportDefinition\ReportType;
use Google\Ads\AdManager\V1\ReportDefinition\TimeZoneSource;
use Google\Ads\AdManager\V1\ReportValue;
use Google\Ads\AdManager\V1\RunReportRequest;
use Google\Type\Date;

/**
 * Pulls "OS | Pricing rule | Site | Ad unit" Ad Exchange stats for one day through the
 * Ad Manager REST/gRPC (V1) Report Service and upserts them into pricing_rule_stats.
 */
class AdManagerPricingRuleSync
{
    private const DIMENSIONS = [
        Dimension::OPERATING_SYSTEM_CATEGORY_NAME,
        Dimension::PRICING_RULE_NAME,
        Dimension::SITE,
        Dimension::AD_UNIT_NAME,
    ];

    private const METRICS = [
        Metric::AD_EXCHANGE_REVENUE,
        Metric::AD_EXCHANGE_IMPRESSIONS,
        Metric::AD_EXCHANGE_TOTAL_REQUESTS,
    ];

    public function __construct(private ?ReportServiceClient $client = null) {}

    /** @return array{rows: int, rules_created: int} */
    public function sync(CarbonInterface $day): array
    {
        if (! config('admanager.network_code')) {
            throw new \RuntimeException('ADMANAGER_NETWORK_CODE is not set in .env.');
        }

        $client = $this->client ??= new ReportServiceClient([
            'credentials' => config('admanager.credentials'),
            // Ad Manager rejects the self-signed JWT gax uses for default scopes; force a real OAuth token.
            'credentialsConfig' => [
                'scopes' => ['https://www.googleapis.com/auth/admanager'],
                'useJwtAccessWithScope' => false,
            ],
        ]);
        $network = 'networks/'.config('admanager.network_code');

        $report = $client->createReport(
            (new CreateReportRequest)->setParent($network)->setReport($this->report($day))
        );

        $operation = $client->runReport((new RunReportRequest)->setName($report->getName()));
        $operation->pollUntilComplete();
        if (! $operation->operationSucceeded()) {
            throw new \RuntimeException('Ad Manager report failed: '.$operation->getError()?->getMessage());
        }
        $resultName = $operation->getResult()->getReportResult();

        $rows = 0;
        $rulesCreated = 0;
        $pageToken = '';

        do {
            $page = $client->fetchReportResultRows(
                (new FetchReportResultRowsRequest)->setName($resultName)->setPageSize(1000)->setPageToken($pageToken)
            );

            foreach ($page->getRows() as $row) {
                $dim = array_map(fn (ReportValue $v) => $v->getStringValue(), iterator_to_array($row->getDimensionValues()));
                $metrics = iterator_to_array($row->getMetricValueGroups()[0]->getPrimaryValues());

                [$os, $rule, $site, $adUnit] = $dim;
                $rule = $rule === '' || $rule === 'Unknown' ? PricingRuleStat::NO_RULE : $rule;

                if ($rule !== PricingRuleStat::NO_RULE && ! PricingRule::where('name', $rule)->exists()) {
                    PricingRule::create(['name' => $rule, 'currency' => config('admanager.currency')] + PricingRule::parseName($rule));
                    $rulesCreated++;
                }

                PricingRuleStat::upsert(
                    [[
                        'date' => $day->toDateString(), 'os' => $os, 'rule_name' => $rule, 'site' => $site, 'ad_unit' => $adUnit,
                        // Revenue arrives as a double in the report currency (e.g. 148.94), not micros.
                        'revenue' => round($metrics[0]->getDoubleValue(), 4),
                        'impressions' => $metrics[1]->getIntValue(),
                        'requests' => $metrics[2]->getIntValue(),
                        'currency' => config('admanager.currency'),
                    ]],
                    ['date', 'os', 'rule_name', 'site', 'ad_unit'],
                    ['revenue', 'impressions', 'requests', 'currency'],
                );
                $rows++;
            }

            $pageToken = $page->getNextPageToken();
        } while ($pageToken !== '');

        return ['rows' => $rows, 'rules_created' => $rulesCreated];
    }

    private function report(CarbonInterface $day): Report
    {
        $date = (new Date)->setYear($day->year)->setMonth($day->month)->setDay($day->day);

        $definition = (new ReportDefinition)
            ->setReportType(ReportType::HISTORICAL)
            ->setDimensions(self::DIMENSIONS)
            ->setMetrics(self::METRICS)
            ->setCurrencyCode(config('admanager.currency'))
            // A time zone can only be set together with a source; use the network's own (Europe/Brussels).
            ->setTimeZoneSource(TimeZoneSource::PUBLISHER)
            ->setDateRange((new DateRange)->setFixed(
                (new FixedDateRange)->setStartDate($date)->setEndDate($date)
            ));

        return (new Report)
            ->setDisplayName('Pricing rule sync '.$day->toDateString())
            ->setReportDefinition($definition);
    }
}
