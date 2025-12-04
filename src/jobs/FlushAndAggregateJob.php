<?php

namespace tallowandsons\lantern\jobs;

use Craft;
use craft\queue\BaseJob;
use tallowandsons\lantern\Lantern;

/**
 * Flush cache to DB and aggregate completed months in one background job.
 */
class FlushAndAggregateJob extends BaseJob
{
    /**
     * Optional aggregation options; if omitted, service will use plugin defaults.
     * Supported keys mirror DatabaseService::aggregateMonthly options.
     * @var array
     */
    public array $options = [];

    public function execute($queue): void
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire('lantern:flush-aggregate', 0)) {
            // Another job is running; skip quietly
            return;
        }

        try {
            // 1) Always flush cache to DB (fast and safe)
            Lantern::getInstance()->databaseService->flushCacheToDatabase();

            // 2) Aggregate monthly with a time throttle from settings
            $cache = Craft::$app->getCache();
            $settings = Lantern::getInstance()->getSettings();
            if ($settings->enableAggregation ?? true) {
                $minInterval = max(600, (int)($settings->aggregateIntervalSeconds ?? 43200));
                $lastAgg = (int)($cache->get('lantern:aggregate:last') ?: 0);
                if (!$lastAgg || (time() - $lastAgg) >= $minInterval) {
                    Lantern::getInstance()->databaseService->aggregateMonthly($this->options);
                    $cache->set('lantern:aggregate:last', time(), 86400);
                }
            }
        } finally {
            $mutex->release('lantern:flush-aggregate');
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lantern', 'Flush Lantern cache and aggregate monthly analytics');
    }
}
