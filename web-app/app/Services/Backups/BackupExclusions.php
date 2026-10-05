<?php

namespace App\Services\Backups;

/**
 * Backup exclusions of a user (`v-list-user-backup-exclusions USER json`,
 * written with `v-update-user-backup-exclusions USER FILE`). Each key holds
 * entries "name[:sub[:sub…]]": a web or mail domain with optional folders or
 * accounts, a database, a home directory path, or "*" for everything.
 */
final class BackupExclusions
{
    public const KEYS = ['WEB', 'DNS', 'MAIL', 'DB', 'CRON', 'USER'];

    /** Node.js runtimes of E13 (one USER entry). */
    public const NODE_ENTRY = '.nvm';

    /** @param  array<string, list<string>>  $entries  key => entries */
    public function __construct(public readonly array $entries) {}

    /**
     * The JSON maps each name to its sub-items joined with commas
     * ({"example.com": "logs,tmp"} for "example.com:logs:tmp").
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromJson(array $data): self
    {
        $entries = [];
        foreach (self::KEYS as $key) {
            $entries[$key] = [];
            foreach ((array) ($data[$key] ?? []) as $name => $subs) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }
                $subs = array_values(array_filter(array_map('trim', explode(',', (string) $subs)), fn ($v) => $v !== ''));
                $entries[$key][] = implode(':', [$name, ...$subs]);
            }
        }

        return new self($entries);
    }

    /** @return list<string> */
    public function get(string $key): array
    {
        return $this->entries[$key] ?? [];
    }

    /** One entry per line, as the textareas show them. */
    public function lines(string $key): string
    {
        return implode("\n", $this->get($key));
    }

    /**
     * A copy with new entries for some keys (the others are kept, so DNS and
     * CRON survive a save, unlike web/edit/backup/exclusions).
     *
     * @param  array<string, list<string>>  $changes
     */
    public function with(array $changes): self
    {
        $entries = $this->entries;
        foreach ($changes as $key => $list) {
            if (in_array($key, self::KEYS, true)) {
                $entries[$key] = array_values(array_unique(array_filter(array_map('trim', $list), fn ($v) => $v !== '')));
            }
        }

        return new self($entries);
    }

    /** Whether USER excludes this home directory path (".nvm", "apps/name"). */
    public function excludesPath(string $path): bool
    {
        return in_array($path, $this->get('USER'), true);
    }

    /**
     * USER entries without the managed toggles, and with the toggles that are on.
     *
     * @param  list<string>  $userLines  entries typed in the textarea
     * @param  array<string, bool>  $toggles  path => excluded
     * @return list<string>
     */
    public static function mergeToggles(array $userLines, array $toggles): array
    {
        $lines = array_values(array_filter($userLines, fn (string $line) => ! array_key_exists($line, $toggles)));
        foreach ($toggles as $path => $excluded) {
            if ($excluded) {
                $lines[] = $path;
            }
        }

        return $lines;
    }

    /** The file of v-update-user-backup-exclusions: one KEY='a,b' line per key. */
    public function toFile(): string
    {
        $lines = [];
        foreach (self::KEYS as $key) {
            $lines[] = $key."='".implode(',', $this->get($key))."'";
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> entries of a textarea, one per line */
    public static function parseLines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), fn ($v) => $v !== ''));
    }
}
