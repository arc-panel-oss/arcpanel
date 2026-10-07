<?php

namespace App\Http\Resources\V2;

use App\Services\Files\FilePath;

/**
 * One entry of a restic snapshot folder (E34 batch 5 FileEntry shape).
 */
final class SnapshotFileEntryResource
{
    /**
     * @param  array{name: string, type: string, dir: bool, size: int, mode: string, mtime: int, link: string, linkType: string, linkInside: bool, validName: bool, writable: bool}  $entry
     * @return array<string, mixed>
     */
    public static function from(array $entry, string $home, string $listingPath): array
    {
        $home = rtrim($home, '/');
        $listingPath = rtrim($listingPath, '/');
        $relativeDir = $listingPath === $home ? '' : substr($listingPath, strlen($home) + 1);
        $path = $relativeDir === '' ? $entry['name'] : $relativeDir.'/'.$entry['name'];

        return [
            'name' => $entry['name'],
            'path' => $path,
            'type' => match ($entry['type']) {
                'd' => 'directory',
                'f' => 'file',
                'l' => 'link',
                default => 'other',
            },
            'size' => $entry['size'],
            'mode' => $entry['mode'],
            'owner' => '',
            'group' => '',
            'modified_at' => UserResource::localTime(
                date('Y-m-d', $entry['mtime']),
                date('H:i:s', $entry['mtime']),
            ),
            'link_target' => $entry['link'] !== '' ? $entry['link'] : null,
            'link_type' => self::linkType($entry),
            'link_inside' => $entry['linkInside'],
            'mime' => null,
            'writable' => false,
            'valid_name' => $entry['validName'] && FilePath::validName($entry['name']),
        ];
    }

    /**
     * @param  array{type: string, linkType: string}  $entry
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
