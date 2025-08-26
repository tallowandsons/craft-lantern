<?php

namespace tallowandsons\lantern\services;

use Craft;
use tallowandsons\lantern\Lantern;
use tallowandsons\lantern\records\MetaRecord;
use tallowandsons\lantern\records\TemplateUsageTotalRecord;
use tallowandsons\lantern\records\TemplateUsageDailyRecord;
use tallowandsons\lantern\records\TemplateUsageMonthlyRecord;
use yii\base\Component;

/**
 * Database Service
 *
 * Handles flushing cache data to the database and database operations.
 */
class DatabaseService extends Component
{
    /**
     * Flush cache data to database
     *
     * This method should be called periodically (e.g., every 5 minutes via cron)
     * to persist the accumulated cache data to the database.
     */
    public function flushCacheToDatabase(): array
    {
        $cacheService = Lantern::getInstance()->cacheService;
        $templateData = $cacheService->getTemplateData();

        if (empty($templateData)) {
            return [
                'success' => true,
                'message' => 'No cache data to flush',
                'templatesProcessed' => 0,
                'totalHitsProcessed' => 0,
            ];
        }

        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $today = gmdate('Y-m-d');
        $now = gmdate('Y-m-d H:i:s');

        $templatesProcessed = 0;
        $totalHitsProcessed = 0;
        $errors = [];

        // Use a transaction to ensure data consistency
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($templateData as $template => $data) {
                $hits = $data['hits'];
                $lastHit = $data['lastHit'];

                // Update total usage record
                $totalRecord = TemplateUsageTotalRecord::findOrCreate($template, $currentSiteId);
                $totalRecord->totalHits += $hits;
                $totalRecord->lastUsed = gmdate('Y-m-d H:i:s', $lastHit);
                if (!$totalRecord->firstSeen) {
                    $totalRecord->firstSeen = $now;
                }

                if (!$totalRecord->save()) {
                    $errors[] = "Failed to save total usage for template: {$template}";
                    continue;
                }

                // Update daily usage record
                $dailyRecord = TemplateUsageDailyRecord::findOrCreate($template, $currentSiteId, $today);
                $dailyRecord->hits += $hits;

                if (!$dailyRecord->save()) {
                    $errors[] = "Failed to save daily usage for template: {$template}";
                    continue;
                }

                $templatesProcessed++;
                $totalHitsProcessed += $hits;
            }

            if (empty($errors)) {
                // On first successful flush, set trackingStartedAt (global and per site) if empty
                $globalMeta = MetaRecord::findOrCreate(0);
                if (!$globalMeta->trackingStartedAt) {
                    $globalMeta->trackingStartedAt = $now;
                    $globalMeta->save(false);
                }

                $siteMeta = MetaRecord::findOrCreate($currentSiteId);
                if (!$siteMeta->trackingStartedAt) {
                    $siteMeta->trackingStartedAt = $now;
                    $siteMeta->save(false);
                }

                // Clear the cache after successful database flush
                $cacheService->clearTemplateHits();
                $transaction->commit();

                Lantern::getInstance()->log->info(
                    "Cache flushed to database: {$templatesProcessed} templates, {$totalHitsProcessed} total hits"
                );

                return [
                    'success' => true,
                    'message' => "Successfully flushed cache to database",
                    'templatesProcessed' => $templatesProcessed,
                    'totalHitsProcessed' => $totalHitsProcessed,
                ];
            } else {
                $transaction->rollBack();

                $errorMessage = "Errors occurred during cache flush: " . implode(', ', $errors);
                Lantern::getInstance()->log->error($errorMessage);

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'templatesProcessed' => 0,
                    'totalHitsProcessed' => 0,
                    'errors' => $errors,
                ];
            }
        } catch (\Exception $e) {
            $transaction->rollBack();

            $errorMessage = "Exception during cache flush: " . $e->getMessage();
            Lantern::getInstance()->log->error($errorMessage);

            return [
                'success' => false,
                'message' => $errorMessage,
                'templatesProcessed' => 0,
                'totalHitsProcessed' => 0,
                'exception' => $e->getMessage(),
            ];
        }
    }

    /**
     * Aggregate completed months from daily into monthly and optionally prune daily rows.
     *
     * Options:
     * - siteIds: int[]|null filter by site ids
     * - sinceMonth: 'YYYY-MM'|null
     * - untilMonth: 'YYYY-MM'|null (defaults to last completed month)
     * - dailyRetentionDays: int|null
     * - prune: bool (default true)
     * - dryRun: bool (default false)
     * - verbose: bool (default false)
     */
    public function aggregateMonthly(array $options = []): array
    {
        $db = Craft::$app->getDb();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $firstDayThisMonth = $now->modify('first day of this month')->format('Y-m-d');

        $siteIds = $options['siteIds'] ?? null; // null means all sites present in data
        $sinceMonth = $options['sinceMonth'] ?? null; // 'YYYY-MM'
        $untilMonth = $options['untilMonth'] ?? null; // 'YYYY-MM'
        $dailyRetentionDays = $options['dailyRetentionDays'] ?? null; // int
        $monthlyRetentionMonths = $options['monthlyRetentionMonths'] ?? null; // int
        $prune = $options['prune'] ?? null; // bool
        $dryRun = $options['dryRun'] ?? false;
        $verbose = $options['verbose'] ?? false;

        // Apply defaults from plugin settings when not provided explicitly
        $settings = Lantern::getInstance()->getSettings();
        if ($dailyRetentionDays === null) {
            $dailyRetentionDays = (int)($settings->dailyRetentionDays ?? 90);
        }
        if ($monthlyRetentionMonths === null) {
            $monthlyRetentionMonths = (int)($settings->monthlyRetentionMonths ?? 24);
        }
        if ($prune === null) {
            $prune = (bool)($settings->prune ?? true);
        }

        // Determine default untilMonth = last completed month
        if (!$untilMonth) {
            $lastCompleted = (new \DateTimeImmutable($firstDayThisMonth, new \DateTimeZone('UTC')))->modify('-1 month');
            $untilMonth = $lastCompleted->format('Y-m');
        }

        // Build month range (inclusive bounds in terms of months)
        // We'll query daily rows prior to first day of this month and within overrides.
        $params = [];
        $siteFilterSql = '';
        if ($siteIds && count($siteIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
            $siteFilterSql = " AND d.siteId IN ($placeholders)";
            $params = array_merge($params, $siteIds);
        }

        $startBoundary = null;
        if ($sinceMonth) {
            $startBoundary = (new \DateTimeImmutable($sinceMonth . '-01', new \DateTimeZone('UTC')))->format('Y-m-d');
        }
        $endBoundary = (new \DateTimeImmutable($untilMonth . '-01', new \DateTimeZone('UTC')))->format('Y-m-d');

        // Find candidate months (completed) that exist in daily and are not logged
        $driver = $db->getIsMysql() ? 'mysql' : ($db->getIsPgsql() ? 'pgsql' : 'other');
        if ($driver === 'mysql') {
            $sql = "
                SELECT DISTINCT
                    d.siteId,
                    DATE_FORMAT(d.day, '%Y-%m-01') AS month
                FROM {{%lantern_usage_daily}} d
                LEFT JOIN {{%lantern_aggregate_month_log}} l
                    ON l.siteId = d.siteId
                    AND l.month = DATE_FORMAT(d.day, '%Y-%m-01')
                WHERE d.day < ?
                  AND l.id IS NULL
                  " . ($startBoundary ? " AND d.day >= ?" : "") . "
                  AND DATE_FORMAT(d.day, '%Y-%m-01') <= ?
                  $siteFilterSql
                ORDER BY d.siteId, month
            ";

            $queryParams = [$firstDayThisMonth];
            if ($startBoundary) {
                $queryParams[] = $startBoundary;
            }
            $queryParams[] = $endBoundary;
            $queryParams = array_merge($queryParams, $params);
            $candidates = $db->createCommand($sql, $queryParams)->queryAll();
        } else { // pgsql and others
            $sql = "
                SELECT DISTINCT
                    d.siteId,
                    DATE_TRUNC('month', d.day)::date AS month
                FROM {{%lantern_usage_daily}} d
                LEFT JOIN {{%lantern_aggregate_month_log}} l
                    ON l.siteId = d.siteId
                    AND l.month = DATE_TRUNC('month', d.day)::date
                WHERE d.day < :firstDayThisMonth
                  AND l.id IS NULL
                  " . ($startBoundary ? " AND d.day >= :startBoundary" : "") . "
                  AND DATE_TRUNC('month', d.day) <= :endBoundary::date
                  $siteFilterSql
                ORDER BY d.siteId, month
            ";

            $queryParams = [':firstDayThisMonth' => $firstDayThisMonth];
            if ($startBoundary) {
                $queryParams[':startBoundary'] = $startBoundary;
            }
            $queryParams[':endBoundary'] = $endBoundary;
            // For site filter using positional placeholders we used above; adapt to named
            if ($siteIds && count($siteIds) > 0) {
                // rebuild SQL with IN (:s0,:s1,...)
                $inNames = [];
                $i = 0;
                foreach ($siteIds as $sid) {
                    $inNames[] = ":s$i";
                    $queryParams[":s$i"] = (int)$sid;
                    $i++;
                }
                $sql = str_replace($siteFilterSql, ' AND d.siteId IN (' . implode(',', $inNames) . ')', $sql);
            } else {
                $sql = str_replace($siteFilterSql, '', $sql);
            }
            $candidates = $db->createCommand($sql, $queryParams)->queryAll();
        }

        $monthsProcessed = 0;
        $templatesAggregated = 0;
        $totalHitsRolled = 0;
        $months = [];

        foreach ($candidates as $row) {
            $siteId = (int)$row['siteId'];
            $monthStr = $row['month']; // 'YYYY-MM-01'

            // Aggregate sums per template
            if ($driver === 'mysql') {
                $sumSql = "
                                        SELECT d.template, SUM(d.hits) AS hits
                                        FROM {{%lantern_usage_daily}} d
                                        WHERE d.siteId = :siteId
                                            AND d.day >= :monthStart AND d.day < DATE_ADD(:monthStart, INTERVAL 1 MONTH)
                                        GROUP BY d.template
                                ";
            } else {
                $sumSql = "
                                        SELECT d.template, SUM(d.hits) AS hits
                                        FROM {{%lantern_usage_daily}} d
                                        WHERE d.siteId = :siteId
                                            AND d.day >= :monthStart AND d.day < (:monthStart::date + INTERVAL '1 month')
                                        GROUP BY d.template
                                ";
            }

            $monthStart = $monthStr;
            $sums = $db->createCommand($sumSql, [
                ':siteId' => $siteId,
                ':monthStart' => $monthStart,
            ])->queryAll();

            if ($dryRun) {
                $templatesAggregated += count($sums);
                $totalHitsRolled += (int)array_sum(array_map(fn($r) => (int)$r['hits'], $sums));
                $monthsProcessed++;
                $months[] = [$siteId, $monthStart];
                continue;
            }

            $transaction = $db->beginTransaction();
            try {
                foreach ($sums as $sumRow) {
                    $template = $sumRow['template'];
                    $hits = (int)$sumRow['hits'];

                    // Upsert monthly row (increment hits)
                    $db->createCommand()->upsert(
                        '{{%lantern_usage_monthly}}',
                        [
                            'template' => $template,
                            'siteId' => $siteId,
                            'month' => $monthStart,
                            'hits' => $hits,
                        ],
                        ['hits' => $hits] // idempotent: overwrite to exact sum
                    )->execute();
                }

                // Log idempotency
                $db->createCommand()->insert('{{%lantern_aggregate_month_log}}', [
                    'siteId' => $siteId,
                    'month' => $monthStart,
                    'aggregatedAt' => gmdate('Y-m-d H:i:s'),
                ])->execute();

                $transaction->commit();

                $templatesAggregated += count($sums);
                $totalHitsRolled += (int)array_sum(array_map(fn($r) => (int)$r['hits'], $sums));
                $monthsProcessed++;
                $months[] = [$siteId, $monthStart];
            } catch (\Throwable $e) {
                $transaction->rollBack();
                return [
                    'success' => false,
                    'message' => 'Aggregation failed: ' . $e->getMessage(),
                ];
            }
        }

        // Prune daily rows based on strategy
        $dailyRowsPruned = 0;
        if ($prune && !$dryRun) {
            if ($dailyRetentionDays !== null && $dailyRetentionDays > 0) {
                // Strategy A: keep last N days
                $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . (int)$dailyRetentionDays . ' days')->format('Y-m-d');
                $dailyRowsPruned = $db->createCommand(
                    'DELETE FROM {{%lantern_usage_daily}} WHERE day < :cutoff',
                    [':cutoff' => $cutoff]
                )->execute();
            } else {
                // Strategy B: delete exact months processed
                foreach ($months as [$siteId, $monthStart]) {
                    if ($driver === 'mysql') {
                        $dailyRowsPruned += $db->createCommand(
                            'DELETE FROM {{%lantern_usage_daily}} WHERE siteId = :siteId AND day >= :start AND day < DATE_ADD(:start, INTERVAL 1 MONTH)',
                            [
                                ':siteId' => $siteId,
                                ':start' => $monthStart,
                            ]
                        )->execute();
                    } else {
                        $dailyRowsPruned += $db->createCommand(
                            "DELETE FROM {{%lantern_usage_daily}} WHERE siteId = :siteId AND day >= :start AND day < (:start::date + INTERVAL '1 month')",
                            [
                                ':siteId' => $siteId,
                                ':start' => $monthStart,
                            ]
                        )->execute();
                    }
                }
            }
        }

        // Optional monthly retention pruning
        $monthlyRowsPruned = 0;
        if (!$dryRun && $prune && $monthlyRetentionMonths !== null && $monthlyRetentionMonths >= 0) {
            // Keep last N months inclusively from first day of current month backward
            $keepFrom = (new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC')))
                ->modify('-' . (int)$monthlyRetentionMonths . ' months')
                ->format('Y-m-d');
            $monthlyRowsPruned = $db->createCommand(
                'DELETE FROM {{%lantern_usage_monthly}} WHERE month < :keepFrom',
                [':keepFrom' => $keepFrom]
            )->execute();
        }

        return [
            'success' => true,
            'monthsProcessed' => $monthsProcessed,
            'templatesAggregated' => $templatesAggregated,
            'totalHitsRolled' => $totalHitsRolled,
            'dailyRowsPruned' => $dailyRowsPruned,
            'monthlyRowsPruned' => $monthlyRowsPruned,
            'dryRun' => $dryRun,
        ];
    }

    /**
     * Get monthly usage stats
     */
    public function getMonthlyUsageStats(?int $siteId = null, ?string $startMonth = null, ?string $endMonth = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $startMonth = $startMonth ? $startMonth . '-01' : date('Y-m-01', strtotime('-12 months'));
        $endMonth = $endMonth ? $endMonth . '-01' : date('Y-m-01');

        $records = TemplateUsageMonthlyRecord::find()
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'month', $startMonth])
            ->andWhere(['<=', 'month', $endMonth])
            ->orderBy(['month' => SORT_DESC, 'hits' => SORT_DESC])
            ->all();

        $stats = [];
        foreach ($records as $record) {
            $stats[] = [
                'template' => $record->template,
                'month' => $record->month,
                'hits' => $record->hits,
            ];
        }

        return $stats;
    }

    /**
     * Utility: return last N days (daily) data for charts
     */
    public function getLastNDays(int $days = 30, ?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $start = date('Y-m-d', strtotime('-' . $days . ' days'));
        return $this->getDailyUsageStats($siteId, $start, date('Y-m-d'));
    }

    /**
     * Utility: return last N months (monthly) data for charts
     */
    public function getLastNMonths(int $months = 12, ?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $start = date('Y-m', strtotime('-' . $months . ' months'));
        return $this->getMonthlyUsageStats($siteId, $start, date('Y-m'));
    }

    /**
     * Get total usage statistics from database
     */
    public function getTotalUsageStats(?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $records = TemplateUsageTotalRecord::find()
            ->where(['siteId' => $siteId])
            ->orderBy(['totalHits' => SORT_DESC])
            ->all();

        $stats = [];
        foreach ($records as $record) {
            $stats[] = [
                'template' => $record->template,
                'totalHits' => $record->totalHits,
                'lastUsed' => $record->lastUsed,
            ];
        }

        return $stats;
    }

    /**
     * Get daily usage statistics from database
     */
    public function getDailyUsageStats(?int $siteId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $startDate = $startDate ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $endDate ?? date('Y-m-d');

        $records = TemplateUsageDailyRecord::find()
            ->where(['siteId' => $siteId])
            ->andWhere(['>=', 'day', $startDate])
            ->andWhere(['<=', 'day', $endDate])
            ->orderBy(['day' => SORT_DESC, 'hits' => SORT_DESC])
            ->all();

        $stats = [];
        foreach ($records as $record) {
            $stats[] = [
                'template' => $record->template,
                'day' => $record->day,
                'hits' => $record->hits,
            ];
        }

        return $stats;
    }

    /**
     * Get unused templates (templates that haven't been used in X days)
     */
    public function getUnusedTemplates(int $daysSinceLastUse = 30, ?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysSinceLastUse} days"));

        $records = TemplateUsageTotalRecord::find()
            ->where(['siteId' => $siteId])
            ->andWhere(['<', 'lastUsed', $cutoffDate])
            ->orWhere(['lastUsed' => null])
            ->orderBy(['lastUsed' => SORT_ASC])
            ->all();

        $unused = [];
        foreach ($records as $record) {
            $unused[] = [
                'template' => $record->template,
                'totalHits' => $record->totalHits,
                'lastUsed' => $record->lastUsed,
                'daysSinceLastUse' => $record->lastUsed
                    ? round((time() - strtotime($record->lastUsed)) / 86400)
                    : null,
            ];
        }

        return $unused;
    }
}
