<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class ReadLayout extends BaseTool
{
    public function name(): string
    {
        return 'read_layout';
    }

    public function description(): string
    {
        return 'Read the draft title, writable layout fields, and full block field values in each Matrix field. matrices includes hero and contentDesigner, with atMin/atMax and a max hint. Prefer summarize_page for orientation; use this when you need full values.';
    }

    public function parameters(): array
    {
        return $this->objectSchema();
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $draft = $this->drafts->editableDraft($conversation, $user);
        if (!$draft instanceof Entry) {
            return $draft;
        }

        return Plugin::getInstance()->contentDesigner->readLayout($draft, $user);
    }
}
