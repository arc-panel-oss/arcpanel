<?php

namespace App\Services\Files;

use App\Arc\SysConfig;

/**
 * What the staging tree holds per hosting account (E39 W5 §7): open upload
 * sessions, bytes of open uploads and live download tickets together, and
 * the share of the staging file system that admission keeps free. Every
 * login and key acting on one account shares its limits.
 */
final class StagingLimits
{
    public const DEFAULT_SESSIONS = 4;

    public const DEFAULT_BYTES = '8G';

    public const DEFAULT_RESERVE = 10;

    public function __construct(
        public readonly int $sessions,
        public readonly int $bytes,
        public readonly int $reserve,
    ) {}

    /** FILE_STAGING_SESSIONS, FILE_STAGING_LIMIT and FILE_STAGING_RESERVE, with their defaults. */
    public static function fromConfig(SysConfig $config): self
    {
        $sessions = $config->get('FILE_STAGING_SESSIONS');
        $reserve = $config->get('FILE_STAGING_RESERVE');

        return new self(
            ctype_digit($sessions) && (int) $sessions > 0 ? (int) $sessions : self::DEFAULT_SESSIONS,
            UploadSessions::bytes($config->get('FILE_STAGING_LIMIT'), self::DEFAULT_BYTES),
            ctype_digit($reserve) && (int) $reserve < 100 ? (int) $reserve : self::DEFAULT_RESERVE,
        );
    }

    /** Whether $size more bytes fit next to the $used bytes an account holds (507 otherwise). */
    public function admitBytes(int $used, int $size): void
    {
        if ($used + $size > $this->bytes) {
            throw new UploadRefused('E_LIMIT', sprintf(
                __('Uploads and downloads in progress for this account may hold at most %s at a time; %s is in use.'),
                UploadSessions::human($this->bytes),
                UploadSessions::human($used),
            ), 507);
        }
    }

    /** The bytes of a file system of $total bytes that admission keeps free. */
    public function reserveBytes(int $total): int
    {
        return (int) ceil($total * $this->reserve / 100);
    }
}
