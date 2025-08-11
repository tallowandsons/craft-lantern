<?php

namespace tallowandsons\lantern\migrations;

use Craft;
use craft\db\Migration;

/**
 * Remove pageHits column from lantern_usage_total table.
 */
class m250811_000001_remove_pagehits extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%lantern_usage_total}}', 'pageHits')) {
            $this->dropColumn('{{%lantern_usage_total}}', 'pageHits');
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Re-adding the column for rollback
        if (!$this->db->columnExists('{{%lantern_usage_total}}', 'pageHits')) {
            $this->addColumn('{{%lantern_usage_total}}', 'pageHits', $this->integer()->unsigned()->notNull()->defaultValue(0));
        }
        return true;
    }
}
