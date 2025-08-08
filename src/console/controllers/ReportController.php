<?php

namespace tallowandsons\lantern\console\controllers;

use Craft;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Report controller
 */
class ReportController extends Controller
{
    public $defaultAction = 'index';

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        switch ($actionID) {
            case 'index':
                // $options[] = '...';
                break;
        }
        return $options;
    }

    /**
     * lantern/report command
     */
    public function actionIndex(): int
    {
        // ...
        return ExitCode::OK;
    }
}
