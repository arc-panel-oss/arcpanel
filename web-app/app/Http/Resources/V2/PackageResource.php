<?php

namespace App\Http\Resources\V2;

use App\Services\Packages\PackageRecord;
use App\Services\Users\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hosting package with its reseller, port, Node.js and Docker settings
 * (`v-list-package-tenancy json`). Unlimited values are null.
 *
 * @property PackageRecord $resource
 */
final class PackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $package = $this->resource;
        $get = fn (string $key, string $default = '') => $package->get($key, $default);

        return [
            /** @var string the name in paths; a reseller's own packages are stored as RESELLER_NAME */
            'name' => $package->name,
            /** @var string the name as its owner sees it */
            'label' => UserService::packageLabel($package->name, $package->owner()),
            /** @var string|null the reseller that created the package; null for the administrator's */
            'owner' => $package->owner() !== '' ? $package->owner() : null,
            /** @var int users on the package */
            'users' => $package->users,
            'templates' => [
                /** @var string */
                'web' => $get('WEB_TEMPLATE', 'default'),
                /** @var string */
                'backend' => $get('BACKEND_TEMPLATE', 'default'),
                /** @var string */
                'proxy' => $get('PROXY_TEMPLATE', 'default'),
                /** @var string */
                'dns' => $get('DNS_TEMPLATE', 'default'),
            ],
            'limits' => [
                'web_domains' => UserResource::limit($get('WEB_DOMAINS')),
                'web_aliases' => UserResource::limit($get('WEB_ALIASES')),
                'dns_domains' => UserResource::limit($get('DNS_DOMAINS')),
                'dns_records' => UserResource::limit($get('DNS_RECORDS')),
                'mail_domains' => UserResource::limit($get('MAIL_DOMAINS')),
                'mail_accounts' => UserResource::limit($get('MAIL_ACCOUNTS')),
                'databases' => UserResource::limit($get('DATABASES')),
                'cron_jobs' => UserResource::limit($get('CRON_JOBS')),
                'disk_mb' => UserResource::limit($get('DISK_QUOTA')),
                'bandwidth_mb' => UserResource::limit($get('BANDWIDTH')),
                /** @var int outgoing mails an hour per account */
                'rate_limit' => (int) $get('RATE_LIMIT', '200'),
                /** @var int backups kept */
                'backups' => (int) $get('BACKUPS', '1'),
            ],
            /** cgroup limits, applied when the server has RESOURCES_LIMIT='yes' (values such as 50%, 100ms, 512M) */
            'resources' => [
                'cpu_quota' => self::value($get('CPU_QUOTA')),
                'cpu_quota_period' => self::value($get('CPU_QUOTA_PERIOD')),
                'memory_limit' => self::value($get('MEMORY_LIMIT')),
                'swap_limit' => self::value($get('SWAP_LIMIT')),
            ],
            /** @var string */
            'shell' => $get('SHELL', 'nologin'),
            /** @var list<string> */
            'nameservers' => $package->nameservers(),
            /** @var bool */
            'backups_incremental' => $get('BACKUPS_INCREMENTAL') === 'yes',
            /** What a reseller on this package may do (E11) */
            'reseller' => [
                /** @var int|null customers a reseller on this package may have */
                'users' => UserResource::limit($get('USERS', '0')),
                /** @var list<string> packages it may assign, `*` for every visible one */
                'packages' => array_values(array_filter(explode(',', $get('RESELLER_PACKAGES')))),
                /** @var bool whether resellers may assign this package */
                'visible' => $get('RESELLER_VISIBLE') === 'yes',
            ],
            /** @var int|null ports of the port registry (E12) */
            'ports' => UserResource::limit($get('PORTS', '0')),
            'node' => [
                /** @var bool */
                'enabled' => $get('NODE', 'no') === 'yes',
                /** @var int|null runtimes per user */
                'versions' => UserResource::limit($get('NODE_VERSIONS', '3')),
                /** @var string `lts`, `*` or a list of major versions */
                'allowed' => $get('NODE_ALLOWED', 'lts'),
                /** @var int|null apps per user */
                'apps' => UserResource::limit($get('NODE_APPS', '3')),
            ],
            'docker' => [
                /** @var bool */
                'enabled' => $get('DOCKER', 'no') === 'yes',
                /** @var int|null apps per user */
                'apps' => UserResource::limit($get('DOCKER_APPS', '0')),
                /** @var string|null CPUs per app */
                'cpus' => self::value($get('DOCKER_CPUS', 'unlimited')),
                /** @var string|null memory per app (512M, 2G) */
                'memory' => self::value($get('DOCKER_MEMORY', 'unlimited')),
            ],
            'app_processes' => [
                /** @var bool app processes allowed (E42) */
                'enabled' => $get('APP_PROCESSES', 'no') === 'yes',
                /** @var int|null processes per user */
                'max' => UserResource::limit($get('APP_PROCESSES_MAX', '3')),
                /** @var string|null memory per process (512M, 1G) */
                'memory' => self::value($get('APP_PROCESSES_MEMORY', 'unlimited')),
                /** @var string|null CPUs per process */
                'cpus' => self::value($get('APP_PROCESSES_CPUS', '1')),
            ],
            'php' => [
                /** @var string `*` (every installed version that has not reached its end of life) or a comma list of versions (E41) */
                'versions' => $get('PHP_VERSIONS', '*') ?: '*',
                /** @var string `*` or a comma list of PHP-FPM profiles */
                'profiles' => $get('PHP_PROFILES', 'default') ?: 'default',
                /** @var string the profile of new web domains */
                'default_profile' => $get('PHP_DEFAULT_PROFILE', 'default') ?: 'default',
            ],
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => UserResource::localTime($get('DATE'), $get('TIME')),
        ];
    }

    /** A value that may be unlimited: null for unlimited. */
    public static function value(string $value): ?string
    {
        return $value === '' || $value === 'unlimited' ? null : $value;
    }
}
