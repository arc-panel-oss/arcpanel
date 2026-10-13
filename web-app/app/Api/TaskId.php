<?php

namespace App\Api;

/**
 * Parsed task id of API v2 (E34 batch 3 spec D31). User names may contain
 * dots; segments are separated by colons.
 */
final class TaskId
{
    public const SOURCE_SYS = 'sys';

    public const SOURCE_DOCKER_ENGINE = 'docker-engine';

    public const SOURCE_NODE = 'node';

    public const SOURCE_DOCKER = 'docker';

    public const SOURCE_INSTALL = 'install';

    public const SOURCE_QUEUE = 'queue';

    /** A background malware scan of a user (E25 batch 1 spec D10). */
    public const SOURCE_MALWARE = 'malware';

    public function __construct(
        public readonly string $source,
        public readonly string $recordId,
        public readonly ?string $user = null,
        public readonly ?string $app = null,
    ) {}

    public function apiId(): string
    {
        return match ($this->source) {
            self::SOURCE_SYS, self::SOURCE_DOCKER_ENGINE => $this->source.':'.$this->recordId,
            self::SOURCE_NODE, self::SOURCE_INSTALL, self::SOURCE_QUEUE, self::SOURCE_MALWARE => $this->source.':'.$this->user.':'.$this->recordId,
            self::SOURCE_DOCKER => $this->source.':'.$this->user.':'.$this->app.':'.$this->recordId,
        };
    }

    public static function queue(string $user, string $recordId): self
    {
        return new self(self::SOURCE_QUEUE, $recordId, $user);
    }

    /**
     * Returns null when the id is malformed (D32 → 404). Every source
     * numbers its tasks with the date in seconds and four random digits,
     * so a record id is exactly 14 digits; anything else would reach the
     * list commands as another argument (`v-list-user-tasks` reads 1–4
     * digits as LINES).
     */
    public static function parse(string $id): ?self
    {
        $parts = explode(':', $id);
        if (preg_match('/^\d{14}$/', (string) end($parts)) !== 1) {
            return null;
        }

        return match ($parts[0]) {
            self::SOURCE_SYS, self::SOURCE_DOCKER_ENGINE => count($parts) === 2 && $parts[1] !== ''
                ? new self($parts[0], $parts[1])
                : null,
            self::SOURCE_NODE, self::SOURCE_INSTALL, self::SOURCE_QUEUE, self::SOURCE_MALWARE => count($parts) === 3
                && $parts[1] !== '' && $parts[2] !== ''
                ? new self($parts[0], $parts[2], $parts[1])
                : null,
            self::SOURCE_DOCKER => count($parts) === 4
                && $parts[1] !== '' && $parts[2] !== '' && $parts[3] !== ''
                ? new self($parts[0], $parts[3], $parts[1], $parts[2])
                : null,
            default => null,
        };
    }
}
