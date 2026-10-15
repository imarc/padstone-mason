<?php

namespace imarc\padstonemason\services\providers;

use RuntimeException;
use Throwable;

class ProviderFailure extends RuntimeException
{
    public static function fromBody(string $provider, int $status, string $body, string $apiKey): self
    {
        $payload = json_decode($body, true);
        $detail = 'unexpected response';
        if (is_array($payload)) {
            $message = $payload['error']['message'] ?? $payload['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                $detail = trim($message);
            }
        }

        return new self(self::redact("$provider returned $status: $detail", $apiKey));
    }

    public static function fromThrowable(string $provider, Throwable $error, string $apiKey): self
    {
        $detail = trim($error->getMessage());
        if ($detail === '') {
            $detail = 'the request did not complete';
        }

        return new self(self::redact("$provider request failed: $detail", $apiKey));
    }

    public static function redact(string $text, string $apiKey): string
    {
        if ($apiKey !== '') {
            $text = str_replace($apiKey, '[redacted]', $text);
        }
        $text = preg_replace('/sk-(?:ant-)?[A-Za-z0-9_\-]{8,}/', '[redacted]', $text) ?? $text;
        if (strlen($text) > 400) {
            $text = substr($text, 0, 400) . '…';
        }

        return $text;
    }
}
