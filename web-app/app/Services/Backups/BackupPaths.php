<?php

namespace App\Services\Backups;

use App\Arc\SysConfig;

/**
 * Local backup archive paths shared by the Backups page and API v2 (E34 D35).
 *
 * The archives live in the `BACKUP` folder of arcpanel.conf. arcpanel-nginx
 * serves them only through its internal `location /backup/ { root /; }`
 * (src/deb/nginx/nginx.conf), so the redirect URI is always under
 * `/backup/`, and it only reaches the archive when the `BACKUP` folder is
 * the folder nginx serves there (`arc.backup_dir`, `/backup` by default).
 */
final class BackupPaths
{
    /** URI prefix of the internal nginx location. */
    public const ACCEL_PREFIX = '/backup/';

    public function __construct(private readonly SysConfig $config) {}

    /** The server's backup folder (`BACKUP`, as v-backup-user uses it). */
    public function folder(): string
    {
        $folder = rtrim($this->config->get('BACKUP', '/backup'), '/');

        return $folder !== '' ? $folder : '/backup';
    }

    /** Absolute path of an archive on disk. */
    public function localFile(string $name): string
    {
        return $this->folder().'/'.$name;
    }

    /**
     * `X-Accel-Redirect` URI of {@see localFile()}, or null when nginx does
     * not serve the backup folder at its internal location.
     */
    public function accelRedirect(string $name): ?string
    {
        return $this->served() ? self::ACCEL_PREFIX.rawurlencode($name) : null;
    }

    private function served(): bool
    {
        $served = rtrim((string) config('arc.backup_dir', '/backup'), '/');
        $folder = $this->folder();
        if ($folder === $served) {
            return true;
        }
        $real = realpath($folder);

        return $real !== false && $real === realpath($served);
    }
}
