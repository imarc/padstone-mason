<?php

namespace imarc\padstonemason\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $conversationId
 * @property string $role
 * @property string|null $content
 * @property string|null $toolCallId
 * @property string|null $toolName
 * @property string|null $toolCalls
 */
class MessageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%padstonemason_messages}}';
    }
}
