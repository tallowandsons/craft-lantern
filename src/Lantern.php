<?php

namespace tallowandsons\lantern;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\TemplateEvent;
use craft\services\Utilities;
use craft\web\View;
use tallowandsons\lantern\models\Settings;
use tallowandsons\lantern\services\CacheService;
use tallowandsons\lantern\services\DatabaseService;
use tallowandsons\lantern\services\InventoryService;
use tallowandsons\lantern\services\LogService;
use tallowandsons\lantern\twig\LanternLoader;
use tallowandsons\lantern\utilities\TemplateUsage;
use tallowandsons\lantern\web\assets\cp\CpAsset;
use yii\base\Event;

/**
 * Lantern plugin
 *
 * @method static Lantern getInstance()
 * @method Settings getSettings()
 * @property LogService $log
 * @author Tallow & Sons
 * @copyright Tallow & Sons
 * @license https://craftcms.github.io/license/ Craft License
 * @property-read CacheService $cacheService
 * @property-read DatabaseService $databaseService
 * @property-read InventoryService $inventoryService
 */
class Lantern extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'log' => LogService::class,
                'cacheService' => CacheService::class,
                'databaseService' => DatabaseService::class,
                'inventoryService' => InventoryService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();
        $this->registerAssetBundles();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function () {
            // replace Twig's loader with LanternLoader
            // if site request (not admin request)
            if (!Craft::$app->getRequest()->getIsCpRequest()) {
                $this->replaceLoader();
            }
        });
    }

    /**
     * Replaces Twig's loader with LanternLoader.
     */
    private function replaceLoader()
    {
        $view = Craft::$app->getView();
        $twig = $view->getTwig();
        $twig->setLoader(new LanternLoader($view));
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('lantern/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = TemplateUsage::class;
        });
    }

    /**
     * Registers asset bundles
     */
    private function registerAssetBundles(): void
    {
        // Load CSS before template is rendered
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            function (TemplateEvent $event) {
                if (Craft::$app->getRequest()->getIsCpRequest()) {
                    Craft::$app->view->registerAssetBundle(CpAsset::class);
                }
            }
        );
    }

    public function debuggingEnabled(): bool
    {
        return $this->getSettings()->enableDebugLogging;
    }
}
