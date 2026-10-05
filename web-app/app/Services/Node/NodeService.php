<?php

namespace App\Services\Node;

use App\Arc\ArcCommand;

/**
 * Node.js runtimes and global packages of a user (E13 spec §6.1). Long
 * operations (installs, updates, the outdated check) start a background
 * task; NodePolicy decides who may do what and the commands check the
 * package keys again on the server.
 */
final class NodeService
{
    /** Actions of v-start-user-node-task */
    public const TASKS = ['add-version', 'add-package', 'update-packages', 'check-outdated'];

    /** Version specs v-add-user-node-version accepts */
    public const SPEC = '/^(lts|\d{1,3}|\d{1,3}\.\d{1,3}\.\d{1,3})$/';

    /** An installed version */
    public const VERSION = '/^\d{1,3}\.\d{1,3}\.\d{1,3}$/';

    /** npm names, optionally scoped (func/node.sh node_pkg_name_valid) */
    public const PACKAGE_NAME = '/^(@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/';

    /** A name with an optional @version, range or dist-tag (node_pkg_spec_valid) */
    public const PACKAGE_SPEC = '/^(@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*(@[A-Za-z0-9.^~<>=*+-]{1,64})?$/';

    public function __construct(private readonly ArcCommand $arc) {}

    public function account(string $user): NodeAccount
    {
        return NodeAccount::from($this->arc->json('v-list-user-node-versions', [$user]));
    }

    /**
     * Majors that can be installed, newest first, with whether $account's
     * package allows each.
     *
     * @return list<array{major: string, latest: string, lts: string, status: string, eol: string, cached: bool, allowed: bool}>
     */
    public function releases(NodeAccount $account): array
    {
        $releases = [];
        foreach ((array) ($this->arc->json('v-list-sys-node-releases')['RELEASES'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $release = [
                'major' => (string) ($row['MAJOR'] ?? ''),
                'latest' => (string) ($row['LATEST'] ?? ''),
                'lts' => (string) ($row['LTS'] ?? ''),
                'status' => (string) ($row['STATUS'] ?? ''),
                'eol' => (string) ($row['EOL'] ?? ''),
                'cached' => ($row['CACHED'] ?? '') === 'yes',
            ];
            $release['allowed'] = self::majorAllowed($account->allowedMajors, $release['major'], $release['status']);
            $releases[] = $release;
        }

        return $releases;
    }

    /** NODE_ALLOWED: lts (active and maintenance LTS), * or a list of majors. */
    public static function majorAllowed(string $allowed, string $major, string $status): bool
    {
        return match ($allowed) {
            '*' => true,
            'lts' => in_array($status, ['active', 'maintenance'], true),
            default => in_array($major, explode(',', $allowed), true),
        };
    }

    /**
     * @return array{checked: string, packages: list<array{name: string, version: string, latest: string, outdated: string, bundled: bool}>}
     */
    public function packages(string $user, string $version): array
    {
        $data = $this->arc->json('v-list-user-npm-packages', [$user, $version]);
        $packages = [];
        foreach ((array) ($data['PACKAGES'] ?? []) as $row) {
            if (is_array($row)) {
                $packages[] = [
                    'name' => (string) ($row['NAME'] ?? ''),
                    'version' => (string) ($row['VERSION'] ?? ''),
                    'latest' => (string) ($row['LATEST'] ?? ''),
                    'outdated' => (string) ($row['OUTDATED'] ?? ''),
                    'bundled' => ($row['BUNDLED'] ?? '') === 'yes',
                ];
            }
        }

        return ['checked' => (string) ($data['CHECKED'] ?? ''), 'packages' => $packages];
    }

    public function task(string $user, int $lines = 100): NodeTask
    {
        return NodeTask::from($this->arc->json('v-list-user-node-task', [$user, (string) $lines]));
    }

    public function enable(string $user): void
    {
        $this->arc->run('v-add-user-nvm', [$user]);
    }

    public function disable(string $user): void
    {
        $this->arc->run('v-delete-user-nvm', [$user]);
    }

    public function setDefault(string $user, string $version): void
    {
        $this->arc->run('v-change-user-node-default', [$user, $version]);
    }

    public function deleteVersion(string $user, string $version): void
    {
        $this->arc->run('v-delete-user-node-version', [$user, $version]);
    }

    public function deletePackage(string $user, string $version, string $name): void
    {
        $this->arc->run('v-delete-user-npm-package', [$user, $version, $name]);
    }

    /**
     * Start a background task; returns its id.
     *
     * @param  list<string>  $args
     */
    public function startTask(string $user, string $action, array $args): string
    {
        return trim($this->arc->run('v-start-user-node-task', [$user, $action, ...$args])->stdout);
    }
}
