<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One match of a name search (searchFiles, E34 batch 5 spec §4.1).
 *
 * @property array{path: string, type: string, size: int} $resource
 */
final class FileSearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $path = $this->resource['path'];
        $slash = strrpos($path, '/');

        return [
            /** @var string */
            'name' => $slash === false ? $path : substr($path, $slash + 1),
            /** @var string path relative to the home directory */
            'path' => $path,
            /** @var 'file'|'directory'|'link'|'other' */
            'type' => match ($this->resource['type']) {
                'd' => 'directory',
                'f' => 'file',
                'l' => 'link',
                default => 'other',
            },
            /** @var int bytes */
            'size' => $this->resource['size'],
        ];
    }
}
