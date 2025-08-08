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
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            ['enableDebugLogging', 'boolean'],
        ];
    }
}
