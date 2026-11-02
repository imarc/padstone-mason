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
        return 'Add a block type from the schema. fieldHandle defaults to contentDesigner. For another Matrix field on the entry, such as hero, pass that handle and one of its block types. Refused when the field is already at its maximum. For nested Matrix fields with a minimum, pass nested blocks in the same call.';
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
