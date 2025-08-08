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
        $this->stdout("  lantern/report/flush-cache  - Flush cache data to database\n");
        $this->stdout("  lantern/report/db-stats     - View database statistics\n");
        $this->stdout("  lantern/report/unused       - Find unused templates\n");
        $this->stdout("  lantern/report/scan         - Scan templates directory\n");
        $this->stdout("  lantern/report/inventory    - View template inventory\n");
        $this->stdout("  lantern/report/orphans      - Find orphaned templates\n");

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

    /**
     * lantern/report/flush-cache command - Flushes cache data to database
     */
    public function actionFlushCache(): int
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

    /**
     * lantern/report/db-stats command - Shows database statistics
     */
    public function actionDbStats(): int
    {
        $lantern = Lantern::getInstance();
        $databaseService = $lantern->databaseService;

        $this->stdout("Template Usage Database Statistics\n", \yii\helpers\Console::FG_CYAN);
        $this->stdout("===================================\n\n");

        $totalStats = $databaseService->getTotalUsageStats();

        if (empty($totalStats)) {
            $this->stdout("No template usage data found in database.\n", \yii\helpers\Console::FG_GREY);
            $this->stdout("Run 'lantern/report/flush-cache' to move cache data to database.\n", \yii\helpers\Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout("Total Usage (All Time):\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout(str_repeat("-", 120) . "\n");
        $this->stdout(sprintf("%-60s %10s %10s %s\n", "Template", "Total Hits", "Page Hits", "Last Used"));
        $this->stdout(str_repeat("-", 120) . "\n");

        foreach ($totalStats as $stat) {
            $lastUsed = $stat['lastUsed'] ? date('Y-m-d H:i', strtotime($stat['lastUsed'])) : 'Never';
            $this->stdout(sprintf(
                "%-60s %10d %10d %s\n",
                $stat['template'],
                $stat['totalHits'],
                $stat['pageHits'],
                $lastUsed
            ));
        }

        $totalTemplates = count($totalStats);
        $totalHits = array_sum(array_column($totalStats, 'totalHits'));
        $totalPageHits = array_sum(array_column($totalStats, 'pageHits'));

        $this->stdout("\nSummary:\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout("  Total templates: {$totalTemplates}\n");
        $this->stdout("  Total hits: {$totalHits}\n");
        $this->stdout("  Total page hits: {$totalPageHits}\n");

        return ExitCode::OK;
    }

    /**
     * lantern/report/unused command - Shows unused templates
     */
    public function actionUnused(int $days = 30): int
    {
        $lantern = Lantern::getInstance();
        $databaseService = $lantern->databaseService;

        $this->stdout("Unused Templates (not used in last {$days} days)\n", \yii\helpers\Console::FG_CYAN);
        $this->stdout(str_repeat("=", 50) . "\n\n");

        $unusedTemplates = $databaseService->getUnusedTemplates($days);

        if (empty($unusedTemplates)) {
            $this->stdout("No unused templates found!\n", \yii\helpers\Console::FG_GREEN);
            $this->stdout("All templates have been used within the last {$days} days.\n");
            return ExitCode::OK;
        }

        $this->stdout("Found " . count($unusedTemplates) . " unused templates:\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout(str_repeat("-", 100) . "\n");
        $this->stdout(sprintf("%-60s %10s %s\n", "Template", "Total Hits", "Last Used"));
        $this->stdout(str_repeat("-", 100) . "\n");

        foreach ($unusedTemplates as $template) {
            $lastUsed = $template['lastUsed'] ? date('Y-m-d', strtotime($template['lastUsed'])) : 'Never';
            $daysSince = $template['daysSinceLastUse'] ? "{$template['daysSinceLastUse']} days ago" : 'Never';

            $this->stdout(sprintf(
                "%-60s %10d %s (%s)\n",
                $template['template'],
                $template['totalHits'],
                $lastUsed,
                $daysSince
            ));
        }

        $this->stdout("\nThese templates may be candidates for cleanup.\n", \yii\helpers\Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * lantern/report/scan command - Scans templates directory and updates inventory
     */
    public function actionScan(): int
    {
        $lantern = Lantern::getInstance();
        $inventoryService = $lantern->inventoryService;

        $this->stdout("Scanning templates directory...\n", \yii\helpers\Console::FG_CYAN);

        $result = $inventoryService->scanTemplatesDirectory();

        if ($result['success']) {
            $this->stdout("Template scan completed successfully!\n", \yii\helpers\Console::FG_GREEN);
            $this->stdout("Found: {$result['templatesFound']} templates\n");
            $this->stdout("Added: {$result['templatesAdded']} new templates\n");
            $this->stdout("Updated: {$result['templatesUpdated']} existing templates\n");
            $this->stdout("Removed: {$result['templatesRemoved']} deleted templates\n");
        } else {
            $this->stdout("Template scan failed!\n", \yii\helpers\Console::FG_RED);
            $this->stdout("Error: {$result['message']}\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * lantern/report/inventory command - Shows template inventory
     */
    public function actionInventory(): int
    {
        $lantern = Lantern::getInstance();
        $inventoryService = $lantern->inventoryService;

        $this->stdout("Template Inventory\n", \yii\helpers\Console::FG_CYAN);
        $this->stdout("==================\n\n");

        $inventory = $inventoryService->getTemplateInventory();

        if (empty($inventory)) {
            $this->stdout("No templates found in inventory.\n", \yii\helpers\Console::FG_GREY);
            $this->stdout("Run 'lantern/report/scan' to scan the templates directory.\n", \yii\helpers\Console::FG_GREY);
            return ExitCode::OK;
        }

        $this->stdout("Found " . count($inventory) . " templates in inventory:\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout(str_repeat("-", 100) . "\n");
        $this->stdout(sprintf("%-50s %-40s %s\n", "Template", "File Path", "Modified"));
        $this->stdout(str_repeat("-", 100) . "\n");

        foreach ($inventory as $template) {
            $modified = $template['fileModified'] ? date('Y-m-d H:i', strtotime($template['fileModified'])) : 'Unknown';

            $this->stdout(sprintf(
                "%-50s %-40s %s\n",
                substr($template['template'], 0, 49),
                substr($template['filePath'], 0, 39),
                $modified
            ));
        }

        $this->stdout("\nSummary:\n", \yii\helpers\Console::FG_YELLOW);
        $this->stdout("  Total templates: " . count($inventory) . "\n");

        return ExitCode::OK;
    }

    /**
     * lantern/report/orphans command - Shows orphaned templates
     */
    public function actionOrphans(): int
    {
        $lantern = Lantern::getInstance();
        $inventoryService = $lantern->inventoryService;

        $this->stdout("Orphaned Template Analysis\n", \yii\helpers\Console::FG_CYAN);
        $this->stdout("==========================\n\n");

        // Get unused templates (exist in inventory but never used)
        $unusedTemplates = $inventoryService->getUnusedInventoryTemplates();

        // Get missing templates (used but not in inventory)
        $missingTemplates = $inventoryService->getMissingTemplates();

        // Display unused templates
        $this->stdout("Templates that exist but are never used:\n", \yii\helpers\Console::FG_YELLOW);
        if (empty($unusedTemplates)) {
            $this->stdout("  None found - all templates in inventory have been used!\n", \yii\helpers\Console::FG_GREEN);
        } else {
            $this->stdout(str_repeat("-", 90) . "\n");
            $this->stdout(sprintf("%-60s %s\n", "Template", "File Path"));
            $this->stdout(str_repeat("-", 90) . "\n");

            foreach ($unusedTemplates as $template) {
                $this->stdout(sprintf(
                    "%-60s %s\n",
                    substr($template['template'], 0, 59),
                    substr($template['filePath'], 0, 29)
                ));
            }
            $this->stdout("  Found " . count($unusedTemplates) . " unused templates.\n");
        }

        $this->stdout("\n");

        // Display missing templates
        $this->stdout("Templates that are used but files are missing:\n", \yii\helpers\Console::FG_YELLOW);
        if (empty($missingTemplates)) {
            $this->stdout("  None found - all used templates have files!\n", \yii\helpers\Console::FG_GREEN);
        } else {
            $this->stdout(str_repeat("-", 100) . "\n");
            $this->stdout(sprintf("%-60s %10s %10s %s\n", "Template", "Total Hits", "Page Hits", "Last Used"));
            $this->stdout(str_repeat("-", 100) . "\n");

            foreach ($missingTemplates as $template) {
                $lastUsed = $template['lastUsed'] ? date('Y-m-d H:i', strtotime($template['lastUsed'])) : 'Never';
                $this->stdout(sprintf(
                    "%-60s %10d %10d %s\n",
                    substr($template['template'], 0, 59),
                    $template['totalHits'],
                    $template['pageHits'],
                    $lastUsed
                ));
            }
            $this->stdout("  Found " . count($missingTemplates) . " missing templates.\n", \yii\helpers\Console::FG_RED);
        }

        $this->stdout("\nRecommendations:\n", \yii\helpers\Console::FG_GREY);
        if (!empty($unusedTemplates)) {
            $this->stdout("- Consider removing unused templates to reduce codebase complexity\n", \yii\helpers\Console::FG_GREY);
        }
        if (!empty($missingTemplates)) {
            $this->stdout("- Investigate missing template files - they may cause errors\n", \yii\helpers\Console::FG_GREY);
        }

        return ExitCode::OK;
    }
}
