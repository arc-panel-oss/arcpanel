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

    /** Size of the staging file system in bytes; 0 when unknown. */
    public function totalBytes(): int
    {
        $total = @disk_total_space(is_dir($this->uploads()) ? $this->uploads() : $this->root);

        return $total === false ? 0 : (int) $total;
    }

    /**
     * Run $admit under the admission lock of the staging tree, so that two
     * requests of one account cannot both pass its limits (E39 W5 §7).
     *
     * @template T
     *
     * @param  callable(): T  $admit
     * @return T
     */
    public function admission(callable $admit): mixed
    {
        $lock = @fopen($this->root.'/.admission', 'c');
        if ($lock === false) {
            throw new RuntimeException('The file manager staging tree is missing (v-add-sys-panel-files).');
        }
        try {
            flock($lock, LOCK_EX);

            return $admit();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * What $user holds in the staging tree now: open upload sessions and the
     * bytes of their declared sizes plus those of live download tickets.
     * $except is a token that does not count (the ticket being admitted).
     *
     * @return array{sessions: int, bytes: int}
     */
    public function usage(string $user, ?int $now = null, string $except = ''): array
    {
        $now ??= time();
        $sessions = 0;
        $bytes = 0;
        foreach (scandir($this->uploads()) ?: [] as $token) {
            $meta = self::validToken($token) ? self::meta($this->uploads().'/'.$token.'/meta.json') : null;
            if ($meta !== null && ($meta['user'] ?? '') === $user && UploadSessions::live($this->uploads().'/'.$token.'/meta.json', $meta, $now)) {
                $sessions++;
                $bytes += (int) ($meta['size'] ?? 0);
            }
        }
        foreach (scandir($this->downloads()) ?: [] as $name) {
            $ticket = substr($name, 0, 32);
            if ($name !== $ticket.'.json' || ! self::validToken($ticket) || $ticket === $except) {
                continue;
            }
            $meta = self::meta($this->downloads().'/'.$name);
            if ($meta !== null && ($meta['user'] ?? '') === $user && $now - (int) ($meta['created'] ?? 0) <= DownloadTickets::TTL
                && is_file($this->downloads().'/'.$ticket)) {
                $bytes += (int) ($meta['size'] ?? 0);
            }
        }

        return ['sessions' => $sessions, 'bytes' => $bytes];
    }

    /** @return array<string, mixed>|null */
    private static function meta(string $file): ?array
    {
        $meta = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($meta) ? $meta : null;
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
