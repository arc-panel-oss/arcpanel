<?php

namespace App\Http\Resources\V2;

use App\Services\Ports\PortAccount;
use App\Services\Ports\PortEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the port registry (E34 batch 4 spec §4.5): a single port or
 * a free range of a user.
 */
final class PortEntryResource extends JsonResource
{
    /** The entries of one account with its totals as `meta` (listPorts and the answers of its writes). */
    public static function list(PortAccount $account): AnonymousResourceCollection
    {
        return self::collection($account->entries)->additional(['meta' => [
            'user' => $account->user,
            'owner' => $account->owner !== '' ? $account->owner : null,
            'limit' => $account->limit,
            'allocated' => $account->allocated,
            'assigned' => $account->assigned,
            'public' => $account->public,
            'free' => $account->free,
            'tree_total' => $account->treeTotal,
            'tree_used' => $account->treeUsed,
            'firewall' => $account->firewallOn(),
            'range' => ['start' => $account->rangeStart, 'end' => $account->rangeEnd],
        ]]);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PortEntry $entry */
        $entry = $this->resource;

        return [
            /** `30001`, or `30010-30019` for a range */
            'port' => $entry->label(),
            'start' => $entry->start,
            'end' => $entry->end,
            'size' => $entry->size(),
            /** `tcp` or `udp` */
            'proto' => $entry->proto,
            /** The account that holds the port. */
            'owner' => $entry->user,
            /** `manual`, `node:<domain>`, `docker:<app>`; null for a free port or range. */
            'app' => $entry->app !== '' ? $entry->app : null,
            'assigned' => $entry->assigned(),
            /** Open to the internet in the registry (closed while the owner is suspended). */
            'public' => $entry->public,
            /** Whether the port may be opened to the internet. */
            'public_ok' => $entry->publicOk,
            /** Id of the port's firewall rule, null without one. */
            'rule' => $entry->rule !== '' ? $entry->rule : null,
            'comment' => $entry->comment !== '' ? $entry->comment : null,
            'suspended' => $entry->suspended,
            /** Live state: `no` (nothing listens), `local` (loopback) or `any` (all addresses). */
            'listen' => $entry->listen,
            'created_at' => UserResource::localTime($entry->date, $entry->time),
        ];
    }
}
