<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class UnpublishEntry extends BaseTool
{
    public function name(): string
    {
        return 'unpublish_entry';
    }

    public function description(): string
    {
        return 'Disable the live entry bound to this conversation so it is no longer public. Call this only when the user explicitly asks to unpublish. The entry and this conversation binding stay in place.';
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

        return Plugin::getInstance()->contentDesigner->unpublishEntry($entry, $user);
    }
}
