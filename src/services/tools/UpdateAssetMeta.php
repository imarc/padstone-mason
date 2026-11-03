<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class UpdateAssetMeta extends BaseTool
{
    public function name(): string
    {
        return 'update_asset_meta';
    }

    public function description(): string
    {
        return 'Update title and/or alt text on an existing Craft asset the current user can save. Does not replace the file. Use after placing an image when the user asks for alt text or a better title.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'assetId' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'alt' => ['type' => 'string'],
        ], ['assetId']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $title = array_key_exists('title', $arguments) ? (string)$arguments['title'] : null;
        $alt = array_key_exists('alt', $arguments) ? (string)$arguments['alt'] : null;

        return Plugin::getInstance()->conversationAssets->updateMeta(
            (int)($arguments['assetId'] ?? 0),
            $title,
            $alt,
            $user,
        );
    }
}