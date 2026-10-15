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
        if (!Plugin::getInstance()->hasContentDesigner($entry)) {
            throw new BadRequestHttpException('This entry has no Content Designer field.');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (!Craft::$app->getElements()->canView($entry, $user)) {
            throw new ForbiddenHttpException('You do not have permission to view that entry.');
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

        $result = Plugin::getInstance()->assistant->reply(
            $conversation,
            (string)$this->request->getRequiredBodyParam('message'),
            $user,
        );
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
        }

        return $this->asJson($result);
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

        return $this->asJson($result);
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
     * @param array<int, array{id: int, title: string, entryId: ?int, draftId: ?int}> $summaries
     */
    private function selectedConversation(int $userId, ?int $entryId, ?int $siteId, array $summaries): ?ConversationRecord
    {
        $requested = (int)$this->request->getParam('conversationId');
        if ($requested) {
            $owned = Plugin::getInstance()->conversations->findOwned($requested, $userId);
            if ($owned && ($entryId === null || (int)$owned->entryId === $entryId)) {
                if ($entryId === null || !$siteId || (int)$owned->siteId === $siteId) {
                    return $owned;
                }
            }
        }

        $first = $summaries[0] ?? null;
        if (!$first) {
            return null;
        }

        return Plugin::getInstance()->conversations->findOwned((int)$first['id'], $userId);
    }

    /**
     * @return array{ok: bool, conversation?: ConversationRecord, draft?: ?Entry, error?: string}
     */
    private function startFromRequest(User $user): array
    {
        if ((string)$this->request->getBodyParam('scope') === 'entry') {
            $entry = $this->entryFromRequest();
            if (!Plugin::getInstance()->hasContentDesigner($entry)) {
                return ['ok' => false, 'error' => 'This entry has no Content Designer field.'];
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

        return [
            'conversation' => $conversation,
            'conversations' => $summaries,
            'messages' => $conversation ? Plugin::getInstance()->conversations->messagesForUser($conversation) : [],
            'previewUrl' => $preview['previewUrl'] ?? null,
            'previewAvailable' => $preview['previewAvailable'] ?? false,
            'editUrl' => $preview['editUrl'] ?? null,
            'draftNote' => $draftNote,
            'entryId' => $entryId,
            'siteId' => $siteId,
            'scope' => $scope,
        ];
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
