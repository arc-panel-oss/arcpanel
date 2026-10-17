<?php

namespace App\Http\Controllers\Api\V2;

use App\Api\KeyPermissions;
use App\Arc\SysConfig;
use App\Http\Controllers\Controller;
use App\Http\Resources\V2\MeResource;

/**
 * @tags Account
 */
final class MeController extends Controller
{
    /**
     * Who am I
     *
     * The access key of the request: its owner and role, its permission
     * sets and the commands they allow (`*` when unrestricted), and the
     * versions of the panel and of the API. Clients use it to check a
     * profile (E34 spec D12). `server_id` identifies the server: it stays
     * the same for every key of the server and changes when the machine is
     * cloned with a new identity (E43 B1). `key_expires_at` and
     * `key_allowed_ips` are the restrictions of the calling key.
     *
     * @operationId getMe
     */
    public function __invoke(KeyPermissions $key, SysConfig $config): MeResource
    {
        return new MeResource($key, $config->get('VERSION'), $config->get('SERVER_ID'));
    }
}
