<?php

namespace App\Api;

use Illuminate\Http\Request;

/**
 * Pages a list of API v2 (E34 batch 2 spec D20): `limit` 1–1000 and
 * `offset` from 0, both optional. Without `limit` the list is complete
 * and `meta` is only `total`, so a client of batch 1 sees the same items.
 */
final class Paginates
{
    /**
     * @template T
     *
     * @param  list<T>  $items  the full list, in the order of the command
     * @return array{items: list<T>, meta: array{total: int, limit?: int, offset?: int}}
     */
    public function slice(Request $request, array $items): array
    {
        ['limit' => $limit, 'offset' => $offset] = $this->window($request);
        $total = count($items);
        if ($limit === null) {
            return ['items' => array_values($items), 'meta' => ['total' => $total]];
        }

        return [
            'items' => array_values(array_slice($items, $offset, $limit)),
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    /**
     * The validated `limit` (null without one) and `offset` (0 without
     * one), for a list whose command pages itself.
     *
     * @return array{limit: int|null, offset: int}
     */
    public function window(Request $request): array
    {
        $query = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        return [
            'limit' => array_key_exists('limit', $query) ? (int) $query['limit'] : null,
            'offset' => (int) ($query['offset'] ?? 0),
        ];
    }
}
