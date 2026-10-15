<?php

namespace imarc\padstonemason\services\providers;

use GuzzleHttp\Client;
use Throwable;

class AnthropicClient implements ProviderClient
{
    public function __construct(
        private string $apiKey,
        private ?Client $http = null,
    ) {
    }

    public function complete(array $messages, array $tools, string $model, int $maxTokens): ProviderResult
    {
        [$system, $converted] = $this->messages($messages);
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $converted,
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }
        if ($tools !== []) {
            $body['tools'] = array_map(fn(array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'],
            ], $tools);
        }

        try {
            $response = $this->http()->post('https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            throw ProviderFailure::fromThrowable('Anthropic', $e, $this->apiKey);
        }

        $raw = (string)$response->getBody();
        $payload = json_decode($raw, true);
        if ($response->getStatusCode() >= 400 || !is_array($payload)) {
            throw ProviderFailure::fromBody('Anthropic', $response->getStatusCode(), $raw, $this->apiKey);
        }

        $text = [];
        $calls = [];
        foreach ($payload['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text[] = (string)($block['text'] ?? '');
            }
            if (($block['type'] ?? '') === 'tool_use') {
                $calls[] = [
                    'id' => (string)($block['id'] ?? uniqid('tool_', true)),
                    'name' => (string)($block['name'] ?? ''),
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        $usage = $payload['usage'] ?? null;

        return new ProviderResult(
            text: $text === [] ? null : implode("\n", $text),
            toolCalls: $calls,
            promptTokens: isset($usage['input_tokens']) ? (int)$usage['input_tokens'] : null,
            completionTokens: isset($usage['output_tokens']) ? (int)$usage['output_tokens'] : null,
        );
    }

    private function http(): Client
    {
        return $this->http ?? new Client(['timeout' => 60]);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function messages(array $messages): array
    {
        $system = [];
        $converted = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $system[] = (string)$message['content'];
                continue;
            }
            if ($message['role'] === 'tool') {
                $converted[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => $message['toolCallId'],
                        'content' => (string)($message['content'] ?? ''),
                    ]],
                ];
                continue;
            }
            if ($message['role'] === 'assistant' && !empty($message['toolCalls'])) {
                $content = [];
                if (!empty($message['content'])) {
                    $content[] = ['type' => 'text', 'text' => (string)$message['content']];
                }
                foreach ($message['toolCalls'] as $call) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $call['id'],
                        'name' => $call['name'],
                        'input' => ToolInput::object($call['arguments'] ?? null),
                    ];
                }
                $converted[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }
            $converted[] = [
                'role' => $message['role'] === 'assistant' ? 'assistant' : 'user',
                'content' => (string)($message['content'] ?? ''),
            ];
        }

        return [implode("\n\n", $system), $converted];
    }
}
