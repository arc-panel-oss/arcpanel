<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The text of a file (readFile, E34 batch 5 spec §4.1) or, without
 * `content` and `utf8`, the version a save wrote (saveFile, §4.2). `hash`
 * is what saveFile expects back.
 *
 * @property array{path: string, content?: string, hash: string, size: int, mtime: int, mime: string, utf8?: bool} $resource
 */
final class FileContentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $file = $this->resource;
        $read = array_key_exists('content', $file);

        return [
            /** @var string path relative to the home directory */
            'path' => $file['path'],
            /** @var string the text; bytes that are not UTF-8 are replaced (see `utf8`). readFile only */
            'content' => $this->when($read, fn () => $file['content'] ?? ''),
            /** @var string SHA-256 of the bytes on disk, in hexadecimal: send it back with saveFile */
            'hash' => $file['hash'],
            /** @var int bytes */
            'size' => $file['size'],
            /** @var string|null modification time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'modified_at' => $file['mtime'] > 0 ? UserResource::localTime(date('Y-m-d', $file['mtime']), date('H:i:s', $file['mtime'])) : null,
            /** @var string|null MIME type */
            'mime' => $file['mime'] !== '' ? $file['mime'] : null,
            /** @var bool false when the file is not valid UTF-8: `content` is scrubbed and saving it back would change the file. readFile only */
            'utf8' => $this->when($read, fn () => (bool) ($file['utf8'] ?? true)),
        ];
    }
}
