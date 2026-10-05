<?php

namespace App\Services\Docker;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;

/**
 * Whether Docker apps are available to a user (E4 spec §6.2): the engine is
 * installed on the server (DOCKER_SYSTEM) and the user's package has
 * DOCKER='yes' (`v-list-user USER json`, `v-list-package-tenancy PACKAGE
 * json`), once per account and request. The policy and the sidebar read it;
 * the commands check it again.
 */
final class DockerAccess
{
    /** @var array<string, bool> package => DOCKER='yes' */
    private array $packages = [];

    /** @var array<string, string> user => package */
    private array $users = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
    ) {}

    /** The Docker engine is installed (v-add-sys-docker). */
    public function engine(): bool
    {
        return $this->config->is('DOCKER_SYSTEM');
    }

    /** The package of $user includes Docker apps. */
    public function allowed(string $user): bool
    {
        $package = $this->users[$user] ??= $this->packageOf($user);
        if ($package === '') {
            return false;
        }

        return $this->packages[$package] ??= $this->packageAllows($package);
    }

    /** Engine installed and package allows: new apps may be installed. */
    public function available(string $user): bool
    {
        return $this->engine() && $this->allowed($user);
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

        return is_array($data) && (($data[$package]['DOCKER'] ?? '') === 'yes');
    }
}
