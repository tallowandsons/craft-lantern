<?php

namespace tallowandsons\lantern\jobs;

use Craft;
use craft\queue\BaseJob;
use tallowandsons\lantern\Lantern;

/**
 * Flushes Lantern's cache to the database in the background queue.
 */
class FlushCacheJob extends BaseJob
{
    public function execute($queue): void
    {
        // Flushes the entire Lantern cache to DB; no site scoping
        Lantern::getInstance()->databaseService->flushCacheToDatabase();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lantern', 'Flush Lantern cache to database');
    }
}
