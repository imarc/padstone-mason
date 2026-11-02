<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\records\ConversationRecord;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(array $arguments, ConversationRecord $conversation, User $user): array;
}
