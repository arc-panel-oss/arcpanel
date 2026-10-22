<?php

namespace App\Services\Packages;

use App\Arc\SysConfig;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Php\PhpManager;
use App\Services\Users\UserService;
use App\Services\WebDomains\Step;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The checks and plans of the package changes that the Packages page and
 * API v2 share (E11 spec §8, E34 spec D2): which packages a principal sees,
 * the server's templates and shells, and a reseller's own limits, which
 * its packages may not exceed. PackagePolicy decides whether the change is
 * allowed at all; the controllers call it first.
 *
 * Values are the keys of the package file (KEY => value, see
 * PackageService::file()); tenancy, ports, Node.js and Docker keys are
 * those of the package sidecar.
 */
final class PackageActions
{
    public function __construct(
        private readonly PackageService $packages,
        private readonly UserService $users,
        private readonly SysConfig $config,
    ) {}

    /** @return array<string, PackageRecord> what the principal may see: every package for administrators, the assignable ones for a reseller */
    public function visible(Principal $principal): array
    {
        $all = $this->packages->all();
        if ($principal->actingRole() === Role::Admin) {
            return $all;
        }

        return array_intersect_key($all, array_flip($this->packages->assignableBy($principal->effectiveUser())));
    }

    /** @throws NotFoundHttpException */
    public function visibleOr404(Principal $principal, string $package): PackageRecord
    {
        return $this->visible($principal)[$package] ?? throw new NotFoundHttpException;
    }

    /** The reseller acting, or '' for an administrator. */
    public static function resellerOf(Principal $principal): string
    {
        return $principal->actingRole() === Role::Reseller ? $principal->effectiveUser() : '';
    }

    /** The name a reseller's own package is stored under (RESELLER_NAME, E11 spec D8). */
    public static function storedName(Principal $principal, string $name): string
    {
        $reseller = self::resellerOf($principal);

        return $reseller !== '' ? $reseller.'_'.$name : $name;
    }

    /**
     * A new package. $tenancy (USERS, RESELLER_PACKAGES, RESELLER_VISIBLE)
     * is an administrator's; pass null for a reseller.
     *
     * @param  array<string, string>  $values
     * @param  array{USERS: string, RESELLER_PACKAGES: string, RESELLER_VISIBLE: string}|null  $tenancy
     * @param  array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string, NODE_APPS: string}  $node
     * @param  array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}  $docker
     * @return list<Step>
     *
     * @throws ValidationException
     */
    public function planCreate(Principal $principal, string $name, array $values, ?array $tenancy, string $ports, array $node, array $docker, ?array $php = null, ?array $processes = null, ?array $storage = null): array
    {
        $reseller = self::resellerOf($principal);
        if ($this->packages->exists(self::storedName($principal, $name))) {
            throw ValidationException::withMessages(['name' => __('A package with this name exists.')]);
        }
        $this->checkValues($values, [], $reseller);

        return $this->packages->planCreate($name, $values, $reseller === '' ? $tenancy : null, $reseller,
            $this->checkPorts($ports, $reseller), $this->checkNode($node, $reseller), $this->checkDocker($docker, $reseller), $this->checkPhp($php, $reseller),
            $this->checkProcesses($processes, $reseller), $this->checkStorage($storage, $reseller));
    }

    /**
     * Save a package; an administrator may rename it, a reseller keeps the name.
     *
     * @param  array<string, string>  $values
     * @param  array{USERS: string, RESELLER_PACKAGES: string, RESELLER_VISIBLE: string}|null  $tenancy
     * @param  array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string, NODE_APPS: string}  $node
     * @param  array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}  $docker
     * @return list<Step>
     *
     * @throws ValidationException
     */
    public function planUpdate(Principal $principal, PackageRecord $record, string $newName, array $values, ?array $tenancy, string $ports, array $node, array $docker, ?array $php = null, ?array $processes = null, ?array $storage = null): array
    {
        $reseller = self::resellerOf($principal);
        $newName = $reseller !== '' ? $record->name : $newName;
        if ($newName !== $record->name && $this->packages->exists($newName)) {
            throw ValidationException::withMessages(['name' => __('A package with this name exists.')]);
        }
        $this->checkValues($values, $record->data, $reseller);

        return $this->packages->planUpdate($record, $newName, $values, $reseller === '' ? $tenancy : null, $reseller,
            $this->checkPorts($ports, $reseller), $this->checkNode($node, $reseller), $this->checkDocker($docker, $reseller), $this->checkPhp($php, $reseller),
            $this->checkProcesses($processes, $reseller), $this->checkStorage($storage, $reseller));
    }

    /**
     * @return list<Step>
     *
     * @throws ValidationException
     */
    public function planCopy(PackageRecord $record, string $name): array
    {
        if ($this->packages->exists($name)) {
            throw ValidationException::withMessages(['name' => __('A package with this name exists.')]);
        }

        return $this->packages->planCopy($record, $name);
    }

    /** Why the package cannot be deleted (it has users), or null. */
    public function deleteRefusal(PackageRecord $record): ?string
    {
        return $record->users > 0 ? __('This package is used by users and cannot be deleted.') : null;
    }

    /** @return list<Step> */
    public function planDelete(Principal $principal, PackageRecord $record): array
    {
        return $this->packages->planDelete($record, self::resellerOf($principal));
    }

    /** Package file key => list of PackageService::templates() it is chosen from. */
    private const CHOICES = ['WEB_TEMPLATE' => 'web', 'BACKEND_TEMPLATE' => 'backend', 'PROXY_TEMPLATE' => 'proxy', 'DNS_TEMPLATE' => 'dns', 'SHELL' => 'shells'];

    /**
     * The templates and shells a package may use, as PackageService::templates()
     * lists them. A reseller's package (E39 W4 spec §8.1) takes its shell
     * from POLICY_RESELLER_PACKAGE_SHELLS and, while users may not choose
     * templates, the reseller's own templates; a value the package already
     * has stays allowed for the shell.
     *
     * @param  array<string, string>  $current  the package's values before the change
     * @return array<string, list<string>>
     */
    public function choices(string $reseller, array $current = []): array
    {
        $templates = $this->packages->templates();
        if ($reseller === '') {
            return $templates;
        }
        $shells = array_values(array_filter(explode(',', $this->config->get('POLICY_RESELLER_PACKAGE_SHELLS', 'nologin'))));
        $templates['shells'] = array_values(array_filter($templates['shells'],
            fn (string $shell) => in_array($shell, $shells, true) || $shell === ($current['SHELL'] ?? null)));
        $own = $this->users->find($reseller);
        $fixed = [
            'web' => ! $this->config->is('POLICY_USER_EDIT_WEB_TEMPLATES') ? 'WEB_TEMPLATE' : null,
            'backend' => ! $this->config->is('POLICY_USER_EDIT_WEB_TEMPLATES') ? 'BACKEND_TEMPLATE' : null,
            'proxy' => ! $this->config->is('POLICY_USER_EDIT_WEB_TEMPLATES') ? 'PROXY_TEMPLATE' : null,
            'dns' => ! $this->config->is('POLICY_USER_EDIT_DNS_TEMPLATES') ? 'DNS_TEMPLATE' : null,
        ];
        foreach ($fixed as $list => $key) {
            if ($key !== null && $templates[$list] !== []) {
                $templates[$list] = [$own->get($key)];
            }
        }

        return $templates;
    }

    /**
     * Templates and shell from choices() (a value the package already has
     * stays allowed, except a template a reseller may not choose), at least
     * two name servers, and for a reseller no limit beyond its own, compared
     * after their units (E39 W4 spec §8.2).
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $current
     *
     * @throws ValidationException
     */
    public function checkValues(array $values, array $current, string $reseller): void
    {
        $choices = $this->choices($reseller, $current);
        $errors = [];
        foreach (self::CHOICES as $key => $list) {
            $kept = ($current[$key] ?? null) === $values[$key] && ($reseller === '' || $key === 'SHELL');
            if ($choices[$list] !== [] && ! in_array($values[$key], $choices[$list], true) && ! $kept) {
                $errors[strtolower($key === 'SHELL' ? 'shell' : $key)] = $reseller !== '' && in_array($values[$key], $this->packages->templates()[$list], true)
                    ? __('Your plan does not allow this value.')
                    : __('Please select a valid value.');
            }
        }
        if (count(array_filter(explode(',', $values['NS'] ?? ''))) < 2) {
            $errors['nameservers'] = __('Enter at least two name servers.');
        }
        if ($reseller !== '') {
            // v-list-user leaves out RATE_LIMIT and the cgroup limits: those come from the reseller's package
            $own = $this->users->find($reseller);
            $plan = $this->ownPackage($reseller);
            foreach ([...PackageRecord::COUNTED, ...PackageRecord::RESOURCES, 'RATE_LIMIT', 'BACKUPS', 'BACKUPS_INCREMENTAL'] as $key) {
                $limit = $own->get($key) !== '' || ! in_array($key, [...PackageRecord::RESOURCES, 'RATE_LIMIT'], true)
                    ? $own->get($key, 'unlimited')
                    : ($plan?->get($key) ?? '');
                $value = $values[$key] ?? '';
                if ($limit === 'unlimited' || $limit === '' || $value === '' || ! PackageLimits::above($key, $value, $limit)) {
                    continue;
                }
                $errors[strtolower($key)] = $key === 'BACKUPS_INCREMENTAL'
                    ? __('Incremental backups are not included in your plan.')
                    : sprintf(__('At most %s on your plan.'), $limit);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** PORTS of the reseller's own package (E12). */
    public function ownPorts(string $reseller): string
    {
        return $this->ownPackage($reseller)?->get('PORTS', '0') ?? '0';
    }

    /**
     * Node.js keys of the reseller's own package (E13, E14).
     *
     * @return array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string, NODE_APPS: string}
     */
    public function ownNode(string $reseller): array
    {
        $record = $this->ownPackage($reseller);

        return [
            'NODE' => $record?->get('NODE', 'no') ?? 'no',
            'NODE_VERSIONS' => $record?->get('NODE_VERSIONS', '3') ?? '3',
            'NODE_ALLOWED' => $record?->get('NODE_ALLOWED', 'lts') ?? 'lts',
            'NODE_APPS' => $record?->get('NODE_APPS', '3') ?? '3',
        ];
    }

    /**
     * PHP keys of the reseller's own package (E41 §7.5).
     *
     * @return array{PHP_VERSIONS: string, PHP_PROFILES: string, PHP_DEFAULT_PROFILE: string}
     */
    public function ownPhp(string $reseller): array
    {
        return PhpManager::packageKeys($this->ownPackage($reseller)?->data ?? []);
    }

    /**
     * PHP keys of a package (E41 D12): a reseller allows only versions and
     * profiles that its own package allows, and * only when that is *.
     *
     * @param  array{PHP_VERSIONS: string, PHP_PROFILES: string, PHP_DEFAULT_PROFILE: string}|null  $php
     * @return array{PHP_VERSIONS: string, PHP_PROFILES: string, PHP_DEFAULT_PROFILE: string}|null
     *
     * @throws ValidationException
     */
    public function checkPhp(?array $php, string $reseller): ?array
    {
        if ($php === null) {
            return null;
        }
        $errors = [];
        if (! PhpManager::allows($php['PHP_PROFILES'], $php['PHP_DEFAULT_PROFILE'])) {
            $errors['php_default_profile'] = __('The default profile must be one of the allowed profiles.');
        }
        if ($reseller !== '') {
            $own = $this->ownPhp($reseller);
            foreach (['PHP_VERSIONS' => 'php_versions', 'PHP_PROFILES' => 'php_profiles'] as $key => $field) {
                if ($own[$key] === '*') {
                    continue;
                }
                $outside = $php[$key] === '*' ? ['*'] : array_diff(explode(',', $php[$key]), explode(',', $own[$key]));
                if ($outside !== []) {
                    $errors[$field] = sprintf(__('Your plan allows only %s.'), str_replace(',', ', ', $own[$key]));
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $php;
    }

    /**
     * Docker app keys of the reseller's own package (E4).
     *
     * @return array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}
     */
    public function ownDocker(string $reseller): array
    {
        $record = $this->ownPackage($reseller);

        return [
            'DOCKER' => $record?->get('DOCKER', 'no') ?? 'no',
            'DOCKER_APPS' => $record?->get('DOCKER_APPS', '0') ?? '0',
            'DOCKER_CPUS' => $record?->get('DOCKER_CPUS', 'unlimited') ?? 'unlimited',
            'DOCKER_MEMORY' => $record?->get('DOCKER_MEMORY', 'unlimited') ?? 'unlimited',
        ];
    }

    /**
     * PORTS of a package; a reseller may not go above its own.
     *
     * @throws ValidationException
     */
    public function checkPorts(string $ports, string $reseller): string
    {
        if ($reseller !== '') {
            $limit = $this->ownPorts($reseller);
            if (PackageLimits::above('PORTS', $ports, $limit)) {
                throw ValidationException::withMessages(['ports' => sprintf(__('At most %s on your plan.'), $limit)]);
            }
        }

        return $ports;
    }

    /**
     * Node.js keys of a package; a reseller cannot give Node.js it does not
     * have, nor more runtimes or apps than its own package allows.
     *
     * @param  array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string, NODE_APPS: string}  $node
     * @return array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string, NODE_APPS: string}
     *
     * @throws ValidationException
     */
    public function checkNode(array $node, string $reseller): array
    {
        if ($reseller !== '') {
            $own = $this->ownNode($reseller);
            $errors = [];
            if ($node['NODE'] === 'yes' && $own['NODE'] !== 'yes') {
                $errors['node'] = __('Node.js is not included in your plan.');
            }
            foreach (['NODE_VERSIONS' => 'node_versions', 'NODE_APPS' => 'node_apps'] as $key => $field) {
                if (PackageLimits::above($key, $node[$key], $own[$key])) {
                    $errors[$field] = sprintf(__('At most %s on your plan.'), $own[$key]);
                }
            }
            if (PackageLimits::above('NODE_ALLOWED', $node['NODE_ALLOWED'], $own['NODE_ALLOWED'])) {
                $errors['node_allowed'] = sprintf(__('Your plan allows only %s.'), $own['NODE_ALLOWED']);
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $node;
    }

    /**
     * Docker app keys of a package; a reseller cannot give Docker apps it
     * does not have, nor more apps, CPUs or memory than its own package.
     *
     * @param  array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}  $docker
     * @return array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}
     *
     * @throws ValidationException
     */
    public function checkDocker(array $docker, string $reseller): array
    {
        if ($reseller !== '') {
            $own = $this->ownDocker($reseller);
            $errors = [];
            if ($docker['DOCKER'] === 'yes' && $own['DOCKER'] !== 'yes') {
                $errors['docker'] = __('Docker apps are not included in your plan.');
            }
            foreach (['DOCKER_APPS', 'DOCKER_CPUS', 'DOCKER_MEMORY'] as $key) {
                if (PackageLimits::above($key, $docker[$key], $own[$key])) {
                    $errors[strtolower($key)] = sprintf(__('At most %s on your plan.'), $own[$key]);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $docker;
    }

    /**
     * The app process keys of a reseller's own package (E42).
     *
     * @return array{APP_PROCESSES: string, APP_PROCESSES_MAX: string, APP_PROCESSES_MEMORY: string, APP_PROCESSES_CPUS: string}
     */
    public function ownProcesses(string $reseller): array
    {
        $record = $this->ownPackage($reseller);

        return [
            'APP_PROCESSES' => $record?->get('APP_PROCESSES', 'no') ?? 'no',
            'APP_PROCESSES_MAX' => $record?->get('APP_PROCESSES_MAX', '3') ?? '3',
            'APP_PROCESSES_MEMORY' => $record?->get('APP_PROCESSES_MEMORY', 'unlimited') ?? 'unlimited',
            'APP_PROCESSES_CPUS' => $record?->get('APP_PROCESSES_CPUS', '1') ?? '1',
        ];
    }

    /**
     * App process keys of a package; a reseller may not go beyond its own.
     *
     * @param  array{APP_PROCESSES: string, APP_PROCESSES_MAX: string, APP_PROCESSES_MEMORY: string, APP_PROCESSES_CPUS: string}|null  $processes
     * @return array{APP_PROCESSES: string, APP_PROCESSES_MAX: string, APP_PROCESSES_MEMORY: string, APP_PROCESSES_CPUS: string}|null
     *
     * @throws ValidationException
     */
    public function checkProcesses(?array $processes, string $reseller): ?array
    {
        if ($processes === null || $reseller === '') {
            return $processes;
        }
        $own = $this->ownProcesses($reseller);
        $errors = [];
        if ($processes['APP_PROCESSES'] === 'yes' && $own['APP_PROCESSES'] !== 'yes') {
            $errors['app_processes'] = __('App processes are not included in your plan.');
        }
        foreach (['APP_PROCESSES_MAX', 'APP_PROCESSES_MEMORY', 'APP_PROCESSES_CPUS'] as $key) {
            if (PackageLimits::above($key, $processes[$key], $own[$key])) {
                $errors[strtolower($key)] = sprintf(__('At most %s on your plan.'), $own[$key]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $processes;
    }

    /**
     * The object storage keys of a reseller's own package (E44).
     *
     * @return array{OBJECT_STORAGE: string, OBJECT_STORAGE_BUCKETS: string, OBJECT_STORAGE_QUOTA: string, OBJECT_STORAGE_BACKUP: string, BACKUP_FREQUENCY?: string}
     */
    public function ownStorage(string $reseller): array
    {
        $record = $this->ownPackage($reseller);
        $own = [];
        foreach (PackageService::STORAGE_DEFAULTS as $key => $default) {
            $own[$key] = $record?->get($key, $default) ?? $default;
        }

        return $own;
    }

    /**
     * Object storage keys of a package; a reseller may not go beyond its own.
     *
     * @param  array{OBJECT_STORAGE: string, OBJECT_STORAGE_BUCKETS: string, OBJECT_STORAGE_QUOTA: string, OBJECT_STORAGE_BACKUP: string, BACKUP_FREQUENCY?: string}|null  $storage
     * @return array{OBJECT_STORAGE: string, OBJECT_STORAGE_BUCKETS: string, OBJECT_STORAGE_QUOTA: string, OBJECT_STORAGE_BACKUP: string, BACKUP_FREQUENCY?: string}|null
     *
     * @throws ValidationException
     */
    public function checkStorage(?array $storage, string $reseller): ?array
    {
        if ($storage === null || $reseller === '') {
            return $storage;
        }
        $own = $this->ownStorage($reseller);
        $errors = [];
        if ($storage['OBJECT_STORAGE'] === 'yes' && $own['OBJECT_STORAGE'] !== 'yes') {
            $errors['object_storage'] = __('Object storage is not included in your plan.');
        }
        foreach (['OBJECT_STORAGE_BUCKETS', 'OBJECT_STORAGE_QUOTA', 'OBJECT_STORAGE_BACKUP', 'BACKUP_FREQUENCY'] as $key) {
            if (isset($storage[$key]) && PackageLimits::above($key, $storage[$key], $own[$key])) {
                $errors[strtolower($key)] = sprintf(__('At most %s on your plan.'), $own[$key]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $storage;
    }

    private function ownPackage(string $reseller): ?PackageRecord
    {
        return $this->packages->all()[$this->users->find($reseller)->package()] ?? null;
    }
}
