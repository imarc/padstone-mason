<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class RemoveBlock extends BaseTool
{
    public function name(): string
    {
        return 'remove_block';
    }

    public function description(): string
    {
        return 'Remove one block. Refused when it would break a minimum count.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'blockId' => ['type' => 'integer'],
        ], ['blockId']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $block = $this->drafts->ownedBlock($conversation, $user, (int)($arguments['blockId'] ?? 0));
        if (!$block instanceof Entry) {
            return $block;
        }

        return Plugin::getInstance()->contentDesigner->removeBlock($block, $user);
    }
}
