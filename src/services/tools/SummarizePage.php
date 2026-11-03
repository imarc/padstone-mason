<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class SummarizePage extends BaseTool
{
    public function name(): string
    {
        return 'summarize_page';
    }

    public function description(): string
    {
        return 'Compact outline of the bound draft: title, slug, and each Matrix field with min/max/count, atMin/atMax, and blocks as id/type/short text signal. Prefer this for orientation before detailed edits. Use read_layout when full field values are needed.';
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

        return Plugin::getInstance()->contentDesigner->summarizePage($draft, $user);
    }
}
