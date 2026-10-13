<?php

namespace App\Services\Keys;

/**
 * One-line descriptions of the permission sets that install/common/api
 * ships: the descriptions of `GET /access-key-sets` (E34 batch 6 §4.6) and
 * the short descriptions and groups of the access key pages.
 */
final class AccessKeySets
{
    /**
     * The descriptions of the REST API v2 sets (E34 spec §4.2, batch 2
     * §5.2), as `GET /access-key-sets` gives them.
     *
     * @return array<string, string>
     */
    public static function hints(): array
    {
        return [
            'v2-read' => __('REST API v2 only: every read endpoint, no change.'),
            'v2-write' => __('REST API v2 only: every endpoint, changes included.'),
            'v2-accounts' => __('REST API v2 only: users and packages.'),
            'v2-web' => __('REST API v2 only: web domains.'),
            'v2-dns' => __('REST API v2 only: DNS zones and records.'),
            'v2-mail' => __('REST API v2 only: mail domains and accounts.'),
            'v2-db' => __('REST API v2 only: databases.'),
            'v2-cron' => __('REST API v2 only: cron jobs and reports.'),
            'v2-backup' => __('REST API v2 only: backups and tasks.'),
            'v2-apps' => __('REST API v2 only: Node.js, Docker apps and ports.'),
            'v2-server' => __('REST API v2 only: server-wide operations of administrators.'),
            'v2-files' => __('REST API v2 only: files of the home directory.'),
            'v2-secrets' => __('REST API v2 only: the secret store; values go in, never out.'),
            'v2-security' => __('REST API v2 only: malware scans, findings and quarantine.'),
        ];
    }

    /**
     * A short description of a set without its API version, which the
     * group heading of the access key pages gives (spec 2026-10-08-008 U4).
     */
    public static function summary(string $set): ?string
    {
        return [
            'v2-read' => __('Every read endpoint, no change.'),
            'v2-write' => __('Every endpoint, changes included.'),
            'v2-accounts' => __('Users and packages.'),
            'v2-web' => __('Web domains.'),
            'v2-dns' => __('DNS zones and records.'),
            'v2-mail' => __('Mail domains and accounts.'),
            'v2-db' => __('Databases.'),
            'v2-cron' => __('Cron jobs and reports.'),
            'v2-backup' => __('Backups and tasks.'),
            'v2-apps' => __('Node.js, Docker apps and ports.'),
            'v2-server' => __('Server-wide operations of administrators.'),
            'v2-files' => __('Files of the home directory.'),
            'v2-secrets' => __('The secret store; values go in, never out.'),
            'v2-security' => __('Malware scans, findings and quarantine.'),
            'billing' => __('User accounts and packages for a billing system.'),
            'mail-accounts' => __('Mail accounts.'),
            'phpmyadmin-sso' => __('phpMyAdmin single sign-on.'),
            'purge-nginx-cache' => __('Purge the NGINX cache of web domains.'),
            'sync-dns-cluster' => __('Synchronisation of a DNS cluster.'),
            'update-dns-records' => __('Change DNS records.'),
        ][$set] ?? null;
    }

    /** Whether a set belongs to REST API v2 (the `v2-*` sets of install/common/api). */
    public static function isV2(string $set): bool
    {
        return str_starts_with($set, 'v2-');
    }

    /**
     * The sets of the add form in groups: full access of REST API v2, its
     * areas, then REST API v1; empty groups are left out (spec U3).
     *
     * @param  list<string>  $sets
     * @return list<array{label: string, note: ?string, sets: list<string>}>
     */
    public static function groups(array $sets): array
    {
        $full = array_values(array_intersect(['v2-read', 'v2-write'], $sets));
        $areas = array_values(array_filter($sets, fn (string $set) => self::isV2($set) && ! in_array($set, $full, true)));
        $v1 = array_values(array_filter($sets, fn (string $set) => ! self::isV2($set)));

        return array_values(array_filter([
            ['label' => __('REST API v2: full access'), 'note' => __('These cover every area; no area set is needed with them.'), 'sets' => $full],
            ['label' => __('REST API v2: by area'), 'note' => null, 'sets' => $areas],
            ['label' => __('REST API v1'), 'note' => null, 'sets' => $v1],
        ], fn (array $group) => $group['sets'] !== []));
    }

    /** A one-line description of a set, or null for a set the panel does not ship. */
    public static function description(string $set): ?string
    {
        $v1 = [
            'billing' => __('REST API v1: user accounts and packages for a billing system.'),
            'mail-accounts' => __('REST API v1: mail accounts.'),
            'phpmyadmin-sso' => __('REST API v1: phpMyAdmin single sign-on.'),
            'purge-nginx-cache' => __('REST API v1: purge the NGINX cache of web domains.'),
            'sync-dns-cluster' => __('REST API v1: synchronisation of a DNS cluster.'),
            'update-dns-records' => __('REST API v1: change DNS records.'),
        ];

        return match ($set) {
            'v2-accounts' => __('REST API v2 only: users, packages and the account (logs, statistics, notifications, search, SSH and access keys).'),
            default => self::hints()[$set] ?? $v1[$set] ?? null,
        };
    }
}
