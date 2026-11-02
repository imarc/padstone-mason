<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class PublishDraft extends BaseTool
{
    public function name(): string
    {
        return 'publish_draft';
    }

    public function description(): string
    {
        return 'Publish the bound draft. Call this only when the user explicitly asks to publish.';
    }

    public function parameters(): array
    {
        return $this->objectSchema();
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $draft = $this->drafts->boundDraft($conversation, $user);
        if (!$draft) {
            return ['ok' => false, 'error' => 'There is no draft to publish.', 'saved' => false];
        }

        $result = Plugin::getInstance()->contentDesigner->publishDraft($draft, $user);
        if (!empty($result['ok'])) {
            $conversation->entryId = (int)($result['entryId'] ?? $conversation->entryId);
            $conversation->draftId = null;
            $conversation->save(false);
        }

        return $result;
    }
}
