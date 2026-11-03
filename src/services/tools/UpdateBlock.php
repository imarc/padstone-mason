<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class UpdateBlock extends BaseTool
{
    public function name(): string
    {
        return 'update_block';
    }

    public function description(): string
    {
        return 'Update writable fields on one block. Omit fields that should stay the same. Pass existing asset ids for Assets fields. Do not send raw file data. Use this when a Matrix field is at its maximum and the content should change in place. For several block changes in one request, prefer apply_blocks.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'blockId' => ['type' => 'integer'],
            'values' => ['type' => 'object'],
        ], ['blockId', 'values']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $block = $this->drafts->ownedBlock($conversation, $user, (int)($arguments['blockId'] ?? 0));
        if (!$block instanceof Entry) {
            return $block;
        }

        return Plugin::getInstance()->contentDesigner->updateBlock(
            $block,
            is_array($arguments['values'] ?? null) ? $arguments['values'] : [],
            $user,
        );
    }
}
