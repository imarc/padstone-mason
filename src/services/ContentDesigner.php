<?php

namespace imarc\padstonemason\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\errors\InvalidFieldException;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use imarc\padstonemason\Plugin;
use Throwable;

class ContentDesigner extends Component
{
    public const UNRESOLVED_MESSAGE = 'Ask the admin which section and entry type to use, and include these sections and entry types in the question. Do not say the request is impossible or a project config change.';

    public function describeSchema(?string $entryTypeHandle, ?User $user): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }

        $entryType = $this->entryTypeByName($entryTypeHandle);
        if (!$entryType) {
            return $this->unresolved();
        }

        $field = $this->contentDesignerField($entryType);

        return [
            'ok' => true,
            'entryType' => $entryType->handle,
            'field' => $field?->handle,
            'blockTypes' => $field ? $this->describeMatrix($field) : [],
            'layoutFields' => $this->describeFields($entryType),
        ];
    }

    public function readLayout(Entry $entry, ?User $user): array
    {
        if (!$user || !Craft::$app->getElements()->canView($entry, $user)) {
            return $this->fail('You do not have permission to view that entry.');
        }

        [$fields, $matrices] = $this->readEntryLayout($entry);

        return [
            'ok' => true,
            'entryId' => $entry->getCanonicalId(),
            'draftId' => $entry->draftId,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'fields' => $fields,
            'matrices' => $matrices,
            'blocks' => $matrices[FieldSupport::CONTENT_DESIGNER]['blocks'] ?? [],
        ];
    }

    public function createPage(array $input, ?User $user): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }

        $section = $this->matchingAllowedSection((string)($input['section'] ?? ''));
        $matches = $section ? $this->matchingEntryTypes($section, (string)($input['entryType'] ?? '')) : [];
        if (!$section || count($matches) !== 1) {
            return $this->unresolved();
        }

        $entryType = $matches[0];

        $siteId = (int)($input['siteId'] ?? Craft::$app->getSites()->getPrimarySite()->id);
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->siteId = $siteId;
        $entry->title = (string)($input['title'] ?? '');
        $entry->slug = (string)($input['slug'] ?? '');
        if ($entry->slug === '') {
            $entry->slug = ElementHelper::generateSlug($entry->title);
        }
        $entry->setAuthorIds([$user->id]);

        if (!$entry->title) {
            return $this->fail('A title is required.');
        }

        if (!Craft::$app->getElements()->canSave($entry, $user)) {
            return $this->fail('You do not have permission to create entries in that section.');
        }

        if (!empty($input['parentId'])) {
            $parent = Entry::find()->id((int)$input['parentId'])->status(null)->siteId($siteId)->one();
            if (!$parent || $parent->sectionId !== $section->id || !Craft::$app->getElements()->canView($parent, $user)) {
                return $this->fail('That parent page was not found.');
            }
            $entry->setParentId($parent->id);
        }

        return $this->mutate(function() use ($entry, $user) {
            $entry->setScenario(Element::SCENARIO_ESSENTIALS);
            if (!Craft::$app->getDrafts()->saveElementAsDraft($entry, $user->id, 'Mason', 'Created by Padstone Mason')) {
                return $this->fail($this->errorText($entry), $entry->getErrors());
            }

            $draft = $this->reload($entry);

            return array_merge([
                'ok' => true,
                'saved' => true,
                'entryId' => $draft->getCanonicalId(),
                'draftId' => $draft->draftId,
                'unpublished' => $draft->getIsUnpublishedDraft(),
            ], $this->previewPayload($draft));
        });
    }

    public function ensureDraft(Entry $source, ?User $user, ?int $existingDraftId = null): array
    {
        if (!$user || !Craft::$app->getElements()->canView($source, $user)) {
            return $this->fail('You do not have permission to view that entry.');
        }

        if ($existingDraftId) {
            $existing = Entry::find()->draftId($existingDraftId)->status(null)->siteId($source->siteId)->one();
            if ($existing instanceof Entry) {
                if (!Craft::$app->getElements()->canSave($existing, $user)) {
                    return $this->fail('You do not have permission to save that draft.');
                }

                return ['ok' => true, 'entry' => $existing];
            }
        }

        if ($source->getIsUnpublishedDraft() || ($source->getIsDraft() && !$source->isProvisionalDraft)) {
            if (!Craft::$app->getElements()->canSave($source, $user)) {
                return $this->fail('You do not have permission to save that draft.');
            }

            return ['ok' => true, 'entry' => $source];
        }

        $canonical = $source->getCanonical(true);
        if (!Craft::$app->getElements()->canView($canonical, $user)) {
            return $this->fail('You do not have permission to view that entry.');
        }

        if (!Craft::$app->getElements()->canSave($canonical, $user) && !$canonical->canCreateDrafts($user)) {
            return $this->fail('You do not have permission to create a draft of that entry.');
        }

        try {
            $draft = Craft::$app->getDrafts()->createDraft(
                $canonical,
                $user->id,
                'Mason',
                'Created by Padstone Mason',
            );
        } catch (Throwable $e) {
            return $this->fail('Could not create a draft.');
        }

        return ['ok' => true, 'entry' => $draft];
    }

    /**
     * @return array{ok: bool, entry?: Entry, error?: string}
     */
    public function forkAssistantDraft(Entry $source, User $user): array
    {
        if (!Craft::$app->getElements()->canView($source, $user)) {
            return $this->fail('You do not have permission to view that entry.');
        }

        $canonical = $source->getCanonical(true);
        if (!Craft::$app->getElements()->canView($canonical, $user)) {
            return $this->fail('You do not have permission to view that entry.');
        }

        if ($canonical->getIsDraft() || $canonical->getIsRevision()) {
            return $this->fail('Publish the entry before starting another assistant chat.');
        }

        if (!Craft::$app->getElements()->canSave($canonical, $user) && !$canonical->canCreateDrafts($user)) {
            return $this->fail('You do not have permission to create a draft of that entry.');
        }

        try {
            $draft = Craft::$app->getDrafts()->createDraft(
                $canonical,
                $user->id,
                $this->assistantDraftName($canonical),
                'Created by Padstone Mason',
            );
        } catch (Throwable $e) {
            return $this->fail('Could not create a draft.');
        }

        return ['ok' => true, 'entry' => $draft];
    }

    private function assistantDraftName(Entry $canonical): string
    {
        $names = [];
        $drafts = Entry::find()
            ->draftOf($canonical->id)
            ->status(null)
            ->siteId($canonical->siteId)
            ->all();
        foreach ($drafts as $draft) {
            $names[] = (string)$draft->draftName;
        }

        if (!in_array('Mason', $names, true)) {
            return 'Mason';
        }

        $number = 2;
        while (in_array('Mason ' . $number, $names, true)) {
            $number++;
        }

        return 'Mason ' . $number;
    }

    public function addBlock(Entry $owner, string $fieldHandle, string $typeHandle, ?int $position, array $values, array $nested, ?User $user): array
    {
        $guard = $this->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $field = $this->matrixOn($owner, $fieldHandle);
        if (!$field) {
            $handles = $this->matrixHandles($owner);

            return $this->fail('That Matrix field is not on the owner. Matrix fields on this layout: ' . ($handles === [] ? 'none' : implode(', ', $handles)) . '.');
        }

        $type = $this->typeOnField($field, $typeHandle);
        if (!$type) {
            return $this->fail('That block type is not available.');
        }

        $prepared = $this->prepareFieldValues($type, $values, $user);
        if (!$prepared['ok']) {
            return $prepared;
        }

        $nestedResult = $this->buildNestedPayload($type, $nested, $user);
        if (!$nestedResult['ok']) {
            return $nestedResult;
        }

        $count = count($this->blockIds($owner, $field));
        if ($field->maxEntries && $count >= (int)$field->maxEntries) {
            return $this->fail($field->handle . ' allows at most ' . $field->maxEntries . ' entries.');
        }

        $fields = $prepared['fields'] + $nestedResult['fields'];

        return $this->mutate(function() use ($owner, $field, $typeHandle, $position, $fields, $prepared) {
            [$entries, $sort] = $this->payload($owner, $field);
            $key = 'new1';
            $entries[$key] = [
                'type' => $typeHandle,
                'fields' => $fields,
            ];
            if ($position === null || $position >= count($sort)) {
                $sort[] = $key;
            } else {
                array_splice($sort, max(0, $position), 0, [$key]);
            }

            $before = $this->blockIds($owner, $field);
            $saved = $this->saveOwner($owner, $field, $entries, $sort);
            if (!$saved['ok']) {
                return $saved;
            }

            $owner = $saved['entry'];
            $after = $this->blocks($owner, $field);
            $created = null;
            foreach ($after as $block) {
                if (!in_array($block->id, $before, true)) {
                    $created = $block;
                    break;
                }
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blockId' => $created?->id,
                'type' => $typeHandle,
                'skipped' => $prepared['skipped'],
                'blocks' => $this->readBlocks($owner, $field),
            ], $this->previewPayload($this->rootEntry($owner)));
        });
    }

    public function updateBlock(Entry $block, array $values, ?User $user): array
    {
        $owner = $block->getOwner();
        if (!$owner instanceof Entry) {
            return $this->fail('That block has no owner.');
        }

        $guard = $this->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $field = $block->getField();
        if (!$field instanceof Matrix) {
            return $this->fail('That element is not a Content Designer block.');
        }

        $type = $block->getType();
        $prepared = $this->prepareFieldValues($type, $values, $user);
        if (!$prepared['ok']) {
            return $prepared;
        }

        if ($prepared['fields'] === [] && $prepared['skipped'] !== []) {
            return [
                'ok' => true,
                'saved' => false,
                'skipped' => $prepared['skipped'],
                'blocks' => $this->readBlocks($owner, $field),
            ];
        }

        if ($prepared['fields'] === []) {
            return $this->fail('Nothing to update.');
        }

        $canonical = $this->rootEntry($owner)->getCanonical(true);
        $beforeCanonical = $this->snapshot($canonical);

        return $this->mutate(function() use ($owner, $field, $block, $prepared, $canonical, $beforeCanonical) {
            [$entries, $sort] = $this->payload($owner, $field);
            $id = (string)$block->id;
            if (!isset($entries[$id])) {
                return $this->fail('That block is not in the field.');
            }
            $entries[$id]['fields'] = $prepared['fields'];
            $saved = $this->saveOwner($owner, $field, $entries, $sort);
            if (!$saved['ok']) {
                return $saved;
            }

            $after = $this->snapshot($this->reload($canonical));
            if ($after !== $beforeCanonical && !$canonical->getIsUnpublishedDraft()) {
                return $this->fail('The live entry changed unexpectedly.');
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blockId' => $block->id,
                'skipped' => $prepared['skipped'],
                'blocks' => $this->readBlocks($saved['entry'], $field),
            ], $this->previewPayload($this->rootEntry($saved['entry'])));
        });
    }

    public function reorderBlocks(Entry $owner, string $fieldHandle, array $orderedIds, ?User $user): array
    {
        $guard = $this->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $field = $this->matrixOn($owner, $fieldHandle);
        if (!$field) {
            return $this->fail('That Matrix field is not on the owner.');
        }

        $current = array_map('strval', $this->blockIds($owner, $field));
        $ordered = array_map('strval', $orderedIds);
        $currentSorted = $current;
        $orderedSorted = $ordered;
        sort($currentSorted);
        sort($orderedSorted);
        if ($currentSorted !== $orderedSorted || count($ordered) !== count(array_unique($ordered))) {
            return $this->fail('That order is invalid.');
        }

        return $this->mutate(function() use ($owner, $field, $ordered) {
            [$entries] = $this->payload($owner, $field);
            $saved = $this->saveOwner($owner, $field, $entries, $ordered);
            if (!$saved['ok']) {
                return $saved;
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blocks' => $this->readBlocks($saved['entry'], $field),
            ], $this->previewPayload($this->rootEntry($saved['entry'])));
        });
    }

    public function removeBlock(Entry $block, ?User $user): array
    {
        $owner = $block->getOwner();
        if (!$owner instanceof Entry) {
            return $this->fail('That block has no owner.');
        }

        $guard = $this->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $field = $block->getField();
        if (!$field instanceof Matrix) {
            return $this->fail('That element is not a Content Designer block.');
        }

        $ids = $this->blockIds($owner, $field);
        $remaining = array_values(array_filter($ids, fn(int $id) => $id !== (int)$block->id));
        if ($field->minEntries && count($remaining) < $field->minEntries) {
            return $this->fail($field->handle . ' requires at least ' . $field->minEntries . ' entries.');
        }

        return $this->mutate(function() use ($owner, $field, $remaining) {
            [$entries] = $this->payload($owner, $field);
            foreach (array_keys($entries) as $key) {
                if (!in_array((int)$key, $remaining, true)) {
                    unset($entries[$key]);
                }
            }
            $saved = $this->saveOwner($owner, $field, $entries, array_map('strval', $remaining));
            if (!$saved['ok']) {
                return $saved;
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blocks' => $this->readBlocks($saved['entry'], $field),
            ], $this->previewPayload($this->rootEntry($saved['entry'])));
        });
    }

    public function updateEntry(Entry $draft, array $input, ?User $user): array
    {
        $guard = $this->guardDraftMutation($draft, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        $prepared = ['fields' => [], 'skipped' => []];
        if ($values !== []) {
            $prepared = $this->prepareFieldValues($draft->getType(), $values, $user);
            if (empty($prepared['ok'])) {
                return $prepared;
            }
        }

        $hasAttributes = array_key_exists('title', $input) || array_key_exists('slug', $input) || !empty($input['parentId']);
        if (!$hasAttributes && $prepared['fields'] === []) {
            if ($prepared['skipped'] !== []) {
                return [
                    'ok' => true,
                    'saved' => false,
                    'skipped' => $prepared['skipped'],
                ];
            }

            return $this->fail('Nothing to update.');
        }

        $canonical = $draft->getCanonical(true);
        $before = $this->snapshot($canonical);

        return $this->mutate(function() use ($draft, $input, $canonical, $before, $prepared) {
            if (array_key_exists('title', $input)) {
                $draft->title = (string)$input['title'];
            }
            if (array_key_exists('slug', $input)) {
                $draft->slug = (string)$input['slug'];
            }
            if (array_key_exists('parentId', $input) && $input['parentId']) {
                $draft->setParentId((int)$input['parentId']);
            }
            foreach ($prepared['fields'] as $handle => $value) {
                $draft->setFieldValue($handle, $value);
            }

            $draft->setScenario($prepared['fields'] === [] ? Element::SCENARIO_ESSENTIALS : Element::SCENARIO_DEFAULT);
            if (!Craft::$app->getElements()->saveElement($draft)) {
                return $this->fail($this->errorText($draft), $draft->getErrors());
            }

            $draft = $this->reload($draft);
            if (!$canonical->getIsUnpublishedDraft()) {
                $after = $this->snapshot($this->reload($canonical));
                if ($after !== $before) {
                    return $this->fail('The live entry changed unexpectedly.');
                }
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'title' => $draft->title,
                'slug' => $draft->slug,
                'skipped' => $prepared['skipped'],
            ], $this->previewPayload($draft));
        });
    }

    public function publishDraft(Entry $draft, ?User $user): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }
        if (!$draft->getIsDraft()) {
            return $this->fail('That entry is not a draft.');
        }
        if (!Craft::$app->getElements()->canSaveCanonical($draft, $user)) {
            return $this->fail('You do not have permission to publish that entry.');
        }

        return $this->mutate(function() use ($draft) {
            try {
                $attributes = [];
                if (!$draft->postDate) {
                    $attributes['postDate'] = new \DateTime();
                }
                $canonical = Craft::$app->getDrafts()->applyDraft($draft, $attributes);
            } catch (Throwable) {
                return $this->fail('Could not publish the draft.');
            }

            $canonical = $this->reload($canonical);

            return [
                'ok' => true,
                'saved' => true,
                'published' => true,
                'entryId' => $canonical->id,
                'editUrl' => $canonical->getCpEditUrl(),
                'title' => $canonical->title,
            ];
        });
    }

    public function findElements(string $kind, string $title, ?User $user): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }

        $query = $kind === 'asset' ? Asset::find() : Entry::find();
        if ($title !== '') {
            $query->search($title);
        }
        $query->limit(10);

        $results = [];
        foreach ($query->all() as $element) {
            if (Craft::$app->getElements()->canView($element, $user)) {
                $row = [
                    'id' => $element->id,
                    'title' => (string)$element->title,
                    'kind' => $kind === 'asset' ? 'asset' : 'entry',
                ];
                if ($element instanceof Entry) {
                    $row['section'] = $element->getSection()?->handle;
                    $row['entryType'] = $element->getType()->handle;
                }
                $results[] = $row;
            }
        }

        return ['ok' => true, 'elements' => $results];
    }

    public function entryFromId(?int $id, ?int $draftId, ?int $siteId, ?User $user): ?Entry
    {
        if (!$id && !$draftId) {
            return null;
        }

        $entry = null;
        if ($draftId) {
            $found = Entry::find()->status(null)->siteId($siteId)->draftId($draftId)->one();
            $entry = $found instanceof Entry ? $found : null;
        }
        if (!$entry && $id) {
            $found = Entry::find()->status(null)->siteId($siteId)->id($id)->drafts(null)->one();
            $entry = $found instanceof Entry ? $found : null;
        }

        return $entry instanceof Entry && $user && Craft::$app->getElements()->canView($entry, $user) ? $entry : null;
    }

    public function blockFromId(int $id, ?int $siteId): ?Entry
    {
        $block = Entry::find()->id($id)->status(null)->drafts(null)->siteId($siteId)->one();

        return $block instanceof Entry ? $block : null;
    }

    private function describeMatrix(Matrix $field): array
    {
        $types = [];
        foreach ($field->getEntryTypes() as $entryType) {
            $types[] = [
                'handle' => $entryType->handle,
                'name' => $entryType->name,
                'fields' => $this->describeFields($entryType),
            ];
        }

        return $types;
    }

    private function describeFields(EntryType $entryType): array
    {
        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            return [];
        }

        $fields = [];
        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (!$element instanceof CustomField) {
                    continue;
                }
                $field = $element->getField();
                if (!$field) {
                    continue;
                }
                $row = [
                    'handle' => $field->handle,
                    'name' => $field->name,
                    'required' => (bool)$element->required,
                    'writable' => FieldSupport::isWritable($field) && !FieldSupport::isReadOnly($field) && !FieldSupport::isMatrix($field),
                    'input' => FieldSupport::inputHint($field),
                ];
                if ($field instanceof Matrix) {
                    $row['minEntries'] = $field->minEntries;
                    $row['maxEntries'] = $field->maxEntries;
                    $row['blockTypes'] = $this->describeMatrix($field);
                }
                $fields[] = $row;
            }
        }

        return $fields;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private function readEntryLayout(Entry $entry): array
    {
        $fields = [];
        $matrices = [];
        foreach ($this->customFields($entry->getType()) as $custom) {
            $field = $custom['field'];
            if ($field instanceof Matrix) {
                $matrices[$field->handle] = [
                    'minEntries' => $field->minEntries,
                    'maxEntries' => $field->maxEntries,
                    'blocks' => $this->readBlocks($entry, $field),
                ];
                continue;
            }
            if (!FieldSupport::isWritable($field) || FieldSupport::isReadOnly($field)) {
                continue;
            }
            try {
                $fields[$field->handle] = FieldSupport::readValue($field, $entry->getFieldValue($field->handle));
            } catch (InvalidFieldException) {
            }
        }

        return [$fields, $matrices];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readBlocks(Entry $owner, Matrix $field): array
    {
        $blocks = [];
        foreach ($this->blocks($owner, $field) as $block) {
            $item = [
                'id' => $block->id,
                'type' => $block->getType()->handle,
                'fields' => [],
            ];
            foreach ($this->customFields($block->getType()) as $custom) {
                $nested = $custom['field'];
                if ($nested instanceof Matrix) {
                    $item['fields'][$nested->handle] = $this->readBlocks($block, $nested);
                    continue;
                }
                if (!FieldSupport::isWritable($nested) || FieldSupport::isReadOnly($nested)) {
                    continue;
                }
                try {
                    $item['fields'][$nested->handle] = FieldSupport::readValue($nested, $block->getFieldValue($nested->handle));
                } catch (InvalidFieldException) {
                }
            }
            $blocks[] = $item;
        }

        return $blocks;
    }

    /**
     * @return Entry[]
     */
    private function blocks(Entry $owner, Matrix $field): array
    {
        $blocks = $owner->getFieldValue($field->handle)->status(null)->all();
        usort($blocks, fn(Entry $a, Entry $b) => ($a->sortOrder ?? 0) <=> ($b->sortOrder ?? 0));

        return $blocks;
    }

    /**
     * @return int[]
     */
    private function blockIds(Entry $owner, Matrix $field): array
    {
        return array_map(fn(Entry $block) => (int)$block->id, $this->blocks($owner, $field));
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: string[]}
     */
    private function payload(Entry $owner, Matrix $field): array
    {
        $entries = [];
        $sort = [];
        foreach ($this->blocks($owner, $field) as $block) {
            $key = (string)$block->id;
            $sort[] = $key;
            $entries[$key] = [
                'type' => $block->getType()->handle,
            ];
        }

        return [$entries, $sort];
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @param string[] $sort
     * @return array{ok: bool, entry?: Entry, error?: string, errors?: array}
     */
    private function saveOwner(Entry $owner, Matrix $field, array $entries, array $sort): array
    {
        $owner->setFieldValue($field->handle, [
            'entries' => $entries,
            'sortOrder' => $sort,
        ]);
        $owner->setScenario(Element::SCENARIO_DEFAULT);
        if (!Craft::$app->getElements()->saveElement($owner)) {
            return $this->fail($this->errorText($owner), $owner->getErrors());
        }

        $reloaded = $this->reload($owner);

        return ['ok' => true, 'entry' => $reloaded];
    }

    /**
     * @return array{ok: bool, fields?: array<string, mixed>, skipped?: array<int, string>, error?: string}
     */
    private function prepareFieldValues(EntryType $type, array $values, ?User $user): array
    {
        $fields = [];
        $skipped = [];
        $byHandle = [];
        foreach ($this->customFields($type) as $custom) {
            $byHandle[$custom['field']->handle] = $custom['field'];
        }

        foreach ($values as $handle => $value) {
            $field = $byHandle[$handle] ?? null;
            if (!$field) {
                return $this->fail("Unknown field: $handle");
            }
            $prepared = FieldSupport::prepare($field, $value, $user);
            if ($prepared['status'] === 'fail') {
                return $this->fail($prepared['message']);
            }
            if ($prepared['status'] === 'skip') {
                $skipped[] = $prepared['message'];
                continue;
            }
            $fields[$handle] = $prepared['value'];
        }

        return ['ok' => true, 'fields' => $fields, 'skipped' => $skipped];
    }

    /**
     * @return array{ok: bool, fields?: array<string, mixed>, error?: string}
     */
    private function buildNestedPayload(EntryType $type, array $nested, ?User $user): array
    {
        $fields = [];
        foreach ($this->customFields($type) as $custom) {
            $field = $custom['field'];
            if (!$field instanceof Matrix) {
                continue;
            }
            $items = $nested[$field->handle] ?? [];
            if (!is_array($items)) {
                return $this->fail($field->handle . ' nested blocks must be a list.');
            }
            $count = count($items);
            if ($field->minEntries && $count < $field->minEntries) {
                return $this->fail($field->handle . ' requires at least ' . $field->minEntries . ' entries.');
            }
            if ($field->maxEntries && $count > $field->maxEntries) {
                return $this->fail($field->handle . ' allows at most ' . $field->maxEntries . ' entries.');
            }
            if ($count === 0) {
                continue;
            }

            $entries = [];
            $sort = [];
            foreach (array_values($items) as $index => $item) {
                if (!is_array($item) || empty($item['type'])) {
                    return $this->fail($field->handle . ' nested blocks need a type.');
                }
                $nestedType = $this->typeOnField($field, (string)$item['type']);
                if (!$nestedType) {
                    return $this->fail('That block type is not available.');
                }
                $prepared = $this->prepareFieldValues($nestedType, $item['fields'] ?? [], $user);
                if (!$prepared['ok']) {
                    return $prepared;
                }
                $key = 'new' . ($index + 1);
                $entries[$key] = [
                    'type' => $nestedType->handle,
                    'fields' => $prepared['fields'],
                ];
                $sort[] = $key;
            }
            $fields[$field->handle] = [
                'entries' => $entries,
                'sortOrder' => $sort,
            ];
        }

        return ['ok' => true, 'fields' => $fields];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function guardDraftMutation(Entry $owner, ?User $user): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }

        $root = $this->rootEntry($owner);
        if (!$root->getIsDraft()) {
            return $this->fail('Refusing to edit the live entry. Changes belong on a draft.');
        }
        if (!Craft::$app->getElements()->canSave($root, $user)) {
            return $this->fail('You do not have permission to save that entry.');
        }

        return ['ok' => true];
    }

    private function rootEntry(Entry $entry): Entry
    {
        $current = $entry;
        while ($current->getOwner() instanceof Entry) {
            $current = $current->getOwner();
        }

        return $current;
    }

    private function reload(Entry $entry): Entry
    {
        $reloaded = Entry::find()
            ->id($entry->id)
            ->siteId($entry->siteId)
            ->drafts(null)
            ->status(null)
            ->one();

        return $reloaded instanceof Entry ? $reloaded : $entry;
    }

    /**
     * @return array{title: ?string, slug: ?string}
     */
    private function snapshot(Entry $entry): array
    {
        $fresh = $this->reload($entry);

        return [
            'title' => $fresh->title,
            'slug' => $fresh->slug,
        ];
    }

    /**
     * @return array{previewUrl: ?string, previewAvailable: bool, editUrl: ?string}
     */
    public function previewFor(Entry $draft): array
    {
        return $this->previewPayload($draft);
    }

    /**
     * @return array{previewUrl: ?string, previewAvailable: bool, editUrl: ?string}
     */
    private function previewPayload(Entry $draft): array
    {
        $editUrl = $draft->getCpEditUrl();
        $targetUrl = null;
        try {
            $targets = $draft->getPreviewTargets();
        } catch (Throwable) {
            $url = $draft->getUrl();
            $targets = $url ? [['url' => $url]] : [];
        }
        foreach ($targets as $target) {
            if (!empty($target['url'])) {
                $targetUrl = $target['url'];
                break;
            }
        }
        if ($targetUrl === null) {
            return [
                'previewUrl' => null,
                'previewAvailable' => false,
                'editUrl' => $editUrl,
            ];
        }

        $token = Craft::$app->getTokens()->createPreviewToken([
            'preview/preview',
            [
                'elementType' => Entry::class,
                'canonicalId' => (int)$draft->getCanonicalId(),
                'siteId' => (int)$draft->siteId,
                'draftId' => $draft->draftId ? (int)$draft->draftId : null,
                'revisionId' => null,
                'userId' => Craft::$app->getUser()->getId(),
            ],
        ]);

        if (!$token) {
            return [
                'previewUrl' => null,
                'previewAvailable' => false,
                'editUrl' => $editUrl,
            ];
        }

        $previewParam = Craft::$app->getSecurity()->hashData(Craft::$app->getSecurity()->generateRandomString());

        return [
            'previewUrl' => UrlHelper::urlWithParams($targetUrl, [
                'token' => $token,
                'x-craft-preview' => $previewParam,
            ]),
            'previewAvailable' => true,
            'editUrl' => $editUrl,
        ];
    }

    private function contentDesignerField(EntryType $entryType): ?Matrix
    {
        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            return null;
        }
        $field = $layout->getFieldByHandle(FieldSupport::CONTENT_DESIGNER);

        return $field instanceof Matrix ? $field : null;
    }

    private function matrixOn(Entry $owner, string $handle): ?Matrix
    {
        try {
            $field = $owner->getFieldLayout()?->getFieldByHandle($handle);
        } catch (InvalidFieldException) {
            return null;
        }

        return $field instanceof Matrix ? $field : null;
    }

    private function typeOnField(Matrix $field, string $handle): ?EntryType
    {
        foreach ($field->getEntryTypes() as $entryType) {
            if ($entryType->handle === $handle) {
                return $entryType;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{handle: string, name: string, entryTypes: array<int, array{handle: string, name: string}>}>
     */
    public function sectionCatalog(): array
    {
        $catalog = [];
        foreach (Plugin::getInstance()->getSettings()->allowedSections as $handle) {
            $section = $this->sectionByHandle((string)$handle);
            if (!$section) {
                continue;
            }
            $types = [];
            foreach ($section->getEntryTypes() as $entryType) {
                $types[] = ['handle' => $entryType->handle, 'name' => $entryType->name];
            }
            $catalog[] = [
                'handle' => $section->handle,
                'name' => $section->name,
                'entryTypes' => $types,
            ];
        }

        return $catalog;
    }

    public function sectionCatalogText(): string
    {
        $lines = [];
        foreach ($this->sectionCatalog() as $section) {
            $types = array_map(
                fn(array $type) => $type['handle'] . ' (' . $type['name'] . ')',
                $section['entryTypes'],
            );
            $lines[] = $section['handle'] . ' (' . $section['name'] . '): ' . implode(', ', $types);
        }

        return $lines === [] ? 'none' : implode("\n", $lines);
    }

    private function unresolved(): array
    {
        return $this->fail(self::UNRESOLVED_MESSAGE) + ['sections' => $this->sectionCatalog()];
    }

    private function entryTypeByName(?string $requested): ?EntryType
    {
        $needle = $this->normalize((string)$requested);
        if ($needle === '') {
            return null;
        }

        $matches = [];
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            if ($this->normalize($entryType->handle) === $needle || $this->normalize($entryType->name) === $needle) {
                $matches[$entryType->id] = $entryType;
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    private function matchingAllowedSection(string $requested): ?Section
    {
        $needle = $this->normalize($requested);
        if ($needle === '') {
            return null;
        }

        $matches = [];
        foreach (Plugin::getInstance()->getSettings()->allowedSections as $handle) {
            $section = $this->sectionByHandle((string)$handle);
            if ($section && $this->normalize($section->handle) === $needle) {
                $matches[$section->id] = $section;
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    /**
     * @return EntryType[]
     */
    private function matchingEntryTypes(Section $section, string $requested): array
    {
        $needle = $this->normalize($requested);
        if ($needle === '') {
            return [];
        }

        $matches = [];
        foreach ($section->getEntryTypes() as $entryType) {
            if ($this->normalize($entryType->handle) === $needle || $this->normalize($entryType->name) === $needle) {
                $matches[$entryType->id] = $entryType;
            }
        }

        return array_values($matches);
    }

    private function sectionByHandle(string $handle): ?Section
    {
        $needle = $this->normalize($handle);
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($this->normalize($section->handle) === $needle) {
                return $section;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $value));
    }

    /**
     * @return string[]
     */
    private function matrixHandles(Entry $owner): array
    {
        $handles = [];
        foreach ($this->customFields($owner->getType()) as $custom) {
            if ($custom['field'] instanceof Matrix) {
                $handles[] = $custom['field']->handle;
            }
        }

        return $handles;
    }

    /**
     * @return array<int, array{field: FieldInterface, required: bool}>
     */
    private function customFields(EntryType $entryType): array
    {
        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            return [];
        }

        $fields = [];
        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (!$element instanceof CustomField) {
                    continue;
                }
                $field = $element->getField();
                if ($field) {
                    $fields[] = [
                        'field' => $field,
                        'required' => (bool)$element->required,
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function mutate(callable $fn): array
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $result = $fn();
            if (empty($result['ok'])) {
                $transaction->rollBack();

                return $result;
            }
            $transaction->commit();

            return $result;
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::error($e->getMessage(), __METHOD__);

            return $this->fail('The change could not be saved.');
        }
    }

    private function errorText(Element $element): string
    {
        $messages = [];
        foreach ($element->getErrors() as $attribute => $errors) {
            foreach ($errors as $error) {
                $messages[] = $attribute . ': ' . $error;
            }
        }

        return $messages ? implode('; ', $messages) : 'Validation failed.';
    }

    private function fail(string $error, array $errors = []): array
    {
        return [
            'ok' => false,
            'saved' => false,
            'error' => $error,
            'errors' => $errors,
        ];
    }
}
