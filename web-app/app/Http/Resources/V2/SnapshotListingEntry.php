<?php

namespace App\Http\Resources\V2;

/**
 * Raw restic folder row plus the home and listing path for FileEntry mapping.
 */
final readonly class SnapshotListingEntry
{
    /**
     * @param  array{name: string, type: string, dir: bool, size: int, mode: string, mtime: int, link: string, linkType: string, linkInside: bool, validName: bool, writable: bool}  $raw
     */
    public function __construct(
        public array $raw,
        public string $home,
        public string $listingPath,
    ) {}
}
