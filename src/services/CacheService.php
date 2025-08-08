<?php

namespace tallowandsons\lantern\services;

use Craft;
use tallowandsons\lantern\Lantern;
use yii\base\Component;

/**
 * Cache Service
 *
 * Handles accumulation of template usage hits across requests.
 * Uses Craft's cache system to persist cumulative hit counts.
 * Counts every actual template render (no de-duplication).
 */
class CacheService extends Component
{
    /**
     * @var string Cache key for persistent template hits
     */
    private const CACHE_KEY_HITS = 'lantern_template_hits';

    /**
     * @var int Cache duration (24 hours)
     */
    private const CACHE_DURATION = 86400;

    /**
     * @var array Cumulative template usage data (persisted to cache)
     * Format: ['template/path.twig' => ['hits' => count, 'lastHit' => timestamp], ...]
     */
    private array $templateData = [];

    /**
     * @var bool Whether we've loaded cache data this request
     */
    private bool $cacheLoaded = false;

    /**
     * Load cache data from Craft's cache
     */
    private function loadCacheData(): void
    {
        if ($this->cacheLoaded) {
            return;
        }

        // Load cumulative template data from cache
        $cachedData = Craft::$app->getCache()->get(self::CACHE_KEY_HITS);
        if ($cachedData !== false) {
            $this->templateData = $cachedData;
        }

        $this->cacheLoaded = true;
    }

    /**
     * Save cache data to Craft's cache
     */
    private function saveCacheData(): void
    {
        // Save cumulative template data to cache
        Craft::$app->getCache()->set(
            self::CACHE_KEY_HITS,
            $this->templateData,
            self::CACHE_DURATION
        );
    }

    /**
     * Increment the hit count for a template (counts every render)
     */
    public function incrementTemplate(string $templateName): void
    {
        // Load cache data first
        $this->loadCacheData();

        // Initialize template data if not exists
        if (!isset($this->templateData[$templateName])) {
            $this->templateData[$templateName] = [
                'hits' => 0,
                'lastHit' => null,
            ];
        }

        // Increment the hit count and update timestamp
        $this->templateData[$templateName]['hits']++;
        $this->templateData[$templateName]['lastHit'] = time();

        // Save to cache immediately
        $this->saveCacheData();

        // Log the increment for debugging (only in debug mode)
        Lantern::getInstance()->log->logTemplateIncrement($templateName);
    }

    /**
     * Get all accumulated template usage data
     *
     * @return array Format: ['template/path.twig' => ['hits' => count, 'lastHit' => timestamp], ...]
     */
    public function getTemplateData(): array
    {
        $this->loadCacheData();
        return $this->templateData;
    }

    /**
     * Get all accumulated template hits (legacy method for backwards compatibility)
     *
     * @return array Format: ['template/path.twig' => hit_count, ...]
     */
    public function getTemplateHits(): array
    {
        $this->loadCacheData();
        $hits = [];
        foreach ($this->templateData as $template => $data) {
            $hits[$template] = $data['hits'];
        }
        return $hits;
    }

    /**
     * Get hit count for a specific template
     */
    public function getTemplateHitCount(string $templateName): int
    {
        $this->loadCacheData();
        return $this->templateData[$templateName]['hits'] ?? 0;
    }

    /**
     * Get last hit timestamp for a specific template
     */
    public function getTemplateLastHit(string $templateName): ?int
    {
        $this->loadCacheData();
        return $this->templateData[$templateName]['lastHit'] ?? null;
    }

    /**
     * Clear all accumulated template data
     */
    public function clearTemplateHits(): void
    {
        $this->templateData = [];

        // Clear the persistent cache
        Craft::$app->getCache()->delete(self::CACHE_KEY_HITS);

        $this->cacheLoaded = true; // Mark as loaded since we just cleared everything
    }

    /**
     * Get the total number of unique templates tracked
     */
    public function getTemplateCount(): int
    {
        $this->loadCacheData();
        return count($this->templateData);
    }

    /**
     * Get the total number of hits across all templates
     */
    public function getTotalHits(): int
    {
        $this->loadCacheData();
        $totalHits = 0;
        foreach ($this->templateData as $data) {
            $totalHits += $data['hits'];
        }
        return $totalHits;
    }
}
