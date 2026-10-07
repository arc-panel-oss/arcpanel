<?php

namespace App\Services\Node;

/**
 * Opaque cursor for Node.js app log snapshots (E34 batch 4 spec D43): the
 * byte offset the CLI returned, encoded for ?after=.
 */
final class NodeAppLogCursor
{
    public static function encode(int $offset): string
    {
        return rtrim(strtr(base64_encode((string) $offset), '+/', '-_'), '=');
    }

    public static function decode(string $cursor): ?int
    {
        if ($cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || ! ctype_digit($raw)) {
            return null;
        }

        return (int) $raw;
    }
}
