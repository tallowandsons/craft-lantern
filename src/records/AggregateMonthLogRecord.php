<?php

namespace tallowandsons\lantern\records;

use craft\db\ActiveRecord;

/**
 * Records months aggregated per site for idempotency
 *
 * @property int $id
 * @property int $siteId
 * @property string $month
 * @property string $aggregatedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class AggregateMonthLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lantern_aggregate_month_log}}';
    }
}
