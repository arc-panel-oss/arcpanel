<?php

namespace App\Services\Files;

use App\Arc\ArcCommandException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Download tickets (E3 spec §6.3): v-add-fs-download writes the bytes into
 * downloads/<ticket>, the panel writes <ticket>.json with its owner
 * (FileStaging::owner(): the login user on the page, the access key in REST
 * API v2), and arcpanel-nginx sends the file through X-Accel-Redirect. A
 * ticket serves its owner for an hour (range requests of previews), then
 * gc() removes it.
 */
final class DownloadTickets
{
    public const TTL = 3600;

    /** Internal NGINX location of the downloads directory */
    public const ACCEL_PREFIX = '/_arc_files/';

    public function __construct(
        private readonly FileStaging $staging,
        private readonly FileService $files,
    ) {}

    /**
     * @param  list<FilePath>  $paths
     * @return array{ticket: string, name: string, size: int, mime: string}
     */
    public function create(string $owner, string $user, array $paths): array
    {
        $ticket = FileStaging::token();
        try {
            $info = $this->files->prepareDownload($user, $ticket, $paths);
        } catch (ArcCommandException $e) {
            throw UploadRefused::fromCommand($e);
        }
        // `file` names what it does not recognise application/octet-stream;
        // the extension then tells the browser what it is (fonts, media)
        if ($info['mime'] === 'application/octet-stream') {
            $guesses = MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($info['name'], PATHINFO_EXTENSION)));
            // mp4 is application/mp4 first; the media types are what browsers play
            $media = array_values(array_filter($guesses, fn (string $type) => preg_match('#^(video|audio|image|font)/#', $type) === 1));
            $info['mime'] = $media[0] ?? $guesses[0] ?? $info['mime'];
        }
        $meta = ['owner' => $owner, 'user' => $user, 'created' => time(), ...$info];
        $sidecar = $this->staging->downloads().'/'.$ticket.'.json';
        file_put_contents($sidecar, json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($sidecar, 0600);

        return ['ticket' => $ticket, ...$info];
    }

    /**
     * The ticket's sidecar, when it belongs to $owner and $user and is
     * younger than an hour; 404 otherwise.
     *
     * @return array{name: string, size: int, mime: string}
     */
    public function resolve(string $owner, string $user, string $ticket): array
    {
        $sidecar = $this->staging->downloads().'/'.$ticket.'.json';
        $meta = FileStaging::validToken($ticket) && is_file($sidecar) ? json_decode((string) file_get_contents($sidecar), true) : null;
        if (! is_array($meta) || ($meta['owner'] ?? '') !== $owner || ($meta['user'] ?? '') !== $user
            || time() - (int) ($meta['created'] ?? 0) > self::TTL || ! is_file($this->staging->downloads().'/'.$ticket)) {
            throw new NotFoundHttpException;
        }

        return ['name' => (string) ($meta['name'] ?? 'download'), 'size' => (int) ($meta['size'] ?? 0), 'mime' => (string) ($meta['mime'] ?? 'application/octet-stream')];
    }

    /**
     * The headers that send a ticket (E3 spec §6.3, E34 batch 5 §4.4):
     * arcpanel-nginx serves the bytes of X-Accel-Redirect with the panel's
     * Content-Type. $inline shows images, audio, video, PDF and plain text
     * in the browser; SVG can carry scripts and is never inline. Every
     * answer but a PDF gets the CSP `sandbox` directive: browsers refuse to
     * show a PDF in a sandbox, and their PDF viewer runs no page scripts.
     *
     * @param  array{name: string, mime: string}  $meta
     * @return array<string, string>
     */
    public static function headers(string $ticket, array $meta, bool $inline): array
    {
        $inline = $inline && $meta['mime'] !== 'image/svg+xml'
            && preg_match('#^(image/|video/|audio/|application/pdf$|text/plain$)#', $meta['mime']) === 1;
        $fallback = preg_replace('/[^\x20-\x7e]|["\\\\]/', '_', $meta['name']) ?: 'download';

        return [
            'X-Accel-Redirect' => self::ACCEL_PREFIX.$ticket,
            'Content-Type' => $meta['mime'],
            'Content-Disposition' => sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $inline ? 'inline' : 'attachment', $fallback, rawurlencode($meta['name'])),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'"
                .($meta['mime'] === 'application/pdf' ? '' : '; sandbox'),
            'Cache-Control' => 'private, no-store',
        ];
    }

    /** @return int tickets removed */
    public function gc(?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach (scandir($this->staging->downloads()) ?: [] as $name) {
            $ticket = substr($name, 0, 32);
            if (! FileStaging::validToken($ticket) || ($name !== $ticket && $name !== $ticket.'.json')) {
                continue;
            }
            $file = $this->staging->downloads().'/'.$name;
            if ($now - (int) @filemtime($file) > self::TTL) {
                @unlink($file);
                if ($name === $ticket) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
