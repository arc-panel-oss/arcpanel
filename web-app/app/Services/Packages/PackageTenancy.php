<?php

namespace App\Services\Packages;

use App\Arc\ArcCommand;
use App\Arc\Options;

/**
 * The tenancy keys of a package (`v-list-package-tenancy PACKAGE json`),
 * read once per package and request. The grants of Node.js, Docker, app
 * processes and object storage and the PHP limits all read the same
 * answer; a page like the web domain editor asked for it five times.
 * The commands check the keys again.
 */
final class PackageTenancy
{
    /** @var array<string, array<string, mixed>> package => keys */
    private array $packages = [];

    public function __construct(
        private readonly ArcCommand $arc,
    ) {}

    /** @return array<string, mixed> the keys of $package; empty when it has none or does not exist */
    public function of(string $package): array
    {
        if ($package === '') {
            return [];
        }

        return $this->packages[$package] ??= $this->read($package);
    }

    /** Whether the key $key of $package is `yes`. */
    public function allows(string $package, string $key): bool
    {
        return ($this->of($package)[$key] ?? '') === 'yes';
    }

    /** @return array<string, mixed> */
    private function read(string $package): array
    {
        $result = $this->arc->run('v-list-package-tenancy', [$package, 'json'], Options::allowFailure());
        $data = $result->ok() ? json_decode($result->stdout, true) : null;

        return is_array($data) && is_array($data[$package] ?? null) ? $data[$package] : [];
    }
}
