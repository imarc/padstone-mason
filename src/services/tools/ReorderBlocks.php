<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;
use imarc\padstonemason\services\FieldSupport;

class ReorderBlocks extends BaseTool
{
    public function name(): string
    {
        return 'reorder_blocks';
    }

    public function description(): string
    {
        return 'Reorder sibling blocks. orderedIds must list each current sibling once.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'fieldHandle' => ['type' => 'string'],
            'parentId' => ['type' => 'integer'],
            'orderedIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ], ['orderedIds']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $owner = $this->drafts->ownerForField($conversation, $user, $arguments['parentId'] ?? null);
        if (!$owner instanceof Entry) {
            return $owner;
        }

        return Plugin::getInstance()->contentDesigner->reorderBlocks(
            $owner,
            (string)($arguments['fieldHandle'] ?? FieldSupport::CONTENT_DESIGNER),
            is_array($arguments['orderedIds'] ?? null) ? $arguments['orderedIds'] : [],
            $user,
        );
    }
}
