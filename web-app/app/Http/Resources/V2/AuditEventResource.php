<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An event of the audit log (`v-list-sys-audit … json`), E23 spec §3: who
 * did what to which object, with the result and its place in the hash
 * chain. Field names of a request are listed, never their values.
 *
 * @property array<string, mixed> $resource
 */
final class AuditEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->resource;
        $actor = (array) ($event['actor'] ?? []);
        $object = is_array($event['object'] ?? null) ? $event['object'] : null;

        return [
            /** @var string ULID of the event, sortable by time */
            'id' => (string) ($event['id'] ?? ''),
            /** @var string UTC with milliseconds, YYYY-MM-DDTHH:MM:SS.mmmZ */
            'time' => (string) ($event['ts'] ?? ''),
            /** @var string|null the X-Request-Id of the request */
            'request_id' => $event['request_id'] ?? null,
            /** @var string `web`, `api_v2`, `api_v1` or `system` */
            'channel' => (string) ($event['channel'] ?? ''),
            'actor' => [
                /** @var string the signed-in user or the owner of the access key; empty when nobody signed in */
                'user' => (string) ($actor['user'] ?? ''),
                /** @var string `admin`, `reseller`, `user`, `system` or empty */
                'role' => (string) ($actor['role'] ?? ''),
                /** @var string|null */
                'ip' => $actor['ip'] ?? null,
                /** @var string|null the access key id */
                'access_key' => $actor['key'] ?? null,
                /** @var string|null SHA-256 of the user agent, 16 hex characters */
                'user_agent_hash' => $actor['ua'] ?? null,
            ],
            /** @var string|null the user acted on during login-as, else the actor */
            'effective_user' => $event['effective_user'] ?? null,
            /** @var string `<module>.<object>.<action>`: the route name or an explicit action such as `auth.login` */
            'action' => (string) ($event['action'] ?? ''),
            /** @var array{type: string, user: string|null, name: string|null}|null */
            'object' => $object === null ? null : [
                'type' => (string) ($object['type'] ?? ''),
                'user' => $object['user'] ?? null,
                'name' => $object['name'] ?? null,
            ],
            /** @var string `ok`, `denied`, `invalid` or `failed` */
            'result' => (string) ($event['result'] ?? ''),
            /** @var int|null the HTTP status of the answer */
            'status' => isset($event['status']) ? (int) $event['status'] : null,
            /** @var list<string> names of the input fields */
            'fields' => array_values(array_map('strval', (array) ($event['fields'] ?? []))),
            /** @var string SHA-256 of the event before this one */
            'prev' => (string) ($event['prev'] ?? ''),
            /** @var string SHA-256 of prev and this event */
            'hash' => (string) ($event['hash'] ?? ''),
        ];
    }
}
