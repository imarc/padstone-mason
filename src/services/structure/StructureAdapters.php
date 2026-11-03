<?php

namespace imarc\padstonemason\services\structure;

use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Entry;

class StructureAdapters extends Component
{
    /** @var StructureAdapterInterface[] */
    private array $adapters = [];

    public function init(): void
    {
        parent::init();
        $this->register(new MatrixStructureAdapter(new StructureHelpers()));
    }

    public function register(StructureAdapterInterface $adapter): void
    {
        $this->adapters[] = $adapter;
    }

    public function forField(FieldInterface $field): ?StructureAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->canHandle($field)) {
                return $adapter;
            }
        }

        return null;
    }

    public function findBlockOnEntry(Entry $root, int $blockId): ?ElementInterface
    {
        foreach ($this->adapters as $adapter) {
            $found = $adapter->findBlockOnOwner($root, $blockId);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    public function resolveBlock(int $id, ?int $siteId): ?ElementInterface
    {
        foreach ($this->adapters as $adapter) {
            $found = $adapter->resolveBlock($id, $siteId);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    public function adapterForBlock(ElementInterface $block): ?StructureAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->ownsBlock($block)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * @return StructureAdapterInterface[]
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
