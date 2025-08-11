<?php

namespace tallowandsons\lantern\records;

use craft\db\ActiveRecord;

/**
 * Lantern Meta record
 *
 * Stores trackingStartedAt per site (siteId=0 reserved for global).
 *
 * @property int $id
 * @property int $siteId
 * @property string|null $trackingStartedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class MetaRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%lantern_meta}}';
    }

    /**
     * Find or create meta row for site
     */
    public static function findOrCreate(int $siteId): self
    {
        $record = static::findOne(['siteId' => $siteId]);
        if (!$record) {
            $record = new static();
            $record->siteId = $siteId;
        }
        return $record;
    }
}
