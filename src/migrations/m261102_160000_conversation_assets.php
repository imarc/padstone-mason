<?php

namespace imarc\padstonemason\migrations;

use craft\db\Migration;

class m261102_160000_conversation_assets extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%padstonemason_conversation_assets}}')) {
            $this->createTable('{{%padstonemason_conversation_assets}}', [
                'id' => $this->primaryKey(),
                'conversationId' => $this->integer()->notNull(),
                'assetId' => $this->integer()->notNull(),
                'sourceUrl' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%padstonemason_conversation_assets}}', ['conversationId', 'assetId'], true);
            $this->createIndex(null, '{{%padstonemason_conversation_assets}}', ['assetId']);
            $this->addForeignKey(
                null,
                '{{%padstonemason_conversation_assets}}',
                ['conversationId'],
                '{{%padstonemason_conversations}}',
                ['id'],
                'CASCADE',
                'CASCADE',
            );
        }

        if (!$this->db->columnExists('{{%padstonemason_messages}}', 'metadata')) {
            $this->addColumn(
                '{{%padstonemason_messages}}',
                'metadata',
                $this->longText()->null()->after('toolCalls'),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%padstonemason_messages}}', 'metadata')) {
            $this->dropColumn('{{%padstonemason_messages}}', 'metadata');
        }
        $this->dropTableIfExists('{{%padstonemason_conversation_assets}}');

        return true;
    }
}
