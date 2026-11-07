<?php

namespace App\Services\Node;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Services\Packages\PackageTenancy;

/**
 * Whether a user's package includes Node.js (package key NODE, E13 spec
 * D10): `v-list-user USER json` and `v-list-package-tenancy PACKAGE json`,
 * once per account and request.
 * The policy and the sidebar read it; the commands check it again.
 */
final class NodeAccess
{
    /** @var array<string, bool> package => NODE='yes' */
    private array $packages = [];

    /** @var array<string, string> user => package */
    private array $users = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly PackageTenancy $tenancy,
    ) {}

    public function allowed(string $user): bool
    {
        $package = $this->users[$user] ??= $this->packageOf($user);
        if ($package === '') {
            return false;
        }

        return $this->packages[$package] ??= $this->packageAllows($package);
    }

    private function packageOf(string $user): string
    {
        $data = $this->arc->json('v-list-user', [$user], Options::allowFailure());

        return (string) ($data[$user]['PACKAGE'] ?? '');
    }

    private function packageAllows(string $package): bool
    {
        return $this->tenancy->allows($package, 'NODE');
    }
}
