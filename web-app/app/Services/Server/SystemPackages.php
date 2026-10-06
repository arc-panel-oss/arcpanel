<?php

namespace App\Services\Server;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;

/**
 * The system package catalog of E5 (spec 2026-10-06-006 §4–§7): runtimes and
 * tools that administrators install from the distribution's archive. Installs
 * and removals run as the system task.
 */
final class SystemPackages
{
    /** Categories of the catalog, in page order. */
    public const CATEGORIES = ['runtime', 'media', 'tooling'];

    public const ACTIONS = ['add', 'delete'];

    /** @var list<array<string, mixed>>|null per request (v-list-sys-packages is not cached) */
    private ?array $entries = null;

    public function __construct(private readonly ArcCommand $arc) {}

    /**
     * Every entry of `v-list-sys-packages json`.
     *
     * @return list<array{slug: string, name: string, summary: string, category: string, provides: list<string>, packages: list<string>, available: bool, status: string, source: string, version: string, upgradable: bool, domains: list<string>, usedBy: int}>
     */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        $entries = [];
        foreach ($this->arc->json('v-list-sys-packages', [], Options::allowFailure()) as $row) {
            if (! is_array($row) || ! isset($row['SLUG'])) {
                continue;
            }
            $entries[] = [
                'slug' => (string) $row['SLUG'],
                'name' => (string) ($row['NAME'] ?? $row['SLUG']),
                'summary' => (string) ($row['SUMMARY'] ?? ''),
                'category' => (string) ($row['CATEGORY'] ?? ''),
                'provides' => array_values(array_map('strval', (array) ($row['PROVIDES'] ?? []))),
                'packages' => array_values(array_map('strval', (array) ($row['PACKAGES'] ?? []))),
                'available' => ($row['AVAILABLE'] ?? '') === 'yes',
                'status' => (string) ($row['STATUS'] ?? 'missing'),
                'source' => (string) ($row['SOURCE'] ?? '-'),
                'version' => (string) ($row['VERSION'] ?? ''),
                'upgradable' => ($row['UPGRADABLE'] ?? '') === 'yes',
                'domains' => array_values(array_map('strval', (array) ($row['DOMAINS'] ?? []))),
                'usedBy' => (int) ($row['USED_BY'] ?? 0),
            ];
        }

        return $this->entries = $entries;
    }

    /**
     * The entries grouped by category, in the order of CATEGORIES.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function grouped(): array
    {
        $groups = array_fill_keys(self::CATEGORIES, []);
        foreach ($this->all() as $entry) {
            $groups[in_array($entry['category'], self::CATEGORIES, true) ? $entry['category'] : 'tooling'][] = $entry;
        }

        return array_filter($groups);
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Runtime ids that an installed entry provides (`python`, `go`, `ruby`).
     *
     * @return list<string>
     */
    public function installedRuntimes(): array
    {
        $runtimes = [];
        foreach ($this->all() as $entry) {
            if ($entry['status'] === 'installed') {
                array_push($runtimes, ...$entry['provides']);
            }
        }

        return array_values(array_unique($runtimes));
    }

    /** `v-start-sys-task package SLUG add|delete` */
    public function start(string $slug, string $action): CommandResult
    {
        return $this->arc->run('v-start-sys-task', ['package', $slug, $action], Options::allowFailure());
    }
}
