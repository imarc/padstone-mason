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
        return 'Find existing assets or entries to relate, link, or pass to rebind_entry. Filter entries by section, entryType, status (or "any"), and siteId. Filter assets by volume and folder path. Query matches title/search text (and asset filenames via search). Prefer list_conversation_assets for images already attached to this chat. If more than one result matches, ask which id to use.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'kind' => ['type' => 'string', 'enum' => ['asset', 'entry']],
            'query' => ['type' => 'string'],
            'title' => ['type' => 'string'],
            'section' => ['type' => 'string'],
            'entryType' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'siteId' => ['type' => 'integer'],
            'volume' => ['type' => 'string'],
            'folder' => ['type' => 'string'],
        ], ['kind']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $query = (string)($arguments['query'] ?? $arguments['title'] ?? '');

        return Plugin::getInstance()->contentDesigner->findElements(
            (string)($arguments['kind'] ?? 'entry'),
            $query,
            $user,
            [
                'section' => $arguments['section'] ?? null,
                'entryType' => $arguments['entryType'] ?? null,
                'status' => $arguments['status'] ?? null,
                'siteId' => $arguments['siteId'] ?? null,
                'volume' => $arguments['volume'] ?? null,
                'folder' => $arguments['folder'] ?? null,
            ],
        );
    }
}
