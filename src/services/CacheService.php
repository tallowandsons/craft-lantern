<?php

namespace tallowandsons\lantern\services;

use Craft;
use tallowandsons\lantern\Lantern;
use tallowandsons\lantern\jobs\FlushAndAggregateJob;
use tallowandsons\lantern\jobs\TemplateScanJob;
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

    /** Soft debounce flags for auto-scan queueing */
    private const CACHE_KEY_SCAN_QUEUED = 'lantern:scan:queued';
    private const CACHE_KEY_LAST_SCAN_AT = 'lantern:scan:last';

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
     * @var array<string,string> Cache of canonical template paths keyed by Twig identifiers
     */
    private array $templatePathCache = [];

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
            $this->templateData = $this->canonicalizeCachedTemplates($cachedData);
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
     * Ensure cached template keys reflect canonical names so historical data lines up with inventory entries.
     *
     * @param array<string,array{hits:int,lastHit:int|null}> $cachedData
     * @return array<string,array{hits:int,lastHit:int|null}>
     */
    private function canonicalizeCachedTemplates(array $cachedData): array
    {
        $normalizedData = [];

        foreach ($cachedData as $template => $data) {
            if (!is_array($data) || !array_key_exists('hits', $data)) {
                continue;
            }

            $canonical = $this->normalizeTemplateName((string)$template);

            if (!isset($normalizedData[$canonical])) {
                $normalizedData[$canonical] = $data;
                continue;
            }

            $existingHits = $normalizedData[$canonical]['hits'] ?? 0;
            $incomingHits = $data['hits'] ?? 0;
            $normalizedData[$canonical]['hits'] = $existingHits + $incomingHits;

            $existingLastHit = $normalizedData[$canonical]['lastHit'] ?? null;
            $incomingLastHit = $data['lastHit'] ?? null;
            if ($incomingLastHit !== null && ($existingLastHit === null || $incomingLastHit > $existingLastHit)) {
                $normalizedData[$canonical]['lastHit'] = $incomingLastHit;
            }
        }

        return $normalizedData;
    }

    /**
     * Increment the hit count for a template (counts every render)
     */
    public function incrementTemplate(string $templateName): void
    {
        $templateName = $this->normalizeTemplateName($templateName);
        if ($templateName === '') {
            return;
        }

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
        $this->maybeScanTemplates();

        // Log the increment for debugging (only in debug mode)
        Lantern::getInstance()->log->logTemplateIncrement($templateName);
    }

    /**
     * Normalize template identifiers before persisting them to cache
     */
    private function normalizeTemplateName(string $templateName): string
    {
        $normalized = trim($templateName);

        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace('\\', '/', $normalized);

        $collapsedSlashes = preg_replace('#/+#', '/', $normalized);
        if (is_string($collapsedSlashes)) {
            $normalized = $collapsedSlashes;
        }

        $normalized = ltrim($normalized, '/');

        $strippedExtension = preg_replace('/\.(twig|html)$/i', '', $normalized);
        if (is_string($strippedExtension)) {
            $normalized = $strippedExtension;
        }

        if (isset($this->templatePathCache[$normalized])) {
            return $this->templatePathCache[$normalized];
        }

        return $this->templatePathCache[$normalized] = $this->canonicalizeTemplateName($normalized);
    }

    /**
     * Resolve template to its inventory-style path (e.g. include site handle prefix when applicable).
     */
    private function canonicalizeTemplateName(string $templateName): string
    {
        $canonical = $templateName;

        try {
            $resolvedPath = Craft::$app->getView()->resolveTemplate($templateName);
            $templatesRoot = Craft::getAlias('@templates');

            if (is_string($resolvedPath) && is_string($templatesRoot)) {
                $normalizedRoot = rtrim(str_replace('\\', '/', $templatesRoot), '/') . '/';
                $normalizedPath = str_replace('\\', '/', $resolvedPath);

                if (str_starts_with($normalizedPath, $normalizedRoot)) {
                    $relative = substr($normalizedPath, strlen($normalizedRoot));
                    $relative = ltrim($relative, '/');
                    $relative = preg_replace('/\.(twig|html)$/i', '', $relative);

                    if (is_string($relative) && $relative !== '') {
                        $canonical = $relative;
                    }
                }
            }
        } catch (\Throwable $exception) {
            Lantern::getInstance()->log->debug(
                "Template canonicalization failed for '{$templateName}': {$exception->getMessage()}",
                'cache'
            );
        }

        return $canonical;
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
     * Decide if we should queue a background template directory scan.
     * Ensures new templates are discovered without relying on cron.
     */
    private function maybeScanTemplates(): void
    {
        static $queuedThisRequest = false;
        if ($queuedThisRequest) {
            return;
        }

        $settings = Lantern::getInstance()->getSettings();
        if (!($settings->autoScanTemplatesEnabled ?? true)) {
            return;
        }

        $cache = Craft::$app->getCache();
        $interval = max(600, (int)($settings->autoScanIntervalSeconds ?? 86400));

        // Already queued recently?
        if ($cache->get(self::CACHE_KEY_SCAN_QUEUED)) {
            return;
        }

        // Respect interval between scans
        $last = (int)($cache->get(self::CACHE_KEY_LAST_SCAN_AT) ?: 0);
        if ($last && (time() - $last) < $interval) {
            return;
        }

        $this->recordTemplateScan($interval);
        Craft::$app->getQueue()->push(new TemplateScanJob());
        $queuedThisRequest = true;
    }

    /**
     * Mark that a template scan was queued or completed.
     * Updates debounce flags so manual/cron runs are respected.
     */
    public function recordTemplateScan(?int $intervalSeconds = null): void
    {
        $cache = Craft::$app->getCache();
        $settings = Lantern::getInstance()->getSettings();
        $interval = max(600, (int)($intervalSeconds ?? $settings->autoScanIntervalSeconds ?? 86400));

        $cache->set(self::CACHE_KEY_SCAN_QUEUED, 1, $interval);
        $cache->set(self::CACHE_KEY_LAST_SCAN_AT, time(), max($interval, 86400));
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
        $templateName = $this->normalizeTemplateName($templateName);
        if ($templateName === '') {
            return 0;
        }

        $this->loadCacheData();
        return $this->templateData[$templateName]['hits'] ?? 0;
    }

    /**
     * Get last hit timestamp for a specific template
     */
    public function getTemplateLastHit(string $templateName): ?int
    {
        $templateName = $this->normalizeTemplateName($templateName);
        if ($templateName === '') {
            return null;
        }

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
