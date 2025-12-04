<?php

namespace tallowandsons\lantern\jobs;

use Craft;
use craft\queue\BaseJob;
use tallowandsons\lantern\Lantern;

/**
 * Background job to scan the templates directory and update inventory.
 */
class TemplateScanJob extends BaseJob
{
    public function execute($queue): void
    {
        Lantern::getInstance()->inventoryService->scanTemplatesDirectory();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('lantern', 'Scan templates directory for Lantern inventory');
    }
}
