<?php

namespace tallowandsons\lantern\web\assets\cp;

use Craft;
use craft\web\AssetBundle;

/**
 * Cp asset bundle
 */
class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';
    public $depends = [];
    public $js = [];
    public $css = ['cp.css'];
}
