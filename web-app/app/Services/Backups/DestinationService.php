<?php

namespace App\Services\Backups;

use App\Arc\ArcCommand;
use App\Arc\CommandResult;
use App\Arc\Options;
use App\Arc\Secret;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Backup destinations through their v-* commands (E48 spec §4): list, add,
 * change, delete, check and the recovery kit. Secrets go to the commands as
 * a Secret directory, one file per secret field, and are never read back.
 */
final class DestinationService
{
    public function __construct(private readonly ArcCommand $arc) {}

    /** @return array<string, BackupDestination> by name */
    public function all(): array
    {
        $list = [];
        foreach ($this->arc->json('v-list-backup-destinations', [], Options::allowFailure()) as $name => $row) {
            if (is_string($name) && is_array($row)) {
                $list[$name] = BackupDestination::fromArray($name, $row);
            }
        }
        ksort($list);

        return $list;
    }

    /** One destination; 404 when there is none of that name. */
    public function find(string $name): BackupDestination
    {
        $destination = $this->all()[$name] ?? null;
        if ($destination === null) {
            throw new NotFoundHttpException;
        }

        return $destination;
    }

    /**
     * @param  array<string, string>  $fields  KEY => value of the driver's and the common fields
     * @param  array<string, string>  $secrets  KEY => value; only the secrets given are written
     */
    public function add(string $name, string $driver, array $fields, array $secrets): CommandResult
    {
        return $this->arc->run('v-add-backup-destination', self::args($name, $driver, $fields, $secrets), Options::allowFailure());
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $secrets  only the secrets that change
     */
    public function change(string $name, array $fields, array $secrets): CommandResult
    {
        return $this->arc->run('v-change-backup-destination', self::args($name, null, $fields, $secrets), Options::allowFailure());
    }

    public function delete(string $name): CommandResult
    {
        return $this->arc->run('v-delete-backup-destination', [$name], Options::allowFailure());
    }

    /**
     * Test the connection; an SFTP destination needs ACCEPT, the fingerprint
     * the administrator confirmed, the first time.
     *
     * @return array{ok: bool, error: string, hostKey: string, latency: string}
     */
    public function check(string $name, string $accept = ''): array
    {
        $result = $this->arc->run('v-check-backup-destination', [$name, $accept, 'json'], Options::allowFailure());
        $row = json_decode($result->stdout, true)[$name] ?? null;
        if (! is_array($row)) {
            return ['ok' => false, 'error' => $result->errorMessage(), 'hostKey' => '', 'latency' => ''];
        }

        return [
            'ok' => ($row['OK'] ?? '') === 'yes',
            'error' => (string) ($row['ERROR'] ?? ''),
            'hostKey' => (string) ($row['HOST_KEY'] ?? ''),
            'latency' => (string) ($row['LATENCY_MS'] ?? ''),
        ];
    }

    /** The recovery kit of NAME (printed by the command, never stored by the panel). */
    public function recoveryKit(string $name): CommandResult
    {
        return $this->arc->run('v-export-backup-destination-key', [$name], Options::allowFailure());
    }

    /**
     * The arguments of v-add-backup-destination (NAME and DRIVER given) or
     * v-change-backup-destination (NAME only).
     *
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $secrets
     * @return list<string|Secret>
     */
    public static function args(string $name, ?string $driver, array $fields, array $secrets): array
    {
        $args = [$name, ...($driver !== null ? [$driver] : []), Secret::file(self::optionsFile($fields))];
        if ($secrets !== []) {
            $args[] = Secret::directory($secrets);
        }

        return $args;
    }

    /** @param  array<string, string>  $fields */
    private static function optionsFile(array $fields): string
    {
        $lines = '';
        foreach ($fields as $key => $value) {
            $lines .= $key."='".str_replace(["'", "\n", "\r"], '', $value)."'\n";
        }

        return $lines;
    }
}
