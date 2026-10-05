<?php

namespace App\Services\Backups;

use App\Services\Files\FilePath;

/**
 * A folder of a restic snapshot: `v-list-user-files-restic USER SNAPSHOT
 * PATH` prints `restic --json ls`, one JSON object per line: the snapshot
 * first, then every node below PATH. Only the direct children of PATH are
 * kept, as entries of the file manager island (FileEntry::toArray()).
 */
final class SnapshotListing
{
    /**
     * @param  list<array{name: string, type: string, dir: bool, size: int, mode: string, mtime: int, link: string, linkType: string, linkInside: bool, validName: bool, writable: bool}>  $entries
     */
    public function __construct(
        public readonly string $path,
        public readonly array $entries,
    ) {}

    /** $path is absolute (/home/USER/…). */
    public static function parse(string $output, string $path): self
    {
        $path = rtrim($path, '/');
        $entries = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $node = json_decode(trim($line), true);
            if (! is_array($node) || ($node['struct_type'] ?? 'node') !== 'node') {
                continue;
            }
            $nodePath = (string) ($node['path'] ?? '');
            if ($nodePath === '' || dirname($nodePath) !== $path) {
                continue;
            }
            $type = match ((string) ($node['type'] ?? '')) {
                'dir' => 'd',
                'symlink' => 'l',
                'file' => 'f',
                default => 'o',
            };
            $name = (string) ($node['name'] ?? basename($nodePath));
            $mtime = strtotime((string) ($node['mtime'] ?? ''));
            $entries[] = [
                'name' => $name,
                'type' => $type,
                'dir' => $type === 'd',
                'size' => (int) ($node['size'] ?? 0),
                'mode' => isset($node['mode']) ? sprintf('%o', ((int) $node['mode']) & 0777) : '',
                'mtime' => $mtime !== false ? $mtime : 0,
                'link' => (string) ($node['linktarget'] ?? ''),
                'linkType' => '',
                'linkInside' => false,
                'validName' => FilePath::validName($name),
                'writable' => false,
            ];
        }
        usort($entries, fn (array $a, array $b) => [! $a['dir'], strtolower($a['name'])] <=> [! $b['dir'], strtolower($b['name'])]);

        return new self($path, $entries);
    }
}
