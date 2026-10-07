<?php

namespace App\Services\Users;

use App\Arc\ArcCommand;
use App\Arc\ArcCommandException;
use App\Arc\ExitCode;
use App\Arc\Options;
use App\Arc\SysConfig;
use App\Auth\Role;
use App\Services\UserDirectory;
use App\Services\WebDomains\ApplyResult;
use App\Services\WebDomains\Step;
use App\Tenancy\TenancyDirectory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Users and customers (E11 spec §7): reads users and their owners, plans the
 * commands of each form and runs them. Policies decide who may do what; the
 * reseller commands check ownership and quota again on the server.
 */
final class UserService
{
    /** Bulk action => command */
    public const BULK_COMMANDS = [
        'suspend' => 'v-suspend-user',
        'unsuspend' => 'v-unsuspend-user',
        'delete' => 'v-delete-user',
    ];

    public function __construct(
        private readonly ArcCommand $arc,
        private readonly UserDirectory $directory,
        private readonly TenancyDirectory $tenancy,
        private readonly SysConfig $config,
    ) {}

    /** @return array<string, UserRecord> every user with its owner, by name */
    public function all(): array
    {
        $records = [];
        foreach ($this->directory->all() as $name => $data) {
            $records[$name] = UserRecord::from($name, $data, $this->tenancy->ownerOf($name));
        }

        return $records;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, UserRecord>
     */
    public function only(array $names): array
    {
        return array_intersect_key($this->all(), array_flip($names));
    }

    public function find(string $name): UserRecord
    {
        $result = $this->arc->run('v-list-user', [$name, 'json'], Options::allowFailure());
        if (in_array($result->exit(), [ExitCode::NotExist, ExitCode::Args, ExitCode::Invalid], true)) {
            throw new NotFoundHttpException;
        }
        if (! $result->ok()) {
            throw ArcCommandException::failed($result);
        }
        $data = json_decode($result->stdout, true);
        if (! isset($data[$name]) || ! is_array($data[$name])) {
            throw new NotFoundHttpException;
        }

        return UserRecord::from($name, $data[$name], $this->tenancy->ownerOf($name));
    }

    /**
     * Packages the principal may give a user: every package but "system" for
     * administrators, the assignable ones for a reseller.
     *
     * @return array<string, array<string, string>> name => limits
     */
    public function assignablePackages(Role $role, string $principal): array
    {
        $packages = $role === Role::Reseller
            ? $this->arc->json('v-list-reseller-packages', [$principal])
            : $this->arc->json('v-list-package-tenancy', ['all']);
        unset($packages['system']);

        $clean = [];
        foreach ($packages as $name => $limits) {
            if (is_string($name) && is_array($limits)) {
                $clean[$name] = array_map('strval', array_filter($limits, 'is_scalar'));
            }
        }
        ksort($clean);

        return $clean;
    }

    /** First key of the reseller's plan that has no room for $package, or null. */
    public function quotaProblem(string $reseller, string $package, ?string $user = null): ?string
    {
        $result = $this->arc->run('v-check-reseller-quota', array_values(array_filter([$reseller, $package, $user])), Options::allowFailure());
        if ($result->ok()) {
            return null;
        }
        $key = trim($result->stdout);

        return preg_match('/^[A-Z_]+$/', $key) === 1 ? $key : 'USERS';
    }

    /**
     * Allocation of a reseller's plan (spec D7): own usage plus the limits of
     * its customers' packages, per key, against its own limits.
     *
     * @return array<string, array{allocated: int|null, limit: string}> null allocated = unlimited
     */
    public function allocation(UserRecord $reseller): array
    {
        $customers = $this->only($this->tenancy->customersOf($reseller->name));
        $rows = [];
        foreach (UserRecord::USAGE as $key => $usageKey) {
            $allocated = (int) $reseller->get($usageKey, '0');
            foreach ($customers as $customer) {
                $value = $customer->get($key, '0');
                if ($value === 'unlimited') {
                    $allocated = null;
                    break;
                }
                $allocated += (int) $value;
            }
            $rows[$key] = ['allocated' => $allocated, 'limit' => $reseller->get($key, 'unlimited')];
        }
        $package = $this->packageTenancy($reseller->package());
        $rows = ['USERS' => ['allocated' => count($customers), 'limit' => $package['USERS'] ?? '0']] + $rows;

        return $rows;
    }

    /** @return array<string, string> tenancy keys of a package */
    public function packageTenancy(string $package): array
    {
        $data = $this->arc->json('v-list-package-tenancy', [$package], Options::allowFailure());

        return isset($data[$package]) && is_array($data[$package]) ? array_map('strval', array_filter($data[$package], 'is_scalar')) : [];
    }

    /** @return array<string, string> code => code */
    public function languages(): array
    {
        return $this->stringOptions($this->arc->json('v-list-sys-languages', [], Options::allowFailure()));
    }

    /** @return array<string, string> */
    public function shells(): array
    {
        return $this->stringOptions($this->arc->json('v-list-sys-shells', [], Options::allowFailure()));
    }

    /** @return array<string, string> */
    public function phpVersions(): array
    {
        return $this->stringOptions($this->arc->json('v-list-sys-php', [], Options::allowFailure()));
    }

    /**
     * Run the steps in order; the first failure stops the run and steps
     * before it stay applied.
     *
     * @param  list<Step>  $steps
     */
    public function apply(array $steps): ApplyResult
    {
        $applied = [];
        foreach ($steps as $step) {
            try {
                $this->arc->run($step->command, $step->args);
            } catch (ArcCommandException $e) {
                $this->forget();

                return new ApplyResult($applied, $step, $e->userMessage(), $e->getCode());
            }
            $applied[] = $step;
            if ($step->onSuccess !== null) {
                ($step->onSuccess)();
            }
        }
        if ($applied !== []) {
            $this->forget();
        }

        return new ApplyResult($applied);
    }

    /**
     * Suspend, unsuspend or delete users: every item runs even when an
     * earlier one failed.
     *
     * @param  list<string>  $users
     * @return array{done: list<string>, failed: array<string, string>}
     */
    public function bulk(string $action, array $users): array
    {
        $command = self::BULK_COMMANDS[$action] ?? throw new \InvalidArgumentException("Unknown action {$action}");
        $done = [];
        $failed = [];
        foreach ($users as $user) {
            $result = $this->arc->run($command, [$user], Options::allowFailure());
            if ($result->ok()) {
                $done[] = $user;
            } else {
                $failed[$user] = $result->message() !== '' ? $result->message() : sprintf(__('Error code: %s'), $result->exitCode);
            }
        }
        $this->forget();

        return ['done' => $done, 'failed' => $failed];
    }

    /** Recompute the usage totals of a reseller after a change in its tree. */
    public function updateCounters(string $reseller): void
    {
        if ($reseller !== '' && $this->tenancy->isReseller($reseller)) {
            $this->arc->run('v-update-reseller-counters', [$reseller], Options::allowFailure());
        }
    }

    public function forget(): void
    {
        $this->directory->forget();
        $this->tenancy->forget();
    }

    /**
     * Name of a package as its owner sees it: a reseller's own packages are
     * stored as RESELLER_NAME (spec D8).
     */
    public static function packageLabel(string $package, string $reseller): string
    {
        $prefix = $reseller.'_';

        return $reseller !== '' && str_starts_with($package, $prefix) ? substr($package, strlen($prefix)) : $package;
    }

    /**
     * @param  array<mixed>  $list
     * @return array<string, string>
     */
    private function stringOptions(array $list): array
    {
        $values = array_values(array_filter($list, 'is_string'));

        return array_combine($values, $values);
    }
}
