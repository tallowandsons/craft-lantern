<?php

namespace tallowandsons\lantern\console\controllers;

use craft\console\Controller;
use tallowandsons\lantern\Lantern;
use yii\console\ExitCode;

/**
 * Cache controller
 */
class CacheController extends Controller
{
    /**
     * lantern/cache/stats command - Shows current template cache statistics
     */
    public function actionStats(): int
    {
        $lantern = Lantern::getInstance();
        $cacheService = $lantern->cacheService;

        $this->stdout("Current Template Cache Statistics\n", \yii\helpers\Console::FG_CYAN);
        $this->stdout("=================================\n\n");

        $templateData = $cacheService->getTemplateData();
        $templateCount = $cacheService->getTemplateCount();
        $totalHits = $cacheService->getTotalHits();

        // Display summary
        $this->stdout("Summary:\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout("  Unique templates tracked: {$templateCount}\n");
        $this->stdout("  Total hits accumulated:   {$totalHits}\n\n");

        if ($templateCount === 0) {
            $this->stdout("No template data in cache.\n", \yii\helpers\Console::FG_GREY);
            $this->stdout("Templates will be tracked when they are loaded during web requests.\n", \yii\helpers\Console::FG_GREY);
            return ExitCode::OK;
        }

        // Display detailed template list
        $this->stdout("Template Details:\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout(str_repeat("-", 100) . "\n");
        $this->stdout(sprintf("%-60s %8s %s\n", "Template", "Hits", "Last Hit"));
        $this->stdout(str_repeat("-", 100) . "\n");

        // Sort templates by hit count (descending)
        uasort($templateData, function ($a, $b) {
            return $b['hits'] <=> $a['hits'];
        });

        foreach ($templateData as $template => $data) {
            $lastHitFormatted = $data['lastHit']
                ? date('Y-m-d H:i:s', $data['lastHit'])
                : 'Never';

            $this->stdout(sprintf("%-60s %8d %s\n", $template, $data['hits'], $lastHitFormatted));
        }

        $this->stdout("\nNote: This shows accumulated template usage data from the cache.\n", \yii\helpers\Console::FG_GREY);
        $this->stdout("Data persists until cache expires or is manually cleared.\n", \yii\helpers\Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * lantern/cache/clear command - Clears template usage cache
     */
    public function actionClear(): int
    {
        $lantern = Lantern::getInstance();
        $cacheService = $lantern->cacheService;

        $this->stdout("Clearing template usage cache...\n", \yii\helpers\Console::FG_CYAN);

        // Get current stats before clearing
        $templateCount = $cacheService->getTemplateCount();
        $totalHits = $cacheService->getTotalHits();

        // Clear the cache
        $cacheService->clearTemplateHits();

        $this->stdout("Cache cleared successfully!\n", \yii\helpers\Console::FG_GREEN);
        $this->stdout("Removed {$templateCount} templates with {$totalHits} total hits.\n");

        return ExitCode::OK;
    }

    /**
     * lantern/cache/flush command - Flushes cache data to database
     */
    public function actionFlush(): int
    {
        $lantern = Lantern::getInstance();
        $databaseService = $lantern->databaseService;

        $this->stdout("Flushing template cache to database...\n", \yii\helpers\Console::FG_CYAN);

        $result = $databaseService->flushCacheToDatabase();

        if ($result['success']) {
            $this->stdout("Cache flushed successfully!\n", \yii\helpers\Console::FG_GREEN);
            $this->stdout("Processed {$result['templatesProcessed']} templates with {$result['totalHitsProcessed']} total hits.\n");
        } else {
            $this->stdout("Failed to flush cache!\n", \yii\helpers\Console::FG_RED);
            $this->stdout("Error: {$result['message']}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }
}
