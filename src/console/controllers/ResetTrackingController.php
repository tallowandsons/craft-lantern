<?php

namespace tallowandsons\lantern\console\controllers;

use Craft;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Reset tracking controller: php craft lantern/reset-tracking
 */
class ResetTrackingController extends Controller
{
    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $db = Craft::$app->getDb();
        $this->stdout("Resetting Lantern tracking metadata...\n", \yii\helpers\Console::FG_CYAN);

        // Truncate lantern_meta
        try {
            $db->createCommand()->delete('{{%lantern_meta}}')->execute();
            $this->stdout("Cleared lantern_meta timestamps.\n", \yii\helpers\Console::FG_GREEN);
        } catch (\Throwable $e) {
            $this->stderr("Failed to clear lantern_meta: {$e->getMessage()}\n", \yii\helpers\Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Done. Tracking will be re-initialized on the next successful flush.\n");
        return ExitCode::OK;
    }
}
