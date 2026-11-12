<?php

namespace imarc\padstonemason\services\structure;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\errors\InvalidFieldException;
use craft\fields\Matrix;
use craft\models\EntryType;
use imarc\padstonemason\services\FieldSupport;

class MatrixStructureAdapter implements StructureAdapterInterface
{
    public function __construct(private StructureHelpers $helpers)
    {
    }

    public function canHandle(FieldInterface $field): bool
    {
        return $field instanceof Matrix;
    }

    public function ownsBlock(ElementInterface $block): bool
    {
        return $block instanceof Entry && $block->getField() instanceof Matrix;
    }

    public function describeTypes(FieldInterface $field): array
    {
        if (!$field instanceof Matrix) {
            return [];
        }

        return $this->describeMatrix($field);
    }

    public function readField(Entry $owner, FieldInterface $field): array
    {
        if (!$field instanceof Matrix) {
            return $this->helpers->countMeta(null, null, 0) + ['blocks' => []];
        }

        $blocks = $this->readBlocks($owner, $field);

        return $this->helpers->countMeta($field->minEntries, $field->maxEntries, count($blocks)) + [
            'blocks' => $blocks,
        ];
    }

    public function summarizeField(Entry $owner, FieldInterface $field): array
    {
        if (!$field instanceof Matrix) {
            return $this->helpers->countMeta(null, null, 0) + ['blocks' => []];
        }

        $blocks = $this->blocks($owner, $field);
        $items = [];
        foreach ($blocks as $block) {
            $items[] = [
                'id' => (int)$block->id,
                'type' => $block->getType()->handle,
                'signal' => $this->helpers->blockSignal($block, $block->getType()->getFieldLayout()),
            ];
        }

        return $this->helpers->countMeta($field->minEntries, $field->maxEntries, count($blocks)) + [
            'blocks' => $items,
        ];
    }

    public function addBlock(
        Entry $owner,
        FieldInterface $field,
        string $typeHandle,
        ?int $position,
        array $values,
        array $nested,
        ?User $user,
    ): array {
        if (!$field instanceof Matrix) {
            return $this->helpers->fail('That field is not a Matrix field.');
        }

        $guard = $this->helpers->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $type = $this->typeOnField($field, $typeHandle);
        if (!$type) {
            return $this->helpers->fail('That block type is not available.');
        }

        $prepared = $this->helpers->prepareFieldValues($type, $values, $user);
        if (!$prepared['ok']) {
            return $prepared;
        }

        $nestedResult = $this->buildNestedPayload($type, $nested, $user);
        if (!$nestedResult['ok']) {
            return $nestedResult;
        }

        $count = count($this->blockIds($owner, $field));
        if ($field->maxEntries && $count >= (int)$field->maxEntries) {
            return $this->helpers->fail($field->handle . ' allows at most ' . $field->maxEntries . ' entries.');
        }

        $fields = $prepared['fields'] + $nestedResult['fields'];

        return $this->helpers->mutate(function() use ($owner, $field, $typeHandle, $position, $fields, $prepared) {
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
            ], $this->helpers->previewPayload($this->helpers->rootEntry($owner)));
        });
    }

    public function updateBlock(ElementInterface $block, array $values, ?User $user): array
    {
        if (!$block instanceof Entry) {
            return $this->helpers->fail('That element is not a Matrix block.');
        }

        $owner = $block->getOwner();
        if (!$owner instanceof Entry) {
            return $this->helpers->fail('That block has no owner.');
        }

        $guard = $this->helpers->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $field = $block->getField();
        if (!$field instanceof Matrix) {
            return $this->helpers->fail('That element is not a Content Designer block.');
        }

        $type = $block->getType();
        $prepared = $this->helpers->prepareFieldValues($type, $values, $user);
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
            return $this->helpers->fail('Nothing to update.');
        }

        $canonical = $this->helpers->rootEntry($owner)->getCanonical(true);
        $beforeCanonical = $this->helpers->snapshot($canonical);

        return $this->helpers->mutate(function() use ($owner, $field, $block, $prepared, $canonical, $beforeCanonical) {
            [$entries, $sort] = $this->payload($owner, $field);
            $id = (string)$block->id;
            if (!isset($entries[$id])) {
                return $this->helpers->fail('That block is not in the field.');
            }
            $entries[$id]['fields'] = $prepared['fields'];
            $saved = $this->saveOwner($owner, $field, $entries, $sort);
            if (!$saved['ok']) {
                return $saved;
            }

            $after = $this->helpers->snapshot($this->helpers->reload($canonical));
            if ($after !== $beforeCanonical && !$canonical->getIsUnpublishedDraft()) {
                return $this->helpers->fail('The live entry changed unexpectedly.');
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blockId' => $block->id,
                'skipped' => $prepared['skipped'],
                'blocks' => $this->readBlocks($saved['entry'], $field),
            ], $this->helpers->previewPayload($this->helpers->rootEntry($saved['entry'])));
        });
    }

    public function removeBlock(ElementInterface $block, ?User $user): array
    {
        if (!$block instanceof Entry) {
            return $this->helpers->fail('That element is not a Matrix block.');
        }

        $owner = $block->getOwner();
        if (!$owner instanceof Entry) {
            return $this->helpers->fail('That block has no owner.');
        }

        $guard = $this->helpers->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $field = $block->getField();
        if (!$field instanceof Matrix) {
            return $this->helpers->fail('That element is not a Content Designer block.');
        }

        $ids = $this->blockIds($owner, $field);
        $remaining = array_values(array_filter($ids, fn(int $id) => $id !== (int)$block->id));
        if ($field->minEntries && count($remaining) < $field->minEntries) {
            return $this->helpers->fail($field->handle . ' requires at least ' . $field->minEntries . ' entries.');
        }

        return $this->helpers->mutate(function() use ($owner, $field, $remaining) {
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
            ], $this->helpers->previewPayload($this->helpers->rootEntry($saved['entry'])));
        });
    }

    public function reorderBlocks(Entry $owner, FieldInterface $field, array $orderedIds, ?User $user): array
    {
        if (!$field instanceof Matrix) {
            return $this->helpers->fail('That field is not a Matrix field.');
        }

        $guard = $this->helpers->guardDraftMutation($owner, $user);
        if (!$guard['ok']) {
            return $guard;
        }

        $current = array_map('strval', $this->blockIds($owner, $field));
        $ordered = array_map('strval', $orderedIds);
        $currentSorted = $current;
        $orderedSorted = $ordered;
        sort($currentSorted);
        sort($orderedSorted);
        if ($currentSorted !== $orderedSorted || count($ordered) !== count(array_unique($ordered))) {
            return $this->helpers->fail('That order is invalid.');
        }

        return $this->helpers->mutate(function() use ($owner, $field, $ordered) {
            [$entries] = $this->payload($owner, $field);
            $saved = $this->saveOwner($owner, $field, $entries, $ordered);
            if (!$saved['ok']) {
                return $saved;
            }

            return array_merge([
                'ok' => true,
                'saved' => true,
                'blocks' => $this->readBlocks($saved['entry'], $field),
            ], $this->helpers->previewPayload($this->helpers->rootEntry($saved['entry'])));
        });
    }

    public function findBlockOnOwner(Entry $root, int $blockId): ?ElementInterface
    {
        if ($blockId < 1) {
            return null;
        }

        return $this->findBlockUnder($root, $blockId);
    }

    public function resolveBlock(int $id, ?int $siteId): ?ElementInterface
    {
        $block = Entry::find()->id($id)->status(null)->drafts(null)->siteId($siteId)->one();

        return $block instanceof Entry && $block->getField() instanceof Matrix ? $block : null;
    }

    private function findBlockUnder(Entry $owner, int $blockId): ?Entry
    {
        foreach ($this->helpers->customFields($owner->getType()) as $custom) {
            $field = $custom['field'];
            if (!$field instanceof Matrix) {
                continue;
            }
            foreach ($this->blocks($owner, $field) as $block) {
                if ((int)$block->id === $blockId) {
                    return $block;
                }
                $nested = $this->findBlockUnder($block, $blockId);
                if ($nested instanceof Entry) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function describeMatrix(Matrix $field): array
    {
        $types = [];
        foreach ($field->getEntryTypes() as $entryType) {
            $types[] = [
                'handle' => $entryType->handle,
                'name' => $entryType->name,
                'fields' => $this->helpers->describeFields($entryType, fn(Matrix $nested) => $this->describeMatrix($nested)),
            ];
        }

        return $types;
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
            foreach ($this->helpers->customFields($block->getType()) as $custom) {
                $nested = $custom['field'];
                if ($nested instanceof Matrix) {
                    $item['fields'][$nested->handle] = $this->readBlocks($block, $nested);
                    continue;
                }
                if (FieldSupport::isReadOnly($nested)) {
                    continue;
                }
                try {
                    $raw = $block->getFieldValue($nested->handle);
                    if (!FieldSupport::canRepresent($nested, $raw)) {
                        continue;
                    }
                    $item['fields'][$nested->handle] = FieldSupport::readValue($nested, $raw);
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
            return $this->helpers->fail($this->helpers->errorText($owner), $owner->getErrors());
        }

        return ['ok' => true, 'entry' => $this->helpers->reload($owner)];
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
     * @return array{ok: bool, fields?: array<string, mixed>, error?: string}
     */
    private function buildNestedPayload(EntryType $type, array $nested, ?User $user): array
    {
        $fields = [];
        foreach ($this->helpers->customFields($type) as $custom) {
            $field = $custom['field'];
            if (!$field instanceof Matrix) {
                continue;
            }
            $items = $nested[$field->handle] ?? [];
            if (!is_array($items)) {
                return $this->helpers->fail($field->handle . ' nested blocks must be a list.');
            }
            $count = count($items);
            if ($field->minEntries && $count < $field->minEntries) {
                return $this->helpers->fail($field->handle . ' requires at least ' . $field->minEntries . ' entries.');
            }
            if ($field->maxEntries && $count > $field->maxEntries) {
                return $this->helpers->fail($field->handle . ' allows at most ' . $field->maxEntries . ' entries.');
            }
            if ($count === 0) {
                continue;
            }

            $entries = [];
            $sort = [];
            foreach (array_values($items) as $index => $item) {
                if (!is_array($item) || empty($item['type'])) {
                    return $this->helpers->fail($field->handle . ' nested blocks need a type.');
                }
                $nestedType = $this->typeOnField($field, (string)$item['type']);
                if (!$nestedType) {
                    return $this->helpers->fail('That block type is not available.');
                }
                $prepared = $this->helpers->prepareFieldValues($nestedType, $item['fields'] ?? [], $user);
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
}
