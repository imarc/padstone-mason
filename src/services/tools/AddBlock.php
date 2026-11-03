<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;
use imarc\padstonemason\services\FieldSupport;

class AddBlock extends BaseTool
{
    public function name(): string
    {
        return 'add_block';
    }

    public function description(): string
    {
        return 'Add one block type from the schema. fieldHandle defaults to contentDesigner. For another structure field on the entry, such as hero, pass that handle and one of its block types. Refused when the field is already at its maximum—update the existing block instead. For nested Matrix fields with a minimum, pass nested blocks in the same call. Nested Neo levels are not supported. For several block changes in one request, prefer apply_blocks.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'fieldHandle' => ['type' => 'string'],
            'type' => ['type' => 'string'],
            'position' => ['type' => 'integer'],
            'parentId' => ['type' => 'integer'],
            'values' => ['type' => 'object'],
            'nested' => ['type' => 'object'],
        ], ['type']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $owner = $this->drafts->ownerForField($conversation, $user, $arguments['parentId'] ?? null);
        if (!$owner instanceof Entry) {
            return $owner;
        }

        return Plugin::getInstance()->contentDesigner->addBlock(
            $owner,
            (string)($arguments['fieldHandle'] ?? FieldSupport::CONTENT_DESIGNER),
            (string)($arguments['type'] ?? ''),
            isset($arguments['position']) ? (int)$arguments['position'] : null,
            is_array($arguments['values'] ?? null) ? $arguments['values'] : [],
            is_array($arguments['nested'] ?? null) ? $arguments['nested'] : [],
            $user,
        );
    }
}
