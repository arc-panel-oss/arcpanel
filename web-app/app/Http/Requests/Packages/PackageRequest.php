<?php

namespace App\Http\Requests\Packages;

use App\Services\Packages\PackageRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit a package (web/add/package, web/edit/package). Limits come
 * from x-ap-unlimited-input: NAME and, when ticked, NAME_unlimited.
 * Templates and shells are checked by the controller against the server's
 * lists.
 */
final class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $object = ['nullable', 'string', 'max:100', 'regex:/^[[:alnum:]][-.\w]*$/'];
        $rules = [
            'name' => ['required', 'string', 'max:60', 'regex:/^[[:alnum:]][-.\w]*$/'],
            'web_template' => $object,
            'backend_template' => $object,
            'proxy_template' => $object,
            'dns_template' => $object,
            'shell' => $object,
            'rate_limit' => ['required', 'integer', 'min:0', 'max:1000000'],
            'backups' => ['required', 'integer', 'min:0', 'max:1000'],
            'backups_incremental' => ['nullable', 'boolean'],
            'nameservers' => ['required', 'string', 'max:2000'],
            'users' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'reseller_packages' => ['nullable', 'string', 'max:2000', 'regex:/^(\*|[[:alnum:]][-.\w]*(\s*,\s*[[:alnum:]][-.\w]*)*)?$/'],
            'reseller_visible' => ['nullable', 'boolean'],
            'ports' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'node' => ['nullable', 'boolean'],
            'node_versions' => ['nullable', 'integer', 'min:0', 'max:999'],
            'node_apps' => ['nullable', 'integer', 'min:0', 'max:999'],
            'node_allowed' => ['nullable', 'string', 'max:100', 'regex:/^(lts|\*|\d{1,3}(,\d{1,3})*)$/'],
            'docker' => ['nullable', 'boolean'],
            'docker_apps' => ['nullable', 'integer', 'min:0', 'max:999'],
            'docker_cpus' => ['nullable', 'string', 'max:20', 'regex:/^(unlimited|\d{1,3}(\.\d{1,2})?)$/'],
            'docker_memory' => ['nullable', 'string', 'max:20', 'regex:/^(unlimited|\d{1,7}[MG]?)$/'],
            // App processes (E42 spec §7)
            'app_processes' => ['nullable', 'boolean'],
            'app_processes_max' => ['nullable', 'integer', 'min:0', 'max:999'],
            'app_processes_memory' => ['nullable', 'string', 'max:20', 'regex:/^(unlimited|\d{1,7}[MG]?)$/'],
            'app_processes_cpus' => ['nullable', 'string', 'max:20', 'regex:/^(unlimited|\d{1,2}(\.\d{1,2})?)$/'],
            // Object storage (E44 spec §6)
            'object_storage' => ['nullable', 'boolean'],
            'object_storage_buckets' => ['nullable', 'integer', 'min:0', 'max:999'],
            'object_storage_quota' => ['nullable', 'string', 'max:20', 'regex:/^(unlimited|\d{1,9}[MGT]?)$/'],
            'object_storage_backup' => ['nullable', 'boolean'],
            // How often a scheduled backup job backs up an account at most (E48 spec §8.2)
            'backup_frequency' => ['nullable', 'in:hourly,daily,weekly'],
            // PHP of the package (E41 §7.5), sent with the php_group marker
            'php_versions_all' => ['nullable', 'boolean'],
            'php_versions' => ['nullable', 'array', Rule::requiredIf(fn () => $this->boolean('php_group') && ! $this->boolean('php_versions_all'))],
            'php_versions.*' => ['string', 'regex:/^\d{1,2}\.\d{1,2}$/'],
            'php_profiles_all' => ['nullable', 'boolean'],
            'php_profiles' => ['nullable', 'array', Rule::requiredIf(fn () => $this->boolean('php_group') && ! $this->boolean('php_profiles_all'))],
            'php_profiles.*' => ['string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'],
            'php_default_profile' => ['nullable', 'required_if:php_group,1', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'],
        ];
        foreach (PackageRecord::COUNTED as $key) {
            $rules[strtolower($key)] = ['nullable', 'required_without:'.strtolower($key).'_unlimited', 'integer', 'min:0', 'max:100000000'];
        }
        foreach (PackageRecord::RESOURCES as $key) {
            $rules[strtolower($key)] = ['nullable', 'string', 'max:20', 'regex:/^(unlimited|\d+(%|ms|s|[KMGT])?)$/'];
        }

        return $rules;
    }

    /**
     * The package file values, KEY => value; cgroup limits keep $current
     * when the server does not use them.
     *
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    public function packageValues(array $current, bool $resources): array
    {
        $values = [
            'WEB_TEMPLATE' => (string) ($this->input('web_template') ?: 'default'),
            'BACKEND_TEMPLATE' => (string) ($this->input('backend_template') ?: ($current['BACKEND_TEMPLATE'] ?? 'default')),
            'PROXY_TEMPLATE' => (string) ($this->input('proxy_template') ?: ($current['PROXY_TEMPLATE'] ?? 'default')),
            'DNS_TEMPLATE' => (string) ($this->input('dns_template') ?: 'default'),
            'RATE_LIMIT' => (string) $this->integer('rate_limit'),
            'BACKUPS' => (string) $this->integer('backups'),
            'BACKUPS_INCREMENTAL' => $this->boolean('backups_incremental') ? 'yes' : 'no',
            'SHELL' => (string) ($this->input('shell') ?: 'nologin'),
            'NS' => implode(',', $this->nameservers()),
        ];
        foreach (PackageRecord::COUNTED as $key) {
            $field = strtolower($key);
            $values[$key] = $this->boolean($field.'_unlimited') ? 'unlimited' : (string) $this->integer($field);
        }
        foreach (PackageRecord::RESOURCES as $key) {
            $values[$key] = $resources ? (string) ($this->input(strtolower($key)) ?: 'unlimited') : ($current[$key] ?? 'unlimited');
        }

        return $values;
    }

    /**
     * PHP keys of the package sidecar (E41 §7.5), or null when the form has
     * no PHP group (a server without PHP-FPM).
     *
     * @return array{PHP_VERSIONS: string, PHP_PROFILES: string, PHP_DEFAULT_PROFILE: string}|null
     */
    public function phpValues(): ?array
    {
        if (! $this->boolean('php_group')) {
            return null;
        }
        $list = function (string $field): string {
            if ($this->boolean($field.'_all')) {
                return '*';
            }
            $items = array_values(array_unique(array_map('strval', (array) $this->input($field, []))));
            $field === 'php_versions' ? usort($items, 'version_compare') : sort($items);

            return implode(',', $items);
        };

        return [
            'PHP_VERSIONS' => $list('php_versions'),
            'PHP_PROFILES' => $list('php_profiles'),
            'PHP_DEFAULT_PROFILE' => (string) $this->input('php_default_profile', 'default'),
        ];
    }

    /** @return array{USERS: string, RESELLER_PACKAGES: string, RESELLER_VISIBLE: string} */
    public function tenancyValues(): array
    {
        $packages = preg_split('/\s*,\s*/', trim((string) $this->input('reseller_packages'))) ?: [];

        return [
            'USERS' => $this->boolean('users_unlimited') ? 'unlimited' : (string) $this->integer('users'),
            'RESELLER_PACKAGES' => implode(',', array_filter($packages)),
            'RESELLER_VISIBLE' => $this->boolean('reseller_visible') ? 'yes' : 'no',
        ];
    }

    /** PORTS of the package sidecar (E12): a number, or unlimited when ticked. */
    public function portsValue(): string
    {
        return $this->boolean('ports_unlimited') ? 'unlimited' : (string) $this->integer('ports');
    }

    /**
     * Node.js keys of the package sidecar: NODE, NODE_VERSIONS (a number,
     * or unlimited when ticked) and NODE_ALLOWED (E13), NODE_APPS (E14).
     *
     * @return array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string, NODE_APPS: string}
     */
    public function nodeValues(): array
    {
        return [
            'NODE' => $this->boolean('node') ? 'yes' : 'no',
            'NODE_VERSIONS' => $this->boolean('node_versions_unlimited') ? 'unlimited' : (string) $this->integer('node_versions', 3),
            'NODE_ALLOWED' => (string) ($this->input('node_allowed') ?: 'lts'),
            'NODE_APPS' => $this->boolean('node_apps_unlimited') ? 'unlimited' : (string) $this->integer('node_apps', 3),
        ];
    }

    /**
     * Docker app keys of the package sidecar (E4): DOCKER, DOCKER_APPS (a
     * number, or unlimited when ticked), DOCKER_CPUS and DOCKER_MEMORY (per
     * app, or unlimited).
     *
     * @return array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}
     */
    public function dockerValues(): array
    {
        return [
            'DOCKER' => $this->boolean('docker') ? 'yes' : 'no',
            'DOCKER_APPS' => $this->boolean('docker_apps_unlimited') ? 'unlimited' : (string) $this->integer('docker_apps', 0),
            'DOCKER_CPUS' => (string) ($this->input('docker_cpus') ?: 'unlimited'),
            'DOCKER_MEMORY' => (string) ($this->input('docker_memory') ?: 'unlimited'),
        ];
    }

    /**
     * App process keys of the package sidecar (E42): APP_PROCESSES,
     * APP_PROCESSES_MAX (a number, or unlimited when ticked),
     * APP_PROCESSES_MEMORY and APP_PROCESSES_CPUS (per process).
     *
     * @return array{APP_PROCESSES: string, APP_PROCESSES_MAX: string, APP_PROCESSES_MEMORY: string, APP_PROCESSES_CPUS: string}
     */
    public function processValues(): array
    {
        return [
            'APP_PROCESSES' => $this->boolean('app_processes') ? 'yes' : 'no',
            'APP_PROCESSES_MAX' => $this->boolean('app_processes_max_unlimited') ? 'unlimited' : (string) $this->integer('app_processes_max', 3),
            'APP_PROCESSES_MEMORY' => (string) ($this->input('app_processes_memory') ?: 'unlimited'),
            'APP_PROCESSES_CPUS' => (string) ($this->input('app_processes_cpus') ?: '1'),
        ];
    }

    /**
     * Object storage keys of the package sidecar (E44): OBJECT_STORAGE,
     * OBJECT_STORAGE_BUCKETS (a number, or unlimited when ticked),
     * OBJECT_STORAGE_QUOTA (per bucket) and OBJECT_STORAGE_BACKUP; and the
     * backup frequency of E48, BACKUP_FREQUENCY.
     *
     * @return array{OBJECT_STORAGE: string, OBJECT_STORAGE_BUCKETS: string, OBJECT_STORAGE_QUOTA: string, OBJECT_STORAGE_BACKUP: string, BACKUP_FREQUENCY: string}
     */
    public function storageValues(): array
    {
        return [
            'OBJECT_STORAGE' => $this->boolean('object_storage') ? 'yes' : 'no',
            'OBJECT_STORAGE_BUCKETS' => $this->boolean('object_storage_buckets_unlimited') ? 'unlimited' : (string) $this->integer('object_storage_buckets', 3),
            'OBJECT_STORAGE_QUOTA' => (string) ($this->input('object_storage_quota') ?: '1G'),
            'OBJECT_STORAGE_BACKUP' => $this->boolean('object_storage_backup') ? 'yes' : 'no',
            'BACKUP_FREQUENCY' => (string) ($this->input('backup_frequency') ?: 'daily'),
        ];
    }

    /** @return list<string> */
    public function nameservers(): array
    {
        $list = preg_split('/[\s,]+/', strtolower((string) $this->input('nameservers'))) ?: [];

        return array_values(array_filter($list, fn (string $ns) => preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]+$/', $ns) === 1));
    }
}
