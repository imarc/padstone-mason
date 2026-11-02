<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class ImportAsset extends BaseTool
{
    public function name(): string
    {
        return 'import_asset';
    }

    public function description(): string
    {
        return 'Download an image from an http or https URL into the configured Craft volume and attach it to this conversation. Returns an asset id to use in Assets fields. Call this when the user gives a new image URL that is not already listed as a conversation asset. Chat uploads are handled separately.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'url' => ['type' => 'string'],
            'filename' => ['type' => 'string'],
        ], ['url']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $url = trim((string)($arguments['url'] ?? ''));
        $filename = isset($arguments['filename']) ? trim((string)$arguments['filename']) : null;
        $media = Plugin::getInstance()->conversationAssets;
        $imported = $media->importFromUrl($url, $user, $filename !== '' ? $filename : null);
        if (empty($imported['ok'])) {
            return ['ok' => false, 'saved' => false, 'error' => $imported['error'] ?? 'The image could not be imported.'];
        }

        $attached = $media->attach($conversation, [(int)$imported['assetId']], $user, $url);
        if (empty($attached['ok'])) {
            return ['ok' => false, 'saved' => false, 'error' => $attached['error'] ?? 'The image could not be linked.'];
        }

        return [
            'ok' => true,
            'saved' => true,
            'assetId' => (int)$imported['assetId'],
            'title' => (string)($imported['title'] ?? ''),
            'filename' => (string)($imported['filename'] ?? ''),
        ];
    }
}
