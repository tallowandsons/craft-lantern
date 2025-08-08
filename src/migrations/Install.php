<?php

namespace tallowandsons\lantern\migrations;

use Craft;
use craft\db\Migration;

/**
 * Install migration.
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Create lantern_usage_total table
        if (!$this->db->tableExists('{{%lantern_usage_total}}')) {
            $this->createTable('{{%lantern_usage_total}}', [
                'id' => $this->primaryKey(),
                'template' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'totalHits' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'pageHits' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'lastUsed' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes for performance
            $this->createIndex(
                'idx_lantern_usage_total_template_site',
                '{{%lantern_usage_total}}',
                ['template', 'siteId'],
                true // unique
            );
            $this->createIndex(
                'idx_lantern_usage_total_site',
                '{{%lantern_usage_total}}',
                'siteId'
            );
            $this->createIndex(
                'idx_lantern_usage_total_lastused',
                '{{%lantern_usage_total}}',
                'lastUsed'
            );
        }

        // Create lantern_usage_daily table
        if (!$this->db->tableExists('{{%lantern_usage_daily}}')) {
            $this->createTable('{{%lantern_usage_daily}}', [
                'id' => $this->primaryKey(),
                'template' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'day' => $this->date()->notNull(),
                'hits' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Add indexes for performance
            $this->createIndex(
                'idx_lantern_usage_daily_template_site_day',
                '{{%lantern_usage_daily}}',
                ['template', 'siteId', 'day'],
                true // unique
            );
            $this->createIndex(
                'idx_lantern_usage_daily_site_day',
                '{{%lantern_usage_daily}}',
                ['siteId', 'day']
            );
            $this->createIndex(
                'idx_lantern_usage_daily_day',
                '{{%lantern_usage_daily}}',
                'day'
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Drop tables in reverse order
        $this->dropTableIfExists('{{%lantern_usage_daily}}');
        $this->dropTableIfExists('{{%lantern_usage_total}}');

        return true;
    }
}
