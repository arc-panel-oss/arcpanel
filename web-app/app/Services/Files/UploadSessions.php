<?php

namespace App\Services\Files;

use App\Arc\ArcCommandException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resumable chunked uploads (E3 spec §6.2). A session is a private folder
 * uploads/<token>/ with meta.json and chunks/<index>; it belongs to the login
 * user that started it and lives 24 hours. finish() assembles the chunks and
 * hands the file to v-add-fs-upload, which writes it as the target user.
 */
final class UploadSessions
{
    public const CHUNK = 8388608;

    public const TTL = 86400;

    /** Default FILE_MANAGER_UPLOAD_MAX */
    public const DEFAULT_MAX = '4G';

    public function __construct(
        private readonly FileStaging $staging,
        private readonly FileService $files,
    ) {}

    /** FILE_MANAGER_UPLOAD_MAX in bytes (K, M, G suffixes). */
    public static function maxBytes(string $value): int
    {
        if (preg_match('/^\s*(\d+)\s*([KMG]?)B?\s*$/i', $value !== '' ? $value : self::DEFAULT_MAX, $m) !== 1) {
            return self::maxBytes(self::DEFAULT_MAX);
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
    }

    /**
     * Start or resume. Returns the session's token, chunk size and the
     * indexes already received.
     *
     * @param  array{path: FilePath, name: string, relativePath: string, size: int, lastModified: int}  $file
     * @return array{token: string, chunkSize: int, received: list<int>}
     */
    public function init(string $login, string $user, array $file, int $maxBytes, bool $quotaEnforced): array
    {
        if ($file['size'] > $maxBytes) {
            throw new HttpException(413, sprintf(__('Files larger than %s cannot be uploaded.'), self::human($maxBytes)));
        }
        $meta = [
            'login' => $login,
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

        if ($quotaEnforced && $this->files->account($user)->exceedsQuota($file['size'])) {
            throw new UploadRefused('E_DISK', __('Disk quota exceeded.'), 422);
        }
        if ($this->staging->freeBytes() < (int) ceil($file['size'] * 1.05)) {
            throw new UploadRefused('E_DISK', __('The server cannot take this upload now.'), 507);
        }
        if (count($this->tokens()) > 100) {
            $this->gc();
        }

        $token = FileStaging::token();
        $dir = $this->staging->newSessionDir($token);
        mkdir($dir.'/chunks', 0700);
        $meta += ['token' => $token, 'chunkSize' => self::CHUNK, 'chunks' => max(1, (int) ceil($file['size'] / self::CHUNK)), 'created' => time()];
        file_put_contents($dir.'/meta.json', json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        chmod($dir.'/meta.json', 0600);

        return ['token' => $token, 'chunkSize' => self::CHUNK, 'received' => []];
    }

    /**
     * Store one chunk from $stream (the request body).
     *
     * @param  resource  $stream
     * @return list<int> indexes received so far
     */
    public function putChunk(string $login, string $user, string $token, int $index, $stream): array
    {
        $meta = $this->session($login, $user, $token);
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

        return $this->received($token);
    }

    /**
     * Assemble and hand off. Without $onConflict an existing destination
     * answers FileConflict before anything is assembled. The session is
     * gone afterwards, also when the hand-off fails.
     *
     * @return array{path: FilePath, name: string}
     */
    public function finish(string $login, string $user, string $token, ?string $onConflict): array
    {
        $meta = $this->session($login, $user, $token);
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
        $target = $folder->child((string) $meta['name']);

        if ($onConflict === null && $this->files->find($user, $target) !== null) {
            throw new FileConflict([$target->name()]);
        }
        if ($onConflict === 'skip') {
            $this->cancel($login, $user, $token);

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

    public function cancel(string $login, string $user, string $token): void
    {
        $this->session($login, $user, $token);
        FileStaging::removeTree($this->staging->uploads().'/'.$token);
    }

    /**
     * Remove sessions older than TTL and editor saves left behind.
     *
     * @return int sessions removed
     */
    public function gc(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach ($this->tokens() as $token) {
            $dir = $this->staging->uploads().'/'.$token;
            $meta = $dir.'/meta.json';
            $created = is_file($meta) ? (int) (json_decode((string) file_get_contents($meta), true)['created'] ?? filemtime($meta)) : (int) filemtime($dir);
            $age = is_file($meta) ? self::TTL : 3600;
            if ($now - $created > $age) {
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
    private function session(string $login, string $user, string $token): array
    {
        $file = $this->staging->uploads().'/'.$token.'/meta.json';
        $meta = FileStaging::validToken($token) && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($meta) || time() - (int) ($meta['created'] ?? 0) > self::TTL) {
            throw new UploadRefused('E_NOTEXIST', __('This upload has expired; it starts again.'), 404);
        }
        if (($meta['login'] ?? '') !== $login || ($meta['user'] ?? '') !== $user) {
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
            if (! is_array($meta) || time() - (int) ($meta['created'] ?? 0) > self::TTL) {
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

    private static function human(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $size) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1), '0'), '.').' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
