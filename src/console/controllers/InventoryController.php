<?php

namespace tallowandsons\lantern\console\controllers;

use craft\console\Controller;
use tallowandsons\lantern\Lantern;
use yii\console\ExitCode;

/**
 * Inventory controller
 */
class InventoryController extends Controller
{
    public $defaultAction = 'index';

    /**
     * lantern/inventory command - Shows template inventory
     */
    public function actionIndex(): int
    {
        $lantern = Lantern::getInstance();
        $inventoryService = $lantern->inventoryService;

        $this->stdout("Template Inventory\n", \yii\helpers\Console::FG_CYAN);
        $this->stdout("==================\n\n");

        $inventory = $inventoryService->getTemplateInventory();

        if (empty($inventory)) {
            $this->stdout("No templates found in inventory.\n", \yii\helpers\Console::FG_GREY);
            $this->stdout("Run 'lantern/inventory/scan' to scan the templates directory.\n", \yii\helpers\Console::FG_GREY);
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
     * lantern/inventory/scan command - Scans templates directory and updates inventory
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
}
