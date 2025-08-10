<?php

namespace tallowandsons\lantern\utilities;

use Craft;
use craft\base\Utility;

/**
 * Template Usage utility
 */
class TemplateUsage extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('lantern', 'Template Usage');
    }

    static function id(): string
    {
        return 'template-usage';
    }

    public static function icon(): ?string
    {
        return 'wrench';
    }

    static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('lantern/cp/utilities/template-usage');
    }
}
