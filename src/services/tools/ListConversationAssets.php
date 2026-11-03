<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class ListConversationAssets extends BaseTool
{
    public function name(): string
    {
        return 'list_conversation_assets';
    }

    public function description(): string
    {
        return 'List images already linked to this conversation (uploads and imported URLs), with id, title, filename, and thumbnail. Prefer these ids when placing an attached image on an Assets field.';
    }

    public function parameters(): array
    {
        return $this->objectSchema();
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        return Plugin::getInstance()->conversationAssets->listForConversation((int)$conversation->id);
    }
}
