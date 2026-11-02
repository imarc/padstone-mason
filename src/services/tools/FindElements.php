<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class FindElements extends BaseTool
{
    public function name(): string
    {
        return 'find_elements';
    }

    public function description(): string
    {
        return 'Find existing assets or entries by title so they can be related, or so an entry can be passed to rebind_entry. Prefer conversation assets listed in the instructions when the user already uploaded or imported an image.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'kind' => ['type' => 'string', 'enum' => ['asset', 'entry']],
            'title' => ['type' => 'string'],
        ], ['kind', 'title']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        return Plugin::getInstance()->contentDesigner->findElements(
            (string)($arguments['kind'] ?? 'entry'),
            (string)($arguments['title'] ?? ''),
            $user,
        );
    }
}
