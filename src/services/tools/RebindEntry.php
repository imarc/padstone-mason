<?php

namespace imarc\padstonemason\services\tools;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class RebindEntry extends BaseTool
{
    public function name(): string
    {
        return 'rebind_entry';
    }

    public function description(): string
    {
        return 'Bind this conversation to an existing entry the current user can save. Pass the entry id from find_elements. If several entries match, ask the admin which id to use and do not call this yet. A later edit uses a Mason draft of that entry. The previous draft is left in place.';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'entryId' => ['type' => 'integer'],
        ], ['entryId']);
    }

    public function execute(array $arguments, ConversationRecord $conversation, User $user): array
    {
        $entryId = (int)($arguments['entryId'] ?? 0);
        if ($entryId < 1) {
            return ['ok' => false, 'saved' => false, 'error' => 'An entry id is required.'];
        }

        $entry = $this->entryForRebind($entryId, $conversation->siteId ? (int)$conversation->siteId : null);
        if (!$entry || !Craft::$app->getElements()->canView($entry, $user)) {
            return ['ok' => false, 'saved' => false, 'error' => 'That entry was not found.'];
        }

        $canonical = $entry->getCanonical(true);
        if (!Craft::$app->getElements()->canSave($canonical, $user)) {
            return ['ok' => false, 'saved' => false, 'error' => 'You do not have permission to save that entry.'];
        }

        if ((int)$conversation->entryId === (int)$canonical->id) {
            $draft = $this->drafts->editableDraft($conversation, $user);
            if (!$draft instanceof Entry) {
                return $draft;
            }

            return $this->payload($draft);
        }

        if ($canonical->getIsDraft()) {
            Plugin::getInstance()->conversations->bind(
                $conversation,
                (int)$canonical->id,
                $canonical->draftId ? (int)$canonical->draftId : null,
                (int)$canonical->siteId,
            );

            return $this->payload($canonical);
        }

        $forked = Plugin::getInstance()->contentDesigner->forkAssistantDraft($canonical, $user);
        if (empty($forked['ok'])) {
            return $forked;
        }

        /** @var Entry $draft */
        $draft = $forked['entry'];
        Plugin::getInstance()->conversations->bind(
            $conversation,
            (int)$draft->getCanonicalId(),
            $draft->draftId ? (int)$draft->draftId : null,
            (int)$draft->siteId,
        );

        return $this->payload($draft);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Entry $draft): array
    {
        return array_merge([
            'ok' => true,
            'saved' => true,
            'rebound' => true,
            'entryId' => (int)$draft->getCanonicalId(),
            'draftId' => $draft->draftId ? (int)$draft->draftId : null,
            'title' => (string)$draft->title,
        ], Plugin::getInstance()->contentDesigner->previewFor($draft));
    }

    private function entryForRebind(int $entryId, ?int $siteId): ?Entry
    {
        foreach ([false, null] as $drafts) {
            $query = Entry::find()->id($entryId)->status(null)->drafts($drafts);
            if ($siteId) {
                $query->siteId($siteId);
            }
            $entry = $query->one();
            if ($entry instanceof Entry) {
                return $entry;
            }
            if (!$siteId) {
                continue;
            }
            $entry = Entry::find()->id($entryId)->status(null)->drafts($drafts)->one();
            if ($entry instanceof Entry) {
                return $entry;
            }
        }

        return null;
    }
}
