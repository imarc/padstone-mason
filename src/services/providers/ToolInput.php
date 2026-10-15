<?php

namespace imarc\padstonemason\services\providers;

/**
 * Providers require tool arguments to be a JSON object. PHP turns {} into []
 * and then encodes that array as a JSON list.
 */
class ToolInput
{
    public static function object(mixed $arguments): array|\stdClass
    {
        if (is_string($arguments) && $arguments !== '') {
            $decoded = json_decode($arguments, true);
            $arguments = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($arguments) || $arguments === [] || array_is_list($arguments)) {
            return new \stdClass();
        }

        return $arguments;
    }

    public static function json(mixed $arguments): string
    {
        $encoded = json_encode(self::object($arguments));

        return is_string($encoded) ? $encoded : '{}';
    }
}
