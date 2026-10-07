<?php

namespace App\Services\Docker;

/**
 * Opaque cursor for Docker app log snapshots (E34 batch 4 spec D43): the
 * RFC3339 timestamp the CLI returned, encoded for ?after=.
 */
final class DockerAppLogCursor
{
    public static function encode(string $since): string
    {
        return rtrim(strtr(base64_encode($since), '+/', '-_'), '=');
    }

    public static function decode(string $cursor): ?string
    {
        if ($cursor === '') {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || preg_match(DockerAppService::SINCE, $raw) !== 1) {
            return null;
        }

        return $raw;
    }
}
