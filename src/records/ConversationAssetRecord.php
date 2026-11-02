<?php

namespace imarc\padstonemason\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $conversationId
 * @property int $assetId
 * @property string|null $sourceUrl
 */
class ConversationAssetRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%padstonemason_conversation_assets}}';
    }
}
