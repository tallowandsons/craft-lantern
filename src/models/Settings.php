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
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            ['enableDebugLogging', 'boolean'],
            ['staleDays', 'integer', 'min' => 1],
            ['newTemplateDays', 'integer', 'min' => 1],
        ];
    }
}
