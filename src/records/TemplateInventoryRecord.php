<?php

namespace tallowandsons\lantern\records;

use craft\db\ActiveRecord;

/**
 * Template Inventory record
 *
 * @property int $id
 * @property string $template
 * @property int $siteId
 * @property string $filePath
 * @property string|null $fileModified
 * @property bool $isActive
 * @property string $lastScanned
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class TemplateInventoryRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%lantern_templateinventory}}';
    }

    /**
     * Find or create a record for the given template and site
     */
    public static function findOrCreate(string $template, int $siteId): self
    {
        $record = static::findOne([
            'template' => $template,
            'siteId' => $siteId,
        ]);

        if (!$record) {
            $record = new static();
            $record->template = $template;
            $record->siteId = $siteId;
            $record->isActive = true;
        }

        return $record;
    }

    /**
     * Mark all templates as inactive for a site (for cleanup before scan)
     */
    public static function markAllInactive(int $siteId): void
    {
        static::updateAll(
            ['isActive' => false],
            ['siteId' => $siteId]
        );
    }

    /**
     * Delete inactive templates for a site (cleanup after scan)
     */
    public static function deleteInactive(int $siteId): int
    {
        return static::deleteAll([
            'siteId' => $siteId,
            'isActive' => false,
        ]);
    }
}
