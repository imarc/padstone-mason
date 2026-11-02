<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class DescribeSchema extends BaseTool
{
    public function name(): string
    {
        return 'describe_schema';
    }

    public function description(): string
    {
        return 'List the entry type field layout. layoutFields includes Matrix fields such as hero and contentDesigner, with their block types. Use only those fields and block types.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'entryType' => ['type' => 'string'],
        ]);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $entryType = (string)($arguments['entryType'] ?? $this->drafts->boundEntryType($conversation, $user));

        return Plugin::getInstance()->contentDesigner->describeSchema($entryType, $user);
    }
}
