<?php

namespace tallowandsons\lantern\records;

use craft\db\ActiveRecord;

/**
 * Template Usage Monthly record
 *
 * @property int $id
 * @property string $template
 * @property int $siteId
 * @property string $month
 * @property int $hits
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class TemplateUsageMonthlyRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%lantern_usage_monthly}}';
    }
}
