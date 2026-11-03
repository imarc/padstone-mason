<?php

namespace imarc\padstonemason\services\tools;

use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\elements\User;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\records\ConversationRecord;

class ConversationDrafts
{
    public function editableDraft(ConversationRecord $conversation, User $user): Entry|array
    {
        $entry = $this->boundDraft($conversation, $user);
        if (!$entry) {
            return ['ok' => false, 'saved' => false, 'error' => 'No entry is bound to this conversation.'];
        }
        if ($entry->getIsDraft() && !$entry->isProvisionalDraft) {
            return $entry;
        }

        $forked = Plugin::getInstance()->contentDesigner->forkAssistantDraft($entry, $user);
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

        return $draft;
    }

    public function boundDraft(ConversationRecord $conversation, User $user): ?Entry
    {
        if (!$conversation->entryId && !$conversation->draftId) {
            return null;
        }

        return Plugin::getInstance()->contentDesigner->entryFromId(
            $conversation->entryId ? (int)$conversation->entryId : null,
            $conversation->draftId ? (int)$conversation->draftId : null,
            $conversation->siteId ? (int)$conversation->siteId : null,
            $user,
        );
    }

    public function boundEntryType(ConversationRecord $conversation, User $user): string
    {
        return $this->boundDraft($conversation, $user)?->getType()->handle ?? '';
    }

    public function ownerForField(ConversationRecord $conversation, User $user, mixed $parentId): Entry|array
    {
        if ($parentId) {
            $block = $this->ownedBlock($conversation, $user, (int)$parentId);
            if (!$block instanceof Entry) {
                if (is_array($block)) {
                    return $block;
                }

                return ['ok' => false, 'saved' => false, 'error' => 'Nested blocks under that parent are not supported for this field type.'];
            }

            return $block;
        }

        return $this->editableDraft($conversation, $user);
    }

    public function ownedBlock(ConversationRecord $conversation, User $user, int $blockId): ElementInterface|array
    {
        $draft = $this->editableDraft($conversation, $user);
        if (!$draft instanceof Entry) {
            return $draft;
        }

        $block = Plugin::getInstance()->contentDesigner->findBlockOnEntry($draft, $blockId);
        if (!$block) {
            return ['ok' => false, 'saved' => false, 'error' => 'That block is not on this draft.'];
        }

        return $block;
    }
}
