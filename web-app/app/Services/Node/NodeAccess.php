<?php

namespace App\Services\Node;

use App\Arc\ArcCommand;
use App\Arc\Options;

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
        $result = $this->arc->run('v-list-package-tenancy', [$package, 'json'], Options::allowFailure());
        if (! $result->ok()) {
            return false;
        }
        $data = json_decode($result->stdout, true);

        return is_array($data) && (($data[$package]['NODE'] ?? '') === 'yes');
    }
}
