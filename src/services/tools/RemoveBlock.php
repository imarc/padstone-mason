<?php

namespace imarc\padstonemason\services\tools;

use craft\base\ElementInterface;
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
        return 'Remove one Content Designer / structure block. Refused when it would break a minimum count.';
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
        if (is_array($block)) {
            return $block;
        }
        if (!$block instanceof ElementInterface) {
            return ['ok' => false, 'saved' => false, 'error' => 'That block is not on this draft.'];
        }

        return Plugin::getInstance()->contentDesigner->removeBlock($block, $user);
    }
}
