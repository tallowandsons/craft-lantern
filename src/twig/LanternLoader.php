<?php

namespace tallowandsons\lantern\twig;

use Twig\Source;
use Craft;
use craft\web\twig\TemplateLoader;
use yii\log\Logger;

final class LanternLoader extends TemplateLoader
{
    private array $loggedThisRequest = [];

    // This is called for *every* template Twig resolves (includes, embeds, extends, pages)
    public function getSourceContext(string $name): Source
    {
        $this->logOnce($name);

        return parent::getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        // Some Twig paths hit this first; log here too (still de-duped)
        $this->logOnce($name);

        return parent::getCacheKey($name);
    }

    private function logOnce(string $name): void
    {
        if (isset($this->loggedThisRequest[$name])) {
            return;
        }

        $this->loggedThisRequest[$name] = true;

        // Ultra-minimal persistence: one line per template per request
        // Format: ISO timestamp, URL, template name
        $url = Craft::$app->getRequest()->getAbsoluteUrl();
        Craft::getLogger()->log(
            sprintf('[lantern] %s %s %s', gmdate('c'), $url, $name),
            Logger::LEVEL_INFO,
            'lantern'
        );
    }
}
