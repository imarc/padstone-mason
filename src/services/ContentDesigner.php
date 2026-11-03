<?php

namespace imarc\padstonemason\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\errors\InvalidFieldException;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\base\ElementInterface;
use craft\base\FieldInterface as CraftFieldInterface;
use imarc\padstonemason\Plugin;
use imarc\padstonemason\services\structure\StructureHelpers;
use imarc\padstonemason\services\structure\StructureAdapterInterface;
use Throwable;

class ContentDesigner extends Component
{
    public const UNRESOLVED_MESSAGE = 'Ask the admin which section and entry type to use, and include these sections and entry types in the question. Do not say the request is impossible or a project config change.';

    public const FIND_LIMIT = 15;

    private ?StructureHelpers $_helpers = null;

    private function helpers(): StructureHelpers
    {
        return $this->_helpers ??= new StructureHelpers();
    }

    private function adapters()
    {
        return Plugin::getInstance()->structureAdapters;
    }

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
        $adapter = $field ? $this->adapters()->forField($field) : null;

        return [
            'ok' => true,
            'entryType' => $entryType->handle,
            'field' => $field?->handle,
            'blockTypes' => ($field && $adapter) ? $adapter->describeTypes($field) : [],
            'layoutFields' => $this->helpers()->describeFields(
                $entryType,
                fn(Matrix $nested) => $this->adapters()->forField($nested)?->describeTypes($nested) ?? [],
            ),
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

    public function summarizePage(Entry $entry, ?User $user): array
    {
        if (!$user || !Craft::$app->getElements()->canView($entry, $user)) {
            return $this->fail('You do not have permission to view that entry.');
        }

        $matrices = [];
        foreach ($this->helpers()->customFields($entry->getType()) as $custom) {
            $field = $custom['field'];
            $adapter = $this->adapters()->forField($field);
            if (!$adapter) {
                continue;
            }
            $matrices[$field->handle] = $adapter->summarizeField($entry, $field);
        }

        return [
            'ok' => true,
            'entryId' => $entry->getCanonicalId(),
            'draftId' => $entry->draftId,
            'title' => (string)$entry->title,
            'slug' => (string)$entry->slug,
            'matrices' => $matrices,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     * @return array<string, mixed>
     */
    public function applyBlocks(Entry $draft, array $operations, ?User $user): array
    {
        $guard = $this->guardDraftMutation($draft, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        if ($operations === []) {
            return $this->fail('At least one operation is required.');
        }

        $steps = [];
        $anySaved = false;
        $preview = [
            'previewUrl' => null,
            'previewAvailable' => false,
            'editUrl' => null,
        ];

        foreach (array_values($operations) as $index => $operation) {
            if (!is_array($operation)) {
                return [
                    'ok' => false,
                    'saved' => $anySaved,
                    'stoppedAt' => $index,
                    'error' => 'Each operation must be an object.',
                    'steps' => $steps,
                ] + $preview;
            }

            $result = $this->applyBlockOperation($draft, $operation, $user);
            $step = [
                'index' => $index,
                'op' => (string)($operation['op'] ?? ''),
                'ok' => !empty($result['ok']),
                'saved' => !empty($result['saved']),
                'error' => $result['error'] ?? null,
            ];
            if (isset($result['blockId'])) {
                $step['blockId'] = $result['blockId'];
            }
            $steps[] = $step;

            if (!empty($result['previewUrl']) || array_key_exists('previewAvailable', $result)) {
                $preview = [
                    'previewUrl' => $result['previewUrl'] ?? null,
                    'previewAvailable' => (bool)($result['previewAvailable'] ?? false),
                    'editUrl' => $result['editUrl'] ?? null,
                ];
            }

            if (empty($result['ok'])) {
                return [
                    'ok' => false,
                    'saved' => $anySaved,
                    'stoppedAt' => $index,
                    'error' => $result['error'] ?? 'The change was not saved.',
                    'steps' => $steps,
                ] + $preview;
            }

            if (!empty($result['saved'])) {
                $anySaved = true;
            }

            $fresh = $this->entryFromId(
                (int)$draft->getCanonicalId(),
                $draft->draftId ? (int)$draft->draftId : null,
                (int)$draft->siteId,
                $user,
            );
            if ($fresh instanceof Entry) {
                $draft = $fresh;
            }
        }

        return [
            'ok' => true,
            'saved' => $anySaved,
            'steps' => $steps,
        ] + $preview;
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
        $resolved = $this->structureFieldOn($owner, $fieldHandle);
        if (!$resolved['ok']) {
            return $resolved;
        }

        /** @var CraftFieldInterface $field */
        $field = $resolved['field'];
        /** @var StructureAdapterInterface $adapter */
        $adapter = $resolved['adapter'];

        return $adapter->addBlock($owner, $field, $typeHandle, $position, $values, $nested, $user);
    }

    public function updateBlock(ElementInterface $block, array $values, ?User $user): array
    {
        $adapter = $this->adapters()->adapterForBlock($block);
        if (!$adapter) {
            return $this->fail('That element is not a Content Designer block.');
        }

        return $adapter->updateBlock($block, $values, $user);
    }

    public function reorderBlocks(Entry $owner, string $fieldHandle, array $orderedIds, ?User $user): array
    {
        $resolved = $this->structureFieldOn($owner, $fieldHandle);
        if (!$resolved['ok']) {
            return $resolved;
        }

        return $resolved['adapter']->reorderBlocks($owner, $resolved['field'], $orderedIds, $user);
    }

    public function removeBlock(ElementInterface $block, ?User $user): array
    {
        $adapter = $this->adapters()->adapterForBlock($block);
        if (!$adapter) {
            return $this->fail('That element is not a Content Designer block.');
        }

        return $adapter->removeBlock($block, $user);
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

    public function unpublishEntry(Entry $entry, ?User $user): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }

        $canonical = $entry->getCanonical(true);
        if (!$canonical instanceof Entry || !Craft::$app->getElements()->canView($canonical, $user)) {
            return $this->fail('That entry was not found.');
        }
        if ($canonical->getIsDraft() || $canonical->getIsRevision()) {
            return $this->fail('That entry is not published.');
        }
        if (!Craft::$app->getElements()->canSave($canonical, $user)) {
            return $this->fail('You do not have permission to unpublish that entry.');
        }

        $siteOnly = Entry::isLocalized() && Craft::$app->getIsMultiSite() && count($canonical->getSupportedSites()) !== 1;
        if ($siteOnly ? !$canonical->getEnabledForSite() : !$canonical->enabled) {
            return [
                'ok' => true,
                'saved' => true,
                'unpublished' => true,
                'alreadyUnpublished' => true,
                'entryId' => (int)$canonical->id,
                'title' => (string)$canonical->title,
            ];
        }

        return $this->mutate(function() use ($canonical, $siteOnly) {
            if ($siteOnly) {
                $canonical->setEnabledForSite(false);
            } else {
                $canonical->enabled = false;
            }
            if (!Craft::$app->getElements()->saveElement($canonical)) {
                return $this->fail($this->errorText($canonical), $canonical->getErrors());
            }

            $saved = $this->reload($canonical);

            return [
                'ok' => true,
                'saved' => true,
                'unpublished' => true,
                'entryId' => (int)$saved->id,
                'title' => (string)$saved->title,
                'editUrl' => $saved->getCpEditUrl(),
            ];
        });
    }

    public function deleteEntry(Entry $entry, ?User $user): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }

        $canonical = $entry->getCanonical(true);
        if (!$canonical instanceof Entry || !Craft::$app->getElements()->canView($canonical, $user)) {
            return $this->fail('That entry was not found.');
        }
        if (!Craft::$app->getElements()->canDelete($canonical, $user)) {
            return $this->fail('You do not have permission to delete that entry.');
        }

        $entryId = (int)$canonical->id;
        $title = (string)$canonical->title;

        return $this->mutate(function() use ($canonical, $entryId, $title) {
            if (!Craft::$app->getElements()->deleteElement($canonical)) {
                return $this->fail('Could not delete that entry.');
            }

            return [
                'ok' => true,
                'saved' => true,
                'deleted' => true,
                'entryId' => $entryId,
                'title' => $title,
            ];
        });
    }

    /**
     * @param array{section?: string, entryType?: string, status?: string, siteId?: int|string, volume?: string, folder?: string} $filters
     * @return array{ok: true, elements: array<int, array<string, mixed>>}|array{ok: false, saved: false, error: string, errors?: array}
     */
    public function findElements(string $kind, string $query, ?User $user, array $filters = []): array
    {
        if (!$user) {
            return $this->fail('You need to be signed in.');
        }

        $kind = $kind === 'asset' ? 'asset' : 'entry';
        $query = trim($query);
        $results = $kind === 'asset'
            ? $this->findAssets($query, $filters, $user)
            : $this->findEntries($query, $filters, $user);

        return ['ok' => true, 'elements' => $results];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function findEntries(string $query, array $filters, User $user): array
    {
        $elementQuery = Entry::find()->limit(self::FIND_LIMIT);
        $status = isset($filters['status']) ? trim((string)$filters['status']) : '';
        if ($status === '' || $status === 'any') {
            $elementQuery->status(null);
        } else {
            $elementQuery->status($status);
        }
        if (!empty($filters['section'])) {
            $elementQuery->section((string)$filters['section']);
        }
        if (!empty($filters['entryType'])) {
            $elementQuery->type((string)$filters['entryType']);
        }
        if (isset($filters['siteId']) && $filters['siteId'] !== '' && $filters['siteId'] !== null) {
            $elementQuery->siteId((int)$filters['siteId']);
        }
        if ($query !== '') {
            $elementQuery->search($query);
        }

        $results = [];
        foreach ($elementQuery->all() as $element) {
            if (!$element instanceof Entry || !Craft::$app->getElements()->canView($element, $user)) {
                continue;
            }
            $results[] = [
                'id' => (int)$element->id,
                'title' => (string)$element->title,
                'kind' => 'entry',
                'slug' => (string)$element->slug,
                'status' => $element->getStatus(),
                'section' => $element->getSection()?->handle,
                'entryType' => $element->getType()->handle,
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function findAssets(string $query, array $filters, User $user): array
    {
        $elementQuery = Asset::find()->status(null)->limit(self::FIND_LIMIT);
        $volumeHandle = trim((string)($filters['volume'] ?? ''));
        if ($volumeHandle !== '') {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
            if ($volume) {
                $elementQuery->volumeId($volume->id);
            } else {
                return [];
            }
        }
        $folderPath = trim((string)($filters['folder'] ?? ''));
        if ($folderPath !== '' && $volumeHandle !== '') {
            $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
            if ($volume) {
                try {
                    $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($folderPath, $volume, false);
                    $elementQuery->folderId($folder->id);
                } catch (Throwable) {
                    return [];
                }
            }
        }
        if ($query !== '') {
            $elementQuery->search($query);
        }

        $results = [];
        foreach ($elementQuery->all() as $element) {
            if (!$element instanceof Asset || !Craft::$app->getElements()->canView($element, $user)) {
                continue;
            }
            $thumbUrl = null;
            try {
                $thumbUrl = Craft::$app->getAssets()->getThumbUrl($element, 64, 64, false);
            } catch (Throwable) {
            }
            $results[] = [
                'id' => (int)$element->id,
                'title' => (string)$element->title,
                'kind' => 'asset',
                'filename' => (string)$element->getFilename(),
                'volume' => $element->getVolume()?->handle,
                'thumbUrl' => $thumbUrl,
            ];
        }

        return $results;
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

    public function blockFromId(int $id, ?int $siteId): ?ElementInterface
    {
        return $this->adapters()->resolveBlock($id, $siteId);
    }

    /**
     * Find a structure block by id under an entry (or nested block), using the same owner-scoped
     * field queries as read_layout. Craft named drafts reuse canonical nested element ids and
     * only add elements_owners rows, so a bare id lookup + getOwner() walk misses the draft.
     */
    public function findBlockOnEntry(Entry $root, int $blockId): ?ElementInterface
    {
        if ($blockId < 1) {
            return null;
        }

        return $this->adapters()->findBlockOnEntry($root, $blockId);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private function readEntryLayout(Entry $entry): array
    {
        $fields = [];
        $matrices = [];
        foreach ($this->helpers()->customFields($entry->getType()) as $custom) {
            $field = $custom['field'];
            $adapter = $this->adapters()->forField($field);
            if ($adapter) {
                $matrices[$field->handle] = $adapter->readField($entry, $field);
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

    private function contentDesignerField(EntryType $entryType): ?CraftFieldInterface
    {
        $layout = $entryType->getFieldLayout();
        if (!$layout) {
            return null;
        }
        $field = $layout->getFieldByHandle(FieldSupport::CONTENT_DESIGNER);
        if (!$field || !$this->adapters()->forField($field)) {
            return null;
        }

        return $field;
    }

    /**
     * @return array{ok: true, field: CraftFieldInterface, adapter: StructureAdapterInterface}|array{ok: false, saved: false, error: string, errors?: array}
     */
    private function structureFieldOn(Entry $owner, string $handle): array
    {
        try {
            $field = $owner->getFieldLayout()?->getFieldByHandle($handle);
        } catch (InvalidFieldException) {
            $field = null;
        }

        if (!$field) {
            $handles = $this->structureHandles($owner);

            return $this->fail(
                'That structure field is not on the owner. Structure fields on this layout: '
                . ($handles === [] ? 'none' : implode(', ', $handles))
                . '.',
            );
        }

        $adapter = $this->adapters()->forField($field);
        if (!$adapter) {
            return $this->fail(
                'That field type is not supported for block editing (' . $field::class . ').',
            );
        }

        return ['ok' => true, 'field' => $field, 'adapter' => $adapter];
    }

    /**
     * @return string[]
     */
    private function structureHandles(Entry $owner): array
    {
        $handles = [];
        foreach ($this->helpers()->customFields($owner->getType()) as $custom) {
            if ($this->adapters()->forField($custom['field'])) {
                $handles[] = $custom['field']->handle;
            }
        }

        return $handles;
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

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function applyBlockOperation(Entry $draft, array $operation, ?User $user): array
    {
        return match ((string)($operation['op'] ?? '')) {
            'add' => $this->applyAddOperation($draft, $operation, $user),
            'update' => $this->applyUpdateOperation($draft, $operation, $user),
            'remove' => $this->applyRemoveOperation($draft, $operation, $user),
            'reorder' => $this->applyReorderOperation($draft, $operation, $user),
            default => $this->fail('Unknown operation. Use add, update, remove, or reorder.'),
        };
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function applyAddOperation(Entry $draft, array $operation, ?User $user): array
    {
        $owner = $draft;
        if (!empty($operation['parentId'])) {
            $owner = $this->findBlockOnEntry($draft, (int)$operation['parentId']);
            if (!$owner instanceof Entry) {
                return $this->fail('That parent block is not on this draft.');
            }
        }

        return $this->addBlock(
            $owner,
            (string)($operation['fieldHandle'] ?? FieldSupport::CONTENT_DESIGNER),
            (string)($operation['type'] ?? ''),
            isset($operation['position']) ? (int)$operation['position'] : null,
            is_array($operation['values'] ?? null) ? $operation['values'] : [],
            is_array($operation['nested'] ?? null) ? $operation['nested'] : [],
            $user,
        );
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function applyUpdateOperation(Entry $draft, array $operation, ?User $user): array
    {
        $block = $this->findBlockOnEntry($draft, (int)($operation['blockId'] ?? 0));
        if (!$block) {
            return $this->fail('That block is not on this draft.');
        }

        return $this->updateBlock(
            $block,
            is_array($operation['values'] ?? null) ? $operation['values'] : [],
            $user,
        );
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function applyRemoveOperation(Entry $draft, array $operation, ?User $user): array
    {
        $block = $this->findBlockOnEntry($draft, (int)($operation['blockId'] ?? 0));
        if (!$block) {
            return $this->fail('That block is not on this draft.');
        }

        return $this->removeBlock($block, $user);
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private function applyReorderOperation(Entry $draft, array $operation, ?User $user): array
    {
        $owner = $draft;
        if (!empty($operation['parentId'])) {
            $owner = $this->findBlockOnEntry($draft, (int)$operation['parentId']);
            if (!$owner instanceof Entry) {
                return $this->fail('That parent block is not on this draft.');
            }
        }

        return $this->reorderBlocks(
            $owner,
            (string)($operation['fieldHandle'] ?? FieldSupport::CONTENT_DESIGNER),
            is_array($operation['orderedIds'] ?? null) ? $operation['orderedIds'] : [],
            $user,
        );
    }

    /**
     * @return array{minEntries: ?int, maxEntries: ?int, count: int, atMin: bool, atMax: bool, hint?: string}
     */
    private function matrixMeta(Matrix $field, int $count): array
    {
        $min = $field->minEntries;
        $max = $field->maxEntries;
        $atMin = $min !== null && $count <= (int)$min;
        $atMax = $max !== null && $count >= (int)$max;
        $meta = [
            'minEntries' => $min,
            'maxEntries' => $max,
            'count' => $count,
            'atMin' => $atMin,
            'atMax' => $atMax,
        ];
        if ($atMax) {
            $meta['hint'] = 'Field is at maximum. Update an existing block id; do not add another.';
        }

        return $meta;
    }

    private function blockSignal(Entry $block): string
    {
        foreach ($this->customFields($block->getType()) as $custom) {
            $field = $custom['field'];
            if (
                !($field instanceof PlainText || $field instanceof CkeditorField || FieldSupport::isRegex($field))
                || FieldSupport::isReadOnly($field)
            ) {
                continue;
            }

            try {
                $value = FieldSupport::readValue($field, $block->getFieldValue($field->handle));
            } catch (Throwable) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
            if ($plain === '') {
                continue;
            }

            if (mb_strlen($plain) > 80) {
                return mb_substr($plain, 0, 77) . '...';
            }

            return $plain;
        }

        return '';
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
