<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Principal;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\AuditEventResource;
use App\Policies\AuditPolicy;
use App\Services\Audit\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The audit log of the server (E23 spec §7.2): administrators read every
 * event and verify the hash chain; resellers read the events of their
 * tree; users are refused. Read-only, area `audit`.
 *
 * @tags Audit log
 */
final class AuditController extends Controller
{
    /** Days listed without `from`: today and the six before. */
    public const DEFAULT_DAYS = 7;

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * List audit events
     *
     * Newest first, between the UTC days `from` and `to` (default: the last
     * 7 days). `limit` events (default 100) after skipping `offset` newer
     * ones; `meta.total` counts the matching events. `user` matches the
     * actor, the effective user or the object's user; `action` matches the
     * start of the action. A reseller sees only the events of its tree.
     *
     * @operationId listAuditEvents
     */
    public function index(Request $request, Principal $principal, AuditPolicy $policy): AnonymousResourceCollection
    {
        abort_unless($policy->view($principal), 403);
        $data = $request->validate([
            /** First UTC day, YYYY-MM-DD; default six days before `to`. */
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** Last UTC day, YYYY-MM-DD; default today. */
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            /** The signed-in user or the owner of the access key. */
            'actor' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^\S+$/'],
            /** The actor, the effective user or the object's user. */
            'user' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/'],
            /** The start of the action, for example `web.domains` or `auth.`. */
            'action' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9][a-z0-9._-]{0,127}$/'],
            /** `ok`, `denied`, `invalid` or `failed`. */
            'result' => ['sometimes', 'nullable', 'in:ok,denied,invalid,failed'],
            /** `web`, `api_v2`, `api_v1` or `system`. */
            'channel' => ['sometimes', 'nullable', 'in:web,api_v2,api_v1,system'],
            /** Events to return, from 1 to 1000. Defaults to 100. */
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            /** Newer events to skip. */
            'offset' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ]);
        $to = $data['to'] ?? gmdate('Y-m-d');
        $from = min($data['from'] ?? gmdate('Y-m-d', strtotime($to.' -'.(self::DEFAULT_DAYS - 1).' days')), $to);
        $limit = (int) ($data['limit'] ?? 100);
        $offset = (int) ($data['offset'] ?? 0);
        $filters = array_intersect_key($data, array_flip(['actor', 'user', 'action', 'result', 'channel']));
        $list = $this->audit->list($from, $to, $filters, $policy->owner($principal), $limit, $offset);

        return AuditEventResource::collection($list['events'])->additional(['meta' => [
            'total' => $list['total'],
            'limit' => $limit,
            'offset' => $offset,
            'from' => $from,
            'to' => $to,
        ]]);
    }

    /**
     * Verify the audit chain
     *
     * Recomputes the SHA-256 chain between `from` and `to` (default: from
     * the first kept day to today). `ok` is false with the first broken
     * `day`, `line` and `reason`. Administrators only.
     *
     * @operationId verifyAuditChain
     */
    public function verify(Request $request, Principal $principal, AuditPolicy $policy): JsonResponse
    {
        abort_unless($policy->verify($principal), 403);
        $data = $request->validate([
            /** First UTC day, YYYY-MM-DD; default the first day the retention keeps. */
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            /** Last UTC day, YYYY-MM-DD; default today. */
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $result = $this->audit->verify((string) ($data['from'] ?? ''), (string) ($data['to'] ?? ''));

        return response()->json(['data' => [
            /** @var bool */
            'ok' => $result['ok'],
            /** @var string */
            'from' => $result['from'],
            /** @var string */
            'to' => $result['to'],
            /** @var int events checked */
            'count' => $result['count'],
            /** @var string|null the first broken day */
            'day' => $result['day'],
            /** @var int|null its line, 0 when the day's file is missing */
            'line' => $result['line'],
            /** @var string|null */
            'reason' => $result['reason'],
        ]]);
    }
}
