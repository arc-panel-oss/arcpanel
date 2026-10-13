<?php

namespace App\Services\Packages;

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
    public function planCreate(Principal $principal, string $name, array $values, ?array $tenancy, string $ports, array $node, array $docker, ?array $php = null): array
    {
        $reseller = self::resellerOf($principal);
        if ($this->packages->exists(self::storedName($principal, $name))) {
            throw ValidationException::withMessages(['name' => __('A package with this name exists.')]);
        }
        $this->checkValues($values, [], $reseller);

        return $this->packages->planCreate($name, $values, $reseller === '' ? $tenancy : null, $reseller,
            $this->checkPorts($ports, $reseller), $this->checkNode($node, $reseller), $this->checkDocker($docker, $reseller), $this->checkPhp($php, $reseller));
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
    public function planUpdate(Principal $principal, PackageRecord $record, string $newName, array $values, ?array $tenancy, string $ports, array $node, array $docker, ?array $php = null): array
    {
        $reseller = self::resellerOf($principal);
        $newName = $reseller !== '' ? $record->name : $newName;
        if ($newName !== $record->name && $this->packages->exists($newName)) {
            throw ValidationException::withMessages(['name' => __('A package with this name exists.')]);
        }
        $this->checkValues($values, $record->data, $reseller);

        return $this->packages->planUpdate($record, $newName, $values, $reseller === '' ? $tenancy : null, $reseller,
            $this->checkPorts($ports, $reseller), $this->checkNode($node, $reseller), $this->checkDocker($docker, $reseller), $this->checkPhp($php, $reseller));
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

    /**
     * Templates and shell from the server's lists (a value the package
     * already has stays allowed), at least two name servers, and for a
     * reseller no limit above its own.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $current
     *
     * @throws ValidationException
     */
    public function checkValues(array $values, array $current, string $reseller): void
    {
        $templates = $this->packages->templates();
        $errors = [];
        foreach (['WEB_TEMPLATE' => 'web', 'BACKEND_TEMPLATE' => 'backend', 'PROXY_TEMPLATE' => 'proxy', 'DNS_TEMPLATE' => 'dns', 'SHELL' => 'shells'] as $key => $list) {
            if ($templates[$list] !== [] && ! in_array($values[$key], $templates[$list], true) && ($current[$key] ?? null) !== $values[$key]) {
                $errors[strtolower($key === 'SHELL' ? 'shell' : $key)] = __('Please select a valid value.');
            }
        }
        if (count(array_filter(explode(',', $values['NS'] ?? ''))) < 2) {
            $errors['nameservers'] = __('Enter at least two name servers.');
        }
        if ($reseller !== '') {
            $own = $this->users->find($reseller);
            foreach ([...PackageRecord::COUNTED, 'RATE_LIMIT', 'BACKUPS'] as $key) {
                $limit = $own->get($key, 'unlimited');
                if ($limit === 'unlimited' || $limit === '') {
                    continue;
                }
                if ($values[$key] === 'unlimited' || (int) $values[$key] > (int) $limit) {
                    $errors[strtolower($key)] = sprintf(__('At most %s on your plan.'), $limit);
                }
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
     * @return array{NODE: string, NODE_VERSIONS: string, NODE_APPS: string}
     */
    public function ownNode(string $reseller): array
    {
        $record = $this->ownPackage($reseller);

        return [
            'NODE' => $record?->get('NODE', 'no') ?? 'no',
            'NODE_VERSIONS' => $record?->get('NODE_VERSIONS', '3') ?? '3',
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
            if ($limit !== 'unlimited' && ($ports === 'unlimited' || (int) $ports > (int) $limit)) {
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
                $limit = $own[$key];
                if ($limit !== 'unlimited' && ($node[$key] === 'unlimited' || (int) $node[$key] > (int) $limit)) {
                    $errors[$field] = sprintf(__('At most %s on your plan.'), $limit);
                }
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
            $mb = fn (string $size) => preg_match('/^(\d+)([MG]?)$/', $size, $m) === 1 ? (int) $m[1] * ($m[2] === 'G' ? 1024 : 1) : PHP_INT_MAX;
            $above = [
                'DOCKER_APPS' => fn (string $value, string $limit) => $value === 'unlimited' || (int) $value > (int) $limit,
                'DOCKER_CPUS' => fn (string $value, string $limit) => $value === 'unlimited' || (float) $value > (float) $limit,
                'DOCKER_MEMORY' => fn (string $value, string $limit) => $mb($value) > $mb($limit),
            ];
            foreach ($above as $key => $check) {
                if ($own[$key] !== 'unlimited' && $check($docker[$key], $own[$key])) {
                    $errors[strtolower($key)] = sprintf(__('At most %s on your plan.'), $own[$key]);
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        return $docker;
    }

    private function ownPackage(string $reseller): ?PackageRecord
    {
        return $this->packages->all()[$this->users->find($reseller)->package()] ?? null;
    }
}
