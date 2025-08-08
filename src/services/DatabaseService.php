<?php

namespace tallowandsons\lantern\services;

use Craft;
use tallowandsons\lantern\Lantern;
use tallowandsons\lantern\records\TemplateUsageTotalRecord;
use tallowandsons\lantern\records\TemplateUsageDailyRecord;
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
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

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
                $totalRecord->pageHits += 1; // Each flush represents page loads, not individual renders
                $totalRecord->lastUsed = date('Y-m-d H:i:s', $lastHit);

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
                'pageHits' => $record->pageHits,
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
                'pageHits' => $record->pageHits,
                'lastUsed' => $record->lastUsed,
                'daysSinceLastUse' => $record->lastUsed
                    ? round((time() - strtotime($record->lastUsed)) / 86400)
                    : null,
            ];
        }

        return $unused;
    }
}
