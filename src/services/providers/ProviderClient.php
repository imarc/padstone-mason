<?php

namespace imarc\padstonemason\services\providers;

interface ProviderClient
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array{name: string, description: string, parameters: array<string, mixed>}> $tools
     */
    public function complete(array $messages, array $tools, string $model, int $maxTokens): ProviderResult;
}
