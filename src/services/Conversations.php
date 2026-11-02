<?php

namespace imarc\padstonemason\services;

use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\Markdown;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;
use imarc\padstonemason\records\MessageRecord;

class Conversations extends Component
{
    public const NEW_CHAT_TITLE = 'New chat';

    public const TITLE_LIMIT = 80;

    public function findForUser(int $userId, ?int $entryId, ?int $siteId): ?ConversationRecord
    {
        $query = ConversationRecord::find()
            ->where(['userId' => $userId])
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC]);

        if ($entryId) {
            $query->andWhere(['entryId' => $entryId]);
        } else {
            $query->andWhere(['entryId' => null]);
        }

        if ($siteId) {
            $query->andWhere(['siteId' => $siteId]);
        }

        $record = $query->one();

        return $record instanceof ConversationRecord ? $record : null;
    }

    /**
     * @return ConversationRecord[]
     */
    public function listForUser(int $userId, ?int $entryId, ?int $siteId): array
    {
        $query = ConversationRecord::find()
            ->where(['userId' => $userId])
            ->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC]);

        if ($entryId) {
            $query->andWhere(['entryId' => $entryId]);
            if ($siteId) {
                $query->andWhere(['siteId' => $siteId]);
            }
        }

        $records = [];
        foreach ($query->all() as $record) {
            if (!$record instanceof ConversationRecord) {
                continue;
            }
            $title = $this->entryTitle($record);
            if ($title !== null && $title !== $record->title) {
                ConversationRecord::updateAll(['title' => $title], ['id' => $record->id]);
                $record->title = $title;
            }
            $records[] = $record;
        }

        return $records;
    }

    /**
     * @return array<int, array{id: int, title: string, entryId: ?int, draftId: ?int}>
     */
    public function summaries(int $userId, ?int $entryId, ?int $siteId): array
    {
        $items = [];
        foreach ($this->listForUser($userId, $entryId, $siteId) as $record) {
            $items[] = [
                'id' => (int)$record->id,
                'title' => (string)$record->title,
                'entryId' => $record->entryId ? (int)$record->entryId : null,
                'draftId' => $record->draftId ? (int)$record->draftId : null,
            ];
        }

        return $items;
    }

    public function findOwned(int $id, int $userId): ?ConversationRecord
    {
        $record = ConversationRecord::findOne(['id' => $id, 'userId' => $userId]);

        return $record instanceof ConversationRecord ? $record : null;
    }

    /**
     * @return array{ok: bool, conversation?: ConversationRecord, draft?: ?Entry, error?: string}
     */
    public function start(int $userId, ?Entry $entry, ?User $user, ?int $siteId): array
    {
        $draft = null;
        if ($entry) {
            if (!$user) {
                return ['ok' => false, 'error' => 'You need to be signed in.'];
            }
            $forked = Plugin::getInstance()->contentDesigner->forkAssistantDraft($entry, $user);
            if (empty($forked['ok'])) {
                return $forked;
            }
            /** @var Entry $draft */
            $draft = $forked['entry'];
        }

        $record = new ConversationRecord();
        $record->userId = $userId;
        $record->siteId = $draft ? $draft->siteId : $siteId;
        $record->title = self::NEW_CHAT_TITLE;
        if ($draft) {
            $record->entryId = $draft->getCanonicalId();
            $record->draftId = $draft->draftId;
            $record->title = $this->entryTitle($record) ?? self::NEW_CHAT_TITLE;
        }
        $record->save(false);

        return ['ok' => true, 'conversation' => $record, 'draft' => $draft];
    }

    public function open(int $userId, ?int $entryId, ?int $draftId, ?int $siteId): ConversationRecord
    {
        $existing = $this->findForUser($userId, $entryId, $siteId);
        if ($existing) {
            if ($draftId && !$existing->draftId) {
                $existing->draftId = $draftId;
                $existing->save(false);
            }

            return $existing;
        }

        $record = new ConversationRecord();
        $record->userId = $userId;
        $record->entryId = $entryId;
        $record->draftId = $draftId;
        $record->siteId = $siteId;
        $record->title = self::NEW_CHAT_TITLE;
        $record->save(false);
        $title = $this->entryTitle($record);
        if ($title !== null) {
            $record->title = $title;
            $record->save(false);
        }

        return $record;
    }

    public function bind(ConversationRecord $conversation, int $entryId, ?int $draftId, ?int $siteId): void
    {
        $conversation->entryId = $entryId;
        $conversation->draftId = $draftId;
        $conversation->siteId = $siteId;
        $title = $this->entryTitle($conversation);
        if ($title !== null) {
            $conversation->title = $title;
        }
        $conversation->save(false);
    }

    public function applyDerivedTitle(ConversationRecord $conversation): void
    {
        $title = $this->derivedTitle($conversation);
        ConversationRecord::updateAll(['title' => $title], ['id' => $conversation->id]);
        $conversation->title = $title;
    }

    public function derivedTitle(ConversationRecord $conversation): string
    {
        $entryTitle = $this->entryTitle($conversation);
        if ($entryTitle !== null) {
            return $entryTitle;
        }

        $first = MessageRecord::find()
            ->where(['conversationId' => $conversation->id, 'role' => 'user'])
            ->orderBy(['id' => SORT_ASC])
            ->one();
        if ($first instanceof MessageRecord) {
            $text = trim((string)$first->content);
            if ($text !== '') {
                return $this->trimTitle($text);
            }
        }

        return self::NEW_CHAT_TITLE;
    }

    public function deleteOwned(int $id, int $userId): bool
    {
        $conversation = $this->findOwned($id, $userId);
        if (!$conversation) {
            return false;
        }

        return (bool)$conversation->delete();
    }

    /**
     * @return array{ok: true, conversationId: int, title: string, entryId: ?int, draftId: ?int, boundTitle: ?string, boundIsDraft: bool, messages: array<int, array{role: string, content: string, html: string, assets: array<int, array{id: int, title: string, filename: string, thumbUrl: ?string, url: ?string}>}>, assets: array<int, array{id: int, title: string, filename: string, thumbUrl: ?string, url: ?string}>, previewUrl: ?string, previewAvailable: bool, editUrl: ?string}|null
     */
    public function ownedView(int $id, User $user): ?array
    {
        $conversation = $this->findOwned($id, (int)$user->id);
        if (!$conversation) {
            return null;
        }

        $preview = $this->previewFor($conversation, $user);
        $bound = $this->boundMeta($conversation, $user);
        $media = Plugin::getInstance()->conversationAssets;
        $messages = [];
        foreach ($this->messagesForUser($conversation) as $message) {
            $content = (string)$message->content;
            $messages[] = [
                'role' => $message->role,
                'content' => $content,
                'html' => Markdown::process($content, 'gfm'),
                'assets' => $this->assetsForMessage($message),
            ];
        }

        return [
            'ok' => true,
            'conversationId' => (int)$conversation->id,
            'title' => (string)$conversation->title,
            'entryId' => $conversation->entryId ? (int)$conversation->entryId : null,
            'draftId' => $conversation->draftId ? (int)$conversation->draftId : null,
            'boundTitle' => $bound['boundTitle'],
            'boundIsDraft' => $bound['boundIsDraft'],
            'messages' => $messages,
            'assets' => $media->summariesForConversation((int)$conversation->id),
            'previewUrl' => $preview['previewUrl'],
            'previewAvailable' => $preview['previewAvailable'],
            'editUrl' => $preview['editUrl'],
        ];
    }

    /**
     * @param array<int, array{id: string, name: string, arguments: array<string, mixed>}>|null $toolCalls
     * @param array<string, mixed>|null $metadata
     */
    public function addMessage(
        ConversationRecord $conversation,
        string $role,
        ?string $content,
        ?string $toolCallId = null,
        ?string $toolName = null,
        ?array $toolCalls = null,
        ?array $metadata = null,
    ): MessageRecord {
        $message = new MessageRecord();
        $message->conversationId = $conversation->id;
        $message->role = $role;
        $message->content = $content;
        $message->toolCallId = $toolCallId;
        $message->toolName = $toolName;
        $message->toolCalls = $toolCalls ? Json::encode($toolCalls) : null;
        $message->metadata = $metadata ? Json::encode($metadata) : null;
        $message->save(false);

        if ($role === 'user' && !$conversation->entryId && $this->isPlaceholderTitle((string)$conversation->title)) {
            $conversation->title = $this->trimTitle((string)$content);
        }
        $conversation->save(false);

        return $message;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messagesForModel(ConversationRecord $conversation): array
    {
        $records = MessageRecord::find()
            ->where(['conversationId' => $conversation->id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $messages = [];
        foreach ($records as $record) {
            if (!$record instanceof MessageRecord) {
                continue;
            }
            $content = $record->content;
            if ($record->role === 'user') {
                $content = $this->contentForModel($record);
            }
            $messages[] = [
                'role' => $record->role,
                'content' => $content,
                'toolCallId' => $record->toolCallId,
                'toolName' => $record->toolName,
                'toolCalls' => $record->toolCalls ? Json::decode($record->toolCalls) : null,
            ];
        }

        return $messages;
    }

    /**
     * @return array<int, array{id: int, title: string, filename: string, thumbUrl: ?string, url: ?string}>
     */
    public function assetsForMessage(MessageRecord $message): array
    {
        $metadata = $message->metadata ? Json::decode($message->metadata) : null;
        if (!is_array($metadata) || empty($metadata['assetIds']) || !is_array($metadata['assetIds'])) {
            return [];
        }

        return Plugin::getInstance()->conversationAssets->summariesForIds(
            array_map('intval', $metadata['assetIds']),
        );
    }

    public function contentForModel(MessageRecord $message): string
    {
        $content = trim((string)$message->content);
        $assets = $this->assetsForMessage($message);
        if ($assets === []) {
            return $content;
        }

        $parts = [];
        foreach ($assets as $asset) {
            $parts[] = '#' . $asset['id'] . ' "' . $asset['filename'] . '"';
        }
        $note = 'Attached assets for this message (use these ids in Assets fields): ' . implode(', ', $parts) . '.';

        return $content === '' ? $note : $content . "\n\n" . $note;
    }

    /**
     * @return MessageRecord[]
     */
    public function messagesForUser(ConversationRecord $conversation): array
    {
        $visible = [];
        foreach (MessageRecord::find()
            ->where(['conversationId' => $conversation->id, 'role' => ['user', 'assistant']])
            ->orderBy(['id' => SORT_ASC])
            ->all() as $record) {
            if (!$record instanceof MessageRecord || trim((string)$record->content) === '') {
                continue;
            }
            $visible[] = $record;
        }

        return $visible;
    }

    public function trimTitle(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return self::NEW_CHAT_TITLE;
        }
        if (mb_strlen($value) <= self::TITLE_LIMIT) {
            return $value;
        }

        return mb_substr($value, 0, self::TITLE_LIMIT);
    }

    private function isPlaceholderTitle(string $title): bool
    {
        return $title === '' || $title === self::NEW_CHAT_TITLE;
    }

    private function entryTitle(ConversationRecord $conversation): ?string
    {
        if (!$conversation->entryId) {
            return null;
        }

        $query = Entry::find()->id((int)$conversation->entryId)->status(null)->drafts(null);
        if ($conversation->siteId) {
            $query->siteId((int)$conversation->siteId);
        }
        $entry = $query->one();
        if (!$entry instanceof Entry) {
            return null;
        }

        $title = trim((string)$entry->title);

        return $title === '' ? null : $this->trimTitle($title);
    }

    /**
     * @return array{boundTitle: ?string, boundIsDraft: bool}
     */
    private function boundMeta(ConversationRecord $conversation, User $user): array
    {
        if (!$conversation->entryId && !$conversation->draftId) {
            return ['boundTitle' => null, 'boundIsDraft' => false];
        }

        $entry = Plugin::getInstance()->contentDesigner->entryFromId(
            $conversation->entryId ? (int)$conversation->entryId : null,
            $conversation->draftId ? (int)$conversation->draftId : null,
            $conversation->siteId ? (int)$conversation->siteId : null,
            $user,
        );
        if (!$entry instanceof Entry) {
            return ['boundTitle' => null, 'boundIsDraft' => false];
        }

        $title = trim((string)$entry->title);

        return [
            'boundTitle' => $title !== '' ? $title : 'Untitled',
            'boundIsDraft' => $entry->getIsDraft() && !$entry->isProvisionalDraft,
        ];
    }

    /**
     * @return array{previewUrl: ?string, previewAvailable: bool, editUrl: ?string}
     */
    private function previewFor(ConversationRecord $conversation, User $user): array
    {
        $empty = [
            'previewUrl' => null,
            'previewAvailable' => false,
            'editUrl' => null,
        ];
        if (!$conversation->entryId && !$conversation->draftId) {
            return $empty;
        }

        $draft = Plugin::getInstance()->contentDesigner->entryFromId(
            $conversation->entryId ? (int)$conversation->entryId : null,
            $conversation->draftId ? (int)$conversation->draftId : null,
            $conversation->siteId ? (int)$conversation->siteId : null,
            $user,
        );

        return $draft ? Plugin::getInstance()->contentDesigner->previewFor($draft) : $empty;
    }
}
