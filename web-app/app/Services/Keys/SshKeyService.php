<?php

namespace App\Services\Keys;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;

/**
 * SSH keys of a user's authorized_keys through the v-* commands, as
 * web/list|add|delete/key call them.
 */
final class SshKeyService
{
    /** The key the file manager adds for itself (E3): neither listed nor counted. */
    public const FILE_MANAGER_KEY = 'filemanager.ssh.key';

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly BulkRunner $runner,
    ) {}

    /**
     * `v-list-user-ssh-key USER json`: ID (the key comment) => fingerprint.
     * A user without authorized_keys prints nothing.
     *
     * @return array<string, string>
     */
    public function forUser(string $user): array
    {
        $result = $this->arc->run('v-list-user-ssh-key', [$user, 'json']);
        if (trim($result->stdout) === '') {
            return [];
        }
        $data = json_decode($result->stdout, true);
        if (! is_array($data)) {
            throw new ArcCommandException('v-list-user-ssh-key returned invalid JSON', $result);
        }

        $keys = [];
        foreach ($data as $row) {
            if (is_array($row) && ($row['ID'] ?? '') !== self::FILE_MANAGER_KEY) {
                $keys[(string) ($row['ID'] ?? '')] = (string) ($row['KEY'] ?? '');
            }
        }

        return $keys;
    }

    /**
     * The key line as web/add/key sends it: a missing comment gets an ID
     * (md5 of the time), and null when the ID or the key is already there.
     *
     * @param  array<string, string>  $existing  forUser()
     */
    public static function prepare(string $key, array $existing): ?string
    {
        $key = trim($key);
        $parts = preg_split('/\s+/', $key) ?: [];
        if (count($parts) < 3) {
            $key .= ' '.md5((string) time());
            $parts[] = md5((string) time());
        }
        $id = implode(' ', array_slice($parts, 2));
        if (array_key_exists($id, $existing) || in_array($parts[1] ?? '', $existing, true)) {
            return null;
        }

        return $key;
    }

    /**
     * The SHA256 fingerprint of a public key line as `ssh-keygen -l`
     * prints it (`SHA256:…`), or null when the key part is not base64.
     */
    public static function fingerprint(string $line): ?string
    {
        $blob = base64_decode((string) (preg_split('/\s+/', trim($line))[1] ?? ''), true);
        if ($blob === false || $blob === '') {
            return null;
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /** `v-add-user-ssh-key USER KEY` */
    public function create(string $user, string $key): CommandResult
    {
        return $this->arc->run('v-add-user-ssh-key', [$user, $key], Options::allowFailure());
    }

    /** `v-delete-user-ssh-key USER ID` */
    public function delete(string $user, string $id): BulkResult
    {
        return $this->runner->run([$id], fn (string $key) => [$key, 'v-delete-user-ssh-key', [$user, $key]]);
    }
}
