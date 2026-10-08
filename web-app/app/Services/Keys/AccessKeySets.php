<?php

namespace App\Services\Keys;

/**
 * One-line descriptions of the permission sets that install/common/api
 * ships: the hints of the access key page (REST API v2 sets) and the
 * descriptions of `GET /access-key-sets` (E34 batch 6 §4.6).
 */
final class AccessKeySets
{
    /**
     * The hints under the REST API v2 sets on the access key page (E34
     * spec §4.2, batch 2 §5.2).
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
        ];
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
