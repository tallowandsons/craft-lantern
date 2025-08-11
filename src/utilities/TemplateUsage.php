<?php

namespace tallowandsons\lantern\utilities;

use Craft;
use craft\base\Utility;
use tallowandsons\lantern\Lantern;
use tallowandsons\lantern\web\assets\cp\CpAsset;

/**
 * Template Usage utility
 */
class TemplateUsage extends Utility
{
    /**
     * Display name in CP utilities
     */
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

    /**
     * Render the utility content.
     *
     * Server-side computes status for each template by joining inventory and usage tables.
     * Provides summary counters and a filterable/sortable table dataset.
     */
    static function contentHtml(): string
    {
        $request = Craft::$app->getRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // Configurable cutoff for staleness
        $settings = Lantern::getInstance()->getSettings();
        $staleDays = property_exists($settings, 'staleDays') && $settings->staleDays > 0 ? (int)$settings->staleDays : 90;
        $newTemplateDays = property_exists($settings, 'newTemplateDays') ? max(0, (int)$settings->newTemplateDays) : 7;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$staleDays} days"));

        // Filters & sorting from query params
        $statusFilter = $request->getQueryParam('status', 'all'); // all|never|stale|active
        $sort = $request->getQueryParam('sort', 'template'); // template|totalHits|lastUsed|status
        $dir = strtolower($request->getQueryParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // Build dataset: join inventory (active only) with usage totals
        $db = Craft::$app->getDb();
        $query = "
            SELECT
                i.template,
                i.filePath,
        i.firstSeen AS inventoryFirstSeen,
                u.totalHits,
                u.lastUsed,
        u.firstSeen AS usageFirstSeen,
                CASE
                    WHEN u.template IS NULL OR u.lastUsed IS NULL THEN 'never'
                    WHEN u.lastUsed < :cutoffDate THEN 'stale'
                    ELSE 'active'
                END AS status
            FROM {{%lantern_templateinventory}} i
            LEFT JOIN {{%lantern_usage_total}} u
              ON i.template = u.template AND i.siteId = u.siteId
            WHERE i.siteId = :siteId
              AND i.isActive = 1
        ";

        $rows = $db->createCommand($query, [
            ':siteId' => $siteId,
            ':cutoffDate' => $cutoffDate,
        ])->queryAll();

        // Totals
        $total = count($rows);
        $never = 0;
        $stale = 0;
        $active = 0;
        $trackingSince = null;
        // Fetch trackingStartedAt for site
        $meta = Craft::$app->getDb()->createCommand('SELECT trackingStartedAt FROM {{%lantern_meta}} WHERE siteId = :siteId', [':siteId' => $siteId])->queryOne();
        if ($meta && !empty($meta['trackingStartedAt'])) {
            $trackingSince = $meta['trackingStartedAt'];
        } else {
            // fall back to global
            $meta = Craft::$app->getDb()->createCommand('SELECT trackingStartedAt FROM {{%lantern_meta}} WHERE siteId = 0')->queryOne();
            $trackingSince = $meta['trackingStartedAt'] ?? null;
        }

        foreach ($rows as $r) {
            if ($r['status'] === 'never') {
                $never++;
            } elseif ($r['status'] === 'stale') {
                $stale++;
            } else {
                $active++;
            }
        }

        // Filter
        if (in_array($statusFilter, ['never', 'stale', 'active', 'new'], true)) {
            $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $statusFilter));
        }

        // Normalize nulls and types for safe sorting/rendering
        foreach ($rows as &$r) {
            $r['totalHits'] = isset($r['totalHits']) ? (int)$r['totalHits'] : 0;
            $r['lastUsed'] = $r['lastUsed'] ?? null;
            $firstSeen = $r['usageFirstSeen'] ?? $r['inventoryFirstSeen'] ?? null;
            $r['firstSeen'] = $firstSeen;
            // Derive "new" status if enabled, never used, and recently first seen
            if ($newTemplateDays > 0 && ($r['status'] === 'never') && $firstSeen) {
                $isNew = (strtotime($firstSeen) >= strtotime("-{$newTemplateDays} days"));
                if ($isNew) {
                    $r['status'] = 'new';
                }
            }
        }
        unset($r);

        // Sorting
        $sortKey = in_array($sort, ['template', 'totalHits', 'lastUsed', 'status'], true) ? $sort : 'template';
        usort($rows, function ($a, $b) use ($sortKey, $dir) {
            $va = $a[$sortKey] ?? null;
            $vb = $b[$sortKey] ?? null;

            // For lastUsed, sort by timestamp with nulls last
            if ($sortKey === 'lastUsed') {
                $ta = $va ? strtotime($va) : null;
                $tb = $vb ? strtotime($vb) : null;
                if ($ta === $tb) return 0;
                if ($ta === null) return 1; // nulls last
                if ($tb === null) return -1;
                return $ta <=> $tb;
            }

            // For numeric fields, compare numerically
            if (in_array($sortKey, ['totalHits'], true)) {
                $cmp = ($a[$sortKey] <=> $b[$sortKey]);
            } else {
                $cmp = strcmp((string)$va, (string)$vb);
            }

            return $dir === 'asc' ? $cmp : -$cmp;
        });

        return Craft::$app->getView()->renderTemplate('lantern/cp/utilities/template-usage/index', [
            'summary' => [
                'total' => $total,
                'never' => $never,
                'stale' => $stale,
                'active' => $active,
            ],
            'rows' => $rows,
            'staleDays' => $staleDays,
            'newTemplateDays' => $newTemplateDays,
            'trackingSince' => $trackingSince,
            'filters' => [
                'status' => $statusFilter,
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }
}
