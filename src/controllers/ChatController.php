<?php

namespace imarc\padstonemason\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Markdown;
use craft\web\Controller;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\UploadedFile;

class ChatController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireUse();
        $user = Craft::$app->getUser()->getIdentity();
        $summaries = Plugin::getInstance()->conversations->summaries($user->id, null, null);
        $conversation = $this->selectedConversation($user->id, null, null, $summaries);

        return $this->renderTemplate('padstone-mason/index', $this->panelVars(
            $conversation,
            $user,
            $summaries,
            null,
            null,
            'all',
            'Ask for a change. It stays on a draft until you publish it.',
        ));
    }

    public function actionPanel(): Response
    {
        $this->requireUse();
        $entry = $this->entryFromRequest();
        $user = Craft::$app->getUser()->getIdentity();
        if (!Craft::$app->getElements()->canView($entry, $user)) {
            throw new ForbiddenHttpException('You do not have permission to view that entry.');
        }
        if (!Craft::$app->getElements()->canSave($entry, $user)) {
            throw new ForbiddenHttpException('You do not have permission to save that entry.');
        }

        $entryId = $entry->getCanonicalId();
        $siteId = (int)$entry->siteId;
        $summaries = Plugin::getInstance()->conversations->summaries($user->id, $entryId, $siteId);
        $conversation = $this->selectedConversation($user->id, $entryId, $siteId, $summaries);

        return $this->asCpScreen()
            ->title('Ask Mason')
            ->contentTemplate('padstone-mason/_panel', $this->panelVars(
                $conversation,
                $user,
                $summaries,
                $entryId,
                $siteId,
                'entry',
                'Edits apply to an assistant draft, not the live entry, until you publish.',
            ));
    }

    public function actionConversation(): Response
    {
        $this->requireUse();
        $user = Craft::$app->getUser()->getIdentity();
        $view = Plugin::getInstance()->conversations->ownedView(
            (int)$this->request->getRequiredParam('conversationId'),
            $user,
        );
        if (!$view) {
            throw new ForbiddenHttpException('That conversation is not available.');
        }

        return $this->asJson($view);
    }

    public function actionCreate(): Response
    {
        $this->requirePostRequest();
        $this->requireUse();
        $user = Craft::$app->getUser()->getIdentity();
        $started = $this->startFromRequest($user);
        if (empty($started['ok'])) {
            return $this->asJson([
                'ok' => false,
                'reply' => $started['error'] ?? 'Could not start a chat.',
            ]);
        }

        /** @var ConversationRecord $conversation */
        $conversation = $started['conversation'];

        return $this->asJson($this->state($conversation, $user));
    }

    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $this->requireUse();
        $user = Craft::$app->getUser()->getIdentity();
        $conversationId = (int)$this->request->getBodyParam('conversationId');
        if ($conversationId) {
            $conversation = Plugin::getInstance()->conversations->findOwned($conversationId, $user->id);
            if (!$conversation) {
                throw new ForbiddenHttpException('That conversation is not available.');
            }
        } else {
            $started = $this->startFromRequest($user);
            if (empty($started['ok'])) {
                return $this->asJson([
                    'ok' => false,
                    'reply' => $started['error'] ?? 'Could not start a chat.',
                    'saved' => false,
                ]);
            }
            /** @var ConversationRecord $conversation */
            $conversation = $started['conversation'];
        }

        $message = trim((string)$this->request->getBodyParam('message', ''));
        $media = Plugin::getInstance()->conversationAssets;
        $turnAssetIds = [];
        $importErrors = [];

        foreach ($this->uploadedImages() as $file) {
            $uploaded = $media->uploadFromRequest($file, $user);
            if (empty($uploaded['ok'])) {
                $importErrors[] = $uploaded['error'] ?? 'An image could not be uploaded.';
                continue;
            }
            $attached = $media->attach($conversation, [(int)$uploaded['assetId']], $user);
            if (empty($attached['ok'])) {
                $importErrors[] = $attached['error'] ?? 'An uploaded image could not be linked.';
                continue;
            }
            $turnAssetIds[] = (int)$uploaded['assetId'];
        }

        $imported = $media->importUrlsFromText($message, $user, $conversation);
        foreach ($imported['assets'] as $asset) {
            $turnAssetIds[] = (int)$asset['assetId'];
        }
        foreach ($imported['errors'] as $error) {
            $importErrors[] = $error;
        }
        $turnAssetIds = array_values(array_unique($turnAssetIds));

        if ($message === '' && $turnAssetIds === []) {
            return $this->asJson([
                'ok' => false,
                'reply' => $importErrors !== []
                    ? implode(' ', array_unique($importErrors))
                    : 'A message or image is required.',
                'saved' => false,
                'conversationId' => (int)$conversation->id,
            ]);
        }

        $result = Plugin::getInstance()->assistant->reply(
            $conversation,
            $message,
            $user,
            assetIds: $turnAssetIds,
        );
        if ($importErrors !== [] && !empty($result['ok'])) {
            $note = implode(' ', array_unique($importErrors));
            $result['reply'] = trim((string)$result['reply'] . "\n\n" . $note);
        } elseif ($importErrors !== [] && empty($result['ok'])) {
            $result['reply'] = trim((string)($result['reply'] ?? '') . ' ' . implode(' ', array_unique($importErrors)));
        }

        $conversation->refresh();
        $result['conversationId'] = (int)$conversation->id;
        $result['title'] = (string)$conversation->title;
        $result['conversations'] = $this->summariesForRequest($user, $conversation);
        $result['replyHtml'] = Markdown::process((string)($result['reply'] ?? ''), 'gfm');
        $view = Plugin::getInstance()->conversations->ownedView((int)$conversation->id, $user);
        $messages = $view['messages'] ?? [];
        $last = $messages === [] ? null : $messages[array_key_last($messages)];
        if ($last && ($last['content'] ?? '') === (string)($result['reply'] ?? '')) {
            $result['messages'] = $messages;
        } elseif ($view) {
            $result['messages'] = $messages;
        }
        $result['assets'] = $view['assets'] ?? [];
        $result['boundTitle'] = $view['boundTitle'] ?? null;
        $result['boundIsDraft'] = (bool)($view['boundIsDraft'] ?? false);
        if ($view) {
            $result['entryId'] = $view['entryId'] ?? $result['entryId'] ?? null;
            $result['draftId'] = $view['draftId'] ?? $result['draftId'] ?? null;
            if (!empty($view['editUrl'])) {
                $result['editUrl'] = $view['editUrl'];
            }
            if (array_key_exists('previewUrl', $view)) {
                $result['previewUrl'] = $view['previewUrl'];
                $result['previewAvailable'] = (bool)($view['previewAvailable'] ?? false);
            }
        }

        return $this->asJson($result);
    }

    public function actionImportAsset(): Response
    {
        $this->requirePostRequest();
        $this->requireUse();
        $user = Craft::$app->getUser()->getIdentity();
        $conversation = Plugin::getInstance()->conversations->findOwned(
            (int)$this->request->getRequiredBodyParam('conversationId'),
            $user->id,
        );
        if (!$conversation) {
            throw new ForbiddenHttpException('That conversation is not available.');
        }

        $url = trim((string)$this->request->getRequiredBodyParam('url'));
        $media = Plugin::getInstance()->conversationAssets;
        $imported = $media->importFromUrl($url, $user);
        if (empty($imported['ok'])) {
            return $this->asJson([
                'ok' => false,
                'error' => $imported['error'] ?? 'The image could not be imported.',
            ]);
        }

        $attached = $media->attach($conversation, [(int)$imported['assetId']], $user, $url);
        if (empty($attached['ok'])) {
            return $this->asJson([
                'ok' => false,
                'error' => $attached['error'] ?? 'The image could not be linked.',
            ]);
        }

        return $this->asJson([
            'ok' => true,
            'asset' => $media->summariesForIds([(int)$imported['assetId']])[0] ?? null,
            'assets' => $media->summariesForConversation((int)$conversation->id),
        ]);
    }

    public function actionPublish(): Response
    {
        $this->requirePostRequest();
        $this->requireUse();
        $user = Craft::$app->getUser()->getIdentity();
        $conversation = Plugin::getInstance()->conversations->findOwned(
            (int)$this->request->getRequiredBodyParam('conversationId'),
            $user->id,
        );
        if (!$conversation) {
            throw new ForbiddenHttpException('That conversation is not available.');
        }

        $result = Plugin::getInstance()->assistant->publish($conversation, $user);
        if (empty($result['ok'])) {
            $reply = 'The change was not saved. ' . ($result['error'] ?? 'Publish failed.');
        } else {
            $reply = 'Published.';
        }
        Plugin::getInstance()->conversations->addMessage($conversation, 'assistant', $reply);
        $result['reply'] = $reply;
        $view = Plugin::getInstance()->conversations->ownedView((int)$conversation->id, $user);
        if ($view) {
            $result['boundTitle'] = $view['boundTitle'];
            $result['boundIsDraft'] = $view['boundIsDraft'];
            $result['entryId'] = $view['entryId'];
            $result['draftId'] = $view['draftId'];
            $result['editUrl'] = $view['editUrl'];
            $result['previewUrl'] = $view['previewUrl'];
            $result['previewAvailable'] = $view['previewAvailable'];
        }

        return $this->asJson($result);
    }

    public function actionRenameSubject(): Response
    {
        $this->requirePostRequest();
        $this->requireUse();
        $user = Craft::$app->getUser()->getIdentity();
        $conversation = Plugin::getInstance()->conversations->findOwned(
            (int)$this->request->getRequiredBodyParam('conversationId'),
            $user->id,
        );
        if (!$conversation) {
            throw new ForbiddenHttpException('That conversation is not available.');
        }

        $result = Plugin::getInstance()->conversations->saveCustomTitle(
            $conversation,
            (string)$this->request->getBodyParam('subject', ''),
        );
        if (empty($result['ok'])) {
            return $this->asJson([
                'ok' => false,
                'error' => $result['error'] ?? 'The subject could not be saved.',
                'title' => (string)$conversation->title,
            ]);
        }

        $conversation->refresh();

        return $this->asJson([
            'ok' => true,
            'conversationId' => (int)$conversation->id,
            'title' => (string)$result['title'],
            'conversations' => $this->summariesForRequest($user, $conversation),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireUse();
        $user = Craft::$app->getUser()->getIdentity();
        $id = (int)$this->request->getRequiredBodyParam('conversationId');
        $existing = Plugin::getInstance()->conversations->findOwned($id, $user->id);
        if (!$existing) {
            throw new ForbiddenHttpException('That conversation is not available.');
        }

        $draftId = $existing->draftId ? (int)$existing->draftId : null;
        if (!Plugin::getInstance()->conversations->deleteOwned($id, $user->id)) {
            throw new ForbiddenHttpException('That conversation is not available.');
        }

        $summaries = $this->summariesForRequest($user, null);
        $next = $summaries[0] ?? null;
        $payload = [
            'ok' => true,
            'deletedId' => $id,
            'draftId' => $draftId,
            'conversations' => $summaries,
            'conversationId' => null,
            'messages' => [],
            'assets' => [],
            'boundTitle' => null,
            'boundIsDraft' => false,
            'previewUrl' => null,
            'previewAvailable' => false,
            'editUrl' => null,
        ];
        if ($next) {
            $view = Plugin::getInstance()->conversations->ownedView((int)$next['id'], $user);
            if ($view) {
                $payload = $view + $payload;
                $payload['conversations'] = $summaries;
            }
        }

        return $this->asJson($payload);
    }

    /**
     * @return UploadedFile[]
     */
    private function uploadedImages(): array
    {
        $files = UploadedFile::getInstancesByName('images');
        if ($files !== []) {
            return $files;
        }

        $single = UploadedFile::getInstanceByName('image');
        if ($single) {
            return [$single];
        }

        return [];
    }

    private function requireUse(): void
    {
        $this->requireCpRequest();
        $this->requireLogin();
        if (!Plugin::getInstance()->getSettings()->enabled) {
            throw new ForbiddenHttpException('The assistant is disabled.');
        }
        $this->requirePermission(Plugin::PERMISSION);
    }

    /**
     * Prefer an explicit conversationId (e.g. slideout reopen) when it belongs to this user
     * and matches the entry/site scope. Otherwise use the newest summary.
     *
     * @param array<int, array{id: int, title: string, entryId: ?int, draftId: ?int}> $summaries
     */
    private function selectedConversation(int $userId, ?int $entryId, ?int $siteId, array $summaries): ?ConversationRecord
    {
        $requested = (int)$this->request->getParam('conversationId');
        if ($requested) {
            $owned = Plugin::getInstance()->conversations->findOwned($requested, $userId);
            if ($owned && $this->conversationMatchesScope($owned, $entryId, $siteId)) {
                return $owned;
            }
        }

        $first = $summaries[0] ?? null;
        if (!$first) {
            return null;
        }

        return Plugin::getInstance()->conversations->findOwned((int)$first['id'], $userId);
    }

    private function conversationMatchesScope(ConversationRecord $conversation, ?int $entryId, ?int $siteId): bool
    {
        if ($entryId !== null && (int)$conversation->entryId !== $entryId) {
            return false;
        }
        if ($entryId !== null && $siteId && (int)$conversation->siteId !== $siteId) {
            return false;
        }

        return true;
    }

    /**
     * @return array{ok: bool, conversation?: ConversationRecord, draft?: ?Entry, error?: string}
     */
    private function startFromRequest(User $user): array
    {
        if ((string)$this->request->getBodyParam('scope') === 'entry') {
            $entry = $this->entryFromRequest();
            if (!Craft::$app->getElements()->canSave($entry, $user)) {
                return ['ok' => false, 'error' => 'You do not have permission to save that entry.'];
            }

            return Plugin::getInstance()->conversations->start($user->id, $entry, $user, (int)$entry->siteId);
        }

        $site = Craft::$app->getSites()->getCurrentSite();

        return Plugin::getInstance()->conversations->start($user->id, null, $user, $site->id);
    }

    /**
     * @param array<int, array{id: int, title: string, entryId: ?int, draftId: ?int}> $summaries
     * @return array<string, mixed>
     */
    private function panelVars(
        ?ConversationRecord $conversation,
        User $user,
        array $summaries,
        ?int $entryId,
        ?int $siteId,
        string $scope,
        string $draftNote,
    ): array {
        $preview = $conversation
            ? Plugin::getInstance()->conversations->ownedView((int)$conversation->id, $user)
            : null;

        $boundDraftId = $preview['draftId'] ?? ($conversation?->draftId ? (int)$conversation->draftId : null);

        return [
            'conversation' => $conversation,
            'conversations' => $summaries,
            'messages' => $preview['messages'] ?? ($conversation ? $this->legacyMessages($conversation) : []),
            'assets' => $preview['assets'] ?? [],
            'previewUrl' => $preview['previewUrl'] ?? null,
            'previewAvailable' => $preview['previewAvailable'] ?? false,
            'editUrl' => $preview['editUrl'] ?? null,
            'boundTitle' => $preview['boundTitle'] ?? null,
            'boundIsDraft' => (bool)($preview['boundIsDraft'] ?? false),
            'boundDraftId' => $boundDraftId ? (int)$boundDraftId : null,
            'draftNote' => $draftNote,
            'entryId' => $entryId,
            'siteId' => $siteId,
            'scope' => $scope,
        ];
    }

    /**
     * @return array<int, array{role: string, content: string, assets: array}>
     */
    private function legacyMessages(ConversationRecord $conversation): array
    {
        $messages = [];
        foreach (Plugin::getInstance()->conversations->messagesForUser($conversation) as $message) {
            $messages[] = [
                'role' => $message->role,
                'content' => (string)$message->content,
                'assets' => Plugin::getInstance()->conversations->assetsForMessage($message),
                'tools' => Plugin::getInstance()->conversations->toolsForMessage($message),
            ];
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(ConversationRecord $conversation, User $user): array
    {
        $view = Plugin::getInstance()->conversations->ownedView((int)$conversation->id, $user) ?? [];
        $view['conversations'] = $this->summariesForRequest($user, $conversation);

        return $view;
    }

    /**
     * @return array<int, array{id: int, title: string, entryId: ?int, draftId: ?int}>
     */
    private function summariesForRequest(User $user, ?ConversationRecord $conversation): array
    {
        if ((string)$this->request->getBodyParam('scope') === 'entry') {
            $entryId = (int)$this->request->getBodyParam('entryId');
            $siteId = (int)$this->request->getBodyParam('siteId');
            if (!$entryId && $conversation?->entryId) {
                $entryId = (int)$conversation->entryId;
                $siteId = (int)$conversation->siteId;
            }

            return Plugin::getInstance()->conversations->summaries($user->id, $entryId ?: null, $siteId ?: null);
        }

        return Plugin::getInstance()->conversations->summaries($user->id, null, null);
    }

    private function entryFromRequest(): Entry
    {
        $entryId = (int)$this->request->getRequiredParam('entryId');
        $siteId = (int)$this->request->getRequiredParam('siteId');
        $entry = Entry::find()->id($entryId)->siteId($siteId)->drafts(null)->status(null)->one();
        if (!$entry instanceof Entry) {
            throw new BadRequestHttpException('Entry not found.');
        }

        return $entry;
    }
}
