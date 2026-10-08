<?php

namespace App\Http\Resources\V2;

use App\Services\Users\UserRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A panel user (`v-list-user USER json` and its owner). Unlimited quotas
 * are null; the password and the reset key are never part of it.
 *
 * @property UserRecord $resource
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            /** @var string the login name */
            'username' => $user->name,
            /** @var string the full name */
            'name' => $user->fullName(),
            /** @var string the contact e-mail */
            'email' => $user->email(),
            'role' => MeResource::role($user->role()),
            /** @var string|null the reseller or administrator that owns the user; null for administrators */
            'owner' => $user->owner !== '' ? $user->owner : null,
            /** @var string */
            'package' => $user->package(),
            /** @var string */
            'language' => $user->language(),
            /** @var string the login shell (nologin without SSH) */
            'shell' => $user->get('SHELL', 'nologin'),
            /** @var bool */
            'suspended' => $user->suspended(),
            /** @var bool */
            'login_disabled' => $user->loginDisabled(),
            /** @var bool */
            'two_factor' => $user->twoFactor(),
            /** @var list<string> */
            'nameservers' => $user->nameservers(),
            /** @var list<string>|null the addresses or networks the login is allowed from; null without a restriction */
            'allowed_ips' => $user->loginUsesIpList()
                ? array_values(array_filter(array_map('trim', explode(',', $user->get('LOGIN_ALLOW_IPS'))), fn (string $ip) => $ip !== ''))
                : null,
            /** @var string|null the PHP version of the command line; null for the server's default */
            'php_cli' => $user->get('PHPCLI') !== '' ? $user->get('PHPCLI') : null,
            'limits' => [
                'web_domains' => self::limit($user->get('WEB_DOMAINS')),
                'dns_domains' => self::limit($user->get('DNS_DOMAINS')),
                'mail_domains' => self::limit($user->get('MAIL_DOMAINS')),
                'mail_accounts' => self::limit($user->get('MAIL_ACCOUNTS')),
                'databases' => self::limit($user->get('DATABASES')),
                'cron_jobs' => self::limit($user->get('CRON_JOBS')),
                'disk_mb' => self::limit($user->get('DISK_QUOTA')),
                'bandwidth_mb' => self::limit($user->get('BANDWIDTH')),
            ],
            'usage' => [
                'web_domains' => (int) $user->get('U_WEB_DOMAINS', '0'),
                'dns_domains' => (int) $user->get('U_DNS_DOMAINS', '0'),
                'mail_domains' => (int) $user->get('U_MAIL_DOMAINS', '0'),
                'mail_accounts' => (int) $user->get('U_MAIL_ACCOUNTS', '0'),
                'databases' => (int) $user->get('U_DATABASES', '0'),
                'cron_jobs' => (int) $user->get('U_CRON_JOBS', '0'),
                'disk_mb' => (int) $user->get('U_DISK', '0'),
                'bandwidth_mb' => (int) $user->get('U_BANDWIDTH', '0'),
            ],
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'created_at' => self::localTime($user->get('DATE'), $user->get('TIME')),
        ];
    }

    /** A quota: a number, or null for unlimited. */
    public static function limit(string $value): ?int
    {
        return $value === '' || $value === 'unlimited' ? null : (int) $value;
    }

    /** DATE and TIME of a record as ISO 8601 local time, null when unknown. */
    public static function localTime(string $date, string $time): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return $date.'T'.(preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) === 1 ? $time : '00:00:00');
    }
}
