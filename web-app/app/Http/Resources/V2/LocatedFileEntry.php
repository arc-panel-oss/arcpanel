<?php

namespace App\Http\Resources\V2;

use App\Services\Files\FileEntry;
use App\Services\Files\FilePath;

/** A FileEntry with its path relative to the home directory (FileEntryResource). */
final class LocatedFileEntry
{
    public function __construct(
        public readonly FileEntry $entry,
        /** relative to the home directory, "" for the home itself */
        public readonly string $path,
    ) {}

    public static function at(FileEntry $entry, FilePath $path): self
    {
        return new self($entry, $path->relative());
    }

    /**
     * The entries of a listing of $dir. A name the panel cannot address
     * (valid_name false) keeps its path as listed, for display only.
     *
     * @param  list<FileEntry>  $entries
     * @return list<self>
     */
    public static function inFolder(FilePath $dir, array $entries): array
    {
        return array_map(fn (FileEntry $entry) => new self($entry, $dir->isRoot() ? $entry->name : $dir->relative().'/'.$entry->name), $entries);
    }
}
