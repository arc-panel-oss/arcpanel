<?php

namespace App\Http\Resources\V2;

use App\Api\KeyPermissions;
use App\Auth\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The access key of the request (`GET /me`).
 *
 * @property KeyPermissions $resource
 */
final class MeResource extends JsonResource
{
    public const API_VERSION = '2';

    public function __construct(KeyPermissions $key, private readonly string $panelVersion)
    {
        parent::__construct($key);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $key = $this->resource;

        return [
            /** @var string the access key id */
            'access_key_id' => $key->keyId,
            /** @var string the owner of the key; every call acts as this user */
            'user' => $key->user,
            'role' => self::role($key->role),
            /** @var bool whether the user may only read (no write endpoint answers) */
            'read_only' => false,
            /** @var list<string> */
            'permissions' => $key->sets,
            /** @var list<string> the commands the key may run, `*` when unrestricted */
            'commands' => $key->commands,
            /** @var string */
            'panel_version' => $this->panelVersion,
            /** @var string */
            'api_version' => self::API_VERSION,
        ];
    }

    /** Roles of API v2 (dns-cluster keys are users). */
    public static function role(Role $role): ApiRole
    {
        return match ($role) {
            Role::Admin => ApiRole::Admin,
            Role::Reseller => ApiRole::Reseller,
            default => ApiRole::User,
        };
    }
}
