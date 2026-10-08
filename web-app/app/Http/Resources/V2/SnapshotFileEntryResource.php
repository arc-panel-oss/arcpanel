<?php

namespace App\Http\Resources\V2;

use App\Services\Files\FilePath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of a restic snapshot folder (E34 batch 5 FileEntry shape).
 *
 * @property SnapshotListingEntry $resource
 */
final class SnapshotFileEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entry = $this->resource->raw;
        $home = rtrim($this->resource->home, '/');
        $listingPath = rtrim($this->resource->listingPath, '/');
        $relativeDir = $listingPath === $home ? '' : substr($listingPath, strlen($home) + 1);
        $path = $relativeDir === '' ? $entry['name'] : $relativeDir.'/'.$entry['name'];

        return [
            /** @var string */
            'name' => $entry['name'],
            /** @var string path relative to the home directory */
            'path' => $path,
            /** @var 'file'|'directory'|'link'|'other' */
            'type' => match ($entry['type']) {
                'd' => 'directory',
                'f' => 'file',
                'l' => 'link',
                default => 'other',
            },
            /** @var int */
            'size' => $entry['size'],
            /** @var string octal mode */
            'mode' => $entry['mode'],
            /** @var string */
            'owner' => '',
            /** @var string */
            'group' => '',
            /** @var string|null creation time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'modified_at' => UserResource::localTime(
                date('Y-m-d', $entry['mtime']),
                date('H:i:s', $entry['mtime']),
            ),
            /** @var string|null */
            'link_target' => $entry['link'] !== '' ? $entry['link'] : null,
            /** @var 'd'|'f'|'missing'|'o'|null */
            'link_type' => self::linkType($entry),
            /** @var bool */
            'link_inside' => $entry['linkInside'],
            /** @var string|null */
            'mime' => null,
            /** @var bool */
            'writable' => false,
            /** @var bool */
            'valid_name' => $entry['validName'] && FilePath::validName($entry['name']),
        ];
    }

    /**
     * @param  array{name: string, type: string, dir: bool, size: int, mode: string, mtime: int, link: string, linkType: string, linkInside: bool, validName: bool, writable: bool}  $entry
     * @return array<string, mixed>
     */
    public static function from(array $entry, string $home, string $listingPath): array
    {
        return (new self(new SnapshotListingEntry($entry, $home, $listingPath)))->resolve(Request::create('/'));
    }

    /**
     * @param  array{type: string, linkType: string}  $entry
     * @return 'd'|'f'|'missing'|'o'|null
     */
    private static function linkType(array $entry): ?string
    {
        if ($entry['type'] !== 'l') {
            return null;
        }

        return match ($entry['linkType']) {
            'd', 'f', 'missing', 'o' => $entry['linkType'],
            default => 'o',
        };
    }
}
