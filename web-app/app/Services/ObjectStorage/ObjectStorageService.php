<?php

namespace App\Services\ObjectStorage;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;
use App\Arc\SysConfig;
use App\Services\Support\ObjectLookup;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;

/**
 * Object storage on Silo through the v-* commands (E44 spec §6–§11): the
 * buckets and S3 keys of an account, and the service of the server.
 */
final class ObjectStorageService
{
    /** @var array<string, array<string, Bucket>> per-request cache */
    private array $buckets = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly ObjectLookup $lookup,
        private readonly PlanRunner $plans,
        private readonly SysConfig $config,
    ) {}

    /** Whether Silo is installed on the server (OBJECT_STORAGE_SYSTEM). */
    public function installed(): bool
    {
        return $this->config->get('OBJECT_STORAGE_SYSTEM') === 'silo';
    }

    /** `v-list-sys-silo json` */
    public function server(): SiloStatus
    {
        return SiloStatus::fromArray($this->arc->json('v-list-sys-silo'));
    }

    /** `v-list-user-object-storage USER json` */
    public function account(string $user): AccountStorage
    {
        return AccountStorage::fromArray($this->arc->json('v-list-user-object-storage', [$user]));
    }

    /** @return array<string, Bucket> name => bucket, sorted by name */
    public function buckets(string $user): array
    {
        if (! isset($this->buckets[$user])) {
            $list = [];
            foreach ($this->arc->json('v-list-user-buckets', [$user]) as $name => $row) {
                if (is_array($row)) {
                    $list[(string) $name] = Bucket::fromArray($user, (string) $name, $row);
                }
            }
            ksort($list, SORT_STRING);
            $this->buckets[$user] = $list;
        }

        return $this->buckets[$user];
    }

    /** One bucket; 404 when the user has no such bucket. */
    public function bucket(string $user, string $name): Bucket
    {
        return Bucket::fromArray($user, $name, $this->lookup->one('v-list-user-bucket', [$user, $name], $name));
    }

    /** @return array<string, S3Key> key ID => key, newest first */
    public function keys(string $user): array
    {
        $list = [];
        foreach ($this->arc->json('v-list-user-s3-keys', [$user]) as $key => $row) {
            if (is_array($row)) {
                $list[(string) $key] = S3Key::fromArray($user, (string) $key, $row);
            }
        }
        uasort($list, fn (S3Key $a, S3Key $b) => strcmp($b->date.' '.$b->time, $a->date.' '.$a->time));

        return $list;
    }

    /** `v-add-user-bucket USER BUCKET QUOTA VERSIONING` */
    public static function planCreateBucket(string $user, string $name, int $quotaMb, bool $versioning): Step
    {
        return new Step(sprintf(__('Add bucket %s'), $name), 'v-add-user-bucket', [$user, $name, (string) $quotaMb, $versioning ? 'yes' : 'no']);
    }

    /** `v-change-user-bucket USER BUCKET QUOTA VERSIONING` */
    public static function planUpdateBucket(Bucket $bucket, int $quotaMb, bool $versioning): Step
    {
        return new Step(sprintf(__('Change bucket %s'), $bucket->name), 'v-change-user-bucket', [$bucket->user, $bucket->name, (string) $quotaMb, $versioning ? 'yes' : 'no']);
    }

    /** `v-delete-user-bucket USER BUCKET` */
    public static function planDeleteBucket(string $user, string $name): Step
    {
        return new Step(sprintf(__('Delete bucket %s and its objects'), $name), 'v-delete-user-bucket', [$user, $name]);
    }

    /** `v-add-user-s3-key USER NAME BUCKET ACCESS EXPIRES` (dry runs only; the answer holds the secret) */
    public static function planCreateKey(string $user, string $name, string $bucket, string $access, string $expires): Step
    {
        return new Step(sprintf(__('Add S3 key %s'), $name), 'v-add-user-s3-key', [$user, $name, $bucket, $access, $expires]);
    }

    /** `v-delete-user-s3-key USER KEY` */
    public static function planDeleteKey(string $user, string $key): Step
    {
        return new Step(sprintf(__('Delete S3 key %s'), $key), 'v-delete-user-s3-key', [$user, $key]);
    }

    /**
     * `v-add-user-s3-key USER NAME BUCKET ACCESS EXPIRES json`: the key ID
     * and its secret, which no command can give again.
     *
     * @return array{0: CommandResult, 1: ?string, 2: ?string} result, key ID, secret
     */
    public function createKey(string $user, string $name, string $bucket, string $access, string $expires): array
    {
        $result = $this->arc->run('v-add-user-s3-key', [$user, $name, $bucket, $access, $expires, 'json'], Options::allowFailure());
        if (! $result->ok()) {
            return [$result, null, null];
        }
        $data = json_decode($result->stdout, true);

        return [$result, is_array($data) ? (string) ($data['KEY'] ?? '') : null, is_array($data) ? (string) ($data['SECRET'] ?? '') : null];
    }

    /** `v-add-user-secret USER NAME VALUE_FILE DESCRIPTION`: an S3 secret saved in the secret store (E38). */
    public function saveSecret(string $user, string $name, string $key, string $secret): CommandResult
    {
        return $this->arc->run('v-add-user-secret', [$user, $name, Secret::file($secret), sprintf('S3 key %s', $key)], Options::allowFailure());
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        $this->buckets = [];

        return $this->plans->apply($steps);
    }
}
