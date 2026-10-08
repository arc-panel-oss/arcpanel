<?php

namespace App\Services\Files;

use App\Services\WebDomains\Step;

/**
 * The change commands of a file operation (E34 batch 5 spec §5): the Steps
 * of each path in the order they run, and what the operation answers once
 * they ran (the new path, the names created). A path without Steps was
 * skipped (`on_conflict=skip`, a move within its folder). Steps run in
 * order and stop at the first failure, so the Steps that ran are always a
 * prefix: done() names the paths whose Steps all ran, failedPath() the path
 * of the Step that failed (D49).
 */
final class FilePlan
{
    /**
     * @param  list<array{path: FilePath, steps: list<Step>}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly mixed $result = null,
    ) {}

    /** @return list<Step> */
    public function steps(): array
    {
        return array_values(array_merge([], ...array_map(fn (array $item) => $item['steps'], $this->items)));
    }

    /**
     * Paths whose Steps all ran when $ran Steps ran; skipped paths are not done.
     *
     * @return list<FilePath>
     */
    public function done(int $ran): array
    {
        $done = [];
        $count = 0;
        foreach ($this->items as $item) {
            $count += count($item['steps']);
            if ($count > $ran) {
                break;
            }
            if ($item['steps'] !== []) {
                $done[] = $item['path'];
            }
        }

        return $done;
    }

    /** The path of the Step after the $ran Steps that ran; null when every Step ran. */
    public function failedPath(int $ran): ?FilePath
    {
        $count = 0;
        foreach ($this->items as $item) {
            $count += count($item['steps']);
            if ($count > $ran) {
                return $item['path'];
            }
        }

        return null;
    }
}
