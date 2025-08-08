<?php

namespace tallowandsons\lantern\services;

use Craft;
use tallowandsons\lantern\Lantern;
use tallowandsons\lantern\records\TemplateInventoryRecord;
use yii\base\Component;
use yii\helpers\FileHelper;

/**
 * Template Inventory Service
 *
 * Handles scanning the templates directory and maintaining an inventory
 * of all available templates in the database.
 */
class InventoryService extends Component
{
    /**
     * Scan templates directory and update inventory
     */
    public function scanTemplatesDirectory(?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $templatesPath = Craft::getAlias('@templates');

        if (!is_dir($templatesPath)) {
            return [
                'success' => false,
                'message' => "Templates directory not found: {$templatesPath}",
                'templatesFound' => 0,
                'templatesAdded' => 0,
                'templatesUpdated' => 0,
                'templatesRemoved' => 0,
            ];
        }

        $templatesFound = 0;
        $templatesAdded = 0;
        $templatesUpdated = 0;
        $templatesRemoved = 0;
        $errors = [];

        // Start transaction for data consistency
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Mark all existing templates as inactive first
            TemplateInventoryRecord::markAllInactive($siteId);

            // Scan for template files
            $templateFiles = $this->findTemplateFiles($templatesPath);

            foreach ($templateFiles as $filePath) {
                $relativePath = $this->getRelativeTemplatePath($filePath, $templatesPath);
                $templateName = $this->getTemplateNameFromPath($relativePath);

                if (empty($templateName)) {
                    continue; // Skip invalid templates
                }

                // Get file info
                $fileModified = date('Y-m-d H:i:s', filemtime($filePath));
                $now = date('Y-m-d H:i:s');

                // Find or create inventory record
                $record = TemplateInventoryRecord::findOrCreate($templateName, $siteId);
                $isNew = $record->getIsNewRecord();

                // Update record
                $record->filePath = $relativePath;
                $record->fileModified = $fileModified;
                $record->isActive = true;
                $record->lastScanned = $now;

                if ($record->save()) {
                    $templatesFound++;
                    if ($isNew) {
                        $templatesAdded++;
                    } else {
                        $templatesUpdated++;
                    }
                } else {
                    $errors[] = "Failed to save template: {$templateName}";
                }
            }

            // Remove templates that were not found (marked as inactive)
            $templatesRemoved = TemplateInventoryRecord::deleteInactive($siteId);

            if (empty($errors)) {
                $transaction->commit();

                Lantern::getInstance()->log->info(
                    "Template inventory scan completed: {$templatesFound} found, {$templatesAdded} added, {$templatesUpdated} updated, {$templatesRemoved} removed"
                );

                return [
                    'success' => true,
                    'message' => "Template inventory scan completed successfully",
                    'templatesFound' => $templatesFound,
                    'templatesAdded' => $templatesAdded,
                    'templatesUpdated' => $templatesUpdated,
                    'templatesRemoved' => $templatesRemoved,
                ];
            } else {
                $transaction->rollBack();

                $errorMessage = "Errors occurred during template scan: " . implode(', ', $errors);
                Lantern::getInstance()->log->error($errorMessage);

                return [
                    'success' => false,
                    'message' => $errorMessage,
                    'templatesFound' => 0,
                    'templatesAdded' => 0,
                    'templatesUpdated' => 0,
                    'templatesRemoved' => 0,
                    'errors' => $errors,
                ];
            }
        } catch (\Exception $e) {
            $transaction->rollBack();

            $errorMessage = "Exception during template scan: " . $e->getMessage();
            Lantern::getInstance()->log->error($errorMessage);

            return [
                'success' => false,
                'message' => $errorMessage,
                'templatesFound' => 0,
                'templatesAdded' => 0,
                'templatesUpdated' => 0,
                'templatesRemoved' => 0,
                'exception' => $e->getMessage(),
            ];
        }
    }

    /**
     * Find all template files in the templates directory
     */
    private function findTemplateFiles(string $templatesPath): array
    {
        $templateFiles = [];

        // Look for .twig and .html files recursively
        $extensions = ['*.twig', '*.html'];

        foreach ($extensions as $extension) {
            $files = FileHelper::findFiles($templatesPath, [
                'only' => [$extension],
                'recursive' => true,
            ]);
            $templateFiles = array_merge($templateFiles, $files);
        }

        return array_unique($templateFiles);
    }

    /**
     * Get relative path from templates directory
     */
    private function getRelativeTemplatePath(string $filePath, string $templatesPath): string
    {
        return str_replace($templatesPath . DIRECTORY_SEPARATOR, '', $filePath);
    }

    /**
     * Convert file path to template name (remove extension, normalize separators)
     */
    private function getTemplateNameFromPath(string $relativePath): string
    {
        // Remove file extensions
        $templateName = preg_replace('/\.(twig|html)$/', '', $relativePath);

        // Normalize directory separators to forward slashes
        $templateName = str_replace(DIRECTORY_SEPARATOR, '/', $templateName);

        // Skip certain files
        if ($this->shouldSkipTemplate($templateName)) {
            return '';
        }

        return $templateName;
    }

    /**
     * Check if a template should be skipped
     */
    private function shouldSkipTemplate(string $templateName): bool
    {
        // Skip hidden files, backup files, etc.
        $skipPatterns = [
            '/^\.',           // Hidden files
            '/~$/',           // Backup files
            '/\.bak$/',       // Backup files
            '/\.tmp$/',       // Temporary files
            '/\.DS_Store$/',  // macOS system files
        ];

        foreach ($skipPatterns as $pattern) {
            if (preg_match($pattern, $templateName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get template inventory from database
     */
    public function getTemplateInventory(?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $records = TemplateInventoryRecord::find()
            ->where(['siteId' => $siteId, 'isActive' => true])
            ->orderBy(['template' => SORT_ASC])
            ->all();

        $inventory = [];
        foreach ($records as $record) {
            $inventory[] = [
                'template' => $record->template,
                'filePath' => $record->filePath,
                'fileModified' => $record->fileModified,
                'lastScanned' => $record->lastScanned,
            ];
        }

        return $inventory;
    }

    /**
     * Get templates that exist in inventory but have never been used
     */
    public function getUnusedInventoryTemplates(?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        // Query for templates in inventory that don't exist in usage totals
        $query = "
            SELECT
                i.template,
                i.filePath,
                i.fileModified,
                i.lastScanned
            FROM {{%lantern_templateinventory}} i
            LEFT JOIN {{%lantern_usage_total}} u ON i.template = u.template AND i.siteId = u.siteId
            WHERE i.siteId = :siteId
            AND i.isActive = 1
            AND u.template IS NULL
            ORDER BY i.template ASC
        ";

        $results = Craft::$app->getDb()->createCommand($query, [
            ':siteId' => $siteId,
        ])->queryAll();

        return $results;
    }

    /**
     * Get templates that are used but not in inventory (missing files)
     */
    public function getMissingTemplates(?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        // Query for templates in usage totals that don't exist in inventory
        $query = "
            SELECT
                u.template,
                u.totalHits,
                u.pageHits,
                u.lastUsed
            FROM {{%lantern_usage_total}} u
            LEFT JOIN {{%lantern_templateinventory}} i ON u.template = i.template AND u.siteId = i.siteId
            WHERE u.siteId = :siteId
            AND (i.template IS NULL OR i.isActive = 0)
            ORDER BY u.totalHits DESC
        ";

        $results = Craft::$app->getDb()->createCommand($query, [
            ':siteId' => $siteId,
        ])->queryAll();

        return $results;
    }
}
