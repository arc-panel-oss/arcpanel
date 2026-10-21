<?php

namespace App\Services\Keys;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Arc\Secret;

/**
 * The secret store of a user (E38) through the v-*-user-secret commands.
 * Values only travel into the commands, in a Secret file; no command the
 * panel runs prints one.
 */
final class UserSecretManager
{
    /**
     * D3: one to five /segments of [A-Za-z0-9_.-], at most 128 characters.
     * "/" delimiters: the API document keeps any other one in its pattern.
     */
    public const NAME_PATTERN = '/^(\/[A-Za-z0-9_.-]{1,64}){1,5}$/';

    /** D4: 32 KiB. */
    public const MAX_VALUE = 32768;

    public function __construct(private readonly ArcCommand $arc) {}

    public static function validName(string $name): bool
    {
        if (strlen($name) > 128 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return false;
        }
        foreach (explode('/', substr($name, 1)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public static function reference(string $name): string
    {
        return '{{secret:'.$name.'}}';
    }

    /**
     * `v-list-user-secret-store USER json`: name => row (NAME, DESCRIPTION,
     * VERSION, FINGERPRINT, USED_BY count, TIME, DATE).
     *
     * @return array<string, array<string, string>>
     */
    public function forUser(string $user): array
    {
        $rows = [];
        foreach ($this->arc->json('v-list-user-secret-store', [$user]) as $name => $row) {
            if (is_array($row)) {
                $rows[(string) $name] = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $row);
            }
        }

        return $rows;
    }

    /**
     * Names of the user's secrets, for the reference pickers: `USAGE=no`
     * skips reading the apps.
     *
     * @return list<string>
     */
    public function names(string $user): array
    {
        $result = $this->arc->run('v-list-user-secret-store', [$user, 'json', 'no'], Options::allowFailure());
        $data = $result->ok() ? json_decode($result->stdout, true) : null;

        return is_array($data) ? array_map('strval', array_keys($data)) : [];
    }

    /**
     * `v-list-user-secret USER NAME json`, or null when there is no such
     * secret.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $user, string $name): ?array
    {
        if (! self::validName($name)) {
            return null;
        }
        $result = $this->arc->run('v-list-user-secret', [$user, $name, 'json'], Options::allowFailure());
        if ($result->exit() === ExitCode::NotExist) {
            return null;
        }
        $data = json_decode($result->stdout, true);

        return is_array($data) && is_array($data[$name] ?? null) ? $data[$name] : null;
    }

    /** `v-add-user-secret USER NAME VALUE_FILE [DESCRIPTION]` */
    public function create(string $user, string $name, string $value, string $description): CommandResult
    {
        return $this->arc->run('v-add-user-secret', [$user, $name, Secret::file($value), $description], Options::allowFailure());
    }

    /** `v-change-user-secret USER NAME VALUE_FILE RESTART`; prints the apps that use it */
    public function replace(string $user, string $name, string $value, bool $restart): CommandResult
    {
        return $this->arc->run('v-change-user-secret', [$user, $name, Secret::file($value), $restart ? 'yes' : 'no'], Options::allowFailure());
    }

    /** `v-rollback-user-secret USER NAME VERSION RESTART` */
    public function rollback(string $user, string $name, string $version, bool $restart): CommandResult
    {
        return $this->arc->run('v-rollback-user-secret', [$user, $name, $version, $restart ? 'yes' : 'no'], Options::allowFailure());
    }

    /** `v-change-user-secret-description USER NAME DESCRIPTION` */
    public function describe(string $user, string $name, string $description): CommandResult
    {
        return $this->arc->run('v-change-user-secret-description', [$user, $name, $description], Options::allowFailure());
    }

    /** `v-delete-user-secret USER NAME [FORCE]` */
    public function delete(string $user, string $name, bool $force): CommandResult
    {
        return $this->arc->run('v-delete-user-secret', $force ? [$user, $name, 'yes'] : [$user, $name], Options::allowFailure());
    }

    /**
     * The apps a change command printed (node:DOMAIN, docker:NAME, app:DOMAIN/NAME).
     *
     * @return list<string>
     */
    public static function usedBy(CommandResult|string $result): array
    {
        return self::matching($result, '/^((?:node|docker|app):\S+)$/');
    }

    /**
     * The apps a change command could not restart (`failed:APP` lines).
     *
     * @return list<string>
     */
    public static function failedRestarts(CommandResult|string $result): array
    {
        return self::matching($result, '/^failed:((?:node|docker|app):\S+)$/');
    }

    /** @return list<string> */
    private static function matching(CommandResult|string $result, string $pattern): array
    {
        $lines = $result instanceof CommandResult ? $result->lines() : (preg_split('/\R/', trim($result)) ?: []);
        $apps = [];
        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $m) === 1) {
                $apps[] = $m[1];
            }
        }

        return $apps;
    }

    /** The command's message without the "Error: " prefix of func/main.sh. */
    public static function reason(CommandResult $result): string
    {
        return (string) preg_replace('/^Error:\s*/', '', $result->errorMessage());
    }
}
