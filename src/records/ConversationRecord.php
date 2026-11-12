<?php

namespace imarc\padstonemason\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $userId
 * @property int|null $entryId
 * @property int|null $draftId
 * @property int|null $siteId
 * @property string $title
 * @property bool $titleCustom
 */
class ConversationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%padstonemason_conversations}}';
    }

    public function beforeSave($insert): bool
    {
        if ($insert && ($this->title === null || $this->title === '')) {
            $this->title = 'New chat';
        }

        return parent::beforeSave($insert);
    }
}
