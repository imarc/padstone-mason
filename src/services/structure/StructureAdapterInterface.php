<?php

namespace imarc\padstonemason\services\structure;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\elements\User;

interface StructureAdapterInterface
{
    public function canHandle(FieldInterface $field): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function describeTypes(FieldInterface $field): array;

    /**
     * @return array{minEntries: ?int, maxEntries: ?int, count: int, atMin: bool, atMax: bool, hint?: string, blocks: array<int, array<string, mixed>>}
     */
    public function readField(Entry $owner, FieldInterface $field): array;

    /**
     * @return array{minEntries: ?int, maxEntries: ?int, count: int, atMin: bool, atMax: bool, hint?: string, blocks: array<int, array{id: int, type: string, signal: string}>}
     */
    public function summarizeField(Entry $owner, FieldInterface $field): array;

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $nested
     * @return array<string, mixed>
     */
    public function addBlock(
        Entry $owner,
        FieldInterface $field,
        string $typeHandle,
        ?int $position,
        array $values,
        array $nested,
        ?User $user,
    ): array;

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function updateBlock(ElementInterface $block, array $values, ?User $user): array;

    /**
     * @return array<string, mixed>
     */
    public function removeBlock(ElementInterface $block, ?User $user): array;

    /**
     * @param array<int|string> $orderedIds
     * @return array<string, mixed>
     */
    public function reorderBlocks(Entry $owner, FieldInterface $field, array $orderedIds, ?User $user): array;

    public function findBlockOnOwner(Entry $root, int $blockId): ?ElementInterface;

    public function resolveBlock(int $id, ?int $siteId): ?ElementInterface;

    /**
     * Whether this adapter owns the given nested block element.
     */
    public function ownsBlock(ElementInterface $block): bool;
}
