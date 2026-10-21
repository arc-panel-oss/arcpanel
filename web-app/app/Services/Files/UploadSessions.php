<?php

namespace App\Services\Files;

use App\Arc\ArcCommandException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resumable chunked uploads (E3 spec §6.2). A session is a private folder
 * uploads/<token>/ with meta.json and chunks/<index>; it belongs to the owner
 * that started it (FileStaging::owner(): the login user on the page, the
 * access key in REST API v2, E34 batch 5 D50) and lives 24 hours, or 2
 * hours after its last chunk (E39 W5 §7). The account's open sessions and
 * staged bytes are limited (StagingLimits). finish() assembles the chunks
 * and hands the file to v-add-fs-upload, which writes it as the target user.
 */
final class UploadSessions
{
    public const CHUNK = 8388608;

    public const TTL = 86400;

    /** Seconds after the last chunk that a session stays open */
    public const IDLE = 7200;

    /** Default FILE_MANAGER_UPLOAD_MAX */
    public const DEFAULT_MAX = '4G';

    public function __construct(
        private readonly FileStaging $staging,
        private readonly FileService $files,
        private readonly StagingLimits $limits,
    ) {}

    /** FILE_MANAGER_UPLOAD_MAX in bytes (K, M, G suffixes). */
    public static function maxBytes(string $value): int
    {
        return self::bytes($value, self::DEFAULT_MAX);
    }

    /** A size with a K, M or G suffix in bytes; $default when empty or invalid. */
    public static function bytes(string $value, string $default): int
    {
        if (preg_match('/^\s*(\d+)\s*([KMG]?)B?\s*$/i', $value !== '' ? $value : $default, $m) !== 1) {
            return $value === $default ? 0 : self::bytes($default, $default);
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
    }

    /**
     * Whether the session of $meta (read from $file) is still open: younger
     * than TTL and touched by a chunk within IDLE.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function live(string $file, array $meta, int $now): bool
    {
        clearstatcache(true, $file);

        return $now - (int) ($meta['created'] ?? 0) <= self::TTL && $now - (int) @filemtime($file) <= self::IDLE;
    }

    /**
     * Start or resume. Returns the session's token, chunk size and the
     * indexes already received.
     *
     * @param  array{path: FilePath, name: string, relativePath: string, size: int, lastModified: int}  $file
     * @return array{token: string, chunkSize: int, received: list<int>}
     */
    public function init(string $owner, string $user, array $file, int $maxBytes, bool $quotaEnforced): array
    {
        if ($file['size'] > $maxBytes) {
            throw new HttpException(413, self::tooLarge($maxBytes));
        }
        $meta = [
            'owner' => $owner,
            'user' => $user,
            'path' => $file['path']->relative(),
            'name' => $file['name'],
            'relativePath' => $file['relativePath'],
            'size' => $file['size'],
            'lastModified' => $file['lastModified'],
        ];

        $existing = $this->findResumable($meta);
        if ($existing !== null) {
            return ['token' => $existing['token'], 'chunkSize' => (int) $existing['chunkSize'], 'received' => $this->received($existing['token'])];
        }

        if (count($this->tokens()) > 100) {
            $this->gc();
        }

        // Admitted and written under one lock: two requests of the account
        // cannot both pass its limits
        return $this->staging->admission(function () use ($user, $file, $quotaEnforced, $meta): array {
            $this->admit($user, $file['size'], $quotaEnforced);
            $token = FileStaging::token();
            $dir = $this->staging->newSessionDir($token);
            mkdir($dir.'/chunks', 0700);
            $meta += ['token' => $token, 'chunkSize' => self::CHUNK, 'chunks' => max(1, (int) ceil($file['size'] / self::CHUNK)), 'created' => time()];
            file_put_contents($dir.'/meta.json', json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            chmod($dir.'/meta.json', 0600);

            return ['token' => $token, 'chunkSize' => self::CHUNK, 'received' => []];
        });
    }

    /**
     * The checks of a new session: the package's disk quota when quotas are
     * enforced (422), the account's open sessions and staged bytes, and
     * free space for the chunks and the assembled file in the staging tree
     * above its reserve (507).
     */
    public function admit(string $user, int $size, bool $quotaEnforced): void
    {
        if ($quotaEnforced && $this->files->account($user)->exceedsQuota($size)) {
            throw new UploadRefused('E_DISK', __('Disk quota exceeded.'), 422);
        }
        $usage = $this->staging->usage($user);
        if ($usage['sessions'] >= $this->limits->sessions) {
            throw new UploadRefused('E_LIMIT', sprintf(__('This account has %d uploads open; finish or cancel one first.'), $usage['sessions']), 507);
        }
        $this->limits->admitBytes($usage['bytes'], $size);
        if ($this->staging->freeBytes() - (int) ceil($size * 1.05) < $this->limits->reserveBytes($this->staging->totalBytes())) {
            throw new UploadRefused('E_DISK', __('The server cannot take this upload now.'), 507);
        }
    }

    /** The message of an upload above FILE_MANAGER_UPLOAD_MAX (413). */
    public static function tooLarge(int $maxBytes): string
    {
        return sprintf(__('Files larger than %s cannot be uploaded.'), self::human($maxBytes));
    }

    /**
     * Store one chunk from $stream (the request body).
     *
     * @param  resource  $stream
     * @return list<int> indexes received so far
     */
    public function putChunk(string $owner, string $user, string $token, int $index, $stream): array
    {
        $meta = $this->session($owner, $user, $token);
        $chunks = (int) $meta['chunks'];
        if ($index < 0 || $index >= $chunks) {
            throw new UploadRefused('E_INVALID', __('Invalid upload chunk.'), 422);
        }
        $expected = $index < $chunks - 1 ? (int) $meta['chunkSize'] : (int) $meta['size'] - (int) $meta['chunkSize'] * ($chunks - 1);

        $dir = $this->staging->uploads().'/'.$token.'/chunks';
        $part = $dir.'/'.$index.'.part';
        $out = fopen($part, 'wb');
        if ($out === false) {
            throw new UploadRefused('E_DISK', __('The server cannot take this upload now.'), 507);
        }
        $written = stream_copy_to_stream($stream, $out, $expected + 1);
        fflush($out);
        fclose($out);
        if ($written !== $expected) {
            @unlink($part);
            throw new UploadRefused('E_INVALID', __('Invalid upload chunk.'), 422);
        }
        rename($part, $dir.'/'.$index);
        // The session stays open for IDLE after its last chunk
        touch($this->staging->uploads().'/'.$token.'/meta.json');

        return $this->received($token);
    }

    /**
     * Where a complete session goes: its folder (with the folders of a
     * folder upload when nested), the file's path and the staged file the
     * hand-off reads. An incomplete session answers 422.
     *
     * @return array{folder: FilePath, target: FilePath, nested: bool, staged: string}
     */
    public function target(string $owner, string $user, string $token): array
    {
        $meta = $this->session($owner, $user, $token);
        if (count($this->received($token)) !== (int) $meta['chunks']) {
            throw new UploadRefused('E_INVALID', __('The upload is not complete.'), 422);
        }

        $folder = FilePath::from((string) $meta['path']);
        $relative = trim((string) $meta['relativePath'], '/');
        // Folder uploads create the folders of the file's relative path
        $nested = str_contains($relative, '/');
        if ($nested) {
            $folder = $folder->join(substr($relative, 0, (int) strrpos($relative, '/')));
        }

        return [
            'folder' => $folder,
            'target' => $folder->child((string) $meta['name']),
            'nested' => $nested,
            'staged' => $this->staging->uploads().'/'.$token.'/file',
        ];
    }

    /**
     * Assemble and hand off. Without $onConflict an existing destination
     * answers FileConflict before anything is assembled. The session is
     * gone afterwards, also when the hand-off fails.
     *
     * @return array{path: FilePath, name: string}
     */
    public function finish(string $owner, string $user, string $token, ?string $onConflict): array
    {
        ['folder' => $folder, 'target' => $target, 'nested' => $nested] = $this->target($owner, $user, $token);
        $meta = $this->session($owner, $user, $token);

        if ($onConflict === null && $this->files->find($user, $target) !== null) {
            throw new FileConflict([$target->name()]);
        }
        if ($onConflict === 'skip') {
            $this->cancel($owner, $user, $token);

            return ['path' => $target, 'name' => $target->name()];
        }

        $dir = $this->staging->uploads().'/'.$token;
        try {
            $file = $this->assemble($dir, $meta);
            if ($nested) {
                $this->files->ensureDirectory($user, $folder);
            }
            $final = $this->files->placeUpload($user, $file, $target, match ($onConflict) {
                'overwrite' => 'overwrite',
                'rename' => 'rename',
                default => 'fail',
            });
        } catch (ArcCommandException $e) {
            throw UploadRefused::fromCommand($e);
        } finally {
            FileStaging::removeTree($dir);
        }

        return ['path' => $final, 'name' => $final->name()];
    }

    public function cancel(string $owner, string $user, string $token): void
    {
        $this->session($owner, $user, $token);
        FileStaging::removeTree($this->staging->uploads().'/'.$token);
    }

    /**
     * Remove sessions that are no longer open (TTL, IDLE) and editor saves
     * left behind.
     *
     * @return int sessions removed
     */
    public function gc(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach ($this->tokens() as $token) {
            $dir = $this->staging->uploads().'/'.$token;
            $file = $dir.'/meta.json';
            $meta = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $stale = is_file($file)
                ? ! is_array($meta) || ! self::live($file, $meta, $now)
                : $now - (int) filemtime($dir) > 3600;
            if ($stale) {
                FileStaging::removeTree($dir);
                $removed++;
            }
        }

        return $removed;
    }

    /** @return list<string> */
    private function tokens(): array
    {
        $tokens = [];
        foreach (scandir($this->staging->uploads()) ?: [] as $name) {
            if (FileStaging::validToken($name) && is_dir($this->staging->uploads().'/'.$name)) {
                $tokens[] = $name;
            }
        }

        return $tokens;
    }

    /** @return array<string, mixed> */
    private function session(string $owner, string $user, string $token): array
    {
        $file = $this->staging->uploads().'/'.$token.'/meta.json';
        $meta = FileStaging::validToken($token) && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($meta) || ! self::live($file, $meta, time())) {
            throw new UploadRefused('E_NOTEXIST', __('This upload has expired; it starts again.'), 404);
        }
        if (($meta['owner'] ?? '') !== $owner || ($meta['user'] ?? '') !== $user) {
            throw new UploadRefused('E_FORBIDEN', __('This upload belongs to another session.'), 403);
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $wanted
     * @return array<string, mixed>|null
     */
    private function findResumable(array $wanted): ?array
    {
        foreach ($this->tokens() as $token) {
            $file = $this->staging->uploads().'/'.$token.'/meta.json';
            $meta = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            if (! is_array($meta) || ! self::live($file, $meta, time())) {
                continue;
            }
            if (array_intersect_key($meta, $wanted) == $wanted) {
                return $meta;
            }
        }

        return null;
    }

    /** @return list<int> */
    private function received(string $token): array
    {
        $indexes = [];
        foreach (scandir($this->staging->uploads().'/'.$token.'/chunks') ?: [] as $name) {
            if (ctype_digit($name)) {
                $indexes[] = (int) $name;
            }
        }
        sort($indexes);

        return $indexes;
    }

    /** @param  array<string, mixed>  $meta */
    private function assemble(string $dir, array $meta): string
    {
        $file = $dir.'/file';
        $out = fopen($file, 'wb');
        if ($out === false) {
            throw new UploadRefused('E_DISK', __('The server cannot take this upload now.'), 507);
        }
        for ($i = 0; $i < (int) $meta['chunks']; $i++) {
            $chunk = $dir.'/chunks/'.$i;
            $in = fopen($chunk, 'rb');
            if ($in === false || stream_copy_to_stream($in, $out) === false) {
                fclose($out);
                throw new UploadRefused('E_DISK', __('The server cannot take this upload now.'), 507);
            }
            fclose($in);
            unlink($chunk);
        }
        fclose($out);
        chmod($file, 0600);
        clearstatcache(true, $file);
        if (filesize($file) !== (int) $meta['size']) {
            throw new UploadRefused('E_INVALID', __('The upload is not complete.'), 422);
        }

        return $file;
    }

    public static function human(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1), '0'), '.').' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
