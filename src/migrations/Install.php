<?php

namespace imarc\padstonemason\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%padstonemason_conversations}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'entryId' => $this->integer(),
            'draftId' => $this->integer(),
            'siteId' => $this->integer(),
            'title' => $this->string()->notNull()->defaultValue('New chat'),
            'titleCustom' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%padstonemason_conversations}}', ['userId', 'entryId']);
        $this->addForeignKey(null, '{{%padstonemason_conversations}}', ['userId'], '{{%users}}', ['id'], 'CASCADE', 'CASCADE');

        $this->createTable('{{%padstonemason_messages}}', [
            'id' => $this->primaryKey(),
            'conversationId' => $this->integer()->notNull(),
            'role' => $this->string(20)->notNull(),
            'content' => $this->longText(),
            'toolCallId' => $this->string(),
            'toolName' => $this->string(),
            'toolCalls' => $this->longText(),
            'metadata' => $this->longText(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%padstonemason_messages}}', ['conversationId']);
        $this->addForeignKey(null, '{{%padstonemason_messages}}', ['conversationId'], '{{%padstonemason_conversations}}', ['id'], 'CASCADE', 'CASCADE');

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

        $this->createTable('{{%padstonemason_usage}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'provider' => $this->string()->notNull(),
            'model' => $this->string()->notNull(),
            'promptTokens' => $this->integer()->notNull(),
            'completionTokens' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%padstonemason_usage}}', ['userId', 'dateCreated']);
        $this->addForeignKey(null, '{{%padstonemason_usage}}', ['userId'], '{{%users}}', ['id'], 'CASCADE', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%padstonemason_usage}}');
        $this->dropTableIfExists('{{%padstonemason_conversation_assets}}');
        $this->dropTableIfExists('{{%padstonemason_messages}}');
        $this->dropTableIfExists('{{%padstonemason_conversations}}');

        return true;
    }
}
