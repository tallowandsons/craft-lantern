<?php

namespace tallowandsons\lantern\console\controllers;

use Craft;
use craft\console\Controller;
use tallowandsons\lantern\Lantern;
use yii\console\ExitCode;

/**
 * Report controller
 */
class ReportController extends Controller
{
    public $defaultAction = 'index';

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        switch ($actionID) {
            case 'index':
            case 'cache-stats':
            case 'clear-cache':
                // No additional options for these actions
                break;
        }
        return $options;
    }

    /**
     * lantern/report command - Shows available commands
     */
    public function actionIndex(): int
    {
        $this->stdout("Lantern Template Usage Tracker\n", \yii\helpers\Console::FG_GREEN);
        $this->stdout("==============================\n\n");

        $this->stdout("Available commands:\n");
        $this->stdout("  lantern/report/cache-stats  - View current template cache statistics\n");
        $this->stdout("  lantern/report/clear-cache  - Clear template usage cache\n");

        return ExitCode::OK;
    }

    /**
     * lantern/report/cache-stats command - Shows current template cache statistics
     */
    public function actionCacheStats(): int
    {
        $lantern = Lantern::getInstance();
        $cacheService = $lantern->cacheService;

        $this->stdout("Current Template Cache Statistics\n", \yii\helpers\Console::FG_CYAN);
        $this->stdout("=================================\n\n");

        $templateHits = $cacheService->getTemplateHits();
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
        $this->stdout(str_repeat("-", 80) . "\n");
        $this->stdout(sprintf("%-60s %s\n", "Template", "Hits"));
        $this->stdout(str_repeat("-", 80) . "\n");

        // Sort templates by hit count (descending)
        arsort($templateHits);

        foreach ($templateHits as $template => $hits) {
            $this->stdout(sprintf("%-60s %d\n", $template, $hits));
        }

        $this->stdout("\nNote: This shows accumulated template usage data from the cache.\n", \yii\helpers\Console::FG_GREY);
        $this->stdout("Data persists until cache expires or is manually cleared.\n", \yii\helpers\Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * lantern/report/clear-cache command - Clears template usage cache
     */
    public function actionClearCache(): int
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
}
