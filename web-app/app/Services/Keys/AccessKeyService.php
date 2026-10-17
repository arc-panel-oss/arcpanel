<?php

namespace App\Services\Keys;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;
use App\Services\Support\ObjectLookup;

/**
 * API access keys through the v-* commands, as web/list|add|delete|bulk/
 * access-key call them. Secrets never leave this class except in the
 * answer of create() (spec §8.2).
 */
final class AccessKeyService
{
    /** The comment that `v-add-access-key` takes (is_comment_format_valid of func/main.sh). */
    public const COMMENT = '/^[[:alnum:]][[:alnum:][:space:]._-]{0,64}[[:alnum:]]$/';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly ObjectLookup $lookup,
        private readonly BulkRunner $runner,
    ) {}

    /** @return list<AccessKey> oldest first, as web/list/access-key sorts them */
    public function forUser(string $user): array
    {
        $keys = [];
        foreach ($this->arc->json('v-list-access-keys', [$user]) as $row) {
            if (is_array($row)) {
                $keys[] = AccessKey::fromArray($row);
            }
        }
        usort($keys, fn (AccessKey $a, AccessKey $b) => [$a->date, $a->time] <=> [$b->date, $b->time]);

        return $keys;
    }

    /** One key of the user, without its secret; 404 when it is missing or someone else's. */
    public function find(string $user, string $key): AccessKey
    {
        $record = AccessKey::fromArray($this->lookup->decode('v-list-access-key', [$key]));
        abort_unless($record->id === $key && $record->user === $user, 404);

        return $record;
    }

    /** @return array<string, mixed> permission sets of `v-list-apis json` */
    public function permissionSets(): array
    {
        return $this->arc->json('v-list-apis');
    }

    /**
     * `v-add-access-key USER SETS COMMENT json`, or with ALLOWED_IPS and
     * EXPIRES before json for a restricted key (E43 B6); the record carries
     * the secret, to show once.
     *
     * @param  list<string>  $sets
     * @param  list<string>  $allowedIps
     */
    public function create(string $user, array $sets, string $comment, array $allowedIps = [], ?int $expires = null): AccessKey|CommandResult
    {
        $args = $allowedIps === [] && $expires === null
            ? [$user, implode(',', $sets), $comment, 'json']
            : [$user, implode(',', $sets), $comment, implode(',', $allowedIps), $expires === null ? '' : (string) $expires, 'json'];
        $result = $this->arc->run('v-add-access-key', $args, Options::allowFailure());
        if (! $result->ok()) {
            return $result;
        }
        $row = json_decode($result->stdout, true);

        return AccessKey::fromArray(is_array($row) ? $row : [], withSecret: true);
    }

    /** @param  list<string>  $keys */
    public function delete(array $keys): BulkResult
    {
        return $this->runner->run($keys, fn (string $key) => [$key, 'v-delete-access-key', [$key]]);
    }
}
