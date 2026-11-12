<?php

namespace imarc\padstonemason\migrations;

use craft\db\Migration;

/**
 * Ensures titleCustom exists (sites that upgraded code without running migrations).
 */
class m261112_200000_ensure_conversation_title_custom extends Migration
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
        return true;
    }
}
