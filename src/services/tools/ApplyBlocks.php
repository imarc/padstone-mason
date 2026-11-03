<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class ApplyBlocks extends BaseTool
{
    public function name(): string
    {
        return 'apply_blocks';
    }

    public function description(): string
    {
        return 'Apply an ordered list of block operations (add, update, remove, reorder) to the bound draft in one call. Prefer this for multi-block recipes. Stops on the first failure and reports each step; earlier successful steps stay saved. Keep recipes modest. Use atomic tools for a single edit or to finish remaining work after a partial failure. Each operation mirrors add_block / update_block / remove_block / reorder_blocks arguments plus an "op" field.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'operations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'op' => ['type' => 'string'],
                        'fieldHandle' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'position' => ['type' => 'integer'],
                        'parentId' => ['type' => 'integer'],
                        'blockId' => ['type' => 'integer'],
                        'orderedIds' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'values' => ['type' => 'object'],
                        'nested' => ['type' => 'object'],
                    ],
                    'required' => ['op'],
                ],
            ],
        ], ['operations']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $draft = $this->drafts->editableDraft($conversation, $user);
        if (!$draft instanceof Entry) {
            return $draft;
        }

        $operations = $arguments['operations'] ?? null;
        if (!is_array($operations)) {
            return ['ok' => false, 'saved' => false, 'error' => 'operations must be an array.'];
        }

        return Plugin::getInstance()->contentDesigner->applyBlocks($draft, $operations, $user);
    }
}
