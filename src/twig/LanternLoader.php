<?php

namespace tallowandsons\lantern\twig;

use Twig\Source;
use Craft;
use craft\web\twig\TemplateLoader;
use tallowandsons\lantern\Lantern;

final class LanternLoader extends TemplateLoader
{
    // This is called for *every* template Twig resolves (includes, embeds, extends, pages)
    public function getSourceContext(string $name): Source
    {
        $this->logTemplate($name);

        return parent::getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        // Some Twig paths hit this first; log here too (still de-duped)
        $this->logTemplate($name);

        return parent::getCacheKey($name);
    }

    private function logTemplate(string $name): void
    {
        $lantern = Lantern::getInstance();

        // Accumulate template usage data in the cache service
        $lantern->cacheService->incrementTemplate($name);

        // Also log for debugging/monitoring (only if not already logged)

        if ($lantern->debuggingEnabled()) {
            if (!$lantern->cacheService->isTemplateLogged($name)) {
                $url = Craft::$app->getRequest()->getAbsoluteUrl();
                $lantern->log->logTemplateLoad($name, $url);
            }
        }
    }
}
