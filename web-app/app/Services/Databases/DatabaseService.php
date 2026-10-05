<?php

namespace App\Services\Databases;

use App\Arc\ArcCommand;
use App\Arc\Options;
use App\Arc\Secret;
use App\Arc\SysConfig;
use App\Services\Support\BulkResult;
use App\Services\Support\BulkRunner;
use App\Services\Support\ObjectLookup;
use App\Services\Support\PlanRunner;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;

/**
 * Databases through the v-* commands, with the arguments and order of the
 * legacy pages (web/list/db, web/add/db, web/edit/db, web/bulk/db,
 * web/download/database).
 */
final class DatabaseService
{
    /** Legacy charset list of templates/pages/add_db.php. */
    public const CHARSETS = [
        'big5', 'dec8', 'cp850', 'hp8', 'koi8r', 'latin1', 'latin2', 'swe7', 'ascii', 'ujis', 'sjis', 'hebrew',
        'tis620', 'euckr', 'koi8u', 'gb2312', 'greek', 'cp1250', 'gbk', 'latin5', 'armscii8', 'utf8', 'utf8mb4',
        'ucs2', 'cp866', 'keybcs2', 'macce', 'macroman', 'cp852', 'latin7', 'cp1251', 'cp1256', 'cp1257',
        'binary', 'geostd8', 'cp932', 'eucjpms',
    ];

    public const BULK_COMMANDS = [
        'suspend' => 'v-suspend-database',
        'unsuspend' => 'v-unsuspend-database',
        'rebuild' => 'v-rebuild-database',
        'delete' => 'v-delete-database',
    ];

    /** The legacy password rule (validate_password in web/inc/main.php). */
    public const PASSWORD_RULE = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(.){8,}$/';

    /** @var array<string, array<string, DatabaseRecord>> per-request cache */
    private array $lists = [];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly SysConfig $config,
        private readonly ObjectLookup $lookup,
        private readonly BulkRunner $runner,
        private readonly PlanRunner $plans,
    ) {}

    /** @return array<string, DatabaseRecord> database => record */
    public function forUser(string $user): array
    {
        if (! isset($this->lists[$user])) {
            $records = [];
            foreach ($this->arc->json('v-list-databases', [$user]) as $database => $row) {
                if (is_string($database) && is_array($row)) {
                    $records[$database] = DatabaseRecord::fromArray($user, $database, $row);
                }
            }
            $this->lists[$user] = $records;
        }

        return $this->lists[$user];
    }

    /**
     * @param  list<string>  $users
     * @return list<DatabaseRecord> sorted by name, then owner
     */
    public function forUsers(array $users): array
    {
        $records = [];
        foreach ($users as $user) {
            array_push($records, ...array_values($this->forUser($user)));
        }
        usort($records, fn (DatabaseRecord $a, DatabaseRecord $b) => [$a->database, $a->user] <=> [$b->database, $b->user]);

        return $records;
    }

    /** One database; 404 when the user has no such database. */
    public function find(string $user, string $database): DatabaseRecord
    {
        return DatabaseRecord::fromArray($user, $database, $this->lookup->one('v-list-database', [$user, $database], $database));
    }

    /** @return list<string> database types of the server (DB_SYSTEM) */
    public function types(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->config->get('DB_SYSTEM')))));
    }

    /** @return list<string> database hosts, unique, in the order of `v-list-database-hosts` */
    public function hosts(): array
    {
        $hosts = [];
        foreach ($this->arc->json('v-list-database-hosts', [], Options::allowFailure()) as $row) {
            $host = is_array($row) ? trim((string) ($row['HOST'] ?? '')) : '';
            if ($host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }

        return $hosts !== [] ? $hosts : ['localhost'];
    }

    /** `v-add-database USER DATABASE DBUSER PASSWORD TYPE HOST CHARSET`; the CLI adds the USER_ prefix. */
    public function planCreate(string $owner, string $database, string $dbUser, string $password, string $type, string $host, string $charset): Step
    {
        return new Step(__('Add the database'), 'v-add-database', [$owner, $database, $dbUser, Secret::file($password), $type, $host, $charset]);
    }

    /**
     * web/edit/db: a new user name (without prefix) and a new password, each
     * only when it changed.
     *
     * @return list<Step>
     */
    public function planUpdate(DatabaseRecord $record, string $dbUser, string $password): array
    {
        $steps = [];
        if ($dbUser !== '' && $dbUser !== $record->shortUser()) {
            $steps[] = new Step(__('Change the database user'), 'v-change-database-user', [$record->user, $record->database, $dbUser]);
        }
        if ($password !== '') {
            $steps[] = new Step(__('Change the password'), 'v-change-database-password', [$record->user, $record->database, Secret::file($password)]);
        }

        return $steps;
    }

    /** @param  list<Step>  $steps */
    public function apply(array $steps): ApplyResult
    {
        $this->lists = [];

        return $this->plans->apply($steps);
    }

    /** @param  list<array{0: string, 1: string}>  $items  [owner, database] */
    public function bulk(string $action, array $items): BulkResult
    {
        $command = self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");
        $this->lists = [];

        // The database commands take no RESTART argument.
        return $this->runner->run($items, fn (array $item) => [$item[1], $command, [$item[0], $item[1]]]);
    }

    /**
     * `v-dump-database USER DATABASE file gzip`: the path of the dump in the
     * backup directory (the command removes it an hour later).
     */
    public function dump(string $user, string $database): string
    {
        return trim((string) ($this->arc->run('v-dump-database', [$user, $database, 'file', 'gzip'])->lines()[0] ?? ''));
    }
}
