<?php

namespace tallowandsons\lantern\records;

use craft\db\ActiveRecord;

/**
 * Template Usage Total record
 *
 * @property int $id
 * @property string $template
 * @property int $siteId
 * @property int $totalHits
 * @property int $pageHits
 * @property string|null $lastUsed
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class TemplateUsageTotalRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%lantern_usage_total}}';
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
            $record->totalHits = 0;
            $record->pageHits = 0;
        }

        return $record;
    }
}
