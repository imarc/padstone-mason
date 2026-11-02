<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class UpdateEntry extends BaseTool
{
    public function name(): string
    {
        return 'update_entry';
    }

    public function description(): string
    {
        return 'Update the draft title, slug, structure parent, or writable layout fields in values. Does not publish. Do not put Matrix fields in values.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'parentId' => ['type' => 'integer'],
            'values' => ['type' => 'object'],
        ]);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $draft = $this->drafts->editableDraft($conversation, $user);
        if (!$draft instanceof Entry) {
            return $draft;
        }

        return Plugin::getInstance()->contentDesigner->updateEntry($draft, $arguments, $user);
    }
}
