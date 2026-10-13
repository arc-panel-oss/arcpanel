<?php

namespace App\Http\Requests\Api\V2;

use App\Services\Packages\PackageRecord;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Base of POST /packages and PATCH /packages/{package}: the fields of the package
 * form (E11 spec §8), grouped as in the Package schema. Every field may be
 * left out: a new package takes the values of the add form, a changed one
 * keeps its own. null means unlimited.
 */
abstract class PackageRequest extends ApiRequest
{
    /** Whether the request creates a package (the name is required then). */
    abstract protected function creates(): bool;

    /** API field of `limits` => key of the package file */
    public const LIMITS = [
        'web_domains' => 'WEB_DOMAINS', 'web_aliases' => 'WEB_ALIASES', 'dns_domains' => 'DNS_DOMAINS', 'dns_records' => 'DNS_RECORDS',
        'mail_domains' => 'MAIL_DOMAINS', 'mail_accounts' => 'MAIL_ACCOUNTS', 'databases' => 'DATABASES', 'cron_jobs' => 'CRON_JOBS',
        'disk_mb' => 'DISK_QUOTA', 'bandwidth_mb' => 'BANDWIDTH',
    ];

    /** API field of `templates` => key of the package file */
    public const TEMPLATES = ['web' => 'WEB_TEMPLATE', 'backend' => 'BACKEND_TEMPLATE', 'proxy' => 'PROXY_TEMPLATE', 'dns' => 'DNS_TEMPLATE'];

    /** API field of `resources` => key of the package file */
    public const RESOURCES = ['cpu_quota' => 'CPU_QUOTA', 'cpu_quota_period' => 'CPU_QUOTA_PERIOD', 'memory_limit' => 'MEMORY_LIMIT', 'swap_limit' => 'SWAP_LIMIT'];

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('nameservers'))) {
            $this->merge(['nameservers' => array_map(fn ($ns) => is_string($ns) ? strtolower(trim($ns)) : $ns, $this->input('nameservers'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $object = ['sometimes', 'string', 'max:100', 'regex:/^[[:alnum:]][-.\w]*$/'];
        $limit = ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'];
        $rules = [
            /** The name; a reseller's package is stored as RESELLER_NAME. Administrators rename a package with PATCH. */
            'name' => [$this->creates() ? 'required' : 'sometimes', 'string', 'max:60', 'regex:/^[[:alnum:]][-.\w]*$/'],
            'templates' => ['sometimes', 'array'],
            'limits' => ['sometimes', 'array'],
            /** Outgoing mails an hour per account. */
            'limits.rate_limit' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            /** Backups kept. */
            'limits.backups' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            /** cgroup limits, used when the server has RESOURCES_LIMIT='yes'. */
            'resources' => ['sometimes', 'array'],
            /** A shell of the server (v-list-sys-shells). */
            'shell' => $object,
            /** At least two name servers. */
            'nameservers' => ['sometimes', 'array', 'min:2', 'max:10'],
            'nameservers.*' => ['string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]+$/'],
            'backups_incremental' => ['sometimes', 'boolean'],
            /** Administrators only: what a reseller on this package may do. */
            'reseller' => ['sometimes', 'array'],
            /** Customers a reseller on this package may have; null for unlimited. */
            'reseller.users' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            /** Packages it may assign; `*` for every visible one. */
            'reseller.packages' => ['sometimes', 'array'],
            'reseller.packages.*' => ['string', 'max:100', 'regex:/^(\*|[[:alnum:]][-.\w]*)$/'],
            /** Whether resellers may assign this package. */
            'reseller.visible' => ['sometimes', 'boolean'],
            /** Ports of the port registry; null for unlimited. */
            'ports' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'node' => ['sometimes', 'array'],
            'node.enabled' => ['sometimes', 'boolean'],
            'node.versions' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            /** `lts`, `*` or a comma list of major versions. */
            'node.allowed' => ['sometimes', 'string', 'max:100', 'regex:/^(lts|\*|\d{1,3}(,\d{1,3})*)$/'],
            'node.apps' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            'docker' => ['sometimes', 'array'],
            'docker.enabled' => ['sometimes', 'boolean'],
            'docker.apps' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            /** CPUs per app, such as 0.5; null for unlimited. */
            'docker.cpus' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            /** Memory per app, such as 512M or 2G; null for unlimited. */
            'docker.memory' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d{1,7}[MG]?$/'],
            'php' => ['sometimes', 'array'],
            /** `*` (every installed version that has not reached its end of life) or a comma list of versions such as `8.3,8.4` (E41). */
            'php.versions' => ['sometimes', 'string', 'max:200', 'regex:/^(\*|\d{1,2}\.\d{1,2}(,\d{1,2}\.\d{1,2})*)$/'],
            /** `*` or a comma list of PHP-FPM profiles; it must keep the default profile. */
            'php.profiles' => ['sometimes', 'string', 'max:500', 'regex:/^(\*|[a-z0-9][a-z0-9-]{0,31}(,[a-z0-9][a-z0-9-]{0,31})*)$/'],
            /** The profile of new web domains, one of the allowed profiles. */
            'php.default_profile' => ['sometimes', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/'],
        ];
        foreach (array_keys(self::TEMPLATES) as $field) {
            $rules['templates.'.$field] = $object;
        }
        foreach (array_keys(self::LIMITS) as $field) {
            $rules['limits.'.$field] = $limit;
        }
        foreach (array_keys(self::RESOURCES) as $field) {
            $rules['resources.'.$field] = ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\d+(%|ms|s|[KMGT])?$/'];
        }

        return $rules;
    }

    /**
     * Keys of the package file: the sent fields over $base. The cgroup
     * limits keep $base when the server does not use them.
     *
     * @param  array<string, string>  $base
     * @return array<string, string>
     */
    public function packageValues(array $base, bool $resources): array
    {
        $values = $base;
        foreach (self::TEMPLATES as $field => $key) {
            if ($this->has('templates.'.$field)) {
                $values[$key] = (string) $this->input('templates.'.$field);
            }
        }
        foreach (self::LIMITS as $field => $key) {
            if ($this->has('limits.'.$field)) {
                $values[$key] = self::number($this->input('limits.'.$field));
            }
        }
        foreach (['rate_limit' => 'RATE_LIMIT', 'backups' => 'BACKUPS'] as $field => $key) {
            if ($this->has('limits.'.$field)) {
                $values[$key] = (string) (int) $this->input('limits.'.$field);
            }
        }
        if ($resources) {
            foreach (self::RESOURCES as $field => $key) {
                if ($this->has('resources.'.$field)) {
                    $values[$key] = (string) ($this->input('resources.'.$field) ?? 'unlimited');
                }
            }
        }
        if ($this->has('shell')) {
            $values['SHELL'] = (string) $this->input('shell');
        }
        if ($this->has('nameservers')) {
            $values['NS'] = implode(',', array_values(array_unique((array) $this->input('nameservers'))));
        }
        if ($this->has('backups_incremental')) {
            $values['BACKUPS_INCREMENTAL'] = $this->boolean('backups_incremental') ? 'yes' : 'no';
        }

        return array_intersect_key($values, array_flip(['WEB_TEMPLATE', 'BACKEND_TEMPLATE', 'PROXY_TEMPLATE', 'DNS_TEMPLATE',
            ...PackageRecord::COUNTED, ...PackageRecord::RESOURCES, 'RATE_LIMIT', 'NS', 'SHELL', 'BACKUPS', 'BACKUPS_INCREMENTAL']));
    }

    /**
     * @param  array<string, string>  $base
     * @return array{USERS: string, RESELLER_PACKAGES: string, RESELLER_VISIBLE: string}
     */
    public function tenancyValues(array $base): array
    {
        return [
            'USERS' => $this->has('reseller.users') ? self::number($this->input('reseller.users')) : ($base['USERS'] ?? '0'),
            'RESELLER_PACKAGES' => $this->has('reseller.packages')
                ? implode(',', array_values(array_unique((array) $this->input('reseller.packages'))))
                : ($base['RESELLER_PACKAGES'] ?? ''),
            'RESELLER_VISIBLE' => $this->has('reseller.visible') ? ($this->boolean('reseller.visible') ? 'yes' : 'no') : ($base['RESELLER_VISIBLE'] ?? 'no'),
        ];
    }

    /** @param  array<string, string>  $base */
    public function portsValue(array $base): string
    {
        return $this->has('ports') ? self::number($this->input('ports')) : ($base['PORTS'] ?? '0');
    }

    /**
     * @param  array<string, string>  $base
     * @return array{NODE: string, NODE_VERSIONS: string, NODE_ALLOWED: string, NODE_APPS: string}
     */
    public function nodeValues(array $base): array
    {
        return [
            'NODE' => $this->has('node.enabled') ? ($this->boolean('node.enabled') ? 'yes' : 'no') : ($base['NODE'] ?? 'no'),
            'NODE_VERSIONS' => $this->has('node.versions') ? self::number($this->input('node.versions')) : ($base['NODE_VERSIONS'] ?? '3'),
            'NODE_ALLOWED' => $this->has('node.allowed') ? (string) $this->input('node.allowed') : ($base['NODE_ALLOWED'] ?? 'lts'),
            'NODE_APPS' => $this->has('node.apps') ? self::number($this->input('node.apps')) : ($base['NODE_APPS'] ?? '3'),
        ];
    }

    /**
     * @param  array<string, string>  $base
     * @return array{DOCKER: string, DOCKER_APPS: string, DOCKER_CPUS: string, DOCKER_MEMORY: string}
     */
    public function dockerValues(array $base): array
    {
        return [
            'DOCKER' => $this->has('docker.enabled') ? ($this->boolean('docker.enabled') ? 'yes' : 'no') : ($base['DOCKER'] ?? 'no'),
            'DOCKER_APPS' => $this->has('docker.apps') ? self::number($this->input('docker.apps')) : ($base['DOCKER_APPS'] ?? '0'),
            'DOCKER_CPUS' => $this->has('docker.cpus') ? (string) ($this->input('docker.cpus') ?? 'unlimited') : ($base['DOCKER_CPUS'] ?? 'unlimited'),
            'DOCKER_MEMORY' => $this->has('docker.memory') ? (string) ($this->input('docker.memory') ?? 'unlimited') : ($base['DOCKER_MEMORY'] ?? 'unlimited'),
        ];
    }

    /**
     * PHP keys of the package sidecar (E41 §7.5), or null when the request
     * has no `php` object, so they stay as they are.
     *
     * @param  array<string, string>  $base
     * @return array{PHP_VERSIONS: string, PHP_PROFILES: string, PHP_DEFAULT_PROFILE: string}|null
     */
    public function phpValues(array $base): ?array
    {
        if (! $this->has('php')) {
            return null;
        }

        return [
            'PHP_VERSIONS' => $this->has('php.versions') ? (string) $this->input('php.versions') : (($base['PHP_VERSIONS'] ?? '') ?: '*'),
            'PHP_PROFILES' => $this->has('php.profiles') ? (string) $this->input('php.profiles') : (($base['PHP_PROFILES'] ?? '') ?: 'default'),
            'PHP_DEFAULT_PROFILE' => $this->has('php.default_profile') ? (string) $this->input('php.default_profile') : (($base['PHP_DEFAULT_PROFILE'] ?? '') ?: 'default'),
        ];
    }

    /**
     * Run a check of PackageActions; its errors, named after the fields of
     * the package form, are renamed to the fields of this request.
     *
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     *
     * @throws ValidationException
     */
    public static function withApiFields(Closure $check): mixed
    {
        try {
            return $check();
        } catch (ValidationException $e) {
            $names = [
                'web_template' => 'templates.web', 'backend_template' => 'templates.backend', 'proxy_template' => 'templates.proxy',
                'dns_template' => 'templates.dns', 'rate_limit' => 'limits.rate_limit', 'backups' => 'limits.backups',
                'node' => 'node.enabled', 'node_versions' => 'node.versions', 'node_apps' => 'node.apps',
                'docker' => 'docker.enabled', 'docker_apps' => 'docker.apps', 'docker_cpus' => 'docker.cpus', 'docker_memory' => 'docker.memory',
                'php_versions' => 'php.versions', 'php_profiles' => 'php.profiles', 'php_default_profile' => 'php.default_profile',
            ];
            foreach (self::LIMITS as $field => $key) {
                $names[strtolower($key)] = 'limits.'.$field;
            }
            $errors = [];
            foreach ($e->errors() as $field => $messages) {
                $errors[$names[$field] ?? $field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /** A count of the request: null is unlimited. */
    private static function number(mixed $value): string
    {
        return $value === null ? 'unlimited' : (string) (int) $value;
    }
}
