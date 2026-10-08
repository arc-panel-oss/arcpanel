<?php

namespace App\Http\Resources\V2;

use App\Services\Ports\PortEntry;
use App\Services\Ports\PortRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The port registry of the server (`v-list-sys-ports`, E34 batch 4 spec §4.5). */
final class PortRegistryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PortRegistry $registry */
        $registry = $this->resource;
        $users = [];
        foreach ($registry->users as $name => $row) {
            $users[] = [
                'user' => (string) $name,
                'owner' => ($row['OWNER'] ?? '') !== '' ? $row['OWNER'] : null,
                'role' => $row['ROLE'] ?? '',
                'suspended' => ($row['SUSPENDED'] ?? '') === 'yes',
                /** PORTS of the package: `unlimited` or a number */
                'limit' => ($row['LIMIT'] ?? '') !== '' ? $row['LIMIT'] : '0',
                'allocated' => (int) ($row['ALLOCATED'] ?? 0),
                'assigned' => (int) ($row['ASSIGNED'] ?? 0),
                'public' => (int) ($row['PUBLIC'] ?? 0),
                'free' => (int) ($row['FREE'] ?? 0),
                'tree_total' => ($row['TREE_TOTAL'] ?? '') !== '' ? (int) $row['TREE_TOTAL'] : null,
                'tree_used' => ($row['TREE_USED'] ?? '') !== '' ? (int) $row['TREE_USED'] : null,
            ];
        }

        return [
            'range' => ['start' => $registry->rangeStart, 'end' => $registry->rangeEnd],
            /** FIREWALL_SYSTEM, null when the firewall is off (every listening port is reachable). */
            'firewall' => $registry->firewallOn() ? $registry->firewall : null,
            'users' => $users,
            'entries' => PortEntryResource::collection($registry->entries)->resolve($request),
            'reserved' => array_map(fn (PortEntry $entry) => [
                'port' => $entry->label(),
                'start' => $entry->start,
                'end' => $entry->end,
                /** `system` (the panel's own services) or `admin` (reserved by an administrator) */
                'kind' => $entry->app,
                'comment' => $entry->comment !== '' ? $entry->comment : null,
            ], $registry->reserved),
            'sockets' => array_map(fn (array $socket) => [
                'proto' => $socket['PROTO'] ?? '',
                'port' => (int) ($socket['PORT'] ?? 0),
                /** `local` (loopback) or `any` (all addresses) */
                'scope' => $socket['SCOPE'] ?? '',
                'uid' => isset($socket['UID']) && $socket['UID'] !== '' ? (int) $socket['UID'] : null,
                'account' => ($socket['ACCOUNT'] ?? '') !== '' ? $socket['ACCOUNT'] : null,
            ], $registry->sockets),
            'findings' => PortFindingResource::collection($registry->findings)->resolve($request),
        ];
    }
}
