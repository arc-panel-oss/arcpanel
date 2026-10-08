<?php

namespace App\Services\Files;

use App\Auth\Principal;
use RuntimeException;

/**
 * The staging tree of the file manager (E3 spec §5.1), owned by arcpanelweb:
 * uploads/<token>/ for upload sessions and editor saves, downloads/<ticket>
 * for download tickets with a <ticket>.json sidecar.
 */
final class FileStaging
{
    public function __construct(private readonly string $root) {}

    public static function fromConfig(): self
    {
        return new self(rtrim((string) config('arc.files_staging'), '/'));
    }

    /**
     * Who owns the upload sessions and download tickets of a request: the
     * login user on the page, `api:KEY_ID` for a REST API v2 key (E34
     * batch 5 D50), so another key of the same user cannot use them. A
     * user name never holds a colon.
     */
    public static function owner(Principal $principal): string
    {
        return $principal->accessKey !== null ? 'api:'.$principal->accessKey : $principal->loginUser;
    }

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1;
    }

    public function uploads(): string
    {
        return $this->root.'/uploads';
    }

    public function downloads(): string
    {
        return $this->root.'/downloads';
    }

    /** A new private directory below uploads/. */
    public function newSessionDir(string $token): string
    {
        $dir = $this->uploads().'/'.$token;
        if (! is_dir($this->uploads()) && ! @mkdir($this->uploads(), 0700, true) && ! is_dir($this->uploads())) {
            throw new RuntimeException('The file manager staging tree is missing (v-add-sys-panel-files).');
        }
        if (! @mkdir($dir, 0700)) {
            throw new RuntimeException('Cannot create a staging directory.');
        }

        return $dir;
    }

    /** Free bytes on the staging file system. */
    public function freeBytes(): int
    {
        $free = @disk_free_space(is_dir($this->uploads()) ? $this->uploads() : $this->root);

        return $free === false ? PHP_INT_MAX : (int) $free;
    }

    public static function removeTree(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::removeTree($dir.'/'.$name);
            }
        }
        @rmdir($dir);
    }
}
