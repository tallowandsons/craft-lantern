<?php

namespace tallowandsons\lantern\models;

use Craft;
use craft\base\Model;

/**
 * Lantern settings
 */
class Settings extends Model
{
    /**
     * @var bool Whether to enable debug logging
     */
    public bool $enableDebugLogging = false;

    /**
     * @var int Number of days without use that marks a template as stale
     */
    public int $staleDays = 90;

    /**
     * @var int Number of days to consider a never-used template as "new"
     */
    public int $newTemplateDays = 7;

    /**
     * @var int How many recent days of daily data to keep (0 disables day-based pruning)
     */
    public int $dailyRetentionDays = 90;

    /**
     * @var int How many recent months of monthly data to keep (0 disables monthly pruning)
     */
    public int $monthlyRetentionMonths = 24;

    /**
     * @var bool Whether to prune data after aggregation by default
     */
    public bool $prune = true;

    /**
     * @var bool Enable background auto-flush from cache to DB (via queue)
     */
    public bool $autoFlushEnabled = true;

    /**
     * @var int Minimum seconds between background flush enqueues (debounce)
     */
    public int $autoFlushIntervalSeconds = 300;

    /**
     * @var bool Only enqueue auto-flush on CP requests (off = also on site requests)
     */
    public bool $autoFlushOnlyOnCpRequests = false;

    /**
     * @var int Minimum seconds between monthly aggregation runs (throttle)
     */
    public int $aggregateIntervalSeconds = 43200; // 12 hours default


    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            ['enableDebugLogging', 'boolean'],
            ['staleDays', 'integer', 'min' => 1],
            ['newTemplateDays', 'integer', 'min' => 1],
            ['dailyRetentionDays', 'integer', 'min' => 0],
            ['monthlyRetentionMonths', 'integer', 'min' => 0],
            ['prune', 'boolean'],
            ['autoFlushEnabled', 'boolean'],
            ['autoFlushOnlyOnCpRequests', 'boolean'],
            ['autoFlushIntervalSeconds', 'integer', 'min' => 60],
            ['aggregateIntervalSeconds', 'integer', 'min' => 600],
        ];
    }
}
