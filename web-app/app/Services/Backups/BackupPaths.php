<?php

namespace App\Services\Backups;

/**
 * Local backup archive paths shared by the Backups page and API v2 (E34 D35).
 */
final class BackupPaths
{
    /** Absolute path of an archive on disk. */
    public static function localFile(string $name): string
    {
        return rtrim((string) config('arc.backup_dir', '/backup'), '/').'/'.$name;
    }

    /** Internal nginx location for {@see localFile()} (X-Accel-Redirect). */
    public static function accelRedirect(string $name): string
    {
        return rtrim((string) config('arc.backup_dir', '/backup'), '/').'/'.rawurlencode($name);
    }
}
