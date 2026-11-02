<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class CreatePage extends BaseTool
{
    public function name(): string
    {
        return 'create_page';
    }

    public function description(): string
    {
        return 'Create an unpublished draft in an allowed section using an entry type assigned to that section. Only call this when no entry is bound and the section and entry type are each an exact match. If they are not, ask the admin and include the sections and entry types from the instructions. Do not call this until they choose.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'title' => ['type' => 'string'],
            'section' => ['type' => 'string'],
            'entryType' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'parentId' => ['type' => 'integer'],
        ], ['title', 'section', 'entryType']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        if ($conversation->entryId) {
            return ['ok' => false, 'saved' => false, 'error' => 'This conversation is already editing an entry.'];
        }

        $result = Plugin::getInstance()->contentDesigner->createPage($arguments + [
            'siteId' => $conversation->siteId,
        ], $user);
        if (!empty($result['ok'])) {
            Plugin::getInstance()->conversations->bind(
                $conversation,
                (int)$result['entryId'],
                isset($result['draftId']) ? (int)$result['draftId'] : null,
                $conversation->siteId,
            );
        }

        return $result;
    }
}
