<?php

namespace imarc\padstonemason\migrations;

use craft\db\Migration;

class m261112_180000_conversation_title_custom extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%padstonemason_conversations}}', 'titleCustom')) {
            $this->addColumn(
                '{{%padstonemason_conversations}}',
                'titleCustom',
                $this->boolean()->notNull()->defaultValue(false),
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%padstonemason_conversations}}', 'titleCustom')) {
            $this->dropColumn('{{%padstonemason_conversations}}', 'titleCustom');
        }

        return true;
    }
}
