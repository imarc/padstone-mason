<?php

namespace imarc\padstonemason\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $userId
 * @property string $provider
 * @property string $model
 * @property int $promptTokens
 * @property int $completionTokens
 */
class UsageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%padstonemason_usage}}';
    }
}
