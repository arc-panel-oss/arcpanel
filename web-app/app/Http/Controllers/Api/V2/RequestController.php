<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\KeyPermissions;
use App\Api\RequestJournal;
use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\ApiRequestResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @tags Requests
 */
final class RequestController extends Controller
{
    /**
     * Look up a request
     *
     * What became of a write, found by its request id: the `X-Request-Id`
     * of its answer, which is the client's own id when it is valid. A client
     * whose answer was lost asks here before it tries again (E43 B4). Writes
     * of access keys are journaled for 7 days without their bodies; reads
     * and dry runs are not. `state` is `running` for 15 minutes after the
     * start of a request without an end and `unknown` after that. A key sees
     * the requests of the keys of its own user, an administrator's key every
     * request; any other id answers 404. When several requests share the id,
     * the latest is returned and `meta.duplicates` counts them. Every key may
     * call it, whatever its permission sets.
     *
     * @operationId getRequest
     */
    public function show(string $id, KeyPermissions $key, RequestJournal $journal): ApiRequestResource
    {
        $found = $journal->find($id, $key->role === Role::Admin ? null : $key->user);
        if ($found === null) {
            throw new NotFoundHttpException;
        }

        return (new ApiRequestResource($found['request']))->additional(['meta' => ['duplicates' => $found['duplicates']]]);
    }
}
