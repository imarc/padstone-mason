<?php

namespace imarc\padstonemason\services\providers;

class ProviderResult
{
    /**
     * @param array<int, array{id: string, name: string, arguments: array<string, mixed>}> $toolCalls
     */
    public function __construct(
        public ?string $text = null,
        public array $toolCalls = [],
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
    ) {
    }

    public function hasUsage(): bool
    {
        return $this->promptTokens !== null || $this->completionTokens !== null;
    }
}
