<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One file, folder or link of a user's home directory (E34 batch 5 spec
 * §4.1, D55): the shape of SnapshotFileEntry, with the live owner, MIME
 * type and write access.
 *
 * @property LocatedFileEntry $resource
 */
final class FileEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entry = $this->resource->entry;

        return [
            /** @var string */
            'name' => $entry->name,
            /** @var string path relative to the home directory; empty for the home directory itself */
            'path' => $this->resource->path,
            /** @var 'file'|'directory'|'link'|'other' */
            'type' => match ($entry->type) {
                'd' => 'directory',
                'f' => 'file',
                'l' => 'link',
                default => 'other',
            },
            /** @var int bytes */
            'size' => $entry->size,
            /** @var string octal mode, e.g. 0644 */
            'mode' => $entry->mode,
            /** @var string */
            'owner' => $entry->owner,
            /** @var string */
            'group' => $entry->group,
            /** @var string|null modification time in the server's time zone, YYYY-MM-DDTHH:MM:SS */
            'modified_at' => $entry->mtime > 0 ? UserResource::localTime(date('Y-m-d', $entry->mtime), date('H:i:s', $entry->mtime)) : null,
            /** @var string|null target of a symbolic link */
            'link_target' => $entry->type === 'l' && $entry->link !== '' ? $entry->link : null,
            /** @var 'd'|'f'|'missing'|'o'|null what a symbolic link points to: folder, file, nothing, other */
            'link_type' => $entry->type === 'l' ? (in_array($entry->linkType, ['d', 'f', 'missing', 'o'], true) ? $entry->linkType : 'o') : null,
            /** @var bool whether a symbolic link points inside the home directory; false for other entries */
            'link_inside' => $entry->type === 'l' && $entry->linkInside,
            /** @var string|null MIME type; null in lists, which do not read it */
            'mime' => $entry->mime !== null && $entry->mime !== '' ? $entry->mime : null,
            /** @var bool|null whether the user may write it; null in lists, which do not test it */
            'writable' => $entry->writable,
            /** @var bool false for a name the panel cannot address (not UTF-8, control characters): listed, but no operation accepts it */
            'valid_name' => $entry->validName,
        ];
    }
}
