<?php

namespace App\Services\Files;

/**
 * One entry of `v-list-fs-directory-json` or `v-get-fs-file-info`.
 */
final class FileEntry
{
    public function __construct(
        public readonly string $name,
        /** d, f, l (symlink) or o (other) */
        public readonly string $type,
        public readonly int $size,
        public readonly string $mode,
        public readonly string $owner,
        public readonly string $group,
        public readonly int $mtime,
        public readonly string $link,
        /** d, f, missing, o; empty for entries that are not symlinks */
        public readonly string $linkType,
        public readonly bool $linkInside,
        public readonly bool $validName,
        public readonly ?string $mime = null,
        public readonly ?bool $writable = null,
    ) {}

    /** @param  array<mixed>  $row */
    public static function from(array $row): self
    {
        $string = fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

        return new self(
            name: $string('NAME'),
            type: in_array($string('TYPE'), ['d', 'f', 'l'], true) ? $string('TYPE') : 'o',
            size: (int) $string('SIZE'),
            mode: $string('MODE'),
            owner: $string('OWNER'),
            group: $string('GROUP'),
            mtime: (int) $string('MTIME'),
            link: $string('LINK'),
            linkType: $string('LINK_TYPE'),
            linkInside: $string('LINK_INSIDE') === 'yes',
            validName: $string('VALID_NAME') !== 'no',
            mime: isset($row['MIME']) ? $string('MIME') : null,
            writable: isset($row['WRITABLE']) ? $string('WRITABLE') === 'yes' : null,
        );
    }

    /** A folder, or a symlink to a folder inside the home directory. */
    public function isDirectory(): bool
    {
        return $this->type === 'd' || ($this->type === 'l' && $this->linkType === 'd' && $this->linkInside);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'type' => $this->type,
            'dir' => $this->isDirectory(),
            'size' => $this->size,
            'mode' => $this->mode,
            'owner' => $this->owner,
            'group' => $this->group,
            'mtime' => $this->mtime,
            'link' => $this->link,
            'linkType' => $this->linkType,
            'linkInside' => $this->linkInside,
            'validName' => $this->validName,
            'mime' => $this->mime,
            'writable' => $this->writable,
        ], fn ($value) => $value !== null);
    }
}
