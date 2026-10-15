<?php

namespace imarc\padstonemason\migrations;

use craft\db\Migration;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class m261015_180000_add_conversation_title extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%padstonemason_conversations}}', 'title')) {
            $this->addColumn(
                '{{%padstonemason_conversations}}',
                'title',
                $this->string()->notNull()->defaultValue('New chat'),
            );
        }

        $this->db->getSchema()->refreshTableSchema('{{%padstonemason_conversations}}');

        foreach (ConversationRecord::find()->all() as $record) {
            if (!$record instanceof ConversationRecord) {
                continue;
            }
            Plugin::getInstance()->conversations->applyDerivedTitle($record);
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%padstonemason_conversations}}', 'title')) {
            $this->dropColumn('{{%padstonemason_conversations}}', 'title');
        }

        return true;
    }
}
