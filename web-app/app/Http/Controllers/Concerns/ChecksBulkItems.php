<?php

namespace App\Http\Controllers\Concerns;

use App\Auth\OwnerScope;
use Closure;

/**
 * Bulk forms post items as "user/object[/sub]". Every item is checked before
 * any command runs: an owner outside the scope answers 404, an action the
 * policy refuses 403, so one foreign item stops the whole request.
 */
trait ChecksBulkItems
{
    /**
     * @param  list<string>  $items
     * @param  Closure(string): bool  $allowed  policy check for the item's owner
     * @return list<list<string>> the parts of each item, the owner first
     */
    protected function bulkItems(array $items, int $parts, OwnerScope $scope, Closure $allowed): array
    {
        $checked = [];
        foreach (array_values(array_unique($items)) as $item) {
            $split = explode('/', $item, $parts);
            abort_unless(count($split) === $parts && ! in_array('', $split, true), 422);
            $scope->assertVisible($split[0]);
            abort_unless($allowed($split[0]), 403);
            $checked[] = $split;
        }

        return $checked;
    }
}
