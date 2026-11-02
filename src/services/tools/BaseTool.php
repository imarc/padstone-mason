<?php

namespace imarc\padstonemason\services\tools;

abstract class BaseTool implements ToolInterface
{
    public function __construct(protected ConversationDrafts $drafts)
    {
    }

    /**
     * @return array{name: string, description: string, parameters: array<string, mixed>}
     */
    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ];
    }

    /**
     * @param array<string, mixed> $properties
     * @param string[] $required
     * @return array<string, mixed>
     */
    protected function objectSchema(array $properties = [], array $required = []): array
    {
        $parameters = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];
        if ($required !== []) {
            $parameters['required'] = $required;
        }

        return $parameters;
    }
}
