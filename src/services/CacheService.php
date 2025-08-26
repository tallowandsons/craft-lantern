<?php

namespace tallowandsons\lantern\services;

use Craft;
use tallowandsons\lantern\Lantern;
use tallowandsons\lantern\jobs\FlushAndAggregateJob;
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

    /** Soft debounce flags for auto-flush queueing */
    private const CACHE_KEY_FLUSH_QUEUED = 'lantern:flush:queued';
    private const CACHE_KEY_LAST_FLUSH_AT = 'lantern:flush:last';

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

        // Enqueue background flush if needed (cheap, non-blocking)
        $this->maybeQueueFlush();

        // Log the increment for debugging (only in debug mode)
        Lantern::getInstance()->log->logTemplateIncrement($templateName);
    }

    /**
     * Decide if we should queue a background flush job.
     * - Throttled by interval
     * - Optional hit threshold
     * - Optional CP-only
     * - Uses a short-lived cache flag to avoid duplicate enqueues across nodes
     */
    private function maybeQueueFlush(): void
    {
        static $queuedThisRequest = false;
        if ($queuedThisRequest) {
            return;
        }

        $settings = Lantern::getInstance()->getSettings();
        if (!($settings->autoFlushEnabled ?? true)) {
            return;
        }
        if (($settings->autoFlushOnlyOnCpRequests ?? false) && !Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        $cache = Craft::$app->getCache();
        $interval = max(60, (int)($settings->autoFlushIntervalSeconds ?? 300));

        // Already queued recently?
        if ($cache->get(self::CACHE_KEY_FLUSH_QUEUED)) {
            return;
        }

        // Respect interval
        $last = (int)($cache->get(self::CACHE_KEY_LAST_FLUSH_AT) ?: 0);
        if ($last && (time() - $last) < $interval) {
            return;
        }

        // Mark as queued and push a background job
        $cache->set(self::CACHE_KEY_FLUSH_QUEUED, 1, $interval);
        $cache->set(self::CACHE_KEY_LAST_FLUSH_AT, time(), 86400);

        Craft::$app->getQueue()->push(new FlushAndAggregateJob([
            'options' => [], // use CP defaults for aggregation/pruning
        ]));

        $queuedThisRequest = true;
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
