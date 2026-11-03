<?php

namespace imarc\padstonemason\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use craft\models\VolumeFolder;
use CraftCms\UrlValidator\UrlValidationException;
use CraftCms\UrlValidator\UrlValidator;
use GuzzleHttp\RequestOptions;
use imarc\padstonemason\models\Settings;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationAssetRecord;
use imarc\padstonemason\records\ConversationRecord;
use Throwable;
use yii\web\UploadedFile;

class ConversationAssets extends Component
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public const MAX_URLS_PER_MESSAGE = 5;

    /** @var string[] */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];

    /** @var string[] */
    public const IMAGE_MIME_PREFIXES = ['image/'];

    private ?UrlValidator $urlValidator = null;

    /**
     * @return array{ok: bool, assetId?: int, title?: string, filename?: string, error?: string}
     */
    public function uploadFromRequest(UploadedFile $file, User $user): array
    {
        if ($file->getHasError()) {
            return ['ok' => false, 'error' => 'The upload failed.'];
        }
        if ($file->size > self::MAX_FILE_BYTES) {
            return ['ok' => false, 'error' => 'That image is too large.'];
        }

        $filename = AssetsHelper::prepareAssetName((string)$file->name);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!$this->extensionAllowed($extension)) {
            return ['ok' => false, 'error' => 'Only image uploads are supported.'];
        }

        $tempPath = AssetsHelper::tempFilePath($extension);
        if (!@move_uploaded_file($file->tempName, $tempPath) && !@copy($file->tempName, $tempPath)) {
            return ['ok' => false, 'error' => 'The upload could not be stored.'];
        }

        $mime = FileHelper::getMimeType($tempPath, checkExtension: false) ?? (string)$file->type;
        if (!$this->mimeAllowed($mime)) {
            @unlink($tempPath);

            return ['ok' => false, 'error' => 'Only image uploads are supported.'];
        }

        return $this->saveTempFile($tempPath, $filename, $mime, $user, null);
    }

    /**
     * @return array{ok: bool, assetId?: int, title?: string, filename?: string, error?: string, sourceUrl?: string}
     */
    public function importFromUrl(string $url, User $user, ?string $filename = null): array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'A valid http or https image URL is required.'];
        }

        if ($filename === null || trim($filename) === '') {
            $filename = AssetsHelper::prepareAssetName(pathinfo(UrlHelper::stripQueryString($url), PATHINFO_BASENAME));
        } else {
            $filename = AssetsHelper::prepareAssetName($filename);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!$this->extensionAllowed($extension)) {
            // Try to infer from path even if basename lacked a useful name.
            $pathExt = strtolower(pathinfo(UrlHelper::stripQueryString(parse_url($url, PHP_URL_PATH) ?: ''), PATHINFO_EXTENSION));
            if ($this->extensionAllowed($pathExt)) {
                $extension = $pathExt;
                if (!str_contains($filename, '.')) {
                    $filename = AssetsHelper::prepareAssetName($filename . '.' . $extension);
                }
            } else {
                return ['ok' => false, 'error' => 'Only image URLs are supported.'];
            }
        }

        try {
            $ips = $this->urlValidator()->validate($url);
        } catch (UrlValidationException $e) {
            return ['ok' => false, 'error' => 'That URL is not allowed.'];
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), 'padstone-mason');

            return ['ok' => false, 'error' => 'That URL could not be validated.'];
        }

        $tempPath = AssetsHelper::tempFilePath($extension);
        try {
            $this->downloadUrl($url, $ips, $tempPath);
        } catch (Throwable $e) {
            @unlink($tempPath);
            Craft::error($e->getMessage(), 'padstone-mason');

            return ['ok' => false, 'error' => 'The image could not be downloaded.'];
        }

        if (!is_file($tempPath) || filesize($tempPath) === 0) {
            @unlink($tempPath);

            return ['ok' => false, 'error' => 'The image could not be downloaded.'];
        }
        if (filesize($tempPath) > self::MAX_FILE_BYTES) {
            @unlink($tempPath);

            return ['ok' => false, 'error' => 'That image is too large.'];
        }

        $mime = FileHelper::getMimeType($tempPath, checkExtension: false) ?? '';
        if (!$this->mimeAllowed($mime)) {
            @unlink($tempPath);

            return ['ok' => false, 'error' => 'Only image URLs are supported.'];
        }

        // Prefer extension from mime when the URL filename was generic.
        $mimeExt = null;
        try {
            $mimeExt = FileHelper::getExtensionByMimeType($mime);
        } catch (Throwable) {
        }
        if ($mimeExt && $this->extensionAllowed($mimeExt) && !$this->extensionAllowed(pathinfo($filename, PATHINFO_EXTENSION))) {
            $filename = AssetsHelper::prepareAssetName(pathinfo($filename, PATHINFO_FILENAME) . '.' . $mimeExt);
        }

        $result = $this->saveTempFile($tempPath, $filename, $mime, $user, $url);
        if (!empty($result['ok'])) {
            $result['sourceUrl'] = $url;
        }

        return $result;
    }

    /**
     * @return array{ok: bool, assets: array<int, array{assetId: int, title: string, filename: string, sourceUrl?: string}>, errors: string[]}
     */
    public function importUrlsFromText(string $text, User $user, ConversationRecord $conversation): array
    {
        $urls = $this->extractImageUrls($text);
        $errors = [];
        $assets = [];
        $existingUrls = $this->sourceUrlsForConversation((int)$conversation->id);

        foreach (array_slice($urls, 0, self::MAX_URLS_PER_MESSAGE) as $url) {
            if (isset($existingUrls[mb_strtolower($url)])) {
                continue;
            }
            $imported = $this->importFromUrl($url, $user);
            if (empty($imported['ok'])) {
                $errors[] = $imported['error'] ?? 'An image URL could not be imported.';
                continue;
            }
            $attached = $this->attach($conversation, [(int)$imported['assetId']], $user, $url);
            if (empty($attached['ok'])) {
                $errors[] = $attached['error'] ?? 'The imported image could not be linked.';
                continue;
            }
            $assets[] = [
                'assetId' => (int)$imported['assetId'],
                'title' => (string)($imported['title'] ?? ''),
                'filename' => (string)($imported['filename'] ?? ''),
                'sourceUrl' => $url,
            ];
            $existingUrls[mb_strtolower($url)] = true;
        }

        return ['ok' => $errors === [] || $assets !== [], 'assets' => $assets, 'errors' => $errors];
    }

    /**
     * @param int[] $assetIds
     * @return array{ok: bool, assetIds: int[], error?: string}
     */
    public function attach(ConversationRecord $conversation, array $assetIds, User $user, ?string $sourceUrl = null): array
    {
        $linked = [];
        foreach ($assetIds as $assetId) {
            $assetId = (int)$assetId;
            if ($assetId < 1) {
                continue;
            }
            $asset = Asset::find()->id($assetId)->status(null)->one();
            if (!$asset instanceof Asset || !Craft::$app->getElements()->canView($asset, $user)) {
                return ['ok' => false, 'assetIds' => $linked, 'error' => 'An asset could not be linked to this chat.'];
            }

            $existing = ConversationAssetRecord::findOne([
                'conversationId' => $conversation->id,
                'assetId' => $assetId,
            ]);
            if ($existing instanceof ConversationAssetRecord) {
                if ($sourceUrl && !$existing->sourceUrl) {
                    $existing->sourceUrl = $sourceUrl;
                    $existing->save(false);
                }
                $linked[] = $assetId;
                continue;
            }

            $record = new ConversationAssetRecord();
            $record->conversationId = (int)$conversation->id;
            $record->assetId = $assetId;
            $record->sourceUrl = $sourceUrl;
            $record->save(false);
            $linked[] = $assetId;
        }

        return ['ok' => true, 'assetIds' => $linked];
    }

    /**
     * @return array<int, array{id: int, title: string, filename: string, thumbUrl: ?string, url: ?string}>
     */
    public function summariesForConversation(int $conversationId): array
    {
        $items = [];
        foreach ($this->recordsForConversation($conversationId) as $record) {
            $asset = Asset::find()->id((int)$record->assetId)->status(null)->one();
            if (!$asset instanceof Asset) {
                continue;
            }
            $items[] = $this->summaryFromAsset($asset);
        }

        return $items;
    }

    /**
     * @param int[] $assetIds
     * @return array<int, array{id: int, title: string, filename: string, thumbUrl: ?string, url: ?string}>
     */
    public function summariesForIds(array $assetIds): array
    {
        $items = [];
        foreach ($assetIds as $assetId) {
            $asset = Asset::find()->id((int)$assetId)->status(null)->one();
            if (!$asset instanceof Asset) {
                continue;
            }
            $items[] = $this->summaryFromAsset($asset);
        }

        return $items;
    }

    public function promptList(int $conversationId): string
    {
        $summaries = $this->summariesForConversation($conversationId);
        if ($summaries === []) {
            return 'Conversation assets: none yet.';
        }

        $parts = [];
        foreach ($summaries as $item) {
            $parts[] = '#' . $item['id'] . ' "' . $item['filename'] . '"';
        }

        return 'Conversation assets (use these ids in Assets fields): ' . implode(', ', $parts) . '.';
    }

    /**
     * @return array{ok: true, assets: array<int, array{id: int, title: string, filename: string, thumbUrl: ?string, url: ?string}>}
     */
    public function listForConversation(int $conversationId): array
    {
        return [
            'ok' => true,
            'assets' => $this->summariesForConversation($conversationId),
        ];
    }

    /**
     * @return array{ok: bool, saved?: bool, assetId?: int, title?: string, alt?: ?string, filename?: string, error?: string}
     */
    public function updateMeta(int $assetId, ?string $title, ?string $alt, User $user): array
    {
        $asset = Asset::find()->id($assetId)->status(null)->one();
        if (!$asset instanceof Asset) {
            return ['ok' => false, 'saved' => false, 'error' => 'That asset was not found.'];
        }
        if (!Craft::$app->getElements()->canSave($asset, $user)) {
            return ['ok' => false, 'saved' => false, 'error' => 'You do not have permission to save that asset.'];
        }
        if ($title === null && $alt === null) {
            return ['ok' => false, 'saved' => false, 'error' => 'Provide a title and/or alt value to update.'];
        }

        if ($title !== null) {
            $asset->title = $title;
        }
        if ($alt !== null) {
            $asset->alt = $alt;
        }

        if (!Craft::$app->getElements()->saveElement($asset)) {
            $errors = $asset->getFirstErrors();

            return [
                'ok' => false,
                'saved' => false,
                'error' => $errors !== [] ? implode(' ', $errors) : 'The asset could not be saved.',
            ];
        }

        return [
            'ok' => true,
            'saved' => true,
            'assetId' => (int)$asset->id,
            'title' => (string)$asset->title,
            'alt' => $asset->alt,
            'filename' => (string)$asset->getFilename(),
        ];
    }

    /**
     * @return array{ok: bool, folder?: VolumeFolder, error?: string}
     */
    public function resolveUploadFolder(): array
    {
        $settings = $this->settings();
        $volume = $settings->assetVolume();
        if (!$volume) {
            return ['ok' => false, 'error' => 'The image upload volume is not configured.'];
        }

        try {
            $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
                $settings->assetFolderPath,
                $volume,
                false,
            );
        } catch (Throwable $e) {
            Craft::error($e->getMessage(), 'padstone-mason');

            return ['ok' => false, 'error' => 'The image upload folder could not be created.'];
        }

        return ['ok' => true, 'folder' => $folder];
    }

    /**
     * @return string[]
     */
    public function extractImageUrls(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('#https?://[^\s<>"\')\]]+#i', $text, $matches);
        $urls = [];
        foreach ($matches[0] ?? [] as $raw) {
            $url = rtrim((string)$raw, '.,;:!?');
            if ($this->urlLooksLikeImage($url)) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function urlLooksLikeImage(string $url): bool
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower(pathinfo(UrlHelper::stripQueryString($path), PATHINFO_EXTENSION));
        if ($this->extensionAllowed($extension)) {
            return true;
        }

        // Allow common CDN paths without a clear extension when the query hints at an image.
        $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
        if (preg_match('/\b(format|fm|type)=(jpe?g|png|gif|webp|svg)\b/i', $query)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{ok: bool, assetId?: int, title?: string, filename?: string, error?: string}
     */
    private function saveTempFile(string $tempPath, string $filename, string $mime, User $user, ?string $sourceUrl): array
    {
        $folderResult = $this->resolveUploadFolder();
        if (empty($folderResult['ok'])) {
            @unlink($tempPath);

            return ['ok' => false, 'error' => $folderResult['error'] ?? 'The upload folder is unavailable.'];
        }

        /** @var VolumeFolder $folder */
        $folder = $folderResult['folder'];
        $volume = $folder->getVolume();
        if (!$user->can('saveAssets:' . $volume->uid)) {
            @unlink($tempPath);

            return ['ok' => false, 'error' => 'You do not have permission to upload images to that volume.'];
        }

        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($filename);
        $asset->setMimeType($mime);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($folder->volumeId);
        $asset->uploaderId = (int)$user->id;
        $asset->avoidFilenameConflicts = true;
        $asset->title = AssetsHelper::filename2Title(pathinfo($filename, PATHINFO_FILENAME));
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            @unlink($tempPath);
            $errors = $asset->getFirstErrors();

            return ['ok' => false, 'error' => $errors !== [] ? reset($errors) : 'The image could not be saved.'];
        }

        return [
            'ok' => true,
            'assetId' => (int)$asset->id,
            'title' => (string)$asset->title,
            'filename' => (string)$asset->getFilename(),
            'sourceUrl' => $sourceUrl,
        ];
    }

    /**
     * @param string[] $ips
     */
    private function downloadUrl(string $url, array $ips, string $tempPath): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT)
            ?? (strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);

        Craft::createGuzzleClient()->request('GET', $url, [
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::SINK => $tempPath,
            RequestOptions::TIMEOUT => 20,
            'curl' => [
                CURLOPT_RESOLVE => ["$host:$port:" . implode(',', $ips)],
            ],
            RequestOptions::ON_STATS => function($stats) use ($url) {
                $ip = $stats->getHandlerStat('primary_ip');
                if ($ip && !$this->urlValidator()->validateIp($ip)) {
                    throw new UrlValidationException("$url is invalid.");
                }
            },
        ]);
    }

    /**
     * @return array{id: int, title: string, filename: string, thumbUrl: ?string, url: ?string}
     */
    private function summaryFromAsset(Asset $asset): array
    {
        $thumbUrl = null;
        $url = null;
        try {
            $thumbUrl = Craft::$app->getAssets()->getThumbUrl($asset, 64, 64, false);
        } catch (Throwable) {
        }
        try {
            $url = $asset->getUrl();
        } catch (Throwable) {
        }

        return [
            'id' => (int)$asset->id,
            'title' => (string)$asset->title,
            'filename' => (string)$asset->getFilename(),
            'thumbUrl' => $thumbUrl,
            'url' => $url,
        ];
    }

    /**
     * @return ConversationAssetRecord[]
     */
    private function recordsForConversation(int $conversationId): array
    {
        $records = [];
        foreach (ConversationAssetRecord::find()
            ->where(['conversationId' => $conversationId])
            ->orderBy(['id' => SORT_ASC])
            ->all() as $record) {
            if ($record instanceof ConversationAssetRecord) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @return array<string, true>
     */
    private function sourceUrlsForConversation(int $conversationId): array
    {
        $map = [];
        foreach ($this->recordsForConversation($conversationId) as $record) {
            if ($record->sourceUrl) {
                $map[mb_strtolower((string)$record->sourceUrl)] = true;
            }
        }

        return $map;
    }

    private function extensionAllowed(string $extension): bool
    {
        if ($extension === '' || !in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        $allowed = Craft::$app->getConfig()->getGeneral()->allowedFileExtensions;
        if (is_array($allowed) && $allowed !== [] && !in_array($extension, $allowed, true)) {
            return false;
        }

        return true;
    }

    private function mimeAllowed(string $mime): bool
    {
        $mime = strtolower(trim($mime));
        if ($mime === '') {
            return false;
        }
        foreach (self::IMAGE_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function urlValidator(): UrlValidator
    {
        return $this->urlValidator ??= new UrlValidator();
    }

    private function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        return $settings;
    }
}
