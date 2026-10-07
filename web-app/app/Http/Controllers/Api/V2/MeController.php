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
     * profile (E34 spec D12).
     *
     * @operationId getMe
     */
    public function __invoke(KeyPermissions $key, SysConfig $config): MeResource
    {
        return new MeResource($key, $config->get('VERSION'));
    }
}
