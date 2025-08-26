<?php

namespace tallowandsons\lantern\migrations;

use craft\db\Migration;

/**
 * Adds monthly aggregation tables
 */
class m250826_000001_monthly_aggregation extends Migration
{
    public function safeUp(): bool
    {
        // Monthly table
        if (!$this->db->tableExists('{{%lantern_usage_monthly}}')) {
            $this->createTable('{{%lantern_usage_monthly}}', [
                'id' => $this->primaryKey(),
                'template' => $this->string(255)->notNull(),
                'siteId' => $this->integer()->notNull(),
                'month' => $this->date()->notNull(), // first day of month in UTC
                'hits' => $this->integer()->unsigned()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(
                'idx_lantern_usage_monthly_template_site_month',
                '{{%lantern_usage_monthly}}',
                ['template', 'siteId', 'month'],
                true
            );
            $this->createIndex('idx_lantern_usage_monthly_month', '{{%lantern_usage_monthly}}', 'month');
            $this->createIndex('idx_lantern_usage_monthly_site_month', '{{%lantern_usage_monthly}}', ['siteId', 'month']);
        }

        // Aggregation log table
        if (!$this->db->tableExists('{{%lantern_aggregate_month_log}}')) {
            $this->createTable('{{%lantern_aggregate_month_log}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'month' => $this->date()->notNull(),
                'aggregatedAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(
                'idx_lantern_aggregate_month_log_site_month',
                '{{%lantern_aggregate_month_log}}',
                ['siteId', 'month'],
                true
            );
            $this->createIndex('idx_lantern_aggregate_month_log_month', '{{%lantern_aggregate_month_log}}', 'month');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%lantern_aggregate_month_log}}');
        $this->dropTableIfExists('{{%lantern_usage_monthly}}');
        return true;
    }
}
