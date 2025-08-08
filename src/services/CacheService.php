<?php

namespace tallowandsons\lantern\services;

use Craft;
use tallowandsons\lantern\Lantern;
use yii\base\Component;

/**
 * Cache Service
 *
 * Handles accumulation of template usage hits across requests.
 * Uses Craft's cache system to persist cumulative hit counts while maintaining
 * per-request de-duplication in memory.
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
     * @var array Cumulative template hit counts (persisted to cache)
     * Format: ['template/path.twig' => total_hit_count, ...]
     */
    private array $templateHits = [];

    /**
     * @var array Track which templates have been logged this request (in-memory only)
     * Format: ['template/path.twig' => true, ...]
     * This resets with each new request to allow proper per-request de-duplication
     */
    private array $loggedThisRequest = [];

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

        // Load cumulative template hits from cache
        $cachedHits = Craft::$app->getCache()->get(self::CACHE_KEY_HITS);
        if ($cachedHits !== false) {
            $this->templateHits = $cachedHits;
        }

        $this->cacheLoaded = true;
    }

    /**
     * Save cache data to Craft's cache
     */
    private function saveCacheData(): void
    {
        // Save cumulative template hits to cache
        Craft::$app->getCache()->set(
            self::CACHE_KEY_HITS,
            $this->templateHits,
            self::CACHE_DURATION
        );
    }

    /**
     * Increment the hit count for a template (with de-duplication per request)
     */
    public function incrementTemplate(string $templateName): void
    {
        // Load cache data first
        $this->loadCacheData();

        // De-duplicate within the same request
        if (isset($this->loggedThisRequest[$templateName])) {
            return;
        }

        $this->loggedThisRequest[$templateName] = true;

        // Increment the hit count in memory
        if (!isset($this->templateHits[$templateName])) {
            $this->templateHits[$templateName] = 0;
        }

        $this->templateHits[$templateName]++;

        // Save to cache immediately
        $this->saveCacheData();

        // Log the increment for debugging (only in debug mode)
        Lantern::getInstance()->log->logTemplateIncrement($templateName);
    }

    /**
     * Get all accumulated template hits for this request
     *
     * @return array Format: ['template/path.twig' => hit_count, ...]
     */
    public function getTemplateHits(): array
    {
        $this->loadCacheData();
        return $this->templateHits;
    }

    /**
     * Get hit count for a specific template
     */
    public function getTemplateHitCount(string $templateName): int
    {
        $this->loadCacheData();
        return $this->templateHits[$templateName] ?? 0;
    }

    /**
     * Clear all accumulated template hits
     */
    public function clearTemplateHits(): void
    {
        $this->templateHits = [];
        $this->loggedThisRequest = [];

        // Clear the persistent cache
        Craft::$app->getCache()->delete(self::CACHE_KEY_HITS);

        $this->cacheLoaded = true; // Mark as loaded since we just cleared everything
    }

    /**
     * Get the total number of unique templates tracked this request
     */
    public function getTemplateCount(): int
    {
        $this->loadCacheData();
        return count($this->templateHits);
    }

    /**
     * Get the total number of hits across all templates this request
     */
    public function getTotalHits(): int
    {
        $this->loadCacheData();
        return array_sum($this->templateHits);
    }

    /**
     * Check if a template has been logged this request (for de-duplication)
     */
    public function isTemplateLogged(string $templateName): bool
    {
        // Note: We don't need to load cache data for this check
        // This is purely in-memory per-request tracking
        return isset($this->loggedThisRequest[$templateName]);
    }
}
