<?php

namespace tallowandsons\lantern\console\controllers;

use Craft;
use craft\console\Controller;
use tallowandsons\lantern\Lantern;
use yii\console\ExitCode;

/**
 * Aggregation controller
 */
class AggregateController extends Controller
{
    public $defaultAction = 'monthly';

    /** @var array|null site IDs to include (comma separated) */
    public $siteId;
    /** @var string|null YYYY-MM */
    public $sinceMonth;
    /** @var string|null YYYY-MM */
    public $untilMonth;
    /** @var int|null days */
    public $dailyRetentionDays;
    /** @var int|null months */
    public $monthlyRetentionMonths;
    /** @var int 0|1 */
    public $prune = 1;
    /** @var int 0|1 */
    public $dryRun = 0;
    /** @var int 0|1 */
    public $verbose = 0;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'siteId';
        $options[] = 'sinceMonth';
        $options[] = 'untilMonth';
        $options[] = 'dailyRetentionDays';
        $options[] = 'monthlyRetentionMonths';
        $options[] = 'prune';
        $options[] = 'dryRun';
        $options[] = 'verbose';
        return $options;
    }

    /**
     * lantern/aggregate/monthly command
     */
    public function actionMonthly(): int
    {
        $siteIds = null;
        if (!empty($this->siteId)) {
            $siteIds = array_filter(array_map('intval', preg_split('/[,\s]+/', (string)$this->siteId)));
        }

        foreach (['sinceMonth' => $this->sinceMonth, 'untilMonth' => $this->untilMonth] as $label => $val) {
            if ($val !== null && !preg_match('/^\d{4}-\d{2}$/', $val)) {
                $this->stderr("Invalid $label format. Expected YYYY-MM.\n", \yii\helpers\Console::FG_RED);
                return ExitCode::DATAERR;
            }
        }

        $opts = [
            'siteIds' => $siteIds,
            'sinceMonth' => $this->sinceMonth,
            'untilMonth' => $this->untilMonth,
            'dailyRetentionDays' => $this->dailyRetentionDays !== null ? (int)$this->dailyRetentionDays : null,
            'monthlyRetentionMonths' => $this->monthlyRetentionMonths !== null ? (int)$this->monthlyRetentionMonths : null,
            'prune' => (bool)$this->prune,
            'dryRun' => (bool)$this->dryRun,
            'verbose' => (bool)$this->verbose,
        ];

        $this->stdout("Aggregating monthly usage...\n", \yii\helpers\Console::FG_CYAN);

        $result = Lantern::getInstance()->databaseService->aggregateMonthly($opts);

        if (!$result['success']) {
            $this->stderr("Aggregation failed: {$result['message']}\n", \yii\helpers\Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!empty($result['message'])) {
            $color = ($result['skipped'] ?? false) ? \yii\helpers\Console::FG_YELLOW : \yii\helpers\Console::FG_GREEN;
            $this->stdout($result['message'] . "\n", $color);
        }

        $this->stdout("Months processed: {$result['monthsProcessed']}\n");
        $this->stdout("Templates aggregated: {$result['templatesAggregated']}\n");
        $this->stdout("Total hits rolled up: {$result['totalHitsRolled']}\n");
        $this->stdout("Daily rows pruned: {$result['dailyRowsPruned']}\n");
        if (isset($result['monthlyRowsPruned'])) {
            $this->stdout("Monthly rows pruned: {$result['monthlyRowsPruned']}\n");
        }
        if ($result['dryRun']) {
            $this->stdout("Dry run - no changes were written.\n", \yii\helpers\Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }
}
