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
 * @property string|null $metadata
 */
class MessageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%padstonemason_messages}}';
    }
}
