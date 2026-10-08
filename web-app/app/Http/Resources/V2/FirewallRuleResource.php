<?php

namespace App\Http\Resources\V2;

use App\Services\Firewall\FirewallRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A firewall rule (`v-list-firewall json`). Its id is its position in the
 * rule order (E34 batch 6 spec D59): a move, or deleting a rule before it,
 * changes it.
 *
 * @property FirewallRule $resource
 */
final class FirewallRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rule = $this->resource;

        return [
            /** @var int the position of the rule; rules apply in this order */
            'id' => (int) $rule->id,
            /** @var 'ACCEPT'|'DROP' */
            'action' => $rule->action,
            /** @var 'TCP'|'UDP'|'ICMP' */
            'protocol' => $rule->protocol,
            /** @var string a port, a range or a list; `0` is every port */
            'port' => $rule->port,
            /** @var string an address, a network, or `ipset:NAME` */
            'source' => $rule->ip,
            /** @var string|null */
            'comment' => $rule->comment !== '' ? $rule->comment : null,
            /** @var bool */
            'suspended' => $rule->suspended,
            /** @var bool the port registry's rule (comment `arcpanel-port-…`): it can be read and moved, not changed */
            'managed' => $rule->managed(),
            /** @var string|null when the rule was last written (created, changed or moved), in the server's time zone */
            'updated_at' => UserResource::localTime($rule->date, $rule->time),
        ];
    }
}
