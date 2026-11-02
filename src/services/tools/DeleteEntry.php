<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class DeleteEntry extends BaseTool
{
    public function name(): string
    {
        return 'delete_entry';
    }

    public function description(): string
    {
        return 'Move the entry bound to this conversation to the trash. Call this only when the user explicitly asks to delete. This clears the conversation binding. The previous draft is removed with the entry.';
    }

    public function parameters(): array
    {
        return $this->objectSchema();
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $entry = $this->drafts->boundDraft($conversation, $user);
        if (!$entry) {
            return ['ok' => false, 'saved' => false, 'error' => 'No entry is bound to this conversation.'];
        }

        $result = Plugin::getInstance()->contentDesigner->deleteEntry($entry, $user);
        if (!empty($result['ok'])) {
            $conversation->entryId = null;
            $conversation->draftId = null;
            $conversation->save(false);
        }

        return $result;
    }
}
