<?php

namespace tallowandsons\lantern\migrations;

use craft\db\Migration;

/**
 * Adds lantern_meta table and firstSeen columns for existing installs
 */
class m250811_000001_tracking_meta extends Migration
{
    public function safeUp(): bool
    {
        // lantern_meta
        if (!$this->db->tableExists('{{%lantern_meta}}')) {
            $this->createTable('{{%lantern_meta}}', [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull()->defaultValue(0),
                'trackingStartedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex('idx_lantern_meta_site', '{{%lantern_meta}}', 'siteId', true);
        }

        // firstSeen in lantern_usage_total
        if ($this->db->columnExists('{{%lantern_usage_total}}', 'firstSeen') === false) {
            $this->addColumn('{{%lantern_usage_total}}', 'firstSeen', $this->dateTime()->null()->after('lastUsed'));
            $this->createIndex('idx_lantern_usage_total_firstseen', '{{%lantern_usage_total}}', 'firstSeen');
        }

        // firstSeen in lantern_templateinventory
        if ($this->db->columnExists('{{%lantern_templateinventory}}', 'firstSeen') === false) {
            $this->addColumn('{{%lantern_templateinventory}}', 'firstSeen', $this->dateTime()->null()->after('isActive'));
            $this->createIndex('idx_lantern_inventory_first_seen', '{{%lantern_templateinventory}}', 'firstSeen');
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Leave meta table and columns by default to avoid data loss
        // But support down by removing added columns and table
        if ($this->db->tableExists('{{%lantern_templateinventory}}') && $this->db->columnExists('{{%lantern_templateinventory}}', 'firstSeen')) {
            $this->dropColumn('{{%lantern_templateinventory}}', 'firstSeen');
        }
        if ($this->db->tableExists('{{%lantern_usage_total}}') && $this->db->columnExists('{{%lantern_usage_total}}', 'firstSeen')) {
            $this->dropColumn('{{%lantern_usage_total}}', 'firstSeen');
        }
        if ($this->db->tableExists('{{%lantern_meta}}')) {
            $this->dropTable('{{%lantern_meta}}');
        }
        return true;
    }
}
