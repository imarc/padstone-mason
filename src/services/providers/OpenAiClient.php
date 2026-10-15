<?php

namespace imarc\padstonemason\services\providers;

use GuzzleHttp\Client;
use Throwable;

class OpenAiClient implements ProviderClient
{
    public function __construct(
        private string $apiKey,
        private ?Client $http = null,
    ) {
    }

    public function complete(array $messages, array $tools, string $model, int $maxTokens): ProviderResult
    {
        $body = [
            'model' => $model,
            'messages' => $this->messages($messages),
            'max_completion_tokens' => $maxTokens,
        ];
        if ($tools !== []) {
            $body['tools'] = array_map(fn(array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
            ], $tools);
        }

        try {
            $response = $this->http()->post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'http_errors' => false,
            ]);
        } catch (Throwable $e) {
            throw ProviderFailure::fromThrowable('OpenAI', $e, $this->apiKey);
        }

        $raw = (string)$response->getBody();
        $payload = json_decode($raw, true);
        if ($response->getStatusCode() >= 400 || !is_array($payload)) {
            throw ProviderFailure::fromBody('OpenAI', $response->getStatusCode(), $raw, $this->apiKey);
        }

        $message = $payload['choices'][0]['message'] ?? [];
        $calls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            $arguments = json_decode($call['function']['arguments'] ?? '{}', true);
            $calls[] = [
                'id' => (string)($call['id'] ?? uniqid('tool_', true)),
                'name' => (string)($call['function']['name'] ?? ''),
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        $usage = $payload['usage'] ?? null;

        return new ProviderResult(
            text: isset($message['content']) ? (string)$message['content'] : null,
            toolCalls: $calls,
            promptTokens: isset($usage['prompt_tokens']) ? (int)$usage['prompt_tokens'] : null,
            completionTokens: isset($usage['completion_tokens']) ? (int)$usage['completion_tokens'] : null,
        );
    }

    private function http(): Client
    {
        return $this->http ?? new Client(['timeout' => 60]);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function messages(array $messages): array
    {
        $converted = [];
        foreach ($messages as $message) {
            $role = $message['role'];
            if ($role === 'tool') {
                $converted[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message['toolCallId'],
                    'content' => (string)($message['content'] ?? ''),
                ];
                continue;
            }

            $row = [
                'role' => $role,
                'content' => $message['content'] ?? '',
            ];
            if ($role === 'assistant' && !empty($message['toolCalls'])) {
                $row['tool_calls'] = array_map(fn(array $call) => [
                    'id' => $call['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $call['name'],
                        'arguments' => ToolInput::json($call['arguments'] ?? null),
                    ],
                ], $message['toolCalls']);
                if ($row['content'] === null || $row['content'] === '') {
                    $row['content'] = null;
                }
            }
            $converted[] = $row;
        }

        return $converted;
    }
}
