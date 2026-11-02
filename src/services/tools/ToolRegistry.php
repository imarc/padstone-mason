<?php

namespace imarc\padstonemason\services\tools;

use craft\elements\User;
use imarc\padstonemason\records\ConversationRecord;

class ToolRegistry
{
    /** @var string[] */
    public const REFUSED = ['create_block_type', 'apply_project_config', 'mcp', 'upload_asset'];

    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /**
     * @param ToolInterface[]|null $tools
     */
    public function __construct(?array $tools = null)
    {
        $drafts = new ConversationDrafts();
        foreach ($tools ?? $this->defaultTools($drafts) as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    /**
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->tools as $tool) {
            $definitions[] = $tool instanceof BaseTool
                ? $tool->definition()
                : [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameters(),
                ];
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function execute(string $name, array $arguments, ConversationRecord $conversation, User $user): array
    {
        $refused = $this->refusedTool($name);
        if ($refused) {
            return $refused;
        }

        $tool = $this->tools[$name] ?? null;
        if (!$tool) {
            return ['ok' => false, 'saved' => false, 'error' => 'The change was not saved. That action is not available.'];
        }

        return $tool->execute($arguments, $conversation, $user);
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array{ok: false, saved: false, error: string}|null
     */
    private function refusedTool(string $name): ?array
    {
        if (!in_array($name, self::REFUSED, true)) {
            return null;
        }

        return [
            'ok' => false,
            'saved' => false,
            'error' => 'The change was not saved. New block types, project config, raw file uploads in field values, and MCP servers are not available. Use conversation assets or import_asset for images.',
        ];
    }

    /**
     * @return ToolInterface[]
     */
    private function defaultTools(ConversationDrafts $drafts): array
    {
        return [
            new DescribeSchema($drafts),
            new ReadLayout($drafts),
            new CreatePage($drafts),
            new AddBlock($drafts),
            new UpdateBlock($drafts),
            new ReorderBlocks($drafts),
            new RemoveBlock($drafts),
            new UpdateEntry($drafts),
            new RebindEntry($drafts),
            new FindElements($drafts),
            new ImportAsset($drafts),
            new PublishDraft($drafts),
            new UnpublishEntry($drafts),
            new DeleteEntry($drafts),
        ];
    }
}
