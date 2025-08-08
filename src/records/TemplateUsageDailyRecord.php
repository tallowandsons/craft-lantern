<?php

namespace tallowandsons\lantern\records;

use craft\db\ActiveRecord;

/**
 * Template Usage Daily record
 *
 * @property int $id
 * @property string $template
 * @property int $siteId
 * @property string $day
 * @property int $hits
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class TemplateUsageDailyRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%lantern_usage_daily}}';
    }

    /**
     * Find or create a record for the given template, site, and day
     */
    public static function findOrCreate(string $template, int $siteId, string $day): self
    {
        $record = static::findOne([
            'template' => $template,
            'siteId' => $siteId,
            'day' => $day,
        ]);

        if (!$record) {
            $record = new static();
            $record->template = $template;
            $record->siteId = $siteId;
            $record->day = $day;
            $record->hits = 0;
        }

        return $record;
    }
}
