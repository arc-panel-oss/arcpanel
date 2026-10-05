<?php

namespace App\Services\Databases;

/**
 * One database as `v-list-database(s) USER [DATABASE] json` returns it.
 */
final class DatabaseRecord
{
    public function __construct(
        public readonly string $user,
        public readonly string $database,
        public readonly string $dbUser,
        public readonly string $host,
        public readonly string $type,
        public readonly string $charset,
        public readonly int $diskMb,
        public readonly bool $suspended,
        public readonly string $date,
        public readonly string $time,
    ) {}

    /** @param  array<string, mixed>  $row */
    public static function fromArray(string $user, string $database, array $row): self
    {
        $value = fn (string $key): string => trim((string) ($row[$key] ?? ''));

        return new self(
            user: $user,
            database: $value('DATABASE') !== '' ? $value('DATABASE') : $database,
            dbUser: $value('DBUSER'),
            host: $value('HOST'),
            type: $value('TYPE'),
            charset: $value('CHARSET'),
            diskMb: (int) $value('U_DISK'),
            suspended: $value('SUSPENDED') === 'yes',
            date: $value('DATE'),
            time: $value('TIME'),
        );
    }

    /** The name without the owner's prefix, as the forms show it. */
    public function shortName(): string
    {
        return self::unprefixed($this->user, $this->database);
    }

    public function shortUser(): string
    {
        return self::unprefixed($this->user, $this->dbUser);
    }

    public static function unprefixed(string $owner, string $name): string
    {
        return str_starts_with($name, $owner.'_') ? substr($name, strlen($owner) + 1) : $name;
    }
}
