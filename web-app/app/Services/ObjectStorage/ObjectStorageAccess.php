<?php

namespace App\Services\ObjectStorage;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\SysConfig;

/**
 * Whether object storage is available to a user (E44 spec §11): Silo is
 * installed (OBJECT_STORAGE_SYSTEM) and the user's package has
 * OBJECT_STORAGE='yes', once per account and request. The sidebar reads it;
 * the commands check it again.
 */
final class ObjectStorageAccess
{
    /** @var array<string, bool> package => OBJECT_STORAGE='yes' */
    private array $packages = [];

    /** @var array<string, string> user => package */
    private array $users = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
    ) {}

    public function installed(): bool
    {
        return $this->config->get('OBJECT_STORAGE_SYSTEM') === 'silo';
    }

    /** The package of $user includes object storage. */
    public function allowed(string $user): bool
    {
        $package = $this->users[$user] ??= (string) ($this->arc->json('v-list-user', [$user], Options::allowFailure())[$user]['PACKAGE'] ?? '');
        if ($package === '') {
            return false;
        }

        return $this->packages[$package] ??= $this->packageAllows($package);
    }

    private function packageAllows(string $package): bool
    {
        $result = $this->arc->run('v-list-package-tenancy', [$package, 'json'], Options::allowFailure());
        $data = $result->ok() ? json_decode($result->stdout, true) : null;

        return is_array($data) && (($data[$package]['OBJECT_STORAGE'] ?? '') === 'yes');
    }
}
