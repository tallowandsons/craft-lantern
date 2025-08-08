<?php

namespace tallowandsons\lantern\services;

use Craft;
use craft\base\Component;
use craft\log\MonologTarget;
use tallowandsons\lantern\Lantern;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\log\Dispatcher;
use yii\log\Logger;

/**
 * Log service
 *
 * Provides dedicated logging functionality for the Lantern plugin,
 * using MonologTarget following Craft standards similar to Blitz.
 */
class LogService extends Component
{
    /**
     * @var bool Whether to enable debug logging
     */
    public bool $enableDebugLogging = false;

    /**
     * @var string The log channel name
     */
    private string $_logChannel = 'lantern';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        // Get debug logging setting from plugin settings
        $settings = Lantern::getInstance()->getSettings();
        $this->enableDebugLogging = $settings->enableDebugLogging ?? $this->enableDebugLogging;

        // Set up dedicated MonologTarget for Lantern logs
        $this->_setupMonologTarget();
    }

    /**
     * Log an informational message
     */
    public function info(string $message, ?string $category = null): void
    {
        $this->log($message, LogLevel::INFO, $category);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message, ?string $category = null): void
    {
        $this->log($message, LogLevel::WARNING, $category);
    }

    /**
     * Log an error message
     */
    public function error(string $message, ?string $category = null): void
    {
        $this->log($message, LogLevel::ERROR, $category);
    }

    /**
     * Log a debug message (only if debug logging is enabled)
     */
    public function debug(string $message, ?string $category = null): void
    {
        if ($this->enableDebugLogging) {
            $this->log($message, LogLevel::DEBUG, $category);
        }
    }

    /**
     * Log a critical error message
     */
    public function critical(string $message, ?string $category = null): void
    {
        $this->log($message, LogLevel::CRITICAL, $category);
    }

    /**
     * Log a message with the specified level
     */
    public function log(string $message, string $level = LogLevel::INFO, ?string $category = null): void
    {
        // Default category to the plugin handle
        if ($category === null) {
            $category = 'lantern';
        } else {
            // Prefix category with plugin handle for namespacing
            $category = 'lantern.' . $category;
        }

        // Convert PSR log level to Yii log level
        $yiiLevel = $this->_convertLogLevel($level);

        // Log the message using Craft's logger
        Craft::getLogger()->log($message, $yiiLevel, $category);
    }

    /**
     * Log template loading
     */
    public function logTemplateLoad(string $templateName, string $url): void
    {
        $this->info("Template loaded: '{$templateName}' for URL: '{$url}'", 'template');
    }

    /**
     * Set up the MonologTarget for Lantern logs
     */
    private function _setupMonologTarget(): void
    {
        // Only setup if we have a valid dispatcher
        if (!(Craft::getLogger()->dispatcher instanceof Dispatcher)) {
            return;
        }

        // Determine the log level based on debug setting
        $logLevel = $this->enableDebugLogging ? LogLevel::DEBUG : LogLevel::INFO;

        // Create the MonologTarget with Lantern-specific configuration
        $target = new MonologTarget([
            'name' => $this->_logChannel,
            'categories' => ['lantern*'],
            'level' => $logLevel,
            'logContext' => false,
            'allowLineBreaks' => false,
            'maxFiles' => 5,
            'formatter' => new LineFormatter(
                format: "[%datetime%] [%level_name%] [%extra.yii_category%] %message%\n",
                dateFormat: 'Y-m-d H:i:s',
                allowInlineLineBreaks: false,
                ignoreEmptyContextAndExtra: true,
            ),
        ]);

        // Add the target to the logger dispatcher
        Craft::getLogger()->dispatcher->targets[$this->_logChannel] = $target;
    }

    /**
     * Convert PSR log level to Yii log level
     */
    private function _convertLogLevel(string $psrLevel): int
    {
        return match ($psrLevel) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL => Logger::LEVEL_ERROR,
            LogLevel::ERROR => Logger::LEVEL_ERROR,
            LogLevel::WARNING => Logger::LEVEL_WARNING,
            LogLevel::NOTICE, LogLevel::INFO => Logger::LEVEL_INFO,
            LogLevel::DEBUG => Logger::LEVEL_TRACE,
            default => Logger::LEVEL_INFO,
        };
    }
}
